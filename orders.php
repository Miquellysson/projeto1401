<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';
require __DIR__.'/lib/order_helpers.php';

if (!function_exists('require_admin')){
  function require_admin(){
    if (empty($_SESSION['admin_id'])) {
      header('Location: admin.php?route=login'); exit;
    }
  }
}
if (!function_exists('csrf_token')){
  function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_check')){
  function csrf_check($t){ $t=(string)$t; return !empty($t) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
}
if (!function_exists('sanitize_string')){
  function sanitize_string($s,$max=255){ $s=trim((string)$s); if (strlen($s)>$max) $s=substr($s,0,$max); return $s; }
}
if (!function_exists('validate_email')){
  function validate_email($e){ return (bool)filter_var($e,FILTER_VALIDATE_EMAIL); }
}
$pdo = db();
require_admin();

$action = $_GET['action'] ?? 'list';
$canManageOrders = admin_can('manage_orders');
if ($action === 'update_status' && !$canManageOrders) {
  require_admin_capability('manage_orders');
}
$isSuperAdmin = is_super_admin();

function orders_flash(string $type, string $message): void {
  $_SESSION['orders_flash'] = ['type' => $type, 'message' => $message];
}

function orders_take_flash(): ?array {
  $flash = $_SESSION['orders_flash'] ?? null;
  unset($_SESSION['orders_flash']);
  return $flash;
}

function orders_table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) {
    return $cache[$table];
  }
  if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
    return $cache[$table] = [];
  }
  try {
    $stmt = $pdo->query('SHOW COLUMNS FROM `'.$table.'`');
    $cols = [];
    if ($stmt) {
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['Field'])) {
          $cols[] = $row['Field'];
        }
      }
    }
    return $cache[$table] = $cols;
  } catch (Throwable $e) {
    return $cache[$table] = [];
  }
}

function status_badge($s){
  if ($s==='paid') return '<span class="badge ok">Pago</span>';
  if ($s==='pending') return '<span class="badge warn">Pendente</span>';
  if ($s==='shipped') return '<span class="badge ok">Enviado</span>';
  if ($s==='canceled') return '<span class="badge danger">Cancelado</span>';
  return '<span class="badge">'.sanitize_html($s).'</span>';
}

function orders_parse_money($value): float {
  $value = preg_replace('/[^\d,\.\-]/', '', (string)$value);
  if ($value === '' || $value === null) {
    return 0.0;
  }
  $commaCount = substr_count($value, ',');
  $dotCount = substr_count($value, '.');
  if ($commaCount > 0 && $dotCount > 0) {
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
  } elseif ($commaCount > 0 && $dotCount === 0) {
    $value = str_replace(',', '.', $value);
  } else {
    $value = str_replace(',', '', $value);
  }
  return (float)$value;
}

function orders_slug(string $text): string {
  $text = strtolower(trim($text));
  $text = preg_replace('/[^a-z0-9]+/', '-', $text);
  return trim($text, '-') ?: 'manual';
}

function orders_fetch_payment_methods(PDO $pdo): array {
  try {
    $stmt = $pdo->query("SELECT code, name FROM payment_methods ORDER BY sort_order ASC, name ASC");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    return [];
  }
}

function orders_store_manual_form(array $data): void {
  $_SESSION['manual_order_form'] = $data;
}

function orders_take_manual_form(): array {
  $data = $_SESSION['manual_order_form'] ?? [];
  unset($_SESSION['manual_order_form']);
  return $data;
}

if ($action === 'export') {
  if (!$isSuperAdmin) {
    require_super_admin();
  }
  $orderColumns = orders_table_columns($pdo, 'orders');
  if (!$orderColumns) {
    orders_flash('error', 'Não foi possível localizar as colunas da tabela de pedidos.');
    header('Location: orders.php');
    exit;
  }
  $quotedOrderColumns = array_map(fn($col) => '`'.$col.'`', $orderColumns);
  $ordersStmt = $pdo->query('SELECT '.implode(',', $quotedOrderColumns).' FROM orders ORDER BY id DESC');
  $ordersList = $ordersStmt ? $ordersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
  $customerIds = [];
  foreach ($ordersList as $row) {
    if (isset($row['customer_id'])) {
      $customerIds[] = (int)$row['customer_id'];
    }
  }
  $customerIds = array_values(array_unique(array_filter($customerIds)));
  $customersList = [];
  if ($customerIds) {
    $customerColumns = orders_table_columns($pdo, 'customers');
    if ($customerColumns) {
      $quotedCustomerColumns = array_map(fn($col) => '`'.$col.'`', $customerColumns);
      $in = implode(',', array_fill(0, count($customerIds), '?'));
      $custStmt = $pdo->prepare('SELECT '.implode(',', $quotedCustomerColumns).' FROM customers WHERE id IN ('.$in.')');
      $custStmt->execute($customerIds);
      $customersList = $custStmt->fetchAll(PDO::FETCH_ASSOC);
    }
  }
  $payload = [
    'generated_at' => date('c'),
    'orders_count' => count($ordersList),
    'customers_count' => count($customersList),
    'orders' => $ordersList,
    'customers' => $customersList,
  ];
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="pedidos-'.date('Ymd_His').'.json"');
  echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$isSuperAdmin) {
    require_super_admin();
  }
  if (!csrf_check($_POST['csrf'] ?? '')) {
    die('CSRF');
  }
  if (!isset($_FILES['orders_file']) || !is_uploaded_file($_FILES['orders_file']['tmp_name']) || $_FILES['orders_file']['error'] !== UPLOAD_ERR_OK) {
    orders_flash('error', 'Envie um arquivo JSON válido exportado anteriormente.');
    header('Location: orders.php?action=import');
    exit;
  }
  $raw = file_get_contents($_FILES['orders_file']['tmp_name']);
  if ($raw === false || trim($raw) === '') {
    orders_flash('error', 'Arquivo vazio ou ilegível.');
    header('Location: orders.php?action=import');
    exit;
  }
  $payload = json_decode($raw, true);
  if (!is_array($payload)) {
    orders_flash('error', 'Conteúdo inválido. Verifique se o arquivo é JSON.');
    header('Location: orders.php?action=import');
    exit;
  }
  $ordersInput = $payload['orders'] ?? [];
  $customersInput = $payload['customers'] ?? [];
  if (!is_array($ordersInput)) {
    orders_flash('error', 'Estrutura de pedidos inválida.');
    header('Location: orders.php?action=import');
    exit;
  }
  if ($customersInput !== null && !is_array($customersInput)) {
    orders_flash('error', 'Estrutura de clientes inválida.');
    header('Location: orders.php?action=import');
    exit;
  }

  $customerColumns = orders_table_columns($pdo, 'customers');
  $orderColumns = orders_table_columns($pdo, 'orders');
  if (!$orderColumns) {
    orders_flash('error', 'Tabela de pedidos indisponível.');
    header('Location: orders.php');
    exit;
  }
  if ($customerColumns && !in_array('id', $customerColumns, true)) {
    $customerColumns = [];
  }
  if ($orderColumns && !in_array('id', $orderColumns, true)) {
    $orderColumns = [];
  }

  $customersImported = 0;
  $ordersImported = 0;

  try {
    $pdo->beginTransaction();

    if ($customerColumns && $customersInput) {
      $customerColsQuoted = array_map(fn($col) => '`'.$col.'`', $customerColumns);
      $customerPlaceholders = implode(',', array_fill(0, count($customerColumns), '?'));
      $customerUpdates = [];
      foreach ($customerColumns as $col) {
        if ($col === 'id') {
          continue;
        }
        $customerUpdates[] = '`'.$col.'`=VALUES(`'.$col.'`)';
      }
      if (!$customerUpdates) {
        $customerUpdates[] = '`id`=`id`';
      }
      $customerSql = 'INSERT INTO customers ('.implode(',', $customerColsQuoted).') VALUES ('.$customerPlaceholders.') ON DUPLICATE KEY UPDATE '.implode(',', $customerUpdates);
      $customerStmt = $pdo->prepare($customerSql);
      foreach ($customersInput as $row) {
        if (!is_array($row) || !array_key_exists('id', $row)) {
          continue;
        }
        $values = [];
        foreach ($customerColumns as $col) {
          $values[] = array_key_exists($col, $row) ? $row[$col] : null;
        }
        $customerStmt->execute($values);
        $customersImported++;
      }
    }

    if ($orderColumns && $ordersInput) {
      $orderColsQuoted = array_map(fn($col) => '`'.$col.'`', $orderColumns);
      $orderPlaceholders = implode(',', array_fill(0, count($orderColumns), '?'));
      $orderUpdates = [];
      foreach ($orderColumns as $col) {
        if ($col === 'id') {
          continue;
        }
        $orderUpdates[] = '`'.$col.'`=VALUES(`'.$col.'`)';
      }
      if (!$orderUpdates) {
        $orderUpdates[] = '`id`=`id`';
      }
      $orderSql = 'INSERT INTO orders ('.implode(',', $orderColsQuoted).') VALUES ('.$orderPlaceholders.') ON DUPLICATE KEY UPDATE '.implode(',', $orderUpdates);
      $orderStmt = $pdo->prepare($orderSql);
      foreach ($ordersInput as $row) {
        if (!is_array($row) || !array_key_exists('id', $row)) {
          continue;
        }
        $values = [];
        foreach ($orderColumns as $col) {
          $values[] = array_key_exists($col, $row) ? $row[$col] : null;
        }
        $orderStmt->execute($values);
        $ordersImported++;
      }
    }

    $pdo->commit();
    orders_flash('success', 'Importação concluída: '.$ordersImported.' pedido(s) e '.$customersImported.' cliente(s) processados.');
    header('Location: orders.php');
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    orders_flash('error', 'Falha ao importar pedidos: '.$e->getMessage());
    header('Location: orders.php?action=import');
    exit;
  }
}

