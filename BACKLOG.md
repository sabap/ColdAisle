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
| **Status** | open |
| **Requested** | 2026-07-26 (user) |
| **Source** | `BACKLOG.md` / commit `dfdeb03` |
| **Priority** | nice-to-have (ops UX) |

**Goal:** Show who is signed in; warn before in-app updates while others are active.

**UX**

1. **Users & Departments** — badge/tag next to users with an active session (“Online” / “Active”).  
2. **Settings → Updates → Apply** — warning such as:  
   *“N users are currently logged in (alice, bob, …). Applying this update may interrupt them.”*  
   Warning only (not a hard block) unless a later “strict mode” is requested.

**Implementer notes**

- `auth_sessions` exists in `sql/schema.sql` but may not be fully wired (PHP native sessions).  
- Likely need login registry + `last_seen` heartbeat, idle cleanup aligned with session security settings, helper e.g. `AuthManager::activeUsers()`.  
- No multi-node session store unless PROD requires it.

**Out of scope (unless asked later):** websockets, force-logout on update, per-page “who is viewing”, mobile push.

**Acceptance**

- [ ] Online users visible on Users & Departments (≤ ~1–2 min staleness OK)  
- [ ] Update apply shows count + sample usernames when sessions are active  
- [ ] Expired sessions not shown as active  
- [ ] Local / LDAPS / Entra accounts behave the same  

---

### 2. Schema status / hardening pass

| Field | Value |
|-------|--------|
| **Status** | open |
| **Requested** | 2026-07-24 (agent note after long upgrades; user acknowledged “note hardening”) |
| **Source** | commit `dbae6e0`, `src/Schema.php` header (kept in sync with this file) |
| **Priority** | ops / support |

**Items**

- Schema status / health UI: app `VERSION`, last ensure OK, expected tables & columns vs live DB (verify after multi-version jumps).  
- Optional `schema_version` / ensure-run log for ops visibility.  
- Explicit idempotent alters for rare reshape/backfill (not only `ADD` column/table).

**Context:** Additive `ensureColumn` / `ensureTable` already converges most installs; this is about **visibility and rare reshape**, not rewriting the ensure model.

---

### 3. Site backup to SMB share

| Field | Value |
|-------|--------|
| **Status** | open |
| **Requested** | 2026-07-27 (user) |
| **Source** | `BACKLOG.md` |
| **Priority** | nice-to-have (ops / DR) |

**Goal:** Copy (or write) site backup packages to a network **SMB** share, not only local `storage/backups/` + browser download.

**Desired UX (v1 sketch — confirm when implementing)**

1. **Settings → Site backup & migration** (or Storage housekeeping): configure UNC path (e.g. `\\fileserver\backups\ColdAisle`), optional domain credentials, and whether remote copy runs on manual export and/or after successful pre-update backup.  
2. **Run / export** still builds the existing site package (or pre-update zip); then copy to the share.  
3. Clear success/failure message (path + error if access denied / offline).  
4. Optional: list recent remote copies or last successful remote write timestamp.

**Implementer notes**

- Reuse `SiteBackupService` / existing zip layout; prefer “build local then copy” over writing the zip stream straight to UNC (easier retry + housekeeping).  
- Windows: UNC via PHP/`copy` if the IIS app pool (or scheduled worker identity) has share permissions; otherwise document service account / credential store carefully (never put share passwords in the downloadable backup package).  
- Align retention with `StorageHousekeepingService` (local keep-N); remote retention may be share-admin policy or a simple “keep last N on share” option.  
- Web app must not require interactive elevation; credential prompts at save-settings time only if needed.  
- User wrote “SBM share” — treat as **SMB** (Windows file share / CIFS).

**Out of scope (unless asked later)**

- Non-SMB targets (S3, Azure Blob, SFTP)  
- Full image / bare-metal backup of the IIS host  
- Continuous replication / always-on sync  

