<?php
/**
 * Machine API v1 helpers: pagination, JSON typing, allowlisted writes.
 */
declare(strict_types=1);

class ApiV1Service
{
    public const DEFAULT_PER = 50;
    public const MAX_PER = 200;

    /** @var list<string> */
    public const RESOURCES = ['cabinets', 'devices', 'pdus', 'ups', 'work_orders'];

    /** @var list<string> */
    private const BOOL_KEYS = [
        'is_active', 'is_service_account', 'can_login', 'must_change_password',
        'snmp_enabled', 'snmp_auto_poll', 'icmp_monitor',
        'half_depth', 'back_side', 'include_in_site_load',
    ];

    /** @var list<string> */
    private const DEVICE_STATUSES = [
        'production', 'testing', 'development', 'reserved', 'spare', 'decommissioning', 'disposed',
    ];

    /**
     * @return array{page:int,per_page:int,offset:int}
     */
    public static function pageParams(): array
    {
        $per = (int)($_GET['per_page'] ?? $_GET['per'] ?? self::DEFAULT_PER);
        if ($per < 1) {
            $per = self::DEFAULT_PER;
        }
        if ($per > self::MAX_PER) {
            $per = self::MAX_PER;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        return [
            'page' => $page,
            'per_page' => $per,
            'offset' => ($page - 1) * $per,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public static function listPayload(string $key, array $rows, int $total, array $page): array
    {
        $per = max(1, (int)$page['per_page']);
        $pages = $total < 1 ? 1 : (int)ceil($total / $per);
        return [
            $key => self::jsonRows($rows),
            'page' => (int)$page['page'],
            'per_page' => $per,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function jsonRow(array $row): array
    {
        if (class_exists('ApiTokenService')) {
            $row = ApiTokenService::stripSecrets($row);
        }
        foreach ($row as $k => $v) {
            if (in_array((string)$k, self::BOOL_KEYS, true) || str_starts_with((string)$k, 'is_')) {
                $row[$k] = self::toBool($v);
                continue;
            }
            if ($v === null) {
                continue;
            }
            if (preg_match('/(_id|_u|u_height|sort_order)$/', (string)$k) && is_numeric($v) && !str_contains((string)$v, '.')) {
                $row[$k] = (int)$v;
            }
        }
        return $row;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function jsonRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::jsonRow($row);
        }
        return $out;
    }

    public static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((int)$v) !== 0;
        }
        $s = strtolower(trim((string)$v));
        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }

    /** @return array<string,mixed> */
    public static function readJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return is_array($_POST) ? $_POST : [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function requireWrite(): void
    {
        if (!class_exists('ApiTokenService') || !ApiTokenService::hasWriteScope()) {
            App::json([
                'error' => 'Write not allowed',
                'hint' => 'Mint a token with scope write (Users → API-Service Account)',
                'scopes' => class_exists('ApiTokenService') ? (ApiTokenService::tokenScopes() ?: 'read') : 'read',
            ], 403);
        }
    }

    /**
     * Paginate an ordered SELECT.
     *
     * @param list<mixed> $params
     * @return array{rows:list<array<string,mixed>>,total:int,page:array{page:int,per_page:int,offset:int,pages:int,total:int}}
     */
    public static function paginate(string $orderedSql, array $params = []): array
    {
        $page = self::pageParams();
        $total = class_exists('ListPager')
            ? ListPager::count($orderedSql, $params)
            : (int)Database::fetchValue(
                'SELECT COUNT(*) FROM (' . ListPager::stripOrderBy($orderedSql) . ') AS _c',
                $params
            );
        $pages = $total < 1 ? 1 : (int)ceil($total / $page['per_page']);
        if ($page['page'] > $pages) {
            $page['page'] = $pages;
            $page['offset'] = ($page['page'] - 1) * $page['per_page'];
        }
        $sql = class_exists('ListPager')
            ? ListPager::applyLimit($orderedSql, $page['offset'], $page['per_page'])
            : $orderedSql;
        $rows = Database::fetchAll($sql, $params);
        $page['total'] = $total;
        $page['pages'] = $pages;
        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    public static function q(): string
    {
        return trim((string)($_GET['q'] ?? ''));
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $device
     */
    public static function patchDevice(array $user, array $device, array $body): array
    {
        self::requireWrite();
        if (!AuthManager::canEditDevice($user, $device)) {
            App::json(['error' => 'Forbidden', 'permission' => 'edit_devices'], 403);
        }
        $id = (int)$device['device_id'];
        $fields = [];
        $strKeys = ['label', 'serial_no', 'asset_tag', 'hostname', 'primary_ip', 'mgmt_ip', 'notes'];
        foreach ($strKeys as $k) {
            if (!array_key_exists($k, $body)) {
                continue;
            }
            $v = $body[$k];
            if ($v === null) {
                $fields[$k] = null;
                continue;
            }
            $s = trim((string)$v);
            if ($k === 'label' && $s === '') {
                throw new RuntimeException('label cannot be empty.');
            }
            $fields[$k] = $s === '' ? null : mb_substr($s, 0, 255);
        }
        if (array_key_exists('status', $body)) {
            $st = strtolower(trim((string)$body['status']));
            if (!in_array($st, self::DEVICE_STATUSES, true)) {
                throw new RuntimeException('Invalid status. Allowed: ' . implode(', ', self::DEVICE_STATUSES));
            }
            $fields['status'] = $st;
        }
        if (array_key_exists('cabinet_id', $body)) {
            if ($body['cabinet_id'] === null || $body['cabinet_id'] === '') {
                $fields['cabinet_id'] = null;
            } else {
                $cid = (int)$body['cabinet_id'];
                $cab = Database::fetchOne('SELECT cabinet_id, u_height FROM cabinets WHERE cabinet_id = ?', [$cid]);
                if (!$cab) {
                    throw new RuntimeException('cabinet_id not found.');
                }
                $fields['cabinet_id'] = $cid;
            }
        }
        if (array_key_exists('position_u', $body)) {
            if ($body['position_u'] === null || $body['position_u'] === '') {
                $fields['position_u'] = null;
            } else {
                $fields['position_u'] = max(1, (int)$body['position_u']);
            }
        }
        if (array_key_exists('u_height', $body)) {
            $fields['u_height'] = max(1, (int)$body['u_height']);
        }
        if ($fields === []) {
            throw new RuntimeException('No updatable fields in body.');
        }
        $nextCab = array_key_exists('cabinet_id', $fields)
            ? ($fields['cabinet_id'] !== null ? (int)$fields['cabinet_id'] : null)
            : ($device['cabinet_id'] !== null && $device['cabinet_id'] !== '' ? (int)$device['cabinet_id'] : null);
        $nextPos = array_key_exists('position_u', $fields)
            ? $fields['position_u']
            : ($device['position_u'] !== null && $device['position_u'] !== '' ? (int)$device['position_u'] : null);
        $nextH = array_key_exists('u_height', $fields)
            ? (int)$fields['u_height']
            : max(1, (int)($device['u_height'] ?? 1));
        $parent = (int)($device['parent_device_id'] ?? 0);
        if ($nextCab && $nextPos !== null && $parent < 1) {
            $conflict = self::uConflict($nextCab, (int)$nextPos, $nextH, $id);
            if ($conflict !== null) {
                throw new RuntimeException($conflict);
            }
        }
        $fields['updated_at'] = date('Y-m-d H:i:s');
        Database::update('devices', $fields, 'device_id = :id', [':id' => $id]);
        AuditService::log((int)$user['user_id'], (string)$user['username'], 'api_device_patch', 'device', $id, [
            'fields' => array_keys($fields),
        ]);
        $row = Database::fetchOne(
            'SELECT d.device_id, d.label, d.serial_no, d.asset_tag, d.cabinet_id, d.position_u, d.u_height,
                    d.device_type, d.manufacturer, d.model, d.is_active, d.primary_ip, d.mgmt_ip, d.hostname,
                    d.status, c.name AS cabinet_name
             FROM devices d
             LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
             WHERE d.device_id = ?',
            [$id]
        );
        return self::jsonRow($row ?: ['device_id' => $id]);
    }

    public static function addDeviceNote(array $user, array $device, array $body): array
    {
        self::requireWrite();
        if (!AuthManager::canEditDevice($user, $device)) {
            App::json(['error' => 'Forbidden', 'permission' => 'edit_devices'], 403);
        }
        $text = trim((string)($body['note_text'] ?? $body['note'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('note_text is required.');
        }
        $id = (int)$device['device_id'];
        $noteId = Database::insert('device_notes', [
            'device_id' => $id,
            'user_id' => (int)$user['user_id'],
            'username' => $user['username'] ?? null,
            'note_text' => mb_substr($text, 0, 4000),
        ]);
        if (class_exists('AssetLifecycleService')) {
            try {
                AssetLifecycleService::logEvent(
                    $id,
                    AssetLifecycleService::EVENT_NOTE,
                    mb_substr($text, 0, 200),
                    ['user_id' => (int)$user['user_id'], 'username' => (string)($user['username'] ?? '')],
                    null,
                    null,
                    $text
                );
            } catch (Throwable $e) {
                // note still saved
            }
        }
        AuditService::log((int)$user['user_id'], (string)$user['username'], 'api_device_note', 'device', $id, [
            'note_id' => $noteId,
        ]);
        return [
            'note_id' => (int)$noteId,
            'device_id' => $id,
            'note_text' => mb_substr($text, 0, 4000),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function createWorkOrder(array $user, array $body): array
    {
        self::requireWrite();
        if (!AuthManager::canEditWorkOrders($user)) {
            App::json(['error' => 'Forbidden', 'permission' => 'edit_work_orders'], 403);
        }
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('title is required.');
        }
        $types = function_exists('work_order_types') ? work_order_types() : ['move' => 'move'];
        $workType = (string)($body['work_type'] ?? 'move');
        if (!isset($types[$workType])) {
            $workType = 'move';
        }
        $checklist = function_exists('work_order_default_checklist')
            ? work_order_default_checklist($workType)
            : [];
        $now = date('Y-m-d H:i:s');
        $woId = (int)Database::insert('work_orders', [
            'title' => mb_substr($title, 0, 200),
            'work_type' => $workType,
            'status' => 'draft',
            'change_ticket' => self::nullStr($body['change_ticket'] ?? null),
            'requested_by' => (int)$user['user_id'],
            'assigned_to' => !empty($body['assigned_to']) ? (int)$body['assigned_to'] : null,
            'scheduled_date' => self::nullStr($body['scheduled_date'] ?? null),
            'notes' => self::nullStr($body['notes'] ?? null),
            'checklist_json' => function_exists('work_order_checklist_encode')
                ? work_order_checklist_encode($checklist)
                : json_encode($checklist),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $seed = (int)($body['device_id'] ?? 0);
        if ($seed > 0) {
            $dev = Database::fetchOne(
                'SELECT device_id, cabinet_id, position_u FROM devices WHERE device_id = ? AND is_active = 1',
                [$seed]
            );
            if ($dev) {
                Database::insert('work_order_items', [
                    'work_order_id' => $woId,
                    'device_id' => $seed,
                    'from_cabinet_id' => $dev['cabinet_id'] !== null ? (int)$dev['cabinet_id'] : null,
                    'from_position_u' => $dev['position_u'] !== null ? (int)$dev['position_u'] : null,
                    'to_cabinet_id' => !empty($body['to_cabinet_id']) ? (int)$body['to_cabinet_id'] : null,
                    'to_position_u' => isset($body['to_position_u']) && $body['to_position_u'] !== '' && $body['to_position_u'] !== null
                        ? (int)$body['to_position_u'] : null,
                    'item_status' => 'pending',
                    'sort_order' => 0,
                ]);
            }
        }
        AuditService::log((int)$user['user_id'], (string)$user['username'], 'api_work_order_create', 'work_order', $woId, [
            'title' => $title,
        ]);
        return self::loadWorkOrder($woId);
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function patchWorkOrder(array $user, int $woId, array $body): array
    {
        self::requireWrite();
        if (!AuthManager::canEditWorkOrders($user)) {
            App::json(['error' => 'Forbidden', 'permission' => 'edit_work_orders'], 403);
        }
        $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$woId]);
        if (!$wo) {
            App::json(['error' => 'Not found'], 404);
        }
        $fields = [];
        if (array_key_exists('title', $body)) {
            $t = trim((string)$body['title']);
            if ($t === '') {
                throw new RuntimeException('title cannot be empty.');
            }
            $fields['title'] = mb_substr($t, 0, 200);
        }
        if (array_key_exists('work_type', $body)) {
            $types = function_exists('work_order_types') ? work_order_types() : [];
            $wt = (string)$body['work_type'];
            if ($types !== [] && !isset($types[$wt])) {
                throw new RuntimeException('Invalid work_type.');
            }
            $fields['work_type'] = $wt;
        }
        if (array_key_exists('status', $body)) {
            $statuses = function_exists('work_order_statuses') ? work_order_statuses() : [];
            $st = strtolower(trim((string)$body['status']));
            if ($statuses !== [] && !isset($statuses[$st])) {
                throw new RuntimeException('Invalid status.');
            }
            $fields['status'] = $st;
            if ($st === 'completed' && empty($wo['completed_at'])) {
                $fields['completed_at'] = date('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('change_ticket', $body)) {
            $fields['change_ticket'] = self::nullStr($body['change_ticket']);
        }
        if (array_key_exists('scheduled_date', $body)) {
            $fields['scheduled_date'] = self::nullStr($body['scheduled_date']);
        }
        if (array_key_exists('notes', $body)) {
            $fields['notes'] = self::nullStr($body['notes']);
        }
        if (array_key_exists('assigned_to', $body)) {
            $fields['assigned_to'] = $body['assigned_to'] === null || $body['assigned_to'] === ''
                ? null : (int)$body['assigned_to'];
        }
        if ($fields === []) {
            throw new RuntimeException('No updatable fields in body.');
        }
        $fields['updated_at'] = date('Y-m-d H:i:s');
        Database::update('work_orders', $fields, 'work_order_id = :id', [':id' => $woId]);
        AuditService::log((int)$user['user_id'], (string)$user['username'], 'api_work_order_patch', 'work_order', $woId, [
            'fields' => array_keys($fields),
        ]);
        return self::loadWorkOrder($woId);
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function addWorkOrderItem(array $user, int $woId, array $body): array
    {
        self::requireWrite();
        if (!AuthManager::canEditWorkOrders($user)) {
            App::json(['error' => 'Forbidden', 'permission' => 'edit_work_orders'], 403);
        }
        $wo = Database::fetchOne('SELECT work_order_id FROM work_orders WHERE work_order_id = ?', [$woId]);
        if (!$wo) {
            App::json(['error' => 'Not found'], 404);
        }
        $did = (int)($body['device_id'] ?? 0);
        if ($did < 1) {
            throw new RuntimeException('device_id is required.');
        }
        $dev = Database::fetchOne(
            'SELECT device_id, cabinet_id, position_u FROM devices WHERE device_id = ? AND is_active = 1',
            [$did]
        );
        if (!$dev) {
            throw new RuntimeException('device_id not found or inactive.');
        }
        $sort = (int)Database::fetchValue(
            'SELECT ISNULL(MAX(sort_order), -1) + 1 FROM work_order_items WHERE work_order_id = ?',
            [$woId]
        );
        $itemId = (int)Database::insert('work_order_items', [
            'work_order_id' => $woId,
            'device_id' => $did,
            'from_cabinet_id' => $dev['cabinet_id'] !== null ? (int)$dev['cabinet_id'] : null,
            'from_position_u' => $dev['position_u'] !== null ? (int)$dev['position_u'] : null,
            'to_cabinet_id' => !empty($body['to_cabinet_id']) ? (int)$body['to_cabinet_id'] : null,
            'to_position_u' => isset($body['to_position_u']) && $body['to_position_u'] !== '' && $body['to_position_u'] !== null
                ? (int)$body['to_position_u'] : null,
            'item_status' => 'pending',
            'sort_order' => $sort,
        ]);
        Database::update('work_orders', ['updated_at' => date('Y-m-d H:i:s')], 'work_order_id = :id', [':id' => $woId]);
        AuditService::log((int)$user['user_id'], (string)$user['username'], 'api_work_order_item_add', 'work_order', $woId, [
            'item_id' => $itemId,
            'device_id' => $did,
        ]);
        return self::loadWorkOrder($woId);
    }

    /**
     * @return array{work_order:array<string,mixed>,items:list<array<string,mixed>>}
     */
    public static function loadWorkOrder(int $id): array
    {
        $wo = Database::fetchOne('SELECT * FROM work_orders WHERE work_order_id = ?', [$id]);
        if (!$wo) {
            App::json(['error' => 'Not found'], 404);
        }
        $items = [];
        try {
            $items = Database::fetchAll(
                'SELECT item_id, work_order_id, device_id, from_cabinet_id, from_position_u,
                        to_cabinet_id, to_position_u, item_status, sort_order
                 FROM work_order_items WHERE work_order_id = ? ORDER BY sort_order, item_id',
                [$id]
            );
        } catch (Throwable $e) {
            $items = [];
        }
        return [
            'work_order' => self::jsonRow($wo),
            'items' => self::jsonRows($items),
        ];
    }

    private static function nullStr(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function uConflict(int $cabinetId, int $positionU, int $uHeight, int $excludeId): ?string
    {
        $devices = Database::fetchAll(
            'SELECT device_id, label, position_u, u_height FROM devices
             WHERE cabinet_id = ? AND is_active = 1 AND position_u IS NOT NULL
               AND parent_device_id IS NULL AND device_id <> ?',
            [$cabinetId, $excludeId]
        );
        $end = $positionU + $uHeight - 1;
        foreach ($devices as $d) {
            $dStart = (int)$d['position_u'];
            $dEnd = $dStart + (int)$d['u_height'] - 1;
            if ($positionU <= $dEnd && $end >= $dStart) {
                return "U-space conflict with {$d['label']} (U{$dStart}-U{$dEnd})";
            }
        }
        $cabU = (int)Database::fetchValue('SELECT u_height FROM cabinets WHERE cabinet_id = ?', [$cabinetId]);
        if ($positionU < 1 || $end > $cabU) {
            return "Position exceeds cabinet height ({$cabU}U)";
        }
        return null;
    }
}
