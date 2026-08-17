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
            <p id="cabAuditContext" class="text-muted" style="font-size:.82rem;margin:.35rem 0 .75rem;display:none"></p>
            <label class="cab-audit-certify" style="display:flex;gap:.65rem;align-items:flex-start;padding:.65rem .75rem;border-radius:8px;border:1px solid rgba(148,163,184,.25);background:rgba(15,23,42,.2)">
                <input type="checkbox" id="cabAuditCertified" style="width:1.15rem;height:1.15rem;margin-top:.15rem;flex-shrink:0">
                <span style="font-size:.95rem;line-height:1.35">I certify I completed a physical audit of this cabinet (devices, labeling, cables as applicable).</span>
            </label>
            <div class="form-row" style="margin-top:1rem">
                <label for="cabAuditComments">Field notes</label>
                <textarea class="form-control" id="cabAuditComments" rows="3"
                          placeholder="Optional: missing assets, cable issues, labeling, airflow, empty U slots…"
                          style="min-height:4.5rem;font-size:1rem"></textarea>
            </div>
            <div class="form-row" style="margin-top:1rem">
                <label>Photos (optional, up to 8)</label>
                <input class="form-control" type="file" id="cabAuditPhotos" accept="image/*" capture="environment" multiple>
                <p class="text-muted" style="font-size:.75rem;margin:.35rem 0 0">
                    Phone camera or library. Stored with this audit. Live inventory is snapshotted when you log.
                </p>
                <div id="cabAuditPhotoPreview" class="cab-audit-thumbs" hidden></div>
            </div>
            <p class="text-muted" style="font-size:.75rem;margin:.75rem 0 0">
                Saved with auditor, date/time, rack snapshot, comments, and photos.
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
    var ctxEl = document.getElementById('cabAuditContext');
    var certEl = document.getElementById('cabAuditCertified');
    var commentsEl = document.getElementById('cabAuditComments');
    var errEl = document.getElementById('cabAuditError');
    var submitBtn = document.getElementById('cabAuditSubmit');
    var currentId = 0;
    var currentName = '';

    function openAuditModal(cabinetId, cabinetName, meta) {
        currentId = parseInt(cabinetId, 10) || 0;
        currentName = cabinetName || ('Cabinet #' + currentId);
        if (!currentId) return;
        if (nameEl) nameEl.textContent = currentName;
        if (titleEl) titleEl.textContent = 'Audit: ' + currentName;
        if (ctxEl) {
            meta = meta || {};
            var bits = [];
            if (meta.devices != null && meta.devices !== '') bits.push(meta.devices + ' device(s)');
            if (meta.uUsed != null && meta.u != null && meta.u !== '') {
                bits.push('U ' + meta.uUsed + ' / ' + meta.u + ' used');
            } else if (meta.u != null && meta.u !== '') {
                bits.push(meta.u + 'U rack');
            }
            if (bits.length) {
                ctxEl.textContent = 'Inventory snapshot: ' + bits.join(' · ');
                ctxEl.style.display = '';
            } else {
                ctxEl.textContent = '';
                ctxEl.style.display = 'none';
            }
        }
        if (certEl) certEl.checked = false;
        if (commentsEl) commentsEl.value = '';
        var photoIn = document.getElementById('cabAuditPhotos');
        if (photoIn) photoIn.value = '';
        var prev = document.getElementById('cabAuditPhotoPreview');
        if (prev) { prev.innerHTML = ''; prev.hidden = true; }
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

    var photoInBind = document.getElementById('cabAuditPhotos');
    if (photoInBind) {
        photoInBind.addEventListener('change', function () {
            var box = document.getElementById('cabAuditPhotoPreview');
            if (!box) return;
            box.innerHTML = '';
            var files = photoInBind.files ? Array.prototype.slice.call(photoInBind.files, 0, 8) : [];
            box.hidden = files.length < 1;
            files.forEach(function (f) {
                var img = document.createElement('img');
                img.alt = f.name;
                img.src = URL.createObjectURL(f);
                box.appendChild(img);
            });
        });
    }
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
            var auditId = res && res.audit && res.audit.cabinet_audit_id;
            var photoIn = document.getElementById('cabAuditPhotos');
            var files = photoIn && photoIn.files ? Array.prototype.slice.call(photoIn.files, 0, 8) : [];
            var uploaded = 0;
            if (auditId && files.length) {
                var base = (window.ColdAisle && ColdAisle.baseUrl) || '';
                var csrf = (window.ColdAisle && ColdAisle.csrf) || '';
                for (var i = 0; i < files.length; i++) {
                    var fd = new FormData();
                    fd.append('cabinet_audit_id', String(auditId));
                    fd.append('photo', files[i]);
                    var pr = await fetch(base.replace(/\/$/, '') + '/api/cabinet_audit_photos.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                        body: fd
                    });
                    if (pr.ok) uploaded++;
                }
            }
            ColdAisle.toast(
                'Audit logged for ' + currentName + (uploaded ? (' · ' + uploaded + ' photo(s)') : ''),
                'success'
            );
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
        openAuditModal(
            btn.getAttribute('data-audit-cabinet'),
            btn.getAttribute('data-audit-name') || '',
            {
                devices: btn.getAttribute('data-audit-devices'),
                u: btn.getAttribute('data-audit-u'),
                uUsed: btn.getAttribute('data-audit-u-used')
            }
        );
    });
})();
</script>
<style>
.cab-audit-certify:has(input:checked) {
  border-color: rgba(52, 211, 153, 0.45) !important;
  background: rgba(6, 78, 59, 0.25) !important;
}
#cabinetAuditModal .modal-panel {
  width: min(26rem, 96vw);
}
#cabAuditSubmit:not(:disabled) {
  min-height: 2.5rem;
  font-weight: 600;
}
.cab-audit-thumbs {
  display: flex;
  flex-wrap: wrap;
  gap: .4rem;
  margin-top: .5rem;
}
.cab-audit-thumbs img {
  width: 4.2rem;
  height: 4.2rem;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid rgba(148,163,184,.3);
}
</style>
<?php endif; ?>
