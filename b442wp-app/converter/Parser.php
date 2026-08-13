<?php
/**
 * Parser — Stage 1 of the B442WP converter pipeline.
 *
 * Accepts the path to a Base44 project zip file, extracts it to a temp
 * directory, recursively scans for relevant source files, reads their
 * contents, and returns a structured array ready for Analyzer.
 */

declare(strict_types=1);

class Parser
{
    private string $zip_path;
    private string $extract_dir;

    /** Maximum file size we will read into memory (500 KB). */
    private const MAX_FILE_SIZE = 512_000;

    /** Extensions we care about. */
    private const SOURCE_EXTENSIONS = ['jsx', 'tsx', 'js', 'ts', 'css'];

    public function __construct(string $zip_path)
    {
        if (!file_exists($zip_path)) {
            throw new InvalidArgumentException("Zip file not found: {$zip_path}");
        }

        $this->zip_path    = $zip_path;
        $this->extract_dir = sys_get_temp_dir()
            . '/b442wp_' . bin2hex(random_bytes(8));
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Parse the zip and return structured source data.
     *
     * @return array{
     *   files: array<string, string>,
     *   globals_css: string|null,
     *   layout_jsx: string|null,
     *   pages_config: string|null,
     *   pages: array<string, string>,
     *   components: array<string, string>,
     *   file_tree: list<string>,
     *   has_typescript: bool,
     *   has_tailwind: bool,
     *   raw_zip_size: int,
     * }
     */
    public function parse(): array
    {
        $raw_zip_size = filesize($this->zip_path) ?: 0;

        $this->extractZip();

        try {
            $files       = $this->readSourceFiles();
            $file_tree   = $this->buildFileTree();
            $pages       = [];
            $components  = [];
            $globals_css  = null;
            $layout_jsx  = null;
            $pages_config = null;
            $app_jsx     = null;
            $tailwind_config = null;

            foreach ($files as $rel_path => $content) {
                $basename = basename($rel_path);
                $lower    = strtolower($basename);
                $lower_stem = strtolower(pathinfo($basename, PATHINFO_FILENAME));

                // ── Special files ────────────────────────────────────────────
                if ($lower === 'globals.css' || $lower === 'global.css' || $lower === 'index.css') {
                    // Prefer globals.css; index.css is fallback
                    if ($globals_css === null || $lower !== 'index.css') {
                        $globals_css = $content;
                    }
                }

                if (
                    $lower === 'layout.jsx'
                    || $lower === 'layout.tsx'
                    || $lower === '_layout.jsx'
                    || $lower === '_layout.tsx'
                ) {
                    $layout_jsx = $content;
                }

                if (
                    $lower === 'pages.config.js'
                    || $lower === 'pages.config.ts'
                ) {
                    $pages_config = $content;
                }

                if (str_starts_with($lower, 'tailwind.config')) {
                    $tailwind_config = $content;
                }

                if (in_array($lower_stem, ['app', '_app'], true)
                    && in_array(strtolower(pathinfo($basename, PATHINFO_EXTENSION)), ['jsx', 'tsx', 'js', 'ts'], true)
                ) {
                    $app_jsx = $content;
                }

                // ── Pages vs Components ───────────────────────────────────────
                if ($this->isPageFile($rel_path)) {
                    $pages[$rel_path] = $content;
                } elseif ($this->isComponentFile($rel_path)) {
                    $components[$rel_path] = $content;
                }
            }

            $has_typescript = false;
            $has_tailwind   = false;

            foreach (array_keys($files) as $path) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === 'tsx' || $ext === 'ts') {
                    $has_typescript = true;
                }
            }

            // Detect Tailwind: check for tailwind.config.* or @tailwind directives
            foreach ($files as $path => $content) {
                $lower_path = strtolower(basename($path));
                if (
                    str_starts_with($lower_path, 'tailwind.config')
                    || str_contains($content, '@tailwind')
                    || str_contains($content, 'tailwindcss')
                ) {
                    $has_tailwind = true;
                    break;
                }
            }

            return [
                'files'           => $files,
                'globals_css'     => $globals_css,
                'layout_jsx'      => $layout_jsx,
                'pages_config'    => $pages_config,
                'app_jsx'         => $app_jsx,
                'tailwind_config' => $tailwind_config,
                'pages'           => $pages,
                'components'      => $components,
                'file_tree'       => $file_tree,
                'has_typescript'  => $has_typescript,
                'has_tailwind'    => $has_tailwind,
                'raw_zip_size'    => $raw_zip_size,
            ];
        } finally {
            $this->cleanup();
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Extract the zip archive to the temp directory.
     */
    private function extractZip(): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($this->zip_path);

        if ($result !== true) {
            throw new RuntimeException(
                "Failed to open zip (ZipArchive code {$result}): {$this->zip_path}"
            );
        }

        if (!mkdir($this->extract_dir, 0o700, true) && !is_dir($this->extract_dir)) {
            $zip->close();
            throw new RuntimeException(
                "Could not create temp extract directory: {$this->extract_dir}"
            );
        }

        $zip->extractTo($this->extract_dir);
        $zip->close();
    }