if ($action === 'import') {
  if (!$isSuperAdmin) {
    require_super_admin();
  }
  admin_header('Importar pedidos');
  echo '<div class="card"><div class="card-title">Importar pedidos</div><div class="card-body space-y-4">';
  $flash = orders_take_flash();
  if ($flash) {
    $class = $flash['type'] === 'error' ? 'alert alert-error' : ($flash['type'] === 'warning' ? 'alert alert-warning' : 'alert alert-success');
    $icon = $flash['type'] === 'error' ? 'fa-circle-exclamation' : ($flash['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check');
    echo '<div class="'.$class.'"><i class="fa-solid '.$icon.' mr-2"></i>'.sanitize_html($flash['message']).'</div>';
  }
  echo '<p class="text-sm text-gray-600">Envie o arquivo JSON gerado pela opção <strong>Exportar pedidos</strong>. A importação realiza um upsert (atualiza ou cria) de clientes e pedidos com base no ID.</p>';
  echo '<form method="post" enctype="multipart/form-data" class="space-y-3">';
  echo '  <input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '  <input class="input w-full" type="file" name="orders_file" accept=".json,application/json" required>';
  echo '  <div class="toolbar-actions">';
  echo '    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-file-import mr-2"></i>Importar agora</button>';
  echo '    <a class="btn btn-ghost btn-sm" href="orders.php"><i class="fa-solid fa-arrow-left mr-2"></i>Voltar</a>';
  echo '  </div>';
  echo '</form>';
  echo '</div></div>';
  admin_footer(); exit;
}

if ($action === 'manual_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin_capability('manage_orders');
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $storeCurrency = strtoupper(cfg()['store']['currency'] ?? 'USD');
  $formData = [
    'customer_first_name' => sanitize_string($_POST['customer_first_name'] ?? '', 120),
    'customer_last_name' => sanitize_string($_POST['customer_last_name'] ?? '', 120),
    'customer_email' => sanitize_string($_POST['customer_email'] ?? '', 180),
    'customer_phone' => sanitize_string($_POST['customer_phone'] ?? '', 60),
    'customer_address' => sanitize_string($_POST['customer_address'] ?? '', 255),
    'customer_address2' => sanitize_string($_POST['customer_address2'] ?? '', 255),
    'customer_city' => sanitize_string($_POST['customer_city'] ?? '', 120),
    'customer_state' => sanitize_string($_POST['customer_state'] ?? '', 60),
    'customer_zipcode' => sanitize_string($_POST['customer_zipcode'] ?? '', 40),
    'customer_country' => sanitize_string($_POST['customer_country'] ?? 'US', 80),
    'shipping_cost' => trim((string)($_POST['shipping_cost'] ?? '0')),
    'currency' => strtoupper(sanitize_string($_POST['currency'] ?? $storeCurrency, 8)),
    'payment_method' => sanitize_string($_POST['payment_method'] ?? '', 80),
    'payment_method_label' => sanitize_string($_POST['payment_method_label'] ?? '', 120),
    'payment_ref' => sanitize_string($_POST['payment_ref'] ?? '', 255),
    'payment_status' => sanitize_string($_POST['payment_status'] ?? 'pending', 40),
    'order_status' => sanitize_string($_POST['order_status'] ?? 'pending', 40),
    'delivery_method_code' => sanitize_string($_POST['delivery_method_code'] ?? '', 80),
    'delivery_method_label' => sanitize_string($_POST['delivery_method_label'] ?? '', 120),
    'delivery_method_details' => trim((string)($_POST['delivery_method_details'] ?? '')),
    'order_notes' => trim((string)($_POST['order_notes'] ?? '')),
    'notify_customer' => !empty($_POST['notify_customer']),
    'notify_admin' => !empty($_POST['notify_admin']),
  ];
  if (strlen($formData['delivery_method_details']) > 1000) {
    $formData['delivery_method_details'] = substr($formData['delivery_method_details'], 0, 1000);
  }
  if (strlen($formData['order_notes']) > 2000) {
    $formData['order_notes'] = substr($formData['order_notes'], 0, 2000);
  }
  $itemNames = $_POST['item_name'] ?? [];
  $itemSkus = $_POST['item_sku'] ?? [];
  $itemQty = $_POST['item_qty'] ?? [];
  $itemPrices = $_POST['item_price'] ?? [];
  $formData['items'] = [];
  if (is_array($itemNames)) {
    foreach ($itemNames as $idx => $rawName) {
      $formData['items'][] = [
        'name' => sanitize_string($rawName ?? '', 255),
        'sku' => sanitize_string($itemSkus[$idx] ?? '', 120),
        'qty' => (int)($itemQty[$idx] ?? 1),
        'price' => trim((string)($itemPrices[$idx] ?? '')),
        'currency' => $formData['currency'],
      ];
    }
  }
  orders_store_manual_form($formData);
  $errors = [];
  if ($formData['customer_email'] === '' || !validate_email($formData['customer_email'])) {
    $errors[] = 'Informe um e-mail válido para o cliente.';
  }
  if ($formData['customer_first_name'] === '' && $formData['customer_last_name'] === '') {
    $errors[] = 'Informe ao menos o primeiro nome do cliente.';
  }
  $calcResult = order_helper_calculate_totals($formData['items'], $formData['shipping_cost'], $formData['currency'] ?: $storeCurrency);
  $itemsPrepared = $calcResult['items'];
  $subtotalValue = $calcResult['subtotal'];
  $shippingCostValue = $calcResult['shipping'];
  $totalValue = $calcResult['total'];
  $errors = array_merge($errors, $calcResult['errors']);
  $currency = $formData['currency'] ?: $storeCurrency;
  $allowedPaymentStatuses = ['pending','paid','review','failed','refunded'];
  if (!in_array($formData['payment_status'], $allowedPaymentStatuses, true)) {
    $formData['payment_status'] = 'pending';
  }
  $allowedOrderStatuses = ['pending','paid','processing','shipped','canceled'];
  if (!in_array($formData['order_status'], $allowedOrderStatuses, true)) {
    $formData['order_status'] = $formData['payment_status'] === 'paid' ? 'paid' : 'pending';
  }
  $paymentMethodValue = $formData['payment_method'];
  if ($paymentMethodValue === '__custom') {
    if ($formData['payment_method_label'] === '') {
      $errors[] = 'Informe o nome do método de pagamento personalizado.';
    } else {
      $paymentMethodValue = $formData['payment_method_label'];
    }
  } elseif ($paymentMethodValue === '') {
    $errors[] = 'Selecione um método de pagamento.';
  }
  if ($errors) {
    orders_flash('error', implode(' ', $errors));
    header('Location: orders.php?action=manual');
    exit;
  }
  try {
    $pdo->beginTransaction();
    $customerEmail = $formData['customer_email'];
    $customerId = null;
    if ($customerEmail !== '') {
      $lookup = $pdo->prepare("SELECT id FROM customers WHERE email=? LIMIT 1");
      $lookup->execute([$customerEmail]);
      $customerId = $lookup->fetchColumn() ?: null;
    }
    $customerName = trim($formData['customer_first_name'].' '.$formData['customer_last_name']);
    if ($customerName === '') {
      $customerName = $customerEmail ?: 'Cliente Manual';
    }
    if ($customerId) {
      $upd = $pdo->prepare("UPDATE customers SET name=?, first_name=?, last_name=?, phone=?, address=?, address2=?, city=?, state=?, zipcode=?, country=? WHERE id=?");
      $upd->execute([
        $customerName,
        $formData['customer_first_name'],
        $formData['customer_last_name'],
        $formData['customer_phone'],
        $formData['customer_address'],
        $formData['customer_address2'],
        $formData['customer_city'],
        $formData['customer_state'],
        $formData['customer_zipcode'],
        $formData['customer_country'],
        $customerId
      ]);
    } else {
      $ins = $pdo->prepare("INSERT INTO customers (name, first_name, last_name, email, phone, address, address2, city, state, zipcode, country, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
      $ins->execute([
        $customerName,
        $formData['customer_first_name'],
        $formData['customer_last_name'],
        $customerEmail,
        $formData['customer_phone'],
        $formData['customer_address'],
        $formData['customer_address2'],
        $formData['customer_city'],
        $formData['customer_state'],
        $formData['customer_zipcode'],
        $formData['customer_country']
      ]);
      $customerId = (int)$pdo->lastInsertId();
    }
    $deliveryLabel = $formData['delivery_method_label'] ?: ($formData['delivery_method_code'] ?: 'Entrega manual');
    $deliveryCode = $formData['delivery_method_code'] ? orders_slug($formData['delivery_method_code']) : orders_slug($deliveryLabel);
    $trackToken = bin2hex(random_bytes(12));
    $insOrder = $pdo->prepare("INSERT INTO orders (customer_id, items_json, subtotal, shipping_cost, total, currency, payment_method, payment_ref, payment_status, status, delivery_method_code, delivery_method_label, delivery_method_details, notes, track_token, order_origin, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
    $insOrder->execute([
      $customerId,
      json_encode($itemsPrepared, JSON_UNESCAPED_UNICODE),
      $subtotalValue,
      $shippingCostValue,
      $totalValue,
      $currency,
      $paymentMethodValue,
      $formData['payment_ref'],
      $formData['payment_status'],
      $formData['order_status'],
      $deliveryCode,
      $deliveryLabel,
      $formData['delivery_method_details'],
      $formData['order_notes'],
      $trackToken,
      'nova'
    ]);
    $orderId = (int)$pdo->lastInsertId();
    $orderCode = sprintf('GT%04d', $orderId);
    $upd = $pdo->prepare("UPDATE orders SET order_code = ? WHERE id = ?");
    $upd->execute([$orderCode, $orderId]);
    $pdo->commit();
    unset($_SESSION['manual_order_form']);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    orders_flash('error', 'Falha ao criar pedido: '.$e->getMessage());
    header('Location: orders.php?action=manual');
    exit;
  }
  try {
    if ($formData['notify_customer'] && $formData['customer_email']) {
      send_order_confirmation($orderId, $formData['customer_email']);
    }
    if ($formData['notify_admin']) {
      send_order_admin_alert($orderId);
    }
    if ($formData['notify_customer'] || $formData['notify_admin']) {
      send_order_whatsapp_alerts($orderId, (bool)$formData['notify_customer'], (bool)$formData['notify_admin']);
    }
  } catch (Throwable $e) {
    // Falha de envio não impede a criação do pedido
  }
  orders_flash('success', 'Pedido '.sprintf('GT%04d', $orderId).' criado com sucesso.');
  header('Location: orders.php?action=view&id='.$orderId);
  exit;
}

if ($action === 'manual') {
  require_admin_capability('manage_orders');
  $storeCurrency = strtoupper(cfg()['store']['currency'] ?? 'USD');
  $formData = orders_take_manual_form();
  $defaults = [
    'customer_first_name' => '',
    'customer_last_name' => '',
    'customer_email' => '',
    'customer_phone' => '',
    'customer_address' => '',
    'customer_address2' => '',
    'customer_city' => '',
    'customer_state' => '',
    'customer_zipcode' => '',
    'customer_country' => 'Estados Unidos',
    'shipping_cost' => '0.00',
    'currency' => $storeCurrency,
    'payment_method' => '',
    'payment_method_label' => '',
    'payment_ref' => '',
    'payment_status' => 'pending',
    'order_status' => 'pending',
    'delivery_method_code' => 'manual',
    'delivery_method_label' => 'Entrega manual',
    'delivery_method_details' => '',
    'order_notes' => '',
    'notify_customer' => true,
    'notify_admin' => true,
    'items' => [
      ['name' => '', 'sku' => '', 'qty' => 1, 'price' => '', 'currency' => $storeCurrency]
    ],
  ];
  $formData = array_replace($defaults, is_array($formData) ? $formData : []);
  $formData['notify_customer'] = !empty($formData['notify_customer']);
  $formData['notify_admin'] = !empty($formData['notify_admin']);
  if (empty($formData['items']) || !is_array($formData['items'])) {
    $formData['items'] = $defaults['items'];
  }
  $formData['items'] = array_values(array_map(function ($item) use ($formData) {
    return [
      'name' => sanitize_string($item['name'] ?? '', 255),
      'sku' => sanitize_string($item['sku'] ?? '', 120),
      'qty' => (int)($item['qty'] ?? 1) ?: 1,
      'price' => trim((string)($item['price'] ?? '')),
      'currency' => $formData['currency'],
    ];
  }, $formData['items']));
  $paymentMethods = orders_fetch_payment_methods($pdo);
  $currencyOptions = array_values(array_unique(array_filter([$storeCurrency, 'USD', 'BRL', $formData['currency'] ?? $storeCurrency])));
  admin_header('Pedido interno');
  $flash = orders_take_flash();
  if ($flash) {
    $class = $flash['type'] === 'error' ? 'alert alert-error' : ($flash['type'] === 'warning' ? 'alert alert-warning' : 'alert alert-success');
    $icon = $flash['type'] === 'error' ? 'fa-circle-exclamation' : ($flash['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check');
    echo '<div class="'.$class.' mb-4"><i class="fa-solid '.$icon.' mr-2"></i>'.sanitize_html($flash['message']).'</div>';
  }
  echo '<form method="post" class="space-y-4" action="orders.php?action=manual_save">';
  echo '<input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '<div class="card"><div class="card-title">Dados do cliente</div><div class="p-4 grid md:grid-cols-2 gap-4">';
  echo '  <div><label class="block text-sm font-medium mb-1">Nome</label><input class="input w-full" name="customer_first_name" value="'.sanitize_html($formData['customer_first_name']).'" required></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Sobrenome</label><input class="input w-full" name="customer_last_name" value="'.sanitize_html($formData['customer_last_name']).'" required></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">E-mail</label><input class="input w-full" type="email" name="customer_email" value="'.sanitize_html($formData['customer_email']).'" required></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Telefone</label><input class="input w-full" name="customer_phone" value="'.sanitize_html($formData['customer_phone']).'"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Endereço</label><input class="input w-full" name="customer_address" value="'.sanitize_html($formData['customer_address']).'"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Complemento</label><input class="input w-full" name="customer_address2" value="'.sanitize_html($formData['customer_address2']).'"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Cidade</label><input class="input w-full" name="customer_city" value="'.sanitize_html($formData['customer_city']).'"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Estado</label><input class="input w-full" name="customer_state" value="'.sanitize_html($formData['customer_state']).'"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">CEP</label><input class="input w-full" name="customer_zipcode" value="'.sanitize_html($formData['customer_zipcode']).'"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">País</label><input class="input w-full" name="customer_country" value="'.sanitize_html($formData['customer_country']).'"></div>';
  echo '</div></div>';
  echo '<div class="card"><div class="card-title flex items-center justify-between">Itens do pedido<button type="button" class="btn btn-ghost btn-sm" id="add-manual-item"><i class="fa-solid fa-plus mr-1"></i>Adicionar item</button></div><div class="p-4 space-y-3" id="manual-items-body">';
  foreach ($formData['items'] as $item) {
    echo '<div class="manual-item-row grid md:grid-cols-5 gap-3">';
    echo '  <div class="md:col-span-2"><label class="block text-xs font-medium mb-1">Produto</label><input class="input w-full" name="item_name[]" value="'.sanitize_html($item['name']).'" required></div>';
    echo '  <div><label class="block text-xs font-medium mb-1">SKU</label><input class="input w-full" name="item_sku[]" value="'.sanitize_html($item['sku']).'"></div>';
    echo '  <div><label class="block text-xs font-medium mb-1">Qtd</label><input class="input w-full" type="number" min="1" name="item_qty[]" value="'.(int)$item['qty'].'"></div>';
    echo '  <div><label class="block text-xs font-medium mb-1">Preço</label><input class="input w-full" name="item_price[]" value="'.sanitize_html($item['price']).'" placeholder="0.00"><button type="button" class="btn btn-ghost btn-sm mt-2" data-remove-item><i class="fa-solid fa-trash"></i></button></div>';
    echo '</div>';
  }
  echo '</div><p class="text-xs text-gray-500 px-4">Os valores serão convertidos automaticamente para o subtotal.</p></div>';
  echo '<div class="card"><div class="card-title">Pagamento e entrega</div><div class="p-4 grid md:grid-cols-2 gap-4">';
  echo '  <div><label class="block text-sm font-medium mb-1">Moeda do pedido</label><select class="select w-full" name="currency">';
  foreach ($currencyOptions as $cur) {
    $sel = strtoupper($cur) === $formData['currency'] ? 'selected' : '';
    echo '<option value="'.sanitize_html($cur).'" '.$sel.'>'.sanitize_html($cur).'</option>';
  }
  echo '  </select></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Frete</label><input class="input w-full" name="shipping_cost" value="'.sanitize_html($formData['shipping_cost']).'" placeholder="0.00"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Método de pagamento</label><select class="select w-full" name="payment_method">';
  echo '    <option value="">Selecione</option>';
  foreach ($paymentMethods as $pm) {
    $sel = $formData['payment_method'] === $pm['code'] ? 'selected' : '';
    echo '<option value="'.sanitize_html($pm['code']).'" '.$sel.'>'.sanitize_html($pm['name']).'</option>';
  }
  $customSelected = $formData['payment_method'] === '__custom' ? 'selected' : '';
  echo '    <option value="__custom" '.$customSelected.'>Outro (personalizado)</option>';
  echo '  </select>';
  echo '  <div id="custom-payment-method" class="mt-3 '.($customSelected ? '' : 'hidden').'"><label class="block text-xs font-medium mb-1">Nome personalizado</label><input class="input w-full" name="payment_method_label" value="'.sanitize_html($formData['payment_method_label']).'" placeholder="Ex.: Link Square"></div>';
  echo '  </div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Referência (opcional)</label><input class="input w-full" name="payment_ref" value="'.sanitize_html($formData['payment_ref']).'" placeholder="URL ou código"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Status do pagamento</label><select class="select w-full" name="payment_status">';
  $paymentStatusOptions = ['pending'=>'Pendente','paid'=>'Pago','review'=>'Revisão','failed'=>'Falhou','refunded'=>'Reembolsado'];
  foreach ($paymentStatusOptions as $value => $label) {
    $sel = $formData['payment_status'] === $value ? 'selected' : '';
    echo '<option value="'.$value.'" '.$sel.'>'.$label.'</option>';
  }
  echo '</select></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Status do pedido</label><select class="select w-full" name="order_status">';
  $orderStatusOptions = ['pending'=>'Pendente','paid'=>'Pago','processing'=>'Processando','shipped'=>'Enviado','canceled'=>'Cancelado'];
  foreach ($orderStatusOptions as $value => $label) {
    $sel = $formData['order_status'] === $value ? 'selected' : '';
    echo '<option value="'.$value.'" '.$sel.'>'.$label.'</option>';
  }
  echo '</select></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Código da entrega</label><input class="input w-full" name="delivery_method_code" value="'.sanitize_html($formData['delivery_method_code']).'"></div>';
  echo '  <div><label class="block text-sm font-medium mb-1">Nome da entrega</label><input class="input w-full" name="delivery_method_label" value="'.sanitize_html($formData['delivery_method_label']).'"></div>';
  echo '  <div class="md:col-span-2"><label class="block text-sm font-medium mb-1">Detalhes da entrega</label><textarea class="textarea w-full" name="delivery_method_details" rows="3">'.sanitize_html($formData['delivery_method_details']).'</textarea></div>';
  echo '  <div class="md:col-span-2"><label class="block text-sm font-medium mb-1">Notas internas</label><textarea class="textarea w-full" name="order_notes" rows="3">'.sanitize_html($formData['order_notes']).'</textarea></div>';
  echo '  <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="notify_customer" value="1" '.($formData['notify_customer'] ? 'checked' : '').'>Enviar e-mail de confirmação ao cliente</label>';
  echo '  <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="notify_admin" value="1" '.($formData['notify_admin'] ? 'checked' : '').'>Enviar alerta de novo pedido ao admin</label>';
  echo '</div></div>';
  echo '<div class="flex items-center gap-2">';
  echo '  <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar pedido</button>';
  echo '  <a class="btn btn-ghost" href="orders.php"><i class="fa-solid fa-arrow-left mr-2"></i>Cancelar</a>';
  echo '</div>';
  echo '</form>';
  echo '<template id="manual-item-template"><div class="manual-item-row grid md:grid-cols-5 gap-3 mt-3">';
  echo '  <div class="md:col-span-2"><label class="block text-xs font-medium mb-1">Produto</label><input class="input w-full" name="item_name[]" required></div>';
  echo '  <div><label class="block text-xs font-medium mb-1">SKU</label><input class="input w-full" name="item_sku[]"></div>';
  echo '  <div><label class="block text-xs font-medium mb-1">Qtd</label><input class="input w-full" type="number" min="1" name="item_qty[]" value="1"></div>';
  echo '  <div><label class="block text-xs font-medium mb-1">Preço</label><input class="input w-full" name="item_price[]" placeholder="0.00"><button type="button" class="btn btn-ghost btn-sm mt-2" data-remove-item><i class="fa-solid fa-trash"></i></button></div>';
  echo '</div></template>';
  echo '<script>
(function(){
  const itemsBody = document.getElementById("manual-items-body");
  const addBtn = document.getElementById("add-manual-item");
  const template = document.getElementById("manual-item-template");
  if (addBtn && template && itemsBody) {
    addBtn.addEventListener("click", function(){
      const clone = template.content.cloneNode(true);
      itemsBody.appendChild(clone);
    });
    itemsBody.addEventListener("click", function(ev){
      const btn = ev.target.closest("[data-remove-item]");
      if (btn) {
        const row = btn.closest(".manual-item-row");
        if (row && itemsBody.querySelectorAll(".manual-item-row").length > 1) {
          row.remove();
        }
      }
    });
  }
  const paymentSelect = document.querySelector("select[name=\'payment_method\']");
  const customBox = document.getElementById("custom-payment-method");
  function toggleCustom(){
    if (!paymentSelect || !customBox) return;
    customBox.classList.toggle("hidden", paymentSelect.value !== "__custom");
  }
  if (paymentSelect && customBox) {
    paymentSelect.addEventListener("change", toggleCustom);
    toggleCustom();
  }
})();
</script>';
  admin_footer(); exit;
}

if ($action==='view') {
  $id=(int)($_GET['id'] ?? 0);
  $orderColumns = orders_table_columns($pdo, 'orders');
  $hasAffiliateId = in_array('affiliate_id', $orderColumns, true);
  $hasAffiliateCode = in_array('affiliate_code', $orderColumns, true);
  $hasAffiliateTable = false;
  if ($hasAffiliateId) {
    try {
      $affTableCheck = $pdo->query("SHOW TABLES LIKE 'affiliates'");
      $hasAffiliateTable = (bool)($affTableCheck && $affTableCheck->fetchColumn());
    } catch (Throwable $e) {
      $hasAffiliateTable = false;
    }
  }
  $affiliateSelect = '';
  $affiliateJoin = '';
  if ($hasAffiliateId && $hasAffiliateTable) {
    $affiliateSelect = ', a.name AS affiliate_name, a.code AS affiliate_code';
    $affiliateJoin = ' LEFT JOIN affiliates a ON a.id=o.affiliate_id';
  } elseif ($hasAffiliateCode) {
    $affiliateSelect = ', o.affiliate_code';
  }
  $st=$pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.name AS customer, c.email, c.phone, c.address, c.address2, c.city, c.state, c.zipcode, c.country{$affiliateSelect} FROM orders o LEFT JOIN customers c ON c.id=o.customer_id{$affiliateJoin} WHERE o.id=?");
  $st->execute([$id]);
  $o=$st->fetch();
  if (!$o){ header('Location: orders.php'); exit; }
  $displayName = trim(($o['first_name'] ?? '').' '.($o['last_name'] ?? '')) ?: ($o['customer'] ?? '');
  $addressParts = [];
  if (!empty($o['address'])) {
    $addressParts[] = sanitize_html($o['address']);
  }
  if (!empty($o['address2'])) {
    $addressParts[] = sanitize_html($o['address2']);
  }
  $cityStateZip = trim($o['city'] ?? '');
  $stateValue = trim($o['state'] ?? '');
  $zipValue = trim($o['zipcode'] ?? '');
  if ($stateValue !== '') {
    $cityStateZip = $cityStateZip ? $cityStateZip.' / '.$stateValue : $stateValue;
  }
  if ($zipValue !== '') {
    $cityStateZip = $cityStateZip ? $cityStateZip.' — '.$zipValue : $zipValue;
  }
  if ($cityStateZip !== '') {
    $addressParts[] = sanitize_html($cityStateZip);
  }
  if (!empty($o['country'])) {
    $addressParts[] = sanitize_html($o['country']);
  }
  $addressHtml = $addressParts ? implode('<br>', $addressParts) : '—';
  $items = json_decode($o['items_json'] ?? '[]', true) ?: [];
  $orderCurrency = strtoupper($o['currency'] ?? (cfg()['store']['currency'] ?? 'USD'));
  $orderCodeDisplay = !empty($o['order_code']) ? $o['order_code'] : '#'.$id;
  $affiliateLabel = '';
  if (!empty($o['affiliate_name'])) {
    $affiliateLabel = (string)$o['affiliate_name'];
  } elseif (!empty($o['affiliate_code'])) {
    $affiliateLabel = (string)$o['affiliate_code'];
  }
  admin_header('Pedido '.$orderCodeDisplay);

  echo '<div class="grid md:grid-cols-3 gap-3">';
  echo '<div class="card md:col-span-2"><div class="card-title">Itens do pedido</div><div class="p-3 overflow-x-auto">';
  echo '<table class="table"><thead><tr><th>SKU</th><th>Produto</th><th>Qtd</th><th>Preço</th><th>Total</th></tr></thead><tbody>';
  foreach($items as $it){
    $itemCurrency = $it['currency'] ?? $orderCurrency;
    $priceValue = (float)($it['price'] ?? 0);
    $line = $priceValue * (int)$it['qty'];
    echo '<tr>';
    echo '<td>'.sanitize_html($it['sku'] ?? '').'</td>';
    echo '<td>'.sanitize_html($it['name']).'</td>';
    echo '<td>'.(int)$it['qty'].'</td>';
    echo '<td>'.format_currency($priceValue, $itemCurrency).'</td>';
    echo '<td>'.format_currency($line, $itemCurrency).'</td>';
    echo '</tr>';
  }
  echo '</tbody></table></div></div>';

  $deliveryLabel = '';
  $deliveryDetailsText = trim((string)($o['delivery_method_details'] ?? ''));

  echo '<div class="card"><div class="card-title flex justify-between items-center"><span>Resumo</span>';
  if ($isSuperAdmin) {
    echo '<a class="btn btn-ghost btn-sm text-red-600" href="orders.php?action=delete&id='.$id.'&csrf='.csrf_token().'" onclick="return confirm(\'Remover pedido #'.$id.'?\')"><i class="fa-solid fa-trash"></i> Excluir</a>';
  }
  echo '</div><div class="p-3">';
  echo '<div class="mb-2 text-sm text-gray-500">Código: <strong>'.sanitize_html($orderCodeDisplay).'</strong> · Origem: <span class="px-2 py-0.5 rounded bg-gray-100">'.sanitize_html($o['order_origin'] ?? 'nova').'</span></div>';
  echo '<div class="mb-2">Afiliado: <strong>'.($affiliateLabel !== '' ? sanitize_html($affiliateLabel) : '—').'</strong></div>';
  echo '<div class="mb-2">Subtotal: <strong>'.format_currency((float)$o['subtotal'], $orderCurrency).'</strong></div>';
  echo '<div class="mb-2">Frete: <strong>'.format_currency((float)$o['shipping_cost'], $orderCurrency).'</strong></div>';
  if (!empty($o['secure_delivery']) || (!empty($o['secure_delivery_fee']) && (float)$o['secure_delivery_fee'] > 0)) {
    $secureFee = (float)($o['secure_delivery_fee'] ?? 0);
    echo '<div class="mb-2">Entrega segura: <strong>'.($secureFee > 0 ? format_currency($secureFee, $orderCurrency) : 'Sim').'</strong></div>';
  }
  echo '<div class="mb-2">Total: <strong>'.format_currency((float)$o['total'], $orderCurrency).'</strong></div>';
  echo '<div class="mb-2">Pagamento: <strong>'.sanitize_html($o['payment_method']).'</strong></div>';
  if (!empty($o['payment_ref'])) echo '<div class="mb-2">Ref: <a class="text-blue-600 underline" href="'.sanitize_html($o['payment_ref']).'" target="_blank">abrir</a></div>';
  echo '<div class="mb-2">Status: '.status_badge($o['status']).'</div>';
  if (!empty($o['delivery_method_label']) || !empty($o['delivery_method_code'])) {
    $deliveryLabel = trim((string)($o['delivery_method_label'] ?? '')) ?: trim((string)($o['delivery_method_code'] ?? ''));
    echo '<div class="mb-2">Método de entrega: <strong>'.sanitize_html($deliveryLabel).'</strong></div>';
  }
  if ($deliveryDetailsText !== '') {
    echo '<div class="mb-2 text-xs text-gray-600">'.sanitize_html($deliveryDetailsText).'</div>';
  }
  if ($canManageOrders) {
    echo '<form class="mt-3" method="post" action="orders.php?action=update_status&id='.$id.'"><input type="hidden" name="csrf" value="'.csrf_token().'"><select class="select" name="status" required><option value="pending" '.($o['status']==='pending'?'selected':'').'>Pendente</option><option value="paid" '.($o['status']==='paid'?'selected':'').'>Pago</option><option value="shipped" '.($o['status']==='shipped'?'selected':'').'>Enviado</option><option value="canceled" '.($o['status']==='canceled'?'selected':'').'>Cancelado</option></select><button class="btn btn-alt btn-sm ml-2" type="submit"><i class="fa-solid fa-rotate"></i> Atualizar</button></form>';
  } else {
    echo '<div class="text-xs text-gray-500">Você não tem permissão para alterar o status.</div>';
  }
  if (!empty($o['zelle_receipt'])){
    echo '<div class="mt-3"><a class="btn btn-alt btn-sm" href="'.sanitize_html($o['zelle_receipt']).'" target="_blank"><i class="fa-solid fa-file"></i> Ver comprovante</a></div>';
  }
  echo '</div></div>';

  $checkoutFields = [
    'Nome' => $o['first_name'] ?? '',
    'Sobrenome' => $o['last_name'] ?? '',
    'E-mail' => $o['email'] ?? '',
    'Telefone' => $o['phone'] ?? '',
    'Rua e número' => $o['address'] ?? '',
    'Complemento' => $o['address2'] ?? '',
    'Cidade' => $o['city'] ?? '',
    'Estado' => $o['state'] ?? '',
    'CEP' => $o['zipcode'] ?? '',
    'País' => $o['country'] ?? '',
    'Método de entrega' => $deliveryLabel,
    'Detalhes da entrega' => $deliveryDetailsText,
  ];

  echo '<div class="card md:col-span-3"><div class="card-title">Cliente</div><div class="p-3">';
  echo '<div class="grid md:grid-cols-2 gap-3 text-sm">';
  foreach ($checkoutFields as $label => $value) {
    $valueStr = trim((string)$value);
    if ($label === 'E-mail' && $valueStr !== '') {
      $valueHtml = '<a class="text-blue-600 underline" href="mailto:'.sanitize_html($valueStr).'">'.sanitize_html($valueStr).'</a>';
    } else {
      $valueHtml = $valueStr !== '' ? sanitize_html($valueStr) : '—';
    }
    echo '<div><div class="text-xs uppercase tracking-wide text-gray-500">'.sanitize_html($label).'</div><div class="font-medium text-gray-900">'.$valueHtml.'</div></div>';
  }
  echo '</div>';
  echo '</div></div>';

  echo '</div>';
  admin_footer(); exit;
}

if ($action==='update_status' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $id=(int)($_GET['id'] ?? 0);
  $status = sanitize_string($_POST['status'] ?? '');
  $st=$pdo->prepare("UPDATE orders SET status=?, updated_at=NOW() WHERE id=?");
  $st->execute([$status,$id]);
  try{
    $info = $pdo->prepare("SELECT order_code,total,currency FROM orders WHERE id=? LIMIT 1");
    $info->execute([$id]);
    if ($row = $info->fetch(PDO::FETCH_ASSOC)) {
      $orderCode = $row['order_code'] ?: '#'.$id;
      $labels = [
        'pending'  => 'Pendente',
        'paid'     => 'Pago',
        'shipped'  => 'Enviado',
        'canceled' => 'Cancelado',
        'failed'   => 'Falha',
      ];
      $label = $labels[$status] ?? ucfirst($status);
      send_notification(
        'order_status',
        'Status atualizado',
        'Pedido '.$orderCode.' agora está '.$label,
        [
          'order_id'   => $id,
          'order_code' => $orderCode,
          'status'     => $status,
          'total'      => (float)($row['total'] ?? 0),
          'currency'   => strtoupper($row['currency'] ?? (cfg()['store']['currency'] ?? 'USD')),
          'url'        => '/orders.php?action=view&id='.$id,
        ]
      );
    }
  }catch(Throwable $e){}
  header('Location: orders.php?action=view&id='.$id); exit;
}

if ($action==='delete' && $isSuperAdmin) {
  if (!csrf_check($_GET['csrf'] ?? '')) die('CSRF');
  $id=(int)($_GET['id'] ?? 0);
  if ($id > 0) {
    $st=$pdo->prepare("DELETE FROM orders WHERE id=?");
    $st->execute([$id]);
    orders_flash('success', 'Pedido removido com sucesso.');
  }
  header('Location: orders.php');
  exit;
}
if ($action === 'bulk_delete' && $isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $ids = $_POST['ids'] ?? '';
  if (is_array($ids)) {
    $ids = array_map('intval', $ids);
  } else {
    $ids = array_filter(array_map('intval', explode(',', (string)$ids)));
  }
  if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM orders WHERE id IN ($placeholders)");
    $st->execute($ids);
    orders_flash('success', 'Pedidos removidos: '.count($ids));
  } else {
    orders_flash('warning', 'Nenhum pedido selecionado para exclusão.');
  }
  header('Location: orders.php');
  exit;
}

// listagem
admin_header('Pedidos');
echo '<style>.origin-legacy{background:linear-gradient(90deg,rgba(255,245,225,.8),rgba(255,255,255,.5));}</style>';
if (!$canManageOrders) {
  echo '<div class="alert alert-warning mx-auto max-w-4xl mb-4"><i class="fa-solid fa-circle-info mr-2"></i>Alterações de status disponíveis apenas para administradores autorizados.</div>';
}
$flash = orders_take_flash();
if ($flash) {
  $class = $flash['type'] === 'error' ? 'alert alert-error' : ($flash['type'] === 'warning' ? 'alert alert-warning' : 'alert alert-success');
  $icon = $flash['type'] === 'error' ? 'fa-circle-exclamation' : ($flash['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check');
  echo '<div class="'.$class.' mx-auto max-w-4xl mb-4"><i class="fa-solid '.$icon.' mr-2"></i>'.sanitize_html($flash['message']).'</div>';
}
$q = trim((string)($_GET['q'] ?? ''));
$w=' WHERE 1=1 '; $p=[];
if ($q!==''){
  $w .= " AND (c.name LIKE ? OR o.id = ? ) ";
  $p = ["%$q%", (int)$q];
}
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo   = trim((string)($_GET['to'] ?? ''));
if ($dateFrom !== '' && strtotime($dateFrom)) {
  $w .= " AND DATE(o.created_at) >= ? ";
  $p[] = $dateFrom;
}
if ($dateTo !== '' && strtotime($dateTo)) {
  $w .= " AND DATE(o.created_at) <= ? ";
  $p[] = $dateTo;
}
$summaryStats = [
  'total' => 0,
  'paid' => 0,
  'shipped' => 0,
  'pending' => 0,
  'canceled' => 0,
  'other' => 0,
  'by_payment' => []
];
try {
  $summaryStats['total'] = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
  $statusStmt = $pdo->query("SELECT status, COUNT(*) AS qty FROM orders GROUP BY status");
  if ($statusStmt) {
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
      $status = (string)($row['status'] ?? 'other');
      $qty = (int)($row['qty'] ?? 0);
      if (isset($summaryStats[$status])) {
        $summaryStats[$status] += $qty;
      } else {
        $summaryStats['other'] += $qty;
      }
    }
  }
  $payStmt = $pdo->query("SELECT payment_method, COUNT(*) AS qty FROM orders GROUP BY payment_method ORDER BY qty DESC LIMIT 5");
  if ($payStmt) {
    while ($row = $payStmt->fetch(PDO::FETCH_ASSOC)) {
      $method = $row['payment_method'] ?: '—';
      $summaryStats['by_payment'][] = ['method' => $method, 'qty' => (int)($row['qty'] ?? 0)];
    }
  }
} catch (Throwable $e) {
}
$orderColumns = orders_table_columns($pdo, 'orders');
$hasAffiliateId = in_array('affiliate_id', $orderColumns, true);
$hasAffiliateCode = in_array('affiliate_code', $orderColumns, true);
$hasAffiliateTable = false;
if ($hasAffiliateId) {
  try {
    $affTableCheck = $pdo->query("SHOW TABLES LIKE 'affiliates'");
    $hasAffiliateTable = (bool)($affTableCheck && $affTableCheck->fetchColumn());
  } catch (Throwable $e) {
    $hasAffiliateTable = false;
  }
}
$affiliateSelect = '';
$affiliateJoin = '';
if ($hasAffiliateId && $hasAffiliateTable) {
  $affiliateSelect = ', a.name AS affiliate_name, a.code AS affiliate_code';
  $affiliateJoin = ' LEFT JOIN affiliates a ON a.id=o.affiliate_id';
} elseif ($hasAffiliateCode) {
  $affiliateSelect = ', o.affiliate_code';
}
$sql="SELECT o.id,o.order_code,o.order_origin,o.total,o.currency,o.status,o.created_at,c.name AS customer_name{$affiliateSelect} FROM orders o LEFT JOIN customers c ON c.id=o.customer_id{$affiliateJoin} $w ORDER BY o.id DESC LIMIT 200";
$st=$pdo->prepare($sql); $st->execute($p);

echo '<div class="card">';
echo '<div class="p-4 grid md:grid-cols-5 sm:grid-cols-2 gap-3">';
echo '  <div class="mini-card"><div class="text-xs text-gray-500">Pedidos totais</div><div class="text-xl font-bold">'.(int)$summaryStats['total'].'</div></div>';
echo '  <div class="mini-card"><div class="text-xs text-gray-500">Pagos</div><div class="text-xl font-bold">'.(int)$summaryStats['paid'].'</div></div>';
echo '  <div class="mini-card"><div class="text-xs text-gray-500">Enviados</div><div class="text-xl font-bold">'.(int)$summaryStats['shipped'].'</div></div>';
echo '  <div class="mini-card"><div class="text-xs text-gray-500">Pendentes</div><div class="text-xl font-bold">'.(int)$summaryStats['pending'].'</div></div>';
echo '  <div class="mini-card"><div class="text-xs text-gray-500">Cancelados</div><div class="text-xl font-bold">'.(int)$summaryStats['canceled'].'</div></div>';
echo '</div>';
if (!empty($summaryStats['by_payment'])) {
  echo '<div class="px-4 pb-3 text-xs text-gray-600">Top pagamentos: ';
  $parts = [];
  foreach ($summaryStats['by_payment'] as $row) {
    $parts[] = sanitize_html($row['method']).' ('.(int)$row['qty'].')';
  }
  echo implode(' • ', $parts);
  echo '</div>';
}
echo '<div class="card-title">Pedidos</div>';
echo '<div class="card-toolbar">';
echo '  <form method="get" class="search-form">';
echo '    <input class="input" name="q" value="'.sanitize_html($q).'" placeholder="Buscar por cliente ou #id">';
echo '    <input class="input" type="date" name="from" value="'.sanitize_html($dateFrom).'" title="De">';
echo '    <input class="input" type="date" name="to" value="'.sanitize_html($dateTo).'" title="Até">';
echo '    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass mr-2"></i>Buscar</button>';
echo '  </form>';
echo '  <div class="toolbar-actions">';
if ($canManageOrders) {
  echo '    <a class="btn btn-primary btn-sm" href="orders.php?action=manual"><i class="fa-solid fa-plus mr-2"></i>Novo pedido interno</a>';
}
if ($isSuperAdmin) {
  echo '    <a class="btn btn-alt btn-sm" href="orders.php?action=export"><i class="fa-solid fa-file-arrow-down mr-2"></i>Exportar pedidos</a>';
  echo '    <a class="btn btn-ghost btn-sm" href="orders.php?action=import"><i class="fa-solid fa-file-arrow-up mr-2"></i>Importar</a>';
}
echo '  </div>';
echo '</div>';
$ordersList = $st->fetchAll(PDO::FETCH_ASSOC);
echo "<style>
@media (max-width: 900px){
  .orders-table-wrapper{display:none;}
  .orders-cards{display:grid;}
}
@media (min-width: 901px){
  .orders-cards{display:none;}
}
.orders-cards{grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;padding:0 16px 16px;}
.order-card{
  border:1px solid rgba(32,96,200,.12);
  border-radius:18px;
  padding:16px;
  background:#fff;
  box-shadow:0 14px 32px -24px rgba(15,23,42,.35);
  display:flex;
  flex-direction:column;
  gap:10px;
}
.order-card .card-head{display:flex;justify-content:space-between;align-items:center;gap:8px}
.order-card .card-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:13px;color:#475569}
.order-card .card-actions{display:flex;gap:8px;flex-wrap:wrap}
.order-card .card-actions .btn{flex:1;justify-content:center}
@media (max-width:600px){
  .order-card .card-head{flex-direction:column;align-items:flex-start}
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
  const selectAll = document.getElementById('select-all-orders');
  if (selectAll) {
    selectAll.addEventListener('change', function(){
      document.querySelectorAll('.order-check').forEach(function(cb){ cb.checked = selectAll.checked; });
    });
  }
});
</script>";
if ($ordersList) {
  echo '<form id="bulk-delete-form" method="post" action="orders.php?action=bulk_delete">';
  echo '<input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '<input type="hidden" name="ids" id="bulk-ids">';
  echo '<div class="orders-table-wrapper p-3 overflow-x-auto"><table class="table"><thead><tr><th><input type="checkbox" id="select-all-orders" '.(!$isSuperAdmin ? 'disabled' : '').'></th><th>#</th><th>Cliente</th><th>Afiliado</th><th>Total</th><th>Status</th><th>Quando</th><th></th></tr></thead><tbody>';
foreach($ordersList as $r){
  $statusClass = 'status-'.$r['status'];
  $originClass = ($r['order_origin'] ?? '') === 'plataforma_antiga' ? 'origin-legacy' : '';
  $rowClasses = trim($statusClass.' '.$originClass);
  echo '<tr class="'.sanitize_html($rowClasses).'">';
  $orderCodeDisplay = !empty($r['order_code']) ? sanitize_html($r['order_code']) : '#'.(int)$r['id'];
  echo '<td><input type="checkbox" class="order-check" name="ids[]" value="'.(int)$r['id'].'" '.(!$isSuperAdmin ? 'disabled' : '').'></td>';
  echo '<td>'.$orderCodeDisplay;
  if (($r['order_origin'] ?? '') === 'plataforma_antiga') {
    echo '<br><span class="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800">Plataforma antiga</span>';
  }
  echo '</td>';
  echo '<td>'.sanitize_html($r['customer_name']).'</td>';
  $affiliateLabel = '';
  if (!empty($r['affiliate_name'])) {
    $affiliateLabel = (string)$r['affiliate_name'];
  } elseif (!empty($r['affiliate_code'])) {
    $affiliateLabel = (string)$r['affiliate_code'];
  }
  echo '<td>'.($affiliateLabel !== '' ? sanitize_html($affiliateLabel) : '—').'</td>';
  $rowCurrency = strtoupper($r['currency'] ?? (cfg()['store']['currency'] ?? 'USD'));
  echo '<td>'.format_currency((float)$r['total'], $rowCurrency).'</td>';
  echo '<td>'.status_badge($r['status']).'</td>';
  echo '<td>'.sanitize_html($r['created_at'] ?? '').'</td>';
  echo '<td><div class="action-buttons"><a class="btn btn-alt btn-sm" href="orders.php?action=view&id='.(int)$r['id'].'"><i class="fa-solid fa-eye"></i> Ver</a></div></td>';
  echo '</tr>';
}
echo '</tbody></table></div>';
  if ($isSuperAdmin) {
    echo '<div class="p-3 flex items-center gap-2"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'Excluir pedidos selecionados?\')"><i class="fa-solid fa-trash mr-2"></i>Excluir selecionados</button></div>';
  }
  echo '</form>';
} else {
  echo '<div class="p-4 text-sm text-gray-500">Nenhum pedido encontrado.</div>';
}
if ($ordersList) {
  echo '<div class="orders-cards">';
  foreach ($ordersList as $r) {
    $orderCodeDisplay = !empty($r['order_code']) ? sanitize_html($r['order_code']) : '#'.(int)$r['id'];
    $rowCurrency = strtoupper($r['currency'] ?? (cfg()['store']['currency'] ?? 'USD'));
    $amount = format_currency((float)$r['total'], $rowCurrency);
    $affiliateLabel = '';
    if (!empty($r['affiliate_name'])) {
      $affiliateLabel = (string)$r['affiliate_name'];
    } elseif (!empty($r['affiliate_code'])) {
      $affiliateLabel = (string)$r['affiliate_code'];
    }
    echo '<article class="order-card">';
    echo '  <div class="card-head"><div><div class="text-xs text-gray-500">Pedido</div><div class="text-lg font-semibold">'.$orderCodeDisplay.'</div></div><div>'.status_badge($r['status']).'</div></div>';
    echo '  <div class="card-meta"><span><i class="fa-solid fa-user mr-1"></i>'.sanitize_html($r['customer_name'] ?: '-').'</span><span><i class="fa-solid fa-link mr-1"></i>'.($affiliateLabel !== '' ? sanitize_html($affiliateLabel) : '—').'</span><span><i class="fa-solid fa-coins mr-1"></i>'.$amount.'</span><span><i class="fa-solid fa-clock mr-1"></i>'.sanitize_html($r['created_at'] ?? '').'</span></div>';
    echo '  <div class="card-actions"><a class="btn btn-alt btn-sm" href="orders.php?action=view&id='.(int)$r['id'].'"><i class="fa-solid fa-eye"></i> Ver</a></div>';
    echo '</article>';
  }
  echo '</div>';
}
echo '</div>';

admin_footer();

admin_footer();
