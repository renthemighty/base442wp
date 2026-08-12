<?php
/**
 * MediaImporter — Fred-side image downloader for base44towordpress Builder v3.0.0.
 *
 * Problem this solves: AssetCollector downloads images on the APP server only
 * (storage/cache/scraped/{uuid}/images/) and api-fred-bundle never transfers
 * them, so ThemeBuilder::copyImages() finds nothing on Fred and themes ship
 * with zero images. MediaImporter re-downloads the originals directly on Fred
 * (which has unrestricted internet) into the theme's assets/images dir and
 * returns a url → assets/images/<file> map for use in AI section prompts.
 *
 * Standalone — no bootstrap/config dependencies. Requires ext-curl.
 */

class MediaImporter
{
    /** Hard ceiling per file (bytes). */
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /** Global wall-clock budget for one import() call (seconds). */
    private const DEADLINE_SECONDS = 1800; // 30 minutes

    /** Per-request timeout (seconds). */
    private const REQUEST_TIMEOUT = 20;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** content-type → canonical extension. */
    private const TYPE_EXT = [
        'image/jpeg'    => 'jpg',
        'image/jpg'     => 'jpg',
        'image/pjpeg'   => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
        'image/svg+xml' => 'svg',
        'image/svg'     => 'svg',
        'image/avif'    => 'avif',
    ];

