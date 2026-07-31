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

## [0.3.8] - 2026-07-31

### Bug fixes

- **Device Discover OIDs Internal Server Error:** harden SNMPv3 discover for env managers (UTF-8-safe JSON, shorter SNMP timeouts, env/probe trees walked before broad APC PowerNet, clearer errors when v3 user is missing).

---
## [0.3.7] - 2026-07-31

### Bug fixes

- **Device SNMPv3 profile not sticking on Save:** applying an SNMPv3 credential profile on a device now always forces version 3, copies user/level/protocols/passphrases from the profile on the server, re-applies on form submit, and shows v3 details on the device view. Invalid CSRF on save shows an error instead of a silent no-op.

---
## [0.3.6] - 2026-07-31

### New features

- **Env host device integration:** device types **Environmental monitor** and **Env expansion module**; when creating env gear into an occupied cabinet U, ColdAisle warns and offers to open the existing device or add sensors there (AP9340-style merge). Device detail **Environment** card lists linked sensors. Sensor form prefers host **Device**, optional floor/3D coords (`pos_x/y/z`) and SNMP index.

### Enhancements

- **LDAPS: group-gated account creation:** with org-wide Base DN, first-time domain logins create a ColdAisle user only if the person is in **any** mapped security group (**Users → Security group → role mapping**). Nested groups still apply. Settings → LDAPS checkbox **Require security group mapping to create accounts** (on by default). Existing users keep signing in.
- **SNMP Discover for environmental OIDs:** higher scoring/hints for humidity, dew point, IEM/EMS/uio probes; walks APC `…318.1.1.10` (Environmental Monitoring) and `…318.1.1.25` (Universal I/O) roots. Upload PowerNet MIB on **SNMP → MIBs** as before.

---
## [0.3.5] - 2026-07-31

### New features

- **Cooling & environmental monitoring (foundation):** own **Cooling** nav section (Dashboard, Air & pumps, Env sensors). Inventory CRAC/CRAH, in-row, chillers, chilled-water and AC pumps with primary/standby pairing (no cooling zones required), capacity/setpoints, warranty, IP, and SNMP fields. Place units on the floor plan like row PDUs. Environmental sensors (temp/humidity and related) support standalone, PDU, cooling-unit, cabinet, or room hosts; warn/crit thresholds; manual readings + history. ASHRAE TC 9.9 class guidance on units. Permissions `view_cooling` / `edit_cooling`. SNMP scheduled poll and mail alerts deferred to a later slice.

---

## [0.3.4] - 2026-07-31

### New features

- **Optional backup encryption:** site export can produce an AES-256-GCM encrypted `.caisle` file with a user-chosen password. Strong UI warning to retain the password; setup restore accepts `.caisle` + password. Password is never stored in ColdAisle.

---
## [0.3.3] - 2026-07-31

### New features

- **SMB backup target:** under **Settings → Site backup & migration**, nested **Copy backups to SMB share** (UNC). Credentials: IIS app pool, local Windows, or domain/AD. Password sealed with `app_key`. Auto-copy on site export and/or pre-update recovery ZIP; **Test connection** probes the share.

---
## [0.3.2] - 2026-07-31

### Enhancements

- **Settings collapsible sections:** section cards default to collapsed with Expand all / Collapse all; `#hash` links open the matching card; open state remembered in `localStorage`.

---
## [0.3.1] - 2026-07-31

### Bug fixes

- **Schema health missing on Settings:** card is always shown for Settings users (not only when `isAdmin` + status succeed); moved near the top after Diagnostics; errors display in the card instead of hiding it.

---
## [0.3.0] - 2026-07-31

### New features

- **Schema health (Settings):** Global Admin can see app version vs live SQL catalog (expected tables/columns), last ensure result, recent ensure log, and run **Ensure schema now**. Additive ensure records success stamps and logs; rare idempotent reshape/backfill steps included.

---
## [0.2.99] - 2026-07-31

### New features

- **Active sessions / presence:** track signed-in users in `auth_sessions` (heartbeat on page use). **Users & Departments** shows an **Online** badge; **Settings → Updates** warns if others are logged in before apply (warning only).

---
## [0.2.98] - 2026-07-30

### Enhancements

- **Medium faceplates for rack views:** cabinet elevation and row view use `.md.jpg` (~240px) for sharper devices; 3D and list thumbs keep tiny `.sm.jpg`. New uploads/imports write both; missing `.md` files generate on first request (or `php scripts/generate_image_variants.php md`).

---
## [0.2.97] - 2026-07-30

### Enhancements

- **Performance polish:** skip pending-file scan unless a deferred-update flag exists; skip crypto bootstrap after one-time stamp; request-timer uses a file flag (no SQL every page); dashboard **lazy-loads** Three.js / 3D after first paint.

---
## [0.2.96] - 2026-07-30

### Bug fixes

- **Still ~15s PHP after schema/pending fixed:** request timer now breaks out `session`, `db_connect`, and `page`; DB connect is timed; `media.php` uses light boot; session write lock released after layout header so parallel faceplates are not blocked; SQL Server DSN enables connection pooling.

---
## [0.2.95] - 2026-07-30

### Bug fixes

- **Slow every page (~15s PHP with idle SQL):** `applyPendingReplacements` no longer walks `storage/` (and other data dirs) on every request — only code paths for `*.coldaisle-new`. Schema ensure is stamped per app version so ~100+ catalog queries are skipped after the first successful run. Request timer footer shows boot phases (`schema` / `crypto` / `pending_files`).

