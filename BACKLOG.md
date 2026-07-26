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
