<?php
/**
 * Inventory report data. Live readings and credentials are not selected.
 */
declare(strict_types=1);

function report_inventory_view(): string
{
    $view = strtolower(trim((string)($_GET['inv'] ?? '')));
    return $view === 'full' ? 'full' : 'summary';
}

/** Summary or Full detail URL for the open report. Keeps the current filters. */
function report_inventory_view_href(string $view): string
{
    $q = $_GET;
    unset($q['export']);
    if ($view === 'full') {
        $q['inv'] = 'full';
    } else {
        unset($q['inv']);
    }
    $qs = http_build_query($q);
    return $qs === '' ? '?' : ('?' . $qs);
}

/** @return array{by_type:list<array<string,mixed>>,by_status:list<array<string,mixed>>,by_dc:list<array<string,mixed>>} */
function report_inventory_summary(): array
{
    return [
        'by_type' => Database::fetchAll(
            "SELECT device_type, COUNT(*) AS cnt FROM devices WHERE is_active = 1 GROUP BY device_type ORDER BY cnt DESC"
        ),
        'by_status' => Database::fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM devices WHERE is_active = 1 GROUP BY status ORDER BY cnt DESC"
        ),
        'by_dc' => Database::fetchAll(
            "SELECT dc.name, COUNT(d.device_id) AS cnt
             FROM datacenters dc
             LEFT JOIN rooms r ON r.datacenter_id = dc.datacenter_id
             LEFT JOIN cabinets c ON c.room_id = r.room_id AND c.is_active = 1
             LEFT JOIN devices d ON d.cabinet_id = c.cabinet_id AND d.is_active = 1
             WHERE dc.is_active = 1
             GROUP BY dc.name ORDER BY dc.name"
        ),
    ];
}

/**
 * Active devices, one row each. Poll results and secrets stay out of the select list.
 *
 * @return list<array<string,mixed>>
 */
function report_inventory_devices(): array
{
    return Database::fetchAll(report_inventory_device_sql());
}

function report_inventory_device_sql(): string
{
    return "SELECT
            d.device_id,
            d.label,
            d.hostname,
            d.device_type,
            d.status,
            d.manufacturer,
            d.model,
            d.serial_no,
            d.asset_tag,
            d.primary_ip,
            d.mgmt_ip,
            d.idrac_host,
            s.name AS site_name,
            dc.name AS datacenter_name,
            rm.name AS room_name,
            rw.name AS row_name,
            c.name AS cabinet_name,
            d.position_u,
            d.u_height,
            d.half_depth,
            d.back_side,
            parent.label AS parent_label,
            mf.name AS template_manufacturer,
            tpl.model AS template_model,
            tpl.device_type AS template_device_type,
            tpl.u_height AS template_u_height,
            tpl.weight_kg AS template_weight_kg,
            tpl.watts AS template_watts,
            tpl.num_data_ports AS template_data_ports,
            tpl.num_power_ports AS template_power_ports,
            tpl.snmp_template AS template_snmp,
            d.nominal_watts,
            d.weight_kg,
            d.num_data_ports,
            d.num_power_ports,
            d.manufacture_date,
            d.install_date,
            d.warranty_provider,
            d.warranty_end,
            dep.name AS department_name,
            LTRIM(RTRIM(CONCAT(ISNULL(ct.first_name, N''), N' ', ISNULL(ct.last_name, N'')))) AS contact_name,
            d.po_number,
            d.purchase_date,
            d.purchase_vendor,
            d.purchase_cost,
            d.rma_number,
            d.rma_status,
            d.rma_notes,
            d.tags,
            d.notes,
            d.custom_fields,
            d.snmp_version,
            d.snmp_auto_poll,
            prof.name AS snmp_profile_name,
            d.snmp_v3_user,
            d.snmp_v3_sec_level,
            d.snmp_v3_auth_proto,
            d.snmp_v3_priv_proto,
            d.snmp_v3_context,
            d.snmp_engine_id,
            stpl.vendor AS site_template_vendor,
            stpl.model AS site_template_model,
            stpl.name AS site_template_name,
            d.icmp_monitor,
            d.created_at,
            d.updated_at
         FROM devices d
         LEFT JOIN cabinets c ON c.cabinet_id = d.cabinet_id
         LEFT JOIN rooms rm ON rm.room_id = c.room_id
         LEFT JOIN datacenters dc ON dc.datacenter_id = rm.datacenter_id
         LEFT JOIN sites s ON s.site_id = dc.site_id
         LEFT JOIN cabinet_rows rw ON rw.row_id = c.row_id
         LEFT JOIN departments dep ON dep.department_id = d.department_id
         LEFT JOIN contacts ct ON ct.contact_id = d.owner_contact_id
         LEFT JOIN devices parent ON parent.device_id = d.parent_device_id
         LEFT JOIN device_templates tpl ON tpl.template_id = d.template_id
         LEFT JOIN manufacturers mf ON mf.manufacturer_id = tpl.manufacturer_id
         LEFT JOIN snmp_v3_profiles prof ON prof.profile_id = d.snmp_v3_profile_id
         LEFT JOIN snmp_site_oid_templates stpl ON stpl.template_id = d.snmp_site_template_id
         WHERE d.is_active = 1
         ORDER BY
            CASE WHEN s.name IS NULL THEN 1 ELSE 0 END,
            s.name,
            dc.name,
            rm.name,
            c.name,
            CASE WHEN d.position_u IS NULL THEN 1 ELSE 0 END,
            d.position_u,
            d.label";
}

