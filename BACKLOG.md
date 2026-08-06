# ColdAisle backlog

**Single source of truth** for deferred product work.  
**Do not implement until explicitly requested.** When picking up an item, confirm scope first; prefer the smallest useful slice.

### How backlog was tracked before this file

| Era | Where it lived | Notes |
|-----|----------------|--------|
| Pre-`BACKLOG.md` | Inline `BACKLOG` comments in source + git commits titled `Note: backlog…` | Real, durable notes—but easy to miss |
| Ongoing | Chat “what’s next?” menus | **Ephemeral** unless promoted here or into a `Note:` commit |
| 2026-07-26+ | This file (`BACKLOG.md`) | Prefer this over new inline notes |

Historical note commits:

- `dbae6e0` — Schema status hardening pass  
- `bbb8a3b` — Update backup retention / housekeeping (**done** → 0.2.65+)  
- `dfdeb03` — Active users presence (first `BACKLOG.md` entry)

---

## Open (formal backlog)

### 1. Active sessions / presence

| Field | Value |
|-------|--------|
| **Status** | **done** (release **0.2.99**) |
| **Requested** | 2026-07-26 (user) |
| **Source** | `BACKLOG.md` / commit `dfdeb03` |
| **Delivered** | `auth_sessions` registry + heartbeat; Online badge on Users; Settings → Updates warning |

**Goal:** Show who is signed in; warn before in-app updates while others are active.

**Acceptance**

- [x] Online users visible on Users & Departments (≤ ~2 min staleness)  
- [x] Update apply shows count + sample usernames when sessions are active (warning only)  
- [x] Expired sessions not shown as active  
- [x] Local / LDAPS / Entra accounts behave the same (shared `touchSession` path)  

---

### 2. Schema status / hardening pass

| Field | Value |
|-------|--------|
| **Status** | **done** (release **0.3.0**) |
| **Requested** | 2026-07-24 (agent note after long upgrades; user acknowledged “note hardening”) |
| **Source** | commit `dbae6e0`, `src/Schema.php` |
| **Delivered** | Settings → Schema health; ensure log; force ensure; inventory vs catalog; idempotent reshapes |

**Items**

- [x] Schema status / health UI: app `VERSION`, last ensure OK, expected tables & columns vs live DB  
- [x] Ensure-run log + `schema_version` / last ensure settings  
- [x] Explicit idempotent reshape/backfill hooks (e.g. presence/breaker nulls)

---

### 3. Site backup to SMB share

| Field | Value |
|-------|--------|
| **Status** | **done** (release **0.3.3**) |
| **Requested** | 2026-07-27 (user) |
| **Source** | `BACKLOG.md` |
| **Delivered** | `SmbBackupService`; Settings → Site backup nested “Copy to SMB”; app pool / local / domain creds; test + auto-copy on export / pre-update |

**Acceptance**

- [x] Admin can set SMB UNC (and credentials if required) and save securely  
- [x] Manual site backup can land on the share when configured  
- [x] Failure is visible (permissions, path missing) without corrupting local backup  
- [x] Works under typical IIS app-pool / SYSTEM poll identities with documented NTFS+share ACLs  

---

### 4. Cooling & environmental monitoring

| Field | Value |
|-------|--------|
| **Status** | **done (foundation)** (release **0.3.5**) — SNMP poll worker + threshold mail alerts deferred |
| **Requested** | 2026-07-29 (user) |
| **Source** | `BACKLOG.md` |
| **Priority** | product expansion (post power/SNMP foundation) |

**Goal:** Track cooling plant and room environment alongside power — so ColdAisle covers thermal/air as well as electrical load.

**Shipped in foundation (0.3.5)**

- Nav section **Cooling** (dashboard, Air & pumps, Env sensors) with `view_cooling` / `edit_cooling`
- `cooling_units` inventory (CRAC/CRAH/in-row/chiller/CW & AC pumps/CDU) with primary/standby pairs, medium, capacity, warranty, SNMP fields, floor placement like PDUs
- `env_sensors` + `env_readings` with host types (standalone / cooling unit / PDU / cabinet / room), thresholds, manual readings
- ASHRAE TC 9.9 class guidance on units (not a compliance engine)
- Floor plan palette + place/move/unplace cooling footprints

**Still open (follow-up slices)**