---
## [0.2.94] - 2026-07-30

### Enhancements

- **Dev request timer:** optional footer timing (page total ms, SQL query count/time, PHP remainder, DB connect ms, browser after-HTML / TTFB / load). Enable under **Settings → Diagnostics** (Global Admin), or via `debug.request_timer` / env `COLDAISLE_DEBUG=1`. Leave off when not troubleshooting.

---
## [0.2.93] - 2026-07-29

### Enhancements

- **3D load performance:** racks appear immediately with solid type-color faces, then faceplates stream in with limited concurrency; dashboard loads **front faces only**; composed faces are cached in `sessionStorage` so returning to the dashboard is much faster. Floor plan 3D still textures front + rear.

---
## [0.2.92] - 2026-07-29

### Enhancements

- **Image performance:** faceplate uploads/imports now write a small companion (`.sm.jpg`, max ~96px) for cabinet elevations, row view, 3D textures, and template list thumbs. Device detail still uses full-resolution images. 3D view deduplicates shared template URLs and uses lighter face textures. Missing small variants are generated on first request (or via `php scripts/generate_image_variants.php`).

---
## [0.2.91] - 2026-07-29

### Enhancements

- **Chassis children in cabinet views:** only parent (rack-mounted) devices appear on cabinet elevations, row view, and 3D; U utilization ignores blades/modules. Parent rows list child devices with **Name / Make / Model / IP** (linked to the device page). Slot labels use openDCIM-style `U{parent}-{slot}` (e.g. `U23-1`).

### Bug fixes

- **OpenDCIM template images:** download from `/assets/pictures/` (this install) as well as classic `/pictures/`; re-fetch when the DB path is set but the file is missing on disk; clearer success/failure logging. Re-run migration with images enabled to backfill.
- **Child devices drawing in rack:** chassis blades/modules store **slot** in `position_u` (not rack U) — they no longer occupy elevation slots or conflict with rack U placement.

---
## [0.2.90] - 2026-07-29

### Enhancements

- **OpenDCIM migration modal:** replace the unreliable progress bar with a continuous **file-transfer animation** (openDCIM → ColdAisle) plus live status text and log; pulse when the job log updates so you can see the task is alive.

---
## [0.2.89] - 2026-07-29

### Bug fixes

- **OpenDCIM migration 500 on audits/images:** spawn real `php.exe` (not php-cgi) for background jobs, return JSON errors instead of bare IIS 500, safer image downloads, and continue past individual image/audit failures.

---
## [0.2.88] - 2026-07-29

### New features

- **OpenDCIM import:** cabinet **audits** (CertifyAudit → `cabinet_audits`) and **template front/rear images** (from openDCIM picture dirs).

---
## [0.2.87] - 2026-07-29

### Bug fixes

- **OpenDCIM import quality:** derive **ZONE / ROW** names from cabinet locations (e.g. `Z1-RA-R4` → ZONE 1 / ROW A) and **merge** into existing ColdAisle zones/rows instead of always creating new ones; map openDCIM **Primary IP / Hostname** into the correct field (IP vs hostname); resolve template **manufacturers** from picture prefixes (Dell, Cisco, APC, …); never blank existing **PDU IP/SNMP** when openDCIM has no value.

---
## [0.2.86] - 2026-07-29

### Bug fixes

- **OpenDCIM live connection:** retry flaky TLS/network failures (Windows PHP curl/stream), clearer error hints, and keep API key/connection fields in the browser session so re-Test does not silently send a blank key.

---
## [0.2.85] - 2026-07-29

### New features

- **OpenDCIM migration (Settings):** interactive wizard with Test connection, Preview (dry-run breakdown), and Run migration; progress modal with live log. Mode A merges into an existing data center without overwriting cabinet floor positions. Offline JSON dump source supported when live openDCIM is unreachable.

---
## [0.2.84] - 2026-07-29

### New features

- **Device templates: power supplies** — define named PSUs (watts, connector) on the template; they are created on the device when the template is applied. PDU outlet mapping is still done on the device after it is in a cabinet.

### Enhancements

- Device edit: removed the **Physical & power** card and legacy **Number of power ports** / power rows in interface labels. **Location** is now **Physical properties** (includes weight, nominal power, data port count).
- Interface labels card is **Data interface labels** only (power is the Power Supply section).
- Creating a device no longer auto-creates power ports; data ports still come from the data-port count.

---
## [0.2.83] - 2026-07-29

### New features

- **Device / PDU outlet mapping:** On device edit (**Power Supply**), choose a PDU assigned to the same cabinet, then an **available (unmapped)** outlet. Saving maps both sides: the PSU stores PDU/outlet, and the PDU outlet shows the device (with link to device properties) plus power-supply name.

### Enhancements

- Device view **Power connections**: PDU name links to the PDU page.
- `api/device_power.php`: validates same-cabinet PDUs, rejects already-mapped outlets, clears reverse links on unmap/delete; `?cabinet_pdus=1` lists free outlets for a cabinet.

---
## [0.2.82] - 2026-07-29

### New features

- **Brand mark & favicon:** icy snowflake + circuit/bit motif; SVG logo and favicon wired into app shell, login, and setup.

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



