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

## [0.3.60] - 2026-08-07

### New features

- **Discover manufacturer rulesets:** APC PowerNet, Liebert/Vertiv, Dell iDRAC, and a safe **default** for everyone else. Inventory manufacturer (then sysDescr) picks the path so iDRAC work cannot walk APC enterprise trees. Discover UI shows which ruleset ran.

---
## [0.3.59] - 2026-08-07

### Bug fixes

- **Device edit SNMP shows Disabled:** rehydrate SNMP version/profile/user from saved device data on Edit (plus server data attributes) so SNMPv3 settings match the properties page.
- **Device Discover “set model”:** Discover uses manufacturer/model from the linked device template when the device row fields are blank (same fallback as the properties view). Saving a device with a template also copies model/vendor onto the device if empty.

---
## [0.3.58] - 2026-08-07

### Bug fixes

- **Device edit SNMPv3 fields blank:** stop clearing the credential profile on page load; normalize version/protocol matching so saved v3 profile, user, level, and auth/priv show correctly on Edit (Dell iDRAC and other devices).
- **Device Discover OIDs unresponsive:** button always opens the discover UI and explains missing manufacturer/model/iDRAC-or-IP instead of doing nothing when disabled.
- **Revert mistaken 0.3.57 PDU UI changes:** restore PDU form / Discover / snmp_pdu.php to 0.3.56 behavior (those edits were aimed at Devices, not PDUs).

---
## [0.3.57] - 2026-08-07

### Bug fixes

- **PDU edit SNMPv3 fields blank:** normalize SNMP version/protocol matching so saved v3 profile, user, level, and auth/priv show correctly in Edit PDU (strict type/alias mismatch had left version as v1 and hid v3 fields).
- **PDU Discover OIDs unresponsive:** button always opens the discover UI and explains missing vendor/model/IP or SNMP credentials instead of doing nothing when disabled.

---
## [0.3.56] - 2026-08-07

### New features

- **Dell iDRAC SNMP target:** when manufacturer is Dell, set **iDRAC IP or Hostname** on the device. Discover, Poll, and the scheduler use that BMC address (instead of OS primary/mgmt IP). Device properties show an **Open iDRAC** HTTPS link in a new tab. SNMPv3 profiles/fields work the same as other devices; community can still be saved (iDRAC often requires one on the controller even for v3).

---
## [0.3.55] - 2026-08-06

### Enhancements

- **PDU list batch labels:** multi-select checkboxes + **Print labels** on Power → PDUs; same printer/size modal; multi-page print (one label per selected PDU).

---
## [0.3.54] - 2026-08-06

### Bug fixes

- **PDU label name width:** name grows to fill the frame width (about 1–2 print px padding each side) via width-fit + SVG textLength; detail lines stay ≤90% of the name width.

---
## [0.3.53] - 2026-08-06

### Bug fixes

- **PDU label type hierarchy:** name scales to fill the frame (with padding); IP/SN/MAC scale from the longest line and stay ≤90% of the name width; right border inset a few more units so the frame fully prints on 1.50″ tape.

---
## [0.3.52] - 2026-08-06

### Bug fixes

- **PDU labels on 1.50″ continuous tape:** for BMP51, all ink (including rounded border) stays in the left **1.50″** of the page even when the Windows dialog is 2×2; larger text in that frame.

---
## [0.3.51] - 2026-08-06

### Bug fixes

- **PDU label right border cut off:** reserve a **3/8″** right hard-stop so the full rounded frame prints; content scales inside the frame with tight padding (~1–2 print px past the stroke).

---
## [0.3.50] - 2026-08-06

### Enhancements

- **PDU label frame:** thin black rounded-corner border; text and QR stay inside frame padding so they never touch the edge.

---
## [0.3.49] - 2026-08-06

### Bug fixes

- **PDU label right-edge clip:** larger right safe margin; detail lines (IP/SN/MAC) scale down to fit the longest field fully; SVG textLength hard-cap so the last glyph is not cut by the printer stop.

---
## [0.3.48] - 2026-08-06

### Enhancements

