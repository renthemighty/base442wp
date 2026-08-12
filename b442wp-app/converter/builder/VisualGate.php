<?php
/**
 * VisualGate — Stage-2 visual gate for WordPress theme converter (base44towordpress v1.0.0).
 *
 * Wraps a Node script (scanner/gate-compare.js) that compares a locally-rendered
 * converted static HTML file against the original site's reference screenshot.
 * Renders via Chromium at 1440x900 viewport, full-page, then pixel-diffs.
 *
 * Standalone — no bootstrap/config dependencies. Requires Node.js + gate-compare.js
 * in the scanner directory.
 */

class VisualGate
{
    /** Absolute path to the scanner/ directory containing gate-compare.js. */
    private string $scannerDir;

    /** Writable temporary directory for generated HTML and diff output. */
    private string $workDir;

    /**
     * Initialize with paths to the scanner directory and a writable work directory.
     *
     * @param string $scannerDir Absolute path to scanner/ containing gate-compare.js
     * @param string $workDir    Writable temporary directory for HTML + diff output
     */
    public function __construct(string $scannerDir, string $workDir)
    {
        $this->scannerDir = rtrim($scannerDir, '/');
        $this->workDir    = rtrim($workDir, '/');
    }

    /**
     * Wrap HTML body in a minimal document, render via Chromium, and compare
     * against a reference screenshot using pixel-diffing.
     *
     * Returns the parsed report from gate-compare.js, which includes:
     *   - verdict: 'pass' | 'review' | 'fail'
     *   - diff_pct: percentage of pixels that differ (0-100)
     *   - width, height: normalized comparison dimensions
     *   - diff_pixels: raw count of differing pixels
     *   - (optional) error: if report generation failed
     *
     * If $referencePng is null/empty/not a file, returns ungated verdict.
     *
     * @param string      $slug           Slug for the generated HTML file (sanitized for filesystem)
     * @param string      $bodyHtml       Body HTML content to wrap and render
     * @param string      $cssHref        Href to stylesheet to link in <head>
     * @param string|null $referencePng   Absolute path to reference PNG screenshot (or null to ungated)
     * @return array{verdict: string, diff_pct: ?float, width?: int, height?: int, diff_pixels?: int, error?: string}
     */
    public function check(string $slug, string $bodyHtml, string $cssHref, ?string $referencePng): array
    {
        // Ungated if reference is missing or not a file.
        if (empty($referencePng) || !is_file($referencePng)) {
            return ['verdict' => 'ungated', 'diff_pct' => null];
        }

        // Ensure work directory exists.
        if (!is_dir($this->workDir)) {
            @mkdir($this->workDir, 0750, true);
        }

        // Sanitize slug for filesystem safety: allow a-z A-Z 0-9 _ -
        $safeName = preg_replace('/[^a-z0-9_-]/i', '-', $slug);
        if ($safeName === '') {
            $safeName = 'page';
        }
        $htmlPath = $this->workDir . '/' . $safeName . '.html';

        // Build minimal HTML document.
        $html = '<!doctype html>'
              . '<html>'
              . '<head>'
              . '<meta charset="utf-8">'
              . '<link rel="stylesheet" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') . '">'
              . '</head>'
              . '<body>'
              . $bodyHtml
              . '</body>'
              . '</html>';