    /**
     * Recursively read all qualifying source files from the extract directory.
     *
     * @return array<string, string>  relative_path => content
     */
    private function readSourceFiles(): array
    {
        $files = [];
        $base  = rtrim($this->extract_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->extract_dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file_info) {
            /** @var SplFileInfo $file_info */
            if (!$file_info->isFile()) {
                continue;
            }

            $ext = strtolower($file_info->getExtension());
            if (!in_array($ext, self::SOURCE_EXTENSIONS, true)) {
                continue;
            }

            // Skip node_modules, .git, dist, build directories
            $real = $file_info->getRealPath();
            if ($real === false) {
                continue;
            }

            $rel = str_replace($base, '', $real);

            // Skip undesirable directories anywhere in path
            $parts = explode(DIRECTORY_SEPARATOR, $rel);
            $skip  = false;
            foreach ($parts as $part) {
                if (in_array($part, ['node_modules', '.git', 'dist', 'build', '.next', '.nuxt', 'coverage'], true)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $size = $file_info->getSize();
            if ($size > self::MAX_FILE_SIZE) {
                // Record path but not content for very large files
                $files[$rel] = "/* File too large to include ({$size} bytes) */";
                continue;
            }

            $content = file_get_contents($real);
            if ($content === false) {
                continue;
            }

            $files[$rel] = $content;
        }

        return $files;
    }

    /**
     * Build a simple list of all source file paths (relative to the zip root).
     *
     * @return list<string>
     */
    private function buildFileTree(): array
    {
        $tree = [];
        $base = rtrim($this->extract_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $this->extract_dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file_info) {
            /** @var SplFileInfo $file_info */
            if (!$file_info->isFile()) {
                continue;
            }

            $real = $file_info->getRealPath();
            if ($real === false) {
                continue;
            }

            $rel = str_replace($base, '', $real);

            // Skip undesirable directories
            $parts = explode(DIRECTORY_SEPARATOR, $rel);
            $skip  = false;
            foreach ($parts as $part) {
                if (in_array($part, ['node_modules', '.git', 'dist', 'build', '.next', '.nuxt'], true)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $tree[] = $rel;
            }
        }

        sort($tree);
        return $tree;
    }

    /**
     * Determine whether a relative file path is a "page" file.
     *
     * A page file lives inside a pages/ directory (at any depth) or is named
     * with a Pages/Page suffix.
     */
    private function isPageFile(string $path): bool
    {
        // Normalise separators
        $norm = str_replace('\\', '/', $path);

        // Matches: pages/Foo.jsx, src/pages/Bar.tsx, app/pages/foo/index.tsx etc.
        if (preg_match('#(?:^|/)pages/#i', $norm)) {
            $ext = strtolower(pathinfo($norm, PATHINFO_EXTENSION));
            return in_array($ext, ['jsx', 'tsx', 'js', 'ts'], true);
        }

        return false;
    }

    /**
     * Determine whether a relative file path is a "component" file.
     */
    private function isComponentFile(string $path): bool
    {
        $norm = str_replace('\\', '/', $path);

        // Must be a JS/TS/JSX/TSX file
        $ext = strtolower(pathinfo($norm, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jsx', 'tsx', 'js', 'ts'], true)) {
            return false;
        }

        // Matches: components/Foo.jsx, src/components/ui/Button.tsx etc.
        if (preg_match('#(?:^|/)components/#i', $norm)) {
            return true;
        }

        // Also treat any PascalCase .jsx/.tsx outside of pages/ as a component
        $basename = pathinfo($norm, PATHINFO_FILENAME);
        if (
            !$this->isPageFile($path)
            && preg_match('/^[A-Z]/', $basename)
            && in_array($ext, ['jsx', 'tsx'], true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Remove the temporary extraction directory.
     */
    private function cleanup(): void
    {
        if (is_dir($this->extract_dir)) {
            $this->rmdirRecursive($this->extract_dir);
        }
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function rmdirRecursive(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
