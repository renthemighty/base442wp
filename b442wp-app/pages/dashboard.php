<?php
/**
 * pages/dashboard.php — User dashboard showing all conversions.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';
require_auth();

$user = current_user();

// Fetch all conversions for this user, newest first
$stmt = db()->prepare(
    'SELECT * FROM conversions WHERE user_id = ? ORDER BY created_at DESC'
);
$stmt->execute([$user['id']]);
$conversions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Map a conversion row to a unified display structure.
 */
function _dash_conversion_display(array $c): array {
    $status      = $c['status'] ?? 'pending';
    $expires_at  = $c['expires_at'] ?? null;

    // Label
    $label = status_label($status);

    // Badge class
    $badge_class = match(true) {
        in_array($status, ['pending', 'parsed'])          => 'badge--info',
        in_array($status, ['paid', 'converting'])         => 'badge--warning',
        $status === 'complete'                            => 'badge--success',
        $status === 'failed'                              => 'badge--error',
        $status === 'expired'                             => 'badge--neutral',
        default                                           => 'badge--neutral',
    };

    // Spinner (show while actively processing)
    $show_spinner = in_array($status, ['paid', 'converting']);

    // Action button
    $action = null;
    if ($status === 'parsed') {
        $action = ['label' => 'View Preview', 'href' => '/preview/' . $c['uuid'], 'class' => 'btn--primary'];
    } elseif ($status === 'complete') {
        $action = ['label' => 'Download', 'href' => '/download/' . $c['uuid'], 'class' => 'btn--primary'];
    }

    // Expiry info
    $expiry_info = null;
    if ($status === 'complete' && $expires_at) {
        $remaining   = time_remaining($expires_at);
        $is_exp      = is_expired($expires_at);
        if ($is_exp) {
            $expiry_info = ['text' => 'Expired', 'class' => 'expiry-badge--neutral'];
        } else {
            // Determine warning level
            $diff = strtotime($expires_at) - time();
            if ($diff < 3 * 86400) {
                $expiry_info = ['text' => 'Expires in ' . $remaining, 'class' => 'expiry-badge--danger'];
            } elseif ($diff < 7 * 86400) {
                $expiry_info = ['text' => 'Expires in ' . $remaining, 'class' => 'expiry-badge--warning'];
            } else {
                $expiry_info = ['text' => 'Expires in ' . $remaining, 'class' => 'expiry-badge--ok'];
            }
        }
    } elseif ($status === 'expired') {
        $expiry_info = ['text' => 'Expired', 'class' => 'expiry-badge--neutral'];
    }

    // Conversion type + price
    $type_label = match($c['conversion_type'] ?? 'basic') {
        'woocommerce' => 'WooCommerce — ' . format_price(1900),
        default       => 'Basic — ' . format_price(900),
    };

    // Theme name or filename fallback
    $display_name = !empty($c['theme_name'])
        ? $c['theme_name']
        : (!empty($c['original_filename']) ? $c['original_filename'] : 'Untitled project');

    return compact(
        'label', 'badge_class', 'show_spinner',
        'action', 'expiry_info', 'type_label', 'display_name'
    );
}

