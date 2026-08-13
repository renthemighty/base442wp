<?php
/**
 * Quick test: parse + analyze the Oria zip to verify the rewrite works.
 */

require_once __DIR__ . '/../converter/Parser.php';
require_once __DIR__ . '/../converter/Analyzer.php';

$zip_path = '/Users/simonpainter/Downloads/oria-wellness-lab.zip';

echo "=== PARSER TEST ===\n\n";

$parser = new Parser($zip_path);
$parsed = $parser->parse();

echo "Files found: " . count($parsed['files']) . "\n";
echo "Pages found: " . count($parsed['pages']) . "\n";
echo "Components found: " . count($parsed['components']) . "\n";
echo "Has globals.css: " . ($parsed['globals_css'] ? 'YES' : 'no') . "\n";
echo "Has layout.jsx: " . ($parsed['layout_jsx'] ? 'YES' : 'no') . "\n";
echo "Has pages.config: " . ($parsed['pages_config'] ? 'YES' : 'no') . "\n";
echo "Has tailwind: " . ($parsed['has_tailwind'] ? 'YES' : 'no') . "\n";
echo "Has tailwind_config: " . ($parsed['tailwind_config'] ? 'YES' : 'no') . "\n";
echo "Has app_jsx: " . ($parsed['app_jsx'] ? 'YES' : 'no') . "\n";

echo "\nPage files:\n";
foreach (array_keys($parsed['pages']) as $p) {
    echo "  - {$p}\n";
}
echo "\nComponent files:\n";
foreach (array_keys($parsed['components']) as $c) {
    echo "  - {$c}\n";
}

echo "\n\n=== ANALYZER TEST ===\n\n";

$analyzer = new Analyzer();
$analysis = $analyzer->analyze($parsed);

echo "Theme name: {$analysis['theme_name']}\n";
echo "Archetype: {$analysis['archetype']}\n";
echo "Has WooCommerce: " . ($analysis['has_woocommerce'] ? 'YES' : 'no') . "\n";

echo "\nPages:\n";
foreach ($analysis['pages'] as $page) {
    echo "  - {$page['name']} (/{$page['slug']}) → {$page['file']}\n";
}

echo "\nNav items:\n";
foreach ($analysis['nav_items'] as $item) {
    echo "  - {$item['label']} → {$item['href']}\n";
}

echo "\nColors:\n";
foreach ($analysis['colors'] as $key => $val) {
    if ($key === '_all_vars') {
        echo "  [+ " . count($val) . " project CSS vars]\n";
        continue;
    }
    echo "  {$key}: {$val}\n";
}

echo "\nFonts:\n";
echo "  Primary: {$analysis['fonts']['primary_family']}\n";
echo "  Secondary: {$analysis['fonts']['secondary_family']}\n";

echo "\nHomepage sections (from Home.jsx imports):\n";
foreach ($analysis['home_sections'] as $section) {
    $has_source = (!empty($section['source_code']) && !str_starts_with($section['source_code'], '/*')) ? 'HAS SOURCE' : 'NO SOURCE';
    echo "  - {$section['name']} ({$section['component_name']}) [{$has_source}]\n";
}

echo "\nShared components:\n";
foreach ($analysis['shared_components'] as $name => $info) {
    echo "  - {$name} (used by: " . implode(', ', $info['used_by']) . ")\n";
}

echo "\nCustom CSS classes:\n";
foreach ($analysis['custom_classes'] as $class => $body) {
    echo "  {$class}\n";
}

echo "\nTailwind classes: " . count($analysis['tailwind_classes']) . " unique\n";
echo "Entities: " . count($analysis['entities']) . "\n";
echo "Estimated files: {$analysis['estimated_files']}\n";

echo "\n=== DONE ===\n";
