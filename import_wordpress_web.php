<?php
ini_set('display_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';

if (!function_exists('require_admin')) {
    function require_admin() {
        if (empty($_SESSION['admin_id'])) {
            header('Location: admin.php?route=login');
            exit;
        }
    }
}

require_admin();
require_super_admin();

$message = null;
$details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF');
    }
    $file = $_FILES['import_file'];
    if (!is_uploaded_file($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $message = ['type' => 'error', 'text' => 'Falha no upload do arquivo.'];
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $pdo = db();
        if ($ext === 'xml') {
            $imported = import_xml_orders($file['tmp_name'], $pdo, $details);
            $message = ['type' => 'success', 'text' => "Importação concluída (XML). Pedidos importados: {$imported}.", 'details' => $details];
        } elseif ($ext === 'csv') {
            $imported = import_csv_orders($file['tmp_name'], $pdo, $details);
            $message = ['type' => 'success', 'text' => "Importação concluída (CSV). Pedidos importados: {$imported}.", 'details' => $details];
        } else {
            $message = ['type' => 'error', 'text' => 'Formato não suportado. Envie XML ou CSV.'];
        }
    }
}

admin_header('Importar pedidos WordPress');
?>
<section class="space-y-4">
  <div class="card p-5">
    <div class="card-title">Importar pedidos da plataforma antiga</div>
    <p class="text-sm text-gray-500 mb-4">
      Envie o arquivo exportado (XML ou CSV) do WordPress/WooCommerce. Apenas pedidos com status <strong>Processing</strong> serão importados.
      Todos serão marcados como "Plataforma antiga" e ficarão destacados na listagem.
    </p>
    <?php if ($message): ?>
      <div class="alert <?= $message['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>">
        <i class="fa-solid <?= $message['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?> mr-2"></i>
        <?= htmlspecialchars($message['text']); ?>
      </div>
      <?php if (!empty($message['details'])): ?>
        <div class="bg-gray-50 border border-gray-200 rounded p-3 space-y-1 text-sm text-gray-700">
          <?php foreach ($message['details'] as $detail): ?>
            <div>• <?= htmlspecialchars($detail); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="space-y-4 mt-4">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input class="block w-full text-sm" type="file" name="import_file" accept=".xml,.csv" required>
      <p class="text-xs text-gray-500">Formatos aceitos: XML (WordPress/WooCommerce) ou CSV.</p>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-file-import mr-2"></i>Importar pedidos</button>
    </form>
  </div>
</section>
<?php admin_footer();
function import_csv_orders(string $path, PDO $pdo, array &$details): int {
    $handle = fopen($path, 'r');
    if (!$handle) {
        $details[] = 'Não foi possível abrir o CSV.';
        return 0;
    }
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        $details[] = 'CSV vazio.';
        return 0;
    }
    $headers = array_map('trim', $headers);
    $map = array_flip($headers);
    $required = ['id','status','total','currency','email'];
    foreach ($required as $req) {
        if (!isset($map[$req])) {
            fclose($handle);
            $details[] = 'CSV precisa das colunas: '.implode(',', $required);
            return 0;
        }
    }
    $imported = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $status = strtolower(trim($row[$map['status']] ?? ''));
        if ($status !== 'wc-processing' && $status !== 'processing') {
            continue;
        }
        $postId = trim($row[$map['id']] ?? '');
        $total = (float)($row[$map['total']] ?? 0);
        $currency = strtoupper(trim($row[$map['currency']] ?? 'USD'));
        $email = trim($row[$map['email']] ?? '');
        $name = trim($row[$map['name']] ?? ('Cliente CSV '.$postId));
        $phone = trim($row[$map['phone']] ?? '');
        $city = trim($row[$map['city']] ?? '');
        $state = trim($row[$map['state']] ?? '');
        $zip = trim($row[$map['zip']] ?? '');
        $country = trim($row[$map['country']] ?? '');
        $address1 = trim($row[$map['address1']] ?? '');
        $address2 = trim($row[$map['address2']] ?? '');
        $createdAt = trim($row[$map['created_at']] ?? '') ?: date('Y-m-d H:i:s');
        $itemsPlaceholder = [[
            'name' => 'Pedido importado CSV #'.$postId,
            'sku' => '',
            'qty' => 1,
            'price' => $total,
            'currency' => $currency
        ]];
        try {
            $pdo->beginTransaction();
            $customerStmt = $pdo->prepare("INSERT INTO customers (name, email, phone, address, address2, city, state, zipcode, country, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $customerStmt->execute([
                $name,
                $email,
                $phone,
                $address1,
                $address2,
                $city,
                $state,
                $zip,
                $country,
                $createdAt
            ]);
            $customerId = (int)$pdo->lastInsertId();
            $orderStmt = $pdo->prepare("INSERT INTO orders (customer_id, items_json, subtotal, shipping_cost, total, currency, payment_method, payment_ref, payment_status, status, delivery_method_code, delivery_method_label, delivery_method_details, notes, track_token, order_origin, order_code, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $orderStmt->execute([
                $customerId,
                json_encode($itemsPlaceholder, JSON_UNESCAPED_UNICODE),
                $total,
                0,
                $total,
                $currency,
                'Plataforma antiga',
                'CSV '.$postId,
                'processing',
                'processing',
                'legacy',
                'Plataforma antiga',
                'Importado CSV',
                '',
                bin2hex(random_bytes(12)),
                'plataforma_antiga',
                'CSV'.$postId,
                $createdAt,
                $createdAt
            ]);
            $pdo->commit();
            $imported++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $details[] = 'Linha com ID '.$postId.' falhou: '.$e->getMessage();
        }
    }
    fclose($handle);
    return $imported;
}
function import_xml_orders(string $path, PDO $pdo, array &$details): int {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) {
        $errs = [];
        foreach (libxml_get_errors() as $err) {
            $errs[] = $err->message;
        }
        $details[] = 'XML inválido: '.implode(' | ', $errs);
        return 0;
    }
    $namespaces = $xml->getDocNamespaces(true);
    $channel = $xml->channel ?? null;
    if (!$channel) {
        $details[] = 'Estrutura inesperada do XML.';
        return 0;
    }
    $imported = 0;
    foreach ($channel->item as $item) {
        $wp = $item->children($namespaces['wp'] ?? null);
        if ((string)$wp->post_type !== 'shop_order') {
            continue;
        }
        $postStatus = (string)$wp->status;
        if ($postStatus !== 'wc-processing') {
            continue;
        }
        $postId = (int)$wp->post_id;
        $createdAt = (string)$wp->post_date_gmt ?: (string)$wp->post_date;
        $createdAt = $createdAt ?: date('Y-m-d H:i:s');
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
        $itemsPlaceholder = [[
            'name' => 'Pedido importado #'.$postId,
            'sku' => '',
            'qty' => 1,
            'price' => $total,
            'currency' => $currency
        ]];
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
                'Plataforma antiga',
                'WP '.$postId,
                'processing',
                'processing',
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
            $details[] = 'Pedido '.$postId.' falhou: '.$e->getMessage();
        }
    }
    return $imported;
}
