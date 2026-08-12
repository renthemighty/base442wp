<?php
/**
 * ClaudeClient — Stage 3 wrapper for the Anthropic Messages API.
 *
 * Provides simple message() and message_with_files() helpers that the
 * Converter uses to call Claude without worrying about HTTP plumbing.
 *
 * v3: adds messageBatch() — concurrent batched requests via curl_multi
 * with per-request retry/backoff — and prompt caching (system blocks
 * with cache_control, shared cache_prefix for batches).
 */

declare(strict_types=1);

class ClaudeClient
{
    private string $api_key;
    private string $model;
    private int    $max_tokens;
    private int $tokens_input  = 0;
    private int $tokens_output = 0;
    private int $tokens_cache_creation = 0;
    private int $tokens_cache_read     = 0;
    /** Count of completed API requests (single message + each batch job). */
    private int $calls = 0;

    /** Anthropic API base URL. */
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Anthropic API version header value. */
    private const API_VERSION = '2023-06-01';

    /** cURL timeout in seconds for a single API call. */
    private const CURL_TIMEOUT = 900;

    /** Maximum characters per file when building the file-attachment context. */
    private const MAX_FILE_CHARS = 60_000;

    /** Max retry attempts per request on 429/529/5xx/network errors. */
    private const MAX_RETRIES = 3;

    /**
     * v3.2.2: when a response stops at the max_tokens cap (stop_reason
     * "max_tokens") the output is TRUNCATED mid-file — never acceptable for
     * generated templates. Retry the identical request with a doubled cap,
     * up to this many raises, hard ceiling MAX_TOKENS_CEILING (non-streaming
     * requests must stay comfortably inside CURL_TIMEOUT).
     */
    private const MAX_TOKEN_RAISES   = 5;
    private const MAX_TOKENS_CEILING = 64000;

    /** Base delay (seconds) for exponential backoff: base * 2^attempt. */
    private const BACKOFF_BASE = 1.5;

    /**
     * Maximum wall-clock seconds a single batch job may consume across all
     * attempts (including re-queues for truncation and transient errors).
     * A job that exceeds this budget is finalized with its best/last output
     * instead of being re-queued, so it cannot stall the whole batch.
     */
    private const MAX_JOB_SECONDS = 2700;