    private const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'avif'];

    private string $dir;

    /** url => downloaded byte size; used by promptMap() relevance ordering. */
    private array $sizes = [];

    /**
     * v3.2.1: auth query passthrough for token-gated origins (unpublished
     * Lovable previews — assets on the preview origin 302 without the
     * ?__lovable_token query). Origin (scheme://host[:port]) the params
     * apply to, and the start URL's query params. Set via
     * setAuthPassthrough(); applied at curl time only — by_url keys,
     * dedupe and filenames keep the original token-free URLs.
     */
    private string $auth_origin = '';
    /** @var array<string,string> */
    private array $auth_params = [];

    /**
     * v3.2.1: store the start/live URL's query params and origin; same-origin
     * downloads get those params appended (existing params win) at request
     * time. No-op when the URL has no query.
     */
    public function setAuthPassthrough(string $start_url): void
    {
        $p = @parse_url($start_url);
        if (!is_array($p) || empty($p['host']) || empty($p['query'])) {
            return;
        }
        parse_str((string) $p['query'], $q);
        if (empty($q)) {
            return;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $port   = !empty($p['port']) ? ':' . $p['port'] : '';
        $this->auth_origin = $scheme . '://' . strtolower((string) $p['host']) . $port;
        $this->auth_params = array_map('strval', $q);
    }

    /** v3.2.1: append the stored auth params when $url is on the auth origin. */
    private function applyAuthQuery(string $url): string
    {
        if ($this->auth_origin === '' || empty($this->auth_params)) {
            return $url;
        }
        $p = @parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return $url;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        $port   = !empty($p['port']) ? ':' . $p['port'] : '';
        if ($scheme . '://' . strtolower((string) $p['host']) . $port !== $this->auth_origin) {
            return $url;
        }
        parse_str((string) ($p['query'] ?? ''), $q);
        foreach ($this->auth_params as $k => $v) {
            if (!array_key_exists($k, $q)) {
                $q[$k] = $v;
            }
        }
        $qs = http_build_query($q);
        return $scheme . '://' . $p['host'] . $port . ($p['path'] ?? '/')
             . ($qs !== '' ? '?' . $qs : '');
    }

    public function __construct(string $themeAssetsDir)
    {
        $this->dir = rtrim($themeAssetsDir, '/');
        if (!is_dir($this->dir)) {
            // v4.2.0: 0755 so the webserver can traverse into assets/images
            // (0750 blocked "other" read/execute, contributing to the same
            // 403-on-every-image bug as the 0640 file mode below).
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Download a list of image descriptors into the assets dir.
     *
     * Each descriptor: ['url' => ..., 'category' => ?, 'alt' => ?]
     * Also tolerated: plain string URLs, and live-scan rows using 'src'.
     *
     * @return array{by_url: array<string,string>, files: string[], skipped: array<string,string>, bytes_total: int}
     */
    public function import(array $urls, int $cap = 2000): array
    {
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $byUrl    = [];
        $files    = [];
        $skipped  = [];
        $bytes    = 0;
        $seen     = []; // normalized url => original url (dedupe)
        $taken    = []; // filename => url that owns it (collision detection)

        // Pre-claim names already present in the target dir.
        foreach ((array) @scandir($this->dir) as $existing) {
            if (is_string($existing) && $existing !== '.' && $existing !== '..') {
                $taken[$existing] = '';
            }
        }

        $count = 0;
        foreach ($urls as $entry) {
            if ($count >= $cap) {
                break;
            }

            $url = '';
            if (is_string($entry)) {
                $url = $entry;
            } elseif (is_array($entry)) {
                $url = (string) ($entry['url'] ?? $entry['src'] ?? '');
            }
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            if (strncmp($url, '//', 2) === 0) {
                $url = 'https:' . $url;
            }

            $norm = $this->normalizeUrl($url);
            if ($norm === '') {
                $skipped[$url] = 'invalid-url';
                continue;
            }
            if (isset($seen[$norm])) {
                continue; // duplicate of one already handled
            }
            $seen[$norm] = $url;

            if (microtime(true) >= $deadline) {
                $skipped[$url] = 'deadline';
                continue;
            }

            try {
                $res = $this->download($url, $deadline);
            } catch (\Throwable $e) {
                $skipped[$url] = 'exception: ' . $e->getMessage();
                continue;
            }

            if (isset($res['skip'])) {
                $skipped[$url] = $res['skip'];
                continue;
            }

            $ext      = $res['ext'];
            $filename = $this->chooseFilename($url, $ext, $taken);
            $dest     = $this->dir . '/' . $filename;

            if (!@rename($res['tmp'], $dest)) {
                // cross-device fallback
                if (!@copy($res['tmp'], $dest)) {
                    @unlink($res['tmp']);
                    $skipped[$url] = 'write-failed';
                    continue;
                }
                @unlink($res['tmp']);
            }
            // v4.2.0: 0640 blocks the webserver's world-read of static
            // assets (403 on every image). Packaged theme files must be
            // world-readable like any other static site asset.
            @chmod($dest, 0644);

            $taken[$filename]   = $url;
            $byUrl[$url]        = 'assets/images/' . $filename;
            $files[]            = $filename;
            $bytes             += $res['size'];
            $this->sizes[$url]  = $res['size'];
            $count++;
        }

        $deadlineSkipCount = 0;
        foreach ($skipped as $reason) {
            if ($reason === 'deadline') {
                $deadlineSkipCount++;
            }
        }
        if ($deadlineSkipCount > 0) {
            error_log(sprintf(
                'WARNING: media import deadline of %ds fired, %d image(s) were skipped and not imported.',
                self::DEADLINE_SECONDS,
                $deadlineSkipCount
            ));
        }

        return [
            'by_url'      => $byUrl,
            'files'       => $files,
            'skipped'     => $skipped,
            'bytes_total' => $bytes,
        ];
    }

    /**
     * Compact url → local-path mapping block for embedding in AI prompts.
     * Larger images first when sizes are known (they are after import()).
     */
    public function promptMap(array $byUrl, int $max = 300): string
    {
        $entries = [];
        foreach ($byUrl as $url => $path) {
            $entries[] = [
                'url'  => (string) $url,
                'path' => (string) $path,
                'size' => (int) ($this->sizes[$url] ?? 0),
            ];
        }
        usort($entries, static function (array $a, array $b): int {
            return $b['size'] <=> $a['size'];
        });
        $entries = array_slice($entries, 0, max(0, $max));

        $lines = [];
        foreach ($entries as $e) {
            $lines[] = $e['url'] . ' -> ' . $e['path'];
        }
        return implode("\n", $lines);
    }

    // ------------------------------------------------------------------

    /** Lowercased scheme+host, no fragment; query kept (it can select the image). */
    private function normalizeUrl(string $url): string
    {
        $p = @parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $out = $scheme . '://' . strtolower($p['host']);
        if (!empty($p['port'])) {
            $out .= ':' . $p['port'];
        }
        $out .= $p['path'] ?? '/';
        if (isset($p['query']) && $p['query'] !== '') {
            $out .= '?' . $p['query'];
        }
        return $out;
    }

    /**
     * @return array{skip?:string, tmp?:string, size?:int, ext?:string}
     */
    private function download(string $url, float $deadline): array
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 2) {
            return ['skip' => 'deadline'];
        }
        $timeout = min(self::REQUEST_TIMEOUT, $remaining);

        $tmp = tempnam(sys_get_temp_dir(), 'mi_');
        if ($tmp === false) {
            return ['skip' => 'tempnam-failed'];
        }
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            @unlink($tmp);
            return ['skip' => 'tmp-open-failed'];
        }

        $ch = curl_init($this->applyAuthQuery($url)); // v3.2.1 token-gated origins
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: image/avif,image/webp,image/png,image/svg+xml,image/*,*/*;q=0.8'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($res, $dlTotal, $dlNow): int {
                // Abort as soon as declared or actual size exceeds the cap.
                if ($dlTotal > self::MAX_BYTES || $dlNow > self::MAX_BYTES) {
                    return 1; // non-zero aborts the transfer
                }
                return 0;
            },
        ]);

        $ok      = curl_exec($ch);
        $errno   = curl_errno($ch);
        $err     = curl_error($ch);
        $code    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype   = strtolower(trim((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        curl_close($ch);
        fclose($fh);

        $cleanup = static function () use ($tmp): void {
            @unlink($tmp);
        };

        if ($errno === CURLE_ABORTED_BY_CALLBACK) {
            $cleanup();
            return ['skip' => 'too-large (>5MB)'];
        }
        if ($ok === false || $errno !== 0) {
            $cleanup();
            return ['skip' => 'curl-error: ' . ($err !== '' ? $err : ('errno ' . $errno))];
        }
        if ($code >= 400 || $code === 0) {
            $cleanup();
            return ['skip' => 'http-' . $code];
        }

        $size = (int) @filesize($tmp);
        if ($size <= 0) {
            $cleanup();
            return ['skip' => 'empty-body'];
        }
        if ($size > self::MAX_BYTES) {
            $cleanup();
            return ['skip' => 'too-large (>5MB)'];
        }

        // Strip charset etc. from content-type.
        $semi = strpos($ctype, ';');
        if ($semi !== false) {
            $ctype = trim(substr($ctype, 0, $semi));
        }

        $ext = self::TYPE_EXT[$ctype] ?? '';
        if ($ext === '') {
            // Generic/missing content-type: sniff the bytes before rejecting.
            $ext = $this->sniffExt($tmp);
            if ($ext === '') {
                $cleanup();
                return ['skip' => 'non-image content-type: ' . ($ctype !== '' ? $ctype : 'unknown')];
            }
        }

        return ['tmp' => $tmp, 'size' => $size, 'ext' => $ext];
    }

    /** Byte-sniff a file for an allowed image format. Returns '' if not an image. */
    private function sniffExt(string $path): string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 512);
        if ($head === '') {
            return '';
        }
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }
        if (strncmp($head, "\x89PNG", 4) === 0) {
            return 'png';
        }
        if (strncmp($head, 'GIF8', 4) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (substr($head, 4, 8) === "ftypavif" || substr($head, 4, 8) === "ftypavis") {
            return 'avif';
        }
        $trim = ltrim($head);
        if (stripos($trim, '<svg') === 0 || (stripos($trim, '<?xml') === 0 && stripos($head, '<svg') !== false)) {
            return 'svg';
        }
        return '';
    }

    /**
     * Sanitized basename from the URL path; sha1-based fallback on
     * collision (different URL, same name) or unusable basename.
     */
    private function chooseFilename(string $url, string $ext, array $taken): string
    {
        $path = (string) (@parse_url($url, PHP_URL_PATH) ?: '');
        $base = $this->basenamePortable($path);
        $base = rawurldecode($base);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $base = trim((string) $base, '.-');

        // Cap length (filesystem safety) without requiring mbstring.
        if ($this->strlenPortable($base) > 80) {
            $base = substr($base, 0, 80);
        }

        $usable = $base !== '' && $base !== $ext;
        if ($usable) {
            // Ensure it carries an allowed image extension; append if missing/odd.
            $dot     = strrpos($base, '.');
            $baseExt = $dot !== false ? strtolower(substr($base, $dot + 1)) : '';
            if (!in_array($baseExt, self::ALLOWED_EXT, true)) {
                $base .= '.' . $ext;
            }
        }

        if (!$usable || isset($taken[$base])) {
            $base = substr(sha1($url), 0, 12) . '.' . $ext;
            // sha1 prefix could still collide with a pre-existing file of the
            // same name from an earlier run for the same URL — that is fine
            // (same URL → same content); for safety suffix if owned by another URL.
            if (isset($taken[$base]) && $taken[$base] !== '' && $taken[$base] !== $url) {
                $i = 2;
                $stem = substr(sha1($url), 0, 12);
                while (isset($taken[$stem . '-' . $i . '.' . $ext])) {
                    $i++;
                }
                $base = $stem . '-' . $i . '.' . $ext;
            }
        }

        return $base;
    }

    /** basename() is locale-sensitive; do it manually for URL paths. */
    private function basenamePortable(string $path): string
    {
        $path = rtrim($path, '/');
        $pos  = strrpos($path, '/');
        return $pos === false ? $path : substr($path, $pos + 1);
    }

    private function strlenPortable(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s, '8bit') : strlen($s);
    }
}