- **PDU labels — multi-printer presets:** choose Brady BMP51, Zebra, Avery, or Generic sizes; BMP51 keeps 2×2 / 1.5×1 / 1×1 dialog sizes.
- **PDU ID label modal:** opens on the PDU detail page (no separate page); Print… / Download SVG from the modal.

### Bug fixes

- **PDU label text clipping:** more conservative width fit and side margins so name and full MAC print without cutting off.

---
## [0.3.47] - 2026-08-06

### Enhancements

- **PDU labels vs Brady dialog:** presets for **2″×2″**, **1.5″×1″**, **1″×1″** (sizes that print from Windows); content packed top-left (not centered); continuous sizes for SVG/Workstation; less text clipping.

---
## [0.3.46] - 2026-08-06

### Enhancements

- **PDU label fonts:** larger adaptive type fitted to the text area (name + detail lines); slightly more room for text vs QR on horizontal labels.

---
## [0.3.45] - 2026-08-06

### New features

- **PDU ID labels (Brady BMP51):** Power → PDU detail → **ID label** prints or downloads SVG tags with name, IP, serial, MAC, and a QR deep link to the PDU page. Horizontal/vertical orientation and length presets sized for continuous **1.50″** vinyl.

### Enhancements

- **PDU MAC address** field for asset tags and labels; **SNMP Discover / Poll** fills empty MAC from IF-MIB `ifPhysAddress` (management Ethernet preferred).

---
## [0.3.44] - 2026-08-03

### Enhancements

- **3D cooling units:** placed CRAC/CRAH/pumps render in 3D (dashboard, floor plan, NOC) as translucent wireframe solids with the ColdAisle snowflake logo on top; standby units draw lighter.
- **NOC auto-reload on version change:** wall display polls `App::VERSION` with metrics; on a new build it reloads so CSS/JS/HTML update without a manual refresh.

---
## [0.3.43] - 2026-08-03

### Enhancements

- **NOC wall panels:** pinned spinning 3D; rotating Overview / Power (24h kW sparkline) / Zone cards / Cooling + hottest sensors; Overview mini-trend + zone chips; tab progress bar; tabs pin a panel temporarily.

---
## [0.3.42] - 2026-08-03

### New features

- **NOC wall display:** public `pages/noc.php` for always-on TVs (no login / no session timeout); optional access token under Settings → NOC; live metrics via `api/noc.php` without full page refresh; slowly spinning 3D floor (top-left; solid racks without faceplate auth).

---
## [0.3.41] - 2026-08-03

### New features

- **Site temperature unit (°C / °F):** Settings → General chooses Celsius or Fahrenheit for the whole site. Values stay stored in °C; UI, charts, thresholds, cooling setpoints, and env alert emails convert for display/entry. Humidity stays %RH.

---
## [0.3.40] - 2026-08-03

### Bug fixes

- **Cooling Discover timeout:** shorten Liebert walk list / per-OID timeouts and enforce a wall-clock budget so Discover cannot exceed PHP max_execution_time (25s fatal on unit 2).

---
## [0.3.39] - 2026-08-03

### Enhancements

- **Cooling Discover (IS-UNITY):** probe/walk LGP identity `476.1.42.2` (Vertiv / model / firmware) first; clearer message when identity works but condition tables (`.3`) are empty.

### Bug fixes

- **Discover candidate OIDs:** prefer longest numeric OID and fix relative walk keys so candidates are not truncated to `3.0` / `9.1.4.x`.

---
## [0.3.38] - 2026-08-03

### Enhancements

- **Cooling Discover diagnostics:** prioritize Emerson **476** walks (with SNMPv3 context via SNMP class), skip APC leaf thrash on air units, and report when enterprise returns empty (typical MIB-view ACL on Liebert card).

---
## [0.3.37] - 2026-08-03

### Enhancements

- **Cooling Discover (Liebert):** air-unit Discover walks Emerson/Liebert enterprise **1.3.6.1.4.1.476** (LGP condition tables), probes known supply/return OIDs, and proposes `supply_temp` / `return_temp` map keys (not APC-only roots).

---
## [0.3.36] - 2026-08-03

### New features

- **Air unit SNMP poll path:** Discover OIDs, save site template, **Poll now**, and scheduled poll on Cooling → Air & pumps unit detail (`api/snmp_cooling.php`); metrics snapshot in `last_poll_json`.

