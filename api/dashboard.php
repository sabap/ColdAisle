<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

App::json([
    'metrics' => [
        'cabinets' => (int) Database::fetchValue('SELECT COUNT(*) FROM cabinets WHERE is_active = 1'),
        'devices' => (int) Database::fetchValue("SELECT COUNT(*) FROM devices WHERE is_active = 1 AND status <> 'disposed'"),
        'pdus' => (int) Database::fetchValue('SELECT COUNT(*) FROM pdus WHERE is_active = 1'),
        'open_disposals' => (int) Database::fetchValue("SELECT COUNT(*) FROM disposals WHERE status IN ('pending','approved','in_progress')"),
        'u_used' => (int) Database::fetchValue('SELECT ISNULL(SUM(u_height),0) FROM devices WHERE is_active = 1 AND position_u IS NOT NULL'),
        'u_total' => (int) Database::fetchValue('SELECT ISNULL(SUM(u_height),0) FROM cabinets WHERE is_active = 1'),
        'power_kw' => (float) Database::fetchValue('SELECT ISNULL(SUM(last_poll_watts),0)/1000.0 FROM pdus WHERE is_active = 1'),
    ],
    'cabinets' => Database::fetchAll(
        'SELECT c.cabinet_id, c.name, c.pos_x, c.pos_y, c.pos_z, c.rotation_deg,
                c.u_height, c.width_mm, c.depth_mm, c.color_hex
         FROM cabinets c WHERE c.is_active = 1'
    ),
]);
