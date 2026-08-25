<?php
/**
 * ColdAisle — Technician hub (Tech mode).
 *
 * Entry point only: search, recent cabinets, due audits, moves this week,
 * and surface cards. All actions open the real pages/APIs — no duplicated logic.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/audit_helpers.php';
require_once dirname(__DIR__) . '/includes/work_order_helpers.php';

App::boot();
$user = App::requireAuth();

// Ensure tech chrome for this session
if (class_exists('TechMode')) {
    TechMode::enable();
}

// Lookup → real detail/list pages (no second data model)
$q = trim((string)($_GET['q'] ?? ''));
$cabMatches = [];
if ($q !== '' && isset($_GET['go'])) {
    $target = strtolower(trim((string)($_GET['go'] ?? 'device')));
    if ($target === 'cabinet') {
        // Resolve via inventory SQL here only for disambiguation; open real cabinet page
        $like = '%' . $q . '%';
        try {
            $cabMatches = Database::fetchAll(
                "SELECT TOP 25 c.cabinet_id, c.name, r.name AS room_name, dc.name AS dc_name
                 FROM cabinets c
                 INNER JOIN rooms r ON r.room_id = c.room_id
                 INNER JOIN datacenters dc ON dc.datacenter_id = r.datacenter_id
                 WHERE c.is_active = 1
                   AND (c.name LIKE ? OR r.name LIKE ? OR dc.name LIKE ?
                        OR CAST(c.cabinet_id AS NVARCHAR(20)) = ?)
                 ORDER BY c.name",
                [$like, $like, $like, $q]
            );
        } catch (Throwable $e) {
            $cabMatches = [];
        }
        if (count($cabMatches) === 1) {
            $cid = (int)$cabMatches[0]['cabinet_id'];
            App::redirect('pages/cabinets.php?id=' . $cid . '&mode=tech');
        }
        // multiple / zero: show results on hub below
    } else {
        // Device list already supports ?q= (label, serial, asset, IP, …)
        App::redirect('pages/devices.php?q=' . rawurlencode($q));
    }
}

$recent = class_exists('TechMode') ? TechMode::recentCabinets() : [];
$hubCards = class_exists('TechMode') ? TechMode::hubSurfaces($user) : [];

$moves = [];
if (AuthManager::canViewNav($user, 'work_orders')) {
    try {
        $moves = work_order_moves_this_week();
    } catch (Throwable $e) {
        $moves = [];
    }
}

$dueCabs = [];
if (AuthManager::canViewNav($user, 'cabinets') || AuthManager::canViewNav($user, 'audits')) {
    try {
        $dueCabs = audit_overdue_cabinets(12);
    } catch (Throwable $e) {
        $dueCabs = [];
    }
}

// Optional: direct cabinet open by id
$openCab = (int)($_GET['cabinet_id'] ?? 0);
if ($openCab > 0) {
    App::redirect(class_exists('TechMode')
        ? ('pages/cabinets.php?id=' . $openCab . '&mode=tech')
        : ('pages/cabinets.php?id=' . $openCab));
}

layout_header('Home', $user, 'tech');
?>

<p class="text-muted" style="font-size:.82rem;margin:.25rem 0 .75rem">
    Field hub — same inventory as desktop. Scan a cabinet QR or search below.
    <a href="<?= App::e(App::url('pages/docs.php#tech-pwa')) ?>">Tech / PWA docs</a>
</p>
<form class="tech-search-form" method="get" action="">
    <label class="text-muted" style="font-size:.8rem;font-weight:600" for="tech_q">Find device or cabinet</label>
    <input class="form-control" type="search" name="q" id="tech_q" value="<?= App::e($q) ?>"
           placeholder="Serial, asset tag, label, hostname, IP, cabinet name…"
           autocomplete="off" enterkeyhint="search" inputmode="search">
    <div class="flex gap-1" style="display:flex;gap:.5rem">
        <button class="btn btn-primary" type="submit" name="go" value="device" style="flex:1">Find device</button>
        <button class="btn btn-secondary" type="submit" name="go" value="cabinet" style="flex:1">Find cabinet</button>
    </div>
</form>

<?php if ($cabMatches): ?>
<div class="card mb-2">
    <div class="card-header"><h2 style="margin:0;font-size:1rem">Cabinet matches for “<?= App::e($q) ?>”</h2></div>
    <div class="card-body flush">
        <ul class="tech-list">
            <?php foreach ($cabMatches as $cm): ?>
                <li>
                    <a href="<?= App::e(TechMode::cabinetFieldUrl((int)$cm['cabinet_id'])) ?>">
                        <span>
                            <?= App::e((string)$cm['name']) ?>
                            <div class="meta" style="text-align:left;margin-top:.15rem">
                                <?= App::e(trim(($cm['dc_name'] ?? '') . ' / ' . ($cm['room_name'] ?? ''), ' /')) ?>
                            </div>
                        </span>
                        <span class="meta">Open →</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php elseif ($q !== '' && isset($_GET['go']) && strtolower((string)$_GET['go']) === 'cabinet'): ?>
    <div class="alert alert-warning">No cabinets matched “<?= App::e($q) ?>”.</div>
<?php endif; ?>

<?php if ($hubCards): ?>
<div class="tech-card-grid">
    <?php foreach ($hubCards as $card): ?>
        <a class="tech-card" href="<?= App::e(TechMode::surfaceUrl($card)) ?>">
            <span class="tech-card-icon" aria-hidden="true"><?= App::e((string)($card['icon'] ?? '·')) ?></span>
            <strong><?= App::e((string)($card['label'] ?? '')) ?></strong>
            <span><?= App::e((string)($card['hint'] ?? '')) ?></span>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($recent): ?>
<div class="card mb-2">
    <div class="card-header"><h2 style="margin:0;font-size:1rem">Recent cabinets</h2></div>
    <div class="card-body flush">
        <ul class="tech-list">
            <?php foreach ($recent as $r): ?>
                <li>
                    <a href="<?= App::e(TechMode::cabinetFieldUrl((int)$r['id'])) ?>">
                        <span><?= App::e($r['name']) ?></span>
                        <span class="meta">Open →</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($dueCabs): ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0;font-size:1rem">Audits due / overdue</h2>
        <?php if (AuthManager::canViewNav($user, 'audits')): ?>
            <a class="btn btn-sm btn-ghost" href="<?= App::e(App::url('pages/audits.php')) ?>">All</a>
        <?php endif; ?>
    </div>
    <div class="card-body flush">
        <ul class="tech-list">
            <?php foreach ($dueCabs as $c):
                $cid = (int)($c['cabinet_id'] ?? 0);
                $status = (string)($c['audit_status'] ?? $c['status'] ?? '');
                $badge = audit_status_badge_class($status);
                $loc = trim(
                    (string)($c['dc_name'] ?? '') . ' / ' . (string)($c['room_name'] ?? ''),
                    ' /'
                );
                ?>
                <li>
                    <a href="<?= App::e(TechMode::cabinetFieldUrl($cid, true)) ?>">
                        <span>
                            <?= App::e((string)($c['name'] ?? ('#' . $cid))) ?>
                            <span class="badge <?= App::e($badge) ?>" style="margin-left:.35rem">
                                <?= App::e(audit_status_label($status)) ?>
                            </span>
                            <?php if ($loc !== ''): ?>
                                <div class="meta" style="text-align:left;margin-top:.15rem"><?= App::e($loc) ?></div>
                            <?php endif; ?>
                        </span>
                        <span class="meta">Audit →</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($moves): ?>
<div class="card mb-2">
    <div class="card-header flex-between">
        <h2 style="margin:0;font-size:1rem">Moves this week</h2>
        <a class="btn btn-sm btn-ghost" href="<?= App::e(App::url('pages/work_orders.php?week=1')) ?>">All work</a>
    </div>
    <div class="card-body flush">
        <ul class="tech-list">
            <?php foreach ($moves as $w):
                $wid = (int)($w['work_order_id'] ?? 0);
                $items = (int)($w['item_count'] ?? 0);
                $done = (int)($w['done_count'] ?? 0);
                ?>
                <li>
                    <a href="<?= App::e(App::url('pages/work_orders.php?id=' . $wid)) ?>">
                        <span>
                            <?= App::e((string)($w['title'] ?? ('WO #' . $wid))) ?>
                            <div class="meta" style="text-align:left;margin-top:.15rem">
                                <?= App::e((string)($w['scheduled_date'] ?? '')) ?>
                                · <?= App::e((string)($w['status'] ?? '')) ?>
                                · <?= $done ?>/<?= $items ?> items
                            </div>
                        </span>
                        <span class="meta">Open →</span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<p class="text-muted" style="font-size:.8rem;margin:1rem 0 0">
    Scan a cabinet QR plaque to open its elevation. Power map lives on the device page.
    Use the header switch to return to Desktop.
</p>

<?php layout_footer(); ?>
