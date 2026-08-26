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

/**
 * Current app-relative path + query (mode/field stripped) for tech toggle round-trips.
 */
function layout_current_return_path(string $fallback = 'index.php'): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '') {
        return $fallback;
    }
    $base = class_exists('App') ? App::basePath() : '';
    $rel = str_replace('\\', '/', $script);
    if ($base !== '' && str_starts_with($rel, $base)) {
        $rel = substr($rel, strlen($base)) ?: $rel;
    }
    $rel = ltrim($rel, '/');
    if ($rel === '') {
        $rel = $fallback;
    }
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($qs !== '') {
        parse_str($qs, $q);
        unset($q['mode'], $q['field']);
        $qs = http_build_query($q);
    }
    return $rel . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Header slider: On = Tech mode, Off = Desktop. Single control for both shells.
 */
function layout_tech_mode_toggle(bool $on): void
{
    if (!class_exists('TechMode')) {
        return;
    }
    $path = layout_current_return_path('index.php');
    // Entering tech: hub is the landing; leaving: stay on this page in desktop chrome
    $onUrl = TechMode::enableUrl('pages/tech.php');
    // If already on a field surface, re-open same page in tech chrome
    if ($on || preg_match('#^(pages/(cabinets|devices|work_orders|disposals|audits|power_pdus|tech)\.php)#', $path)) {
        if (!str_starts_with($path, 'pages/tech.php') && $path !== 'index.php') {
            $onUrl = TechMode::enableUrl($path);
        }
    }
    $offUrl = TechMode::disableUrl(
        (str_starts_with($path, 'pages/tech.php') || $path === 'index.php')
            ? 'index.php'
            : $path
    );
    $uid = 'techModeToggle';
    ?>
    <label class="tech-mode-switch" title="<?= $on ? 'Tech mode on — tap for Desktop' : 'Tech mode off — tap for Tech' ?>">
        <span class="tech-mode-switch-label"><?= $on ? 'Tech' : 'Desktop' ?></span>
        <span class="tech-mode-switch-track">
            <input type="checkbox" id="<?= App::e($uid) ?>"
                   <?= $on ? 'checked' : '' ?>
                   data-on-url="<?= App::e($onUrl) ?>"
                   data-off-url="<?= App::e($offUrl) ?>"
                   aria-label="Tech mode">
            <span class="tech-mode-switch-thumb" aria-hidden="true"></span>
        </span>
    </label>
    <script>
    (function () {
      var el = document.getElementById(<?= json_encode($uid) ?>);
      if (!el || el.dataset.bound) return;
      el.dataset.bound = '1';
      el.addEventListener('change', function () {
        var url = el.checked ? el.getAttribute('data-on-url') : el.getAttribute('data-off-url');
        if (url) window.location.href = url;
      });
    })();
    </script>
    <?php
}

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

    $cssV = preg_replace('/\W+/', '', (string)App::VERSION) . '65';
    $wizAuto = false;
    $wizRisk = ['warn' => false, 'message' => '', 'counts' => []];
    $tourActive = false;
    if (class_exists('SetupWizardService') && AuthManager::can($user, 'manage_settings')) {
        try {
            $wizAuto = SetupWizardService::shouldAutoOpen($user);
            $wizRisk = SetupWizardService::riskAssessment();
        } catch (Throwable $e) {
            $wizAuto = false;
        }
    }
    if (class_exists('SiteTourService') && !$wizAuto && !$tech) {
        try {
            $tourActive = SiteTourService::isActive();
        } catch (Throwable $e) {
            $tourActive = false;
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= App::e($csrf) ?>">
    <meta name="theme-color" content="#0f172a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?= App::e(App::url('manifest.php')) ?>">
    <title><?= App::e($title) ?> · <?= App::e($appName) ?><?= $tech ? ' · Tech' : '' ?></title>
    <link rel="icon" href="<?= App::e(App::url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= App::e(App::url('assets/img/favicon-32.png')) ?>" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="<?= App::e(App::url('assets/img/favicon-180.png')) ?>" sizes="180x180">
    <style>
      #globalSearchPalette { display: none !important; }
      #globalSearchPalette.is-open { display: flex !important; }
    </style>
    <link rel="stylesheet" href="<?= App::e(App::url('assets/css/app.css')) ?>?v=<?= App::e($cssV) ?>">
    <link rel="stylesheet" href="<?= App::e(App::url('assets/css/tech.css')) ?>?v=<?= App::e($cssV) ?>">
    <script>
    window.ColdAisle = {
      baseUrl: <?= json_encode(App::baseUrl()) ?>,
      csrf: <?= json_encode($csrf) ?>,
      tempUnit: <?= json_encode(class_exists('TempUnitService') ? TempUnitService::siteUnit() : 'C') ?>,
      tempSymbol: <?= json_encode(class_exists('TempUnitService') ? TempUnitService::symbol() : '°C') ?>,
      liveToasts: true,
      techMode: <?= $tech ? 'true' : 'false' ?>,
      setupWizardAuto: <?= $wizAuto ? 'true' : 'false' ?>,
      setupWizardRisk: <?= json_encode($wizRisk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      siteTourActive: <?= (!empty($tourActive) ? 'true' : 'false') ?>,
      searchUrl: <?= json_encode(App::url('api/search.php')) ?>
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
    <aside class="sidebar" id="sidebar" data-tour="sidebar">
        <div class="sidebar-brand">
            <img class="brand-logo" src="<?= App::e(App::url('assets/img/logo.svg')) ?>" width="36" height="36" alt="">
            <div>
                <strong><?= App::e($appName) ?></strong>
                <?php if ($org): ?><small><?= App::e($org) ?></small><?php endif; ?>
            </div>
        </div>
        <button type="button" class="sidebar-find" id="sidebarFindBtn" data-open-find
                title="Jump to a cabinet, device, PDU, or work order">
            <span class="sidebar-find-label">Find…</span>
            <kbd>/</kbd>
        </button>
        <nav class="sidebar-nav">
            <?php
            $nav = [
                'dashboard' => ['Dashboard', 'index.php', '▣', 'nav-dashboard'],
                'floorplan' => ['Floor Planner', 'pages/floorplan.php', '▦', 'nav-floorplan'],
                'datacenters' => ['Data Centers', 'pages/datacenters.php', '🏛', 'nav-datacenters'],
                'cabinets' => ['Cabinets', 'pages/cabinets.php', '▤', 'nav-cabinets'],
                'devices' => ['Devices', 'pages/devices.php', '🖥', 'nav-devices'],
                'power' => ['Power', 'pages/power.php', '⚡', 'nav-power'],
                'cooling' => ['Cooling', 'pages/cooling.php', '❄', 'nav-cooling'],
                'cables' => ['Cabling', 'pages/cables.php', '🔌', 'nav-cables'],
                'ipam' => ['IPAM', 'pages/ipam.php', '🔢', 'nav-ipam'],
                'snmp' => ['SNMP', 'pages/snmp.php', '📡', 'nav-snmp'],
                'work_orders' => ['Work orders', 'pages/work_orders.php', '📋', 'nav-work-orders'],
                'disposals' => ['Decommission', 'pages/disposals.php', '🗑', 'nav-disposals'],
                'audits' => ['Audits', 'pages/audits.php', '✓', 'nav-audits'],
                'reports' => ['Reports', 'pages/reports.php', '📊', 'nav-reports'],
                'users' => ['Users & Depts', 'pages/users.php', '👤', 'nav-users'],
                'docs' => ['Documentation', 'pages/docs.php', '📖', 'nav-docs'],
                'settings' => ['Settings', 'pages/settings.php', '⚙', 'nav-settings'],
            ];
            $devicesActive = in_array($active, ['devices', 'device_templates'], true);
            $powerActive = in_array($active, ['power', 'power_zones', 'power_pdus', 'power_pdu_templates', 'power_templates', 'power_ups'], true);
            $coolingActive = in_array($active, ['cooling', 'cooling_units', 'env_sensors'], true);
            foreach ($nav as $key => [$label, $href, $icon, $tourId]):
                if (!AuthManager::canViewNav($user, $key)) {
                    continue;
                }
                $cls = ($active === $key
                    || ($key === 'devices' && $devicesActive)
                    || ($key === 'power' && $powerActive)
                    || ($key === 'cooling' && $coolingActive)) ? 'active' : '';
            ?>
                <a class="nav-item <?= $cls ?>" href="<?= App::e(App::url($href)) ?>"
                   data-tour="<?= App::e((string)$tourId) ?>">
                    <span class="nav-icon"><?= $icon ?></span>
                    <span><?= App::e($label) ?></span>
                </a>
                <?php if ($key === 'devices'): ?>
                    <a class="nav-item nav-sub <?= $active === 'devices' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/devices.php')) ?>">
                        <span class="nav-icon"></span><span>All devices</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'device_templates' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/device_templates.php')) ?>"
                       data-tour="nav-device-templates">
                        <span class="nav-icon"></span><span>Templates</span>
                    </a>
                <?php endif; ?>
                <?php if ($key === 'power'): ?>
                    <a class="nav-item nav-sub <?= $active === 'power' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power.php')) ?>">
                        <span class="nav-icon"></span><span>Dashboard</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'power_zones' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power_zones.php')) ?>"
                       data-tour="nav-power-zones">
                        <span class="nav-icon"></span><span>Zones</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'power_pdus' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power_pdus.php')) ?>"
                       data-tour="nav-power-pdus">
                        <span class="nav-icon"></span><span>PDUs</span>
                    </a>
                    <a class="nav-item nav-sub <?= $active === 'power_ups' ? 'active' : '' ?>"
                       href="<?= App::e(App::url('pages/power_ups.php')) ?>"
                       data-tour="nav-power-ups">
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
            <button type="button" class="btn btn-ghost topbar-find" id="topbarFindBtn" data-open-find
                    data-tour="global-search" title="Find cabinets, devices, PDUs, work orders (press /)">
                Find <kbd>/</kbd>
            </button>
            <div class="topbar-actions">
                <span data-tour="tech-mode" title="Field chrome for tablets. Dashboard → Field kit explains Add to Home Screen."><?php layout_tech_mode_toggle(false); ?></span>
                <span data-tour="notifications">
                <a class="notif-badge" href="<?= App::e(App::url('pages/notifications.php')) ?>"
                   title="Notifications"
                   <?= $unread < 1 ? 'hidden' : '' ?>><?= (int)$unread ?></a>
                </span>
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
            <div class="topbar-actions">
                <?php layout_tech_mode_toggle(true); ?>
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
 * Command palette: jump to cabinet / device / PDU / UPS / cable / work order.
 */
function layout_search_palette(): void
{
    ?>
    <div class="gs-palette" id="globalSearchPalette" hidden style="display:none !important">
        <div class="gs-palette-backdrop" data-close-find tabindex="-1"></div>
        <div class="gs-palette-card" role="dialog" aria-modal="true" aria-labelledby="globalSearchInput">
            <div class="gs-palette-head">
                <label class="visually-hidden" for="globalSearchInput">Jump to inventory</label>
                <button type="button" class="modal-close" id="globalSearchClose" data-close-find aria-label="Close find">×</button>
            </div>
            <input class="form-control gs-palette-input" type="search" id="globalSearchInput"
                   placeholder="Jump to cabinet, device, PDU, UPS, work order…"
                   autocomplete="off" spellcheck="false" enterkeyhint="search">
            <p class="gs-palette-hint">Esc or the × closes. <kbd>/</kbd> or <kbd>Ctrl</kbd>+<kbd>K</kbd> opens. Enter opens the highlighted match.</p>
            <div class="topbar-search-panel gs-palette-results" id="globalSearchPanel" hidden role="listbox" aria-label="Search results"></div>
        </div>
    </div>
    <?php
}

/**
 * GET ?q= box used on inventory list pages.
 *
 * @param array<string,scalar|null> $keepGet Extra query params preserved on Search and Clear.
 */
function layout_search_form(string $placeholder, string $q, string $clearPath, array $keepGet = []): void
{
    $keep = [];
    foreach ($keepGet as $k => $v) {
        if ($v === '' || $v === null || $v === false) {
            continue;
        }
        $keep[(string)$k] = (string)$v;
    }
    $clearHref = App::url($clearPath);
    if ($keep) {
        $clearHref .= (str_contains($clearHref, '?') ? '&' : '?') . http_build_query($keep);
    }
    ?>
    <form method="get" class="list-search-form" role="search">
        <?php foreach ($keep as $k => $v):
            if ($k === 'q') {
                continue;
            }
            ?>
            <input type="hidden" name="<?= App::e($k) ?>" value="<?= App::e($v) ?>">
        <?php endforeach; ?>
        <input class="form-control" type="search" name="q" value="<?= App::e($q) ?>"
               placeholder="<?= App::e($placeholder) ?>" autocomplete="off" enterkeyhint="search">
        <button class="btn btn-secondary" type="submit">Search</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-ghost" href="<?= App::e($clearHref) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * Sticky in-page jump chips (device / PDU detail).
 *
 * @param list<array{id:string,label:string}> $items
 */
/**
 * Pager + “export this view” for inventory lists.
 *
 * @param array{page:int,per_page:int,offset:int,total:int,pages:int,from:int,to:int} $pager
 * @param array<string,scalar> $keepGet
 */
function layout_list_pager(array $pager, string $path, array $keepGet = [], bool $export = true): void
{
    $total = (int)($pager['total'] ?? 0);
    $page = (int)($pager['page'] ?? 1);
    $pages = max(1, (int)($pager['pages'] ?? 1));
    $per = (int)($pager['per_page'] ?? ListPager::DEFAULT_PER);
    $from = (int)($pager['from'] ?? 0);
    $to = (int)($pager['to'] ?? 0);
    $keep = [];
    foreach ($keepGet as $k => $v) {
        if ($v === '' || $v === null || $v === false) {
            continue;
        }
        if ((string)$k === 'page') {
            continue;
        }
        $keep[(string)$k] = (string)$v;
    }
    if ($per !== ListPager::DEFAULT_PER) {
        $keep['per'] = (string)$per;
    } else {
        unset($keep['per']);
    }
    $href = static function (array $over) use ($path, $keep): string {
        return ListPager::href($path, $keep, $over);
    };
    ?>
    <div class="list-pager">
        <div class="list-pager-meta">
            <?php if ($total < 1): ?>
                <span>No rows</span>
            <?php else: ?>
                <span>Showing <?= (int)$from ?>–<?= (int)$to ?> of <?= (int)$total ?></span>
            <?php endif; ?>
            <?php if ($export && $total > 0 && class_exists('ListPager')): ?>
                <a class="btn btn-sm btn-secondary" href="<?= App::e($href(['export' => 'csv', 'page' => null])) ?>">Export CSV</a>
            <?php endif; ?>
        </div>
        <div class="list-pager-nav">
            <span class="list-pager-per" title="Rows per page">
                <?php foreach (ListPager::CHOICES as $n): ?>
                    <?php if ($n === $per): ?>
                        <strong><?= (int)$n ?></strong>
                    <?php else: ?>
                        <a href="<?= App::e($href([
                            'per' => $n === ListPager::DEFAULT_PER ? null : (string)$n,
                            'page' => null,
                        ])) ?>"><?= (int)$n ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                / page
            </span>
            <?php if ($pages > 1): ?>
                <?php if ($page > 1): ?>
                    <a class="btn btn-sm btn-ghost" href="<?= App::e($href(['page' => (string)($page - 1)])) ?>">Prev</a>
                <?php else: ?>
                    <span class="btn btn-sm btn-ghost" aria-disabled="true">Prev</span>
                <?php endif; ?>
                <?php
                $window = [];
                $start = max(1, $page - 2);
                $end = min($pages, $page + 2);
                if ($start > 1) {
                    $window[] = 1;
                    if ($start > 2) {
                        $window[] = 0;
                    }
                }
                for ($i = $start; $i <= $end; $i++) {
                    $window[] = $i;
                }
                if ($end < $pages) {
                    if ($end < $pages - 1) {
                        $window[] = 0;
                    }
                    $window[] = $pages;
                }
                foreach ($window as $p):
                    if ($p === 0): ?>
                        <span class="list-pager-ellipsis">…</span>
                    <?php elseif ($p === $page): ?>
                        <span class="list-pager-page is-active"><?= (int)$p ?></span>
                    <?php else: ?>
                        <a class="list-pager-page" href="<?= App::e($href(['page' => (string)$p])) ?>"><?= (int)$p ?></a>
                    <?php endif;
                endforeach; ?>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-sm btn-ghost" href="<?= App::e($href(['page' => (string)($page + 1)])) ?>">Next</a>
                <?php else: ?>
                    <span class="btn btn-sm btn-ghost" aria-disabled="true">Next</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function layout_page_jump(array $items, string $aria = 'On this page'): void
{
    $out = [];
    foreach ($items as $it) {
        $id = trim((string)($it['id'] ?? ''));
        $label = trim((string)($it['label'] ?? ''));
        if ($id === '' || $label === '') {
            continue;
        }
        $out[] = ['id' => $id, 'label' => $label];
    }
    if ($out === []) {
        return;
    }
    ?>
    <nav class="page-jump" aria-label="<?= App::e($aria) ?>">
        <?php foreach ($out as $it): ?>
            <a class="page-jump-chip" href="#<?= App::e($it['id']) ?>" data-jump-id="<?= App::e($it['id']) ?>">
                <?= App::e($it['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function layout_footer(): void
{
    $ctx = $GLOBALS['coldaisle_layout_ctx'] ?? null;
    $tech = is_array($ctx) && !empty($ctx['tech']);
    $user = is_array($ctx) ? ($ctx['user'] ?? []) : [];
    $active = is_array($ctx) ? (string)($ctx['active'] ?? '') : '';
    $donateUrl = 'https://paypal.me/mattelsberry';
    $siteUrl = 'https://coldaisle.app';
    $githubUrl = class_exists('UpdateService', false)
        ? UpdateService::githubUrl()
        : 'https://github.com/sabap/ColdAisle';
    $licenseName = 'MIT License';
    $licenseUrl = $githubUrl . '/blob/main/LICENSE';
    $timerOn = class_exists('App', false) && App::requestTimerEnabled();
    $timing = $timerOn ? App::requestTimingSnapshot() : null;
    $jsV = preg_replace('/\W+/', '', (string)App::VERSION) . '24';

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
<?php layout_search_palette(); ?>
<script src="<?= App::e(App::url('assets/js/app.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register(<?= json_encode(App::url('sw.js')) ?>, { scope: <?= json_encode(rtrim(App::baseUrl(), '/') . '/') ?> }).catch(function () {});
}
</script>
<?php if (class_exists('SetupWizardService') && !empty($user) && AuthManager::can($user, 'manage_settings')): ?>
<script src="<?= App::e(App::url('assets/js/setup-wizard.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<script src="<?= App::e(App::url('assets/js/site-tour.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<?php endif; ?>
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
            · <button type="button" class="footer-link-btn" data-ca-modal-open="aboutColdAisle"
                    title="About ColdAisle">About</button>
            · <a href="<?= App::e($siteUrl) ?>" target="_blank" rel="noopener noreferrer">Website</a>
            · <a href="<?= App::e($donateUrl) ?>" target="_blank" rel="noopener noreferrer">Donate</a>
            · <a href="<?= App::e($githubUrl) ?>" target="_blank" rel="noopener noreferrer">GitHub</a>
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
<?php layout_search_palette(); ?>

<div class="modal-overlay modal-overlay-glass" id="aboutColdAisle" hidden>
    <div class="modal-panel modal-panel-glass about-coldaisle-panel" role="dialog" aria-modal="true"
         aria-labelledby="aboutColdAisleTitle">
        <div class="modal-header">
            <h2 id="aboutColdAisleTitle">About ColdAisle</h2>
            <button type="button" class="modal-close" data-ca-modal-close="aboutColdAisle" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body about-coldaisle-body">
            <div class="about-coldaisle-brand">
                <img class="about-coldaisle-logo" src="<?= App::e(App::url('assets/img/logo.svg')) ?>"
                     width="48" height="48" alt="" onerror="this.style.display='none'">
                <div>
                    <div class="about-coldaisle-name">ColdAisle</div>
                    <div class="about-coldaisle-tag">Data Center Infrastructure Management</div>
                </div>
            </div>
            <p class="about-coldaisle-blurb">
                Modern DCIM for Windows — floor plans, 3D racks, power, cabling raceways, SNMP,
                and one-click updates. Free and open source.
            </p>
            <dl class="about-coldaisle-meta">
                <div>
                    <dt>Version</dt>
                    <dd><code>v<?= App::e((string)App::VERSION) ?></code></dd>
                </div>
                <div>
                    <dt>License</dt>
                    <dd>
                        <a href="<?= App::e($licenseUrl) ?>" target="_blank" rel="noopener noreferrer"><?= App::e($licenseName) ?></a>
                    </dd>
                </div>
                <div>
                    <dt>Website</dt>
                    <dd>
                        <a href="<?= App::e($siteUrl) ?>" target="_blank" rel="noopener noreferrer">coldaisle.app</a>
                    </dd>
                </div>
                <div>
                    <dt>Source</dt>
                    <dd>
                        <a href="<?= App::e($githubUrl) ?>" target="_blank" rel="noopener noreferrer">github.com/sabap/ColdAisle</a>
                    </dd>
                </div>
            </dl>
            <div class="about-coldaisle-actions">
                <a class="btn btn-primary btn-sm" href="<?= App::e($siteUrl) ?>" target="_blank" rel="noopener noreferrer">Open website</a>
                <a class="btn btn-secondary btn-sm" href="<?= App::e($githubUrl) ?>" target="_blank" rel="noopener noreferrer">GitHub</a>
                <a class="btn btn-ghost btn-sm" href="<?= App::e($licenseUrl) ?>" target="_blank" rel="noopener noreferrer">License</a>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary btn-sm" data-ca-modal-close="aboutColdAisle">Close</button>
        </div>
    </div>
</div>

<script src="<?= App::e(App::url('assets/js/app.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register(<?= json_encode(App::url('sw.js')) ?>, { scope: <?= json_encode(rtrim(App::baseUrl(), '/') . '/') ?> }).catch(function () {});
}
</script>
<?php if (class_exists('SetupWizardService') && !empty($user) && AuthManager::can($user, 'manage_settings')): ?>
<script src="<?= App::e(App::url('assets/js/setup-wizard.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<script src="<?= App::e(App::url('assets/js/site-tour.js')) ?>?v=<?= App::e($jsV) ?>"></script>
<?php endif; ?>
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
