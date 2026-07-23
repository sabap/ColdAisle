<?php
/**
 * Shared cabinet audit certification modal + JS.
 * Expects: $user
 * Optional: $cabinetAuditCanLog (bool) — computed if omitted
 */
declare(strict_types=1);

if (!isset($cabinetAuditCanLog)) {
    $cabinetAuditCanLog = AuthManager::can($user, 'edit_audits')
        || AuthManager::can($user, 'edit_infrastructure')
        || AuthManager::can($user, 'edit_devices_all');
}
?>
<?php if ($cabinetAuditCanLog): ?>
<div class="modal-overlay modal-overlay-glass" id="cabinetAuditModal" hidden>
    <div class="modal-panel modal-panel-glass" role="dialog" aria-modal="true" aria-labelledby="cabAuditTitle">
        <div class="modal-header">
            <h2 id="cabAuditTitle">Cabinet audit</h2>
            <button type="button" class="modal-close" id="cabAuditClose" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted" style="margin-top:0;font-size:.9rem">
                Logging an audit for
                <strong id="cabAuditCabName">—</strong>
            </p>
            <label class="cab-audit-certify">
                <input type="checkbox" id="cabAuditCertified">
                <span>Do you certify that you have completed an audit of this cabinet?</span>
            </label>
            <div class="form-row" style="margin-top:1rem">
                <label for="cabAuditComments">Comments</label>
                <textarea class="form-control" id="cabAuditComments" rows="4"
                          placeholder="Optional notes: missing assets, cable issues, labeling, airflow, …"></textarea>
            </div>
            <p class="text-muted" style="font-size:.75rem;margin:.75rem 0 0">
                This will be stored as an audit record for this cabinet (auditor, date/time, and comments)
                for future reports by datacenter, cabinet, or user.
            </p>
            <div id="cabAuditError" class="alert alert-error" hidden style="margin-top:.75rem"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="cabAuditCancel">Cancel</button>
            <button type="button" class="btn btn-primary" id="cabAuditSubmit" disabled>Log audit</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('cabinetAuditModal');
    if (!modal || modal.dataset.bound) return;
    modal.dataset.bound = '1';

    var titleEl = document.getElementById('cabAuditTitle');
    var nameEl = document.getElementById('cabAuditCabName');
    var certEl = document.getElementById('cabAuditCertified');
    var commentsEl = document.getElementById('cabAuditComments');
    var errEl = document.getElementById('cabAuditError');
    var submitBtn = document.getElementById('cabAuditSubmit');
    var currentId = 0;
    var currentName = '';

    function openAuditModal(cabinetId, cabinetName) {
        currentId = parseInt(cabinetId, 10) || 0;
        currentName = cabinetName || ('Cabinet #' + currentId);
        if (!currentId) return;
        if (nameEl) nameEl.textContent = currentName;
        if (titleEl) titleEl.textContent = 'Audit: ' + currentName;
        if (certEl) certEl.checked = false;
        if (commentsEl) commentsEl.value = '';
        if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
        if (submitBtn) submitBtn.disabled = true;
        modal.hidden = false;
        document.body.classList.add('modal-open');
        if (certEl) certEl.focus();
    }

    function closeAuditModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        currentId = 0;
    }

    function syncSubmit() {
        if (submitBtn) submitBtn.disabled = !(certEl && certEl.checked);
    }

    window.ColdAisle = window.ColdAisle || {};
    window.ColdAisle.openCabinetAudit = openAuditModal;

    if (certEl) certEl.addEventListener('change', syncSubmit);
    document.getElementById('cabAuditClose').addEventListener('click', closeAuditModal);
    document.getElementById('cabAuditCancel').addEventListener('click', closeAuditModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeAuditModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeAuditModal();
    });

    submitBtn.addEventListener('click', async function () {
        if (!currentId || !certEl.checked) return;
        submitBtn.disabled = true;
        if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
        try {
            var res = await ColdAisle.api('api/cabinet_audits.php', {
                method: 'POST',
                body: {
                    cabinet_id: currentId,
                    certified: true,
                    comments: commentsEl ? commentsEl.value : ''
                }
            });
            ColdAisle.toast('Audit logged for ' + currentName, 'success');
            closeAuditModal();
            // Refresh history list if present on page
            if (typeof window.ColdAisle.refreshCabinetAuditHistory === 'function') {
                window.ColdAisle.refreshCabinetAuditHistory(currentId, res && res.audit);
            } else {
                // Soft-update last-audit badges in row view
                document.querySelectorAll('[data-cab-last-audit="' + currentId + '"]').forEach(function (el) {
                    el.textContent = 'Audited just now';
                    el.classList.add('cab-audit-fresh');
                });
            }
        } catch (err) {
            if (errEl) {
                errEl.textContent = (err && err.message) ? err.message : 'Failed to log audit';
                errEl.hidden = false;
            }
            submitBtn.disabled = false;
            syncSubmit();
        }
    });

    // Delegate: buttons with data-audit-cabinet
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-audit-cabinet]');
        if (!btn) return;
        e.preventDefault();
        openAuditModal(btn.getAttribute('data-audit-cabinet'), btn.getAttribute('data-audit-name') || '');
    });
})();
</script>
<?php endif; ?>
