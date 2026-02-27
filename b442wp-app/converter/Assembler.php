<?php
/**
 * Assembler — Stage 4 of the B442WP Converter Pipeline
 *
 * Takes the array of [filename => content] produced by Converter and:
 *  1. Creates the theme directory: {output_dir}/{uuid}/{prefix}-theme/
 *  2. Writes every file into that directory
 *  3. Generates style.css (WordPress theme header from wp-skeleton template)
 *  4. Creates a placeholder screenshot.png
 *  5. Zips the theme directory into {output_dir}/{uuid}/{prefix}-theme.zip
 *  6. Returns the absolute path to the zip file
 */

declare(strict_types=1);

class Assembler
{
    private string $output_dir;
    private string $theme_name;
    private string $prefix;      // e.g. "malle" — used for directory names
    private string $uuid;

    public function __construct(string $theme_name, string $uuid = '')
    {
        $this->output_dir = rtrim((string)(config('output_dir') ?? (dirname(__DIR__) . '/storage/conversions')), '/');
        $this->theme_name = $theme_name;
        $this->prefix     = self::slugify($theme_name);
        $this->uuid       = $uuid ?: bin2hex(random_bytes(8));
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Assemble all generated files into a WordPress theme zip.
     *
     * @param  array  $files      [relative_path => content]
     * @param  array  $conversion  Full conversion DB row (for metadata)
     * @return string              Absolute path to the created zip file
     */
    public function assemble(array $files, array $conversion): string
    {
        $uuid = $conversion['uuid'] ?? $this->uuid;

        // Build output dirs
        $conv_dir  = $this->output_dir . '/' . $uuid;
        $theme_dir = $conv_dir . '/' . $this->prefix . '-theme';

        if (!is_dir($conv_dir) && !mkdir($conv_dir, 0755, true)) {
            throw new RuntimeException("Cannot create conversion directory: $conv_dir");
        }

        // ── 1. Write style.css (WP theme header) ─────────────────────────────

        if (!isset($files['style.css'])) {
            $files['style.css'] = $this->build_style_css($conversion);
        } else {
            // Ensure a proper theme header exists at the top
            if (!str_contains($files['style.css'], 'Theme Name:')) {
                $files['style.css'] = $this->build_style_css($conversion) . "\n\n" . $files['style.css'];
            }
        }

        // ── 2. Write all files ────────────────────────────────────────────────

        foreach ($files as $relative_path => $content) {
            $this->write_file($theme_dir, $relative_path, (string) $content);
        }

        // ── 3. Ensure mandatory WP theme files exist ──────────────────────────

        $this->ensure_skeleton_files($theme_dir, $conversion);

        // ── 4. Screenshot placeholder ─────────────────────────────────────────

        if (!file_exists($theme_dir . '/screenshot.png')) {
            $this->create_screenshot($theme_dir, $conversion);
        }

        // ── 5. Zip ────────────────────────────────────────────────────────────

        $zip_name = $this->prefix . '-theme.zip';
        $zip_path = $conv_dir . '/' . $zip_name;

        if (!$this->zip_directory($theme_dir, $zip_path)) {
            throw new RuntimeException("Failed to create theme zip: $zip_path");
        }

        return $zip_path;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the WordPress style.css theme header.
     */
    private function build_style_css(array $conversion): string
    {
        $tpl_path = dirname(__DIR__) . '/wp-skeleton/style.css.tpl';

        if (file_exists($tpl_path)) {
            $tpl = file_get_contents($tpl_path);
        } else {
            $tpl = "/*\nTheme Name: {THEME_NAME}\nDescription: {DESCRIPTION}\nVersion: 1.0.0\nText Domain: {TEXT_DOMAIN}\n*/";
        }

        $has_woo    = !empty($conversion['has_woocommerce']);
        $description = 'Converted from Base44 by Base44 to WordPress (base44towordpress.com).'
            . ($has_woo ? ' Includes WooCommerce support.' : '');

        $replacements = [
            '{THEME_NAME}'   => $this->theme_name,
            '{AUTHOR}'       => 'Base44 to WordPress',
            '{DESCRIPTION}'  => $description,
            '{TEXT_DOMAIN}'  => $this->prefix,
            '{CONST_PREFIX}' => strtoupper($this->prefix),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $tpl);
    }

    /**
     * Ensure mandatory WordPress theme files exist (using wp-skeleton stubs if needed).
     */
    private function ensure_skeleton_files(string $theme_dir, array $conversion): void
    {
        $skeleton_dir = dirname(__DIR__) . '/wp-skeleton';

        $mandatory = [
            'index.php'       => 'index.php.tpl',
            'page.php'        => 'page.php.tpl',
            'single.php'      => 'single.php.tpl',
            '404.php'         => '404.php.tpl',
            'front-page.php'  => 'front-page.php.tpl',
            'functions.php'   => 'functions-base.php.tpl',
        ];

        foreach ($mandatory as $target => $stub) {
            $target_path = $theme_dir . '/' . $target;
            if (!file_exists($target_path)) {
                $stub_path = $skeleton_dir . '/' . $stub;
                if (file_exists($stub_path)) {
                    $content = file_get_contents($stub_path);
                    $content = $this->apply_replacements($content, $conversion);
                    $this->write_file($theme_dir, $target, $content);
                }
            }
        }
    }

    /**
     * Apply template placeholders to a skeleton file.
     */
    private function apply_replacements(string $content, array $conversion): string
    {
        $replacements = [
            '{THEME_NAME}'    => $this->theme_name,
            '{PREFIX}'        => $this->prefix,
            '{TEXT_DOMAIN}'   => $this->prefix,
            '{CONST_PREFIX}'  => strtoupper($this->prefix),
            '{AUTHOR}'        => 'Base44 to WordPress',
            '{SECTION_PARTS}' => '',  // Filled by Converter for front-page.php
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Create a simple SVG screenshot placeholder as PNG.
     * WordPress needs screenshot.png (1200x900) in the theme root.
     * We generate a simple colored SVG and save it. Real hosting will replace it.
     */
    private function create_screenshot(string $theme_dir, array $conversion): void
    {
        // Generate a simple placeholder as an SVG (browsers accept this for WP theme preview)
        // For actual PNG, GD/Imagick would be needed — this is a safe fallback
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900">
  <rect width="1200" height="900" fill="#0f172a"/>
  <rect x="0" y="0" width="1200" height="64" fill="#1e293b"/>
  <text x="32" y="42" font-family="system-ui,sans-serif" font-size="20" font-weight="700" fill="#f8fafc">{$this->theme_name}</text>
  <text x="600" y="460" font-family="system-ui,sans-serif" font-size="48" font-weight="900" fill="#f8fafc" text-anchor="middle">
    {$this->theme_name}
  </text>
  <text x="600" y="520" font-family="system-ui,sans-serif" font-size="20" fill="#94a3b8" text-anchor="middle">
    WordPress Theme — Converted by Base44 to WordPress
  </text>
</svg>
SVG;

        // Try to save as PNG via GD if available, fall back to SVG renamed as PNG
        if (function_exists('imagecreatetruecolor')) {
            try {
                $im  = imagecreatetruecolor(1200, 900);
                $bg  = imagecolorallocate($im, 15, 23, 42);
                $fg  = imagecolorallocate($im, 248, 250, 252);
                imagefill($im, 0, 0, $bg);
                imagestring($im, 5, 50, 420, $this->theme_name, $fg);
                imagestring($im, 3, 50, 460, 'Converted by Base44 to WordPress', $fg);
                imagepng($im, $theme_dir . '/screenshot.png');
                imagedestroy($im);
                return;
            } catch (Throwable $e) {
                // fall through to SVG fallback
            }
        }

        // SVG fallback (works for local preview, not official WP theme screenshots)
        file_put_contents($theme_dir . '/screenshot.png', $svg);
    }

    /**
     * Write a single file, creating intermediate directories as needed.
     */
    private function write_file(string $base_dir, string $relative_path, string $content): void
    {
        // Sanitize path — prevent path traversal
        $relative_path = ltrim(str_replace(['../', './', '\\'], ['', '', '/'], $relative_path), '/');

        if (empty($relative_path)) {
            return;
        }

        $full_path = $base_dir . '/' . $relative_path;
        $dir       = dirname($full_path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($full_path, $content);
    }

    /**
     * Recursively zip a directory.
     */
    private function zip_directory(string $source_dir, string $output_zip): bool
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is required');
        }

        $source_dir = realpath($source_dir);
        if (!$source_dir || !is_dir($source_dir)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($output_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $base_name = basename($source_dir);
        $iterator  = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $file_path     = $file->getRealPath();
            $relative_path = $base_name . '/' . substr($file_path, strlen($source_dir) + 1);

            $zip->addFile($file_path, $relative_path);
        }

        return $zip->close();
    }

    /**
     * Convert a theme name to a URL-safe slug.
     * "Malle Calm Flow" → "malle-calm-flow"
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 64);
        return $slug ?: 'theme';
    }
}