1. **SNMP poll end-to-end** for cooling units + env probes (site OID templates map → `env_readings`, worker)  
2. **AP9340 field mapping** — after user Discover/OID export, curated PowerNet env template (temp/humidity per probe)  
3. **Threshold alerts / digests** via mail (high temp/humidity, offline sensors)  
4. History charts (reuse power-history patterns)  
5. **3D / floor plan sensor markers** using `env_sensors.pos_*` (height/Z later)  
6. Optional heat/humidity floor overlay  
7. **Vertiv / Liebert DS LGP condition SNMP** — formal item **#8** (blocked on Unity VACM / Monitoring Support; templates in `AC_Vertiv_Thermal/`)  
8. **3D airflow particles** (ceiling vents → cold aisle → hot aisle → returns) — formal item **#9**

**Recommended order (AP9340 site)**

1. Tag AP9340 as **Environmental monitor**; expansion modules as **Env expansion module** (or leave type, just link sensors to the manager)  
2. **SNMP → MIBs** — upload APC PowerNet MIB pack  
3. On AP9340 device → **Discover OIDs** → save site template; share walk results for curated fields  
4. **Cooling → Env sensors** — create points with host = that device  
5. Later: poll worker writes readings; place sensors on floor/3D  

**Out of scope (unless asked later)**

- Full BMS / BACnet / Modbus gateways  
- CFD / thermal twin modeling  
- Automatic cooling control (setpoints / BMS write)  
- ASHRAE envelope compliance engine (reports can come later)

**Acceptance**

- [x] Can define temp (+ humidity) points and see last value + recent history (manual readings)  
- [x] Can inventory cooling units and associate them with rooms; active/standby without zones  
- [ ] SNMP poll path works for at least one probe template end-to-end  
- [ ] Threshold alert (e.g. high temp) can notify via existing mail settings  
- [x] Power features remain unaffected  

---

### 5. NOC-View dashboard (live metrics, no full page refresh)

| Field | Value |
|-------|--------|
| **Status** | **done** (pages/noc.php + api/noc.php; optional token; spinning 3D) |
| **Requested** | 2026-07-29 (user); refined 2026-08-03 (public TV / no auth timeout) |
| **Source** | `BACKLOG.md` (chat request after 3D/dashboard perf work) |
| **Priority** | nice-to-have (ops / wall display) |
| **Delivered** | Public NOC wall; JSON poll; auto-rotate 3D top-left; Settings → NOC |

**Goal:** A **NOC-style dashboard** that shows key site metrics and keeps them **auto-updating without a full page refresh** (suitable for a wall monitor or always-on ops tab).

**Desired UX (v1 sketch — confirm when implementing)**

1. **Dedicated view** (e.g. Dashboard → NOC / full-screen friendly layout): large typography, dark “ops wall” density, minimal chrome optional.  
2. **Metrics** (start from what we already have; expand as data allows): device/cabinet counts, U utilization, PDU load / kW, recent audits or open alerts, optional mini floor/3D or top hot cabinets — confirm set at implement time.  
3. **Live updates:** poll JSON APIs (or SSE later) on a timer (e.g. 15–60s); update numbers/charts in place — **no** full reload of the page or re-bootstrap of heavy 3D unless the user opts in.  
4. **Resilience:** show last-updated timestamp; degrade gracefully if a poll fails; pause when the tab is hidden (Page Visibility) to save load.

**Implementer notes**

- Prefer thin **read APIs** (reuse / extend `api/dashboard.php`, power history snapshots, alert digests) over scraping HTML.  
- Keep 3D optional or static snapshot on NOC wall — continuous Three.js rebuild is expensive (see 0.2.93 session face cache).  
- Auth: same session cookie; long-lived wall displays may need a kiosk/read-only role or token later (out of scope unless asked).  
- Do not block normal interactive dashboard; NOC can be a separate route/page.

**Out of scope (unless asked later)**

- True websockets / multi-user collaborative cursors  
- External TV signage players / proprietary NOC appliances  
- Auto-logout disable for kiosks without a dedicated kiosk account design  

**Acceptance (when built)**

- [x] NOC (or equivalent) page loads and shows core metrics  
- [x] Metrics refresh on an interval without full page navigation/reload  
- [x] Last-updated time visible; failed poll does not blank the whole UI  
- [x] Hidden tab does not hammer the server (pause or slow poll)  
- [x] Existing home dashboard still works for day-to-day use  
- [x] No login / session timeout for TV wall (optional access token)  
- [x] Spinning 3D floor overview (top-left)  

