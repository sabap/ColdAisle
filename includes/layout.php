<?php
/**
 * ColdAisle - Shared layout helpers
 *
 * Tech mode (TechMode): same layout_header/footer entry points; chrome only.
 * Business logic stays on the real pages/APIs.
 */
declare(strict_types=1);

/** @var array{title:string,user:array,active:string,tech:bool}|null */
$GLOBALS['coldaisle_layout_ctx'] = $GLOBALS['coldaisle_layout_ctx'] ?? null;

function layout_header(string $title, array $user, string $active = ''): void
{
    $appName = App::appName();
    $org = App::config('org_name', '');
    $display = $user['display_name'] ?: $user['username'];
    $csrf = App::csrfToken();
    $flashes = App::getFlashes();
    $httpsMismatch = App::httpsConfigMismatch();
    $tech = class_exists('TechMode') && TechMode::isActive();
    $GLOBALS['coldaisle_layout_ctx'] = [
        'title' => $title,
        'user' => $user,
        'active' => $active,
        'tech' => $tech,
    ];
    $unread = 0;
    try {
        $unread = (int) Database::fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0',
            [(int)$user['user_id']]
        );
    } catch (Throwable $e) {
        // ignore
    }
    // Flashes already read; free session lock so media.php / parallel requests are not blocked
    App::releaseSessionLock();

    $cssV = preg_replace('/\W+/', '', (string)App::VERSION) . '46';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= App::e($csrf) ?>">
    <meta name="theme-color" content="#0f172a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title><?= App::e($title) ?> · <?= App::e($appName) ?><?= $tech ? ' · Tech' : '' ?></title>
    <link rel="icon" href="<?= App::e(App::url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= App::e(App::url('assets/img/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= App::e(App::url('assets/img/favicon-180.png')) ?>" sizes="180x180">
    <link rel="stylesheet" href="<?= App::e(App::url('assets/css/app.css')) ?>?v=<?= App::e($cssV) ?>">
    <?php if ($tech): ?>
    <link rel="stylesheet" href="<?= App::e(App::url('assets/css/tech.css')) ?>?v=<?= App::e($cssV) ?>">
    <?php endif; ?>
    <script>
    window.ColdAisle = {
      baseUrl: <?= json_encode(App::baseUrl()) ?>,
      csrf: <?= json_encode($csrf) ?>,
      tempUnit: <?= json_encode(class_exists('TempUnitService') ? TempUnitService::siteUnit() : 'C') ?>,
      tempSymbol: <?= json_encode(class_exists('TempUnitService') ? TempUnitService::symbol() : '°C') ?>,
      liveToasts: true,
      techMode: <?= $tech ? 'true' : 'false' ?>
    };
    window.WINDCIM = window.ColdAisle; // legacy alias
    </script>
