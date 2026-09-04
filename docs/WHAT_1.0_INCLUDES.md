# What ColdAisle 1.0 includes

Operator and installer summary of the **1.0 product surface**.  
Current tagged app version is in [`VERSION`](../VERSION). This page describes what **1.0** includes — not a promise of Trellis, Nlyte, or a BMS.

In a running site: **Documentation → What 1.0 includes**.

## Who it is for

Mid-size halls on **Windows (IIS + PHP + SQL Server)** that need floor plans, power, cooling, cabling, IPAM, and a NOC wall — without a Linux-only DCIM or a plant-control product.

## In scope (1.0)

| Area | What operators get |
|------|--------------------|
| **Auth** | Local accounts, LDAPS (AD), Microsoft Entra ID (OIDC). RBAC: Administrator, Operator, Auditor, Viewer. |
| **Install & stay current** | Browser setup (or restore a site backup). **Settings → Updates** from GitHub tags. Site backup/restore, optional SMB copy, storage housekeeping. |
| **Hall inventory** | Sites, rooms, cabinets (U elevation, templates, chassis children), devices, interfaces. |
| **Floor plan + 3D** | Drag cabinets, row PDUs, cooling units, raceways, supply vents and returns. 2D plan and Three.js 3D (dashboard, planner, NOC). |
| **Power** | Zones A/B, row and rack PDUs, multi-phase, outlets, templates. SNMP Discover/Poll. Facility load without rack+row double-count. Power path report, free kW/U, phase imbalance. History charts and load alert mail. |
| **UPS** | Inventory, PowerNet-style poll, load/battery/runtime, history. |
| **Cooling & air** | CRAC/CRAH/in-row/chiller/pumps/CDU inventory, active/standby (manual or **SNMP On/Off/Standby**). Env sensors (cold/hot aisle, intake/exhaust). ASHRAE recommended-band *guidance* (not a compliance engine). |
| **Liebert / Vertiv DS SNMP** | SNMPv3 with per-unit Engine ID. Discover + poll of LGP leaves: supply/return, humidity, setpoints, states, capacities, run hours, remotes. Site °C/°F. Sentinel “not equipped” values dropped. |
| **Airflow (not CFD)** | Ceiling supplies and returns; particles vent → cabinet front → rear → return. Color from live supply, aisle sensors, and return (blue → green → yellow → red). Toggle on dashboard / planner / NOC. |
| **NOC wall** | Live metrics, rotating panels, 3D, optional public token. Cooling pane: live S/R, aisle averages, active/standby, 24h temperature history. |
| **Cabling** | Port-to-port circuits; raceways on the plan (overhead / underfloor). |
| **IPAM** | Address plan, subnet plan, aligned groups (same host index across prefixes — e.g. Metro-E last octet), Excel import. Viewer vs edit. Not DDI. Walkthrough: in-app **Documentation → IPAM**. |
| **Work orders** | Moves/changes with checklist; apply to inventory. Optional ITSM outbound/pull (SDP, ServiceNow, Zendesk, Jira, Freshservice). |
| **Field** | Cabinet QR labels, Tech / PWA chrome, audits, chain-of-custody / warranty fields. |
| **Mail** | Welcome, local password reset, disposal due-soon, env threshold/stale digests, power alerts. |
| **API** | Bearer `/api/v1.php` for robots (see in-app Documentation). |

## Out of 1.0 (by design)

Do **not** expect these unless a later release says otherwise:

- Cooling or PDU **writes** (setpoints, On/Off, outlet reboot)
- Discrete Liebert **alarm table** walks (44 boolean alarms) — leaf telemetry is enough for 1.0
- Other Vertiv families (CRV, CRD, plant chillers) until those assets exist on site
- PDU LCD/LED **Locate** blink (parked; no safe public OID yet)
- BMS / BACnet / Modbus
- Full CFD / thermal twin
- Multi-site / multi-tenant
- Native iOS/Android store apps (Tech mode in the browser is the field path)
- IPv6, Infoblox-class DDI, switch interface inventory as IPAM
- Long-term TSDB (Influx/Prometheus) — SQL history is the 1.0 store

## Operator checklist (this hall)

1. SNMP scheduler on; PDUs, UPS, and cooling units **auto-poll**.  
2. Liebert DS: SNMPv3 profile + **Engine ID** per unit; Poll now shows supply/return, not only uptime.  
3. Env sensors: **Placement** = Cold aisle or Hot aisle (untagged probes are treated as cold aisle).  
4. Floor plan: vents, returns, cabinet **front facing**, cooling footprints.  
5. NOC: air particles on if you want the rainbow; soak a few days so cooling history has points.  
6. **Settings → Site backup** — keep a `coldaisle-site_*.zip` you have restored once in a drill.

## Docs map

| Doc | Role |
|-----|------|
| This file | 1.0 surface (operators / 1.0 gate) |
| [`CHANGELOG.md`](../CHANGELOG.md) | What each tag shipped |
| [`BACKLOG.md`](../BACKLOG.md) | Deferred work only |
| [`DCIM_GAPS.md`](../DCIM_GAPS.md) | Competitive map (not a build queue) |
| [`RELEASING.md`](RELEASING.md) | How maintainers cut a tag |
| In-app **Documentation** | Floor planner, SNMP Discover, work orders, Tech/PWA, API |
