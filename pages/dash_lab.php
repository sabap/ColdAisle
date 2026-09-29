<?php
/**
 * Experimental overlay dashboard — large hall 3D with metric chips and a side inspector.
 * Production dashboard (index.php) is unchanged.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('view_dashboard');

$apiUrl = App::url('api/noc.php?scene=1');
$cssUrl = App::url('assets/css/dash-lab.css') . '?v=2';
$jsUrl = App::url('assets/js/dash-lab.js') . '?v=2';
$threeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
$dcim3dUrl = App::url('assets/js/dcim-3d.js') . '?v=39';
$nocLab = App::url('pages/noc_lab.php');
$prodDash = App::url('index.php');

layout_header('Hall lab', $user, 'dash_lab');
?>
<link rel="stylesheet" href="<?= App::e($cssUrl) ?>">
<script>
window.ColdAisleLab = {
  apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>,
  pollMs: 20000,
  autoRotate: true,
  threeUrl: <?= json_encode($threeUrl, JSON_UNESCAPED_SLASHES) ?>,
  dcim3dUrl: <?= json_encode($dcim3dUrl, JSON_UNESCAPED_SLASHES) ?>,
  logoUrl: <?= json_encode(App::url('assets/img/logo.svg'), JSON_UNESCAPED_SLASHES) ?>
};
</script>

<div class="lab-overlay-root">
  <nav class="lab-rail" aria-label="Lab views">
    <div class="lab-core" title="Link"></div>
    <button type="button" class="lab-rail-btn is-active" data-panel="overview" title="Overview">
      <span class="lab-rail-stack">▣<small>All</small></span>
    </button>
    <button type="button" class="lab-rail-btn" data-panel="thermal" title="Thermal">
      <span class="lab-rail-stack">🌡<small>Temp</small></span>
    </button>
    <button type="button" class="lab-rail-btn" data-panel="power" title="Power">
      <span class="lab-rail-stack">⚡<small>Power</small></span>
    </button>
    <button type="button" class="lab-rail-btn" data-panel="network" title="Inventory">
      <span class="lab-rail-stack">🖥<small>Inv</small></span>
    </button>
  </nav>

  <header class="lab-top">
    <div style="display:flex;align-items:baseline;flex-wrap:wrap">
      <h1>ColdAisle // Command</h1>
      <span class="lab-kicker">Experimental HUD</span>
    </div>
    <div style="display:flex;align-items:center;gap:1rem">
      <span class="lab-live"><i></i> Live link</span>
      <a href="<?= App::e($prodDash) ?>">Exit HUD</a>
      <a href="<?= App::e($nocLab) ?>">Wall</a>
      <span class="lab-clock" id="labClock">—</span>
    </div>
  </header>

  <div class="lab-stage">
    <div class="lab-3d" id="lab3d" aria-label="Experimental 3D hall"></div>
    <div class="lab-hud" aria-hidden="true">
      <span class="c tl"></span><span class="c tr"></span>
      <span class="c bl"></span><span class="c br"></span>
      <div class="lab-hex"></div>
      <div class="lab-scan"></div>
      <div class="lab-vignette"></div>
      <div class="lab-reticle"></div>
    </div>
    <div class="lab-chips" id="labChips"></div>
    <div class="lab-cam" role="group" aria-label="Camera">
      <button type="button" data-cam="orbit" class="is-active">Orbit</button>
      <button type="button" data-cam="aisle">Aisle</button>
      <button type="button" data-cam="plan">Plan</button>
      <button type="button" data-cam="reset">Reset</button>
    </div>
  </div>

  <aside class="lab-inspector">
    <h2 id="labInspectorTitle">Systems</h2>
    <div id="labInspectorBody"></div>
  </aside>

  <div class="lab-stream">
    <div class="lab-stream-head">
      <span>Telemetry</span>
      <span>event bus</span>
    </div>
    <div id="labStreamList"></div>
  </div>
</div>
<script src="<?= App::e($jsUrl) ?>" defer></script>
<?php
layout_footer();
