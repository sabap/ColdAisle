<?php
/**
 * ColdAisle — multi-hop raceway routing for cables.
 *
 * Raceways (cable_paths) form a graph. Cabinet A → hop raceways → Cabinet B.
 * Routes store ordered path_ids on cables.path_route_json; path_id remains the
 * first hop for legacy single-path views.
 */
declare(strict_types=1);

class CableRouteService
{
    /** Max meters from cabinet footprint center to attach to a raceway endpoint. */
    public const CABINET_ATTACH_M = 4.0;

    /** Endpoints of different raceways closer than this share a junction node. */
    public const JUNCTION_SNAP_M = 0.55;

    /**
     * Parse path_route_json into ordered path_ids.
     * @return list<int>
     */
    public static function parseRouteJson(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            try {
                $raw = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                return [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = $raw['path_ids'] ?? $raw['hops'] ?? $raw;
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $h) {
            if (is_array($h)) {
                $id = (int)($h['path_id'] ?? $h['id'] ?? 0);
            } else {
                $id = (int)$h;
            }
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<int> $pathIds
     * @param 'manual'|'calculated'|'single' $source
     */
    public static function encodeRouteJson(array $pathIds, string $source = 'manual'): string
    {
        $clean = [];
        foreach ($pathIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        return json_encode([
            'path_ids' => array_values($clean),
            'source' => $source,
        ], JSON_UNESCAPED_SLASHES) ?: '{"path_ids":[]}';
    }

    /**
     * Resolve hop list for a cable row (route json or legacy path_id).
     * @param array<string,mixed> $cable
     * @return list<int>
     */
    public static function pathIdsForCable(array $cable): array
    {
        $ids = self::parseRouteJson($cable['path_route_json'] ?? null);
        if ($ids !== []) {
            return $ids;
        }
        $pid = (int)($cable['path_id'] ?? 0);
        return $pid > 0 ? [$pid] : [];
    }

    /**
     * Shortest multi-hop raceway route between two cabinets in a room.
     *
     * @param array{
     *   path_kinds?:list<string>,media_class?:string,network?:string,
     *   raceway_network?:string
     * } $options network = all|ladder|fiber|fiber_u_channel|fiber_raceway|conduit|copper
     * @return array{
     *   ok:bool,message:string,path_ids:list<int>,path_codes:list<string>,
     *   length_m:float,hops:list<array<string,mixed>>,geometry:list<array{x:float,y:float}>,
     *   network?:string,path_kinds_used?:list<string>
     * }
     */
    public static function calculateBetweenCabinets(int $fromCabinetId, int $toCabinetId, array $options = []): array
    {
        if ($fromCabinetId < 1 || $toCabinetId < 1 || $fromCabinetId === $toCabinetId) {
            return self::fail('Select two different cabinets.');
        }
        try {
            $a = Database::fetchOne(
                'SELECT cabinet_id, name, room_id, pos_x, pos_y, width_mm, depth_mm
                 FROM cabinets WHERE cabinet_id = ? AND is_active = 1',
                [$fromCabinetId]
            );
            $b = Database::fetchOne(
                'SELECT cabinet_id, name, room_id, pos_x, pos_y, width_mm, depth_mm
                 FROM cabinets WHERE cabinet_id = ? AND is_active = 1',
                [$toCabinetId]
            );
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
        if (!$a || !$b) {
            return self::fail('Cabinet not found.');
        }
        if ((int)($a['room_id'] ?? 0) !== (int)($b['room_id'] ?? 0) || (int)($a['room_id'] ?? 0) < 1) {
            return self::fail('Both cabinets must be in the same room with floor positions.');
        }
        if ($a['pos_x'] === null || $a['pos_y'] === null || $b['pos_x'] === null || $b['pos_y'] === null) {
            return self::fail('Both cabinets need floor-plan positions.');
        }
        $roomId = (int)$a['room_id'];
        $paths = class_exists('CablePlantService')
            ? CablePlantService::pathsForRoom($roomId, true)
            : [];
        if ($paths === []) {
            return self::fail('No raceways drawn in this room yet — draw pathways on the floor plan first.');
        }

        $network = strtolower(trim((string)($options['network'] ?? $options['raceway_network'] ?? 'all')));
        if ($network === '') {
            $network = 'all';
        }
        $kindFilter = [];
        $mediaFilter = null;
        if (isset($options['path_kinds']) && is_array($options['path_kinds'])) {
            foreach ($options['path_kinds'] as $pk) {
                $pk = class_exists('CablePlantService')
                    ? CablePlantService::normalizePathKind((string)$pk)
                    : strtolower(trim((string)$pk));
                if ($pk !== '') {
                    $kindFilter[] = $pk;
                }
            }
        } elseif ($network !== 'all' && class_exists('CablePlantService')) {
            $nets = CablePlantService::racewayNetworks();
            if (isset($nets[$network])) {
                $kindFilter = $nets[$network]['path_kinds'] ?? [];
                $mediaFilter = $nets[$network]['media_class'] ?? null;
            }
        }
        if (isset($options['media_class']) && is_string($options['media_class']) && $options['media_class'] !== '') {
            $mediaFilter = strtolower(trim($options['media_class']));
        }
        if ($kindFilter !== [] || $mediaFilter !== null) {
            $paths = array_values(array_filter($paths, static function ($p) use ($kindFilter, $mediaFilter) {
                $k = class_exists('CablePlantService')
                    ? CablePlantService::normalizePathKind((string)($p['path_kind'] ?? 'ladder'))
                    : strtolower((string)($p['path_kind'] ?? 'ladder'));
                if ($kindFilter !== [] && !in_array($k, $kindFilter, true)) {
                    // Also accept raw aliases already normalized
                    return false;
                }
                if ($mediaFilter !== null && $mediaFilter !== '') {
                    $m = strtolower((string)($p['media_class'] ?? ''));
                    // Fiber U-channel paths are fiber; allow empty media on fiber kinds
                    if ($m !== '' && $m !== $mediaFilter && $m !== 'mixed') {
                        return false;
                    }
                }
                return true;
            }));
        }
        if ($paths === []) {
            $netLabel = $network !== 'all' ? $network : 'selected';
            return self::fail(
                'No raceways match the ' . $netLabel
                . ' network in this room. Draw or clone those paths first.'
            );
        }

        $centerA = self::cabinetCenter($a);
        $centerB = self::cabinetCenter($b);
        $graph = self::buildGraph($paths);
        if ($graph['nodes'] === []) {
            return self::fail('Raceways need at least two waypoints each.');
        }

        // Attach cabinets as virtual nodes
        $startKey = 'cab:' . $fromCabinetId;
        $endKey = 'cab:' . $toCabinetId;
        $graph['nodes'][$startKey] = $centerA;
        $graph['nodes'][$endKey] = $centerB;
        $graph['edges'][$startKey] = $graph['edges'][$startKey] ?? [];
        $graph['edges'][$endKey] = $graph['edges'][$endKey] ?? [];

        foreach ($graph['endpointKeys'] as $ek) {
            $pt = $graph['nodes'][$ek];
            $dA = self::dist($centerA, $pt);
            $dB = self::dist($centerB, $pt);
            if ($dA <= self::CABINET_ATTACH_M) {
                $graph['edges'][$startKey][] = ['to' => $ek, 'w' => $dA, 'path_id' => 0, 'kind' => 'drop'];
                $graph['edges'][$ek][] = ['to' => $startKey, 'w' => $dA, 'path_id' => 0, 'kind' => 'drop'];
            }
            if ($dB <= self::CABINET_ATTACH_M) {
                $graph['edges'][$endKey][] = ['to' => $ek, 'w' => $dB, 'path_id' => 0, 'kind' => 'drop'];
                $graph['edges'][$ek][] = ['to' => $endKey, 'w' => $dB, 'path_id' => 0, 'kind' => 'drop'];
            }
        }
        if ($graph['edges'][$startKey] === [] || $graph['edges'][$endKey] === []) {
            return self::fail(
                'Could not attach cabinets to raceways (need a pathway endpoint within '
                . self::CABINET_ATTACH_M . ' m of each cabinet).'
            );
        }

        $result = self::dijkstra($graph, $startKey, $endKey);
        if ($result === null) {
            return self::fail('No continuous raceway route between these cabinets (merge endpoints or add connectors).');
        }

        $pathIds = [];
        $hops = [];
        $prevPath = 0;
        foreach ($result['edgePath'] as $e) {
            $pid = (int)($e['path_id'] ?? 0);
            if ($pid > 0 && $pid !== $prevPath) {
                $pathIds[] = $pid;
                $prevPath = $pid;
            }
        }
        $pathIds = array_values(array_unique($pathIds));
        $codeMap = [];
        foreach ($paths as $p) {
            $codeMap[(int)$p['path_id']] = trim((string)($p['path_code'] ?? $p['name'] ?? ('#' . $p['path_id'])));
        }
        $codes = [];
        foreach ($pathIds as $pid) {
            $codes[] = $codeMap[$pid] ?? ('#' . $pid);
            $hops[] = [
                'path_id' => $pid,
                'path_code' => $codeMap[$pid] ?? ('#' . $pid),
            ];
        }

        $geometry = self::buildGeometryAlongRoute($graph, $result['nodePath'], $result['edgePath']);

        $netNote = ($network !== 'all' && $network !== '') ? (' [' . $network . ']') : '';
        return [
            'ok' => true,
            'message' => 'Route' . $netNote . ': ' . implode(' → ', array_merge(
                [(string)($a['name'] ?? 'A')],
                $codes,
                [(string)($b['name'] ?? 'B')]
            )),
            'path_ids' => $pathIds,
            'path_codes' => $codes,
            'length_m' => round($result['distance'], 3),
            'hops' => $hops,
            'geometry' => $geometry,
            'network' => $network,
            'path_kinds_used' => $kindFilter,
            'from_cabinet' => [
                'cabinet_id' => $fromCabinetId,
                'name' => (string)($a['name'] ?? ''),
                'x' => $centerA['x'],
                'y' => $centerA['y'],
            ],
            'to_cabinet' => [
                'cabinet_id' => $toCabinetId,
                'name' => (string)($b['name'] ?? ''),
                'x' => $centerB['x'],
                'y' => $centerB['y'],
            ],
            'room_id' => $roomId,
        ];
    }

    /**
     * Full drawable payload for one cable (port A → raceways → port B).
     *
     * @return array{ok:bool,message:string,route?:array<string,mixed>}
     */
    /**
     * @param array<string,mixed> $routeOptions passed to calculateBetweenCabinets when calculating
     */
    public static function routeForCable(int $cableId, bool $calculateIfMissing = false, array $routeOptions = []): array
    {
        try {
            $cable = Database::fetchOne(
                'SELECT c.*,
                        pa.port_id AS a_port, da.device_id AS a_device_id, da.label AS a_device,
                        da.device_type AS a_device_type, da.cabinet_id AS a_cabinet_id,
                        ca.name AS a_cabinet_name, ca.pos_x AS a_cab_x, ca.pos_y AS a_cab_y,
                        ca.width_mm AS a_cab_w, ca.depth_mm AS a_cab_d, ca.room_id AS a_room_id,
                        pb.port_id AS b_port, db.device_id AS b_device_id, db.label AS b_device,
                        db.device_type AS b_device_type, db.cabinet_id AS b_cabinet_id,
                        cb.name AS b_cabinet_name, cb.pos_x AS b_cab_x, cb.pos_y AS b_cab_y,
                        cb.width_mm AS b_cab_w, cb.depth_mm AS b_cab_d, cb.room_id AS b_room_id
                 FROM cables c
                 LEFT JOIN device_ports pa ON pa.port_id = c.a_port_id
                 LEFT JOIN devices da ON da.device_id = pa.device_id
                 LEFT JOIN cabinets ca ON ca.cabinet_id = da.cabinet_id
                 LEFT JOIN device_ports pb ON pb.port_id = c.b_port_id
                 LEFT JOIN devices db ON db.device_id = pb.device_id
                 LEFT JOIN cabinets cb ON cb.cabinet_id = db.cabinet_id
                 WHERE c.cable_id = ?',
                [$cableId]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!$cable) {
            return ['ok' => false, 'message' => 'Cable not found.'];
        }

        $pathIds = self::pathIdsForCable($cable);
        $calculated = false;
        $calc = null;
        if ($pathIds === [] && $calculateIfMissing) {
            $ca = (int)($cable['a_cabinet_id'] ?? 0);
            $cb = (int)($cable['b_cabinet_id'] ?? 0);
            if ($ca > 0 && $cb > 0) {
                $calc = self::calculateBetweenCabinets($ca, $cb, $routeOptions);
                if (!empty($calc['ok'])) {
                    $pathIds = $calc['path_ids'];
                    $calculated = true;
                }
            }
        }

        $roomId = (int)($cable['a_room_id'] ?? $cable['b_room_id'] ?? 0);
        $pathsById = [];
        if ($roomId > 0 && class_exists('CablePlantService')) {
            foreach (CablePlantService::pathsForRoom($roomId, false) as $p) {
                $pathsById[(int)$p['path_id']] = $p;
            }
        }

        $hopMeta = [];
        $codes = [];
        foreach ($pathIds as $pid) {
            $p = $pathsById[$pid] ?? null;
            $code = $p
                ? trim((string)($p['path_code'] ?? $p['name'] ?? ('#' . $pid)))
                : ('#' . $pid);
            $codes[] = $code;
            $hopMeta[] = [
                'path_id' => $pid,
                'path_code' => $code,
                'path_kind' => $p['path_kind'] ?? null,
                'color_hex' => $p['color_hex'] ?? null,
            ];
        }

        // Geometry: cabinet A → stitched raceways → cabinet B
        $geometry = [];
        $aCab = [
            'x' => self::cabX($cable, 'a'),
            'y' => self::cabY($cable, 'a'),
        ];
        $bCab = [
            'x' => self::cabX($cable, 'b'),
            'y' => self::cabY($cable, 'b'),
        ];
        if ($aCab['x'] !== null && $aCab['y'] !== null) {
            $geometry[] = ['x' => $aCab['x'], 'y' => $aCab['y'], 'kind' => 'cabinet'];
        }
        if ($calculated && !empty($calc['geometry'])) {
            foreach ($calc['geometry'] as $pt) {
                if (($pt['kind'] ?? '') === 'cabinet') {
                    continue;
                }
                $geometry[] = $pt;
            }
        } else {
            $geometry = array_merge($geometry, self::stitchPathGeometry($pathIds, $pathsById, $aCab, $bCab));
        }
        if ($bCab['x'] !== null && $bCab['y'] !== null) {
            $last = $geometry !== [] ? $geometry[count($geometry) - 1] : null;
            if (!$last || abs(($last['x'] ?? 0) - $bCab['x']) > 0.01 || abs(($last['y'] ?? 0) - $bCab['y']) > 0.01) {
                $geometry[] = ['x' => $bCab['x'], 'y' => $bCab['y'], 'kind' => 'cabinet'];
            }
        }

        $speed = (string)($cable['speed'] ?? '');
        $speedColors = class_exists('CablePlantService') ? CablePlantService::speedColors() : [];
        $endColor = $speedColors[$speed] ?? '#e2e8f0';
        $jacket = (string)($cable['color_hex'] ?? '');
        if ($jacket === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $jacket)) {
            $jacket = class_exists('CablePlantService')
                ? (CablePlantService::cableFieldsFromInput([
                    'media_type' => $cable['media_type'] ?? '',
                    'speed' => $speed,
                ])['color_hex'] ?? '#38bdf8')
                : '#38bdf8';
        }

        $labelParts = [];
        if (!empty($cable['a_cabinet_name'])) {
            $labelParts[] = (string)$cable['a_cabinet_name'];
        } elseif (!empty($cable['a_device'])) {
            $labelParts[] = (string)$cable['a_device'];
        }
        foreach ($codes as $c) {
            $labelParts[] = $c;
        }
        if (!empty($cable['b_cabinet_name'])) {
            $labelParts[] = (string)$cable['b_cabinet_name'];
        } elseif (!empty($cable['b_device'])) {
            $labelParts[] = (string)$cable['b_device'];
        }

        return [
            'ok' => true,
            'message' => $pathIds === []
                ? 'No pathway assigned' . ($calculateIfMissing ? ' and no calculated route available.' : '.')
                : implode(' → ', $labelParts),
            'route' => [
                'cable_id' => $cableId,
                'cable_label' => $cable['cable_label'] ?? null,
                'media_type' => $cable['media_type'] ?? null,
                'speed' => $speed !== '' ? $speed : null,
                'color_hex' => $jacket,
                'end_color_hex' => $endColor,
                'path_ids' => $pathIds,
                'path_codes' => $codes,
                'hops' => $hopMeta,
                'calculated' => $calculated,
                'has_path' => $pathIds !== [],
                'room_id' => $roomId,
                'geometry' => $geometry,
                'label' => implode(' → ', $labelParts),
                'a' => [
                    'device_id' => (int)($cable['a_device_id'] ?? 0),
                    'device' => (string)($cable['a_device'] ?? ''),
                    'device_type' => (string)($cable['a_device_type'] ?? ''),
                    'cabinet_id' => (int)($cable['a_cabinet_id'] ?? 0),
                    'cabinet' => (string)($cable['a_cabinet_name'] ?? ''),
                    'x' => $aCab['x'],
                    'y' => $aCab['y'],
                ],
                'b' => [
                    'device_id' => (int)($cable['b_device_id'] ?? 0),
                    'device' => (string)($cable['b_device'] ?? ''),
                    'device_type' => (string)($cable['b_device_type'] ?? ''),
                    'cabinet_id' => (int)($cable['b_cabinet_id'] ?? 0),
                    'cabinet' => (string)($cable['b_cabinet_name'] ?? ''),
                    'x' => $bCab['x'],
                    'y' => $bCab['y'],
                ],
            ],
        ];
    }

    /**
     * All drawable routes for a device (skip switch bulk if $forSwitchAll).
     *
     * @return array{ok:bool,message:string,routes:list<array>,room_id:int,is_switch:bool}
     */
    /**
     * @param array<string,mixed> $routeOptions passed when calculating missing routes
     */
    public static function routesForDevice(int $deviceId, bool $calculateIfMissing = false, array $routeOptions = []): array
    {
        if ($deviceId < 1) {
            return ['ok' => false, 'message' => 'Invalid device.', 'routes' => [], 'room_id' => 0, 'is_switch' => false];
        }
        try {
            $dev = Database::fetchOne(
                'SELECT device_id, label, device_type, cabinet_id FROM devices WHERE device_id = ?',
                [$deviceId]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'routes' => [], 'room_id' => 0, 'is_switch' => false];
        }
        if (!$dev) {
            return ['ok' => false, 'message' => 'Device not found.', 'routes' => [], 'room_id' => 0, 'is_switch' => false];
        }
        $type = strtolower((string)($dev['device_type'] ?? ''));
        $isSwitch = str_contains($type, 'switch') || str_contains($type, 'router') || $type === 'chassis';

        try {
            $cables = Database::fetchAll(
                "SELECT c.cable_id
                 FROM cables c
                 INNER JOIN device_ports p ON p.port_id = c.a_port_id OR p.port_id = c.b_port_id
                 WHERE p.device_id = ? AND (c.status IS NULL OR c.status <> 'retired')
                 ORDER BY c.cable_id",
                [$deviceId]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'routes' => [], 'room_id' => 0, 'is_switch' => $isSwitch];
        }

        $routes = [];
        $roomId = 0;
        foreach ($cables as $c) {
            $r = self::routeForCable((int)$c['cable_id'], $calculateIfMissing, $routeOptions);
            if (!empty($r['ok']) && !empty($r['route'])) {
                $routes[] = $r['route'];
                if ($roomId < 1) {
                    $roomId = (int)($r['route']['room_id'] ?? 0);
                }
            }
        }
        return [
            'ok' => true,
            'message' => count($routes) . ' connection route(s)',
            'routes' => $routes,
            'room_id' => $roomId,
            'is_switch' => $isSwitch,
            'device_id' => $deviceId,
            'device_label' => (string)($dev['label'] ?? ''),
        ];
    }

    /**
     * Persist multi-hop route on a cable.
     * @param list<int> $pathIds
     */
    public static function applyRouteToCable(int $cableId, array $pathIds, string $source = 'manual'): array
    {
        if ($cableId < 1) {
            return ['ok' => false, 'message' => 'Invalid cable.'];
        }
        $clean = [];
        foreach ($pathIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[] = $id;
            }
        }
        $fields = [
            'path_id' => $clean[0] ?? null,
            'path_route_json' => self::encodeRouteJson($clean, $source),
        ];
        try {
            Database::update('cables', $fields, 'cable_id = :id', [':id' => $cableId]);
        } catch (Throwable $e) {
            // Column may not exist yet — try path_id only
            try {
                Database::update(
                    'cables',
                    ['path_id' => $clean[0] ?? null],
                    'cable_id = :id',
                    [':id' => $cableId]
                );
                return [
                    'ok' => true,
                    'message' => 'Saved primary pathway (upgrade DB for multi-hop routes).',
                    'path_ids' => $clean,
                ];
            } catch (Throwable $e2) {
                return ['ok' => false, 'message' => $e2->getMessage()];
            }
        }
        return [
            'ok' => true,
            'message' => $clean === []
                ? 'Cleared pathway on cable.'
                : ('Applied route with ' . count($clean) . ' hop(s).'),
            'path_ids' => $clean,
        ];
    }

    /**
     * Path hop ids ordered from a given port's perspective (local → peer).
     * Stored route is always a_port → b_port; reverse when viewing from b.
     *
     * @param array<string,mixed> $cable
     * @return list<int>
     */
    public static function pathIdsFromPortPerspective(array $cable, int $portId): array
    {
        $ids = self::pathIdsForCable($cable);
        if ($ids === [] || $portId < 1) {
            return $ids;
        }
        $a = (int)($cable['a_port_id'] ?? 0);
        $b = (int)($cable['b_port_id'] ?? 0);
        if ($portId === $b && $portId !== $a) {
            return array_values(array_reverse($ids));
        }
        return $ids;
    }

    /**
     * Human path label from a port's perspective (cabinet + raceway codes + peer cabinet).
     *
     * @param array<string,mixed> $cable row with optional a_cabinet_name / b_cabinet_name / path_route_json
     * @param array<int,string> $codeById path_id => code
     */
    public static function routeLabelFromPort(
        array $cable,
        int $localPortId,
        array $codeById = [],
        ?string $localCabinet = null,
        ?string $peerCabinet = null
    ): string {
        $ids = self::pathIdsFromPortPerspective($cable, $localPortId);
        $parts = [];
        if ($localCabinet !== null && $localCabinet !== '') {
            $parts[] = $localCabinet;
        }
        foreach ($ids as $pid) {
            $parts[] = $codeById[$pid] ?? ('#' . $pid);
        }
        if ($peerCabinet !== null && $peerCabinet !== '') {
            $parts[] = $peerCabinet;
        }
        if ($parts === [] && $ids === []) {
            return '';
        }
        if ($parts === []) {
            foreach ($ids as $pid) {
                $parts[] = $codeById[$pid] ?? ('#' . $pid);
            }
        }
        return implode(' → ', $parts);
    }

    /**
     * Create or update a single cable linking local_port ↔ peer_port with multi-hop path.
     * Path hop order is from the local port toward the peer. Storage is always a→b;
     * if local is the b end of an existing cable, hops are reversed before save.
     * Both device ports then show the same connection (peer end gets the reverse path label).
     *
     * @param array{
     *   path_ids?:list<int>,path_ids_ordered?:string,route_source?:string,
     *   media_type?:?string,speed?:?string,color_hex?:?string,cable_label?:?string,
     *   length_m?:float|string|null,cable_role?:?string,notes?:?string,status?:?string,
     *   circuit_id?:?string,strand_count?:int|string|null,
     *   sync_port_attrs?:bool
     * } $options
     * @return array{ok:bool,message:string,cable_id?:int,path_ids?:list<int>,path_ids_stored?:list<int>,created?:bool}
     */
    public static function upsertPortConnection(int $localPortId, int $peerPortId, array $options = []): array
    {
        if ($localPortId < 1 || $peerPortId < 1) {
            return ['ok' => false, 'message' => 'Both local and peer ports are required.'];
        }
        if ($localPortId === $peerPortId) {
            return ['ok' => false, 'message' => 'A port cannot connect to itself.'];
        }

        try {
            $local = Database::fetchOne(
                'SELECT p.port_id, p.device_id, p.label, p.port_number, p.port_type, p.media_type, p.speed,
                        d.label AS device_label, d.cabinet_id, c.name AS cabinet_name, c.room_id
                 FROM device_ports p
                 INNER JOIN devices d ON d.device_id = p.device_id
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 WHERE p.port_id = ?',
                [$localPortId]
            );
            $peer = Database::fetchOne(
                'SELECT p.port_id, p.device_id, p.label, p.port_number, p.port_type, p.media_type, p.speed,
                        d.label AS device_label, d.cabinet_id, c.name AS cabinet_name, c.room_id
                 FROM device_ports p
                 INNER JOIN devices d ON d.device_id = p.device_id
                 LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
                 WHERE p.port_id = ?',
                [$peerPortId]
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!$local || !$peer) {
            return ['ok' => false, 'message' => 'Port not found.'];
        }
        if ((string)($local['port_type'] ?? 'data') !== 'data' || (string)($peer['port_type'] ?? 'data') !== 'data') {
            return ['ok' => false, 'message' => 'Only data ports can be linked with a cable path.'];
        }

        // Path hops from local → peer (as the operator documents the run)
        $pathIdsLocal = [];
        if (!empty($options['path_ids_ordered']) && is_string($options['path_ids_ordered'])) {
            foreach (explode(',', $options['path_ids_ordered']) as $pid) {
                $pid = (int)trim($pid);
                if ($pid > 0) {
                    $pathIdsLocal[] = $pid;
                }
            }
        } elseif (isset($options['path_ids']) && is_array($options['path_ids'])) {
            foreach ($options['path_ids'] as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $pathIdsLocal[] = $pid;
                }
            }
        }
        $routeSource = (string)($options['route_source'] ?? ($pathIdsLocal !== [] ? 'manual' : 'manual'));

        // Existing cable on either port
        $existingLocal = self::activeCableForPort($localPortId);
        $existingPeer = self::activeCableForPort($peerPortId);

        $cableId = 0;
        $created = false;
        $aPort = $localPortId;
        $bPort = $peerPortId;

        if ($existingLocal && (int)$existingLocal['cable_id'] > 0) {
            $cableId = (int)$existingLocal['cable_id'];
            $other = (int)$existingLocal['a_port_id'] === $localPortId
                ? (int)$existingLocal['b_port_id']
                : (int)$existingLocal['a_port_id'];
            if ($other > 0 && $other !== $peerPortId) {
                return [
                    'ok' => false,
                    'message' => 'This port is already connected to another port. Disconnect it first.',
                ];
            }
            // Keep stored a/b endpoints; only reassign if peer was empty
            $aPort = (int)$existingLocal['a_port_id'];
            $bPort = (int)$existingLocal['b_port_id'];
            if ($aPort === $localPortId) {
                $bPort = $peerPortId;
            } elseif ($bPort === $localPortId) {
                $aPort = $peerPortId;
            } else {
                // local was null somehow — set as A
                $aPort = $localPortId;
                $bPort = $peerPortId;
            }
        } elseif ($existingPeer && (int)$existingPeer['cable_id'] > 0) {
            $cableId = (int)$existingPeer['cable_id'];
            $other = (int)$existingPeer['a_port_id'] === $peerPortId
                ? (int)$existingPeer['b_port_id']
                : (int)$existingPeer['a_port_id'];
            if ($other > 0 && $other !== $localPortId) {
                return [
                    'ok' => false,
                    'message' => 'Peer port is already connected to a different port. Disconnect it first.',
                ];
            }
            $aPort = (int)$existingPeer['a_port_id'];
            $bPort = (int)$existingPeer['b_port_id'];
            if ($aPort === $peerPortId) {
                $bPort = $localPortId;
            } elseif ($bPort === $peerPortId) {
                $aPort = $localPortId;
            } else {
                $aPort = $localPortId;
                $bPort = $peerPortId;
            }
        }

        // Convert local→peer hop order into stored a_port→b_port order
        $pathIdsStored = $pathIdsLocal;
        if ($aPort === $peerPortId && $bPort === $localPortId && $pathIdsLocal !== []) {
            $pathIdsStored = array_values(array_reverse($pathIdsLocal));
        } elseif ($aPort === $localPortId && $bPort === $peerPortId) {
            $pathIdsStored = $pathIdsLocal;
        } elseif ($pathIdsLocal !== [] && $aPort !== $localPortId) {
            // local is b end
            $pathIdsStored = array_values(array_reverse($pathIdsLocal));
        }

        $hasPathInput = array_key_exists('path_ids', $options)
            || array_key_exists('path_ids_ordered', $options);

        $postFields = [
            'a_port_id' => $aPort,
            'b_port_id' => $bPort,
            'media_type' => $options['media_type'] ?? $local['media_type'] ?? $peer['media_type'] ?? null,
            'speed' => $options['speed'] ?? $local['speed'] ?? $peer['speed'] ?? null,
            'color_hex' => $options['color_hex'] ?? null,
            'cable_label' => $options['cable_label'] ?? null,
            'length_m' => $options['length_m'] ?? null,
            'cable_role' => $options['cable_role'] ?? 'structured',
            'notes' => $options['notes'] ?? null,
            'status' => $options['status'] ?? 'active',
            'circuit_id' => $options['circuit_id'] ?? null,
            'strand_count' => $options['strand_count'] ?? null,
            'route_source' => $routeSource,
        ];
        if ($hasPathInput) {
            $postFields['path_ids'] = $pathIdsStored;
        }

        $fields = class_exists('CablePlantService')
            ? CablePlantService::cableFieldsFromInput($postFields)
            : [
                'a_port_id' => $aPort,
                'b_port_id' => $bPort,
                'media_type' => $postFields['media_type'],
                'speed' => $postFields['speed'],
                'status' => 'active',
            ];

        // Apply / clear multi-hop only when the client sent path fields
        if ($hasPathInput) {
            if ($pathIdsStored === []) {
                $fields['path_id'] = null;
                $fields['path_route_json'] = self::encodeRouteJson([], $routeSource);
            } else {
                $fields['path_id'] = $pathIdsStored[0];
                $fields['path_route_json'] = self::encodeRouteJson($pathIdsStored, $routeSource);
            }
        }

        // Always set endpoints (cableFieldsFromInput may null empty)
        $fields['a_port_id'] = $aPort;
        $fields['b_port_id'] = $bPort;

        try {
            if ($cableId > 0) {
                Database::update('cables', $fields, 'cable_id = :id', [':id' => $cableId]);
            } else {
                $fields['installed_at'] = date('Y-m-d H:i:s');
                $cableId = (int)Database::insert('cables', $fields);
                $created = true;
            }
        } catch (Throwable $e) {
            // Retry without path_route_json if column missing
            unset($fields['path_route_json']);
            try {
                if ($cableId > 0) {
                    Database::update('cables', $fields, 'cable_id = :id', [':id' => $cableId]);
                } else {
                    $fields['installed_at'] = date('Y-m-d H:i:s');
                    $cableId = (int)Database::insert('cables', $fields);
                    $created = true;
                }
            } catch (Throwable $e2) {
                return ['ok' => false, 'message' => $e2->getMessage()];
            }
        }

        // Optionally sync media/speed onto both ports so both ends document the link
        $syncPorts = !array_key_exists('sync_port_attrs', $options) || !empty($options['sync_port_attrs']);
        if ($syncPorts) {
            $media = $fields['media_type'] ?? null;
            $speed = $fields['speed'] ?? null;
            foreach ([$localPortId, $peerPortId] as $pid) {
                $patch = [];
                if ($media !== null && $media !== '') {
                    $patch['media_type'] = $media;
                }
                if ($speed !== null && $speed !== '') {
                    $patch['speed'] = $speed;
                }
                if ($patch !== []) {
                    try {
                        Database::update('device_ports', $patch, 'port_id = :id', [':id' => $pid]);
                    } catch (Throwable $e) {
                        // non-fatal
                    }
                }
            }
        }

        $codeMap = self::pathCodeMapForIds($pathIdsLocal);
        $hopLabel = self::routeLabelFromPort(
            [
                'a_port_id' => $aPort,
                'b_port_id' => $bPort,
                'path_route_json' => self::encodeRouteJson($pathIdsStored, $routeSource),
                'path_id' => $pathIdsStored[0] ?? null,
            ],
            $localPortId,
            $codeMap,
            (string)($local['cabinet_name'] ?? $local['device_label'] ?? ''),
            (string)($peer['cabinet_name'] ?? $peer['device_label'] ?? '')
        );

        $peerLabel = trim(
            (string)($peer['device_label'] ?? 'peer')
            . ' · '
            . (string)($peer['label'] ?? ('Port ' . ($peer['port_number'] ?? '')))
        );

        return [
            'ok' => true,
            'message' => ($created ? 'Connected to ' : 'Updated connection to ')
                . $peerLabel
                . ($hopLabel !== '' ? ('. Path: ' . $hopLabel) : '')
                . '. Peer port shows the reverse path.',
            'cable_id' => $cableId,
            'path_ids' => $pathIdsLocal,
            'path_ids_stored' => $pathIdsStored,
            'created' => $created,
            'a_port_id' => $aPort,
            'b_port_id' => $bPort,
            'route_label' => $hopLabel,
            'peer_device_id' => (int)$peer['device_id'],
            'peer_port_id' => $peerPortId,
        ];
    }

    /**
     * Remove cable link from a port (deletes the cable record so both ends disconnect).
     *
     * @return array{ok:bool,message:string,cable_id?:int}
     */
    public static function disconnectPort(int $portId): array
    {
        if ($portId < 1) {
            return ['ok' => false, 'message' => 'Invalid port.'];
        }
        $cable = self::activeCableForPort($portId);
        if (!$cable) {
            return ['ok' => false, 'message' => 'No active cable on this port.'];
        }
        $cid = (int)$cable['cable_id'];
        try {
            Database::delete('cables', 'cable_id = ?', [$cid]);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        return [
            'ok' => true,
            'message' => 'Connection removed from both ends.',
            'cable_id' => $cid,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function activeCableForPort(int $portId): ?array
    {
        try {
            $row = Database::fetchOne(
                "SELECT * FROM cables
                 WHERE (a_port_id = ? OR b_port_id = ?)
                   AND (status IS NULL OR status <> 'retired')
                 ORDER BY cable_id DESC",
                [$portId, $portId]
            );
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param list<int> $pathIds
     * @return array<int,string>
     */
    private static function pathCodeMapForIds(array $pathIds): array
    {
        $map = [];
        if ($pathIds === []) {
            return $map;
        }
        try {
            $ph = implode(',', array_fill(0, count($pathIds), '?'));
            $rows = Database::fetchAll(
                "SELECT path_id, path_code, name FROM cable_paths WHERE path_id IN ($ph)",
                $pathIds
            );
            foreach ($rows as $r) {
                $id = (int)$r['path_id'];
                $c = trim((string)($r['path_code'] ?? ''));
                if ($c === '') {
                    $c = trim((string)($r['name'] ?? ('#' . $id)));
                }
                $map[$id] = $c;
            }
        } catch (Throwable $e) {
            // ignore
        }
        return $map;
    }

    // --- Graph internals ---

    /**
     * @param list<array<string,mixed>> $paths
     * @return array{nodes:array<string,array{x:float,y:float}>,edges:array<string,list<array>>,endpointKeys:list<string>}
     */
    private static function buildGraph(array $paths): array
    {
        $nodes = [];
        $edges = [];
        $endpointKeys = [];
        $pathEnds = []; // path_id => [startKey, endKey]

        foreach ($paths as $p) {
            $pid = (int)($p['path_id'] ?? 0);
            $pts = $p['waypoints_list'] ?? [];
            if (!is_array($pts) || count($pts) < 2) {
                continue;
            }
            $keys = [];
            $len = 0.0;
            for ($i = 0; $i < count($pts); $i++) {
                $k = 'v:' . $pid . ':' . $i;
                $nodes[$k] = [
                    'x' => (float)($pts[$i]['x'] ?? 0),
                    'y' => (float)($pts[$i]['y'] ?? 0),
                ];
                $edges[$k] = $edges[$k] ?? [];
                $keys[] = $k;
                if ($i > 0) {
                    $d = self::dist($nodes[$keys[$i - 1]], $nodes[$k]);
                    $len += $d;
                    $edges[$keys[$i - 1]][] = ['to' => $k, 'w' => $d, 'path_id' => $pid, 'kind' => 'raceway'];
                    $edges[$k][] = ['to' => $keys[$i - 1], 'w' => $d, 'path_id' => $pid, 'kind' => 'raceway'];
                }
            }
            $start = $keys[0];
            $end = $keys[count($keys) - 1];
            $pathEnds[$pid] = [$start, $end, $len];
            $endpointKeys[] = $start;
            $endpointKeys[] = $end;
        }

        // Junction snaps between endpoints of different paths
        $n = count($endpointKeys);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $ka = $endpointKeys[$i];
                $kb = $endpointKeys[$j];
                // skip same path endpoints already connected along polyline
                if (str_starts_with($ka, 'v:') && str_starts_with($kb, 'v:')) {
                    $pa = (int)explode(':', $ka)[1];
                    $pb = (int)explode(':', $kb)[1];
                    if ($pa === $pb) {
                        continue;
                    }
                }
                $d = self::dist($nodes[$ka], $nodes[$kb]);
                if ($d <= self::JUNCTION_SNAP_M) {
                    $w = max($d, 0.05);
                    $edges[$ka][] = ['to' => $kb, 'w' => $w, 'path_id' => 0, 'kind' => 'junction'];
                    $edges[$kb][] = ['to' => $ka, 'w' => $w, 'path_id' => 0, 'kind' => 'junction'];
                }
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'endpointKeys' => array_values(array_unique($endpointKeys)),
            'pathEnds' => $pathEnds,
        ];
    }

    /**
     * @param array{nodes:array,edges:array} $graph
     * @return array{distance:float,nodePath:list<string>,edgePath:list<array>}|null
     */
    private static function dijkstra(array $graph, string $start, string $goal): ?array
    {
        $dist = [];
        $prev = [];
        $prevEdge = [];
        $q = new SplPriorityQueue();
        $q->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        foreach (array_keys($graph['nodes']) as $k) {
            $dist[$k] = INF;
        }
        if (!isset($dist[$start]) || !isset($dist[$goal])) {
            return null;
        }
        $dist[$start] = 0.0;
        $q->insert($start, 0.0);
        $visited = [];

        while (!$q->isEmpty()) {
            $u = $q->extract();
            if (!empty($visited[$u])) {
                continue;
            }
            $visited[$u] = true;
            if ($u === $goal) {
                break;
            }
            foreach ($graph['edges'][$u] ?? [] as $e) {
                $v = (string)$e['to'];
                if (!isset($dist[$v])) {
                    continue;
                }
                $alt = $dist[$u] + (float)$e['w'];
                if ($alt < $dist[$v]) {
                    $dist[$v] = $alt;
                    $prev[$v] = $u;
                    $prevEdge[$v] = $e;
                    $q->insert($v, -$alt);
                }
            }
        }
        if (!is_finite($dist[$goal] ?? INF)) {
            return null;
        }
        $nodePath = [];
        $edgePath = [];
        for ($c = $goal; $c !== null; $c = $prev[$c] ?? null) {
            $nodePath[] = $c;
            if (isset($prevEdge[$c])) {
                $edgePath[] = $prevEdge[$c];
            }
            if ($c === $start) {
                break;
            }
        }
        $nodePath = array_reverse($nodePath);
        $edgePath = array_reverse($edgePath);
        return [
            'distance' => (float)$dist[$goal],
            'nodePath' => $nodePath,
            'edgePath' => $edgePath,
        ];
    }

    /**
     * @param array{nodes:array} $graph
     * @param list<string> $nodePath
     * @param list<array> $edgePath
     * @return list<array{x:float,y:float,kind?:string}>
     */
    private static function buildGeometryAlongRoute(array $graph, array $nodePath, array $edgePath): array
    {
        $out = [];
        foreach ($nodePath as $k) {
            if (!isset($graph['nodes'][$k])) {
                continue;
            }
            $kind = str_starts_with($k, 'cab:') ? 'cabinet' : 'raceway';
            $out[] = [
                'x' => (float)$graph['nodes'][$k]['x'],
                'y' => (float)$graph['nodes'][$k]['y'],
                'kind' => $kind,
            ];
        }
        return self::dedupeGeom($out);
    }

    /**
     * @param list<int> $pathIds
     * @param array<int,array> $pathsById
     * @param array{x:?float,y:?float} $aCab
     * @param array{x:?float,y:?float} $bCab
     * @return list<array{x:float,y:float,kind?:string}>
     */
    private static function stitchPathGeometry(array $pathIds, array $pathsById, array $aCab, array $bCab): array
    {
        $out = [];
        $cursor = ($aCab['x'] !== null && $aCab['y'] !== null)
            ? ['x' => (float)$aCab['x'], 'y' => (float)$aCab['y']]
            : null;
        foreach ($pathIds as $pid) {
            $p = $pathsById[$pid] ?? null;
            $pts = $p['waypoints_list'] ?? [];
            if (!is_array($pts) || count($pts) < 2) {
                continue;
            }
            $first = ['x' => (float)$pts[0]['x'], 'y' => (float)$pts[0]['y']];
            $last = [
                'x' => (float)$pts[count($pts) - 1]['x'],
                'y' => (float)$pts[count($pts) - 1]['y'],
            ];
            $forward = true;
            if ($cursor !== null) {
                $d0 = self::dist($cursor, $first);
                $d1 = self::dist($cursor, $last);
                $forward = $d0 <= $d1;
            }
            $seq = $forward ? $pts : array_reverse($pts);
            foreach ($seq as $pt) {
                $out[] = [
                    'x' => (float)$pt['x'],
                    'y' => (float)$pt['y'],
                    'kind' => 'raceway',
                ];
            }
            $cursor = $forward ? $last : $first;
        }
        return self::dedupeGeom($out);
    }

    /** @param list<array{x:float,y:float,kind?:string}> $pts */
    private static function dedupeGeom(array $pts): array
    {
        $out = [];
        foreach ($pts as $pt) {
            if ($out === []) {
                $out[] = $pt;
                continue;
            }
            $last = $out[count($out) - 1];
            if (abs($last['x'] - $pt['x']) > 0.005 || abs($last['y'] - $pt['y']) > 0.005) {
                $out[] = $pt;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $cab */
    private static function cabinetCenter(array $cab): array
    {
        $w = ((float)($cab['width_mm'] ?? 600)) / 1000.0;
        $d = ((float)($cab['depth_mm'] ?? 1200)) / 1000.0;
        return [
            'x' => (float)$cab['pos_x'] + $w / 2,
            'y' => (float)$cab['pos_y'] + $d / 2,
        ];
    }

    /** @param array<string,mixed> $cable */
    private static function cabX(array $cable, string $side): ?float
    {
        $x = $cable[$side . '_cab_x'] ?? null;
        if ($x === null || $x === '') {
            return null;
        }
        $w = ((float)($cable[$side . '_cab_w'] ?? 600)) / 1000.0;
        return (float)$x + $w / 2;
    }

    /** @param array<string,mixed> $cable */
    private static function cabY(array $cable, string $side): ?float
    {
        $y = $cable[$side . '_cab_y'] ?? null;
        if ($y === null || $y === '') {
            return null;
        }
        $d = ((float)($cable[$side . '_cab_d'] ?? 1200)) / 1000.0;
        return (float)$y + $d / 2;
    }

    /** @param array{x:float,y:float} $a @param array{x:float,y:float} $b */
    private static function dist(array $a, array $b): float
    {
        return hypot((float)$a['x'] - (float)$b['x'], (float)$a['y'] - (float)$b['y']);
    }

    /** @return array{ok:bool,message:string,path_ids:list,path_codes:list,length_m:float,hops:list,geometry:list} */
    private static function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'path_ids' => [],
            'path_codes' => [],
            'length_m' => 0.0,
            'hops' => [],
            'geometry' => [],
        ];
    }
}