/**
 * Operator columns for Full detail. Keys are display fields, not raw poll columns.
 *
 * @return list<array{key:string,label:string,wrap:bool}>
 */
function report_inventory_columns(): array
{
    $cols = [
        ['device_id', 'Device ID', false],
        ['label', 'Name', false],
        ['hostname', 'Hostname', false],
        ['device_type', 'Type', false],
        ['status', 'Status', false],
        ['manufacturer', 'Manufacturer', false],
        ['model', 'Model', false],
        ['serial_no', 'Serial number', false],
        ['asset_tag', 'Asset tag', false],
        ['primary_ip', 'Primary IP', false],
        ['mgmt_ip', 'Management IP', false],
        ['idrac_host', 'iDRAC', false],
        ['site_name', 'Site', false],
        ['datacenter_name', 'Data center', false],
        ['room_name', 'Room', false],
        ['row_name', 'Row', false],
        ['cabinet_name', 'Cabinet', false],
        ['position_u', 'U position', false],
        ['u_height', 'U height', false],
        ['face', 'Face', false],
        ['depth', 'Depth', false],
        ['parent_label', 'Parent', false],
        ['template', 'Template', false],
        ['nominal_watts', 'Nameplate watts', false],
        ['weight_kg', 'Weight kg', false],
        ['num_data_ports', 'Data ports', false],
        ['num_power_ports', 'Power ports', false],
        ['manufacture_date', 'Manufacture date', false],
        ['install_date', 'Install date', false],
        ['warranty_provider', 'Warranty company', false],
        ['warranty_end', 'Warranty end', false],
        ['department_name', 'Department', false],
        ['contact_name', 'Primary contact', false],
        ['po_number', 'PO number', false],
        ['purchase_date', 'Purchase date', false],
        ['purchase_vendor', 'Purchase vendor', false],
        ['purchase_cost', 'Purchase cost', false],
        ['rma_number', 'RMA number', false],
        ['rma_status', 'RMA status', false],
        ['rma_notes', 'RMA notes', true],
        ['tags', 'Tags', true],
        ['notes', 'Notes', true],
        ['custom_fields', 'Custom fields', true],
        ['snmp_version', 'SNMP version', false],
        ['snmp_poll', 'SNMP poll', false],
        ['snmp_profile_name', 'SNMP v3 profile', false],
        ['snmp_v3_user', 'SNMP v3 user', false],
        ['snmp_v3_sec_level', 'SNMP security', false],
        ['snmp_v3_auth_proto', 'SNMP auth', false],
        ['snmp_v3_priv_proto', 'SNMP priv', false],
        ['snmp_v3_context', 'SNMP context', false],
        ['snmp_engine_id', 'SNMP engine ID', false],
        ['site_template', 'Site OID template', false],
        ['icmp_monitor', 'ICMP monitor', false],
        ['created_at', 'Created', false],
        ['updated_at', 'Updated', false],
    ];
    $out = [];
    foreach ($cols as $col) {
        $out[] = ['key' => $col[0], 'label' => $col[1], 'wrap' => $col[2]];
    }
    return $out;
}

