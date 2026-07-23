<?php
// admin/audit_log.php – Audit log viewer
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');
$pageTitle = t('audit_log_title');
$activePage = 'audit_log';

$fAction = get('action', '');
$fUser = get('username', '');
$fDays = (int)get('days', 30);
$fOd = get('od', date('Y-m-d', strtotime("-{$fDays} days")));
if (!validateDate($fOd)) $fOd = date('Y-m-d', strtotime("-{$fDays} days"));

$where = ['a.created_at >= ?'];
$params = [$fOd . ' 00:00:00'];
if ($fAction) { $where[] = 'a.action=?'; $params[] = $fAction; }
if ($fUser) { $where[] = '(a.username LIKE ? OR u.username LIKE ? OR u.skutecneJmeno LIKE ?)'; $params[] = '%'.$fUser.'%'; $params[] = '%'.$fUser.'%'; $params[] = '%'.$fUser.'%'; }

$wSQL = 'WHERE ' . implode(' AND ', $where);
$logs = db_all(
    "SELECT a.*, COALESCE(NULLIF(u.skutecneJmeno, ''), u.username, a.username) AS display_username
     FROM audit_log a
     LEFT JOIN users u ON (
         u.username COLLATE utf8mb4_unicode_ci = a.username COLLATE utf8mb4_unicode_ci
         OR u.skutecneJmeno COLLATE utf8mb4_unicode_ci = a.username COLLATE utf8mb4_unicode_ci
     )
     $wSQL
     ORDER BY a.created_at DESC
     LIMIT 1000",
    $params
);

// Unique actions for dropdown
$actions = db_all("SELECT DISTINCT action FROM audit_log ORDER BY action");

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-clock-history me-2"></i><?= t('audit_log_title') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <!-- Filtry -->
    <form method="get" class="filter-bar no-print mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label-dvd"><?= t('action') ?></label>
                <select name="action" class="form-select">
                    <option value=""><?= t('all') ?></option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= h($a['action']) ?>" <?= $fAction===$a['action']?'selected':'' ?>><?= h($a['action']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-dvd"><?= t('user') ?></label>
                <input type="text" name="username" class="form-control" value="<?= h($fUser) ?>" placeholder="admin">
            </div>
            <div class="col-md-2">
                <label class="form-label-dvd"><?= t('od') ?></label>
                <input type="date" name="od" class="form-control" value="<?= h($fOd) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn-dvd btn-dvd-sm">
                    <i class="bi bi-funnel me-1"></i><?= t('filter') ?>
                </button>
            </div>
            <div class="col-auto">
                <a href="audit_log.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem"><?= t('reset') ?></a>
            </div>
        </div>
    </form>

    <!-- Tabulka -->
    <div class="tbl-wrap">
    <div class="overflow-x">
    <table class="tbl">
        <thead>
            <tr>
                <th style="width:120px;"><?= t('cas') ?></th>
                <th style="width:100px;"><?= t('user') ?></th>
                <th style="width:120px;"><?= t('action') ?></th>
                <th style="width:100px;"><?= t('entity') ?></th>
                <th style="width:250px;"><?= t('popis') ?></th>
                <th style="width:100px;">IP</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><code><?= date('d.m. H:i:s', strtotime($log['created_at'])) ?></code></td>
                <td><strong style="color:var(--accent)"><?= h($log['display_username']) ?></strong></td>
                <td>
                    <span style="background:rgba(91,141,238,.2);color:#5b8dee;padding:.2rem .5rem;border-radius:4px;font-size:.8rem;font-weight:500;">
                        <?= h($log['action']) ?>
                    </span>
                </td>
                <td style="color:var(--muted);font-size:.8rem;">
                    <?= h($log['entity_type'] ?? '—') ?>
                    <?php if ($log['entity_id']): ?><code>#<?= $log['entity_id'] ?></code><?php endif; ?>
                </td>
                <td><?= h($log['description'] ?? '—') ?></td>
                <td><code style="font-size:.75rem;color:var(--muted);"><?= h($log['ip_address']) ?></code></td>
            </tr>
            <?php if ($log['new_data'] || $log['old_data']): ?>
            <tr style="background:rgba(255,255,255,.02);border-bottom:1px solid rgba(255,255,255,.05);">
                <td colspan="6" style="padding:0.75rem;">
                    <details style="cursor:pointer;">
                        <summary style="color:var(--muted);font-size:.8rem;font-weight:500;">
                            <i class="bi bi-chevron-right me-1"></i><?= t('change_details') ?>
                        </summary>
                        <div style="margin-top:0.5rem;padding:0.5rem;background:rgba(0,0,0,.2);border-radius:4px;font-family:var(--mono);font-size:.75rem;white-space:pre-wrap;word-break:break-all;color:var(--muted);">
                            <?php
                            if ($log['old_data']) {
                                echo "<strong>" . t('old_data') . ":</strong>\n";
                                $old = json_decode($log['old_data'], true);
                                echo json_encode($old, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                                echo "\n\n";
                            }
                            if ($log['new_data']) {
                                echo "<strong>" . t('new_data') . ":</strong>\n";
                                $new = json_decode($log['new_data'], true);
                                echo json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                            }
                            ?>
                        </div>
                    </details>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty($logs)): ?>
            <tr>
                <td colspan="6" style="text-align:center;color:var(--muted);padding:2rem;">
                    <i class="bi bi-inbox"></i> <?= t('no_records') ?>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
    <div class="tbl-footer"><span><?= count($logs) ?> <?= t('max_records_hint') ?></span></div>

    <div style="margin-top:1rem;font-size:.8rem;color:var(--muted);">
        <?= t('displayed_records') ?>
    </div>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>