**Acceptance (when built)**

- [ ] Admin can set SMB UNC (and credentials if required) and save securely  
- [ ] Manual site backup can land on the share when configured  
- [ ] Failure is visible (permissions, path missing) without corrupting local backup  
- [ ] Works under typical IIS app-pool / SYSTEM poll identities with documented NTFS+share ACLs  

---

### 4. Cooling & environmental monitoring

| Field | Value |
|-------|--------|
| **Status** | open |
| **Requested** | 2026-07-29 (user) |
| **Source** | `BACKLOG.md` |
| **Priority** | product expansion (post power/SNMP foundation) |

**Goal:** Track cooling plant and room environment alongside power — so ColdAisle covers thermal/air as well as electrical load.

**Desired capabilities (v1 sketch — confirm scope when implementing)**

1. **Environment sensors / points**  
   - Temperature, humidity (and optionally dew point, differential pressure, airflow)  
   - Placement: site / DC / room / row / rack (or free “zone”)  
   - Status from last poll + short history charts (reuse power-history patterns where practical)

2. **Cooling equipment inventory**  
   - CRAC/CRAH, in-row coolers, chillers, CDUs, leak detectors as first-class assets (or typed “environment devices”)  
   - Optional link to power PDUs / circuits that feed them  

3. **SNMP / poll integration**  
   - Site OID templates for common environmental probes (e.g. APC NetBotz-class, Vertiv, generic temp/humidity OIDs)  
   - Scheduled poll via existing worker (or shared scheduler)  
   - Alerts/digests for high temp, high humidity, sensor offline (extend PowerAlertService patterns or a sibling EnvironmentAlertService)

4. **UI**  
   - Nav area under Power or a sibling **Environment / Cooling** section  
   - Room or floor overlay later (optional): heat/humidity callouts — not required for v1  

**Implementer notes**

- Reuse: `SnmpPoller` / site OID templates, history sample tables (or parallel `env_readings`), MailService digests, Settings retention.  
- Prefer additive schema (`env_sensors`, `cooling_units`, readings JSON or typed columns) rather than overloading `pdus`.  
- Units: °C default with optional °F display; humidity %RH.  
- Don’t invent BMS/BACnet full stack in v1 unless requested — SNMP + manual entry first.

**Out of scope (unless asked later)**

- Full BMS / BACnet / Modbus gateways  
- CFD / thermal twin modeling  
- Automatic cooling control (setpoints / BMS write)  
- ASHRAE envelope compliance engine (reports can come later)

**Acceptance (when built)**

- [ ] Can define at least temp (+ humidity) points and see last value + recent history  
- [ ] Can inventory cooling units and associate them with rooms/rows  
- [ ] SNMP poll path works for at least one probe template end-to-end  
- [ ] Threshold alert (e.g. high temp) can notify via existing mail settings  
- [ ] Power features remain unaffected  

---

### 5. NOC-View dashboard (live metrics, no full page refresh)

| Field | Value |
|-------|--------|
| **Status** | open |
| **Requested** | 2026-07-29 (user) |
| **Source** | `BACKLOG.md` (chat request after 3D/dashboard perf work) |
| **Priority** | nice-to-have (ops / wall display) |

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

- [ ] NOC (or equivalent) page loads and shows core metrics  
- [ ] Metrics refresh on an interval without full page navigation/reload  
- [ ] Last-updated time visible; failed poll does not blank the whole UI  
- [ ] Hidden tab does not hammer the server (pause or slow poll)  
- [ ] Existing home dashboard still works for day-to-day use  

---

### 6. Cabinet QR codes (labels / plaques → cabinet page)

| Field | Value |
|-------|--------|
| **Status** | open |
| **Requested** | 2026-07-29 (user) |
| **Source** | `BACKLOG.md` (chat request) |
| **Priority** | nice-to-have (field ops / audits) |

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

## Completed (keep for audit; do not re-implement)

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
