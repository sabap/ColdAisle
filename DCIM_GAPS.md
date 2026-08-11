# ColdAisle — DCIM feature gap list

**Purpose:** Reference comparison of ColdAisle against typical commercial and open-source DCIM offerings (openDCIM, Sunbird dcTrack, Nlyte, Device42, Schneider EcoStruxure IT, Vertiv Trellis-class, NetBox for network-heavy sites, etc.).

**Not an implementation queue.**  
- **Implementable work the user has accepted** lives in [`BACKLOG.md`](BACKLOG.md).  
- This file tracks **product / competitive gaps** so chat menus and compaction do not lose the map.  
- Promote a gap into `BACKLOG.md` only when scope is confirmed and work is intentionally deferred.

**Last reviewed:** 2026-08-11 (post **0.3.110** — capacity/phase G-A2; power path G-A1).

---

## How to use this file

| Role | Rule |
|------|------|
| **Operators / product** | Scan tiers A–C for “what others sell that we thin or lack.” |
| **Implementers** | Do **not** start work from this file alone. Confirm with the user; if accepted, add or update `BACKLOG.md`, then implement. |
| **Maintainers** | When a gap ships, move it to **Closed gaps** with release version. When a gap becomes formal backlog, link the backlog item number. |

### Status legend

| Status | Meaning |
|--------|---------|
| **Strong** | Competitive for our target (IIS / SQL Server / mid-size DC ops) |
| **Partial** | Inventory or foundation exists; depth or polish lag peers |
| **Gap** | Missing or only a stub relative to typical DCIM |
| **Out of scope** | Explicitly deferred unless requested (safety, plant bus, CFD, …) |
| **Blocked** | Waiting on external dependency (vendor, site visit) |
| **Backlog** | Formal item in `BACKLOG.md` |

---

## 1. Already strong (do not treat as gaps)

| Area | Notes | Approx since |
|------|--------|--------------|
| Rack inventory, elevations, templates, chassis children | U layout, conflict checks, openDCIM-style slots | Core |
| Floor plan + 3D (Three.js) | Cabinets, row PDUs, cooling footprints, heat spheres | Core + cooling |
| NOC wall | Live metrics, optional token, spinning 3D | 0.3.x |
| Power zones, row/rack PDUs, outlets, multi-phase | Templates, Discover/Poll, history charts, facility rollup modes | Power arc |
| SNMP v2c/v3, MIBs, site OID templates, scheduler | PDU, device, UPS; scheduled inventory list includes UPS/cooling | Through 0.3.108 |
| UPS inventory + load/battery history charts | PowerNet UPS path; hold/carry-forward series | UPS arc |
| Auth | Local, LDAPS, Entra ID; RBAC roles | Core |
| Updates, site backup/restore, SMB copy, storage housekeeping | Settings-driven | 0.2–0.3 |
| Cabinet QR + field audit mode | Labels, sheets, `?field=1` / `?audit=1` | **0.3.105** |
| Cooling unit inventory + env sensors (manual) | Active/standby pairs, ASHRAE guidance copy | **0.3.5** foundation |
| Cabling (port–port), disposals, audits, reports | Depth varies — see Partial below | Core |
| Facility load without rack+row double-count | prefer_upstream / manual / sum all | **0.3.101** |
| PDU list split (row vs cabinet) | Power → PDUs | **0.3.104** |

---

## 2. Formal backlog (tracked in BACKLOG.md)

These are **not** free-form suggestions; they have acceptance criteria in the backlog.

| Gap ID | Topic | BACKLOG | Status | Notes |
|--------|--------|---------|--------|--------|
| G-B4 | Cooling / env SNMP E2E, threshold mail, history charts | **#4** | Partial | Foundation shipped; poll path, AP9340 map, alerts, charts still open |
| G-B8 | Vertiv / Liebert DS LGP thermal SNMP | **#8** | **Blocked ~2026-08-18** | Unity VACM / firmware; templates in `AC_Vertiv_Thermal/` |
| G-B9 | 3D airflow particles (vents → aisle → return) | **#9** | Open | Visual; depends on anchors + temp samples |
| G-B10 | PDU Locate (LCD / network LED blink) | **#10** | Parked | Phase 0 lab required; no safe public blink OID documented |

---

## 3. Competitive gaps (tiers)

### Tier A — High value for power-heavy mid-size sites

| ID | Gap | Status | vs peers | ColdAisle today | Suggested slice |
|----|-----|--------|----------|-----------------|-----------------|
| **G-A1** | **End-to-end power path report** | **Closed (v1)** — **0.3.109** | dcTrack, Nlyte, openDCIM power path style | Report + dashboard card; soft UPS by zone; no SVG one-line | Optional later: hard UPS/panel FKs, diagram |
| **G-A2** | **Capacity planning / phase imbalance** | **Closed (v1)** — **0.3.110** | “Where can I place N kW?” tools | Free kW/U, phase amps, 20% imbalance badge, Fits? filter | Reserved/planned capacity bookings still out of scope |
| **G-A3** | **Cooling live telemetry + env alerts** | Partial + **G-B8** | Trellis / EcoStruxure thermal panels | Unit inventory; SNMP often identity-only on Liebert until unblocked | After #8: supply/return/RH/state on unit + NOC; env threshold digests (can start without Vertiv via AP9340) |
| **G-A4** | **Vendor OID / device template library** | Partial | Fleet “pick model → done” | APC/Schneider path mature; Discover rulesets; site templates | Curated packs: more UPS, CyberPower, Raritan, Vertiv cooling JSON import |
| **G-A5** | **Bulk / zone SNMP ops surface** | Partial | Enterprise “stale poll” / bulk actions | Worker + scheduled list; thin bulk UI | Last-poll age on lists; poll all in zone; stale badge on dashboard/NOC |

