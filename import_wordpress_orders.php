<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';

if (php_sapi_name() !== 'cli') {
    echo "Execute este script via CLI: php import_wordpress_orders.php /caminho/arquivo.xml\n";
    exit(1);
}

$inputFile = $argv[1] ?? (__DIR__.'/getpowerresearch.WordPress.xml');
if (!is_file($inputFile)) {
    echo "Arquivo não encontrado: {$inputFile}\n";
    exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($inputFile, 'SimpleXMLElement', LIBXML_NOCDATA);
if (!$xml) {
    echo "Falha ao ler XML.\n";
    foreach (libxml_get_errors() as $error) {
        echo $error->message."\n";
    }
    exit(1);
}
$namespaces = $xml->getDocNamespaces(true);
$channel = $xml->channel ?? null;
if (!$channel) {
    echo "Estrutura inesperada.\n";
    exit(1);
}

$pdo = db();
$imported = 0;
foreach ($channel->item as $item) {
    $wp = $item->children($namespaces['wp'] ?? null);
    if ((string)$wp->post_type !== 'shop_order') {
        continue;
    }
    $postId = (int)$wp->post_id;
    $postStatus = (string)$wp->status;
    if ($postStatus !== 'wc-processing') {
        continue; // Import only processing orders
    }
    $createdAt = (string)$wp->post_date_gmt ?: (string)$wp->post_date;
    $createdAt = $createdAt ? $createdAt : date('Y-m-d H:i:s');
    $meta = [];
    foreach ($wp->postmeta as $metaEntry) {
        $key = (string)$metaEntry->meta_key;
        $value = (string)$metaEntry->meta_value;
        $meta[$key] = $value;
    }
    $total = (float)($meta['_order_total'] ?? 0);
    $currency = strtoupper($meta['_order_currency'] ?? 'USD');
    $shipping = (float)($meta['_order_shipping'] ?? 0);
    $billingFirst = trim(($meta['_billing_first_name'] ?? ''));
    $billingLast = trim(($meta['_billing_last_name'] ?? ''));
    $billingName = trim($billingFirst.' '.$billingLast);
    if ($billingName === '') {
        $billingName = $meta['_billing_email'] ?? ('Cliente WP '.$postId);
    }
    $customerEmail = trim($meta['_billing_email'] ?? '');
    $customerPhone = trim($meta['_billing_phone'] ?? '');
    $address1 = trim($meta['_billing_address_1'] ?? '');
    $address2 = trim($meta['_billing_address_2'] ?? '');
    $city = trim($meta['_billing_city'] ?? '');
    $state = trim($meta['_billing_state'] ?? '');
    $postcode = trim($meta['_billing_postcode'] ?? '');
    $country = trim($meta['_billing_country'] ?? '');
    $paymentTitle = trim($meta['_payment_method_title'] ?? 'Plataforma antiga');
    $status = 'pending';
    if ($postStatus === 'wc-completed') {
        $status = 'paid';
    } elseif ($postStatus === 'wc-processing') {
        $status = 'processing';
    } elseif ($postStatus === 'wc-cancelled') {
        $status = 'canceled';
    }
    $itemsPlaceholder = [
        [
            'name' => 'Pedido importado #'.$postId,
            'sku' => '',
            'qty' => 1,
            'price' => $total,
            'currency' => $currency
        ]
    ];
    try {
        $pdo->beginTransaction();
        $customerStmt = $pdo->prepare("INSERT INTO customers (name, first_name, last_name, email, phone, address, address2, city, state, zipcode, country, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $customerStmt->execute([
            $billingName,
            $billingFirst,
            $billingLast,
            $customerEmail,
            $customerPhone,
            $address1,
            $address2,
            $city,
            $state,
            $postcode,
            $country,
            $createdAt
        ]);
        $customerId = (int)$pdo->lastInsertId();
        $orderStmt = $pdo->prepare("INSERT INTO orders (customer_id, items_json, subtotal, shipping_cost, total, currency, payment_method, payment_ref, payment_status, status, delivery_method_code, delivery_method_label, delivery_method_details, notes, track_token, order_origin, order_code, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $orderStmt->execute([
            $customerId,
            json_encode($itemsPlaceholder, JSON_UNESCAPED_UNICODE),
            $total,
            $shipping,
            $total,
            $currency,
            $paymentTitle,
            'WP '.$postId,
            $status === 'paid' ? 'paid' : 'pending',
            $status,
            'legacy',
            'Plataforma antiga',
            'Importado do WordPress',
            '',
            bin2hex(random_bytes(12)),
            'plataforma_antiga',
            'WP'.$postId,
            $createdAt,
            $createdAt
        ]);
        $pdo->commit();
        $imported++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "Falha ao importar pedido {$postId}: ".$e->getMessage()."\n";
    }
}

echo "Importados {$imported} pedidos.\n";