### Bug fixes

- **Floor plan cooling nudge:** arrow-key nudge works for selected air units (same as cabinets/PDUs).

---
## [0.3.35] - 2026-08-03

### Bug fixes

- **Air unit SNMP version display:** edit form correctly selects **v3** (and v1) after save; Network metric shows version (and v3 user) instead of always looking like v2c.

---
## [0.3.34] - 2026-08-03

### Bug fixes

- **Air unit SNMPv3:** enable SNMP + version **v3** now shows credential profile selector and full v3 fields (user, auth/priv, context), matching PDUs; secrets sealed; profiles from SNMP → Profiles.

---
## [0.3.33] - 2026-08-03

### New features

- **Env sensor refinements (4):**
- **Scheduled poll:** saving a site OID template on `env_monitor` / `env_module` turns on `snmp_auto_poll`; device UI reflects it and tips keep the Windows task path clear.
- **Threshold email alerts:** Settings → Environmental alerts (enable, recipients, cooldown, RH warn/crit); fires after SNMP poll and manual readings via `EnvSensorAlertService`.
- **Combo humidity:** list shows `°C / %RH`; sensor detail has a dedicated RH metric card and dual history series.
- **24h history chart:** SVG charts on sensor detail (`api/env_history.php` + `assets/js/env-charts.js`).

---
## [0.3.32] - 2026-08-01

### Bug fixes

- **Heat sphere cores:** place on the cabinet face **center** (intake/exhaust edge), not offset into the aisle or shifted to the right by port index.

---
## [0.3.31] - 2026-08-01

### Enhancements

- **3D heat spheres:** radius halved (~3 ft); extremely soft feathered edges via nested additive shells and radial alpha falloff.

---
## [0.3.30] - 2026-08-01

### Bug fixes

- **Heat sphere positions:** use each env sensor’s **Cabinet** property (deployed rack), not the management/expansion module chassis location.

---
## [0.3.29] - 2026-08-01

### Bug fixes

- **Heat spheres stacked on MM rack:** TH0N sensors no longer fall back to the management module cabinet; resolve expansion device/rack by label (`TH01`, `[01]`, EXP…), optional location match, and lateral offset by port so probes separate along the face.

---
## [0.3.28] - 2026-08-01

### Bug fixes

- **3D heat spheres missing:** harden env→cabinet resolution (no fragile SQL columns); show dashboard hint when sensors have values but cabinets are not placed on the floor plan.

---
## [0.3.27] - 2026-08-01

### New features

- **3D temp heat spheres:** translucent ~6 ft influence bubbles on Dashboard (and floor-plan 3D) from env sensor last temps; position from cabinet + placement (equipment intake → cold face) or explicit coords; toggle on dashboard; not CFD.

---
## [0.3.26] - 2026-08-01

### Enhancements

- **Env sensors table:** show actual host **device name** (linked), not only “Device (env manager…)”; roomier columns, short kind labels, placement/cabinet and last-seen subtext.

---
## [0.3.25] - 2026-08-01

### Bug fixes

- **Env sensor map collisions:** exclusive assignment by exact MEM name then TH0X:Y module.sensor (no shared slots like TH01:3 and TH02:3 both on M1S3).

---
## [0.3.24] - 2026-08-01

### Bug fixes

- **AP9340 TH expansion temps:** poll Modular Env Manager table `1.3.6.1.4.1.318.1.1.10.4.2.3` (module.sensor — matches TH02:3 etc.); use high-precision tenths when present; map by name/module; EMS R# slots were empty placeholders.

---
## [0.3.23] - 2026-08-01

### Bug fixes

- **EMS expansion still 0°C:** walk APC **Universal I/O** sensor table (`318.1.1.25`) for TH module temps (EMS `R#` slots were empty placeholders); relax live detection; map TH01:N → UIO port/sensor; show UIO in Poll snapshot.

---
## [0.3.22] - 2026-08-01

### Bug fixes

- **EMS expansion 0°C readings:** use PowerNet **L#/R#** probe serials (local MM vs remote TH), skip dead/no-comms slots, treat temps as whole degrees (not tenths), re-map sticky wrong indexes, show slot snapshot + match list on Poll.

