<?php
/**
 * Assembler — Stage 4 of the B442WP pipeline.
 *
 * Takes the [filename => content] array produced by Converter and:
 *  1. Creates the theme directory: {output_dir}/{uuid}/{theme_dir_name}/
 *  2. Writes every file to disk, creating subdirectories as needed
 *  3. Ensures mandatory WordPress skeleton files exist
 *  4. Ensures style.css carries a valid WordPress theme header
 *  5. Creates a screenshot.png placeholder (SVG or GD PNG)
 *  6. Zips the theme directory → {output_dir}/{uuid}/{theme_dir_name}.zip
 *  7. Returns the absolute path to the zip file
 *
 * @package B442WP
 */

declare(strict_types=1);

class Assembler
{
    private string $theme_name;
    private string $theme_slug;      // kebab-case e.g. "malle-calm-flow"
    private string $theme_dir_name;  // "{theme_slug}-theme"
    private string $output_base;     // config('output_dir')

    public function __construct(string $theme_name)
    {
        $this->theme_name     = $theme_name;
        $this->theme_slug     = strtolower(
            trim(preg_replace('/[^a-z0-9]+/i', '-', $theme_name) ?? 'theme', '-')
        );
        if ($this->theme_slug === '') {
            $this->theme_slug = 'theme';
        }
        $this->theme_dir_name = $this->theme_slug . '-theme';
        $this->output_base    = rtrim((string) config('output_dir'), '/');
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Write all generated files to disk and package them as a zip.
     *
     * Output structure:
     *   {output_base}/{uuid}/{theme_dir_name}/          ← theme files
     *   {output_base}/{uuid}/{theme_dir_name}.zip       ← downloadable package
     *
     * @param  array  $files       ['relative/path.php' => 'file content', ...]
     * @param  array  $conversion  DB conversion row (must contain 'uuid')
     * @return string              Absolute path to the output zip file
     * @throws RuntimeException    On directory creation or zip failure
     */
    public function assemble(array $files, array $conversion): string
    {
        $uuid = $conversion['uuid'];

        $conv_dir  = $this->output_base . '/' . $uuid;
        $theme_dir = $conv_dir . '/' . $this->theme_dir_name;
        $zip_path  = $conv_dir . '/' . $this->theme_dir_name . '.zip';

        // ── 1. Create theme output directory ─────────────────────────────────

        if (!is_dir($theme_dir)) {
            if (!mkdir($theme_dir, 0750, true)) {
                throw new RuntimeException('Cannot create theme directory: ' . $theme_dir);
            }
        }

        // ── 2. Ensure style.css has a valid WordPress theme header ───────────

        if (!isset($files['style.css'])) {
            $files['style.css'] = $this->build_style_css($conversion);
        } elseif (!str_contains($files['style.css'], 'Theme Name:')) {
            $files['style.css'] = $this->build_style_css($conversion) . "\n\n" . $files['style.css'];
        }

        // ── 3. Write each file ────────────────────────────────────────────────

        foreach ($files as $relative_path => $content) {
            $this->write_file($theme_dir, $relative_path, (string) $content);
        }

        // ── 4. Ensure mandatory WordPress skeleton files exist ────────────────

        $this->ensure_skeleton_files($theme_dir, $conversion);

        // ── 5. Screenshot placeholder ─────────────────────────────────────────

        if (!file_exists($theme_dir . '/screenshot.png')) {
            $this->create_screenshot($theme_dir);
        }

        // ── 6. Zip everything ─────────────────────────────────────────────────

        $this->zip_directory($theme_dir, $zip_path);

        return $zip_path;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Write a single file, sanitising its path and creating parent directories.
     *
     * Path traversal sequences (../ and ..\) are stripped before writing.
     */
    private function write_file(string $base_dir, string $relative_path, string $content): void
    {
        // Sanitize path (prevent directory traversal)
        $relative_path = ltrim(str_replace(['../', '..\\'], '', $relative_path), '/');

        if ($relative_path === '') {
            return;
        }

        $full_path = $base_dir . '/' . $relative_path;
        $dir       = dirname($full_path);

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        file_put_contents($full_path, $content);
    }

    /**
     * Create a screenshot.png placeholder in the theme root.
     *
     * WordPress expects a 1200 × 900 px screenshot.png.
     * Tries GD for a real PNG; falls back to saving the SVG as-is (which
     * works for local previews — a real PNG can be dropped in later).
     */
    private function create_screenshot(string $theme_dir): void
    {
        // Try GD first for an actual PNG
        if (function_exists('imagecreatetruecolor')) {
            try {
                $im  = imagecreatetruecolor(1200, 900);
                $bg  = imagecolorallocate($im, 15, 23, 42);   // #0f172a
                $nav = imagecolorallocate($im, 30, 41, 59);   // #1e293b
                $fg  = imagecolorallocate($im, 248, 250, 252); // #f8fafc
                $sub = imagecolorallocate($im, 148, 163, 184); // #94a3b8

                imagefill($im, 0, 0, $bg);
                imagefilledrectangle($im, 0, 0, 1200, 60, $nav);
                imagestring($im, 5, 50, 22, $this->theme_name, $fg);
                imagestring($im, 5, 300, 420, $this->theme_name, $fg);
                imagestring($im, 3, 220, 460, 'Converted from Base44 by base44towordpress.com', $sub);

                imagepng($im, $theme_dir . '/screenshot.png');
                imagedestroy($im);
                return;
            } catch (\Throwable $e) {
                // fall through to SVG fallback
            }
        }

        // SVG fallback — saved as screenshot.png (accepted by WordPress for
        // theme preview; a proper PNG can be substituted later)
        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900">
  <rect width="1200" height="900" fill="#0f172a"/>
  <rect x="0" y="0" width="1200" height="60" fill="#1e293b"/>
  <text x="50" y="38" font-family="system-ui,sans-serif" font-size="18" font-weight="700" fill="#f8fafc">
    {$this->theme_name}
  </text>
  <text x="600" y="470" font-family="system-ui,sans-serif" font-size="32" font-weight="900" fill="#f8fafc" text-anchor="middle">
    {$this->theme_name}
  </text>
  <text x="600" y="510" font-family="system-ui,sans-serif" font-size="16" fill="#94a3b8" text-anchor="middle">
    Converted from Base44 by base44towordpress.com
  </text>
</svg>
SVG;

        file_put_contents($theme_dir . '/screenshot.png', $svg);
    }

    /**
     * Recursively zip a source directory into an output zip file.
     *
     * Files are stored inside the zip as {theme_dir_name}/path/to/file so that
     * unzipping the archive produces a ready-to-install WordPress theme folder.
     *
     * @throws RuntimeException  If ZipArchive is unavailable or the zip cannot be created.
     */
    private function zip_directory(string $source_dir, string $output_zip): bool
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive extension is required but not available.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($output_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create zip file: ' . $output_zip);
        }

        $source_dir = rtrim((string) realpath($source_dir), '/');
        $parent_dir = dirname($source_dir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_path = $file->getRealPath();
                $relative  = substr($file_path, strlen($parent_dir) + 1);
                $zip->addFile($file_path, $relative);
            }
        }

        return $zip->close();
    }

