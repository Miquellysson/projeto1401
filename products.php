<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

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
if (!function_exists('sanitize_html')){
  function sanitize_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('validate_email')){
  function validate_email($e){ return (bool)filter_var($e,FILTER_VALIDATE_EMAIL); }
}

$pdo = db();
require_admin();

ensure_products_schema($pdo);
$storeCurrency = strtoupper(cfg()['store']['currency'] ?? 'USD');

$action = $_GET['action'] ?? 'list';
$canManageProducts = admin_can('manage_products');
$writeActions = ['new','create','edit','update','delete','destroy','bulk_destroy','import'];
if (!$canManageProducts && in_array($action, $writeActions, true)) {
  require_admin_capability('manage_products');
}

$isSuperAdmin = is_super_admin();

/* ========= Helpers ========= */

function products_flash(string $type, string $message): void {
  $_SESSION['products_flash'] = ['type' => $type, 'message' => $message];
}

function products_take_flash(): ?array {
  $flash = $_SESSION['products_flash'] ?? null;
  unset($_SESSION['products_flash']);
  return $flash;
}

function ensure_products_schema(PDO $pdo): void {
  static $checked = false;
  if ($checked) {
    return;
  }
  $checked = true;
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM products");
    $hasPriceCompare = false;
    $hasCurrency = false;
    $hasBadge = false;
    if ($cols) {
      while ($col = $cols->fetch(PDO::FETCH_ASSOC)) {
        if (($col['Field'] ?? '') === 'price_compare') {
          $hasPriceCompare = true;
        }
        if (($col['Field'] ?? '') === 'currency') {
          $hasCurrency = true;
        }
        if (($col['Field'] ?? '') === 'badge_one_month') {
          $hasBadge = true;
        }
      }
    }
    if (!$hasPriceCompare) {
      $pdo->exec("ALTER TABLE products ADD COLUMN price_compare DECIMAL(10,2) NULL AFTER price");
    }
    if (!$hasCurrency) {
      $pdo->exec("ALTER TABLE products ADD COLUMN currency VARCHAR(3) NOT NULL DEFAULT 'USD' AFTER price_compare");
      $defaultCurrency = strtoupper(cfg()['store']['currency'] ?? 'USD');
      $upd = $pdo->prepare("UPDATE products SET currency = ? WHERE currency IS NULL OR currency = ''");
      $upd->execute([$defaultCurrency]);
    }
    if (!$hasBadge) {
      $pdo->exec("ALTER TABLE products ADD COLUMN badge_one_month TINYINT(1) NOT NULL DEFAULT 0 AFTER featured");
    }
  } catch (Throwable $e) {
    // Ignora: se as colunas já existirem ou permissão negada, apenas seguimos sem interromper
  }
}

function product_generate_unique_slug(PDO $pdo, string $candidate, ?int $ignoreId = null): string {
  $base = trim($candidate);
  if ($base === '') {
    $base = bin2hex(random_bytes(4));
  }
  $slug = slugify($base);
  if ($slug === '') {
    $slug = bin2hex(random_bytes(4));
  }
  $checkSql = 'SELECT id FROM products WHERE slug = ?';
  if ($ignoreId) {
    $checkSql .= ' AND id <> ?';
  }
  $checkSql .= ' LIMIT 1';
  $stmt = $pdo->prepare($checkSql);
  $suffix = 2;
  $uniqueSlug = $slug;
  while (true) {
    $params = [$uniqueSlug];
    if ($ignoreId) {
      $params[] = $ignoreId;
    }
    $stmt->execute($params);
    if (!$stmt->fetch()) {
      return $uniqueSlug;
    }
    $uniqueSlug = $slug.'-'.$suffix;
    $suffix++;
  }
}

function product_parse_specs_text(string $input): array {
  $specs = [];
  foreach (preg_split('/\r?\n/', $input) as $line) {
    $line = trim($line);
    if ($line === '') {
      continue;
    }
    $parts = preg_split('/\s*[\|:\-]{1}\s*/', $line, 2);
    if (!$parts || count($parts) < 2) {
      $parts = explode('=', $line, 2);
      if (count($parts) < 2) {
        $specs[] = ['label' => $line, 'value' => ''];
        continue;
      }
    }
    $label = trim($parts[0]);
    $value = trim($parts[1]);
    if ($label === '' && $value === '') {
      continue;
    }
    $specs[] = ['label' => $label, 'value' => $value];
  }
  return $specs;
}

function product_specs_to_text(array $specs): string {
  if (!$specs) {
    return '';
  }
  $lines = [];
  foreach ($specs as $entry) {
    if (is_array($entry)) {
      $label = trim((string)($entry['label'] ?? ''));
      $value = trim((string)($entry['value'] ?? ''));
      if ($label === '' && $value === '') {
        continue;
      }
      $lines[] = $label !== '' ? $label.': '.$value : $value;
    } else {
      $lines[] = (string)$entry;
    }
  }
  return implode("\n", $lines);
}

function product_normalize_gallery($raw): array {
  $gallery = [];
  if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      $raw = $decoded;
    } else {
      $raw = [$raw];
    }
  }
  if (!is_array($raw)) {
    return [];
  }
  foreach ($raw as $item) {
    if (is_string($item)) {
      $path = trim($item);
      if ($path !== '') {
        $gallery[] = ['path' => $path, 'alt' => ''];
      }
      continue;
    }
    if (is_array($item)) {
      $path = trim((string)($item['path'] ?? ''));
      if ($path === '') {
        continue;
      }
      $alt = trim((string)($item['alt'] ?? ''));
      $gallery[] = ['path' => $path, 'alt' => $alt];
    }
  }
  return $gallery;
}

function load_product_details(PDO $pdo, int $productId): array {
  static $cache = [];
  if (isset($cache[$productId])) {
    return $cache[$productId];
  }
  $defaults = [
    'short_description' => '',
    'detailed_description' => '',
    'specs' => [],
    'additional_info' => '',
    'payment_conditions' => '',
    'delivery_info' => '',
    'media_gallery' => [],
    'video_url' => '',
  ];

  $stmt = $pdo->prepare("SELECT short_description, detailed_description, specs_json, additional_info, payment_conditions, delivery_info, media_gallery, video_url FROM product_details WHERE product_id = ? LIMIT 1");
  $stmt->execute([$productId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return $cache[$productId] = $defaults;
  }
  $details = $defaults;
  $details['short_description'] = (string)($row['short_description'] ?? '');
  $details['detailed_description'] = (string)($row['detailed_description'] ?? '');
  $details['additional_info'] = (string)($row['additional_info'] ?? '');
  $details['payment_conditions'] = (string)($row['payment_conditions'] ?? '');
  $details['delivery_info'] = (string)($row['delivery_info'] ?? '');
  $details['video_url'] = (string)($row['video_url'] ?? '');

  $specsRaw = $row['specs_json'] ?? null;
  if (is_string($specsRaw) && $specsRaw !== '') {
    $decoded = json_decode($specsRaw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
      $details['specs'] = $decoded;
    }
  }

  $details['media_gallery'] = product_normalize_gallery($row['media_gallery'] ?? []);

  return $cache[$productId] = $details;
}

function product_store_image(?array $file): ?string {
  if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    return null;
  }
  $dir = __DIR__.'/storage/products';
  @mkdir($dir, 0775, true);
  $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
    $ext = 'jpg';
  }
  $fname = 'p_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.'.$ext;
  $target = $dir.'/'.$fname;
  if (@move_uploaded_file($file['tmp_name'], $target)) {
    return 'storage/products/'.$fname;
  }
  return null;
}