</head>
<body class="<?= $tech ? 'tech-mode' : '' ?>">
<div class="app-shell">
    <?php if ($tech):
        layout_tech_shell_open($title, $user, $active, $appName, $display, $unread, $flashes, $httpsMismatch);
        return;
    endif; ?>
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
            <img class="brand-logo" src="<?= App::e(App::url('assets/img/logo.svg')) ?>" width="36" height="36" alt="">
            <div>
                <strong><?= App::e($appName) ?></strong>
                <?php if ($org): ?><small><?= App::e($org) ?></small><?php endif; ?>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php
            $nav = [
                'dashboard' => ['Dashboard', 'index.php', '▣'],
                'tech' => ['Tech mode', 'pages/tech.php?mode=tech', '📱'],
                'floorplan' => ['Floor Planner', 'pages/floorplan.php', '▦'],
                'datacenters' => ['Data Centers', 'pages/datacenters.php', '🏛'],
                'cabinets' => ['Cabinets', 'pages/cabinets.php', '▤'],
                'devices' => ['Devices', 'pages/devices.php', '🖥'],
                'power' => ['Power', 'pages/power.php', '⚡'],
                'cooling' => ['Cooling', 'pages/cooling.php', '❄'],
                'cables' => ['Cabling', 'pages/cables.php', '🔌'],
                'snmp' => ['SNMP', 'pages/snmp.php', '📡'],
                'work_orders' => ['Work orders', 'pages/work_orders.php', '📋'],
                'disposals' => ['Decommission', 'pages/disposals.php', '🗑'],
                'audits' => ['Audits', 'pages/audits.php', '✓'],
                'reports' => ['Reports', 'pages/reports.php', '📊'],
                'users' => ['Users & Depts', 'pages/users.php', '👤'],
                'settings' => ['Settings', 'pages/settings.php', '⚙'],
            ];
            $devicesActive = in_array($active, ['devices', 'device_templates'], true);
            $powerActive = in_array($active, ['power', 'power_zones', 'power_pdus', 'power_pdu_templates', 'power_templates', 'power_ups'], true);
            $coolingActive = in_array($active, ['cooling', 'cooling_units', 'env_sensors'], true);
            foreach ($nav as $key => [$label, $href, $icon]):
                if (!AuthManager::canViewNav($user, $key)) {
                    continue;
                }
                $cls = ($active === $key
                    || ($key === 'devices' && $devicesActive)
                    || ($key === 'power' && $powerActive)
                    || ($key === 'cooling' && $coolingActive)) ? 'active' : '';
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
                    <a class="nav-item nav-sub <?= $active === 'power_ups' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power_ups.php')) ?>">
                        <span class="nav-icon"></span><span>UPS</span>
                    </a>
                    <a class="nav-item nav-sub <?= in_array($active, ['power_pdu_templates', 'power_templates'], true) ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power_pdu_templates.php')) ?>">
                        <span class="nav-icon"></span><span>Power Templates</span>
                    </a>
                <?php endif; ?>
                <?php if ($key === 'cooling'): ?>
                    <a class="nav-item nav-sub <?= $active === 'cooling' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/cooling.php')) ?>">
                        <span class="nav-icon"></span><span>Dashboard</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'cooling_units' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/cooling_units.php')) ?>">
                        <span class="nav-icon"></span><span>Air &amp; pumps</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'env_sensors' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/env_sensors.php')) ?>">
                        <span class="nav-icon"></span><span>Env sensors</span>
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
                <a class="notif-badge" href="<?= App::e(App::url('pages/notifications.php')) ?>"
                   title="Notifications"
                   <?= $unread < 1 ? 'hidden' : '' ?>><?= (int)$unread ?></a>
            </div>
        </header>
        <main class="content">
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= App::e($f['type']) ?>"><?= App::e($f['message']) ?></div>
            <?php endforeach; ?>
    <?php
}

/**
 * Tech-mode chrome open (main content follows until layout_footer).
 *
 * @param list<array{type:string,message:string}> $flashes
 */
function layout_tech_shell_open(
    string $title,
    array $user,
    string $active,
    string $appName,
    string $display,
    int $unread,
    array $flashes,
    bool $httpsMismatch
): void {
    $exitUrl = class_exists('TechMode')
        ? TechMode::disableUrl(null)
        : App::url('index.php?mode=full');
    // Prefer returning to the same logical page in full mode
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && class_exists('TechMode')) {
        $base = App::basePath();
        $rel = $script;
        if ($base !== '' && str_starts_with(str_replace('\\', '/', $script), $base)) {
            $rel = substr(str_replace('\\', '/', $script), strlen($base)) ?: $script;
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        // Strip mode/field so exit is clean
        if ($qs !== '') {
            parse_str($qs, $q);
            unset($q['mode'], $q['field']);
            $qs = http_build_query($q);
        }
        $path = $rel . ($qs !== '' ? '?' . $qs : '');
        $exitUrl = TechMode::disableUrl($path !== '' ? $path : 'index.php');
    }
    ?>
    <?php if ($httpsMismatch && AuthManager::can($user, 'manage_settings')): ?>
    <div class="alert alert-error" style="margin:0;border-radius:0">
        HTTPS configured but this session is HTTP.
        <a href="<?= App::e(App::url('pages/settings.php#security')) ?>">Settings</a>
    </div>
    <?php endif; ?>
    <div class="main-area">
        <header class="topbar">
            <h1 class="page-title"><?= App::e($title) ?></h1>
            <div class="topbar-actions" style="display:flex;align-items:center;gap:.5rem">
                <a class="notif-badge" href="<?= App::e(App::url('pages/notifications.php')) ?>"
                   title="Notifications"
                   <?= $unread < 1 ? 'hidden' : '' ?>><?= (int)$unread ?></a>
                <a class="tech-exit-link" href="<?= App::e($exitUrl) ?>" title="Leave technician chrome">Desktop</a>
            </div>
        </header>
        <main class="content">
            <div class="tech-banner">
                <strong>Tech mode</strong>
                <span class="text-muted">Field chrome · same data &amp; permissions as desktop</span>
                <span class="text-muted" style="margin-left:auto;font-size:.8rem"><?= App::e($display) ?></span>
            </div>
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= App::e($f['type']) ?>"><?= App::e($f['message']) ?></div>
            <?php endforeach; ?>
    <?php
}

