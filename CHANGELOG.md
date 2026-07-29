# Changelog

All notable changes to **ColdAisle** are documented here.

Format: categories under each version are **New features**, **Enhancements**, and **Bug fixes**.  
While work is in progress, add bullets under **[Unreleased]**. The release script promotes that section into a versioned entry and publishes it with the Git tag / GitHub Release.

See [docs/RELEASING.md](docs/RELEASING.md).

---

## [Unreleased]

### New features

### Enhancements

### Bug fixes

---

## [0.2.81] - 2026-07-29

### Bug fixes

- **Facility / zone power charts:** stop comb-to-zero usage when poll times stagger across many PDUs. Site/zone totals now **hold each PDU’s last known watts** (up to ~15 min / 3 buckets) instead of summing only PDUs present in that 5‑minute bucket.
- Avoid writing **spurious 0 W** history samples when a poll only succeeds on load_state / non-power OIDs (common on older APC maps).

---

## [0.2.80] - 2026-07-28

### Enhancements

- Phase status table: missing metrics show **N/A** with tooltip *“The PDU's SNMP does not report this metric”* (instead of a dash).
- Removed the separate **phase load-state** history graph; load state remains in the Phase status table.

---

## [0.2.79] - 2026-07-28

### New features

- PDU history: **phase load-state** L1/L2/L3 chart (for older APC rPDU units that only report load state).
- Catalog seed template **APC rPDU 3-phase load state (AP786x typical)** with phase load-state OIDs.

### Enhancements

- Phase voltage / avg voltage charts **hide when no voltage data** is available (template OIDs or history samples).
- Phase status table shows voltage column only when volts are present; load-state-only phases still appear (AP7862/AP7864).

### Bug fixes

- `power_phase_poll_decode` no longer drops phases that only have `load_state`.

---

## [0.2.78] - 2026-07-28

### Bug fixes

- **Critical:** remove UTF-8 BOM from `src/App.php` introduced by the release script (Windows `Set-Content -Encoding UTF8`). BOM caused a fatal error (`strict_types declaration must be the very first statement`) and took sites offline after update. Release script now writes UTF-8 without BOM.

---

## [0.2.77] - 2026-07-28

### Bug fixes

- **Update available → Release notes** now opens `CHANGELOG.md` on GitHub (readable notes) instead of the tag/assets-only page when no formal GitHub Release body exists. Notes are also loaded from the changelog when the release body is empty, and shown under Settings → Updates.

---

## [0.2.76] - 2026-07-28

### New features

- **Changelog and release process**: track work as New features / Enhancements / Bug fixes in `CHANGELOG.md`; ship with `scripts/Release-ColdAisle.ps1` (tag + GitHub Release notes). See `docs/RELEASING.md`.

---
## [0.2.75] - 2026-07-28

### Enhancements

- Apply PDU inventory template from the **Edit PDU** modal (not only on create); links template and can sync outlet inventory on save.

---

## [0.2.74] - 2026-07-28

### Enhancements

- Add/Edit PDU form: **Name** and **IP address** on the first row; fixed **3-column** form grid.

---

## [0.2.73] - 2026-07-28

### Enhancements

- Add PDU: **Apply PDU template** control moved to top of the modal.
- Selecting a template fills **SNMPv3 profile fields** (user, level, protocols) without re-selecting the profile.
- Template-filled fields show **subdued** styling until edited.

---

## [0.2.72] - 2026-07-28

### Enhancements

- **All PDUs** list shows **IP address** with a more compact table layout to reduce horizontal scrolling.

---

## [0.2.71] - 2026-07-28

### New features

- **Power â†’ PDU Templates**: list, create, and edit inventory templates.
- Edit template: **save template only** or **save and apply to all linked PDUs** (explicit `pdu_template_id` or legacy vendor+model match).

### Enhancements

- PDUs store `pdu_template_id` when created/updated from a template for bulk apply targeting.

---

## [0.2.70] - 2026-07-28

### Enhancements

- PDU inventory templates **include the site OID map** (`snmp_site_template_id`) and related SNMP poll options.
- Applying a PDU template on create also applies the bundled OID template and scheduled-poll flag.

---

## [0.2.69] - 2026-07-28

### New features

- PDU create/edit form: select **site OID template** and **scheduled SNMP poll** without only relying on Discover.

---

## [0.2.68] - 2026-07-26

### Enhancements

- README feature list and install docs refreshed for power/SNMP/ops work.
- Installers document and package the SNMP schedule stack (`run_poll_snmp.cmd`, Register/Enable scripts); optional `-EnableSnmp` / `-RegisterSnmpTask`.

---

## [0.2.67] - 2026-07-26

### Bug fixes

- Storage housekeeping **keep-last-N** now deletes excess backups even when files are newer than one hour (short write-grace only).

---

## [0.2.66] - 2026-07-26

### Enhancements

- Pre-update recovery zips always produced as real `.zip` files (ZipArchive or PowerShell fallback); size verified and logged.
- **Create recovery backup** action under Settings â†’ Updates.
- PHP `extension=zip` recommended; enabled where installers configure `php.ini`.

### Bug fixes

- Pre-update backup path clarified when deploys are file-copy (no zip) vs in-app Update.

---

## [0.2.65] - 2026-07-26

### New features

- **Storage housekeeping**: retention for pre-update and site-export zips, temp cleanup, log rotation; Settings UI; hooks after update/export and occasional SNMP worker run.

---

## Earlier releases

Releases before **0.2.65** were tagged in git with short commit messages. From this file forward, use **[Unreleased]** + `scripts/Release-ColdAisle.ps1` so every push has categorized notes.

Browse tags: https://github.com/sabap/ColdAisle/releases