---

### 6. Cabinet QR codes (labels / plaques → cabinet page)

| Field | Value |
|-------|--------|
| **Status** | open (shared `QrCodeService` landed with PDU labels; cabinets not yet wired) |
| **Requested** | 2026-07-29 (user) |
| **Source** | `BACKLOG.md` (chat request) |
| **Priority** | nice-to-have (field ops / audits) |
| **Related** | PDU ID labels (print/SVG + QR) reuse `QrCodeService` / `LabelLayoutService` |

**Goal:** Generate **QR codes per cabinet** for printing labels or laser-engraving plaques. Scanning a code should open that cabinet’s page in ColdAisle for audits or quick device reference.

**Desired UX (v1 sketch — confirm when implementing)**

1. **Encode a stable deep link** to the cabinet detail page (e.g. `https://{host}/pages/cabinets.php?id={cabinet_id}`), optionally with a short token or path alias if IDs should not appear raw.  
2. **Per-cabinet QR** on the cabinet page (download PNG/SVG; print-friendly size).  
3. **Bulk export** (optional v1.1): sheet of QRs + cabinet names for label runs / plaque shops.  
4. **Scan path:** phone camera / scanner opens the URL in a browser → login if needed → cabinet view (devices, audits).  

**Implementer notes**

- Prefer **absolute HTTPS URLs** using configured `base_url` so engraved plaques still work after DNS changes only if hostname stays valid — document that plaques should use the production FQDN.  
- Client-side QR library or server-side generation (PHP) both fine; SVG is best for laser engraving.  
- **Mobile without a native app:** modern phone cameras open HTTPS URLs in the browser; ColdAisle is a web app, so this **is viable without a store app** if:  
  - the site is reachable from the scan network (corp Wi‑Fi / VPN), and  
  - auth works on mobile browsers (session / Entra).  
- PWA “Add to Home Screen” can improve the field feel later; not required for QR → cabinet.  
- Audits: deep-link can land on cabinet detail with audit history / log-audit control already present.

**Out of scope (unless asked later)**

- Native iOS/Android app with in-app camera scanner  
- QR on every device (cabinet-only unless requested)  
- Offline scan queue when the phone has no network  

**Acceptance (when built)**

- [ ] Each cabinet can show/download a QR that encodes its ColdAisle URL  
- [ ] Opening that URL (authenticated) lands on the correct cabinet page  
- [ ] Printable / engraving-friendly export (at least PNG or SVG)  
- [ ] Document network/auth expectations for field scanning (no native app required for basic flow)  

---

### 7. Global temperature unit (°C / °F)

| Field | Value |
|-------|--------|
| **Status** | **done** (ship with next release; TempUnitService + Settings → General) |
| **Requested** | 2026-08-03 (user) |
| **Source** | chat (after env sensor refinements 0.3.33; no F/C setting today) |
| **Priority** | product polish (site ops preference) |
| **Delivered** | Site-wide °C/°F; store °C; convert UI/API/alerts/setpoints; humidity unchanged |

**Goal:** Let the **site** choose **Celsius or Fahrenheit globally** so all env/cooling temperature UI (and related thresholds/charts) matches local practice — not a per-sensor free-text label.

**Desired UX (v1 sketch — confirm when implementing)**

1. **Settings** (General or a small “Display / units” card): **Temperature unit** = Celsius (°C) or Fahrenheit (°F). Site-wide only (not per user) unless asked later.  
2. **Display conversion** everywhere temps are shown: env sensors list/detail, history charts, 3D heat spheres scale labels if any, cooling setpoints, ASHRAE band copy (or show dual/native carefully), alert email bodies.  
3. **Storage convention (pick one at implement time):**  
   - **Prefer store canonical °C** (and convert on read for display + on write when user enters °F), **or**  
   - store as entered and tag unit (harder for charts/thresholds — avoid unless needed).  
4. **Thresholds:** warn/crit fields follow the selected unit in the UI; convert when saving if storage is °C.  
5. **SNMP poll:** device may report °C or °F (e.g. APC PowerNet system scale). Normalize into the storage convention so a site set to °F does not double-convert or mislabel.  
6. **Default:** °C (current behavior) for existing installs.