/** Display text for one full-detail cell. Blank means the field is empty. */
function report_inventory_cell(array $row, string $key): string
{
    return match ($key) {
        'device_id' => report_inventory_whole($row['device_id'] ?? null),
        'position_u' => report_inventory_whole($row['position_u'] ?? null),
        'u_height' => report_inventory_whole(report_inventory_first($row['u_height'] ?? null, $row['template_u_height'] ?? null)),
        'num_data_ports' => report_inventory_whole(report_inventory_first($row['num_data_ports'] ?? null, $row['template_data_ports'] ?? null)),
        'num_power_ports' => report_inventory_whole(report_inventory_first($row['num_power_ports'] ?? null, $row['template_power_ports'] ?? null)),
        'manufacturer' => report_inventory_or($row['manufacturer'] ?? null, $row['template_manufacturer'] ?? null),
        'model' => report_inventory_or($row['model'] ?? null, $row['template_model'] ?? null),
        'device_type' => report_inventory_or($row['device_type'] ?? null, $row['template_device_type'] ?? null),
        'snmp_version' => report_inventory_or($row['snmp_version'] ?? null, $row['template_snmp'] ?? null),
        'face' => report_inventory_on($row['back_side'] ?? null) ? 'Rear' : 'Front',
        'depth' => report_inventory_on($row['half_depth'] ?? null) ? 'Half' : 'Full',
        'template' => report_inventory_pair($row['template_manufacturer'] ?? null, $row['template_model'] ?? null),
        'nominal_watts' => report_inventory_plain_num(report_inventory_first($row['nominal_watts'] ?? null, $row['template_watts'] ?? null)),
        'weight_kg' => report_inventory_plain_num(report_inventory_first($row['weight_kg'] ?? null, $row['template_weight_kg'] ?? null)),
        'manufacture_date', 'install_date', 'warranty_end', 'purchase_date' => report_inventory_day($row[$key] ?? null),
        'purchase_cost' => report_inventory_money($row['purchase_cost'] ?? null),
        'custom_fields' => report_inventory_custom($row['custom_fields'] ?? null),
        'snmp_poll' => report_inventory_yes($row['snmp_auto_poll'] ?? null),
        'site_template' => report_inventory_site_template($row),
        'icmp_monitor' => report_inventory_yes($row['icmp_monitor'] ?? null),
        'created_at', 'updated_at' => report_inventory_stamp($row[$key] ?? null),
        default => report_inventory_scalar($row[$key] ?? null),
    };
}

/** Device value when it is set. Otherwise the linked template value. */
function report_inventory_first(mixed $own, mixed $fallback): mixed
{
    if (report_inventory_scalar($own) !== '') {
        return $own;
    }
    return $fallback;
}

function report_inventory_or(mixed $own, mixed $fallback): string
{
    return report_inventory_scalar(report_inventory_first($own, $fallback));
}

function report_inventory_scalar(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_resource($value)) {
        $read = stream_get_contents($value);
        $value = $read === false ? '' : $read;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if ($value === null) {
        return '';
    }
    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }
    return trim((string)$value);
}

function report_inventory_on(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int)$value === 1;
    }
    $s = strtolower(report_inventory_scalar($value));
    return in_array($s, ['1', 'true', 'yes'], true);
}

function report_inventory_yes(mixed $value): string
{
    return report_inventory_on($value) ? 'Yes' : 'No';
}

function report_inventory_whole(mixed $value): string
{
    $s = report_inventory_scalar($value);
    if ($s === '' || !is_numeric($s)) {
        return '';
    }
    return (string)(int)round((float)$s);
}

function report_inventory_plain_num(mixed $value): string
{
    $s = report_inventory_scalar($value);
    if ($s === '' || !is_numeric($s)) {
        return '';
    }
    $formatted = rtrim(rtrim(number_format((float)$s, 4, '.', ''), '0'), '.');
    if ($formatted === '' || $formatted === '-') {
        return '0';
    }
    return $formatted;
}

function report_inventory_money(mixed $value): string
{
    $s = report_inventory_scalar($value);
    if ($s === '' || !is_numeric($s)) {
        return '';
    }
    return number_format((float)$s, 2, '.', '');
}

function report_inventory_day(mixed $value): string
{
    $s = report_inventory_scalar($value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
        return $m[1];
    }
    return $s;
}

function report_inventory_stamp(mixed $value): string
{
    $s = report_inventory_scalar($value);
    return preg_replace('/\.\d+$/', '', $s) ?? $s;
}

function report_inventory_pair(mixed $left, mixed $right): string
{
    return trim(report_inventory_scalar($left) . ' ' . report_inventory_scalar($right));
}

function report_inventory_site_template(array $row): string
{
    $vendor = report_inventory_scalar($row['site_template_vendor'] ?? null);
    $model = report_inventory_scalar($row['site_template_model'] ?? null);
    $label = trim($vendor . ($vendor !== '' && $model !== '' ? ' ' : '') . $model);
    if ($label === '') {
        $label = report_inventory_scalar($row['site_template_name'] ?? null);
    }
    return $label;
}

function report_inventory_custom(mixed $value): string
{
    $text = report_inventory_scalar($value);
    if ($text === '') {
        return '';
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return $text;
    }
    $parts = [];
    foreach ($decoded as $key => $item) {
        $label = is_int($key) ? (string)($key + 1) : trim((string)$key);
        if ($label === '') {
            continue;
        }
        if (is_array($item)) {
            $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $itemText = $encoded === false ? '' : $encoded;
        } elseif (is_bool($item)) {
            $itemText = $item ? 'Yes' : 'No';
        } elseif ($item === null) {
            $itemText = '';
        } else {
            $itemText = trim((string)$item);
        }
        $parts[] = $label . ': ' . $itemText;
    }
    return implode('; ', $parts);
}
