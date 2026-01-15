<?php

declare(strict_types=1);


if (!function_exists('admin_app_fetch_summary')) {
    function admin_app_fetch_summary(PDO $pdo, ?string $rangeStart = null, ?string $rangeEnd = null): array
    {
        $rangeStart = $rangeStart ? trim($rangeStart) : null;
        $rangeEnd = $rangeEnd ? trim($rangeEnd) : null;
        $rangeStart = $rangeStart !== '' ? $rangeStart : null;
        $rangeEnd = $rangeEnd !== '' ? $rangeEnd : null;
        $rangeStartTs = $rangeStart ? $rangeStart.' 00:00:00' : null;
        $rangeEndTs = $rangeEnd ? $rangeEnd.' 23:59:59' : null;

        $summary = [
            'pending_orders'      => 0,
            'paid_today'          => 0,
            'revenue_today'       => 0.0,
            'orders_last_hour'    => 0,
            'status_breakdown'    => [],
            'last_sync_iso'       => date(DATE_ATOM),
            'totals'              => [
                'orders'    => 0,
                'customers' => 0,
                'products'  => 0,
                'categories'=> 0,
            ],
        ];

        $todayStart = date('Y-m-d 00:00:00');
        $hourAgo    = date('Y-m-d H:i:s', time() - 3600);

        try {
            if ($rangeStartTs || $rangeEndTs) {
                $sql = "SELECT COUNT(*) FROM orders WHERE status = 'pending'";
                $params = [];
                if ($rangeStartTs) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $rangeEndTs;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $summary['pending_orders'] = (int)$stmt->fetchColumn();
            } else {
                $summary['pending_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($rangeStartTs || $rangeEndTs) {
                $sql = "SELECT COUNT(*) FROM orders WHERE payment_status = 'paid'";
                $params = [];
                if ($rangeStartTs) {
                    $sql .= " AND updated_at >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND updated_at <= ?";
                    $params[] = $rangeEndTs;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $summary['paid_today'] = (int)$stmt->fetchColumn();
            } else {
                $summary['paid_today'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'paid' AND created_at >= ".$pdo->quote($todayStart))->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($rangeStartTs || $rangeEndTs) {
                $sql = "SELECT COUNT(*) FROM orders WHERE 1=1";
                $params = [];
                if ($rangeStartTs) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $rangeEndTs;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $summary['orders_last_hour'] = (int)$stmt->fetchColumn();
            } else {
                $summary['orders_last_hour'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE created_at >= ".$pdo->quote($hourAgo))->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($rangeStartTs || $rangeEndTs) {
                $sql = "SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid'";
                $params = [];
                if ($rangeStartTs) {
                    $sql .= " AND updated_at >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND updated_at <= ?";
                    $params[] = $rangeEndTs;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $summary['revenue_today'] = (float)$stmt->fetchColumn();
            } else {
                $summary['revenue_today'] = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND created_at >= ".$pdo->quote($todayStart))->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($rangeStartTs || $rangeEndTs) {
                $sql = "SELECT status, COUNT(*) AS total FROM orders WHERE 1=1";
                $params = [];
                if ($rangeStartTs) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $rangeEndTs;
                }
                $sql .= " GROUP BY status ORDER BY total DESC LIMIT 5";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->query("SELECT status, COUNT(*) AS total FROM orders GROUP BY status ORDER BY total DESC LIMIT 5");
            }
            if ($stmt) {
                $summary['status_breakdown'] = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $summary['status_breakdown'][] = [
                        'status' => (string)($row['status'] ?? 'unknown'),
                        'total'  => (int)($row['total'] ?? 0),
                    ];
                }
            }
        } catch (Throwable $e) {
        }

        return $summary;
    }
}

if (!function_exists('admin_app_fetch_recent_orders')) {
    function admin_app_fetch_recent_orders(PDO $pdo, int $limit = 12, ?string $rangeStart = null, ?string $rangeEnd = null): array
    {
        $limit = max(1, min(50, $limit));
        $rangeStart = $rangeStart ? trim($rangeStart) : null;
        $rangeEnd = $rangeEnd ? trim($rangeEnd) : null;
        $rangeStart = $rangeStart !== '' ? $rangeStart : null;
        $rangeEnd = $rangeEnd !== '' ? $rangeEnd : null;
        $rangeStartTs = $rangeStart ? $rangeStart.' 00:00:00' : null;
        $rangeEndTs = $rangeEnd ? $rangeEnd.' 23:59:59' : null;
        $orders = [];
        try {
            $sql = "
                SELECT
                    o.id,
                    o.status,
                    o.payment_status,
                    o.total,
                    o.currency,
                    o.order_code,
                    o.created_at,
                    o.updated_at,
                    c.name AS customer_name
                FROM orders o
                LEFT JOIN customers c ON c.id = o.customer_id
            ";
            $params = [];
            if ($rangeStartTs || $rangeEndTs) {
                $sql .= " WHERE 1=1";
                if ($rangeStartTs) {
                    $sql .= " AND COALESCE(o.updated_at, o.created_at) >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND COALESCE(o.updated_at, o.created_at) <= ?";
                    $params[] = $rangeEndTs;
                }
            }
            $sql .= " ORDER BY o.updated_at DESC LIMIT {$limit}";
            if ($params) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->query($sql);
            }
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $orders[] = [
                        'id'             => (int)($row['id'] ?? 0),
                        'order_code'     => (string)($row['order_code'] ?? ''),
                        'status'         => (string)($row['status'] ?? ''),
                        'payment_status' => (string)($row['payment_status'] ?? ''),
                        'customer_name'  => (string)($row['customer_name'] ?? ''),
                        'total'          => (float)($row['total'] ?? 0),
                        'currency'       => strtoupper((string)($row['currency'] ?? (cfg()['store']['currency'] ?? 'USD'))),
                        'created_at'     => (string)($row['created_at'] ?? ''),
                        'updated_at'     => (string)($row['updated_at'] ?? ''),
                        'event_key'      => sprintf('%s-%s', (int)($row['id'] ?? 0), strtotime((string)($row['updated_at'] ?? '')) ?: time()),
                    ];
                }
            }
        } catch (Throwable $e) {
        }
        return $orders;
    }
}

