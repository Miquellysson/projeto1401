<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require __DIR__.'/../bootstrap.php';
require __DIR__.'/../config.php';
require __DIR__.'/../lib/db.php';
require __DIR__.'/../lib/utils.php';
require __DIR__.'/../lib/admin_app.php';

header('Content-Type: application/json');

if (!function_exists('admin_app_require_login')) {
    function admin_app_require_login(): void
    {
        if (empty($_SESSION['admin_id'])) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            exit;
        }
    }
}

admin_app_require_login();

$pdo = db();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 15;
$limit = max(5, min(40, $limit));

$response = [
    'ok'          => true,
    'server_time' => date(DATE_ATOM),
    'summary'     => admin_app_fetch_summary($pdo),
    'orders'      => admin_app_fetch_recent_orders($pdo, $limit),
    'alerts'      => admin_app_fetch_alerts($pdo),
];

if (is_super_admin()) {
    $response['health'] = admin_app_fetch_system_health($pdo);
}

echo json_encode($response);