**Implementer notes**

- Single setting key (e.g. `temp_unit` = `C` \| `F`) via `SettingsService`; read once per request helper like `App::tempUnit()` / `formatTemp($c)`.  
- Do not invent a second unit system for humidity (%RH stays).  
- Floor-plan metric/imperial (length) stays separate from temperature unit.  
- Migrating existing `env_sensors.unit` strings (`°C`, `°C / %RH`) — update defaults when kind changes; do not require bulk rewrite of free-text unit if display is driven by global setting.

**Out of scope (unless asked later)**

- Per-user temperature preference  
- Kelvin / Rankine  
- Auto-detect from browser locale  

**Acceptance (when built)**

- [x] Admin can set site temperature unit to °C or °F in Settings  
- [x] Env sensor values, charts, and manual entry honor that unit  
- [x] Thresholds and alert emails use the same unit consistently  
- [x] SNMP-ingested temps stored as °C (assumed poll scale °C); display converts only — no double-convert on write path  
- [x] Existing sites default to °C with no behavior change until changed  

---

### 8. Vertiv / Liebert DS cooling SNMP (LGP condition maps + expanded telemetry)

| Field | Value |
|-------|--------|
| **Status** | **blocked / parked** — revisit after Vertiv Monitoring Support reply |
| **Requested** | 2026-08-05…08-06 (user + Vertiv field / monitoring engagement) |
| **Source** | chat (Liebert DS only showing uptime; Vertiv rep + `AC_Vertiv_Thermal/` templates; ticket to Monitoring.Support) |
| **Priority** | high for site cooling ops once agent access is fixed |
| **Do not implement until** | (1) user confirms Monitoring Support unblocked `.3` GETs **or** explicitly asks to import templates for dry-run/probe-only, and (2) scope confirmed |

**Goal:** Poll live Liebert **DS** thermal metrics into ColdAisle (supply/return temp, humidity, system state, capacity, alarms—not only sysUpTime / product identity), using **official Vertiv NMS OID maps**, then expand to related product templates in the pack.

**Diagnosis already established (do not re-litigate without new evidence)**

| Observation | Detail |
|-------------|--------|
| Agent reachable | SNMPv3/v2c path works for MIB-2 + identity |
| Identity works | `1.3.6.1.4.1.476.1.42.2` → Vertiv, **IS-UNITY-ICOM2**, fw ≈ **v8.0.0.1-4.4.7** |
| sysObjectID | `1.3.6.1.4.1.476.1.42` |
| Condition tree empty | Walks/GETs under **`1.3.6.1.4.1.476.1.42.3`** return empty / timeout (likely **VACM / view ACL**, context, or feature enablement on Unity—not missing OIDs in the app) |
| Discover limitation | ColdAisle cooling Discover preferred other LGP leaf shapes (e.g. community IDs 5002/4291); Vertiv DS templates use **`…3.4.1.2.3.1.3.n`** present-value style |

**External dependency (ticket)**

- **Vertiv Software & Management Card Technical Support**  
  - Phone: `800.222.5877` option **2 + 2**  
  - Email: `Monitoring.Support@Vertiv.com`  
- Ask: enable **read** access to LGP condition OIDs; confirm views, context, firmware, and validation snmpgets.  
- **Acceptance for unblocking:** positive GET of at least  
  - Return Temp `1.3.6.1.4.1.476.1.42.3.4.1.2.3.1.3.3`  
  - System State `1.3.6.1.4.1.476.1.42.3.4.3.1.0`

**Vendor assets on disk (do not delete)**

Folder: **`AC_Vertiv_Thermal/`** (Vertiv technician contribution — NMS device templates, `templateVersion` 6, SNMP).

| File | Model | Approx points | Use |
|------|--------|---------------|-----|
| `AC_Vertiv_DS_json.txt` | **DS** | ~106 | **Primary** for site Liebert DS units |
| `AC_Vertiv_DS_wControl.json` | DS | ~91 | Same core + **writable Unit Control** OID (defer writes) |
| `AC_Vertiv_CRAC_Intellislot.json` | Generic CRAC | ~231 | Broader IntelliSlot library |
| `AC_Vertiv_CRV.json` / `CRV4` / `Unity_CRV_300_InRowCooler` | CRV family | ~91–133 | In-row |
| `AC_Vertiv_CRD010.json` | CRD | ~104 | |
| `AC_Vertiv_DataMate.json` | DataMate | ~41 | |
| `AC_Vertiv_Chiller_XDC_Intellislot.json` / `AC_Emerson_Liebert_Chiller_EFC300.json` | Chillers | ~83–88 | Plant |

