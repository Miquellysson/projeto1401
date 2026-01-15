<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require __DIR__.'/../bootstrap.php';
require __DIR__.'/../config.php';
require __DIR__.'/../lib/db.php';
require __DIR__.'/../lib/utils.php';
require __DIR__.'/../lib/admin_app.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

if (!function_exists('require_super_admin')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'missing_super_admin_guard']);
    exit;
}

require_super_admin();

$pdo = db();

$summary = admin_app_fetch_summary($pdo);
$control = super_admin_fetch_control_room($pdo);
$alerts  = admin_app_fetch_alerts($pdo);
$health  = admin_app_fetch_system_health($pdo);

echo json_encode([
    'ok'          => true,
    'server_time' => date(DATE_ATOM),
    'summary'     => $summary,
    'control'     => $control,
    'alerts'      => $alerts,
    'health'      => $health,
]);
