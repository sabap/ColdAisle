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

## [0.3.119] - 2026-08-11

### New features

- **Asset lifecycle depth (G-B3):** device PO / purchase / RMA fields; **Lifecycle & chain of custody** event log (auto on status, location, ownership, warranty, PO, RMA + manual custody notes); warranty expiration email digests via poll worker; richer **Reports → Warranty Expiration** (remaining days, filters, dept/asset tag)

---
## [0.3.118] - 2026-08-11

### New features

- **Mail product flows (G-B5):** welcome email on user create (login + set-password link, no password in message); local **Forgot password** / **Reset password** pages with hashed tokens (2h TTL); disposal due-soon email digests from the poll worker (`notification_sent` once per target date; Settings → disposal notify days / enable / recipient)

---
## [0.3.117] - 2026-08-11

### Enhancements

- **Work orders:** bulk-add all rack devices from a source cabinet (optional shared destination); **Moves this week** strip + list filter for open WOs scheduled Mon–Sun

---
## [0.3.116] - 2026-08-11

### New features

- **Change / move work orders (G-B2):** plan rack moves with change ticket, scheduled date, from→to cabinet/U, checklist, status lifecycle (draft → planned → in progress → complete/cancel); optional apply destinations to inventory; nav **Work orders**; device **Move / work order** deep link

---
## [0.3.115] - 2026-08-11

### New features

- **OID pack library (Tier A / G-A4):** SNMP → **Built-in OID pack library** installs curated packs from `config/snmp_oid_templates.json` as shared site templates (one-click); **Import Vertiv DS** from `AC_Vertiv_Thermal` NMS JSON (no control SETs); new Liebert DS + AP9340 env host packs

---
## [0.3.114] - 2026-08-11

### Bug fixes

- **NOC “Network error loading NOC data”:** `includes/cooling_helpers.php` lost its `<?php` open tag (file was emitted as HTML into `api/noc.php` JSON); restored tag; NOC client shows a clearer invalid-JSON message when the API response is not parseable

---
## [0.3.113] - 2026-08-11

### New features

- **Parallel SNMP poll worker pool:** scheduled and zone polls fan out across concurrent CLI unit workers (default 8, configurable 1–32 under Settings → SNMP schedule); each dead host is capped (~45s) so timeouts no longer serialize the whole fleet

---
## [0.3.112] - 2026-08-11

### New features

- **SNMP last-poll age + zone poll (Tier A / G-A5):** relative polled ages (green / warn &gt;1h / danger &gt;4h) on PDU, UPS, cooling, and SNMP schedule lists; **Poll SNMP now** on power zone detail force-polls zone PDUs + UPS; NOC reports fleet SNMP stale count

---
## [0.3.111] - 2026-08-11

### New features

- **Env stale / offline alerts (Tier A / G-A3):** after each SNMP poll cycle, detect sensors with no report past a configurable age; digest or per-sensor mail; Settings → Alerts hub options
- **Cooling live telemetry cards:** promote known keys from `last_poll_json` (supply/return/control temp, humidity, state, capacity, alarms) on unit detail and Cooling dashboard

### Enhancements

- **Cooling dashboard:** sensors sorted by crit/warn/stale first; unit table shows snapshot telemetry when available
- **Env scheduled scan:** re-evaluate all sensors with readings after poll (not only the device just polled)

---
## [0.3.110] - 2026-08-11

### New features

- **Capacity planning / phase imbalance (Tier A / G-A2):** zones show free kW, free U (rows on zone), phase amps, and **Imbalanced** when phase skew ≥20%; Power dashboard facility headroom strip; Reports → **Power Capacity** live table + **Fits?** filter (need free kW / free U)

---
## [0.3.109] - 2026-08-11

### New features

- **Power path report (Tier A / G-A1):** Reports → **Power Path** walks device PSU → rack PDU outlet → row/room breaker feed → zone → UPS (zone association); filters for unmapped PSUs, single-feed/partial map, half-map links, cabinets without row feed; Power dashboard risk card links to the report

---
## [0.3.108] - 2026-08-11

### Bug fixes

