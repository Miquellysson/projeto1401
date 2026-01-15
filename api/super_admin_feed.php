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

$commissionStart = trim((string)($_GET['commission_start'] ?? ''));
$commissionEnd = trim((string)($_GET['commission_end'] ?? ''));
$startDate = null;
$endDate = null;

if ($commissionStart !== '') {
    $startDate = DateTime::createFromFormat('Y-m-d', $commissionStart) ?: null;
    $commissionStart = $startDate ? $startDate->format('Y-m-d') : '';
}
if ($commissionEnd !== '') {
    $endDate = DateTime::createFromFormat('Y-m-d', $commissionEnd) ?: null;
    $commissionEnd = $endDate ? $endDate->format('Y-m-d') : '';
}
if ($startDate && $endDate && $endDate < $startDate) {
    [$commissionStart, $commissionEnd] = [$commissionEnd, $commissionStart];
}

$summary = admin_app_fetch_summary(
    $pdo,
    $commissionStart !== '' ? $commissionStart : null,
    $commissionEnd !== '' ? $commissionEnd : null
);
$control = super_admin_fetch_control_room(
    $pdo,
    $commissionStart !== '' ? $commissionStart : null,
    $commissionEnd !== '' ? $commissionEnd : null
);
$alerts  = admin_app_fetch_alerts(
    $pdo,
    $commissionStart !== '' ? $commissionStart : null,
    $commissionEnd !== '' ? $commissionEnd : null
);
$health  = admin_app_fetch_system_health(
    $pdo,
    $commissionStart !== '' ? $commissionStart : null,
    $commissionEnd !== '' ? $commissionEnd : null
);

echo json_encode([
    'ok'          => true,
    'server_time' => date(DATE_ATOM),
    'summary'     => $summary,
    'control'     => $control,
    'alerts'      => $alerts,
    'health'      => $health,
]);
