<?php
/**
 * ColdAisle — end-to-end power path inventory (read-only).
 *
 * Walks existing edges:
 *   device → PSU → rack PDU outlet → (optional) row/room breaker feed → zone → UPS (soft, by zone)
 *
 * Does not invent UPS→panel FKs or circuit wiring; those hops are labeled as inferred/zone association.
 */
declare(strict_types=1);

class PowerPathService
{
    /**
     * @param array{zone_id?:int,view?:string} $filters
     *   view: all | unmapped | single_feed | half_map | no_row_feed
     * @return array{
     *   summary: array<string,int>,
     *   paths: list<array<string,mixed>>,
     *   devices: list<array<string,mixed>>,
     *   unmapped_psus: list<array<string,mixed>>,
     *   half_maps: list<array<string,mixed>>,
     *   single_feed_devices: list<array<string,mixed>>,
     *   cabinets_no_row_feed: list<array<string,mixed>>,
     *   ups_no_zone: list<array<string,mixed>>,
     *   zones: list<array{zone_id:int,name:string}>,
     *   filters: array<string,mixed>
     * }
     */
    public static function report(array $filters = []): array
    {
        $zoneFilter = isset($filters['zone_id']) ? (int)$filters['zone_id'] : 0;
        $view = strtolower(trim((string)($filters['view'] ?? 'all')));
        if (!in_array($view, ['all', 'unmapped', 'single_feed', 'half_map', 'no_row_feed'], true)) {
            $view = 'all';
        }

        $zones = [];
        try {
            $zones = Database::fetchAll('SELECT zone_id, name FROM power_zones ORDER BY name');
        } catch (Throwable $e) {
            $zones = [];
        }

        $psuRows = [];
        try {
            $psuRows = Database::fetchAll(
                'SELECT dps.power_supply_id, dps.device_id, dps.name AS psu_name, dps.watts, dps.connector_type,
                        dps.pdu_id AS psu_pdu_id, dps.pdu_outlet_id,
                        d.device_id AS dev_id, d.label AS device_label, d.device_type, d.is_active AS device_active,
                        d.cabinet_id,
                        c.name AS cabinet_name, c.row_id AS cabinet_row_id,
                        cr.name AS row_name, cr.zone_id AS row_zone_id,
                        o.outlet_id, o.outlet_number, o.label AS outlet_label,
                        o.connected_device_id AS outlet_device_id,
                        o.device_power_supply_id AS outlet_psu_id,
                        o.pdu_id AS outlet_pdu_id,
                        p.pdu_id AS rack_pdu_id, p.name AS rack_pdu_name, p.pdu_scope AS rack_pdu_scope,
                        p.zone_id AS rack_zone_id, p.ip_address AS rack_pdu_ip,
                        z.zone_id AS pdu_zone_id, z.name AS pdu_zone_name, z.feed_type AS pdu_feed_type
                 FROM device_power_supplies dps
                 INNER JOIN devices d ON d.device_id = dps.device_id AND d.is_active = 1
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
                 LEFT JOIN pdu_outlets o ON o.outlet_id = dps.pdu_outlet_id
                 LEFT JOIN pdus p ON p.pdu_id = COALESCE(dps.pdu_id, o.pdu_id) AND p.is_active = 1
                 LEFT JOIN power_zones z ON z.zone_id = p.zone_id
                 ORDER BY d.label, dps.sort_order, dps.power_supply_id'
            );
        } catch (Throwable $e) {
            App::log('PowerPathService PSU query failed: ' . $e->getMessage(), 'error');
            $psuRows = [];
        }

        // Row/room breaker feeds: cabinet_id → list of feeders
        $feedsByCabinet = [];
        try {
            $breakerRows = Database::fetchAll(
                'SELECT b.breaker_id, b.breaker_number, b.label AS breaker_label, b.phase,
                        b.connected_cabinet_id, b.rated_amps,
                        p.pdu_id, p.name AS pdu_name, p.pdu_scope, p.zone_id AS pdu_zone_id,
                        p.ip_address, z.name AS zone_name, z.feed_type
                 FROM pdu_breakers b
                 INNER JOIN pdus p ON p.pdu_id = b.pdu_id AND p.is_active = 1
                 LEFT JOIN power_zones z ON z.zone_id = p.zone_id
                 WHERE b.connected_cabinet_id IS NOT NULL
                 ORDER BY p.name, b.breaker_number'
            );
            foreach ($breakerRows as $br) {
                $cid = (int)($br['connected_cabinet_id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                if (!isset($feedsByCabinet[$cid])) {
                    $feedsByCabinet[$cid] = [];
                }
                $feedsByCabinet[$cid][] = $br;
            }
        } catch (Throwable $e) {
            $feedsByCabinet = [];
        }

        // UPS by zone (soft association)
        $upsByZone = [];
        $upsNoZone = [];
        try {
            $upsRows = Database::fetchAll(
                'SELECT ups_id, name, zone_id, last_load_pct, last_battery_pct, last_output_status, primary_ip
                 FROM ups_units
                 WHERE is_active = 1
                 ORDER BY name'
            );
            foreach ($upsRows as $u) {
                $zid = (int)($u['zone_id'] ?? 0);
                if ($zid <= 0) {
                    $upsNoZone[] = $u;
                    continue;
                }
                if (!isset($upsByZone[$zid])) {
                    $upsByZone[$zid] = [];
                }
                $upsByZone[$zid][] = $u;
            }
        } catch (Throwable $e) {
            $upsByZone = [];
            $upsNoZone = [];
        }

        $paths = [];
        $unmapped = [];
        $halfMaps = [];
        /** @var array<int, array{device_id:int,label:string,mapped_pdu_ids:array<int,true>,psu_count:int,mapped_count:int,zone_ids:array<int,true>,cabinet_id:?int}> $deviceAgg */
        $deviceAgg = [];

        foreach ($psuRows as $row) {
            $deviceId = (int)$row['device_id'];
            $cabinetId = $row['cabinet_id'] !== null && $row['cabinet_id'] !== ''
                ? (int)$row['cabinet_id']
                : null;
            $outletId = $row['pdu_outlet_id'] !== null && $row['pdu_outlet_id'] !== ''
                ? (int)$row['pdu_outlet_id']
                : 0;
            $rackPduId = $row['rack_pdu_id'] !== null && $row['rack_pdu_id'] !== ''
                ? (int)$row['rack_pdu_id']
                : ($row['psu_pdu_id'] !== null && $row['psu_pdu_id'] !== '' ? (int)$row['psu_pdu_id'] : 0);

            $zoneId = 0;
            $zoneName = '';
            $feedType = '';
            if (!empty($row['pdu_zone_id'])) {
                $zoneId = (int)$row['pdu_zone_id'];
                $zoneName = (string)($row['pdu_zone_name'] ?? '');
                $feedType = (string)($row['pdu_feed_type'] ?? '');
            } elseif (!empty($row['row_zone_id'])) {
                $zoneId = (int)$row['row_zone_id'];
            }

            $feeds = $cabinetId ? ($feedsByCabinet[$cabinetId] ?? []) : [];
            $feedLabels = [];
            $feedDetail = [];
            foreach ($feeds as $f) {
                $bn = (string)($f['breaker_label'] ?? '');
                if ($bn === '') {
                    $bn = 'Brk ' . (string)($f['breaker_number'] ?? '?');
                }
                $lab = (string)$f['pdu_name'] . ' / ' . $bn;
                $feedLabels[] = $lab;
                $feedDetail[] = [
                    'pdu_id' => (int)$f['pdu_id'],
                    'pdu_name' => (string)$f['pdu_name'],
                    'pdu_scope' => (string)($f['pdu_scope'] ?? ''),
                    'breaker_number' => (int)($f['breaker_number'] ?? 0),
                    'breaker_label' => $bn,
                    'zone_name' => (string)($f['zone_name'] ?? ''),
                    'feed_type' => (string)($f['feed_type'] ?? ''),
                ];
                // Prefer feeder zone if rack zone missing
                if ($zoneId <= 0 && !empty($f['pdu_zone_id'])) {
                    $zoneId = (int)$f['pdu_zone_id'];
                    $zoneName = (string)($f['zone_name'] ?? '');
                    $feedType = (string)($f['feed_type'] ?? '');
                }
            }

            // When a zone filter is set, only include rows that resolve to that zone.
            if ($zoneFilter > 0 && $zoneId !== $zoneFilter) {
                continue;
            }

            if (!isset($deviceAgg[$deviceId])) {
                $deviceAgg[$deviceId] = [
                    'device_id' => $deviceId,
                    'label' => (string)$row['device_label'],
                    'mapped_pdu_ids' => [],
                    'psu_count' => 0,
                    'mapped_count' => 0,
                    'zone_ids' => [],
                    'cabinet_id' => $cabinetId,
                    'cabinet_name' => (string)($row['cabinet_name'] ?? ''),
                ];
            }
            $deviceAgg[$deviceId]['psu_count']++;

            $mapped = $outletId > 0 && $rackPduId > 0;
            if ($mapped) {
                $deviceAgg[$deviceId]['mapped_count']++;
                $deviceAgg[$deviceId]['mapped_pdu_ids'][$rackPduId] = true;
                if ($zoneId > 0) {
                    $deviceAgg[$deviceId]['zone_ids'][$zoneId] = true;
                }
            }

            // Half-map detection
            $half = false;
            $halfReason = '';
            if ($outletId > 0) {
                $outPsu = $row['outlet_psu_id'] !== null && $row['outlet_psu_id'] !== ''
                    ? (int)$row['outlet_psu_id'] : 0;
                $outDev = $row['outlet_device_id'] !== null && $row['outlet_device_id'] !== ''
                    ? (int)$row['outlet_device_id'] : 0;
                $outPdu = $row['outlet_pdu_id'] !== null && $row['outlet_pdu_id'] !== ''
                    ? (int)$row['outlet_pdu_id'] : 0;
                $psuId = (int)$row['power_supply_id'];
                if ($outPdu > 0 && $rackPduId > 0 && $outPdu !== $rackPduId) {
                    $half = true;
                    $halfReason = 'PSU PDU does not match outlet PDU';
                } elseif ($outPsu > 0 && $outPsu !== $psuId) {
                    $half = true;
                    $halfReason = 'Outlet linked to a different PSU';
                } elseif ($outDev > 0 && $outDev !== $deviceId) {
                    $half = true;
                    $halfReason = 'Outlet linked to a different device';
                } elseif ($outPsu === 0 && $outDev === 0) {
                    $half = true;
                    $halfReason = 'Outlet reverse link empty (PSU points at outlet)';
                }
            } elseif (!empty($row['psu_pdu_id']) && $outletId === 0) {
                $half = true;
                $halfReason = 'PSU has PDU but no outlet';
            }

            // Resolve zone name if we only had row_zone_id
            if ($zoneId > 0 && $zoneName === '') {
                foreach ($zones as $z) {
                    if ((int)$z['zone_id'] === $zoneId) {
                        $zoneName = (string)$z['name'];
                        break;
                    }
                }
            }

            $upsList = $zoneId > 0 ? ($upsByZone[$zoneId] ?? []) : [];
            $upsLabels = array_map(static fn($u) => (string)$u['name'], $upsList);

            $pathRow = [
                'power_supply_id' => (int)$row['power_supply_id'],
                'device_id' => $deviceId,
                'device_label' => (string)$row['device_label'],
                'device_type' => (string)($row['device_type'] ?? ''),
                'psu_name' => (string)$row['psu_name'],
                'psu_watts' => $row['watts'],
                'cabinet_id' => $cabinetId,
                'cabinet_name' => (string)($row['cabinet_name'] ?? ''),
                'row_name' => (string)($row['row_name'] ?? ''),
                'mapped' => $mapped,
                'half_map' => $half,
                'half_reason' => $halfReason,
                'rack_pdu_id' => $rackPduId > 0 ? $rackPduId : null,
                'rack_pdu_name' => $mapped ? (string)$row['rack_pdu_name'] : '',
                'outlet_number' => $mapped ? $row['outlet_number'] : null,
                'outlet_label' => $mapped ? (string)($row['outlet_label'] ?? '') : '',
                'zone_id' => $zoneId > 0 ? $zoneId : null,
                'zone_name' => $zoneName,
                'feed_type' => $feedType,
                'row_feeds' => $feedDetail,
                'row_feed_summary' => $feedLabels ? implode('; ', $feedLabels) : '',
                'has_row_feed' => $feedLabels !== [],
                'ups' => $upsList,
                'ups_summary' => $upsLabels ? implode(', ', $upsLabels) : '',
            ];
            $pathRow['path_text'] = implode(' → ', [
                'Device: ' . $pathRow['device_label'],
                'PSU: ' . $pathRow['psu_name'],
                $mapped
                    ? ('Rack PDU: ' . $pathRow['rack_pdu_name'] . ' · '
                        . ($pathRow['outlet_label'] !== ''
                            ? $pathRow['outlet_label']
                            : 'outlet #' . (string)$pathRow['outlet_number']))
                    : 'Rack PDU / outlet: unmapped',
                $pathRow['has_row_feed']
                    ? ('Feed: ' . $pathRow['row_feed_summary'])
                    : 'Feed: —',
                $zoneName !== ''
                    ? ('Zone: ' . $zoneName . ($feedType !== '' ? ' [' . $feedType . ']' : ''))
                    : 'Zone: —',
                $pathRow['ups_summary'] !== ''
                    ? ('UPS (zone): ' . $pathRow['ups_summary'])
                    : 'UPS (zone): —',
            ]);

            $paths[] = $pathRow;

            if (!$mapped) {
                $unmapped[] = $pathRow;
            }
            if ($half) {
                $halfMaps[] = $pathRow;
            }
        }

        // Single-feed devices: ≥1 mapped PSU and only one distinct rack PDU among maps
        $singleFeed = [];
        foreach ($deviceAgg as $agg) {
            if ($agg['mapped_count'] < 1) {
                continue;
            }
            $distinctPdus = count($agg['mapped_pdu_ids']);
            $isSingle = $distinctPdus <= 1;
            // Also flag multi-PSU devices with only one mapped cord
            $partialMap = $agg['psu_count'] >= 2 && $agg['mapped_count'] < $agg['psu_count'];
            if ($isSingle || $partialMap) {
                $reasons = [];
                if ($isSingle) {
                    $reasons[] = 'all mapped cords on one PDU';
                }
                if ($partialMap) {
                    $reasons[] = $agg['mapped_count'] . '/' . $agg['psu_count'] . ' PSUs mapped';
                }
                $singleFeed[] = [
                    'device_id' => $agg['device_id'],
                    'device_label' => $agg['label'],
                    'cabinet_name' => $agg['cabinet_name'],
                    'psu_count' => $agg['psu_count'],
                    'mapped_count' => $agg['mapped_count'],
                    'distinct_pdus' => $distinctPdus,
                    'reason' => implode('; ', $reasons),
                ];
            }
        }

        // Cabinets with active rack PDUs but no breaker feed
        $cabinetsNoFeed = [];
        try {
            $cabRows = Database::fetchAll(
                'SELECT c.cabinet_id, c.name AS cabinet_name, cr.name AS row_name,
                        (SELECT COUNT(*) FROM pdus p
                         WHERE p.cabinet_id = c.cabinet_id AND p.is_active = 1) AS rack_pdu_count,
                        (SELECT COUNT(*) FROM devices d
                         WHERE d.cabinet_id = c.cabinet_id AND d.is_active = 1) AS device_count
                 FROM cabinets c
                 LEFT JOIN cabinet_rows cr ON cr.row_id = c.row_id
                 WHERE c.is_active = 1
                 ORDER BY c.name'
            );
            foreach ($cabRows as $cab) {
                $cid = (int)$cab['cabinet_id'];
                $rackCount = (int)($cab['rack_pdu_count'] ?? 0);
                if ($rackCount <= 0) {
                    continue;
                }
                if (!empty($feedsByCabinet[$cid])) {
                    continue;
                }
                if ($zoneFilter > 0) {
                    // optional: skip if no path in zone — keep simple, include all no-feed cabinets
                }
                $cabinetsNoFeed[] = [
                    'cabinet_id' => $cid,
                    'cabinet_name' => (string)$cab['cabinet_name'],
                    'row_name' => (string)($cab['row_name'] ?? ''),
                    'rack_pdu_count' => $rackCount,
                    'device_count' => (int)($cab['device_count'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            $cabinetsNoFeed = [];
        }

        // Filter path list by view
        $filteredPaths = $paths;
        if ($view === 'unmapped') {
            $filteredPaths = $unmapped;
        } elseif ($view === 'single_feed') {
            $sfIds = array_fill_keys(array_column($singleFeed, 'device_id'), true);
            $filteredPaths = array_values(array_filter(
                $paths,
                static fn($p) => isset($sfIds[(int)$p['device_id']])
            ));
        } elseif ($view === 'half_map') {
            $filteredPaths = $halfMaps;
        } elseif ($view === 'no_row_feed') {
            $filteredPaths = array_values(array_filter(
                $paths,
                static fn($p) => !empty($p['cabinet_id']) && empty($p['has_row_feed'])
            ));
        }

        return [
            'summary' => [
                'path_rows' => count($paths),
                'mapped' => count($paths) - count($unmapped),
                'unmapped_psus' => count($unmapped),
                'half_maps' => count($halfMaps),
                'single_feed_devices' => count($singleFeed),
                'cabinets_no_row_feed' => count($cabinetsNoFeed),
                'ups_no_zone' => count($upsNoZone),
                'devices_with_psus' => count($deviceAgg),
            ],
            'paths' => $filteredPaths,
            'all_paths' => $paths,
            'devices' => array_values($deviceAgg),
            'unmapped_psus' => $unmapped,
            'half_maps' => $halfMaps,
            'single_feed_devices' => $singleFeed,
            'cabinets_no_row_feed' => $cabinetsNoFeed,
            'ups_no_zone' => $upsNoZone,
            'zones' => $zones,
            'filters' => [
                'zone_id' => $zoneFilter,
                'view' => $view,
            ],
        ];
    }

    /**
     * Compact counts for dashboard cards (zone filter optional).
     *
     * @return array{unmapped_psus:int,single_feed_devices:int,half_maps:int,cabinets_no_row_feed:int}
     */
    public static function summaryCounts(?int $zoneId = null): array
    {
        $filters = [];
        if ($zoneId !== null && $zoneId > 0) {
            $filters['zone_id'] = $zoneId;
        }
        $r = self::report($filters);
        $s = $r['summary'];
        return [
            'unmapped_psus' => (int)$s['unmapped_psus'],
            'single_feed_devices' => (int)$s['single_feed_devices'],
            'half_maps' => (int)$s['half_maps'],
            'cabinets_no_row_feed' => (int)$s['cabinets_no_row_feed'],
        ];
    }
}