### Tier B — Classic mid-tier DCIM

| ID | Gap | Status | vs peers | ColdAisle today | Suggested slice |
|----|-----|--------|----------|-----------------|-----------------|
| **G-B1** | **Structured cable plant** | Partial | Patch panels, trunks, pathways | Port–port cables, media, simple routes | Patch panels, trunk bundles, circuit IDs, bulk CSV import |
| **G-B2** | **Change / move work orders** | Gap | ITSM-linked moves, approvals | Audits + disposals; no work-order lifecycle | Planned move with ticket ID, from/to cabinet, checklist (optional ITSM later) |
| **G-B3** | **Asset lifecycle depth** | Partial | PO/RMA/warranty campaigns | Serial, asset tag, warranty fields, disposal workflow | Warranty mail digests; owner/dept workflows; chain-of-custody notes |
| **G-B4-net** | **IPAM / network-DCIM bridge** | Gap (often intentional) | Device42, NetBox | Infra-first; interfaces on devices only | Out of default scope unless multi-homed switch inventory is requested |
| **G-B5** | **Mail product flows** | Partial | Welcome, password reset, lifecycle mail | SMTP + test + power/env alert digests (where built) | Welcome user, forgot-password, disposal reminders |

### Tier C — Low priority / out of scope unless asked

| ID | Gap | Status | Why deferred |
|----|-----|--------|--------------|
| **G-C1** | Outlet remote control (on/off/reboot) | Out of scope | Safety-critical SETs; audit + allowlist required |
| **G-C2** | PDU Locate blink | Backlog **#10** | Research parked; Phase 0 on live gear first |
| **G-C3** | BMS / BACnet / Modbus plant bus | Out of scope | Plant integration product class |
| **G-C4** | Full CFD / thermal twin | Out of scope | Storytelling via **#9** only unless CFD is requested |
| **G-C5** | Multi-site / multi-tenant enterprise | Gap | Single-site focus today |
| **G-C6** | Native iOS/Android app | Out of scope | QR + field mode cover browser field ops |
| **G-C7** | Long-term TSDB (Influx/Prometheus) | Out of scope | SQL history OK until retention becomes a problem |
| **G-C8** | Auto cooling control (setpoint write) | Out of scope | Safety; Vertiv Unit Control OID deferred even when read works |

---

## 4. Suggested build order (while Vertiv parked)

Use as a **discussion default**, not a commitment.

| Order | ID | Work | Why now |
|-------|-----|------|---------|
| 1 | G-A1 | Power path report (read-only) | **Done 0.3.109** |
| 2 | G-A2 | Phase imbalance / free capacity hints | **Done 0.3.110** |
| 3 | G-A3 (env slice) | Env threshold mail + history charts | **Next** |
| 4 | G-A5 | Stale poll / last-poll age | Cheap reliability win |
| 5 | G-B8 → G-A3 | Vertiv DS template + poll after ~2026-08-18 | Largest cooling unlock |
| 6 | G-B9 A or G-B1 | Airflow anchors **or** cable plant depth | Ops preference |

---

## 5. Closed gaps (shipped; keep for audit)

| Former gap | Delivered | Release / notes |
|------------|-----------|-----------------|
| **G-A2 Capacity / phase imbalance** | Free kW & U per zone; phase amps + imbalance ≥20%; Fits? report filter; dashboard headroom | **0.3.110** |
| **G-A1 Power path report** | `PowerPathService`; Reports → Power Path; unmapped / single-feed / half-map / no row feed; Power dashboard card | **0.3.109** |
| Cabinet QR labels / plaques → cabinet page | QR preview, SVG/PNG/print, bulk sheet, field + audit polish | **0.3.105** (`BACKLOG` #6) |
| UPS “No data yet” / single-blip charts | `snmp_last_poll_at`; hold ~90m + carry-forward | **0.3.106–0.3.107** |
| UPS missing from SNMP scheduled list | List UPS + cooling with toggle on; Incomplete if no template/IP | **0.3.108** |
| Facility load double-count (rack under row) | Rollup modes: sum all / prefer upstream / manual | **0.3.101** |
| Row vs cabinet PDU inventory UX | Split tables on Power → PDUs | **0.3.104** |
| NOC live wall without full reload | `pages/noc.php` + API + optional token | **BACKLOG** #5 done |
| Site backup to SMB | `SmbBackupService` | **0.3.3** |
| Active sessions / presence | Users Online + update warning | **0.2.99** |
| Global °C / °F | `TempUnitService` | **BACKLOG** #7 done |
| Cooling inventory foundation | Units + env sensors + floor place | **0.3.5** (SNMP depth still open) |

---

## 6. Relationship to other docs

| Doc | Role |
|-----|------|
| [`BACKLOG.md`](BACKLOG.md) | **Only** formal deferred implementation items with acceptance criteria |
| [`CHANGELOG.md`](CHANGELOG.md) | What shipped (user-facing) |
| [`README.md`](README.md) | Product overview for installers |
| **This file** | Competitive / product gap map and closed-gap memory |

### Chat → formal rule

1. Competitive “what’s missing?” discussion → update **this file**.  
2. User says “build X” or “put X on backlog” → **BACKLOG.md** (+ optional status change here to **Backlog** + link).  
3. X ships → **CHANGELOG**, move backlog item to Completed, move gap row to **Closed gaps**.

---

## 7. Revision history

| Date | Change |
|------|--------|
| 2026-08-11 | Initial formal gap list (tiers A–C, backlog links, closed gaps through 0.3.108) |
| 2026-08-11 | G-A1 closed in **0.3.109** (power path report); Tier A program in progress |
| 2026-08-11 | G-A2 closed in **0.3.110** (capacity / phase imbalance / Fits?) |