    // ─── Additional private helpers ───────────────────────────────────────────

    /**
     * Build a WordPress-valid style.css theme header from the skeleton template.
     */
    private function build_style_css(array $conversion): string
    {
        $tpl_path = dirname(__DIR__) . '/wp-skeleton/style.css.tpl';

        if (file_exists($tpl_path)) {
            $tpl = file_get_contents($tpl_path) ?: '';
        } else {
            $tpl = "/*\nTheme Name: {THEME_NAME}\nDescription: {DESCRIPTION}\nVersion: 1.0.0\nText Domain: {TEXT_DOMAIN}\n*/";
        }

        $has_woo     = !empty($conversion['has_woocommerce']);
        $description = 'Converted from Base44 by Base44 to WordPress (base44towordpress.com).'
            . ($has_woo ? ' Includes WooCommerce support.' : '');

        $replacements = [
            '{THEME_NAME}'   => $this->theme_name,
            '{AUTHOR}'       => 'Base44 to WordPress',
            '{DESCRIPTION}'  => $description,
            '{TEXT_DOMAIN}'  => $this->theme_slug,
            '{CONST_PREFIX}' => strtoupper($this->theme_slug),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $tpl);
    }

    /**
     * Ensure all mandatory WordPress theme files are present.
     *
     * Any file missing from the theme directory is sourced from the
     * wp-skeleton/ stubs with placeholder values filled in.
     */
    private function ensure_skeleton_files(string $theme_dir, array $conversion): void
    {
        $skeleton_dir = dirname(__DIR__) . '/wp-skeleton';

        // Map: WordPress filename → skeleton template filename
        $mandatory = [
            'index.php'      => 'index.php.tpl',
            'page.php'       => 'page.php.tpl',
            'single.php'     => 'single.php.tpl',
            '404.php'        => '404.php.tpl',
            'front-page.php' => 'front-page.php.tpl',
            'functions.php'  => 'functions-base.php.tpl',
        ];

        foreach ($mandatory as $target => $stub) {
            $target_path = $theme_dir . '/' . $target;
            if (file_exists($target_path)) {
                continue;
            }

            $stub_path = $skeleton_dir . '/' . $stub;
            if (!file_exists($stub_path)) {
                continue;
            }

            $content = file_get_contents($stub_path);
            if ($content === false) {
                continue;
            }

            $content = $this->apply_replacements($content);
            $this->write_file($theme_dir, $target, $content);
        }
    }

    /**
     * Apply template placeholder replacements to a skeleton file string.
     */
    private function apply_replacements(string $content): string
    {
        $replacements = [
            '{THEME_NAME}'   => $this->theme_name,
            '{PREFIX}'       => $this->theme_slug,
            '{TEXT_DOMAIN}'  => $this->theme_slug,
            '{CONST_PREFIX}' => strtoupper($this->theme_slug),
            '{AUTHOR}'       => 'Base44 to WordPress',
            '{DESCRIPTION}'  => '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
}
