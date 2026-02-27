<?php
/**
 * ClaudeClient — Stage 3 wrapper for the Anthropic Messages API.
 *
 * Provides simple message() and message_with_files() helpers that the
 * Converter uses to call Claude without worrying about HTTP plumbing.
 */

declare(strict_types=1);

class ClaudeClient
{
    private string $api_key;
    private string $model;
    private int    $max_tokens;

    /** Anthropic API base URL. */
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Anthropic API version header value. */
    private const API_VERSION = '2023-06-01';

    /** cURL timeout in seconds for a single API call. */
    private const CURL_TIMEOUT = 300;

    /** Maximum characters per file when building the file-attachment context. */
    private const MAX_FILE_CHARS = 60_000;

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
     * @param  array<string, mixed> $options  Optional overrides: max_tokens, temperature.
     * @return string               The text content of the first response block.
     * @throws RuntimeException     On API or network error.
     */
    public function message(string $system, string $user, array $options = []): string
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $options['max_tokens'] ?? $this->max_tokens,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $response = $this->make_request($payload);
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
    public function message_with_files(string $system, string $user, array $files): string
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

        return $this->message($system, $combined_user);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

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
}