if (!function_exists('admin_app_fetch_alerts')) {
    function admin_app_fetch_alerts(PDO $pdo, ?string $rangeStart = null, ?string $rangeEnd = null): array
    {
        $rangeStart = $rangeStart ? trim($rangeStart) : null;
        $rangeEnd = $rangeEnd ? trim($rangeEnd) : null;
        $rangeStart = $rangeStart !== '' ? $rangeStart : null;
        $rangeEnd = $rangeEnd !== '' ? $rangeEnd : null;
        $rangeStartTs = $rangeStart ? $rangeStart.' 00:00:00' : null;
        $rangeEndTs = $rangeEnd ? $rangeEnd.' 23:59:59' : null;

        $alerts = [
            'pending_overdue' => [],
            'failed_payments' => [],
        ];

        try {
            $threshold = date('Y-m-d H:i:s', time() - 7200); // 2 hours
            $sql = "SELECT id, status, created_at, total, currency FROM orders WHERE status = 'pending' AND created_at < ?";
            $params = [$threshold];
            if ($rangeStartTs) {
                $sql .= " AND created_at >= ?";
                $params[] = $rangeStartTs;
            }
            if ($rangeEndTs) {
                $sql .= " AND created_at <= ?";
                $params[] = $rangeEndTs;
            }
            $sql .= " ORDER BY created_at ASC LIMIT 5";
            $stmt = $pdo->prepare($sql);
            if ($stmt && $stmt->execute($params)) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $alerts['pending_overdue'][] = [
                        'id'         => (int)($row['id'] ?? 0),
                        'status'     => (string)($row['status'] ?? ''),
                        'created_at' => (string)($row['created_at'] ?? ''),
                        'total'      => (float)($row['total'] ?? 0),
                        'currency'   => strtoupper((string)($row['currency'] ?? (cfg()['store']['currency'] ?? 'USD'))),
                    ];
                }
            }
        } catch (Throwable $e) {
        }

        try {
            $sql = "SELECT id, payment_status, updated_at, total, currency FROM orders WHERE payment_status IN ('failed','chargeback','refunded')";
            $params = [];
            if ($rangeStartTs) {
                $sql .= " AND updated_at >= ?";
                $params[] = $rangeStartTs;
            }
            if ($rangeEndTs) {
                $sql .= " AND updated_at <= ?";
                $params[] = $rangeEndTs;
            }
            $sql .= " ORDER BY updated_at DESC LIMIT 5";
            if ($params) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->query($sql);
            }
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $alerts['failed_payments'][] = [
                        'id'             => (int)($row['id'] ?? 0),
                        'payment_status' => (string)($row['payment_status'] ?? ''),
                        'updated_at'     => (string)($row['updated_at'] ?? ''),
                        'total'          => (float)($row['total'] ?? 0),
                        'currency'       => strtoupper((string)($row['currency'] ?? (cfg()['store']['currency'] ?? 'USD'))),
                    ];
                }
            }
        } catch (Throwable $e) {
        }

        return $alerts;
    }
}

