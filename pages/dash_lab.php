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
$cssUrl = App::url('assets/css/dash-lab.css') . '?v=1';
$jsUrl = App::url('assets/js/dash-lab.js') . '?v=1';
$threeUrl = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
$dcim3dUrl = App::url('assets/js/dcim-3d.js') . '?v=36';
$nocLab = App::url('pages/noc_lab.php');
$prodDash = App::url('index.php');

layout_header('Hall lab', $user, 'dash_lab');
?>
<link rel="stylesheet" href="<?= App::e($cssUrl) ?>">
<script>
window.ColdAisleLab = {
  apiUrl: <?= json_encode($apiUrl, JSON_UNESCAPED_SLASHES) ?>,
  pollMs: 20000,
  autoRotate: false,
  threeUrl: <?= json_encode($threeUrl, JSON_UNESCAPED_SLASHES) ?>,
  dcim3dUrl: <?= json_encode($dcim3dUrl, JSON_UNESCAPED_SLASHES) ?>,
  logoUrl: <?= json_encode(App::url('assets/img/logo.svg'), JSON_UNESCAPED_SLASHES) ?>
};
</script>

<div class="lab-overlay-root">
  <nav class="lab-rail" aria-label="Lab views">
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
    <div>
      <h1>Hall lab</h1>
      <span class="lab-kicker">Experimental overlay · does not replace the production dashboard</span>
    </div>
    <div style="display:flex;align-items:center;gap:.85rem">
      <a href="<?= App::e($prodDash) ?>">Production dashboard</a>
      <a href="<?= App::e($nocLab) ?>">Lab NOC</a>
      <span class="lab-clock" id="labClock">—</span>
    </div>
  </header>

  <div class="lab-stage">
    <div class="lab-3d" id="lab3d" aria-label="Experimental 3D hall"></div>
    <div class="lab-chips" id="labChips"></div>
    <div class="lab-cam" role="group" aria-label="Camera">
      <button type="button" data-cam="orbit" class="is-active">Orbit</button>
      <button type="button" data-cam="aisle">Aisle</button>
      <button type="button" data-cam="plan">Plan</button>
      <button type="button" data-cam="reset">Reset</button>
    </div>
    <div class="lab-banner">Lab look · perforated doors · studio lighting</div>
  </div>

  <aside class="lab-inspector">
    <h2 id="labInspectorTitle">Hall</h2>
    <div id="labInspectorBody"></div>
  </aside>

  <div class="lab-stream">
    <div class="lab-stream-head">
      <span>Event stream</span>
      <span>site notifications</span>
    </div>
    <div id="labStreamList"></div>
  </div>
</div>
<script src="<?= App::e($jsUrl) ?>" defer></script>
<?php
layout_footer();