- **UPS (and cooling) missing from SNMP Scheduled polling list:** units with **Scheduled poll** on now appear with type badge, last load/battery, Open/Unschedule; Incomplete if no site OID template or IP (worker already polled them)

---
## [0.3.107] - 2026-08-11

### Bug fixes

- **UPS graphs “No data yet” after Poll:** history series used wrong column `last_poll_at` (actual: `snmp_last_poll_at`), so unit load failed and charts returned empty series

---
## [0.3.106] - 2026-08-11

### Bug fixes

- **UPS history charts only a single blip:** series now holds the last sample for ~90 minutes (was ~10) and forward-fills gaps so 24h graphs draw a continuous line; local time labels; fallback to unit last-poll snapshot if `ups_readings` is empty

---
## [0.3.105] - 2026-08-11

### Enhancements

- **Cabinet QR + field audit polish:** per-cabinet QR preview and labels (SVG/PNG/print/sheet); bulk QR sheet from cabinet list and per-room; field mode (`?field=1`) with sticky audit bar; audit modal shows inventory snapshot; deep link `?audit=1` opens log-audit

---
## [0.3.104] - 2026-08-10

### Enhancements

- **PDU list split:** Power → PDUs shows separate **Row PDUs** and **Cabinet PDUs** tables (room section when present); shared batch ICMP/labels toolbar; select-all is per section

---
## [0.3.103] - 2026-08-10

### Enhancements

- Maintenance release.

---
## [0.3.102] - 2026-08-10

### Enhancements

- Maintenance release.

---
## [0.3.101] - 2026-08-10

### Enhancements

- **Facility load rollup (no double-count):** site/zone totals and 24h facility charts honor a configurable mode — **Sum all PDUs**, **Prefer row/room meters** (recommended when cabinets feed from row PDUs), or **Manual** per-PDU “Include in site load”. Power Dashboard explains the active mode and warns when rack+row would double-count under Sum all

---
## [0.3.100] - 2026-08-10

### Enhancements

- **SNMP Poll progress modal:** Poll now opens an animated device→ColdAisle GET-packet overlay (status tips while working); timeout/error stay on the modal with Close / click-outside; no more full-page blank HTTP 500 on poll timeout

### Bug fixes

- **PDU Poll now blank 500:** form POST replaced with AJAX (`api/snmp_pdu.php`); client 55s abort + server try/catch JSON errors; same overlay on devices, UPS, and cooling Poll now

---
## [0.3.99] - 2026-08-10

### Bug fixes

- **False “low voltage” outage markers on healthy 120 V row PDUs:** outage detection no longer treats normal L–N (~100–132 V) as low_v when inventory nominal is L–L (e.g. 208); charts re-evaluate markers from stored phase volts so existing false positives clear on refresh

---
## [0.3.98] - 2026-08-10

### Enhancements

- **PDU Discover auto-fills serial and model:** writes empty inventory Serial / Model from PowerNet ident OIDs (or sysDescr `SN:` / `MN:`); model no longer required before Discover; Overview and Edit form update live when saved

---
## [0.3.97] - 2026-08-10

### Bug fixes

- **APC xPDU Discover empty candidates:** PD40 / 0M-5103 (PowerNet 15.x) now uses longer GET timeouts (~1.5s, matching Poll), scores unnamed 15.x leaves, and probes map OIDs into the Candidates table when snmpwalk returns little; skip misleading MIB-index warning when xPDU map is seeded

---
## [0.3.96] - 2026-08-10

### Enhancements

- **APC InfraStruXure row xPDU (PD40G6FK1 / 0M-5103):** Discover walks PowerNet `318.1.1.15` (system output + phases), seeds a dedicated map (tenths kW/A/V scales), stock template **APC InfraStruXure xPDU** — isolated from rack rPDU 12/26 paths so AP7862 polling is unchanged

---
## [0.3.95] - 2026-08-10

### Enhancements

- **Natural sort for PDU (and UPS) names:** lists use alphanumeric order so unit 1…9 appear before 10 (e.g. RA-R1 before RA-R10)