if (!function_exists('admin_app_fetch_system_health')) {
    function admin_app_fetch_system_health(PDO $pdo, ?string $rangeStart = null, ?string $rangeEnd = null): array
    {
        $rangeStart = $rangeStart ? trim($rangeStart) : null;
        $rangeEnd = $rangeEnd ? trim($rangeEnd) : null;
        $rangeStart = $rangeStart !== '' ? $rangeStart : null;
        $rangeEnd = $rangeEnd !== '' ? $rangeEnd : null;
        $rangeStartTs = $rangeStart ? $rangeStart.' 00:00:00' : null;
        $rangeEndTs = $rangeEnd ? $rangeEnd.' 23:59:59' : null;

        $health = [
            'errors_last_hour'  => 0,
            'orders_with_error' => 0,
            'disk_free_percent' => null,
            'php_version'       => PHP_VERSION,
            'app_version'       => defined('APP_VERSION') ? APP_VERSION : null,
            'last_migration'    => null,
        ];

        try {
            if ($rangeStartTs || $rangeEndTs) {
                $sql = "SELECT COUNT(*) FROM orders WHERE status IN ('error','failed')";
                $params = [];
                if ($rangeStartTs) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $rangeEndTs;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $health['orders_with_error'] = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('error','failed')");
                if ($stmt) {
                    $health['orders_with_error'] = (int)$stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
        }

        try {
            if ($rangeStartTs || $rangeEndTs) {
                $sql = "SELECT COUNT(*) FROM orders WHERE payment_status = 'failed'";
                $params = [];
                if ($rangeStartTs) {
                    $sql .= " AND updated_at >= ?";
                    $params[] = $rangeStartTs;
                }
                if ($rangeEndTs) {
                    $sql .= " AND updated_at <= ?";
                    $params[] = $rangeEndTs;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $health['errors_last_hour'] = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                if ($stmt) {
                    $health['errors_last_hour'] = (int)$stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
        }

        $root = dirname(__DIR__);
        $diskTotal = @disk_total_space($root);
        $diskFree  = @disk_free_space($root);
        if ($diskTotal && $diskTotal > 0) {
            $health['disk_free_percent'] = round(($diskFree / $diskTotal) * 100, 2);
        }

        try {
            $stmt = $pdo->query("SELECT MAX(applied_at) FROM migrations");
            if ($stmt) {
                $health['last_migration'] = $stmt->fetchColumn() ?: null;
            }
        } catch (Throwable $e) {
        }

        return $health;
    }
}

if (!function_exists('super_admin_fetch_control_room')) {
    function super_admin_fetch_control_room(PDO $pdo, ?string $commissionStart = null, ?string $commissionEnd = null): array
    {
        $commissionStart = $commissionStart ? trim($commissionStart) : null;
        $commissionEnd = $commissionEnd ? trim($commissionEnd) : null;
        $commissionStart = $commissionStart !== '' ? $commissionStart : null;
        $commissionEnd = $commissionEnd !== '' ? $commissionEnd : null;

        $data = [
            'totals' => [
                'orders'    => 0,
                'customers' => 0,
                'products'  => 0,
                'users'     => 0,
            ],
            'orders_by_status' => [],
            'orders_last_24h'  => 0,
            'revenue_last_24h' => 0.0,
            'failed_today'     => 0,
            'active_admins'    => 0,
            'commission_total' => 0.0,
            'commission_period' => [
                'start' => $commissionStart,
                'end'   => $commissionEnd,
            ],
            'stack'            => [
                'php_version' => PHP_VERSION,
                'server'      => php_uname('n'),
                'os'          => PHP_OS,
                'timezone'    => date_default_timezone_get(),
            ],
            'recent_orders'    => admin_app_fetch_recent_orders($pdo, 12, $commissionStart, $commissionEnd),
        ];

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = "SELECT COUNT(*) FROM orders WHERE 1=1";
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['totals']['orders'] = (int)$stmt->fetchColumn();
            } else {
                $data['totals']['orders'] = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = "SELECT COUNT(*) FROM customers WHERE 1=1";
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $data['totals']['customers'] = (int)$stmt->fetchColumn();
                } catch (Throwable $e) {
                    $data['totals']['customers'] = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
                }
            } else {
                $data['totals']['customers'] = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = "SELECT COUNT(*) FROM products WHERE 1=1";
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $data['totals']['products'] = (int)$stmt->fetchColumn();
                } catch (Throwable $e) {
                    $data['totals']['products'] = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
                }
            } else {
                $data['totals']['products'] = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = "SELECT COUNT(*) FROM users WHERE 1=1";
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $data['totals']['users'] = (int)$stmt->fetchColumn();
                } catch (Throwable $e) {
                    $data['totals']['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                }
            } else {
                $data['totals']['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = 'SELECT status, COUNT(*) AS total FROM orders WHERE 1=1';
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                $sql .= ' GROUP BY status ORDER BY total DESC LIMIT 6';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status ORDER BY total DESC LIMIT 6');
            }
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $data['orders_by_status'][] = [
                        'status' => (string)($row['status'] ?? 'unknown'),
                        'total'  => (int)($row['total'] ?? 0),
                    ];
                }
            }
        } catch (Throwable $e) {
        }

        try {
            $sql = "SELECT COUNT(*) FROM orders WHERE (payment_status IN ('paid','shipped') OR status IN ('paid','shipped'))";
            $params = [];
            if ($commissionStart) {
                $sql .= " AND COALESCE(updated_at, created_at) >= ?";
                $params[] = $commissionStart.' 00:00:00';
            }
            if ($commissionEnd) {
                $sql .= " AND COALESCE(updated_at, created_at) <= ?";
                $params[] = $commissionEnd.' 23:59:59';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $paidCount = (int)$stmt->fetchColumn();
            $data['commission_total'] = $paidCount * 10.0;
        } catch (Throwable $e) {
        }

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = 'SELECT COUNT(*) FROM orders WHERE 1=1';
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND created_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND created_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['orders_last_24h'] = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->query('SELECT COUNT(*) FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)');
                if ($stmt) {
                    $data['orders_last_24h'] = (int)$stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
        }

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = "SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid'";
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND updated_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND updated_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['revenue_last_24h'] = (float)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                if ($stmt) {
                    $data['revenue_last_24h'] = (float)$stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
        }

        try {
            if ($commissionStart || $commissionEnd) {
                $sql = "SELECT COUNT(*) FROM orders WHERE payment_status IN ('failed','chargeback')";
                $params = [];
                if ($commissionStart) {
                    $sql .= " AND updated_at >= ?";
                    $params[] = $commissionStart.' 00:00:00';
                }
                if ($commissionEnd) {
                    $sql .= " AND updated_at <= ?";
                    $params[] = $commissionEnd.' 23:59:59';
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $data['failed_today'] = (int)$stmt->fetchColumn();
            } else {
                $stmt = $pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status IN ('failed','chargeback') AND DATE(updated_at) = CURDATE()");
                if ($stmt) {
                    $data['failed_today'] = (int)$stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
        }

        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role IN ('super_admin','admin','manager')");
            if ($stmt) {
                $data['active_admins'] = (int)$stmt->fetchColumn();
            }
        } catch (Throwable $e) {
        }

        return $data;
    }
}
        try {
            $summary['totals']['orders'] = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $summary['totals']['customers'] = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $summary['totals']['products'] = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE active=1")->fetchColumn();
        } catch (Throwable $e) {
        }

        try {
            $summary['totals']['categories'] = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE active=1")->fetchColumn();
        } catch (Throwable $e) {
        }