$page_title = 'Dashboard';
$body_class = 'page-dashboard';
ob_start();
?>
<div class="page-content">
    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header__text">
                <h1 class="page-header__title">Your Conversions</h1>
                <p class="page-header__subtitle">
                    <?php if (count($conversions) === 0): ?>
                        No conversions yet — upload your first project below.
                    <?php else: ?>
                        <?= count($conversions) ?> conversion<?= count($conversions) !== 1 ? 's' : '' ?> total
                    <?php endif; ?>
                </p>
            </div>
            <div class="page-header__actions">
                <a href="<?= base_url('/upload') ?>" class="btn btn--primary btn--lg">
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="M10 14V6M7 9l3-3 3 3" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M4 16h12" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                    </svg>
                    New Conversion
                </a>
            </div>
        </div>

        <?php if (empty($conversions)): ?>
        <!-- Empty State -->
        <div class="empty-state card">
            <div class="empty-state__icon" aria-hidden="true">
                <svg width="56" height="56" viewBox="0 0 56 56" fill="none">
                    <circle cx="28" cy="28" r="27" stroke="#e2e8f0" stroke-width="2"/>
                    <rect x="16" y="18" width="24" height="20" rx="3" stroke="#cbd5e1" stroke-width="1.75" stroke-linejoin="round"/>
                    <path d="M20 24h16M20 28h10" stroke="#cbd5e1" stroke-width="1.75" stroke-linecap="round"/>
                    <circle cx="36" cy="36" r="6" fill="#3b82f6"/>
                    <path d="M36 33v6M33 36h6" stroke="white" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </div>
            <h2 class="empty-state__title">No conversions yet</h2>
            <p class="empty-state__text">
                Upload your first Base44 React project to get a WordPress theme preview — it only takes a minute.
            </p>
            <a href="<?= base_url('/upload') ?>" class="btn btn--primary btn--lg">
                Upload your first project
            </a>
        </div>

        <?php else: ?>
        <!-- Conversions Table -->
        <div class="card card--table">
            <div class="table-responsive">
                <table class="table" aria-label="Conversion history">
                    <thead>
                        <tr>
                            <th scope="col">Project</th>
                            <th scope="col">Status</th>
                            <th scope="col">Type</th>
                            <th scope="col">Created</th>
                            <th scope="col" class="table__col--action">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($conversions as $c):
                            $d = _dash_conversion_display($c);
                        ?>
                        <tr class="table__row">
                            <!-- Project name -->
                            <td class="table__cell table__cell--name">
                                <div class="conversion-name">
                                    <span class="conversion-name__text" title="<?= htmlspecialchars($d['display_name']) ?>">
                                        <?= htmlspecialchars($d['display_name']) ?>
                                    </span>
                                    <span class="conversion-name__uuid">
                                        <?= htmlspecialchars(substr($c['uuid'], 0, 8)) ?>…
                                    </span>
                                </div>
                            </td>

                            <!-- Status badge -->
                            <td class="table__cell table__cell--status">
                                <span class="badge <?= $d['badge_class'] ?>">
                                    <?php if ($d['show_spinner']): ?>
                                    <span class="spinner spinner--sm" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($d['label']) ?>
                                </span>
                                <?php if ($d['expiry_info']): ?>
                                <span
                                    class="expiry-badge <?= $d['expiry_info']['class'] ?>"
                                    <?php if (!empty($c['expires_at'])): ?>
                                    data-expires="<?= htmlspecialchars($c['expires_at']) ?>"
                                    <?php endif; ?>
                                >
                                    <?= htmlspecialchars($d['expiry_info']['text']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if (($c['status'] ?? '') === 'failed'): ?>
                                <a href="mailto:admin@base44towordpress.com?subject=Conversion%20Failed%20<?= urlencode($c['uuid']) ?>"
                                   class="table__support-link">Contact support</a>
                                <?php endif; ?>
                            </td>

                            <!-- Type -->
                            <td class="table__cell table__cell--type">
                                <?= htmlspecialchars($d['type_label']) ?>
                            </td>

                            <!-- Date -->
                            <td class="table__cell table__cell--date">
                                <time datetime="<?= htmlspecialchars($c['created_at']) ?>">
                                    <?= htmlspecialchars(date('M j, Y', strtotime($c['created_at']))) ?>
                                </time>
                            </td>

                            <!-- Action -->
                            <td class="table__cell table__cell--action">
                                <?php if ($d['action']): ?>
                                <a href="<?= base_url($d['action']['href']) ?>"
                                   class="btn btn--sm <?= $d['action']['class'] ?>">
                                    <?= htmlspecialchars($d['action']['label']) ?>
                                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                        <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </a>
                                <?php else: ?>
                                <span class="table__no-action" aria-label="No action available">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pricing reminder -->
        <p class="dashboard__pricing-note">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.25"/>
                <path d="M8 7v5M8 5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            Basic theme conversions are <strong><?= format_price(900) ?></strong> · WooCommerce conversions are <strong><?= format_price(1900) ?></strong> · Downloads expire after 14 days.
        </p>
        <?php endif; ?>

    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
