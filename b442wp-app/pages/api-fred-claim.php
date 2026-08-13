<?php
declare(strict_types=1);
/**
 * GET /api/fred-claim
 * Fred VPS worker claims the next pending job from the fred_queue stage.
 * Returns job JSON or 204 No Content when nothing is waiting.
 */

if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('{}');
}

$token    = $_SERVER['HTTP_X_FRED_TOKEN'] ?? '';
$expected = (string) config('fred_token', '');
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    exit('{"error":"forbidden"}');
}

$row = db()->query(
    "SELECT * FROM conversions WHERE conversion_stage = 'fred_queue' ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(204);
    exit;
}

// Atomic claim — only wins if still fred_queue
db()->prepare(
    "UPDATE conversions SET conversion_stage = 'fred_claimed' WHERE id = ? AND conversion_stage = 'fred_queue'"
)->execute([(int) $row['id']]);

$stage = db()->query(
    "SELECT conversion_stage FROM conversions WHERE id = " . (int) $row['id']
)->fetchColumn();

if ($stage !== 'fred_claimed') {
    http_response_code(204);
    exit;
}

$analysis = json_decode($row['analysis_data'] ?? '{}', true) ?: [];

// Apply quality caps — Fred has no time limit, but cap at reasonable values
$skip_slugs = [
    'login','register','forgotpassword','resetpassword','signup','logout',
    'admin','lladminsection','lladminlogin','lladmindashboard','lladmin',
    'albumdetail','songdetail','eventdetail','productdetail','blogarticle',
];

if (count($analysis['sections'] ?? []) > 12) {
    $analysis['sections'] = array_slice($analysis['sections'], 0, 12);
}

$filtered = [];
foreach ($analysis['sub_pages'] ?? [] as $slug => $pd) {
    if (in_array($slug, $skip_slugs, true)) continue;
    $filtered[$slug] = $pd;
    if (count($filtered) >= 5) break;
}
$analysis['sub_pages'] = $filtered;

echo json_encode([
    'id'              => (int) $row['id'],
    'uuid'            => $row['uuid'],
    'live_url'        => $row['live_url'],
    'theme_name'      => $row['theme_name'] ?? '',
    'has_woocommerce' => (bool) $row['has_woocommerce'],
    'analysis'        => $analysis,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
