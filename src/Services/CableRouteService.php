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
     * @return array{
     *   ok:bool,message:string,path_ids:list<int>,path_codes:list<string>,
     *   length_m:float,hops:list<array<string,mixed>>,geometry:list<array{x:float,y:float}>
     * }
     */
    public static function calculateBetweenCabinets(int $fromCabinetId, int $toCabinetId): array
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

        return [
            'ok' => true,
            'message' => 'Route: ' . implode(' → ', array_merge(
                [(string)($a['name'] ?? 'A')],
                $codes,
                [(string)($b['name'] ?? 'B')]
            )),
            'path_ids' => $pathIds,
            'path_codes' => $codes,
            'length_m' => round($result['distance'], 3),
            'hops' => $hops,
            'geometry' => $geometry,
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
    public static function routeForCable(int $cableId, bool $calculateIfMissing = false): array
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
                $calc = self::calculateBetweenCabinets($ca, $cb);
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
    public static function routesForDevice(int $deviceId, bool $calculateIfMissing = false): array
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
            $r = self::routeForCable((int)$c['cable_id'], $calculateIfMissing);
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