function layout_footer(): void
{
    $ctx = $GLOBALS['coldaisle_layout_ctx'] ?? null;
    $tech = is_array($ctx) && !empty($ctx['tech']);
    $user = is_array($ctx) ? ($ctx['user'] ?? []) : [];
    $active = is_array($ctx) ? (string)($ctx['active'] ?? '') : '';
    $donateUrl = 'https://paypal.me/mattelsberry';
    $timerOn = class_exists('App', false) && App::requestTimerEnabled();
    $timing = $timerOn ? App::requestTimingSnapshot() : null;
    $jsV = preg_replace('/\W+/', '', (string)App::VERSION) . '9';

    if ($tech):
        $nav = (class_exists('TechMode') && $user)
            ? TechMode::navSurfaces($user)
            : [];
        ?>
        </main>
    </div>
    <nav class="tech-bottom-nav" aria-label="Technician navigation">
        <?php foreach ($nav as $s):
            $isActive = class_exists('TechMode') && TechMode::surfaceIsActive($s, $active);
            ?>
            <a class="<?= $isActive ? 'active' : '' ?>"
               href="<?= App::e(TechMode::surfaceUrl($s)) ?>">
                <span class="tech-nav-icon" aria-hidden="true"><?= App::e((string)($s['icon'] ?? '·')) ?></span>
                <span><?= App::e((string)($s['label'] ?? '')) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
<script src="<?= App::e(App::url('assets/js/app.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<script>
(function () {
  if (window.ColdAisle && ColdAisle.liveToasts && typeof ColdAisle.initLiveToasts === 'function') {
    ColdAisle.initLiveToasts();
  }
})();
</script>
</body>
</html>
        <?php
        return;
    endif;
    ?>
        </main>
        <footer class="app-footer">
            ColdAisle v<?= App::VERSION ?> · <?= date('Y') ?>
            · <a href="<?= App::e($donateUrl) ?>" target="_blank" rel="noopener noreferrer">Donate</a>
            · <a href="https://github.com/sabap/ColdAisle" target="_blank" rel="noopener noreferrer">GitHub</a>
            <?php if (class_exists('TechMode')): ?>
                · <a href="<?= App::e(TechMode::enableUrl('pages/tech.php')) ?>">Tech mode</a>
            <?php endif; ?>
            <?php if ($timing): ?>
                <span class="dev-request-timer"
                      id="devRequestTimer"
                      title="Dev request timer (disable debug.request_timer / COLDAISLE_DEBUG when done). SQL = prepare+execute; PHP = server wall − SQL; Browser = after HTML received."
                      data-total-ms="<?= App::e((string)$timing['total_ms']) ?>"
                      data-sql-ms="<?= App::e((string)$timing['sql_ms']) ?>"
                      data-sql-count="<?= App::e((string)$timing['sql_count']) ?>"
                      data-php-ms="<?= App::e((string)$timing['php_ms']) ?>"
                      data-connect-ms="<?= App::e((string)$timing['connect_ms']) ?>">
                    · <span class="dev-timer-server">Server <?= App::e((string)$timing['total_ms']) ?>ms
                        (SQL <?= (int)$timing['sql_count'] ?>q / <?= App::e((string)$timing['sql_ms']) ?>ms
                        · PHP <?= App::e((string)$timing['php_ms']) ?>ms
                        <?php if ($timing['connect_ms'] > 0): ?>
                            · connect <?= App::e((string)$timing['connect_ms']) ?>ms
                        <?php endif; ?>
                        <?php
                        $boot = $timing['boot'] ?? [];
                        if ($boot):
                            $bits = [];
                            foreach ($boot as $phase => $ms) {
                                $bits[] = App::e((string)$phase) . ' ' . App::e((string)$ms) . 'ms';
                            }
                            echo ' · boot: ' . implode(', ', $bits);
                        endif;
                        ?>)
                    </span>
                    <span class="dev-timer-browser"> · Browser …</span>
                </span>
            <?php endif; ?>
        </footer>
    </div>
</div>
<script src="<?= App::e(App::url('assets/js/app.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<script>
(function () {
  if (window.ColdAisle && ColdAisle.liveToasts && typeof ColdAisle.initLiveToasts === 'function') {
    ColdAisle.initLiveToasts();
  }
})();
</script>
</body>
</html>
    <?php
}
