# ColdAisle backlog

Ideas and deferred work. **Do not implement until explicitly requested.**

When picking up an item, confirm scope with the requester; prefer the smallest useful slice.

---

## Active sessions / presence

**Status:** backlog  
**Requested:** 2026-07-26  
**Priority:** nice-to-have (ops UX)

### Goal
Show which users are currently signed in, and warn admins before applying an in-app update while others are active.

### Desired UX
1. **Users & Departments** (`pages/users.php`)  
   - Badge/tag next to each user who has an **active session** (e.g. “Online” / “Active”).  
   - Optional: idle vs recently active if easy (not required for v1).

2. **Settings → Updates → Apply**  
   - Before `UpdateService::applyUpdate` (confirm UI and/or server-side check):  
     **“N users are currently logged in (alice, bob, …). Applying this update may interrupt them.”**  
   - Admin can still proceed (warning, not a hard block) unless we later add a strict mode.

### Notes for implementers
- Schema already has `auth_sessions` (`sql/schema.sql`), but it may not be fully wired today — PHP uses native sessions; verify whether rows are written on login/activity.
- Likely need:
  - Session registry on login + `last_seen` heartbeat (layout/bootstrap or lightweight ping).
  - Cleanup of expired/idle sessions (align with existing `session_idle_minutes` / `session_absolute_minutes` security settings).
  - Helper e.g. `AuthManager::activeUsers(): list` for Users page + update confirm.
- Do **not** invent multi-node session stores unless PROD requires shared session state across servers.
- Privacy: show username (and maybe display name); avoid dumping IPs in the update warning unless useful for admins.

### Out of scope (unless asked later)
- Real-time websocket presence  
- Forcing logout of other users on update  
- Per-page “who is viewing this cabinet”  
- Mobile push / notifications for “user signed in”

### Acceptance (when built)
- [ ] Online users visible on Users & Departments without a full page refresh requirement stricter than ~1–2 minutes of staleness  
- [ ] Update apply surfaces count + sample usernames when any non-self (or any) sessions are active  
- [ ] Expired sessions do not show as active  
- [ ] Works for local / LDAPS / Entra accounts the same way  

---

## Schema hardening (from `Schema.php`)

**Status:** backlog  

- Schema status / health UI: app VERSION, last ensure OK, expected tables/columns vs live DB  
- Optional `schema_version` / ensure-run log for ops  
- Explicit idempotent alters for rare reshape/backfill (not only ADD)