        // Write HTML file.
        if (!@file_put_contents($htmlPath, $html)) {
            return ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'could-not-write-html'];
        }

        // Check if exec() is available (may be stripped on CloudLinux).
        if (!function_exists('exec')) {
            return ['verdict' => 'ungated', 'diff_pct' => null];
        }

        // Build command: node gate-compare.js --ref=<path> --html=<path> --out=<dir>
        $cmd = 'node ' . escapeshellarg($this->scannerDir . '/gate-compare.js')
             . ' --ref=' . escapeshellarg($referencePng)
             . ' --html=' . escapeshellarg($htmlPath)
             . ' --out=' . escapeshellarg($this->workDir)
             . ' 2>&1';

        $output = [];
        $returnCode = 0;
        @exec($cmd, $output, $returnCode);

        // Read the report JSON from $workDir/report.json
        $reportPath = $this->workDir . '/report.json';
        if (!is_file($reportPath)) {
            return ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'no-report-file'];
        }

        $reportJson = @file_get_contents($reportPath);
        if ($reportJson === false) {
            return ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'report-read-failed'];
        }

        $report = @json_decode($reportJson, true);
        if (!is_array($report)) {
            return ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'report-not-json'];
        }

        // Return the parsed report (must contain at least 'verdict' and 'diff_pct').
        return $report;
    }

    /**
     * Run the visual gate for multiple pages concurrently using one Chromium browser.
     *
     * @param array  $pages    [ slug => ['html' => string, 'ref' => ?string], ... ]
     * @param string $cssHref  Href to stylesheet to link in <head>
     * @return array           [ slug => ['verdict' => string, 'diff_pct' => ?float], ... ]
     */
    public function checkBatch(array $pages, string $cssHref): array
    {
        // Ensure work directory exists.
        if (!is_dir($this->workDir)) {
            @mkdir($this->workDir, 0750, true);
        }

        // Build a sub-dir for gate output.
        $gateOut = $this->workDir . '/gateout';
        @mkdir($gateOut, 0750, true);

        $manifest = [];
        $failedWrites = [];

        // For each page, sanitize slug and build the wrapped HTML document.
        foreach ($pages as $slug => $page) {
            // Sanitize slug for filesystem safety: allow a-z A-Z 0-9 _ -
            $safeName = preg_replace('/[^a-z0-9_-]/i', '-', $slug);
            if ($safeName === '') {
                $safeName = 'page';
            }
            $htmlPath = $this->workDir . '/' . $safeName . '.html';

            // Build minimal HTML document.
            $bodyHtml = $page['html'] ?? '';
            $html = '<!doctype html>'
                  . '<html>'
                  . '<head>'
                  . '<meta charset="utf-8">'
                  . '<link rel="stylesheet" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') . '">'
                  . '</head>'
                  . '<body>'
                  . $bodyHtml
                  . '</body>'
                  . '</html>';

            // Write HTML file.
            if (!@file_put_contents($htmlPath, $html)) {
                $failedWrites[$slug] = ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'could-not-write-html'];
                continue;
            }

            // Add to manifest array.
            $manifest[] = [
                'slug' => $slug,
                'ref' => (string)($page['ref'] ?? ''),
                'html' => $htmlPath
            ];
        }

        // Write manifest to JSON file.
        @file_put_contents($this->workDir . '/gate-manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));

        // Check if exec() is available (may be stripped on CloudLinux).
        if (!function_exists('exec')) {
            $result = [];
            foreach ($pages as $slug => $page) {
                $result[$slug] = ['verdict' => 'ungated', 'diff_pct' => null];
            }
            return $result;
        }

        // Build and exec the command: node gate-compare.js --batch=<manifest> --out=<dir> --concurrency=6
        $cmd = 'node ' . escapeshellarg($this->scannerDir . '/gate-compare.js')
             . ' --batch=' . escapeshellarg($this->workDir . '/gate-manifest.json')
             . ' --out=' . escapeshellarg($gateOut)
             . ' --concurrency=6'
             . ' 2>&1';

        $output = [];
        $returnCode = 0;
        @exec($cmd, $output, $returnCode);

        // Read the batch report JSON from $gateOut/batch-report.json
        $batchReportPath = $gateOut . '/batch-report.json';
        if (!is_file($batchReportPath)) {
            $result = [];
            foreach ($pages as $slug => $page) {
                if (isset($failedWrites[$slug])) {
                    $result[$slug] = $failedWrites[$slug];
                } else {
                    $result[$slug] = ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'no-batch-report'];
                }
            }
            return $result;
        }

        $batchReportJson = @file_get_contents($batchReportPath);
        if ($batchReportJson === false) {
            $result = [];
            foreach ($pages as $slug => $page) {
                if (isset($failedWrites[$slug])) {
                    $result[$slug] = $failedWrites[$slug];
                } else {
                    $result[$slug] = ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'batch-report-read-failed'];
                }
            }
            return $result;
        }

        $report = @json_decode($batchReportJson, true);
        if (!is_array($report)) {
            $result = [];
            foreach ($pages as $slug => $page) {
                if (isset($failedWrites[$slug])) {
                    $result[$slug] = $failedWrites[$slug];
                } else {
                    $result[$slug] = ['verdict' => 'fail', 'diff_pct' => null, 'error' => 'batch-report-not-json'];
                }
            }
            return $result;
        }

        // Build result: start with any pre-recorded failures from step 3, then for each slug in pages.
        $result = [];
        foreach ($pages as $slug => $page) {
            if (isset($failedWrites[$slug])) {
                $result[$slug] = $failedWrites[$slug];
            } elseif (isset($report[$slug])) {
                $result[$slug] = [
                    'verdict' => $report[$slug]['verdict'] ?? 'fail',
                    'diff_pct' => $report[$slug]['diff_pct'] ?? null,
                    'render' => $report[$slug]['render'] ?? 'full',
                    'height_mismatch' => $report[$slug]['height_mismatch'] ?? false,
                ];
            } else {
                $result[$slug] = ['verdict' => 'fail', 'diff_pct' => null, 'render' => 'full', 'height_mismatch' => false];
            }
        }

        return $result;
    }
}