---
## [0.3.94] - 2026-08-10

### Enhancements

- **NOC Power wall:** live PDU load (kW, polled count, amps, top PDUs), UPS load % / est. kW / battery, dual 24h sparklines (facility kW + UPS load/est. kW), per-UPS electrical row (V in/out, A, Hz, load, runtime)

---
## [0.3.93] - 2026-08-10

### Enhancements

- **UPS detail electrical charts:** 24h graphs for output load %, input/output voltage, input/output frequency, output current, battery, est. kW

### Bug fixes

- **UPS Load % / Est. output empty on Power dashboard & zones:** poller only read `output_load` and ignored `load_pct` (stock Discover key); accept both aliases and store full electrical sample for history

---
## [0.3.92] - 2026-08-10

### Enhancements

- **AP7862 PDU detail unsupported metrics:** Load current chart shows watermark “Unsupported on this unit” when SNMP has no usable amps; hide phase-voltage / util / power indicators that are not collected; polled-current card matches; classic load-state chart labels fixed

---
## [0.3.91] - 2026-08-10

### Bug fixes

- **Facility output kW step-drop after re-poll / UPS work:** site/zone hold-forward no longer drops a PDU from the sum when a poll sample has null watts (common for AP7862 load-state polls); holds last real watts up to 45 minutes. Chart is still PDU-only (UPS never subtracted).

---
## [0.3.90] - 2026-08-10

### Enhancements

- **AP7862-style PDU detail (amps-only SNMP):** hide Polled load kW card and Output (usage) chart when the NMC only reports amps/load-state (like APC Load Management); show Polled current (A), load current chart, and phase load-state; treat Line-to-Line voltage as device L–L (not L1-only); fix classic load-state labels (1=normal)

---
## [0.3.89] - 2026-08-10

### Bug fixes

- **AP7862 poll diag:** walk classic load-status indexes 1–12 (phases + banks) and include tenths-of-A map in Poll now output when total load stays 0

---
## [0.3.88] - 2026-08-10

### Bug fixes

- **AP7862 still 0 kW with full template:** stop injecting rPDU2 outlet/phase OIDs on classic-only cards (AOS 3.9); map `input_volts` (Ident L–L …12.1.15); I×V uses NMC voltage + PF; Poll now shows **load diagnostics + raw metric values** so operators can paste results without giving production access

---
## [0.3.87] - 2026-08-10

### Bug fixes

- **AP7862 Discover almost empty (walk count ~3):** APC walk roots were EMS/rPDU2-only and skipped classic `rPDU` (`318.1.1.12`) that AOS 3.9 / AP7862 uses; expanded leaf probes (phase/bank amps, Ident watts, rPDU2 power), seed full AP78xx OID map when sparse, fix stock templates, poll banks 4–6 when phases are 0

---
## [0.3.86] - 2026-08-10

### Bug fixes

- **APC AP7862 still 0.00 kW after UPS work:** poll recovery probes rPDU2 power + legacy rPDU phase amps and estimates W via I×V; reclassifies Discover maps that put `rPDULoadStatusLoad` (tenths A) under `watts`; Discover no longer treats current/load-status or UPS trees as PDU watts. UPS commits did not rewrite PDU OIDs — chart drop tracks zeroed poll samples after bad/zero watts maps.

---
## [0.3.85] - 2026-08-10

### Bug fixes

- **APC AP7862 load still 0.00 kW on old site maps:** on poll, auto-inject `rPDU2DeviceStatusPower` + phase I/V/P when the map only had Ident watts (often stuck at 0); last-chance SNMP probe of device power; I×V using inventory voltage when phase volts are missing

---
## [0.3.84] - 2026-08-10

### Bug fixes

- **APC PDU load 0.00 kW (AP7862 / rPDU2):** auto-scale PowerNet rPDU2 power (hundredths of kW) and current (tenths A) from OID path even when the site map key is plain `watts`/`amps`; ignore APC `-1` unsupported; prefer phase power or I×V when Ident watts stays 0; stock AP78xx template + rPDU2 total-power key.

---
## [0.3.83] - 2026-08-10