    public function __construct()
    {
        $this->api_key    = (string) config('claude_api_key');
        $this->model      = (string) config('claude_model', 'claude-sonnet-4-20250514');
        $this->max_tokens = (int)   config('claude_max_tokens', 8192);

        if ($this->api_key === '' || $this->api_key === 'sk-ant-XXXXXXXXXXXX') {
            throw new RuntimeException('Claude API key is not configured.');
        }
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Send a single-turn message to Claude and return the response text.
     *
     * @param  string               $system   System prompt.
     * @param  string               $user     User message.
     * @param  array<string, mixed> $options  Optional overrides: max_tokens, temperature,
     *                                        cache_system (bool — wrap system in a block
     *                                        with cache_control: ephemeral),
     *                                        cache_prefix (string — prepended as its own
     *                                        cached system block before $system).
     * @return string               The text content of the first response block.
     * @throws RuntimeException     On API or network error.
     */
    public function message(string $system, string $user, array $options = []): string
    {
        $cache_prefix = isset($options['cache_prefix']) && is_string($options['cache_prefix'])
            ? $options['cache_prefix']
            : null;

        if ($cache_prefix !== null && $cache_prefix !== '') {
            $system_param = $this->buildSystemBlocks($system, $cache_prefix);
        } elseif (!empty($options['cache_system'])) {
            $system_param = [
                [
                    'type'          => 'text',
                    'text'          => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        } else {
            $system_param = $system;
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->max_tokens,
            'system'     => $system_param,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        // v3.2.2: retry with a raised cap when the response was truncated at
        // max_tokens (stop_reason "max_tokens") — truncated templates are
        // never acceptable output.
        $raises = 0;
        while (true) {
            $response = $this->make_request($payload);
            if ((string) ($response['stop_reason'] ?? '') !== 'max_tokens'
                || $raises >= self::MAX_TOKEN_RAISES
                || (int) $payload['max_tokens'] >= self::MAX_TOKENS_CEILING) {
                break;
            }
            $raises++;
            $payload['max_tokens'] = min(self::MAX_TOKENS_CEILING, ((int) $payload['max_tokens']) * 2);
            echo "  [Claude] output truncated at max_tokens — retrying with cap {$payload['max_tokens']} (raise {$raises}/" . self::MAX_TOKEN_RAISES . ")\n";
        }
        return $this->extractText($response);
    }

    /**
     * Send a message that includes named source-file contents in the user turn.
     *
     * Files are embedded as fenced code blocks so Claude can reference them.
     *
     * @param  string                                     $system  System prompt.
     * @param  string                                     $user    User instructions (appended after files).
     * @param  list<array{name: string, content: string}> $files   Source files to include.
     * @return string
     * @throws RuntimeException
     */
    public function message_with_files(string $system, string $user, array $files, array $options = []): string
    {
        $file_block = '';
        foreach ($files as $file) {
            $name    = $file['name'] ?? 'file';
            $content = $file['content'] ?? '';

            // Truncate very large files to stay within token budget
            if (strlen($content) > self::MAX_FILE_CHARS) {
                $content = substr($content, 0, self::MAX_FILE_CHARS)
                    . "\n\n/* ... truncated at " . self::MAX_FILE_CHARS . " chars ... */";
            }

            $lang = $this->extensionToLanguage(pathinfo($name, PATHINFO_EXTENSION));
            $file_block .= "=== {$name} ===\n```{$lang}\n{$content}\n```\n\n";
        }

        $combined_user = $file_block . $user;

        return $this->message($system, $combined_user, $options);
    }

    /**
     * Run a batch of single-turn jobs concurrently via curl_multi.
     *
     * Each job: [
     *   'system'     => string|array  (string, or pre-built array of system blocks),
     *   'user'       => string,
     *   'max_tokens' => int      (optional, default = client max_tokens),
     *   'meta'       => mixed    (optional, passed through untouched),
     * ]
     *
     * Options:
     *   'cache_prefix' => string — shared context prepended as a cached system
     *   block (cache_control: ephemeral) before each job's string system prompt.
     *   Jobs whose system is already an array are sent as-is (no prefix added).
     *
     * Returns an array with the SAME KEYS as $jobs, each value:
     *   [
     *     'text'           => string|null,  // response text, null on failure
     *     'error'          => string|null,  // error message, null on success
     *     'tokens_in'      => int,          // input_tokens (uncached remainder)
     *     'tokens_out'     => int,
     *     'cache_creation' => int,          // cache_creation_input_tokens
     *     'cache_read'     => int,          // cache_read_input_tokens
     *     'meta'           => mixed,        // passthrough from the job
     *   ]
     *
     * Per-request retry: 429/529/5xx and network errors retried up to 3x with
     * exponential backoff (Retry-After honored) without blocking the pool.
     * On 429/529 the effective pool size is degraded (min 1).
     *
     * @param  array<array-key, array<string, mixed>> $jobs
     * @param  int                                    $pool    Max concurrent requests.
     * @param  array<string, mixed>                   $options
     * @return array<array-key, array<string, mixed>>
     */
    public function messageBatch(array $jobs, int $pool = 6, array $options = []): array
    {
        $results = [];
        if ($jobs === []) {
            return $results;
        }

        $cache_prefix = isset($options['cache_prefix']) && is_string($options['cache_prefix']) && $options['cache_prefix'] !== ''
            ? $options['cache_prefix']
            : null;

        $pool          = max(1, $pool);
        $effectivePool = $pool;

        // Build the work queue: every entry carries its original key + attempt count.
        $queue = [];          // jobs ready to dispatch now
        $delayed = [];        // [ ['ready_at' => float, 'item' => array], ... ]
        foreach ($jobs as $key => $job) {
            $queue[] = [
                'key'        => $key,
                'payload'    => $this->buildJobPayload($job, $cache_prefix),
                'meta'       => $job['meta'] ?? null,
                'attempt'    => 0,
                'started_at' => microtime(true),
            ];
            // Pre-fill result slot so output keys align with input keys/order.
            $results[$key] = [
                'text'           => null,
                'error'          => null,
                'tokens_in'      => 0,
                'tokens_out'     => 0,
                'cache_creation' => 0,
                'cache_read'     => 0,
                'meta'           => $job['meta'] ?? null,
            ];
        }

        $mh     = curl_multi_init();
        $active = [];   // spl_object_id => ['ch' => CurlHandle, 'item' => array, 'headers' => array]

        $addHandle = function (array $item) use (&$active, $mh): void {
            $ch = curl_init(self::API_URL);
            $headers = [];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $item['payload_json'],
                CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: '         . $this->api_key,
                    'anthropic-version: ' . self::API_VERSION,
                    'content-type: application/json',
                    'accept: application/json',
                ],
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            $id = spl_object_id($ch);
            $active[$id] = ['ch' => $ch, 'item' => $item, 'headers' => &$headers];
            curl_multi_add_handle($mh, $ch);
        };

        // Pre-encode payloads once (retries resend the same body).
        foreach ($queue as &$item) {
            $item['payload_json'] = json_encode(
                $item['payload'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
        unset($item);

        while ($queue !== [] || $delayed !== [] || $active !== []) {
            // Promote delayed retries whose backoff has elapsed.
            $now = microtime(true);
            foreach ($delayed as $i => $d) {
                if ($d['ready_at'] <= $now) {
                    $queue[] = $d['item'];
                    unset($delayed[$i]);
                }
            }

            // Fill the pool.
            while ($queue !== [] && count($active) < $effectivePool) {
                $addHandle(array_shift($queue));
            }

            if ($active === []) {
                // Nothing in flight; wait for the nearest delayed retry.
                if ($delayed !== []) {
                    $next = min(array_column($delayed, 'ready_at'));
                    $sleep = max(0.05, $next - microtime(true));
                    usleep((int) (min($sleep, 1.0) * 1_000_000));
                }
                continue;
            }

            // Drive the multi handle.
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running > 0) {
                curl_multi_select($mh, 0.2);
            }

            // Harvest completed transfers.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $id  = spl_object_id($ch);
                $st  = $active[$id];
                unset($active[$id]);
                curl_multi_remove_handle($mh, $ch);

                $item      = $st['item'];
                $key       = $item['key'];
                $raw       = curl_multi_getcontent($ch);
                $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_errno = $info['result'];
                $headers   = $st['headers'];
                curl_close($ch);

                $retryable    = false;
                $error_msg    = null;
                $retry_after  = null;

                if ($curl_errno !== CURLE_OK) {
                    $retryable = true;
                    $error_msg = 'cURL error #' . $curl_errno . ': ' . curl_strerror($curl_errno);
                } else {
                    $decoded = is_string($raw) ? json_decode($raw, true) : null;

                    if ($http_code >= 200 && $http_code < 300 && is_array($decoded)
                        && (($decoded['type'] ?? '') !== 'error')) {
                        // Success.
                        $usage = $decoded['usage'] ?? [];
                        $tin   = (int) ($usage['input_tokens']  ?? 0);
                        $tout  = (int) ($usage['output_tokens'] ?? 0);
                        $tcc   = (int) ($usage['cache_creation_input_tokens'] ?? 0);
                        $tcr   = (int) ($usage['cache_read_input_tokens']     ?? 0);

                        $this->tokens_input          += $tin;
                        $this->tokens_output         += $tout;
                        $this->tokens_cache_creation += $tcc;
                        $this->tokens_cache_read     += $tcr;
                        $this->calls++;

                        // v3.2.2: truncated at the max_tokens cap — requeue the
                        // identical job with a doubled cap instead of accepting
                        // a mid-file cut. Usage above is already accounted.
                        $mt_raises = (int) ($item['mt_raises'] ?? 0);
                        if ((string) ($decoded['stop_reason'] ?? '') === 'max_tokens'
                            && $mt_raises < self::MAX_TOKEN_RAISES
                            && (int) ($item['payload']['max_tokens'] ?? 0) < self::MAX_TOKENS_CEILING) {
                            // Per-job budget guard: do not re-queue if the job has
                            // already consumed its full wall-clock allowance.
                            $elapsed = microtime(true) - (float) ($item['started_at'] ?? microtime(true));
                            if ($elapsed >= self::MAX_JOB_SECONDS) {
                                $best = '';
                                try { $best = $this->extractText($decoded); } catch (RuntimeException $e) {}
                                error_log(sprintf(
                                    'WARNING: truncated output shipped as final result for job "%s", elapsed %.1fs, job budget %ds. Response was cut off at max_tokens and the budget expired before a retry with a higher cap could run.',
                                    $key,
                                    $elapsed,
                                    self::MAX_JOB_SECONDS
                                ));
                                echo "  [Claude] batch job '{$key}' exceeded " . self::MAX_JOB_SECONDS . "s budget — using best/last output\n";
                                $results[$key] = [
                                    'text'           => $best !== '' ? $best : null,
                                    'error'          => $best !== '' ? null : 'job exceeded ' . self::MAX_JOB_SECONDS . 's budget',
                                    'tokens_in'      => $tin,
                                    'tokens_out'     => $tout,
                                    'cache_creation' => $tcc,
                                    'cache_read'     => $tcr,
                                    'meta'           => $item['meta'],
                                ];
                                continue;
                            }
                            $item['mt_raises'] = $mt_raises + 1;
                            $item['payload']['max_tokens'] = min(
                                self::MAX_TOKENS_CEILING,
                                max(1, (int) ($item['payload']['max_tokens'] ?? 8192)) * 2
                            );
                            $item['payload_json'] = json_encode(
                                $item['payload'],
                                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                            );
                            echo "  [Claude] batch job '{$key}' truncated at max_tokens — retrying with cap "
                               . $item['payload']['max_tokens'] . " (raise {$item['mt_raises']}/" . self::MAX_TOKEN_RAISES . ")\n";
                            $queue[] = $item;
                            continue;
                        }

                        try {
                            $text = $this->extractText($decoded);
                        } catch (RuntimeException $e) {
                            $text = '';
                        }

                        $results[$key] = [
                            'text'           => $text,
                            'error'          => null,
                            'tokens_in'      => $tin,
                            'tokens_out'     => $tout,
                            'cache_creation' => $tcc,
                            'cache_read'     => $tcr,
                            'meta'           => $item['meta'],
                        ];
                        continue;
                    }

                    // Error path.
                    $api_msg = is_array($decoded)
                        ? ($decoded['error']['message'] ?? substr((string) $raw, 0, 300))
                        : substr((string) $raw, 0, 300);
                    $error_msg = "HTTP {$http_code}: {$api_msg}";

                    if ($http_code === 429 || $http_code === 529 || $http_code >= 500) {
                        $retryable = true;
                        if (isset($headers['retry-after']) && is_numeric($headers['retry-after'])) {
                            $retry_after = (float) $headers['retry-after'];
                        }
                        // Overload: degrade pool size gracefully.
                        if ($http_code === 429 || $http_code === 529) {
                            $effectivePool = max(1, $effectivePool - 1);
                        }
                    }
                }

                if ($retryable && $item['attempt'] < self::MAX_RETRIES) {
                    // Per-job budget guard: do not re-queue if the job has
                    // already consumed its full wall-clock allowance.
                    $elapsed = microtime(true) - (float) ($item['started_at'] ?? microtime(true));
                    if ($elapsed >= self::MAX_JOB_SECONDS) {
                        echo "  [Claude] batch job '{$key}' exceeded " . self::MAX_JOB_SECONDS . "s budget — using best/last output\n";
                        $results[$key]['error'] = 'job exceeded ' . self::MAX_JOB_SECONDS . 's budget';
                        $results[$key]['meta']  = $item['meta'];
                    } else {
                        $item['attempt']++;
                        $delay = $retry_after !== null
                            ? $retry_after
                            : self::BACKOFF_BASE * (2 ** ($item['attempt'] - 1)) + (mt_rand(0, 500) / 1000);
                        $delayed[] = [
                            'ready_at' => microtime(true) + $delay,
                            'item'     => $item,
                        ];
                    }
                } else {
                    $results[$key]['error'] = $error_msg ?? 'Unknown error';
                    $results[$key]['meta']  = $item['meta'];
                }
            }
        }

        curl_multi_close($mh);

        return $results;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build the Messages API payload for one batch job.
     *
     * @param  array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function buildJobPayload(array $job, ?string $cache_prefix): array
    {
        $system = $job['system'] ?? '';

        if (is_array($system)) {
            // Caller supplied pre-built system blocks — send as-is.
            $system_param = $system;
        } elseif ($cache_prefix !== null) {
            $system_param = $this->buildSystemBlocks((string) $system, $cache_prefix);
        } else {
            $system_param = (string) $system;
        }

        return [
            'model'      => $this->model,
            'max_tokens' => isset($job['max_tokens']) ? (int) $job['max_tokens'] : $this->max_tokens,
            'system'     => $system_param,
            'messages'   => [
                ['role' => 'user', 'content' => (string) ($job['user'] ?? '')],
            ],
        ];
    }

    /**
     * Build a two-block system array: cached shared prefix + per-job suffix.
     *
     * @return list<array<string, mixed>>
     */
    private function buildSystemBlocks(string $system, string $cache_prefix): array
    {
        $blocks = [
            [
                'type'          => 'text',
                'text'          => $cache_prefix,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
        if ($system !== '') {
            $blocks[] = ['type' => 'text', 'text' => $system];
        }
        return $blocks;
    }

    /**
     * Execute the HTTP request to the Anthropic Messages endpoint.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>  Decoded JSON response.
     * @throws RuntimeException
     */
    private function make_request(array $payload): array
    {
        $json_body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json_body,
            CURLOPT_TIMEOUT        => self::CURL_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: '       . $this->api_key,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
                'accept: application/json',
            ],
        ]);

        $raw      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("cURL error calling Claude API: {$curl_err}");
        }

        if (!is_string($raw)) {
            throw new RuntimeException('Unexpected non-string response from cURL.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                "Claude API returned non-JSON response (HTTP {$http_code}): " . substr($raw, 0, 500)
            );
        }

        // API-level error: { "type": "error", "error": { "message": "..." } }
        if (isset($decoded['type']) && $decoded['type'] === 'error') {
            $msg = $decoded['error']['message'] ?? 'Unknown API error';
            $typ = $decoded['error']['type']    ?? 'unknown';
            throw new RuntimeException("Claude API error [{$typ}]: {$msg}");
        }

        if ($http_code < 200 || $http_code >= 300) {
            $msg = $decoded['error']['message'] ?? $raw;
            throw new RuntimeException("Claude API HTTP {$http_code}: {$msg}");
        }

        $this->tokens_input          += (int) ($decoded['usage']['input_tokens']  ?? 0);
        $this->tokens_output         += (int) ($decoded['usage']['output_tokens'] ?? 0);
        $this->tokens_cache_creation += (int) ($decoded['usage']['cache_creation_input_tokens'] ?? 0);
        $this->tokens_cache_read     += (int) ($decoded['usage']['cache_read_input_tokens']     ?? 0);
        $this->calls++;

        return $decoded;
    }

    /**
     * Extract the text content from the first content block of the response.
     *
     * @param  array<string, mixed> $response
     * @return string
     * @throws RuntimeException
     */
    private function extractText(array $response): string
    {
        $content = $response['content'] ?? [];

        if (!is_array($content) || empty($content)) {
            throw new RuntimeException('Claude API returned an empty content array.');
        }

        // Find the first text block
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        throw new RuntimeException('Claude API response contained no text content block.');
    }

    /**
     * Map a file extension to a fenced-code-block language identifier.
     */
    private function extensionToLanguage(string $ext): string
    {
        return match (strtolower($ext)) {
            'jsx', 'tsx' => 'jsx',
            'ts'         => 'typescript',
            'js'         => 'javascript',
            'css'        => 'css',
            'json'       => 'json',
            'html'       => 'html',
            'php'        => 'php',
            default      => '',
        };
    }

    /**
     * Get accumulated token usage across all requests (single + batch).
     *
     * Backward compatible: 'input' and 'output' keys unchanged; cache
     * counters added. 'input' is the uncached remainder only — total prompt
     * size = input + cache_creation + cache_read.
     *
     * @return array{input: int, output: int, cache_creation: int, cache_read: int}
     */
    public function getTokenUsage(): array
    {
        return [
            'input'          => $this->tokens_input,
            'output'         => $this->tokens_output,
            'cache_creation' => $this->tokens_cache_creation,
            'cache_read'     => $this->tokens_cache_read,
        ];
    }

    /** Total completed API requests (single messages + each batch job). */
    public function getCalls(): int
    {
        return $this->calls;
    }
}
