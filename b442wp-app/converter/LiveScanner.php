<?php
/**
 * LiveScanner — Stage 0 of the B442WP conversion pipeline.
 *
 * Shells out to scanner/live-scan.js (Playwright/Chromium) and returns
 * ground-truth design data extracted from the live URL: computed CSS custom
 * properties, getComputedStyle() values for key elements, Google Fonts URLs,
 * image URLs, and desktop/mobile screenshots.
 *
 * Returns null on any error — the pipeline continues with zip-only analysis.
 *
 * @package B442WP
 */

declare(strict_types=1);

class LiveScanner
{
    private string $script_path;

    public function __construct()
    {
        // live-scan.js lives in the app root (public_html/)
        $this->script_path = APP_ROOT . '/live-scan.js';
    }

    /**
     * Scan the live URL and return structured design data, or null on failure.
     *
     * @param  string $url     The live site URL provided by the user.
     * @param  string $out_dir Directory to write screenshots into.
     * @return array|null      Decoded JSON from live-scan.js, or null.
     */
    public function scan(string $url, string $out_dir): ?array
    {
        // Guard: Base44 editor URLs are auth-gated and will hang indefinitely.
        if (str_contains($url, 'app.base44.com')) {
            error_log('[LiveScanner] Skipping Base44 editor URL (auth-gated): ' . $url);
            return null;
        }

        // Validate URL scheme
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            error_log('[LiveScanner] Skipping non-HTTP URL: ' . $url);
            return null;
        }

        if (!file_exists($this->script_path)) {
            error_log('[LiveScanner] live-scan.js not found at: ' . $this->script_path);
            return null;
        }

        if (!is_dir($out_dir)) {
            @mkdir($out_dir, 0755, true);
        }

        $cmd = 'node '
            . escapeshellarg($this->script_path)
            . ' ' . escapeshellarg($url)
            . ' ' . escapeshellarg($out_dir)
            . ' 2>/dev/null';

        $output    = [];
        $exit_code = 0;

        exec($cmd, $output, $exit_code);

        if (empty($output)) {
            error_log('[LiveScanner] No output from live-scan.js for: ' . $url);
            return null;
        }

        $json = implode('', $output);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            error_log('[LiveScanner] JSON parse failed for: ' . $url . ' raw: ' . substr($json, 0, 200));
            return null;
        }

        if (!empty($data['scan_error'])) {
            error_log('[LiveScanner] Scan error for ' . $url . ': ' . $data['scan_error']);
            // Still return partial data — tokens may be populated even with an error
        }

        return $data;
    }
}
