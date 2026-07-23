<?php
/**
 * ColdAisle - Shared layout helpers
 */
declare(strict_types=1);

function layout_header(string $title, array $user, string $active = ''): void
{
    $appName = App::appName();
    $org = App::config('org_name', '');
    $display = $user['display_name'] ?: $user['username'];
    $csrf = App::csrfToken();
    $flashes = App::getFlashes();
    $httpsMismatch = App::httpsConfigMismatch();
    $unread = 0;
    try {
        $unread = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [(int)$user['user_id']]
        );
    } catch (Throwable $e) {
        // ignore
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= App::e($csrf) ?>">
    <title><?= App::e($title) ?> · <?= App::e($appName) ?></title>
    <link rel="stylesheet" href="<?= App::e(App::url('assets/css/app.css')) ?>?v=22">
    <script>
    window.ColdAisle = { baseUrl: <?= json_encode(App::baseUrl()) ?>, csrf: <?= json_encode($csrf) ?> };
    window.WINDCIM = window.ColdAisle; // legacy alias
    </script>
</head>
<body>
<div class="app-shell">
    <?php if ($httpsMismatch && AuthManager::can($user, 'manage_settings')): ?>
    <div class="alert alert-error" style="margin:0;border-radius:0;border-left:0;border-right:0;border-top:0">
        <strong>HTTPS not active yet.</strong>
        Settings list a public URL starting with <code>https://</code>, but this session is HTTP.
        Install a TLS certificate and HTTPS binding in IIS for that hostname, then enable
        <a href="<?= App::e(App::url('pages/settings.php#security')) ?>">Force HTTPS</a> when ready.
        Links currently use HTTP so the UI keeps working.
    </div>
    <?php endif; ?>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-icon">⚡</span>
            <div>
                <strong><?= App::e($appName) ?></strong>
                <?php if ($org): ?><small><?= App::e($org) ?></small><?php endif; ?>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php
            $nav = [
                'dashboard' => ['Dashboard', 'index.php', '▣'],
                'floorplan' => ['Floor Planner', 'pages/floorplan.php', '▦'],
                'datacenters' => ['Data Centers', 'pages/datacenters.php', '🏛'],
                'cabinets' => ['Cabinets', 'pages/cabinets.php', '▤'],
                'devices' => ['Devices', 'pages/devices.php', '🖥'],
                'power' => ['Power', 'pages/power.php', '⚡'],
                'cables' => ['Cabling', 'pages/cables.php', '🔌'],
                'snmp' => ['SNMP', 'pages/snmp.php', '📡'],
                'disposals' => ['Decommission', 'pages/disposals.php', '🗑'],
                'audits' => ['Audits', 'pages/audits.php', '✓'],
                'reports' => ['Reports', 'pages/reports.php', '📊'],
                'users' => ['Users & Depts', 'pages/users.php', '👤'],
                'settings' => ['Settings', 'pages/settings.php', '⚙'],
            ];
            $devicesActive = in_array($active, ['devices', 'device_templates'], true);
            $powerActive = in_array($active, ['power', 'power_zones', 'power_pdus'], true);
            foreach ($nav as $key => [$label, $href, $icon]):
                if (!AuthManager::canViewNav($user, $key)) {
                    continue;
                }
                $cls = ($active === $key
                    || ($key === 'devices' && $devicesActive)
                    || ($key === 'power' && $powerActive)) ? 'active' : '';
            ?>
                <a class="nav-item <?= $cls ?>" href="<?= App::e(App::url($href)) ?>">
                    <span class="nav-icon"><?= $icon ?></span>
                    <span><?= App::e($label) ?></span>
                </a>
                <?php if ($key === 'devices'): ?>
                    <a class="nav-item nav-sub <?= $active === 'devices' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/devices.php')) ?>">
                        <span class="nav-icon"></span><span>All devices</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'device_templates' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/device_templates.php')) ?>">
                        <span class="nav-icon"></span><span>Templates</span>
                    </a>
                <?php endif; ?>
                <?php if ($key === 'power'): ?>
                    <a class="nav-item nav-sub <?= $active === 'power' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power.php')) ?>">
                        <span class="nav-icon"></span><span>Dashboard</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'power_zones' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power_zones.php')) ?>">
                        <span class="nav-icon"></span><span>Zones</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'power_pdus' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power_pdus.php')) ?>">
                        <span class="nav-icon"></span><span>PDUs</span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-chip">
                <span class="avatar"><?= App::e(mb_strtoupper(mb_substr($display, 0, 1))) ?></span>
                <div>
                    <strong><?= App::e($display) ?></strong>
                    <small><?= App::e($user['role_name'] ?? '') ?></small>
                </div>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= App::e(App::url('logout.php')) ?>">Logout</a>
        </div>
    </aside>
    <div class="main-area">
        <header class="topbar">
            <button type="button" class="btn btn-ghost btn-icon" id="sidebarToggle" aria-label="Toggle menu">☰</button>
            <h1 class="page-title"><?= App::e($title) ?></h1>
            <div class="topbar-actions">
                <?php if ($unread > 0): ?>
                    <a class="notif-badge" href="<?= App::e(App::url('pages/notifications.php')) ?>" title="Notifications"><?= (int)$unread ?></a>
                <?php endif; ?>
            </div>
        </header>
        <main class="content">
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= App::e($f['type']) ?>"><?= App::e($f['message']) ?></div>
            <?php endforeach; ?>
    <?php
}

function layout_footer(): void
{
    $donateUrl = 'https://paypal.me/mattelsberry';
    ?>
        </main>
        <footer class="app-footer">
            ColdAisle v<?= App::VERSION ?> · <?= date('Y') ?>
            · <a href="<?= App::e($donateUrl) ?>" target="_blank" rel="noopener noreferrer">Donate</a>
            · <a href="https://github.com/sabap/ColdAisle" target="_blank" rel="noopener noreferrer">GitHub</a>
        </footer>
    </div>
</div>
<script src="<?= App::e(App::url('assets/js/app.js')) ?>?v=3"></script>
</body>
</html>
    <?php
}