### Enhancements

- **NOC alerts glass:** recent-alerts toast sits over the left 3D view (bottom-left of the 3D panel) instead of the right edge.
- **UPS testing mode:** when Settings → Diagnostics testing mode is on, UPS detail offers **Simulate connectivity outage**, **Simulate on battery**, and **Simulate recovery** ([TEST] alerts).

---
## [0.3.82] - 2026-08-10

### Enhancements

- **Power Templates:** renamed from PDU Templates; page has separate **PDU templates** and **UPS templates** cards.
- **UPS inventory templates:** create from UPS detail (**Create UPS template**), manage under Power Templates, select on **New UPS** to prefill model/size/SNMP/OID map.

---
## [0.3.81] - 2026-08-10

### Enhancements

- **UPS history charts:** samples stored on each poll (`ups_readings`); Power dashboard overall PDU load + site UPS load/battery graphs; zone detail and zone list show UPS load 24h charts.
- **Zones + UPS:** zone cards/table include UPS count and avg load; zone detail lists UPS with health and dual PDU/UPS history panels.

---
## [0.3.80] - 2026-08-10

### Enhancements

- **UPS on Power dashboard:** metric tile, attention line (on battery / critical), full UPS inventory card (load/battery/runtime/health table).
- **NOC UPS:** overview tile + Power panel cards (units, load, battery, rated kVA) and per-unit list with health badges.
- **Main Dashboard UPS:** summary metric card linking to Power (online / on battery / load).

---
## [0.3.79] - 2026-08-10

### Enhancements

- **UPS OID template assignment:** detail page shows assigned site template with Apply dropdown; Edit UPS has OID map selector; **Use default APC UPS map** creates/assigns PowerNet defaults without re-Discover; share one template across multiple UPS units.

---
## [0.3.78] - 2026-08-10

### Bug fixes

- **Floor plan UPS nudge:** arrow keys / nudge amount move an unlocked UPS (was ignored); cooling nudge also updates its own X/Y fields.

---
## [0.3.77] - 2026-08-10

### Enhancements

- **UPS delete / floor plan / manufacture date (complete ship):** same notes as 0.3.76, with the implementation commit included in this tag for Settings → Updates.

---
## [0.3.76] - 2026-08-10

### Enhancements

- **UPS delete:** detail page **Delete UPS** soft-removes inventory (clears floor position, stops scheduled poll).
- **Floor plan UPS:** click shows properties panel (name, size, position, facing, color); unlock → drag/rotate/snap → Save; **Unplace** returns to palette. Facing normalized to north/east/south/west like PDUs/cooling.
- **UPS SNMP:** poll fills **Manufacture date** from PowerNet `upsAdvIdentDateOfManufacture` when available (mm/dd/yy → inventory date).

---
## [0.3.75] - 2026-08-10

### Enhancements

- **UPS inventory:** asset tag, warranty company/expiration, install & manufacture dates (same pattern as devices).
- **UPS SNMP poll:** writes polled serial into the Serial Number field; formats `sysuptime` / TimeTicks as human-readable uptime (not raw hundredths).

---
## [0.3.74] - 2026-08-10

### New features

- **UPS inventory (Power → UPS):** in-row and in-rack units (Schneider Symmetra 40K–oriented). Floor plan placement, dashboard/NOC 3D with soft health glow, SNMPv3 Discover/Poll via PowerNet UPS ruleset (`ups` family), scheduled poll, load/battery/runtime status.
- **Discover ruleset `ups`:** APC/Schneider PowerNet UPS OID walks (318.1.1.1) separate from rPDU/EMS.

### Enhancements

- Floor plan palette: UPS presets + unplaced list; metrics panel links to UPS list.

---
## [0.3.73] - 2026-08-10

### New features

- **SNMP custom thresholds (Settings → Alerts):** define warn/crit rules by metric key for devices, PDUs, or cooling units; evaluated after SNMP poll; routes through AlertService (`snmp` category).
- **NOC recent alerts glass toast:** persistent frosted panel (survives rotating Overview/Power/Zones/Cooling) fed by live `recent_alerts` from the NOC API.

