<?php
/**
 * Shared "Add environmental sensor" modal.
 *
 * Expects (same as _env_sensor_form.php):
 *   $kinds, $hosts, $placements, $rooms, $coolingUnits, $pdus, $cabinets, $devices
 * Optional:
 *   $edit — prefilled fields (device_id, host_type, …)
 *   $modalId — default addEnvSensor
 *   $autoOpen — open on load when true
 */
declare(strict_types=1);

$modalId = $modalId ?? 'addEnvSensor';
$edit = $edit ?? [];
$formAction = 'add_sensor';
$formId = 'envSensorForm_' . preg_replace('/\W+/', '_', (string)$modalId);
$autoOpen = !empty($autoOpen);
// Always post to env_sensors so this modal works on device pages too
$formPostUrl = App::url('pages/env_sensors.php');
?>
<div class="modal-overlay" id="<?= App::e($modalId) ?>" hidden style="display:none"
     role="presentation">
    <div class="modal-panel modal-panel-wide" role="dialog" aria-modal="true"
         aria-labelledby="<?= App::e($modalId) ?>Title">
        <div class="modal-header">
            <h2 id="<?= App::e($modalId) ?>Title">Add environmental sensor</h2>
            <button type="button" class="modal-close" data-ca-modal-close="<?= App::e($modalId) ?>"
                    aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <?php
            $envFormPostUrl = $formPostUrl;
            $envFormId = $formId;
            require __DIR__ . '/_env_sensor_form.php';
            ?>
        </div>
    </div>
</div>
<script>
(function () {
  var modalId = <?= json_encode($modalId, JSON_UNESCAPED_SLASHES) ?>;
  var autoOpen = <?= $autoOpen ? 'true' : 'false' ?>;

  function getModal() {
    return document.getElementById(modalId);
  }

  function openModal() {
    var m = getModal();
    if (!m) return;
    m.hidden = false;
    m.style.display = 'flex';
    m.removeAttribute('hidden');
    document.body.classList.add('modal-open');
    // Focus first field
    setTimeout(function () {
      var name = m.querySelector('input[name="name"]');
      if (name) name.focus();
    }, 50);
  }

  function closeModal() {
    var m = getModal();
    if (!m) return;
    m.hidden = true;
    m.style.display = 'none';
    m.setAttribute('hidden', '');
    document.body.classList.remove('modal-open');
  }

  // Expose for buttons: data-ca-modal-open="addEnvSensor"
  window.ColdAisle = window.ColdAisle || {};
  window.ColdAisle.openModal = window.ColdAisle.openModal || function (id) {
    if (id === modalId) openModal();
    else {
      var el = document.getElementById(id);
      if (el) {
        el.hidden = false;
        el.style.display = 'flex';
        el.removeAttribute('hidden');
        document.body.classList.add('modal-open');
      }
    }
  };
  window.ColdAisle.closeModal = window.ColdAisle.closeModal || function (el) {
    if (typeof el === 'string') el = document.getElementById(el);
    if (!el) return;
    el.hidden = true;
    el.style.display = 'none';
    el.setAttribute('hidden', '');
    document.body.classList.remove('modal-open');
  };
  // Named open for this instance (multiple modals safe)
  window['caOpen_' + modalId] = openModal;
  window['caClose_' + modalId] = closeModal;

  function bind() {
    document.querySelectorAll('[data-ca-modal-open="' + modalId + '"]').forEach(function (btn) {
      if (btn._caBoundOpen) return;
      btn._caBoundOpen = true;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        openModal();
      });
    });
    document.querySelectorAll('[data-ca-modal-close="' + modalId + '"]').forEach(function (btn) {
      if (btn._caBoundClose) return;
      btn._caBoundClose = true;
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        closeModal();
      });
    });
    var m = getModal();
    if (m && !m._caBackdropBound) {
      m._caBackdropBound = true;
      m.addEventListener('click', function (e) {
        if (e.target === m) closeModal();
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var m2 = getModal();
        if (m2 && !m2.hidden) closeModal();
      }
    });
    if (autoOpen) openModal();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
</script>