**Key DS OIDs (from Vertiv template — map into site cooling OID template)**

| Metric | OID |
|--------|-----|
| Alarms Present | `1.3.6.1.4.1.476.1.42.3.2.2.0` |
| Control Temp | `…3.4.1.2.3.1.3.1` |
| Supply Temp | `…3.4.1.2.3.1.3.2` |
| Return Temp | `…3.4.1.2.3.1.3.3` |
| Supply / Return temp setpoints | `…3.4.1.2.3.1.6.2` / `.6.3` |
| Control / Return humidity | `…3.4.2.2.3.1.3.1` / `.3.2` |
| System State (On/Off/Standby) | `…3.4.3.1.0` |
| Cooling / Fan / Humidify / Dehumidify | `…3.4.3.2` … `.7` |
| Cooling / fan capacity % | `…3.4.3.9` / `.16` |
| Run hours (comp, fan, reheat, free cool…) | `…3.4.6.*` |
| Remote sensors 1–10 (×0.1 °F style) | `…3.9.20.1.20.1.2.1.5059.n` |
| Unit Control (writable, **opt-in later**) | `…3.7.4.2.0` (`DS_wControl` only) |

Notes from templates: many temps labeled **FAHRENHEIT**; some setpoints/remotes use `LINEAR` scale (e.g. 0.1); alarms often `SNMP_GET_TABLE` under `…3.2…` (not simple leaf GET).

**Implementation slices (when unblocked)**

1. **Probe-first validation** — hard-coded GET of Return Temp + System State (and a few more) on unit 1; fail clearly if still noSuchObject.  
2. **Built-in / site OID template “Vertiv Liebert DS”** — core map: `return_temp`, `supply_temp`, humidity, system_state, setpoints, capacity, alarms_present; honor scale + store °C via existing `TempUnitService` rules.  
3. **Cooling Discover** — prefer DS present-value OIDs / import path from Vertiv JSON instead of only 5002/4291 heuristics.  
4. **Poll snapshot UI** — unit detail + NOC cooling panel: state, supply/return, RH, capacity %, alarms present.  
5. **Expand (later)** — discrete alarm booleans (table semantics), remote sensors → optional env points, run hours.  
6. **Template importer** — convert other `AC_Vertiv_Thermal` JSON (CRV, CRAC, chillers) into site templates when those assets exist.  
7. **Do not enable Unit Control SET** unless explicitly requested (safety).

**Out of scope unless asked**

- Automatic setpoint / On-Off control from ColdAisle  
- Replacing Vertiv’s own NMS  
- Full LGP MIB dump packaging (use templates + targeted GETs)

**Acceptance (when built)**

- [ ] With a correctly configured Unity view, **Poll now** on a DS unit returns supply/return temp (and humidity or system state) into `last_poll_json` / cooling UI  
- [ ] Site can attach a **Vertiv DS** OID template without hand-typing OIDs  
- [ ] Values respect site °C/°F display; no double conversion  
- [ ] NOC / cooling views show more than uptime once data is present  
- [ ] Writable control OIDs remain unused by default  

**Revisit trigger:** reply from `Monitoring.Support@Vertiv.com` (or successful GET of Return Temp / System State on site credentials).

---

### 9. 3D airflow particles (vents → cold aisle → hot aisle → AC return)

| Field | Value |
|-------|--------|
| **Status** | **open** (design noted; not started) |
| **Requested** | 2026-08-06 (user) |
| **Source** | chat (NOC/3D cooling visualization idea) |
| **Priority** | visual / ops storytelling (depends on placement + temp data) |
| **Do not implement until** | explicitly requested; confirm v1 scope (anchors + particles only vs full CFD-like paths) |

**Goal:** In the 3D room view, show **air particles** that originate at **ceiling supply vents**, travel through the **cold aisle**, across/through cabinets (front → rear), and return via the **hot aisle** to **AC return** anchors. Particle **color** reflects live/last temperatures from the sensors along that path. Effect is **toggleable** (dashboard / floor-plan 3D / NOC).