---
## [0.3.21] - 2026-08-01

### Bug fixes

- **EMS probe names were thresholds:** read `emsProbeStatusProbeName` (.2) not high-temp thresh (.4) which showed as “59”; reject numeric fake names; order-fallback maps remaining TH sensors to free probe indexes.

---
## [0.3.20] - 2026-08-01

### Bug fixes

- **EMS expansion module sensors on Poll:** expand the full APC probe table (not only template 1–4), read probe **names**, match TH/MM sensors by label (not port→index 1), and include all `env_module` hosted sensors as candidates.

---
## [0.3.19] - 2026-08-01

### New features

- **Env sensor SNMP poll:** device **Poll now** / scheduler writes `temperature.*` and `humidity.*` from the site OID template into matching env sensors (`last_value`, optional `last_humidity` for combo) and `env_readings`. Matches by probe/map key, OID, or name (`MM:1`, `TH01:1`, …).

---
## [0.3.18] - 2026-08-01

### Bug fixes

- **Add sensor modal:** self-contained open/close (no dependency on stale `app.js` cache); works on Env sensors list and Device → Environment card as a popup (form POSTs to env sensors). Cache-bust CSS/JS by app version.

---
## [0.3.17] - 2026-08-01

### New features

- **Env sensor kind:** Temperature + humidity (combo) for dual probes (TH/MM combo sensors).

### Enhancements

- **Env sensor form:** clearer SNMP help (OID/index optional; probe/map key examples); unit auto-fills from kind.

### Bug fixes

- **Env sensors / Cooling units Add button:** wire `data-modal-open` and restyle broken modals so Add sensor / Add unit open correctly.

---
## [0.3.16] - 2026-08-01

### Bug fixes

- **Device Poll now (v1/v2c):** pass the device community into the SNMP session (Discover already did; Poll incorrectly used empty → `public`). Clearer error when *all* OIDs fail vs a single missing probe.

---
## [0.3.15] - 2026-08-01

### Bug fixes

- **Discover proposed map:** stop mapping EMS temperature OIDs to `watts` (temp.1 was reused as power). Env trees and already-claimed `temperature.*` / `humidity.*` OIDs are excluded from power fallbacks.

---
## [0.3.14] - 2026-08-01

### Bug fixes

- **Discover hang fix shipped:** 0.3.13 tagged the notes only; this release includes the actual leaf-first / EMS-before-PDU Discover code so IIS no longer returns a bare Internal Server Error on AP9340.

---
## [0.3.13] - 2026-08-01

### Bug fixes

- **Discover bare Internal Server Error (IIS):** stop walking the full EMS tree (`318.1.1.10`); leaf-GET first with short timeouts and a wall-clock budget; probe EMS OIDs before PDU OIDs so AP9340 does not spend 20s timing out power leaves; skip walks when live temp/humidity already found; v1 uses `snmpget`; step log in `storage/logs/snmp_discover_last.txt`; UI points at that log for non-JSON 500s.

---
## [0.3.12] - 2026-08-01

### Enhancements

- **EMS / AP9340 Discover:** filter out probe *config/threshold* OIDs; walk live status tables; propose `temperature.N` / `humidity.N` map keys from status readings.

---
## [0.3.11] - 2026-08-01

### Bug fixes

- **Device SNMP version reverts to v3 after Save:** choosing v1/v2c now clears the SNMPv3 credential profile so it cannot force version 3 on the next save.

---
## [0.3.10] - 2026-07-31

### Bug fixes

- **Discover IIS timeout / Internal Server Error:** fail fast when sysDescr probe fails (no multi-tree walk hang); skip snmp_read_mib in web Discover; shorter SNMP timeouts; fatal errors returned as JSON.

---
## [0.3.9] - 2026-07-31

### Bug fixes

- **Discover still Internal Server Error (Windows Net-SNMP):** stop setting `MIBS=ALL` during MIB load (that hang is why the poll worker clears `MIBS`). Discover now matches the poller env, discards Net-SNMP stdout noise before JSON, and falls back if SNMP timeout args are unsupported.

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