function product_get_extra_payment_gateways(PDO $pdo): array {
  try {
    $core = ['square','stripe','paypal'];
    $ignored = ['pix','zelle','venmo','whatsapp'];
    $skip = array_unique(array_merge($core, $ignored));
    $stmt = $pdo->prepare("SELECT code, name FROM payment_methods WHERE code NOT IN ('".implode("','", array_map('addslashes', $skip))."') ORDER BY sort_order ASC, id ASC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function product_get_payment_link_map(PDO $pdo, int $productId): array {
  if ($productId <= 0) {
    return [];
  }
  try {
    $stmt = $pdo->prepare("SELECT gateway_code, link FROM product_payment_links WHERE product_id = ?");
    $stmt->execute([$productId]);
    $map = [];
    foreach ($stmt as $row) {
      $code = (string)($row['gateway_code'] ?? '');
      if ($code !== '') {
        $map[$code] = (string)($row['link'] ?? '');
      }
    }
    return $map;
  } catch (Throwable $e) {
    return [];
  }
}

function product_sanitize_extra_link($value): string {
  $value = trim((string)$value);
  if ($value === '') {
    return '';
  }
  return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function product_store_extra_links(PDO $pdo, int $productId, array $extraGateways, array $payload): void {
  if ($productId <= 0 || !$extraGateways) {
    return;
  }
  try {
    $del = $pdo->prepare("DELETE FROM product_payment_links WHERE product_id = ? AND gateway_code = ?");
    $ins = $pdo->prepare("INSERT INTO product_payment_links (product_id, gateway_code, link, created_at, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE link=VALUES(link), updated_at=VALUES(updated_at)");
    foreach ($extraGateways as $gateway) {
      $code = (string)($gateway['code'] ?? '');
      if ($code === '') {
        continue;
      }
      $link = product_sanitize_extra_link($payload[$code] ?? '');
      $del->execute([$productId, $code]);
      if ($link !== '') {
        $now = date('Y-m-d H:i:s');
        $ins->execute([$productId, $code, $link, $now, $now]);
      }
    }
  } catch (Throwable $e) {
  }
}

function product_collect_payload(PDO $pdo, array $post, array $files, string $storeCurrency, ?array $existing = null): array {
  $errors = [];

  $name = sanitize_string($post['name'] ?? '', 190);
  $sku = sanitize_string($post['sku'] ?? '', 100);
  $price = (float)($post['price'] ?? 0);
  $priceCompareInput = trim((string)($post['price_compare'] ?? ''));
  $priceCompare = $priceCompareInput === '' ? null : (float)$priceCompareInput;
  if ($priceCompare !== null && $priceCompare < 0) {
    $priceCompare = null;
  }
  $shippingCost = isset($post['shipping_cost']) ? (float)$post['shipping_cost'] : 7.0;
  if ($shippingCost < 0) {
    $shippingCost = 0;
  }
  $currency = normalize_product_currency($post['currency'] ?? $storeCurrency, $storeCurrency);
  $stock = (int)($post['stock'] ?? 0);
  $category_id = (int)($post['category_id'] ?? 0);
  if ($category_id <= 0) {
    $category_id = null;
  }
  $description = sanitize_string($post['description'] ?? '', 2000);
  $active = (int)($post['active'] ?? 1) ? 1 : 0;
  $featured = (int)($post['featured'] ?? 0) ? 1 : 0;
  $badgeOneMonth = (int)($post['badge_one_month'] ?? ($existing['badge_one_month'] ?? 0)) ? 1 : 0;

  $slugInput = trim((string)($post['slug'] ?? ''));
  $slugBase = $slugInput !== '' ? $slugInput : $name;
  $ignoreId = $existing['id'] ?? null;
  $slug = product_generate_unique_slug($pdo, $slugBase !== '' ? $slugBase : ($existing['slug'] ?? $name), $ignoreId);

  $square_input = (string)($post['square_payment_link'] ?? '');
  [$square_ok, $square_link, $square_error] = normalize_square_link($square_input);
  $square_credit_input = (string)($post['square_credit_link'] ?? '');
  [$credit_ok, $square_credit_link, $credit_error] = normalize_square_link($square_credit_input);
  $square_debit_input = (string)($post['square_debit_link'] ?? '');
  [$debit_ok, $square_debit_link, $debit_error] = normalize_square_link($square_debit_input);
  $square_afterpay_input = (string)($post['square_afterpay_link'] ?? '');
  [$afterpay_ok, $square_afterpay_link, $afterpay_error] = normalize_square_link($square_afterpay_input);
  $stripe_input = (string)($post['stripe_payment_link'] ?? '');
  [$stripe_ok, $stripe_link, $stripe_error] = normalize_stripe_link($stripe_input);
  $paypal_input = (string)($post['paypal_payment_link'] ?? '');
  [$paypal_ok, $paypal_link, $paypal_error] = normalize_paypal_link($paypal_input);
  $extraGateways = product_get_extra_payment_gateways($pdo);
  $extraLinksInput = $post['extra_payment_links'] ?? [];
  $extraLinksSanitized = [];
  foreach ($extraGateways as $gateway) {
    $code = (string)($gateway['code'] ?? '');
    if ($code === '') {
      continue;
    }
    $raw = trim((string)($extraLinksInput[$code] ?? ''));
    $sanitized = product_sanitize_extra_link($raw);
    if ($raw !== '' && $sanitized === '') {
      $errors[] = 'Link inválido para '.$code.'. Informe uma URL completa ou deixe em branco.';
    }
    $extraLinksSanitized[$code] = $sanitized;
  }

  foreach ([
    [$square_ok, $square_error],
    [$credit_ok, $credit_error],
    [$debit_ok, $debit_error],
    [$afterpay_ok, $afterpay_error],
    [$stripe_ok, $stripe_error],
    [$paypal_ok, $paypal_error],
  ] as [$ok, $error]) {
    if (!$ok && $error) {
      $errors[] = $error;
    }
  }

  $shortDescription = trim((string)($post['short_description'] ?? ''));
  $detailedDescription = trim((string)($post['detailed_description'] ?? ''));
  $specsText = trim((string)($post['specs'] ?? ''));
  $specs = product_parse_specs_text($specsText);
  $additionalInfo = trim((string)($post['additional_info'] ?? ''));
  $paymentConditions = trim((string)($post['payment_conditions'] ?? ''));
  $deliveryInfo = trim((string)($post['delivery_info'] ?? ''));
  $videoUrlRaw = trim((string)($post['video_url'] ?? ''));
  $videoUrl = '';
  if ($videoUrlRaw !== '') {
    if (filter_var($videoUrlRaw, FILTER_VALIDATE_URL)) {
      $videoUrl = $videoUrlRaw;
    } else {
      $errors[] = 'Informe uma URL válida para o vídeo ou deixe o campo vazio.';
    }
  }

  $existingIdx = $post['gallery_existing_index'] ?? [];
  $existingPaths = $post['gallery_existing_path'] ?? [];
  $existingAlts = $post['gallery_existing_alt'] ?? [];
  $removeIdx = array_map('strval', $post['gallery_existing_remove'] ?? []);
  $removeLookup = array_flip($removeIdx);

  $gallery = [];
  $countExisting = min(count($existingIdx), count($existingPaths));
  for ($i = 0; $i < $countExisting; $i++) {
    $indexKey = (string)$existingIdx[$i];
    if (isset($removeLookup[$indexKey])) {
      continue;
    }
    $path = trim((string)($existingPaths[$i] ?? ''));
    if ($path === '') {
      continue;
    }
    $alt = trim((string)($existingAlts[$i] ?? ''));
    $gallery[] = ['path' => $path, 'alt' => $alt];
  }

  if (isset($files['gallery_uploads']) && is_array($files['gallery_uploads']['name'] ?? null)) {
    $countUploads = count($files['gallery_uploads']['name']);
    for ($i = 0; $i < $countUploads; $i++) {
      $file = [
        'name' => $files['gallery_uploads']['name'][$i] ?? null,
        'type' => $files['gallery_uploads']['type'][$i] ?? null,
        'tmp_name' => $files['gallery_uploads']['tmp_name'][$i] ?? null,
        'error' => $files['gallery_uploads']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['gallery_uploads']['size'][$i] ?? 0,
      ];
      $stored = product_store_image($file);
      if ($stored) {
        $gallery[] = ['path' => $stored, 'alt' => trim($name)];
      }
    }
  }

  $galleryUrls = trim((string)($post['gallery_urls'] ?? ''));
  if ($galleryUrls !== '') {
    foreach (preg_split('/\r?\n/', $galleryUrls) as $line) {
      $line = trim($line);
      if ($line === '') continue;
      $parts = explode('|', $line, 2);
      $url = trim($parts[0] ?? '');
      if ($url === '') continue;
      $alt = trim($parts[1] ?? '');
      $gallery[] = ['path' => $url, 'alt' => $alt];
    }
  }

  if (count($gallery) > 4) {
    $gallery = array_slice($gallery, 0, 4);
  }

  $image_path = $existing['image_path'] ?? null;
  if (isset($files['image'])) {
    $newImage = product_store_image($files['image']);
    if ($newImage) {
      $image_path = $newImage;
    }
  }

  $productData = [
    'name' => $name,
    'slug' => $slug,
    'sku' => $sku,
    'price' => $price,
    'price_compare' => $priceCompare,
    'currency' => $currency,
    'shipping_cost' => $shippingCost,
    'stock' => $stock,
    'category_id' => $category_id,
    'description' => $description,
    'active' => $active,
    'featured' => $featured,
    'badge_one_month' => $badgeOneMonth,
    'image_path' => $image_path,
    'square_payment_link' => $square_link,
    'square_credit_link' => $square_credit_link,
    'square_debit_link' => $square_debit_link,
    'square_afterpay_link' => $square_afterpay_link,
    'stripe_payment_link' => $stripe_link,
    'paypal_payment_link' => $paypal_link,
  ];

  $detailsData = [
    'short_description' => $shortDescription,
    'detailed_description' => $detailedDescription,
    'specs_json' => $specs,
    'additional_info' => $additionalInfo,
    'payment_conditions' => $paymentConditions,
    'delivery_info' => $deliveryInfo,
    'media_gallery' => $gallery,
    'video_url' => $videoUrl,
  ];

  $formRow = $productData;
  $formRow['short_description'] = $shortDescription;
  $formRow['detailed_description'] = $detailedDescription;
  $formRow['specs_text'] = $specsText;
  $formRow['additional_info'] = $additionalInfo;
  $formRow['payment_conditions'] = $paymentConditions;
  $formRow['delivery_info'] = $deliveryInfo;
  $formRow['video_url'] = $videoUrl;
  $formRow['gallery'] = $gallery;
  $formRow['gallery_urls_text'] = $galleryUrls;
  $formRow['__specs'] = $specs;
  $formRow['__details'] = [
    'short_description' => $shortDescription,
    'detailed_description' => $detailedDescription,
    'specs' => $specs,
    'additional_info' => $additionalInfo,
    'payment_conditions' => $paymentConditions,
    'delivery_info' => $deliveryInfo,
    'media_gallery' => $gallery,
    'video_url' => $videoUrl,
  ];
  $formRow['__extra_links'] = $extraLinksSanitized;
  $formRow['badge_one_month'] = $badgeOneMonth;

  return [$productData, $detailsData, $formRow, $errors];
}

function product_save_details(PDO $pdo, int $productId, array $details): void {
  $shortDescription = trim((string)($details['short_description'] ?? ''));
  $detailedDescription = trim((string)($details['detailed_description'] ?? ''));
  $additionalInfo = trim((string)($details['additional_info'] ?? ''));
  $paymentConditions = trim((string)($details['payment_conditions'] ?? ''));
  $deliveryInfo = trim((string)($details['delivery_info'] ?? ''));
  $videoUrl = trim((string)($details['video_url'] ?? ''));

  $specs = $details['specs_json'] ?? [];
  if (!is_array($specs)) {
    $specs = [];
  }
  $gallery = $details['media_gallery'] ?? [];
  if (!is_array($gallery)) {
    $gallery = [];
  }

  $specsJson = json_encode($specs, JSON_UNESCAPED_UNICODE);
  if ($specsJson === false) {
    $specsJson = '[]';
  }
  $galleryJson = json_encode($gallery, JSON_UNESCAPED_UNICODE);
  if ($galleryJson === false) {
    $galleryJson = '[]';
  }

  $stmt = $pdo->prepare("
    INSERT INTO product_details (
      product_id,
      short_description,
      detailed_description,
      specs_json,
      additional_info,
      payment_conditions,
      delivery_info,
      media_gallery,
      video_url
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      short_description = VALUES(short_description),
      detailed_description = VALUES(detailed_description),
      specs_json = VALUES(specs_json),
      additional_info = VALUES(additional_info),
      payment_conditions = VALUES(payment_conditions),
      delivery_info = VALUES(delivery_info),
      media_gallery = VALUES(media_gallery),
      video_url = VALUES(video_url)
  ");
  $stmt->execute([
    $productId,
    $shortDescription !== '' ? $shortDescription : null,
    $detailedDescription !== '' ? $detailedDescription : null,
    $specsJson,
    $additionalInfo !== '' ? $additionalInfo : null,
    $paymentConditions !== '' ? $paymentConditions : null,
    $deliveryInfo !== '' ? $deliveryInfo : null,
    $galleryJson,
    $videoUrl !== '' ? $videoUrl : null,
  ]);
}


function categories_options($pdo, $current=0){
  $opts='';
  try{
    $st=$pdo->query("SELECT id,name FROM categories WHERE active=1 ORDER BY sort_order, name");
    foreach($st as $c){
      $sel = ($current==(int)$c['id'])?'selected':'';
      $opts.='<option value="'.(int)$c['id'].'" '.$sel.'>'.sanitize_html($c['name']).'</option>';
    }
  }catch(Throwable $e){}
  return $opts;
}

/** Verifica se um SKU já existe (ignorando um ID opcional) */
function sku_exists(PDO $pdo, string $sku, ?int $ignoreId = null): bool {
  if ($ignoreId) {
    $st = $pdo->prepare("SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1");
    $st->execute([$sku, $ignoreId]);
  } else {
    $st = $pdo->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
    $st->execute([$sku]);
  }
  return (bool) $st->fetchColumn();
}

/** Valida e normaliza uma URL do Square (aceita subdomínios válidos). */
function normalize_square_link(string $input): array {
  $url = trim($input);
  if ($url === '') {
    return [true, '', null];
  }
  if (strlen($url) > 255) {
    return [false, $url, 'O link do Square deve ter até 255 caracteres.'];
  }
  if (!filter_var($url, FILTER_VALIDATE_URL)) {
    return [false, $url, 'Informe uma URL válida do Square.'];
  }
  $parts = parse_url($url);
  $scheme = strtolower($parts['scheme'] ?? '');
  if ($scheme !== 'https') {
    return [false, $url, 'O link do Square deve começar com https://'];
  }
  $host = strtolower($parts['host'] ?? '');
  $allowed = ['square.link', 'checkout.square.site', 'squareup.com'];
  $match = false;
  foreach ($allowed as $domain) {
    if ($host === $domain) { $match = true; break; }
    if (substr($host, -strlen('.'.$domain)) === '.'.$domain) { $match = true; break; }
  }
  if (!$match) {
    return [false, $url, 'Domínio não permitido. Use links square.link, checkout.square.site ou squareup.com.'];
  }
  return [true, $url, null];
}

function normalize_stripe_link(string $input): array {
  $url = trim($input);
  if ($url === '') {
    return [true, '', null];
  }
  if (strlen($url) > 255) {
    return [false, $url, 'O link do Stripe deve ter até 255 caracteres.'];
  }
  if (!filter_var($url, FILTER_VALIDATE_URL)) {
    return [false, $url, 'Informe uma URL válida do Stripe.'];
  }
  $parts = parse_url($url);
  $scheme = strtolower($parts['scheme'] ?? '');
  if ($scheme !== 'https') {
    return [false, $url, 'O link do Stripe deve começar com https://'];
  }
  $host = strtolower($parts['host'] ?? '');
  $allowed = ['buy.stripe.com', 'checkout.stripe.com'];
  $match = false;
  foreach ($allowed as $domain) {
    if ($host === $domain) { $match = true; break; }
    if (substr($host, -strlen('.'.$domain)) === '.'.$domain) { $match = true; break; }
  }
  if (!$match) {
    return [false, $url, 'Domínio não permitido. Use links buy.stripe.com ou checkout.stripe.com.'];
  }
  return [true, $url, null];
}

function normalize_paypal_link(string $input): array {
  $url = trim($input);
  if ($url === '') {
    return [true, '', null];
  }
  if (strlen($url) > 255) {
    return [false, $url, 'O link do PayPal deve ter até 255 caracteres.'];
  }
  if (!filter_var($url, FILTER_VALIDATE_URL)) {
    return [false, $url, 'Informe uma URL válida do PayPal.'];
  }
  $parts = parse_url($url);
  $scheme = strtolower($parts['scheme'] ?? '');
  if ($scheme !== 'https') {
    return [false, $url, 'O link do PayPal deve começar com https://'];
  }
  // Aceita qualquer host https (liberado para evitar bloqueio de links válidos do PayPal)
  return [true, $url, null];
}

function parse_decimal_value($raw, ?float $default = null): ?float {
  $value = trim((string)$raw);
  if ($value === '') {
    return $default;
  }
  $value = str_replace(["\xc2\xa0", ' '], '', $value);
  $comma = strrpos($value, ',');
  $dot   = strrpos($value, '.');
  if ($comma !== false && $dot !== false) {
    if ($comma > $dot) {
      $value = str_replace('.', '', $value);
      $value = str_replace(',', '.', $value);
    } else {
      $value = str_replace(',', '', $value);
    }
  } elseif ($comma !== false) {
    $value = str_replace(',', '.', $value);
  }
  if (!is_numeric($value)) {
    return null;
  }
  return (float)$value;
}

function normalize_product_currency($value, $fallback): string {
  $fallback = strtoupper($fallback ?: 'USD');
  $value = strtoupper(trim((string)$value));
  $allowed = ['USD', 'BRL'];
  if ($value === '' || !in_array($value, $allowed, true)) {
    return $fallback;
  }
  return $value;
}

/** Formulário de produto (reutilizável) */
function product_form($row){
  global $storeCurrency, $pdo;

  $id = (int)($row['id'] ?? 0);
  $defaultDetails = [
    'short_description' => '',
    'detailed_description' => '',
    'specs' => [],
    'additional_info' => '',
    'payment_conditions' => '',
    'delivery_info' => '',
    'media_gallery' => [],
    'video_url' => '',
  ];

  if (isset($row['__details']) && is_array($row['__details'])) {
    $details = array_merge($defaultDetails, $row['__details']);
  } elseif ($id > 0) {
    $details = load_product_details($pdo, $id);
  } else {
    $details = $defaultDetails;
  }

  $name = sanitize_html($row['name'] ?? '');
  $slugValue = sanitize_html($row['slug'] ?? '');
  $sku = sanitize_html($row['sku'] ?? '');
  $price = number_format((float)($row['price'] ?? 0), 2, '.', '');
  $priceCompareRaw = $row['price_compare'] ?? null;
  $priceCompare = ($priceCompareRaw === null || $priceCompareRaw === '') ? '' : number_format((float)$priceCompareRaw, 2, '.', '');
  $shippingCost = number_format((float)($row['shipping_cost'] ?? 7.00), 2, '.', '');
  $stock = (int)($row['stock'] ?? 0);
  $category_id = (int)($row['category_id'] ?? 0);
  $desc = sanitize_html($row['description'] ?? '');
  $active = (int)($row['active'] ?? 1);
  $featured = (int)($row['featured'] ?? 0);
  $badgeOneMonth = (int)($row['badge_one_month'] ?? 0);
  $currency = normalize_product_currency($row['currency'] ?? '', $storeCurrency);
  $img = sanitize_html($row['image_path'] ?? '');
  $square_link = sanitize_html($row['square_payment_link'] ?? '');
  $stripe_link = sanitize_html($row['stripe_payment_link'] ?? '');
  $paypal_link = sanitize_html($row['paypal_payment_link'] ?? '');
  $square_credit = sanitize_html($row['square_credit_link'] ?? '');
  $square_debit = sanitize_html($row['square_debit_link'] ?? '');
  $square_afterpay = sanitize_html($row['square_afterpay_link'] ?? '');
  $csrf = csrf_token();
  $extraGateways = product_get_extra_payment_gateways($pdo);
  $extraLinkMap = product_get_payment_link_map($pdo, $id);
  if (isset($row['__extra_links']) && is_array($row['__extra_links'])) {
    $extraLinkMap = array_merge($extraLinkMap, $row['__extra_links']);
  }

  $shortDescription = htmlspecialchars($row['short_description'] ?? $details['short_description'], ENT_QUOTES, 'UTF-8');
  $detailedDescription = htmlspecialchars($row['detailed_description'] ?? $details['detailed_description'], ENT_QUOTES, 'UTF-8');
  $specsArray = $row['__specs'] ?? $details['specs'];
  $specsText = htmlspecialchars($row['specs_text'] ?? product_specs_to_text($specsArray), ENT_QUOTES, 'UTF-8');
  $additionalInfo = htmlspecialchars($row['additional_info'] ?? $details['additional_info'], ENT_QUOTES, 'UTF-8');
  $paymentConditions = htmlspecialchars($row['payment_conditions'] ?? $details['payment_conditions'], ENT_QUOTES, 'UTF-8');
  $deliveryInfo = htmlspecialchars($row['delivery_info'] ?? $details['delivery_info'], ENT_QUOTES, 'UTF-8');
  $videoUrl = sanitize_html($row['video_url'] ?? $details['video_url']);
  $galleryItems = product_normalize_gallery($row['gallery'] ?? $details['media_gallery']);
  $galleryUrlsText = htmlspecialchars($row['gallery_urls_text'] ?? '', ENT_QUOTES, 'UTF-8');

  $slugExample = $slugValue;
  if ($slugExample === '' && $name !== '') {
    $slugExample = sanitize_html(slugify($name));
  }

  echo '<form class="p-4 space-y-3" method="post" enctype="multipart/form-data" action="products.php?action=' . ($id ? 'update&id=' . $id : 'create') . '">';
  echo '  <input type="hidden" name="csrf" value="' . $csrf . '">';
  echo '  <div class="grid md:grid-cols-2 gap-3">';
  echo '    <div class="field"><span>Nome</span><input class="input" name="name" value="' . $name . '" required></div>';
  echo '    <div class="field"><span>Slug (URL)</span><input class="input" name="slug" value="' . $slugValue . '" placeholder="ex.: ' . ($slugExample ?: 'seu-produto') . '">';
  echo '      <p class="text-xs text-gray-500 mt-1">Usado na URL da página do produto. Deixe em branco para gerar automaticamente.</p></div>';
  echo '    <div class="field"><span>SKU</span><input class="input" name="sku" value="' . $sku . '" required></div>';
  echo '    <div class="field"><span>Preço original (De)</span><input class="input" name="price_compare" type="number" step="0.01" value="' . $priceCompare . '" placeholder="Ex.: 59.90">';
  echo '      <p class="text-xs text-gray-500 mt-1">Deixe vazio para ocultar a faixa “de”.</p></div>';
  echo '    <div class="field"><span>Preço atual (Por)</span><input class="input" name="price" type="number" step="0.01" value="' . $price . '" required>';
  echo '      <p class="text-xs text-gray-500 mt-1">Informe o valor na moeda selecionada abaixo.</p></div>';
  $currencyOptions = [
    'USD' => 'USD (US$)',
    'BRL' => 'BRL (R$)'
  ];
  $currencySelect = '<select class="select" name="currency">';
  foreach ($currencyOptions as $code => $label) {
    $sel = ($currency === $code) ? 'selected' : '';
    $currencySelect .= '<option value="' . $code . '" ' . $sel . '>' . $label . '</option>';
  }
  $currencySelect .= '</select>';
  echo '    <div class="field"><span>Moeda</span>' . $currencySelect . '</div>';
  echo '    <div class="field"><span>Frete (' . $currency . ')</span><input class="input" name="shipping_cost" type="number" step="0.01" value="' . $shippingCost . '" placeholder="7.00"></div>';
  echo '    <div class="field"><span>Estoque</span><input class="input" name="stock" type="number" value="' . $stock . '" required></div>';
  echo '    <div class="field"><span>Categoria</span><select class="select" name="category_id">' . categories_options($pdo, $category_id) . '</select></div>';
  echo '    <div class="field"><span>Ativo</span><select class="select" name="active"><option value="1" ' . ($active ? 'selected' : '') . '>Sim</option><option value="0" ' . (!$active ? 'selected' : '') . '>Não</option></select></div>';
  echo '    <div class="field"><span>Destaque</span><select class="select" name="featured"><option value="0" ' . (!$featured ? 'selected' : '') . '>Não</option><option value="1" ' . ($featured ? 'selected' : '') . '>Sim</option></select></div>';
  echo '    <div class="field"><span>Tarja “Tratamento para 1 mês”</span><select class="select" name="badge_one_month"><option value="0" ' . (!$badgeOneMonth ? 'selected' : '') . '>Ocultar</option><option value="1" ' . ($badgeOneMonth ? 'selected' : '') . '>Exibir</option></select><p class="text-xs text-gray-500 mt-1">Quando ativado, mostra uma tarja destacando que o produto cobre um tratamento de 1 mês.</p></div>';
  echo '  </div>';

  echo '  <div class="grid md:grid-cols-2 gap-3">
            <div class="field md:col-span-2"><span>Descrição curta</span><textarea class="textarea" rows="3" name="short_description">' . $shortDescription . '</textarea>
              <p class="text-xs text-gray-500 mt-1">Usada em listagens, cabeçalhos e pré-visualizações.</p></div>
            <div class="field md:col-span-2"><span>Descrição detalhada (HTML permitido)</span><textarea class="textarea h-48" name="detailed_description">' . $detailedDescription . '</textarea>
              <p class="text-xs text-gray-500 mt-1">Você pode usar HTML básico. O conteúdo é sanitizado automaticamente na exibição.</p></div>
            <div class="field md:col-span-2"><span>Especificações (uma por linha)</span><textarea class="textarea h-32" name="specs">' . $specsText . '</textarea>
              <p class="text-xs text-gray-500 mt-1">Formato sugerido: <code>Chave: Valor</code>.</p></div>
            <div class="field md:col-span-2"><span>Informações adicionais</span><textarea class="textarea h-32" name="additional_info">' . $additionalInfo . '</textarea></div>
            <div class="field md:col-span-2"><span>Condições de pagamento</span><textarea class="textarea" rows="3" name="payment_conditions">' . $paymentConditions . '</textarea></div>
            <div class="field md:col-span-2"><span>Informações de entrega</span><textarea class="textarea" rows="3" name="delivery_info">' . $deliveryInfo . '</textarea></div>
            <div class="field md:col-span-2"><span>Vídeo (YouTube/Vimeo ou URL)</span><input class="input" type="url" name="video_url" value="' . $videoUrl . '" placeholder="https://www.youtube.com/watch?v=..."></div>
            <div class="field md:col-span-2"><span>Descrição (legado)</span><textarea class="textarea" rows="3" name="description">' . $desc . '</textarea>
              <p class="text-xs text-gray-500 mt-1">Campo mantido para compatibilidade com integrações antigas.</p></div>
          </div>';

  echo '  <div class="border border-gray-200 rounded-2xl p-4 bg-gray-50 space-y-4">';
  echo '    <div class="font-semibold text-gray-800">Galeria de imagens (máximo 4)</div>';
  if ($galleryItems) {
    echo '    <div class="grid sm:grid-cols-2 gap-4">';
    foreach ($galleryItems as $idx => $item) {
      $path = htmlspecialchars($item['path'] ?? '', ENT_QUOTES, 'UTF-8');
      if ($path === '') {
        continue;
      }
      $alt = htmlspecialchars($item['alt'] ?? '', ENT_QUOTES, 'UTF-8');
      echo '      <div class="bg-white rounded-xl border border-gray-200 p-3 flex gap-3 items-start">';
      echo '        <img src="' . $path . '" alt="thumb" class="w-20 h-20 object-cover rounded-lg">';
      echo '        <div class="flex-1 space-y-2">';
      echo '          <input type="hidden" name="gallery_existing_index[]" value="' . $idx . '">';
      echo '          <input type="hidden" name="gallery_existing_path[]" value="' . $path . '">';
      echo '          <div class="text-xs text-gray-500 break-all">' . $path . '</div>';
      echo '          <label class="block text-xs text-gray-500">Texto alternativo</label>';
      echo '          <input class="input" name="gallery_existing_alt[]" value="' . $alt . '">';
      echo '          <label class="inline-flex items-center gap-2 text-xs text-rose-600"><input type="checkbox" name="gallery_existing_remove[]" value="' . $idx . '"> Remover</label>';
      echo '        </div>';
      echo '      </div>';
    }
    echo '    </div>';
  }
  echo '    <div class="field"><span>Enviar novas imagens</span><input class="input" type="file" name="gallery_uploads[]" accept="image/*" multiple>';
  echo '      <p class="text-xs text-gray-500 mt-1">Arquivos JPG, PNG ou WEBP. Serão redimensionados automaticamente conforme necessário.</p></div>';
  echo '    <div class="field"><span>Adicionar URLs externas (opcional)</span><textarea class="textarea" rows="3" name="gallery_urls" placeholder="https://exemplo.jpg | Texto alternativo">' . $galleryUrlsText . '</textarea>';
  echo '      <p class="text-xs text-gray-500 mt-1">Uma URL por linha. Use <code>URL | ALT</code> para definir o texto alternativo.</p></div>';
  echo '  </div>';

  echo '  <hr class="border-gray-200">';

  echo '  <div class="field md:col-span-2"><span>Link do checkout do cartão (Square)</span>';
  echo '      <input class="input" type="url" name="square_payment_link" value="' . $square_link . '" placeholder="https://square.link/u/xxxx">';
  echo '      <p class="text-xs text-gray-500 mt-1">Cole aqui o link gerado no Square para o checkout de cartão (aceita square.link, checkout.square.site ou squareup.com).</p>';
  if ($square_link) {
    echo '      <p class="text-xs mt-1"><a class="text-brand-600 underline" href="' . $square_link . '" target="_blank" rel="noopener">Testar link</a></p>';
  }
  echo '    </div>';
  echo '    <div class="field md:col-span-2"><span>Cartão de crédito (Square) por produto</span>';
  echo '      <input class="input" type="url" name="square_credit_link" value="' . $square_credit . '" placeholder="https://square.link/.../credit">';
  echo '      <p class="text-xs text-gray-500 mt-1">Link específico deste produto para cartão de crédito. Caso vazio, usamos o link padrão acima ou configurações do método.</p>';
  if ($square_credit) {
    echo '      <p class="text-xs mt-1"><a class="text-brand-600 underline" href="' . $square_credit . '" target="_blank" rel="noopener">Testar</a></p>';
  }
  echo '    </div>';
  echo '    <div class="field md:col-span-2"><span>Cartão de débito (Square) por produto</span>';
  echo '      <input class="input" type="url" name="square_debit_link" value="' . $square_debit . '" placeholder="https://square.link/.../debit">';
  if ($square_debit) {
    echo '      <p class="text-xs mt-1"><a class="text-brand-600 underline" href="' . $square_debit . '" target="_blank" rel="noopener">Testar</a></p>';
  }
  echo '    </div>';
  echo '    <div class="field md:col-span-2"><span>Afterpay (Square) por produto</span>';
  echo '      <input class="input" type="url" name="square_afterpay_link" value="' . $square_afterpay . '" placeholder="https://square.link/.../afterpay">';
  if ($square_afterpay) {
    echo '      <p class="text-xs mt-1"><a class="text-brand-600 underline" href="' . $square_afterpay . '" target="_blank" rel="noopener">Testar</a></p>';
  }
  echo '    </div>';
  echo '    <div class="field md:col-span-2"><span>Checkout Stripe</span>';
  echo '      <input class="input" type="url" name="stripe_payment_link" value="' . $stripe_link . '" placeholder="https://buy.stripe.com/...">';
  if ($stripe_link) {
    echo '      <p class="text-xs mt-1"><a class="text-brand-600 underline" href="' . $stripe_link . '" target="_blank" rel="noopener">Testar</a></p>';
  }
  echo '    </div>';
  echo '    <div class="field md:col-span-2"><span>Checkout PayPal</span>';
  echo '      <input class="input" type="url" name="paypal_payment_link" value="' . $paypal_link . '" placeholder="https://www.paypal.com/checkoutnow?...">';
  if ($paypal_link) {
    echo '      <p class="text-xs mt-1"><a class="text-brand-600 underline" href="' . $paypal_link . '" target="_blank" rel="noopener">Testar</a></p>';
  }
  echo '    </div>';
  if ($extraGateways) {
    echo '    <div class="mt-4 text-sm font-semibold text-gray-700">Links adicionais por forma de pagamento</div>';
    foreach ($extraGateways as $gateway) {
      $code = (string)($gateway['code'] ?? '');
      if ($code === '') {
        continue;
      }
      $label = sanitize_html($gateway['name'] ?? $code);
      $value = sanitize_html($extraLinkMap[$code] ?? '');
      echo '    <div class="field md:col-span-2"><span>Checkout '.$label.'</span>';
      echo '      <input class="input" type="url" name="extra_payment_links['.sanitize_html($code).']" value="' . $value . '" placeholder="https://...">';
      echo '      <p class="text-xs text-gray-500 mt-1">Link específico deste produto para '.$label.'.</p>';
      if ($value) {
        echo '      <p class="text-xs mt-1"><a class="text-brand-600 underline" href="' . $value . '" target="_blank" rel="noopener">Testar</a></p>';
      }
      echo '    </div>';
    }
  }

  echo '  <div class="field"><span>Imagem principal do produto (JPG/PNG/WEBP)</span><input class="input" type="file" name="image" accept=".jpg,.jpeg,.png,.webp"></div>';
  if ($img) {
    echo '  <div class="p-2"><img src="' . sanitize_html($img) . '" alt="img" style="max-height:100px;border-radius:8px"></div>';
  }
  echo '  <div class="pt-2"><button class="btn alt" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar</button> <a class="btn" href="products.php"><i class="fa-solid fa-arrow-left"></i> Voltar</a></div>';
  echo '</form>';
}

/* ========= Actions ========= */

if ($action==='export') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="produtos-'.date('Ymd-His').'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['sku','name','price','price_compare','shipping_cost','currency','stock','category_id','description','image_path','square_credit_link','square_debit_link','square_afterpay_link','square_payment_link','stripe_payment_link','paypal_payment_link','badge_one_month','active']);
  $stmt = $pdo->query("SELECT sku,name,price,price_compare,shipping_cost,currency,stock,category_id,description,image_path,square_credit_link,square_debit_link,square_afterpay_link,square_payment_link,stripe_payment_link,paypal_payment_link,badge_one_month,active FROM products ORDER BY id ASC");
  foreach ($stmt as $row) {
    fputcsv($out, [
      $row['sku'],
      $row['name'],
      number_format((float)$row['price'], 2, '.', ''),
      $row['price_compare'] !== null ? number_format((float)$row['price_compare'], 2, '.', '') : '',
      number_format((float)($row['shipping_cost'] ?? 7), 2, '.', ''),
      strtoupper($row['currency'] ?? $storeCurrency),
      (int)$row['stock'],
      $row['category_id'],
      $row['description'],
      $row['image_path'],
      $row['square_credit_link'],
      $row['square_debit_link'],
      $row['square_afterpay_link'],
      $row['square_payment_link'],
      $row['stripe_payment_link'],
      $row['paypal_payment_link'],
      isset($row['badge_one_month']) ? (int)$row['badge_one_month'] : 0,
      (int)$row['active']
    ]);
  }
  fclose($out);
  exit;
}

if ($action==='import') {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
    if (empty($_FILES['csv']['name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
      products_flash('error', 'Selecione um arquivo CSV válido.');
      header('Location: products.php?action=import'); exit;
    }
    $tmp = $_FILES['csv']['tmp_name'];
    $handle = fopen($tmp, 'r');
    if (!$handle) {
      products_flash('error', 'Não foi possível ler o arquivo enviado.');
      header('Location: products.php?action=import'); exit;
    }
    $delimiter = ';';
    $header = fgetcsv($handle, 0, $delimiter);
    if ($header && count($header) < 2) {
      rewind($handle);
      $delimiter = ',';
      $header = fgetcsv($handle, 0, $delimiter);
    }
    if (!$header) {
      fclose($handle);
      products_flash('error', 'Arquivo CSV vazio ou inválido.');
      header('Location: products.php?action=import'); exit;
    }
    $headerLower = array_map(fn($v) => strtolower(trim($v)), $header);
    $headerMap = array_flip($headerLower);
    $hasPriceCompare = isset($headerMap['price_compare']);
    $hasBadgeColumn = isset($headerMap['badge_one_month']);
    foreach (['sku','name','price','stock'] as $required) {
      if (!isset($headerMap[$required])) {
        fclose($handle);
        products_flash('error', 'Cabeçalho inválido. Campos obrigatórios: sku, name, price, stock.');
        header('Location: products.php?action=import'); exit;
      }
    }
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $line = 1;
    $selectSku = $pdo->prepare("SELECT * FROM products WHERE sku = ? LIMIT 1");
    $updateStmt = $pdo->prepare("UPDATE products SET name=?, sku=?, price=?, price_compare=?, currency=?, shipping_cost=?, stock=?, category_id=?, description=?, active=?, featured=?, badge_one_month=?, image_path=?, square_credit_link=?, square_debit_link=?, square_afterpay_link=?, square_payment_link=?, stripe_payment_link=?, paypal_payment_link=? WHERE id=?");
    $insertStmt = $pdo->prepare("INSERT INTO products(name,sku,price,price_compare,currency,shipping_cost,stock,category_id,description,active,featured,badge_one_month,image_path,square_credit_link,square_debit_link,square_afterpay_link,square_payment_link,stripe_payment_link,paypal_payment_link,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
    $categoryIds = [];
    try {
      $categoryIds = $pdo->query("SELECT id FROM categories")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
      $categoryIds = [];
    }
    $categoryIds = array_map('intval', $categoryIds);
    $pdo->beginTransaction();
    try {
      while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line++;
        if (count($row) === 1 && trim($row[0]) === '') {
          continue;
        }
        $data = [];
        foreach ($headerLower as $idx => $col) {
          $data[$col] = trim((string)($row[$idx] ?? ''));
        }
        $sku = $data['sku'] ?? '';
        if ($sku === '') {
          $errors[] = "Linha {$line}: SKU vazio, registro ignorado.";
          $skipped++;
          continue;
        }
        $name = $data['name'] ?? '';
        if ($name === '') {
          $errors[] = "Linha {$line}: Nome vazio para o SKU {$sku}.";
          $skipped++;
          continue;
        }
        $price = parse_decimal_value($data['price'] ?? '', null);
        if ($price === null) {
          $errors[] = "Linha {$line}: Preço inválido para o SKU {$sku}.";
          $skipped++;
          continue;
        }
        $priceCompare = null;
        if ($hasPriceCompare) {
          $priceCompare = parse_decimal_value($data['price_compare'] ?? '', null);
          if ($priceCompare !== null && $priceCompare < 0) {
            $priceCompare = null;
          }
        }
        $shippingCost = parse_decimal_value($data['shipping_cost'] ?? '', 7.0);
        if ($shippingCost === null) {
          $shippingCost = 7.0;
        }
        $shippingCost = max(0, $shippingCost);
        $stock = (int)($data['stock'] ?? 0);
        $categoryId = null;
        if (isset($data['category_id']) && $data['category_id'] !== '') {
          $categoryId = (int)$data['category_id'];
          if ($categoryId < 0) $categoryId = null;
          if ($categoryId !== null && !in_array($categoryId, $categoryIds, true)) {
            $errors[] = "Linha {$line}: Categoria {$categoryId} não encontrada. Valor ajustado para vazio.";
            $categoryId = null;
          }
        }
        $currencyInput = $data['currency'] ?? '';
        $badgeInput = $hasBadgeColumn ? ((int)($data['badge_one_month'] ?? 0) ? 1 : 0) : null;
        $description = $data['description'] ?? '';
        $imagePath = $data['image_path'] ?? null;
        $squareCreditInput = $data['square_credit_link'] ?? '';
        [$squareCreditOk, $squareCreditLink, $squareCreditError] = normalize_square_link($squareCreditInput);
        if (!$squareCreditOk) {
          $errors[] = "Linha {$line}: {$squareCreditError}";
          $skipped++;
          continue;
        }
        $squareDebitInput = $data['square_debit_link'] ?? '';
        [$squareDebitOk, $squareDebitLink, $squareDebitError] = normalize_square_link($squareDebitInput);
        if (!$squareDebitOk) {
          $errors[] = "Linha {$line}: {$squareDebitError}";
          $skipped++;
          continue;
        }
        $squareAfterpayInput = $data['square_afterpay_link'] ?? '';
        [$squareAfterpayOk, $squareAfterpayLink, $squareAfterpayError] = normalize_square_link($squareAfterpayInput);
        if (!$squareAfterpayOk) {
          $errors[] = "Linha {$line}: {$squareAfterpayError}";
          $skipped++;
          continue;
        }
        $squareInput = $data['square_payment_link'] ?? '';
        [$squareOk, $squareLink, $squareError] = normalize_square_link($squareInput);
        if (!$squareOk) {
          $errors[] = "Linha {$line}: {$squareError}";
          $skipped++;
          continue;
        }
        $stripeInput = $data['stripe_payment_link'] ?? '';
        [$stripeOk, $stripeLink, $stripeError] = normalize_stripe_link($stripeInput);
        if (!$stripeOk) {
          $errors[] = "Linha {$line}: {$stripeError}";
          $skipped++;
          continue;
        }
        $paypalInput = $data['paypal_payment_link'] ?? '';
        [$paypalOk, $paypalLink, $paypalError] = normalize_paypal_link($paypalInput);
        if (!$paypalOk) {
          $errors[] = "Linha {$line}: {$paypalError}";
          $skipped++;
          continue;
        }
        $active = isset($data['active']) ? (int)$data['active'] : 1;
        if ($active !== 0) $active = 1;
        $selectSku->execute([$sku]);
        $existing = $selectSku->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
          $featured = (int)($existing['featured'] ?? 0);
          $imgToUse = ($imagePath !== null && $imagePath !== '') ? $imagePath : ($existing['image_path'] ?? null);
          $descToUse = $description !== '' ? $description : ($existing['description'] ?? '');
          $categoryToUse = $categoryId ?? ($existing['category_id'] ?? null);
          if ($categoryToUse !== null && !in_array((int)$categoryToUse, $categoryIds, true)) {
            $categoryToUse = null;
          }
          $squareCreditToUse = $squareCreditLink !== '' ? $squareCreditLink : ($existing['square_credit_link'] ?? '');
          $squareDebitToUse = $squareDebitLink !== '' ? $squareDebitLink : ($existing['square_debit_link'] ?? '');
          $squareAfterpayToUse = $squareAfterpayLink !== '' ? $squareAfterpayLink : ($existing['square_afterpay_link'] ?? '');
          $squareToUse = $squareLink !== '' ? $squareLink : ($existing['square_payment_link'] ?? '');
          $stripeToUse = $stripeLink !== '' ? $stripeLink : ($existing['stripe_payment_link'] ?? '');
          $paypalToUse = $paypalLink !== '' ? $paypalLink : ($existing['paypal_payment_link'] ?? '');
          $compareToUse = $hasPriceCompare ? $priceCompare : ($existing['price_compare'] ?? null);
          $currencyToUse = normalize_product_currency($currencyInput, $existing['currency'] ?? $storeCurrency);
          $badgeToUse = $badgeInput !== null ? $badgeInput : (int)($existing['badge_one_month'] ?? 0);
          $updateStmt->execute([
            $name,
            $sku,
            $price,
            $compareToUse,
            $currencyToUse,
            $shippingCost,
            $stock,
            $categoryToUse,
            $descToUse,
            $active,
            $featured,
            $badgeToUse,
            $imgToUse,
            $squareCreditToUse,
            $squareDebitToUse,
            $squareAfterpayToUse,
            $squareToUse,
            $stripeToUse,
            $paypalToUse,
            $existing['id']
          ]);
          $updated++;
        } else {
          $currencyFinal = normalize_product_currency($currencyInput, $storeCurrency);
          $badgeInsert = $badgeInput !== null ? $badgeInput : 0;
          $insertStmt->execute([
            $name,
            $sku,
            $price,
            $priceCompare,
            $currencyFinal,
            $shippingCost,
            $stock,
            $categoryId,
            $description,
            $active,
            0,
            $badgeInsert,
            $imagePath ?: null,
            $squareCreditLink,
            $squareDebitLink,
            $squareAfterpayLink,
            $squareLink,
            $stripeLink,
            $paypalLink
          ]);
          $inserted++;
        }
      }
      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      fclose($handle);
      products_flash('error', 'Erro ao importar: '.$e->getMessage());
      header('Location: products.php?action=import'); exit;
    }
    fclose($handle);
    $parts = [];
    if ($inserted) $parts[] = "{$inserted} produto(s) criado(s)";
    if ($updated) $parts[] = "{$updated} produto(s) atualizado(s)";
    if ($skipped) $parts[] = "{$skipped} linha(s) ignorada(s)";
    if ($errors) {
      $parts[] = implode(' ', $errors);
      products_flash('warning', implode(' | ', $parts));
    } else {
      products_flash('success', $parts ? implode(' | ', $parts) : 'Importação concluída.');
    }
    header('Location: products.php'); exit;
  }

  admin_header('Importar produtos');
  echo '<div class="card"><div class="card-title">Importar produtos via CSV</div><div class="p-4 space-y-4">';
  $flash = products_take_flash();
  if ($flash) {
    $class = $flash['type'] === 'error' ? 'alert alert-error' : ($flash['type'] === 'warning' ? 'alert alert-warning' : 'alert alert-success');
    $icon = $flash['type'] === 'error' ? 'fa-circle-exclamation' : ($flash['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check');
    echo '<div class="'.$class.'"><i class="fa-solid '.$icon.' mr-2"></i>'.sanitize_html($flash['message']).'</div>';
  }
  echo '<p class="text-sm text-gray-600">Envie um arquivo CSV (UTF-8) com o cabeçalho <code>sku,name,price,price_compare,shipping_cost,currency,stock,category_id,description,image_path,square_credit_link,square_debit_link,square_afterpay_link,square_payment_link,stripe_payment_link,paypal_payment_link,badge_one_month,active</code> (os campos <code>price_compare</code>, <code>shipping_cost</code>, <code>currency</code> e <code>badge_one_month</code> são opcionais).</p>';
  echo '<p class="text-sm text-gray-600">Use <a class="text-brand-600 underline" href="products.php?action=export">Exportar CSV</a> para gerar um modelo.</p>';
  echo '<form method="post" enctype="multipart/form-data" class="space-y-3">';
  echo '  <input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '  <input class="input w-full" type="file" name="csv" accept=".csv" required>';
  echo '  <div class="flex gap-2">';
  echo '    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-file-arrow-up mr-2"></i>Importar</button>';
  echo '    <a class="btn btn-ghost" href="products.php"><i class="fa-solid fa-arrow-left mr-2"></i>Voltar</a>';
  echo '  </div>';
  echo '</form></div></div>';
  admin_footer(); exit;
}


if ($action==='new') {
  admin_header('Novo produto');
  echo '<div class="card"><div class="card-title">Cadastrar produto</div>';
  product_form(['currency' => $storeCurrency]);
  echo '</div>';
  admin_footer(); exit;
}

if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');

  [$productData, $detailsData, $formRow, $errors] = product_collect_payload($pdo, $_POST, $_FILES, $storeCurrency);

  $skuValue = $productData['sku'] ?? '';
  if ($skuValue === '' || sku_exists($pdo, $skuValue, null)) {
    $errors[] = 'SKU já utilizado: '.$skuValue.'.';
  }

  if ($errors) {
    admin_header('Novo produto');
    echo '<div class="card"><div class="card-title">Cadastrar produto</div>';
    echo '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700">';
    echo '<i class="fa-solid fa-triangle-exclamation mr-1"></i>';
    echo implode('<br>', array_map('sanitize_html', $errors));
    echo '</div>';
    product_form($formRow);
    echo '</div>';
    admin_footer(); exit;
  }

  $productColumns = [
    'name','slug','sku','price','price_compare','currency','shipping_cost','stock','category_id',
    'description','active','featured','badge_one_month','image_path','square_payment_link','square_credit_link',
    'square_debit_link','square_afterpay_link','stripe_payment_link','paypal_payment_link'
  ];
  $productValues = [
    $productData['name'],
    $productData['slug'],
    $skuValue,
    $productData['price'],
    $productData['price_compare'],
    $productData['currency'],
    $productData['shipping_cost'],
    $productData['stock'],
    $productData['category_id'],
    $productData['description'],
    $productData['active'],
    $productData['featured'],
    $productData['badge_one_month'],
    $productData['image_path'],
    $productData['square_payment_link'],
    $productData['square_credit_link'],
    $productData['square_debit_link'],
    $productData['square_afterpay_link'],
    $productData['stripe_payment_link'],
    $productData['paypal_payment_link'],
  ];

  try {
    $pdo->beginTransaction();
    $placeholders = implode(',', array_fill(0, count($productColumns), '?'));
    $insertSql = 'INSERT INTO products('.implode(',', $productColumns).') VALUES('.$placeholders.')';
    $st = $pdo->prepare($insertSql);
    $st->execute($productValues);
    $productId = (int)$pdo->lastInsertId();
    product_save_details($pdo, $productId, $detailsData);
    $extraGateways = product_get_extra_payment_gateways($pdo);
    $extraLinks = is_array($formRow['__extra_links'] ?? null) ? $formRow['__extra_links'] : [];
    product_store_extra_links($pdo, $productId, $extraGateways, $extraLinks);
    $pdo->commit();
    products_flash('success', 'Produto cadastrado com sucesso.');
    header('Location: products.php'); exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $message = $e instanceof PDOException && !empty($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062
      ? 'SKU duplicado no banco. Escolha outro valor.'
      : 'Erro ao salvar produto: '.$e->getMessage();
    admin_header('Novo produto');
    echo '<div class="card"><div class="card-title">Cadastrar produto</div>';
    echo '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700"><i class="fa-solid fa-circle-exclamation mr-1"></i> '.sanitize_html($message).'</div>';
    product_form($formRow);
    echo '</div>';
    admin_footer(); exit;
  }
}

if ($action==='edit') {
  $id=(int)($_GET['id'] ?? 0);
  $st=$pdo->prepare("SELECT * FROM products WHERE id=?");
  $st->execute([$id]);
  $row=$st->fetch();
  if (!$row){ header('Location: products.php'); exit; }
  admin_header('Editar produto');
  echo '<div class="card"><div class="card-title">Editar produto #'.(int)$id.'</div>';
  product_form($row);
  echo '</div>';
  admin_footer(); exit;
}

if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $id=(int)($_GET['id'] ?? 0);

  $existingStmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
  $existingStmt->execute([$id]);
  $existingProduct = $existingStmt->fetch(PDO::FETCH_ASSOC);
  if (!$existingProduct) {
    header('Location: products.php'); exit;
  }

  [$productData, $detailsData, $formRow, $errors] = product_collect_payload($pdo, $_POST, $_FILES, $storeCurrency, $existingProduct);
  $formRow['id'] = $id;

  $skuValue = $productData['sku'] ?? '';
  if ($skuValue === '' || sku_exists($pdo, $skuValue, $id)) {
    $errors[] = 'SKU já utilizado por outro produto: '.$skuValue.'.';
  }

  if ($errors) {
    admin_header('Editar produto');
    echo '<div class="card"><div class="card-title">Editar produto #'.(int)$id.'</div>';
    echo '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700">';
    echo '<i class="fa-solid fa-triangle-exclamation mr-1"></i>';
    echo implode('<br>', array_map('sanitize_html', $errors));
    echo '</div>';
    product_form($formRow);
    echo '</div>';
    admin_footer(); exit;
  }

  $updateMap = [
    'name' => $productData['name'],
    'slug' => $productData['slug'],
    'sku' => $skuValue,
    'price' => $productData['price'],
    'price_compare' => $productData['price_compare'],
    'currency' => $productData['currency'],
    'shipping_cost' => $productData['shipping_cost'],
    'stock' => $productData['stock'],
    'category_id' => $productData['category_id'],
    'description' => $productData['description'],
    'active' => $productData['active'],
    'featured' => $productData['featured'],
    'badge_one_month' => $productData['badge_one_month'],
    'image_path' => $productData['image_path'],
    'square_payment_link' => $productData['square_payment_link'],
    'square_credit_link' => $productData['square_credit_link'],
    'square_debit_link' => $productData['square_debit_link'],
    'square_afterpay_link' => $productData['square_afterpay_link'],
    'stripe_payment_link' => $productData['stripe_payment_link'],
    'paypal_payment_link' => $productData['paypal_payment_link'],
  ];

  $setParts = [];
  $values = [];
  foreach ($updateMap as $column => $value) {
    $setParts[] = $column.' = ?';
    $values[] = $value;
  }
  $values[] = $id;

  try {
    $pdo->beginTransaction();
    $updateSql = 'UPDATE products SET '.implode(',', $setParts).' WHERE id = ?';
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute($values);
    product_save_details($pdo, $id, $detailsData);
    $extraGateways = product_get_extra_payment_gateways($pdo);
    $extraLinks = is_array($formRow['__extra_links'] ?? null) ? $formRow['__extra_links'] : [];
    product_store_extra_links($pdo, $id, $extraGateways, $extraLinks);
    $pdo->commit();
    products_flash('success', 'Produto atualizado com sucesso.');
    header('Location: products.php'); exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    admin_header('Editar produto');
    echo '<div class="card"><div class="card-title">Editar produto #'.(int)$id.'</div>';
    echo '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700"><i class="fa-solid fa-circle-exclamation mr-1"></i> '.sanitize_html('Erro ao atualizar produto: '.$e->getMessage()).'</div>';
    product_form($formRow);
    echo '</div>';
    admin_footer(); exit;
  }
}

if ($action==='delete') {
  $id=(int)($_GET['id'] ?? 0);
  $csrf=$_GET['csrf'] ?? '';
  if (!csrf_check($csrf)) die('CSRF');
  require_super_admin();
  // Soft delete: active=0
  $st=$pdo->prepare("UPDATE products SET active=0 WHERE id=?");
  $st->execute([$id]);
  header('Location: products.php'); exit;
}

if ($action==='destroy') {
  $id=(int)($_GET['id'] ?? 0);
  $csrf=$_GET['csrf'] ?? '';
  if (!csrf_check($csrf)) die('CSRF');
  require_super_admin();
  if ($id > 0) {
    $st=$pdo->prepare("DELETE FROM products WHERE id=?");
    $st->execute([$id]);
    products_flash('success', 'Produto #'.$id.' excluído definitivamente.');
  }
  header('Location: products.php'); exit;
}

if ($action==='bulk_destroy' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  require_super_admin();
  $ids = array_filter(array_map('intval', $_POST['selected'] ?? []));
  if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM products WHERE id IN ($placeholders)");
    $st->execute($ids);
    products_flash('success', count($ids).' produto(s) excluído(s) definitivamente.');
  } else {
    products_flash('warning', 'Selecione pelo menos um produto para excluir.');
  }
  header('Location: products.php'); exit;
}

if ($action==='bulk_badge' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  require_admin_capability('manage_products');
  $mode = $_GET['mode'] ?? 'enable';
  $value = $mode === 'disable' ? 0 : 1;
  $ids = array_filter(array_map('intval', $_POST['selected'] ?? []));
  if (!$ids) {
    products_flash('warning', 'Selecione ao menos um produto para aplicar a tarja.');
    header('Location: products.php'); exit;
  }
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("UPDATE products SET badge_one_month = ? WHERE id IN ($placeholders)");
  $params = array_merge([$value], $ids);
  $stmt->execute($params);
  products_flash('success', $value ? 'Tarja “Tratamento para 1 mês” aplicada aos itens selecionados.' : 'Tarja removida dos itens selecionados.');
  header('Location: products.php'); exit;
}

/* ========= Listagem ========= */

admin_header('Produtos');
$flash = products_take_flash();
if ($flash) {
  $class = $flash['type'] === 'error' ? 'alert alert-error' : ($flash['type'] === 'warning' ? 'alert alert-warning' : 'alert alert-success');
  $icon = $flash['type'] === 'error' ? 'fa-circle-exclamation' : ($flash['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check');
  echo '<div class="'.$class.' mx-auto max-w-4xl mb-4"><i class="fa-solid '.$icon.' mr-2"></i>'.sanitize_html($flash['message']).'</div>';
}
if (!$canManageProducts) {
  echo '<div class="alert alert-warning mx-auto max-w-4xl mb-4"><i class="fa-solid fa-circle-info mr-2"></i>Você possui acesso somente leitura nesta seção.</div>';
}
$q = trim((string)($_GET['q'] ?? ''));
$w = " WHERE 1=1 ";
$p = [];
if ($q!==''){
  $w .= " AND (p.name LIKE ? OR p.sku LIKE ?) ";
  $like = "%$q%"; $p = [$like,$like];
}
$st=$pdo->prepare("SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id $w ORDER BY p.id DESC LIMIT 200");
$st->execute($p);

echo '<div class="card">';
echo '<div class="card-title">Produtos</div>';
echo '<div class="card-toolbar">';
echo '  <form method="get" class="search-form">';
echo '    <input type="hidden" name="action" value="list">';
echo '    <input class="input" name="q" value="'.sanitize_html($q).'" placeholder="Buscar por nome ou SKU">';
echo '    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass mr-2"></i>Buscar</button>';
echo '  </form>';
echo '  <div class="toolbar-actions">';
if ($canManageProducts) {
  echo '    <a class="btn btn-alt btn-sm" href="products.php?action=new"><i class="fa-solid fa-plus mr-2"></i>Novo produto</a>';
  echo '    <a class="btn btn-ghost btn-sm" href="products.php?action=import"><i class="fa-solid fa-file-arrow-up mr-2"></i>Importar CSV</a>';
}
echo '    <a class="btn btn-ghost btn-sm" href="products.php?action=export"><i class="fa-solid fa-file-arrow-down mr-2"></i>Exportar CSV</a>';
echo '  </div>';
echo '</div>';
echo '<form id="bulk-delete-form" method="post" action="products.php?action=bulk_destroy">';
echo '  <input type="hidden" name="csrf" value="'.csrf_token().'">';
echo '  <div class="p-3 overflow-x-auto"><table class="table"><thead><tr>';
if ($isSuperAdmin) {
  echo '<th><input type="checkbox" id="checkAllProducts"></th>';
} else {
  echo '<th></th>';
}
echo '<th>#</th><th>SKU</th><th>Produto</th><th>Categoria</th><th>Preço</th><th>Frete</th><th>Moeda</th><th>Estoque</th><th>Cartão (Square)</th><th>Ativo</th><th></th></tr></thead><tbody>';
foreach($st as $r){
  $rowClasses = [];
  if ((int)$r['active'] === 0) {
    $rowClasses[] = 'product-inactive';
  }
  $rowClassAttr = $rowClasses ? ' class="'.implode(' ', $rowClasses).'"' : '';
  echo '<tr'.$rowClassAttr.'>';
  echo '<td>';
  if ($isSuperAdmin) {
    echo '<input type="checkbox" name="selected[]" value="'.(int)$r['id'].'" class="product-select">';
  }
  echo '</td>';
  echo '<td>'.(int)$r['id'].'</td>';
  echo '<td>'.sanitize_html($r['sku']).'</td>';
  echo '<td>'.sanitize_html($r['name']).'</td>';
  echo '<td>'.sanitize_html($r['category_name']).'</td>';
  $priceNow = (float)$r['price'];
  $productCurrency = normalize_product_currency($r['currency'] ?? '', $storeCurrency);
  $priceCompareList = isset($r['price_compare']) ? (float)$r['price_compare'] : null;
  $priceFormatted = format_currency($priceNow, $productCurrency);
  if ($priceCompareList && $priceCompareList > $priceNow) {
    $compareFormatted = format_currency($priceCompareList, $productCurrency);
    echo '<td><div class="flex flex-col leading-tight"><span class="text-[11px] line-through text-gray-400">'.$compareFormatted.'</span><span class="font-semibold text-brand-700">'.$priceFormatted.'</span></div></td>';
  } else {
    echo '<td>'.$priceFormatted.'</td>';
  }
  echo '<td>'.format_currency((float)($r['shipping_cost'] ?? 7), $productCurrency).'</td>';
  echo '<td>'.$productCurrency.'</td>';
  echo '<td>'.(int)$r['stock'].'</td>';
  $squareCol = trim((string)($r['square_payment_link'] ?? ''));
  if ($squareCol !== '') {
    $safeLink = sanitize_html($squareCol);
    echo '<td><span class="badge ok">Config.</span> <a class="text-sm text-brand-600 underline ml-1" href="'.$safeLink.'" target="_blank" rel="noopener">Testar</a></td>';
  } else {
    echo '<td><span class="badge danger">Pendente</span></td>';
  }
  echo '<td>'.((int)$r['active']?'<span class="badge ok">Sim</span>':'<span class="badge danger">Não</span>').'</td>';
  echo '<td><div class="action-buttons">';
  if ($canManageProducts) {
    echo '<a class="btn btn-alt btn-sm" href="products.php?action=edit&id='.(int)$r['id'].'"><i class="fa-solid fa-pen"></i> Editar</a>';
  }
  if ($isSuperAdmin) {
    echo '<a class="btn btn-soft-warn btn-sm" href="products.php?action=delete&id='.(int)$r['id'].'&csrf='.csrf_token().'" onclick="return confirm(\'Desativar este produto?\')"><i class="fa-solid fa-ban"></i> Desativar</a>';
    echo '<a class="btn btn-danger btn-sm" href="products.php?action=destroy&id='.(int)$r['id'].'&csrf='.csrf_token().'" onclick="return confirm(\'Excluir definitivamente este produto?\')"><i class="fa-solid fa-trash"></i> Excluir</a>';
  }
  echo '</div></td>';
  echo '</tr>';
}
echo '</tbody></table></div>';
if ($isSuperAdmin) {
  echo '<div class="p-3 flex flex-wrap gap-2 items-center justify-end border-t">';
  echo '  <button type="submit" class="btn btn-soft-warn btn-sm" formaction="products.php?action=bulk_badge&mode=enable"><i class="fa-solid fa-ribbon mr-2"></i>Ativar tarja</button>';
  echo '  <button type="submit" class="btn btn-ghost btn-sm" formaction="products.php?action=bulk_badge&mode=disable"><i class="fa-solid fa-ban mr-2"></i>Remover tarja</button>';
  echo '  <button type="submit" class="btn btn-danger btn-sm" formaction="products.php?action=bulk_destroy" onclick="return confirm(\'Excluir definitivamente os itens selecionados?\')"><i class="fa-solid fa-trash-can mr-2"></i>Excluir selecionados</button>';
  echo '</div>';
}
echo '</form></div>';
echo '<script>
document.getElementById("checkAllProducts")?.addEventListener("change", function(e){
  const checked = e.target.checked;
  document.querySelectorAll(".product-select").forEach(cb => cb.checked = checked);
});
</script>';

admin_footer();