---
## [0.3.72] - 2026-08-10

### Bug fixes

- **Settings → Diagnostics:** Testing mode switch no longer overlaps the “Testing mode” title/description.

---
## [0.3.71] - 2026-08-07

### Enhancements

- **NOC 3D health:** cabinet outage/warn state updates every metrics poll (not only 5‑minute scene reload); soft red/amber volume glow + floor bloom pulse instead of hard outline rails.

### Bug fixes

- **NOC 3D:** simulated and real ICMP cabinet health was missing or stale because `cabinet_health` was not pushed on live polls.

---
## [0.3.70] - 2026-08-07

### New features

- **Testing mode (Global Admin):** Settings → Diagnostics toggle exposes **Simulate outage** / **Simulate recovery** on device and PDU pages (and bulk on lists). Forces ICMP DOWN/UP and fires `[TEST]` alerts through the notification hub.
- **Batch ICMP monitor:** select devices or PDUs on list pages → **Monitor ICMP** / **Stop ICMP** (and simulate actions when testing mode is on).

---
## [0.3.69] - 2026-08-07

### New features

- **Cabinet / 3D health coloring:** aggregate ICMP, power alerts, and env thresholds per cabinet; tint dashboard/NOC/floorplan 3D racks, floorplan 2D glow + beacon, row/list health chips, and cabinet detail indicators. Soft emissive pulse on warn/crit (not chunky boxes).

---
## [0.3.68] - 2026-08-07

### New features

- **Alerts & notifications hub (Settings):** one panel for global delivery, ICMP / power / env thresholds, and routing subscriptions (global, department, device, PDU). Platform foundation for future SNMP thresholds.
- **Live toast feed:** unread notifications surface as modern toasts without leaving the page (`api/notifications.php` poll).
- **Health indicators:** soft pulse chips on device and PDU lists (ICMP UP / degraded / DOWN) with row accent; CSS ready for cabinet / 3D views later.

### Enhancements

- **Env alerts** route through `AlertService` (in-app + subscription email), not email-only.
- Notifications page copy points at Settings → Alerts; `alert_subscriptions` documented in schema.

---

## [0.3.67] - 2026-08-07

### Enhancements

- Changelog / version bump only (implementation shipped in **0.3.68**).

---
## [0.3.66] - 2026-08-07

### Bug fixes

- **Device ICMP host:** ping **mgmt/primary IP only** (OS path). iDRAC is not used — BMC stays reachable when the OS is down.

---
## [0.3.65] - 2026-08-07

### New features

- **ICMP monitoring (devices + PDUs):** toggle **Monitor via ICMP** (same checkmark style as scheduled SNMP poll), **Ping now**, and status badge (UP / Degraded / DOWN). Uses industry-style debounce: 3 packets, 1s timeout, **3 consecutive fails → DOWN**, fast recovery on success. Runs in the existing Windows poll task; optional email on down/recovered.

---
## [0.3.64] - 2026-08-07

### Enhancements

- **iDRAC identity poll:** template keys `service_tag` / `system_model` fill empty device **Serial** and **Model** on successful Poll (does not overwrite existing values).

---
## [0.3.63] - 2026-08-07

### Bug fixes

- **Device Poll after Discover (iDRAC):** use the same procedural SNMPv3 GET path as Discover, normalize auth/priv protocols, resolve FQDN to IP when possible, and include host/user/level in poll failure messages.

---
## [0.3.62] - 2026-08-07

### Enhancements

- **Device SNMP poll errors:** total GET failure message includes the target host (and notes iDRAC host when used) plus a reminder that agent IP allow-lists live on the iDRAC, not in ColdAisle.

### Bug fixes

- **Device SNMPv3 poll:** honor stored security level (authPriv / authNoPriv) instead of only inferring from whether passphrases are present.

---
## [0.3.61] - 2026-08-07

### Enhancements

- **iDRAC Discover scoring:** Dell identity OIDs (service tag / express service code) are no longer labeled “possible watts”; extra power/thermal/status leaf probes for common iDRAC OMSA objects.

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