**Why this is compelling**

- Makes cold/hot aisle strategy visible on the wall display without CFD.  
- Reuses existing heat-sphere temp pipeline + cooling supply/return when SNMP (#8) lands.  
- Complements solid AC units and heat spheres rather than replacing them.

**Prerequisites / building blocks we already have**

- Floor plan + 3D: cabinets, floor PDUs, cooling units, env heat spheres (`EnvSensor3dData`, `heatOverlay` toggle).  
- Cabinets have `front_facing` / rotation; sensors can host on cabinet front/rear-ish placement.  
- Site temp unit (°C/°F) for legend labels.  
- Cooling units placed with footprint (supply/return *equipment* anchors can link to a unit).

**Missing pieces to design in**

1. **Airflow anchors** (new placeable objects on floor plan / ceiling plane)  
   - Types: `supply_vent` (ceiling), `return` (ceiling or upper wall / unit face), maybe later `raised_floor_tile`.  
   - Fields: `room_id`, `pos_x`, `pos_y`, `pos_z` (ceiling height default from room), size/orientation optional, optional `cooling_unit_id` link, label, color.  
   - Palette + place/nudge/lock like cooling units; show on 2D plan as vent symbols.  
2. **Flow topology (v1 — simple, not CFD)**  
   - Explicit **flow paths** or auto-heuristic: each supply → nearest cold-aisle midline → cabinet fronts → cabinet rears → hot-aisle midline → linked/nearest return.  
   - Prefer **user-authored paths** (ordered list of anchors + optional waypoints) for predictability; auto-suggest as assist.  
   - Row / aisle side: use cabinet `front_facing` + row geometry so cold = intake side, hot = exhaust side.  
3. **Particle system (Three.js)**  
   - Lightweight `THREE.Points` or small sprites; pool size cap (e.g. 200–800 total) for TV/NOC perf.  
   - Spawn at supply vents; lerp along path segments; recycle at return.  
   - Speed modest; optional slight turbulence.  
   - Toggle: `airflowOverlay` next to heat spheres (dashboard, floor plan 3D, NOC). Default **off** (perf + clutter).  
4. **Temperature → color**  
   - Sample temps along path:  
     - supply / output air (cooling unit poll or vent-linked sensor)  
     - cabinet **front** thermal sensors  
     - cabinet **rear** thermal sensors  
     - return air (cooling unit / return-linked sensor)  
   - Color map aligned with existing heat-sphere scale (cool blue → green → yellow → red); store °C, display legend in site unit.  
   - Missing temps: neutral gray / dim particles for that segment.  
5. **NOC**  
   - Same toggle (or inherit “show airflow”); keep auto-rotate usable with particles.

**Suggested delivery slices**

| Slice | Deliverable |
|-------|-------------|
| **A — Anchors** | Schema + floor plan palette for supply vents & returns; place/move/unplace; optional link to cooling unit |
| **B — 3D markers** | Render vents/returns in 3D (ceiling discs / return grilles) without particles |
| **C — Paths** | Define simple path (ordered anchors or auto path from vent→return via aisle midlines); visualize path as thin ribbon optional |
| **D — Particles + toggle** | Particle animation along paths; color from nearest temp samples; on/off control |
| **E — Polish** | Density/speed settings, NOC default, legend, performance caps |

**Dependencies**

- **Useful without #8:** cabinet front/rear env sensors alone still color mid-path particles.  
- **Richer with #8:** supply/return air temps from Liebert DS poll color vent→aisle and hot→return segments.  
- Heat spheres stay; particles are additive storytelling, not a thermal model of record.

**Out of scope (unless asked later)**

- Real CFD / Fluent / pressure fields  
- Smoke tests / containment curtain physics  
- Particle occlusion / GPU compute fluid  
- Controlling CRACs from particle UI  

**Acceptance (when built)**

- [ ] Can place ceiling **supply vents** and **returns** on the floor plan and see them in 3D  
- [ ] Can turn **airflow particles** on/off in 3D without breaking heat spheres or faceplates  
- [ ] Particles move supply → cold aisle → hot aisle → return along a defined or auto path  
- [ ] Particle color changes with available front/rear cabinet temps and supply/return temps when present  
- [ ] Performance acceptable on dashboard and NOC (capped particle count; default off)  

**Open design questions (resolve at implement time)**

- Auto path vs only user-drawn paths for v1?  
- One global “aisle direction” per room vs per row?  
- Ceiling height: room setting vs fixed default (e.g. 3.0 m)?  
- Should returns default to top of linked CRAC footprint when unit is placed?

---

## Completed (keep for audit; do not re-implement)

### Global temperature unit (°C / °F)

| Field | Value |
|-------|--------|
| **Status** | **done** |
| **Source** | backlog item #7 |
| **Delivered** | `TempUnitService`; Settings → General; env sensors/history/charts/alerts; cooling setpoints; ASHRAE display |

---

### Site backup to SMB share

| Field | Value |
|-------|--------|
| **Status** | **done** (release **0.3.3**) |
| **Source** | backlog item #3 |
| **Delivered** | UNC target; auth: app pool / local Windows / domain (AD); sealed password; copy after site export & optional pre-update ZIP; test connection |

---

### Schema status / hardening pass

| Field | Value |
|-------|--------|
| **Status** | **done** (release **0.3.0**) |
| **Source** | backlog item #2 |
| **Delivered** | `Schema::status()` inventory check; Settings → Schema health; ensure JSONL log + stamp metadata; force ensure; `runIdempotentReshapes()` |

---

### Active sessions / presence

| Field | Value |
|-------|--------|
| **Status** | **done** (release **0.2.99**) |
| **Source** | backlog item #1 |
| **Delivered** | Presence via `auth_sessions` (login + throttled heartbeat); Online badge on Users & Departments; Settings → Updates apply warning lists active users (not a hard block) |

---

### Update / backup housekeeping

| Field | Value |
|-------|--------|
| **Status** | **done** (releases **0.2.65–0.2.67**) |
| **Source** | commit `bbb8a3b` (`UpdateService.php` BACKLOG note) |
| **Delivered** | `StorageHousekeepingService`, Settings → Storage housekeeping, prune after update/export + SNMP worker, keep-N / max-age, list/delete, recovery backup button; keep-count fix for recent files |

Original note covered: retention for pre-update + site-export zips, Settings UI / prune after update, safety floor (newest kept), list/delete, no nested backup-of-backup. **All addressed.**

---

## Suggested in chat (not formal backlog)

These appeared in “what’s next?” / optional lists. **Not user-requested backlog entries** unless promoted. Listed so they are not lost in compaction memory.

| Idea | Context | Likely status |
|------|---------|----------------|
| Alerts on phase/device load | “what’s next” #1 | **Done** (PowerAlertService digests) |
| Per-outlet live view | “what’s next” #2 | **Done** (0.2.42+; phase-metered AP8861 has no per-outlet SNMP) |
| Short power history / charts / reports | “what’s next” #3 | **Done** (history phases 1–4) |
| Discover polish | “what’s next” #4 | **Done** (user selected) |
| Zone/dashboard phase **imbalance** flag | “what’s next” #5 | **Open suggestion** only |
| Vendor **template library** growth (CyberPower / Raritan / Vertiv) | “what’s next” #6 | **Open suggestion** (partial: site OID templates + PDU templates exist for APC path) |
| Ops: last-poll age, bulk poll zone | “what’s next” #7 | **Partial** (scheduler / poll worker shipped; “poll all in zone” UI may still be thin) |
| SQL login least-privilege | README checklist + post-schedule optional | **Ops doc** (not app feature); still valid for PROD hardening |
| Fleet template ops | Post-housekeeping optional | **Partial** (PDU templates 0.2.45); “fleet apply” breadth unclear—confirm before building |
| Mail: disposal / welcome / forgot-password | After MailService | **Open suggestion** (SMTP/test exists; flows not built) |
| Bulk import UI (openDCIM migration) | README | **Open suggestion** |
| Full outlet remote control (on/off) | Explicitly “not next” | Out of scope unless requested |
| Long-term time-series DB | Explicitly “not next” | Out of scope unless requested |

---

## Validation rules (for maintainers)

1. **Formal open backlog** = only sections under **Open** above (plus any new entries the user asks to add).  
2. **Inline `BACKLOG` comments** in PHP should either match this file or be replaced with a one-line pointer here.  
3. Chat suggestions do **not** become open backlog until the user says so (or a `Note: backlog` / `BACKLOG.md` edit is made).  
4. When an item ships, move it to **Completed** with release version(s)—do not delete history.
