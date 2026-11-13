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
| **Firewalls** | Plant → Firewalls. Cisco ASA and Threat Defense, read-only SNMP, throughput charts, a dashboard WAN card, and a NOC panel. Details below. |
| **NOC wall** | Live metrics, rotating panels, 3D, optional public token. Cooling pane: live S/R, aisle averages, active/standby, 24h temperature history. Firewalls pane: stored throughput charts, paged by pair. |
| **Cabling** | Port-to-port circuits; raceways on the plan (overhead / underfloor). |
| **IPAM** | Address plan, subnet plan, aligned groups (same host index across prefixes — e.g. Metro-E last octet), Excel import. Viewer vs edit. Not DDI. Walkthrough: in-app **Documentation → IPAM**. |
| **Work orders** | Moves/changes with checklist; apply to inventory. Optional ITSM outbound/pull (SDP, ServiceNow, Zendesk, Jira, Freshservice). |
| **Field** | Cabinet QR labels, Tech / PWA chrome, audits, chain-of-custody / warranty fields. |
| **Mail** | Welcome, local password reset, disposal due-soon, env threshold/stale digests, power alerts. |
| **API** | Bearer `/api/v1.php` for robots (see in-app Documentation). |

## Firewalls

Plant → Firewalls polls a Cisco ASA or a Secure Firewall Threat Defense over SNMP. ColdAisle does not change policy, and it does not collect the activation key or VPN user names. Walkthrough: in-app **Documentation → Firewalls**.

- **Add firewall** chooses ASA (SNMPv2c template) or Threat Defense (SNMPv3 on the management address of that firewall). On a multiple-context ASA, set the SNMP context to the admin context. A blank community or passphrase on a later edit keeps the saved secret.
- Scheduled polling is on for a new firewall and runs about once a minute. Those rows also appear under **Plant → SNMP**. The wall and the charts read stored samples. They do not query the firewall when the page is open.
- An optional management center can fill health, software, and the HA pair name when SNMP left them blank. Charts still come from each firewall. Members that share a pair name are grouped on the NOC. Their numbers are not added together. Leave **Verify TLS** checked unless the center uses a private certificate.
- If the SNMP library on this server cannot authenticate SHA256 or AES256, use SHA and AES on the firewall user and on the firewall form. The edit page says so when that limit applies. A newer ColdAisle release does not add those algorithms.
- **Devices:** set the chassis type to Firewall. The device page keeps the rack location. Edit the firewall and choose that chassis under **Rack device**, or use **Add this firewall** on the device. SNMP, charts, and credentials for the firewall are edited on the firewall, not in the device SNMP card. One chassis links to one firewall. Saving the device does not erase SNMP already stored on that device row, and it does not start a second poller.
- The interface table keeps up to 128 names from the poll. Names that start with `Internal-` are omitted. Physical links show status, speed, and rate. A rate is the change since the previous poll, as a percent of link speed, after two polls within 15 minutes. Virtual tunnel interfaces show **Up** or **Down** only and are not charted. A tunnel the firewall has removed, or that is administratively down, leaves the table. A tunnel that is administratively up and operationally down stays and shows Down. **Hide** is for a name SNMP still reports. Clear it under **Edit firewall → Hidden interfaces**. Graph switches choose which up links with a speed appear on the throughput charts.
- Each graphed interface has a 30-minute chart and a 24-hour chart. Inbound is blue and outbound is orange. CPU, memory, and connections use six hours. PAT, AnyConnect, and IPSec chart when the firewall reports them. Threat Defense adds data-plane, Snort, and disk charts when those readings exist. Samples are kept 14 days.
- The dashboard **Firewalls** card lists up to eight firewalls and up to two WAN links each, with Up or Down and a rate when the link reports a speed. Tunnels and hidden names are skipped.
- **NOC → Firewalls** shows one firewall with up to five graphed interfaces. With more than one, it pages a pair at a time, side by side, with up to two graphed interfaces each. The wall includes the first 12 firewalls.

## Out of 1.0 (by design)

Do **not** expect these unless a later release says otherwise:

- Cooling or PDU **writes** (setpoints, On/Off, outlet reboot)
- Firewall **policy edits or deploys** (the management center login is inventory and health only)
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

1. SNMP scheduler on; PDUs, UPS, cooling units, and firewalls **auto-poll**.  
2. Liebert DS: SNMPv3 profile + **Engine ID** per unit; Poll now shows supply/return, not only uptime.  
3. Env sensors: **Placement** = Cold aisle or Hot aisle (untagged probes are treated as cold aisle).  
4. Floor plan: vents, returns, cabinet **front facing**, cooling footprints.  
5. NOC: air particles on if you want the rainbow; soak a few days so cooling history has points.  
6. **Settings → Site backup** — keep a `coldaisle-site_*.zip` you have restored once in a drill.
7. Firewalls: add each ASA or Threat Defense, leave scheduled polling on, and link the rack device whose type is Firewall. Hide a tunnel SNMP still reports after a removal. Live tunnels stay as Up or Down only.

## Docs map

| Doc | Role |
|-----|------|
| This file | 1.0 surface (operators / 1.0 gate) |
| [`CHANGELOG.md`](../CHANGELOG.md) | What each tag shipped |
| [`BACKLOG.md`](../BACKLOG.md) | Deferred work only |
| [`DCIM_GAPS.md`](../DCIM_GAPS.md) | Competitive map (not a build queue) |
| [`RELEASING.md`](RELEASING.md) | How maintainers cut a tag |
| In-app **Documentation** | Floor planner, IPAM, SNMP Discover, Firewalls, work orders, Tech/PWA, API |
