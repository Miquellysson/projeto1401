<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('require_admin')) {
  function require_admin(){
    if (empty($_SESSION['admin_id'])) {
      header('Location: admin.php?route=login');
      exit;
    }
  }
}
if (!function_exists('csrf_token')) {
  function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_check')) {
  function csrf_check($token){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token); }
}

require_admin();

$pdo = db();
$canEditSettings = admin_can('manage_settings');
$canManagePayments = admin_can('manage_payment_methods');
$canManageBuilder = admin_can('manage_builder');
$isSuperAdmin = is_super_admin();

function pm_sanitize($value, $max = 255) {
  $value = trim((string)$value);
  if (mb_strlen($value) > $max) {
    $value = mb_substr($value, 0, $max);
  }
  return $value;
}

function pm_clip_text($value, $max = 8000) {
  $value = (string)$value;
  if (mb_strlen($value) > $max) {
    $value = mb_substr($value, 0, $max);
  }
  return $value;
}

function pm_safe_html($value, $allowed = '<br><strong><em><span>', $max = 8000) {
  $value = pm_clip_text($value, $max);
  $value = trim((string)$value);
  $value = strip_tags($value, $allowed);
  return $value;
}

function pm_slug($text) {
  $text = strtolower($text);
  $text = preg_replace('/[^a-z0-9\-]+/i', '-', $text);
  $text = trim($text, '-');
  return $text ?: 'metodo';
}

function pm_decode_settings($row) {
  $settings = [];
  if (!empty($row['settings'])) {
    $json = json_decode($row['settings'], true);
    if (is_array($json)) {
      $settings = $json;
    }
  }
  return $settings;
}

function pm_collect_settings($type, array $data) {
  $settings = [
    'type' => $type,
    'account_label' => pm_sanitize($data['account_label'] ?? '', 120),
    'account_value' => pm_sanitize($data['account_value'] ?? '', 255),
    'button_bg' => pm_sanitize($data['button_bg'] ?? '#dc2626', 20),
    'button_text' => pm_sanitize($data['button_text'] ?? '#ffffff', 20),
    'button_hover_bg' => pm_sanitize($data['button_hover_bg'] ?? '#b91c1c', 20),
  ];

  switch ($type) {
    case 'pix':
      $settings['pix_key'] = pm_sanitize($data['pix_key'] ?? '', 140);
      $settings['merchant_name'] = pm_sanitize($data['pix_merchant_name'] ?? '', 120);
      $settings['merchant_city'] = pm_sanitize($data['pix_merchant_city'] ?? '', 60);
      break;
    case 'zelle':
      $settings['recipient_name'] = pm_sanitize($data['zelle_recipient_name'] ?? '', 120);
      break;
    case 'venmo':
      $settings['venmo_link'] = pm_sanitize($data['venmo_link'] ?? '', 255);
      break;
    case 'paypal':
      $settings['business'] = pm_sanitize($data['paypal_business'] ?? '', 180);
      $settings['currency'] = strtoupper(pm_sanitize($data['paypal_currency'] ?? 'USD', 3));
      $settings['return_url'] = pm_sanitize($data['paypal_return_url'] ?? '', 255);
      $settings['cancel_url'] = pm_sanitize($data['paypal_cancel_url'] ?? '', 255);
      $settings['mode'] = pm_sanitize($data['paypal_mode'] ?? 'standard', 60);
      $settings['redirect_url'] = pm_sanitize($data['paypal_redirect_url'] ?? '', 255);
      $settings['open_new_tab'] = !empty($data['paypal_open_new_tab']);
      break;
    case 'whatsapp':
      $settings['number'] = pm_sanitize($data['whatsapp_number'] ?? '', 60);
      $settings['message'] = pm_clip_text($data['whatsapp_message'] ?? '', 500);
      $settings['link'] = pm_sanitize($data['whatsapp_link'] ?? '', 255);
      break;
    case 'square':
      $settings['mode'] = pm_sanitize($data['square_mode'] ?? 'square_product_link', 60);
      $settings['open_new_tab'] = !empty($data['square_open_new_tab']);
      $settings['redirect_url'] = pm_sanitize($data['square_redirect_url'] ?? '', 255);
      $settings['badge_title'] = pm_safe_html($data['square_badge_title'] ?? 'Seleção especial', '<br><strong><em><span>', 240);
      $settings['badge_text'] = pm_safe_html($data['square_badge_text'] ?? 'Selecionados com carinho para você', '<br><strong><em><span>', 400);
      $settings['credit_label'] = pm_sanitize($data['square_credit_label'] ?? 'Cartão de crédito', 80);
      $settings['credit_link'] = pm_sanitize($data['square_credit_link'] ?? '', 255);
      $settings['debit_label'] = pm_sanitize($data['square_debit_label'] ?? 'Cartão de débito', 80);
      $settings['debit_link'] = pm_sanitize($data['square_debit_link'] ?? '', 255);
      $settings['afterpay_label'] = pm_sanitize($data['square_afterpay_label'] ?? 'Afterpay', 80);
      $settings['afterpay_link'] = pm_sanitize($data['square_afterpay_link'] ?? '', 255);
      break;
    case 'stripe':
      $settings['mode'] = pm_sanitize($data['stripe_mode'] ?? 'stripe_product_link', 60);
      $settings['open_new_tab'] = !empty($data['stripe_open_new_tab']);
      $settings['redirect_url'] = pm_sanitize($data['stripe_redirect_url'] ?? '', 255);
      break;
    default:
      $settings['mode'] = pm_sanitize($data['custom_mode'] ?? 'manual', 60);
      $settings['redirect_url'] = pm_sanitize($data['custom_redirect_url'] ?? '', 255);
      break;
  }

  return $settings;
}

function pm_normalize_color(string $value, string $fallback = '#2060C8'): string {
  $value = trim($value);
  if ($value === '') {
    $value = $fallback;
  }
  return '#'.normalize_hex_color($value);
}

function hero_sanitize_icon(?string $icon, string $fallback = 'fa-star'): string {
  $icon = trim((string)$icon);
  if ($icon === '') {
    $icon = $fallback;
  }
  $icon = preg_replace('/[^a-z0-9\\- ]/i', '', $icon);
  if ($icon === '') {
    $icon = $fallback;
  }
  if (strpos($icon, 'fa-') !== 0) {
    $icon = 'fa-'.ltrim($icon, '-');
  }
  return $icon;
}

function pm_decode_if_base64(string $value): string {
  if (strpos($value, '__B64__') === 0) {
    $decoded = base64_decode(substr($value, 7), true);
    if ($decoded !== false) {
      $value = $decoded;
    }
  }
  return $value;
}

function pm_upload_icon(array $file) {
  if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
    return [true, null];
  }
  $validation = validate_file_upload($file, ['image/jpeg','image/png','image/webp','image/svg+xml'], 1024 * 1024);
  if (!$validation['success']) {
    return [false, $validation['message'] ?? 'Arquivo inválido'];
  }
  $dir = __DIR__.'/storage/payment_icons';
  @mkdir($dir, 0775, true);
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp','svg'], true)) {
    $map = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/webp' => 'webp',
      'image/svg+xml' => 'svg'
    ];
    $ext = $map[$validation['mime_type'] ?? ''] ?? 'png';
  }
  $filename = 'pm_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
  $dest = $dir.'/'.$filename;
  if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    return [false, 'Falha ao salvar ícone'];
  }
  return [true, 'storage/payment_icons/'.$filename];
}


$rawAction = $_POST['task'] ?? $_GET['task'] ?? ($_POST['action'] ?? ($_GET['action'] ?? 'list'));
$action = is_string($rawAction) ? $rawAction : 'list';
$tab = $_GET['tab'] ?? 'general';
if (!in_array($tab, ['general','payments','builder','checkout'], true)) {
  $tab = 'general';
}

if ($action === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_admin_capability('manage_payment_methods');
  header('Content-Type: application/json; charset=utf-8');
  $payload = json_decode(file_get_contents('php://input'), true);
  $csrf = $payload['csrf'] ?? '';
  if (!csrf_check($csrf)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
  }
  $ids = $payload['ids'] ?? [];
  if (!is_array($ids) || !$ids) {
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
  }
  $order = 0;
  $st = $pdo->prepare('UPDATE payment_methods SET sort_order = ? WHERE id = ?');
  foreach ($ids as $id) {
    $order += 10;
    $st->execute([$order, (int)$id]);
  }
  echo json_encode(['ok' => true]);
  exit;
}

if ($action === 'toggle' && isset($_GET['id'])) {
  if (!csrf_check($_GET['csrf'] ?? '')) die('CSRF');
  require_admin_capability('manage_payment_methods');
  $id = (int)$_GET['id'];
  $pdo->prepare('UPDATE payment_methods SET is_active = IF(is_active=1,0,1) WHERE id=?')->execute([$id]);
  header('Location: settings.php?tab=payments');
  exit;
}

if ($action === 'delete' && isset($_GET['id'])) {
  if (!csrf_check($_GET['csrf'] ?? '')) die('CSRF');
  require_super_admin();
  $id = (int)$_GET['id'];
  $pdo->prepare('DELETE FROM payment_methods WHERE id=?')->execute([$id]);
  header('Location: settings.php?tab=payments');
  exit;
}

if (($action === 'create' || $action === 'update') && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  require_admin_capability('manage_payment_methods');

  $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
  $name = pm_sanitize($_POST['name'] ?? '');
  if ($name === '') {
    die('Nome obrigatório');
  }
  $codeInput = pm_sanitize($_POST['code'] ?? '', 50);
  $type = pm_sanitize($_POST['method_type'] ?? 'custom', 50);
  $code = $codeInput ?: pm_slug($name);
  if ($type !== 'custom') {
    $code = $type;
  }

  $description = pm_sanitize($_POST['description'] ?? '', 500);
  $instructions = trim((string)($_POST['instructions'] ?? ''));
  $publicNote = pm_clip_text($_POST['public_note'] ?? '', 2000);
  $isActive = isset($_POST['is_active']) ? 1 : 0;
  $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
  $requireReceipt = isset($_POST['require_receipt']) ? 1 : 0;

  $settings = pm_collect_settings($type, $_POST);

  if ($type === 'square' && $isActive) {
    $mode = $settings['mode'] ?? 'square_product_link';
    $hasLink = false;
    if (!empty($settings['credit_link']) || !empty($settings['debit_link']) || !empty($settings['afterpay_link'])) {
      $hasLink = true;
    }
    if (!$hasLink && $mode === 'direct_url' && !empty($settings['redirect_url'])) {
      $hasLink = true;
    }
    if (!$hasLink) {
      die('Configure ao menos um link (crédito, débito, Afterpay ou URL fixa) antes de ativar o cartão de crédito (Square).');
    }
  }

  $iconPath = null;
  if ($action === 'update') {
    $st = $pdo->prepare('SELECT icon_path FROM payment_methods WHERE id=?');
    $st->execute([$id]);
    $iconPath = $st->fetchColumn();
  }

  if (!empty($_FILES['icon']['name'])) {
    [$ok, $result] = pm_upload_icon($_FILES['icon']);
    if (!$ok) {
      die('Erro no upload de ícone: '.$result);
    }
    if ($iconPath && file_exists(__DIR__.'/'.$iconPath)) {
      @unlink(__DIR__.'/'.$iconPath);
    }
    $iconPath = $result;
  }

  if ($action === 'create') {
    $check = $pdo->prepare('SELECT COUNT(*) FROM payment_methods WHERE code = ?');
    $check->execute([$code]);
    if ($check->fetchColumn()) {
      die('Código já utilizado por outro método.');
    }
    $ins = $pdo->prepare('INSERT INTO payment_methods(code,name,description,instructions,settings,icon_path,is_active,is_featured,require_receipt,sort_order,public_note) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $sortOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM payment_methods')->fetchColumn();
    $ins->execute([
      $code,
      $name,
      $description,
      $instructions,
      json_encode($settings, JSON_UNESCAPED_UNICODE),
      $iconPath,
      $isActive,
      $isFeatured,
      $requireReceipt,
      $sortOrder,
      $publicNote
    ]);
    $newId = (int)$pdo->lastInsertId();
    if ($isFeatured && $newId > 0) {
      $pdo->prepare('UPDATE payment_methods SET is_featured = 0 WHERE id <> ?')->execute([$newId]);
    }
  } else {
    $dup = $pdo->prepare('SELECT COUNT(*) FROM payment_methods WHERE code = ? AND id <> ?');
    $dup->execute([$code, $id]);
    if ($dup->fetchColumn()) {
      die('Outro método já utiliza este código.');
    }
    $upd = $pdo->prepare('UPDATE payment_methods SET code=?, name=?, description=?, instructions=?, settings=?, icon_path=?, is_active=?, is_featured=?, require_receipt=?, public_note=?, updated_at=NOW() WHERE id=?');
    $upd->execute([
      $code,
      $name,
      $description,
      $instructions,
      json_encode($settings, JSON_UNESCAPED_UNICODE),
      $iconPath,
      $isActive,
      $isFeatured,
      $requireReceipt,
      $publicNote,
      $id
    ]);
    if ($isFeatured && $id > 0) {
      $pdo->prepare('UPDATE payment_methods SET is_featured = 0 WHERE id <> ?')->execute([$id]);
    }
  }

  header('Location: settings.php?tab=payments');
  exit;
}

if ($action === 'save_general' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  require_admin_capability('manage_settings');
  $errors = [];

  $storeName = pm_sanitize($_POST['store_name'] ?? '', 120);
  if ($storeName === '') {
    $errors[] = 'Informe o nome da loja.';
  } else {
    setting_set('store_name', $storeName);
  }

  $storeEmail = trim((string)($_POST['store_email'] ?? ''));
  if ($storeEmail !== '') {
    if (validate_email($storeEmail)) {
      setting_set('store_email', $storeEmail);
    } else {
      $errors[] = 'E-mail de suporte inválido.';
    }
  } else {
    setting_set('store_email', '');
  }

  $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
  if ($adminEmail !== '') {
    if (validate_email($adminEmail)) {
      setting_set('admin_email', $adminEmail);
    } else {
      $errors[] = 'E-mail do admin inválido.';
    }
  } else {
    setting_set('admin_email', '');
  }

  $storePhone = pm_sanitize($_POST['store_phone'] ?? '', 60);
  setting_set('store_phone', $storePhone);

  $storeAddress = pm_sanitize($_POST['store_address'] ?? '', 240);
  setting_set('store_address', $storeAddress);

  $metaTitle = pm_sanitize($_POST['store_meta_title'] ?? '', 160);
  if ($metaTitle === '') {
    $metaTitle = ($storeName ?: 'Get Power Research').' | Loja';
  }
  setting_set('store_meta_title', $metaTitle);

  $pwaName = pm_sanitize($_POST['pwa_name'] ?? '', 80);
  if ($pwaName === '') {
    $pwaName = $storeName ?: 'Get Power Research';
  }
  setting_set('pwa_name', $pwaName);

  $pwaShort = pm_sanitize($_POST['pwa_short_name'] ?? '', 40);
  if ($pwaShort === '') {
    $pwaShort = $pwaName;
  }
  setting_set('pwa_short_name', $pwaShort);

  if (!empty($_FILES['store_logo']['name'])) {
    $upload = save_logo_upload($_FILES['store_logo']);
    if (!empty($upload['success'])) {
      setting_set('store_logo_url', $upload['path']);
      setting_set('store_logo', $upload['path']);
    } else {
      $errors[] = $upload['message'] ?? 'Falha ao enviar logo.';
    }
  }

  $heroTitle = pm_sanitize($_POST['home_hero_title'] ?? '', 160);
  $heroSubtitle = pm_sanitize($_POST['home_hero_subtitle'] ?? '', 240);
  if ($heroTitle === '') {
    $heroTitle = 'Tudo para sua saúde';
  }
  if ($heroSubtitle === '') {
    $heroSubtitle = 'Experiência de app, rápida e segura.';
  }
  setting_set('home_hero_title', $heroTitle);
  setting_set('home_hero_subtitle', $heroSubtitle);

  $heroSearchPlaceholder = pm_sanitize($_POST['hero_search_placeholder'] ?? '', 160);
  if ($heroSearchPlaceholder === '') {
    $heroSearchPlaceholder = 'Buscar produtos, tratamentos...';
  }
  setting_set('hero_search_placeholder', $heroSearchPlaceholder);

  $heroSearchButton = pm_sanitize($_POST['hero_search_button_label'] ?? '', 60);
  if ($heroSearchButton === '') {
    $heroSearchButton = 'Buscar agora';
  }
  setting_set('hero_search_button_label', $heroSearchButton);

  $heroSupportLeft = pm_sanitize($_POST['hero_support_text_left'] ?? '', 160);
  if ($heroSupportLeft === '') {
    $heroSupportLeft = 'Atendimento humano rápido';
  }
  $heroSupportRight = pm_sanitize($_POST['hero_support_text_right'] ?? '', 160);
  if ($heroSupportRight === '') {
    $heroSupportRight = 'Produtos verificados';
  }
  setting_set('hero_support_text_left', $heroSupportLeft);
  setting_set('hero_support_text_right', $heroSupportRight);

  $heroBackgroundType = $_POST['hero_background_type'] ?? setting_get('hero_background_type', setting_get('hero_background', 'gradient'));
  $heroBackgroundType = in_array($heroBackgroundType, ['gradient','solid','image'], true) ? $heroBackgroundType : 'gradient';
  $heroGradientFrom = pm_normalize_color($_POST['hero_gradient_from'] ?? '#2060C8', '#2060C8');
  $heroGradientTo = pm_normalize_color($_POST['hero_gradient_to'] ?? '#0F172A', '#0F172A');
  $heroSolidColor = pm_normalize_color($_POST['hero_solid_color'] ?? '#2060C8', '#2060C8');
  $heroBackgroundImage = trim((string)($_POST['hero_background_image_url'] ?? setting_get('hero_background_image', '')));
  if (!empty($_POST['hero_background_image_remove'])) {
    $heroBackgroundImage = '';
  }
  if (!empty($_FILES['hero_background_upload']['name'])) {
    $upload = save_hero_background_upload($_FILES['hero_background_upload']);
    if (!empty($upload['success'])) {
      $heroBackgroundImage = $upload['path'];
    } else {
      $errors[] = $upload['message'] ?? 'Falha ao enviar imagem do fundo.';
    }
  }
  if ($heroBackgroundImage !== '') {
    if (filter_var($heroBackgroundImage, FILTER_VALIDATE_URL) === false) {
      $heroBackgroundImage = ltrim($heroBackgroundImage);
    }
  }
  setting_set('hero_background_type', $heroBackgroundType);
  setting_set('hero_background', $heroBackgroundType);
  setting_set('hero_gradient_from', $heroGradientFrom);
  setting_set('hero_gradient_to', $heroGradientTo);
  setting_set('hero_solid_color', $heroSolidColor);
  setting_set('hero_accent_color', $heroGradientFrom);
  setting_set('hero_background_image', $heroBackgroundImage);

  $heroIcons = $_POST['hero_highlights_icon'] ?? [];
  $heroTitles = $_POST['hero_highlights_title'] ?? [];
  $heroDescs = $_POST['hero_highlights_desc'] ?? [];
  $heroHighlights = [];
  $heroDefaults = hero_default_highlights();
  for ($i = 0; $i < 4; $i++) {
    $default = $heroDefaults[$i] ?? $heroDefaults[0];
    $icon = hero_sanitize_icon($heroIcons[$i] ?? $default['icon'], $default['icon']);
    $title = pm_sanitize($heroTitles[$i] ?? $default['title'], 80);
    if ($title === '') {
      $title = $default['title'];
    }
    $desc = pm_sanitize($heroDescs[$i] ?? $default['desc'], 200);
    if ($desc === '') {
      $desc = $default['desc'];
    }
  $heroHighlights[] = [
    'icon' => $icon,
    'title' => $title,
    'desc' => $desc,
  ];
}
setting_set('hero_highlights', $heroHighlights);

  $badgeColor = pm_normalize_color($_POST['badge_one_month_color'] ?? '#0EA5E9', '#0EA5E9');
  setting_set('badge_one_month_color', $badgeColor);

  $featuredEnabled = isset($_POST['home_featured_enabled']) ? '1' : '0';
  $featuredTitle = pm_sanitize($_POST['home_featured_title'] ?? '', 80);
  $featuredSubtitle = pm_safe_html($_POST['home_featured_subtitle'] ?? '', '<br><strong><em><span>', 400);
  $featuredLabel = pm_sanitize($_POST['home_featured_label'] ?? '', 80);
  $featuredBadgeTitle = pm_safe_html($_POST['home_featured_badge_title'] ?? '', '<br><strong><em><span>', 240);
  $featuredBadgeText = pm_safe_html($_POST['home_featured_badge_text'] ?? '', '<br><strong><em><span>', 400);
  if ($featuredTitle === '') {
    $featuredTitle = 'Ofertas em destaque';
  }
  if ($featuredSubtitle === '') {
    $featuredSubtitle = 'Seleção especial com preços imperdíveis.';
  }
  if ($featuredLabel === '') {
    $featuredLabel = 'Oferta destaque';
  }
  if ($featuredBadgeTitle === '') {
    $featuredBadgeTitle = 'Seleção especial';
  }
  if ($featuredBadgeText === '') {
    $featuredBadgeText = 'Selecionados com carinho para você';
  }
  setting_set('home_featured_enabled', $featuredEnabled);
  setting_set('home_featured_title', $featuredTitle);
  setting_set('home_featured_subtitle', $featuredSubtitle);
  setting_set('home_featured_label', $featuredLabel);
  setting_set('home_featured_badge_title', $featuredBadgeTitle);
  setting_set('home_featured_badge_text', $featuredBadgeText);

  $footerCopyInput = pm_decode_if_base64($_POST['footer_copy'] ?? '');
  $footerCopy = pm_clip_text($footerCopyInput, 280);
  if ($footerCopy === '') {
    $footerCopy = '© {{year}} '.($storeName ?: 'Sua Loja').'. Todos os direitos reservados.';
  }
  setting_set('footer_copy', $footerCopy);

  $emailDefaultSet = email_template_defaults($storeName ?: (cfg()['store']['name'] ?? 'Sua Loja'));
  $emailCustomerSubject = pm_sanitize($_POST['email_customer_subject'] ?? '', 180);
  if ($emailCustomerSubject === '') {
    $emailCustomerSubject = $emailDefaultSet['customer_subject'];
  }
  setting_set('email_customer_subject', $emailCustomerSubject);

  $emailCustomerBody = pm_clip_text(pm_decode_if_base64($_POST['email_customer_body'] ?? ''), 8000);
  if ($emailCustomerBody === '') {
    $emailCustomerBody = $emailDefaultSet['customer_body'];
  }
  setting_set('email_customer_body', $emailCustomerBody);

  $emailAdminSubject = pm_sanitize($_POST['email_admin_subject'] ?? '', 180);
  if ($emailAdminSubject === '') {
    $emailAdminSubject = $emailDefaultSet['admin_subject'];
  }
  setting_set('email_admin_subject', $emailAdminSubject);

  $emailAdminBody = pm_clip_text(pm_decode_if_base64($_POST['email_admin_body'] ?? ''), 8000);
  if ($emailAdminBody === '') {
    $emailAdminBody = $emailDefaultSet['admin_body'];
  }
  setting_set('email_admin_body', $emailAdminBody);

  $whatsEnabled = isset($_POST['whatsapp_enabled']) ? '1' : '0';
  $whatsNumberRaw = pm_sanitize($_POST['whatsapp_number'] ?? '', 40);
  $whatsNumber = preg_replace('/\D+/', '', $whatsNumberRaw);
  $whatsButtonText = pm_sanitize($_POST['whatsapp_button_text'] ?? '', 80);
  $whatsMessage = pm_sanitize($_POST['whatsapp_message'] ?? '', 400);
  if ($whatsButtonText === '') {
    $whatsButtonText = 'Fale com a gente';
  }
  if ($whatsMessage === '') {
    $whatsMessage = 'Olá! Gostaria de tirar uma dúvida sobre os produtos.';
  }
  setting_set('whatsapp_enabled', $whatsEnabled);
  setting_set('whatsapp_number', $whatsNumber);
  setting_set('whatsapp_button_text', $whatsButtonText);
  setting_set('whatsapp_message', $whatsMessage);

  $twilioEnabled = isset($_POST['whatsapp_twilio_enabled']) ? '1' : '0';
  $twilioSid = pm_sanitize($_POST['whatsapp_twilio_sid'] ?? '', 64);
  $twilioToken = pm_sanitize($_POST['whatsapp_twilio_token'] ?? '', 120);
  $twilioFrom = pm_sanitize($_POST['whatsapp_twilio_from'] ?? '', 40);
  $twilioAdminTo = pm_clip_text($_POST['whatsapp_twilio_admin_to'] ?? '', 220);
  $twilioDefaults = whatsapp_template_defaults($storeName ?: (cfg()['store']['name'] ?? 'Sua Loja'));
  $twilioCustomerTemplate = pm_clip_text($_POST['whatsapp_twilio_customer_template'] ?? '', 600);
  $twilioAdminTemplate = pm_clip_text($_POST['whatsapp_twilio_admin_template'] ?? '', 600);
  if ($twilioCustomerTemplate === '') {
    $twilioCustomerTemplate = $twilioDefaults['customer'];
  }
  if ($twilioAdminTemplate === '') {
    $twilioAdminTemplate = $twilioDefaults['admin'];
  }
  setting_set('whatsapp_twilio_enabled', $twilioEnabled);
  setting_set('whatsapp_twilio_sid', $twilioSid);
  setting_set('whatsapp_twilio_token', $twilioToken);
  setting_set('whatsapp_twilio_from', $twilioFrom);
  setting_set('whatsapp_twilio_admin_to', $twilioAdminTo);
  setting_set('whatsapp_twilio_customer_template', $twilioCustomerTemplate);
  setting_set('whatsapp_twilio_admin_template', $twilioAdminTemplate);

  if (!empty($_FILES['pwa_icon']['name'])) {
    $pwaUpload = save_pwa_icon_upload($_FILES['pwa_icon']);
    if (empty($pwaUpload['success'])) {
      $errors[] = $pwaUpload['message'] ?? 'Falha ao atualizar o ícone do app.';
    }
  }
  $themeColor = pm_sanitize($_POST['theme_color'] ?? '#2060C8', 20);
  if (!preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $themeColor)) {
    $themeColor = '#2060C8';
  }
  setting_set('theme_color', strtoupper($themeColor));
  $headerSublineNew = pm_sanitize($_POST['header_subline'] ?? '', 120);
  if ($headerSublineNew === '') $headerSublineNew = 'Loja Online';
  setting_set('header_subline', $headerSublineNew);
  $footerTitleNew = pm_sanitize($_POST['footer_title'] ?? '', 80);
  if ($footerTitleNew === '') $footerTitleNew = 'Get Power Research';
  setting_set('footer_title', $footerTitleNew);
  $footerDescriptionNew = pm_sanitize($_POST['footer_description'] ?? '', 160);
  if ($footerDescriptionNew === '') $footerDescriptionNew = 'Sua loja online com experiência de app.';
  setting_set('footer_description', $footerDescriptionNew);

  $googleAnalyticsCode = pm_clip_text(pm_decode_if_base64($_POST['google_analytics_code'] ?? ''), 8000);
  setting_set('google_analytics_code', $googleAnalyticsCode);

  $policyAllowedTags = '<p><br><strong><em><span><ul><ol><li><a><h1><h2><h3>';
  $privacyContent = pm_safe_html(pm_decode_if_base64($_POST['privacy_policy_content'] ?? ''), $policyAllowedTags, 10000);
  $refundContent = pm_safe_html(pm_decode_if_base64($_POST['refund_policy_content'] ?? ''), $policyAllowedTags, 10000);
  setting_set('privacy_policy_content', $privacyContent);
  setting_set('refund_policy_content', $refundContent);

  $smtpConfigIncoming = [
    'host' => trim((string)($_POST['smtp_host'] ?? '')),
    'port' => trim((string)($_POST['smtp_port'] ?? '')),
    'user' => trim((string)($_POST['smtp_user'] ?? '')),
    'pass' => trim((string)($_POST['smtp_pass'] ?? '')),
    'secure' => trim((string)($_POST['smtp_secure'] ?? 'tls')),
    'from_name' => trim((string)($_POST['smtp_from_name'] ?? '')),
    'from_email' => trim((string)($_POST['smtp_from_email'] ?? '')),
  ];
  $hasSmtpConfig = array_filter($smtpConfigIncoming, fn($value, $key) => $key !== 'secure' && $value !== '', ARRAY_FILTER_USE_BOTH);
  if ($hasSmtpConfig) {
    setting_set('smtp_config', $smtpConfigIncoming);
  } else {
    setting_set('smtp_config', '');
  }

  if ($errors) {
    $_SESSION['settings_general_error'] = implode(' ', $errors);
    header('Location: settings.php?tab=general&error=1');
    exit;
  }

  header('Location: settings.php?tab=general&saved=1');
  exit;
}

if ($action === 'save_checkout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  require_admin_capability('manage_settings');

  $countriesRaw = trim((string)($_POST['checkout_countries'] ?? ''));
  $statesRaw = trim((string)($_POST['checkout_states'] ?? ''));
  $deliveryRaw = trim((string)($_POST['checkout_delivery_methods'] ?? ''));
  $secureDeliveryRaw = trim((string)($_POST['checkout_secure_delivery_price'] ?? ''));

  $countriesParsed = [];
  if ($countriesRaw !== '') {
    foreach (preg_split('/\r?\n/', $countriesRaw) as $line) {
      $line = trim($line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) < 2) continue;
      $code = strtoupper(pm_sanitize($parts[0], 5));
      $label = pm_sanitize($parts[1], 80);
      if ($code === '' || $label === '') continue;
      $countriesParsed[$code] = ['code' => $code, 'name' => $label];
    }
  }
  if (!$countriesParsed) {
    foreach (checkout_default_countries() as $defaultCountry) {
      $code = strtoupper($defaultCountry['code']);
      $countriesParsed[$code] = ['code' => $code, 'name' => $defaultCountry['name']];
    }
  }

  $statesParsed = [];
  if ($statesRaw !== '') {
    foreach (preg_split('/\r?\n/', $statesRaw) as $line) {
      $line = trim($line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) === 3) {
        [$countryCode, $stateCode, $stateName] = $parts;
      } elseif (count($parts) === 2) {
        $countryCode = 'US';
        [$stateCode, $stateName] = $parts;
      } else {
        continue;
      }
      $countryCode = strtoupper(pm_sanitize($countryCode, 5));
      $stateCode = strtoupper(pm_sanitize($stateCode, 10));
      $stateName = pm_sanitize($stateName, 100);
      if ($countryCode === '' || $stateCode === '' || $stateName === '') continue;
      $statesParsed[] = [
        'country' => $countryCode,
        'code' => $stateCode,
        'name' => $stateName,
      ];
    }
  }
  if (!$statesParsed) {
    $statesParsed = checkout_default_states();
  }

  $deliveryParsed = [];
  if ($deliveryRaw !== '') {
    foreach (preg_split('/\r?\n/', $deliveryRaw) as $line) {
      $line = trim($line);
      if ($line === '') continue;
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) < 2) continue;
      $codeInput = $parts[0];
      $label = pm_sanitize($parts[1], 120);
      $description = pm_clip_text($parts[2] ?? '', 255);
      if ($label === '') continue;
      $normalizedCode = strtolower(preg_replace('/[^a-z0-9\-_]+/i', '-', $codeInput));
      if ($normalizedCode === '') {
        $normalizedCode = strtolower(preg_replace('/[^a-z0-9\-_]+/i', '-', slugify($label)));
      }
      if ($normalizedCode === '') {
        $normalizedCode = 'method';
      }
      $deliveryParsed[$normalizedCode] = [
        'code' => $normalizedCode,
        'name' => $label,
        'description' => $description,
      ];
    }
  }
  if (!$deliveryParsed) {
    foreach (checkout_default_delivery_methods() as $method) {
      $normalized = strtolower(preg_replace('/[^a-z0-9\-_]+/i', '-', $method['code'] ?? slugify($method['name'] ?? 'method')));
      $deliveryParsed[$normalized] = [
        'code' => $normalized,
        'name' => $method['name'] ?? 'Entrega padrão',
        'description' => $method['description'] ?? '',
      ];
    }
  }

  $countriesList = array_values($countriesParsed);
  $defaultCountrySubmitted = strtoupper(trim((string)($_POST['checkout_default_country'] ?? '')));
  $secureDeliveryPrice = (float)str_replace(',', '.', $secureDeliveryRaw);
  $secureDeliveryPrice = $secureDeliveryPrice < 0 ? 0 : round($secureDeliveryPrice, 2);
  $validCountryCodes = array_map(function ($c) { return strtoupper($c['code']); }, $countriesList);
  if (!in_array($defaultCountrySubmitted, $validCountryCodes, true)) {
    $defaultCountrySubmitted = $countriesList[0]['code'] ?? 'US';
  }

  setting_set('checkout_countries', $countriesList);
  setting_set('checkout_states', $statesParsed);
  setting_set('checkout_delivery_methods', array_values($deliveryParsed));
  setting_set('checkout_default_country', $defaultCountrySubmitted ?: 'US');
  setting_set('checkout_secure_delivery_price', $secureDeliveryPrice);

  header('Location: settings.php?tab=checkout&saved=1');
  exit;
}

try {
$methods = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$hasWhatsapp = false;
foreach ($methods as $m) {
  if (($m['code'] ?? '') === 'whatsapp') {
    $hasWhatsapp = true;
    break;
  }
}
if (!$hasWhatsapp && $canManagePayments) {
  $sortOrder = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM payment_methods')->fetchColumn();
  $settingsJson = json_encode([
    'type' => 'whatsapp',
    'account_label' => 'WhatsApp',
    'account_value' => '',
    'number' => '',
    'message' => 'Olá! Gostaria de finalizar meu pedido.',
    'link' => ''
  ], JSON_UNESCAPED_UNICODE);
  $insWhatsapp = $pdo->prepare('INSERT INTO payment_methods(code,name,description,instructions,settings,icon_path,is_active,require_receipt,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
  $insWhatsapp->execute([
    'whatsapp',
    'WhatsApp',
    '',
    'Converse com nossa equipe pelo WhatsApp para concluir: {whatsapp_link}.',
    $settingsJson,
    null,
    0,
    0,
    $sortOrder
  ]);
  $methods = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
}
} catch (Throwable $e) {
  $methods = [];
}

$editRow = null;
$editSettings = [];
if ($action === 'edit' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $st = $pdo->prepare('SELECT * FROM payment_methods WHERE id=?');
  $st->execute([$id]);
  $editRow = $st->fetch(PDO::FETCH_ASSOC);
  if ($editRow) {
    $editSettings = pm_decode_settings($editRow);
    $tab = 'payments';
  }
}

$draftStmt = $pdo->prepare("SELECT content, styles FROM page_layouts WHERE page_slug=? AND status='draft' LIMIT 1");
$draftStmt->execute(['home']);
$draftRow = $draftStmt->fetch(PDO::FETCH_ASSOC);

$publishedStmt = $pdo->prepare("SELECT content, styles FROM page_layouts WHERE page_slug=? AND status='published' LIMIT 1");
$publishedStmt->execute(['home']);
$publishedRow = $publishedStmt->fetch(PDO::FETCH_ASSOC);

$layoutData = [
  'draft' => $draftRow ?: null,
  'published' => $publishedRow ?: null,
  'csrf' => csrf_token(),
];
$layoutJson = json_encode($layoutData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$storeCfg = cfg()['store'] ?? [];
$storeNameCurrent = setting_get('store_name', $storeCfg['name'] ?? 'Get Power Research');
$storeEmailCurrent = setting_get('store_email', $storeCfg['support_email'] ?? 'contato@example.com');
$adminEmailCurrent = setting_get('admin_email', defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '');
$storePhoneCurrent = setting_get('store_phone', $storeCfg['phone'] ?? '');
$storeAddressCurrent = setting_get('store_address', $storeCfg['address'] ?? '');
$storeLogoCurrent = get_logo_path();
$storeLogoPreview = $storeLogoCurrent ? versioned_public_url($storeLogoCurrent) : '';
$generalError = $_SESSION['settings_general_error'] ?? '';
unset($_SESSION['settings_general_error']);

$checkoutCountriesCurrent = checkout_get_countries();
$checkoutStatesCurrent = checkout_get_states();
$checkoutDeliveryCurrent = checkout_get_delivery_methods();
$checkoutCountriesText = implode("\n", array_filter(array_map(function ($entry) {
  $code = strtoupper($entry['code'] ?? '');
  $name = $entry['name'] ?? '';
  return $code !== '' ? $code.'|'.$name : '';
}, $checkoutCountriesCurrent)));
$checkoutStatesText = implode("\n", array_filter(array_map(function ($entry) {
  $country = strtoupper($entry['country'] ?? 'US');
  $code = strtoupper($entry['code'] ?? '');
  $name = $entry['name'] ?? '';
  return ($country && $code && $name) ? $country.'|'.$code.'|'.$name : '';
}, $checkoutStatesCurrent)));
$checkoutDeliveryText = implode("\n", array_filter(array_map(function ($entry) {
  $code = $entry['code'] ?? '';
  $name = $entry['name'] ?? '';
  $description = $entry['description'] ?? '';
  return ($code && $name) ? $code.'|'.$name.'|'.$description : '';
}, $checkoutDeliveryCurrent)));
$checkoutDefaultCountryCurrent = setting_get('checkout_default_country', $checkoutCountriesCurrent[0]['code'] ?? 'US');
$checkoutDefaultCountryCurrent = strtoupper(trim((string)$checkoutDefaultCountryCurrent));
$checkoutSecureDeliveryPriceCurrent = (float)setting_get('checkout_secure_delivery_price', 0);

$sections = [
  [
    'key' => 'general',
    'title' => 'Dados da loja',
    'description' => 'Nome, endereço, telefone, e-mail e logo exibidos para os clientes.',
    'icon' => 'fa-store'
  ],
  [
    'key' => 'payments',
    'title' => 'Pagamentos',
    'description' => 'Configure métodos ativos, instruções personalizadas e ordem de exibição.',
    'icon' => 'fa-credit-card'
  ],
  [
    'key' => 'checkout',
    'title' => 'Checkout',
    'description' => 'Defina países, estados e métodos de entrega exibidos no checkout.',
    'icon' => 'fa-truck-fast'
  ],
  [
    'key' => 'builder',
    'title' => 'Editor da Home',
    'description' => 'Personalize a página inicial com o editor visual (drag-and-drop).',
    'icon' => 'fa-paintbrush'
  ],
];

admin_header('Configurações');
?>
<section class="space-y-6">
  <div class="dashboard-hero">
    <div class="flex flex-col gap-3">
      <div>
        <h1 class="text-2xl md:text-3xl font-bold">Configurações da plataforma</h1>
        <p class="text-white/90 text-sm md:text-base mt-1">Ajuste rapidamente informações da loja, pagamentos e layout da home.</p>
      </div>
      <div class="quick-links">
        <a class="quick-link" href="settings.php?tab=general">
          <span class="icon"><i class="fa-solid fa-store"></i></span>
          <span><div class="font-semibold">Dados gerais</div><div class="text-xs opacity-80">Logo, contatos e textos da vitrine</div></span>
        </a>
        <a class="quick-link" href="settings.php?tab=payments">
          <span class="icon"><i class="fa-solid fa-credit-card"></i></span>
          <span><div class="font-semibold">Pagamentos</div><div class="text-xs opacity-80">Formas de pagamento e instruções</div></span>
        </a>
        <a class="quick-link" href="settings.php?tab=checkout">
          <span class="icon"><i class="fa-solid fa-truck-fast"></i></span>
          <span><div class="font-semibold">Checkout</div><div class="text-xs opacity-80">Campos, países e métodos de entrega</div></span>
        </a>
        <a class="quick-link" href="settings.php?tab=builder">
          <span class="icon"><i class="fa-solid fa-paintbrush"></i></span>
          <span><div class="font-semibold">Editor da home</div><div class="text-xs opacity-80">Monte a página inicial em tempo real</div></span>
        </a>
        <a class="quick-link" href="dashboard.php">
          <span class="icon"><i class="fa-solid fa-gauge-high"></i></span>
          <span><div class="font-semibold">Voltar ao dashboard</div><div class="text-xs opacity-80">Resumo da operação e pedidos</div></span>
        </a>
      </div>
    </div>
  </div>

  <div class="tab-controls">
    <?php foreach ($sections as $section): ?>
      <a href="settings.php?tab=<?= $section['key']; ?>" class="<?= $tab === $section['key'] ? 'active' : ''; ?>">
        <i class="fa-solid <?= $section['icon']; ?> mr-2"></i><?= sanitize_html($section['title']); ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="settings-grid">
  <div data-tab-panel="general" class="card <?= $tab === 'general' ? '' : 'hidden'; ?>">
    <div class="card-body settings-form">
    <h2 class="text-lg font-semibold mb-1">Informações da Loja</h2>
    <?php if (isset($_GET['saved'])): ?>
      <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <span>Configurações atualizadas com sucesso.</span>
      </div>
    <?php endif; ?>
    <?php if ($generalError): ?>
      <div class="alert alert-error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?= sanitize_html($generalError); ?></span>
      </div>
    <?php endif; ?>
    <?php
      $heroTitleCurrent = setting_get('home_hero_title', 'Tudo para sua saúde');
      $heroSubtitleCurrent = setting_get('home_hero_subtitle', 'Experiência de app, rápida e segura.');
$heroSearchPlaceholderCurrent = setting_get('hero_search_placeholder', 'Buscar produtos, tratamentos...');
$heroSearchButtonCurrent = setting_get('hero_search_button_label', 'Buscar agora');
$heroSupportTextLeftCurrent = setting_get('hero_support_text_left', 'Atendimento humano rápido');
$heroSupportTextRightCurrent = setting_get('hero_support_text_right', 'Produtos verificados');
$heroBackgroundTypeCurrent = setting_get('hero_background_type', setting_get('hero_background', 'gradient'));
$heroGradientFromCurrent = setting_get('hero_gradient_from', '#2060C8');
$heroGradientToCurrent = setting_get('hero_gradient_to', '#0F172A');
$heroSolidColorCurrent = setting_get('hero_solid_color', '#2060C8');
$heroBackgroundImageCurrent = setting_get('hero_background_image', '');
$heroHighlightsCurrent = setting_get('hero_highlights', []);
$heroHighlightDefaults = hero_default_highlights();
for ($i = 0; $i < count($heroHighlightDefaults); $i++) {
  if (!isset($heroHighlightsCurrent[$i]) || !is_array($heroHighlightsCurrent[$i])) {
    $heroHighlightsCurrent[$i] = $heroHighlightDefaults[$i];
  } else {
    $heroHighlightsCurrent[$i] = array_merge($heroHighlightDefaults[$i], $heroHighlightsCurrent[$i]);
  }
}
$badgeColorCurrent = setting_get('badge_one_month_color', '#0EA5E9');
$featuredEnabledCurrent = (int)setting_get('home_featured_enabled', '0');
$featuredLabelCurrent = setting_get('home_featured_label', 'Oferta destaque');
$featuredTitleCurrent = setting_get('home_featured_title', 'Ofertas em destaque');
$featuredSubtitleCurrent = setting_get('home_featured_subtitle', 'Seleção especial com preços imperdíveis.');
$featuredBadgeTitleCurrent = setting_get('home_featured_badge_title', 'Seleção especial');
$featuredBadgeTextCurrent = setting_get('home_featured_badge_text', 'Selecionados com carinho para você');
$emailDefaults = email_template_defaults($storeNameCurrent ?: ($storeCfg['name'] ?? ''));
$smtpConfig = setting_get('smtp_config', ['host' => '', 'port' => '', 'user' => '', 'pass' => '', 'secure' => 'tls', 'from_name' => '', 'from_email' => '']);
$emailTemplateVersionTarget = 3;
$emailTemplateVersionCurrent = (int)setting_get('email_template_version', 0);
if ($emailTemplateVersionCurrent < $emailTemplateVersionTarget) {
  setting_set('email_customer_subject', $emailDefaults['customer_subject']);
  setting_set('email_customer_body', $emailDefaults['customer_body']);
  setting_set('email_admin_subject', $emailDefaults['admin_subject']);
  setting_set('email_admin_body', $emailDefaults['admin_body']);
  setting_set('email_template_version', $emailTemplateVersionTarget);
}
$emailCustomerSubjectCurrent = setting_get('email_customer_subject', $emailDefaults['customer_subject']);
  $emailCustomerBodyCurrent = setting_get('email_customer_body', $emailDefaults['customer_body']);
  $emailAdminSubjectCurrent = setting_get('email_admin_subject', $emailDefaults['admin_subject']);
  $emailAdminBodyCurrent = setting_get('email_admin_body', $emailDefaults['admin_body']);
$whatsappEnabled = (int)setting_get('whatsapp_enabled', '0');
$whatsappNumber = setting_get('whatsapp_number', '');
$whatsappButtonText = setting_get('whatsapp_button_text', 'Fale com a gente');
$whatsappMessage = setting_get('whatsapp_message', 'Olá! Gostaria de tirar uma dúvida sobre os produtos.');
$twilioDefaults = whatsapp_template_defaults($storeNameCurrent ?: ($storeCfg['name'] ?? 'Sua Loja'));
$twilioEnabledCurrent = (int)setting_get('whatsapp_twilio_enabled', '0');
$twilioSidCurrent = setting_get('whatsapp_twilio_sid', '');
$twilioTokenCurrent = setting_get('whatsapp_twilio_token', '');
$twilioFromCurrent = setting_get('whatsapp_twilio_from', '');
$twilioAdminToCurrent = setting_get('whatsapp_twilio_admin_to', '');
$twilioCustomerTemplateCurrent = setting_get('whatsapp_twilio_customer_template', $twilioDefaults['customer']);
$twilioAdminTemplateCurrent = setting_get('whatsapp_twilio_admin_template', $twilioDefaults['admin']);
$headerSublineCurrent = setting_get('header_subline', 'Loja Online');
$footerTitleCurrent = setting_get('footer_title', 'Get Power Research');
$footerDescriptionCurrent = setting_get('footer_description', 'Sua loja online com experiência de app.');
$footerCopyCurrent = setting_get('footer_copy', '© {{year}} '.($storeNameCurrent ?: 'Sua Loja').'. Todos os direitos reservados.');
$themeColorCurrent = setting_get('theme_color', '#2060C8');
$googleAnalyticsCurrent = setting_get('google_analytics_code', '');
$privacyPolicyCurrent = setting_get('privacy_policy_content', '');
$refundPolicyCurrent = setting_get('refund_policy_content', '');
$heroBackgroundCurrent = setting_get('hero_background', 'gradient');
$heroAccentColorCurrent = setting_get('hero_accent_color', '#F59E0B');
$metaTitleCurrent = setting_get('store_meta_title', ($storeNameCurrent ?: 'Get Power Research').' | Loja');
$pwaNameCurrent = setting_get('pwa_name', $storeNameCurrent ?: 'Get Power Research');
  $pwaShortNameCurrent = setting_get('pwa_short_name', $pwaNameCurrent);
  $pwaIcons = get_pwa_icon_paths();
  $pwaIconPreview = pwa_icon_url(192);
$sampleCurrency = cfg()['store']['currency'] ?? 'USD';
$sampleSubtotal = format_currency(250, $sampleCurrency);
$sampleShipping = format_currency(15, $sampleCurrency);
$sampleTotal = format_currency(265, $sampleCurrency);
$sampleTax = format_currency(0, $sampleCurrency);
$sampleDiscount = format_currency(0, $sampleCurrency);
$sampleItemsRows = '<tr><td>TIRZ 20 – Tirzepatide 20mg<br><span class="muted">SKU: TIRZ-001</span></td><td>1</td><td>'.$sampleSubtotal.'</td></tr>';
$sampleItemsList = '<ul style="padding-left:18px;margin:0;"><li>TIRZ 20 – Tirzepatide 20mg — Qtd: 1 — '.$sampleSubtotal.'</li><li>TIRZ 15 – Tirzepatide 15mg — Qtd: 1 — '.$sampleSubtotal.'</li></ul>';
$sampleAddress = '123 Wellness St.<br>Miami - FL 33101<br>Estados Unidos';
$emailPreviewSample = [
  'site_name' => $storeNameCurrent ?: 'Get Power Research',
  'store_name' => $storeNameCurrent ?: 'Get Power Research',
  'store_logo_url' => email_logo_url(),
  'order_id' => '1024',
  'order_number' => '#1024',
  'order_date' => date('d/m/Y H:i'),
  'customer_name' => 'Maria Silva',
  'customer_email' => $storeEmailCurrent ?: 'cliente@example.com',
  'customer_phone' => '(305) 555-0119',
  'billing_first_name' => 'Maria',
  'billing_last_name' => 'Silva',
  'billing_full_name' => 'Maria Silva',
  'billing_email' => 'cliente@example.com',
  'billing_email_href' => 'cliente@example.com',
  'billing_phone' => '(305) 555-0119',
  'billing_address1' => '123 Wellness St.',
  'billing_address2' => 'Apto 501',
  'billing_city' => 'Miami',
  'billing_state' => 'FL',
  'billing_postcode' => '33101',
  'billing_country' => 'Estados Unidos',
  'billing_address_html' => $sampleAddress,
  'billing_address' => $sampleAddress,
  'customer_full_address' => $sampleAddress,
  'shipping_address1' => '123 Wellness St.',
  'shipping_address2' => 'Apto 501',
  'shipping_city' => 'Miami',
  'shipping_state' => 'FL',
  'shipping_postcode' => '33101',
  'shipping_country' => 'Estados Unidos',
  'shipping_address_html' => $sampleAddress,
  'shipping_address' => $sampleAddress,
  'shipping_full_address' => $sampleAddress,
  'order_total' => $sampleTotal,
  'order_subtotal' => $sampleSubtotal,
  'order_shipping' => $sampleShipping,
  'order_shipping_total' => $sampleShipping,
  'order_tax_total' => $sampleTax,
  'order_discount_total' => $sampleDiscount,
  'order_items' => $sampleItemsRows,
  'order_items_rows' => $sampleItemsRows,
  'order_items_list' => $sampleItemsList,
  'payment_method' => 'Square - Cartão',
  'payment_status' => 'paid',
  'payment_reference' => 'SQ-99881',
  'customer_note' => 'Pode entregar após as 18h?',
  'order_notes' => 'Pode entregar após as 18h?',
  'track_link' => '<a href="https://loja.exemplo.com/track/ABCD1234">https://loja.exemplo.com/track/ABCD1234</a>',
  'track_url' => 'https://loja.exemplo.com/track/ABCD1234',
  'support_email' => $storeEmailCurrent ?: 'support@example.com',
  'shipping_method' => 'Entrega padrão (5-7 dias)',
  'shipping_method_description' => 'Envio com rastreio e assinatura na entrega.',
  'shipping_address' => $sampleAddress,
  'delivery_method_code' => 'standard',
  'admin_order_url' => 'https://loja.exemplo.com/admin.php?route=orders&action=view&id=1024',
  'additional_content' => 'Dúvidas? Responda este e-mail ou fale com nossa equipe.',
  'year' => date('Y'),
];
$emailPreviewSampleJson = json_encode($emailPreviewSample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>
    <form id="general-settings-form" method="post" enctype="multipart/form-data" action="settings.php?tab=general">
      <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
      <input type="hidden" name="task" value="save_general">
      <?php if (!$canEditSettings): ?>
        <div class="alert alert-warning">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span>Você não tem permissão para editar estas configurações. Os campos estão bloqueados para leitura.</span>
        </div>
      <?php endif; ?>
      <fieldset class="space-y-6" <?= $canEditSettings ? '' : 'disabled'; ?>>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-1">Nome da loja</label>
          <input class="input w-full" name="store_name" value="<?= sanitize_html($storeNameCurrent); ?>" maxlength="120" required>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">E-mail de suporte</label>
          <input class="input w-full" name="store_email" type="email" value="<?= sanitize_html($storeEmailCurrent); ?>" maxlength="160" placeholder="contato@minhaloja.com">
          <p class="hint mt-1">Utilizado em notificações e exibição para o cliente.</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">E-mail do admin</label>
          <input class="input w-full" name="admin_email" type="email" value="<?= sanitize_html($adminEmailCurrent); ?>" maxlength="160" placeholder="admin@minhaloja.com">
          <p class="hint mt-1">Recebe os alertas de novos pedidos.</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Telefone</label>
          <input class="input w-full" name="store_phone" value="<?= sanitize_html($storePhoneCurrent); ?>" maxlength="60" placeholder="+1 (305) 555-0123">
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Endereço</label>
          <textarea class="textarea w-full" name="store_address" rows="2" maxlength="240" placeholder="Rua, bairro, cidade, estado"><?= sanitize_html($storeAddressCurrent); ?></textarea>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Logo da loja (PNG/JPG/WEBP · máx 2MB)</label>
          <?php if ($storeLogoCurrent): ?>
            <div class="mb-3"><img src="<?= sanitize_html($storeLogoPreview ?: $storeLogoCurrent); ?>" alt="Logo atual" class="h-16 object-contain rounded-md border border-gray-200 p-2 bg-white"></div>
          <?php else: ?>
            <p class="hint mb-2">Nenhuma logo encontrada. Você pode enviar uma agora.</p>
          <?php endif; ?>
          <input class="block w-full text-sm text-gray-600" type="file" name="store_logo" accept=".png,.jpg,.jpeg,.webp">
        </div>
      </div>

      <hr class="border-gray-200">

      <h3 class="text-md font-semibold">Texto do destaque na Home</h3>
      <div class="grid md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Título principal</label>
          <input class="input w-full" name="home_hero_title" maxlength="160" value="<?= sanitize_html($heroTitleCurrent); ?>" required>
          <p class="text-xs text-gray-500 mt-1">Texto destacado exibido em negrito (ex.: "Tudo para sua saúde").</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Subtítulo</label>
          <textarea class="textarea w-full" name="home_hero_subtitle" rows="2" maxlength="240" required><?= sanitize_html($heroSubtitleCurrent); ?></textarea>
          <p class="text-xs text-gray-500 mt-1">Linha de apoio exibida logo abaixo do título.</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Placeholder da busca</label>
          <input class="input w-full" name="hero_search_placeholder" maxlength="160" value="<?= sanitize_html($heroSearchPlaceholderCurrent); ?>" placeholder="Buscar produtos, tratamentos...">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Texto do botão</label>
          <input class="input w-full" name="hero_search_button_label" maxlength="80" value="<?= sanitize_html($heroSearchButtonCurrent); ?>" placeholder="Buscar agora">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Texto auxiliar (esquerda)</label>
          <input class="input w-full" name="hero_support_text_left" maxlength="160" value="<?= sanitize_html($heroSupportTextLeftCurrent); ?>" placeholder="Atendimento humano rápido">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Texto auxiliar (direita)</label>
          <input class="input w-full" name="hero_support_text_right" maxlength="160" value="<?= sanitize_html($heroSupportTextRightCurrent); ?>" placeholder="Produtos verificados">
        </div>
        <div class="md:col-span-2 border border-gray-200 rounded-xl p-4 bg-gray-50 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h4 class="text-sm font-semibold text-gray-800">Plano de fundo</h4>
              <p class="text-xs text-gray-500">Escolha se o hero usa gradiente, cor sólida ou uma imagem.</p>
            </div>
          </div>
          <div class="grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-1">Tipo de fundo</label>
              <select class="select w-full" name="hero_background_type">
                <option value="gradient" <?= $heroBackgroundTypeCurrent === 'gradient' ? 'selected' : ''; ?>>Gradiente</option>
                <option value="solid" <?= $heroBackgroundTypeCurrent === 'solid' ? 'selected' : ''; ?>>Cor sólida</option>
                <option value="image" <?= $heroBackgroundTypeCurrent === 'image' ? 'selected' : ''; ?>>Imagem</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Cor sólida</label>
              <input class="input w-full" type="color" name="hero_solid_color" value="<?= sanitize_html($heroSolidColorCurrent); ?>">
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Gradiente: cor inicial</label>
              <input class="input w-full" type="color" name="hero_gradient_from" value="<?= sanitize_html($heroGradientFromCurrent); ?>">
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Gradiente: cor final</label>
              <input class="input w-full" type="color" name="hero_gradient_to" value="<?= sanitize_html($heroGradientToCurrent); ?>">
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium mb-1">Imagem (opcional)</label>
              <input class="block w-full text-sm text-gray-600" type="file" name="hero_background_upload" accept=".jpg,.jpeg,.png,.webp">
              <?php if ($heroBackgroundImageCurrent): ?>
                <?php $heroImagePreview = (strpos($heroBackgroundImageCurrent, 'http') === 0) ? $heroBackgroundImageCurrent : '/'.ltrim($heroBackgroundImageCurrent, '/'); ?>
                <div class="flex items-center gap-3 mt-2">
                  <img src="<?= sanitize_html($heroImagePreview); ?>" alt="Hero background" class="h-16 w-16 object-cover rounded-lg border border-gray-200">
                  <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="hero_background_image_remove" value="1">
                    Remover imagem atual
                  </label>
                </div>
              <?php endif; ?>
              <input class="input w-full mt-2" name="hero_background_image_url" value="<?= sanitize_html($heroBackgroundImageCurrent); ?>" placeholder="https://exemplo.com/fundo.jpg ou storage/hero/fundo.jpg">
              <p class="text-xs text-gray-500 mt-1">Use uma URL externa ou um caminho relativo (ex.: <code>storage/hero/fundo.jpg</code>).</p>
            </div>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Cor da tarja “Tratamento para 1 mês”</label>
          <input class="input w-full" type="color" name="badge_one_month_color" value="<?= sanitize_html($badgeColorCurrent); ?>">
          <p class="text-xs text-gray-500 mt-1">Define o fundo da tarja exibida nos produtos marcados como “Tratamento para 1 mês”.</p>
        </div>
        <div class="md:col-span-2">
          <h4 class="text-sm font-semibold text-gray-800">Cartões de destaque</h4>
          <p class="text-xs text-gray-500 mb-3">Personalize os quatro cards exibidos à direita do hero. Ícones usam classes do Font Awesome (ex.: <code>fa-shield-halved</code>).</p>
          <div class="grid md:grid-cols-2 gap-4">
            <?php foreach ($heroHighlightsCurrent as $idx => $highlight): ?>
              <div class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Bloco <?= $idx + 1; ?></div>
                <div>
                  <label class="block text-sm font-medium mb-1">Ícone</label>
                  <input class="input w-full" name="hero_highlights_icon[]" value="<?= sanitize_html($highlight['icon'] ?? 'fa-star'); ?>" placeholder="fa-star">
                </div>
                <div>
                  <label class="block text-sm font-medium mb-1">Título</label>
                  <input class="input w-full" name="hero_highlights_title[]" maxlength="80" value="<?= sanitize_html($highlight['title'] ?? ''); ?>">
                </div>
                <div>
                  <label class="block text-sm font-medium mb-1">Descrição</label>
                  <textarea class="textarea w-full" name="hero_highlights_desc[]" rows="2" maxlength="200"><?= sanitize_html($highlight['desc'] ?? ''); ?></textarea>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Texto curto abaixo do logo</label>
          <input class="input w-full" name="header_subline" maxlength="120" value="<?= sanitize_html($headerSublineCurrent); ?>" placeholder="Farmácia Online">
          <p class="hint mt-1">Exibido no topo, ao lado da logo.</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Cor primária (theme-color)</label>
          <input class="input w-full" type="color" name="theme_color" value="<?= sanitize_html($themeColorCurrent); ?>">
          <p class="hint mt-1">Usada em navegadores móveis e barras de título.</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Google Analytics</label>
          <textarea class="textarea w-full font-mono text-xs" name="google_analytics_code" rows="4" placeholder="Cole aqui o snippet do Google Analytics (ex.: gtag.js)"><?= htmlspecialchars($googleAnalyticsCurrent, ENT_QUOTES, 'UTF-8'); ?></textarea>
          <p class="hint mt-1">Cole o código completo fornecido pelo Google (incluindo &lt;script&gt;). Ele será injetado no &lt;head&gt; da loja.</p>
        </div>
        <div class="md:col-span-2">
          <h3 class="text-md font-semibold mt-4">Páginas legais</h3>
          <p class="text-xs text-gray-500 mb-2">Edite o conteúdo exibido nas páginas de Política de Privacidade e Política de Reembolso da loja.</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Política de privacidade</label>
          <textarea class="textarea w-full h-48 font-mono text-sm" name="privacy_policy_content"><?= htmlspecialchars($privacyPolicyCurrent, ENT_QUOTES, 'UTF-8'); ?></textarea>
          <p class="hint mt-1">Aceita HTML básico (&lt;p&gt;, &lt;ul&gt;, &lt;a&gt;, &lt;strong&gt;...). Será exibida na página “Política de Privacidade”.</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Política de reembolso</label>
          <textarea class="textarea w-full h-48 font-mono text-sm" name="refund_policy_content"><?= htmlspecialchars($refundPolicyCurrent, ENT_QUOTES, 'UTF-8'); ?></textarea>
          <p class="hint mt-1">Mesmo formato; exibida na página “Política de Reembolso”.</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Título do rodapé</label>
          <input class="input w-full" name="footer_title" maxlength="80" value="<?= sanitize_html($footerTitleCurrent); ?>">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Descrição do rodapé</label>
          <textarea class="textarea w-full" name="footer_description" rows="2" maxlength="160"><?= sanitize_html($footerDescriptionCurrent); ?></textarea>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Texto do rodapé</label>
          <textarea class="textarea w-full font-mono text-sm" name="footer_copy" rows="2" maxlength="280"><?= sanitize_html($footerCopyCurrent); ?></textarea>
          <p class="hint mt-1">Suporta placeholders <code>{{year}}</code> e <code>{{store_name}}</code>. Ex.: “© {{year}} {{store_name}}. Todos os direitos reservados.”</p>
        </div>
      </div>

      <hr class="border-gray-200">

      <h3 class="text-md font-semibold">Vitrine de destaques</h3>
      <div class="grid md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium mb-1">Exibir seção na home</label>
          <select class="select" name="home_featured_enabled">
            <option value="0" <?= !$featuredEnabledCurrent ? 'selected' : ''; ?>>Ocultar</option>
            <option value="1" <?= $featuredEnabledCurrent ? 'selected' : ''; ?>>Mostrar</option>
          </select>
          <p class="hint mt-1">Quando ativa, aparece antes da lista principal com os produtos marcados como “Destaque”.</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Título da seção</label>
          <input class="input w-full" name="home_featured_title" maxlength="80" value="<?= sanitize_html($featuredTitleCurrent); ?>" placeholder="Ofertas em destaque">
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Descrição de apoio</label>
          <textarea class="textarea w-full" name="home_featured_subtitle" rows="2" maxlength="200"><?= sanitize_html($featuredSubtitleCurrent); ?></textarea>
          <p class="hint mt-1">Ex.: “Seleção especial com preços imperdíveis — de X por Y”.</p>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Etiqueta superior</label>
          <input class="input w-full" name="home_featured_label" maxlength="80" value="<?= sanitize_html($featuredLabelCurrent); ?>" placeholder="Oferta destaque">
          <p class="hint mt-1">Texto pequeno exibido acima do título.</p>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Título principal (H1)</label>
          <input class="input w-full" name="home_featured_badge_title" maxlength="120" value="<?= sanitize_html($featuredBadgeTitleCurrent); ?>" placeholder="Seleção especial">
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium mb-1">Texto complementar</label>
          <textarea class="textarea w-full" name="home_featured_badge_text" rows="2" maxlength="240"><?= sanitize_html($featuredBadgeTextCurrent); ?></textarea>
        </div>
      </div>

      <div class="md:col-span-2 border border-gray-200 rounded-xl p-4 bg-white space-y-4">
        <div class="flex flex-col gap-1">
          <h3 class="text-md font-semibold flex items-center gap-2"><i class="fa-solid fa-envelope text-brand-600"></i> Templates de e-mail</h3>
          <p class="text-xs text-gray-500">Personalize os e-mails enviados para o cliente e para a equipe. Placeholders disponíveis: <code>{{site_name}}</code>, <code>{{store_name}}</code>, <code>{{store_logo_url}}</code>, <code>{{order_id}}</code>, <code>{{order_number}}</code>, <code>{{order_date}}</code>, <code>{{customer_name}}</code>, <code>{{billing_full_name}}</code>, <code>{{billing_email}}</code>, <code>{{billing_phone}}</code>, <code>{{billing_address_html}}</code>, <code>{{customer_full_address}}</code>, <code>{{shipping_address_html}}</code>, <code>{{shipping_full_address}}</code>, <code>{{shipping_method}}</code>, <code>{{shipping_method_description}}</code>, <code>{{order_items_rows}}</code>, <code>{{order_items}}</code>, <code>{{order_subtotal}}</code>, <code>{{order_shipping_total}}</code>, <code>{{order_tax_total}}</code>, <code>{{order_discount_total}}</code>, <code>{{order_total}}</code>, <code>{{payment_method}}</code>, <code>{{payment_status}}</code>, <code>{{payment_reference}}</code>, <code>{{track_link}}</code>, <code>{{track_url}}</code>, <code>{{support_email}}</code>, <code>{{customer_note}}</code>, <code>{{admin_order_url}}</code>, <code>{{additional_content}}</code>, <code>{{year}}</code>.</p>
        </div>
        <div class="grid md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-1">Assunto (cliente)</label>
            <input class="input w-full" name="email_customer_subject" maxlength="180" value="<?= htmlspecialchars($emailCustomerSubjectCurrent, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="md:col-span-2">
            <div class="flex items-center justify-between gap-3 mb-1">
              <label class="block text-sm font-medium">Conteúdo (cliente)</label>
              <button type="button" class="btn btn-ghost text-xs px-3 py-1 whitespace-nowrap" data-email-preview="customer">
                <i class="fa-solid fa-eye mr-1"></i>Pré-visualizar
              </button>
            </div>
            <textarea class="textarea w-full font-mono text-sm h-44" name="email_customer_body"><?= htmlspecialchars($emailCustomerBodyCurrent, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <p class="hint mt-1">Você pode usar HTML básico. Ex.: &lt;p&gt;, &lt;strong&gt;, &lt;ul&gt;.</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-1">Assunto (admin)</label>
            <input class="input w-full" name="email_admin_subject" maxlength="180" value="<?= htmlspecialchars($emailAdminSubjectCurrent, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
          <div class="md:col-span-2">
            <div class="flex items-center justify-between gap-3 mb-1">
              <label class="block text-sm font-medium">Conteúdo (admin)</label>
              <button type="button" class="btn btn-ghost text-xs px-3 py-1 whitespace-nowrap" data-email-preview="admin">
                <i class="fa-solid fa-eye mr-1"></i>Pré-visualizar
              </button>
            </div>
            <textarea class="textarea w-full font-mono text-sm h-44" name="email_admin_body"><?= htmlspecialchars($emailAdminBodyCurrent, ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>
        </div>
      </div>

      <div class="md:col-span-2 border border-gray-200 rounded-xl p-4 bg-white space-y-4">
        <div class="flex items-center gap-2 justify-between">
          <div>
            <h3 class="text-md font-semibold">SMTP (envio autenticado)</h3>
            <p class="text-xs text-gray-500">Preencha para enviar e-mails via servidor SMTP (mais confiável que o <code>mail()</code>). Deixe em branco para usar o envio padrão.</p>
          </div>
          <button type="button"
                  id="smtpGmailFill"
                  class="btn btn-ghost btn-sm whitespace-nowrap"
                  data-store-name="<?= htmlspecialchars($storeNameCurrent, ENT_QUOTES, 'UTF-8'); ?>"
                  data-store-email="<?= htmlspecialchars($storeEmailCurrent, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="fa-brands fa-google mr-1"></i>Usar Gmail
          </button>
        </div>
        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">Host SMTP</label>
            <input class="input w-full" name="smtp_host" value="<?= sanitize_html($smtpConfig['host'] ?? ''); ?>" placeholder="smtp.hostinger.com">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Porta</label>
            <input class="input w-full" name="smtp_port" value="<?= sanitize_html($smtpConfig['port'] ?? ''); ?>" placeholder="587">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Usuário</label>
            <input class="input w-full" name="smtp_user" value="<?= sanitize_html($smtpConfig['user'] ?? ''); ?>" placeholder="email@seudominio.com">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Senha</label>
            <input class="input w-full" name="smtp_pass" type="password" value="<?= sanitize_html($smtpConfig['pass'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Segurança</label>
            <select class="select w-full" name="smtp_secure">
              <?php $secureMode = $smtpConfig['secure'] ?? 'tls'; ?>
              <option value="tls" <?= $secureMode === 'tls' ? 'selected' : ''; ?>>TLS (587)</option>
              <option value="ssl" <?= $secureMode === 'ssl' ? 'selected' : ''; ?>>SSL (465)</option>
              <option value="none" <?= $secureMode === 'none' ? 'selected' : ''; ?>>Sem segurança</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Nome exibido</label>
            <input class="input w-full" name="smtp_from_name" value="<?= sanitize_html($smtpConfig['from_name'] ?? $storeNameCurrent); ?>" placeholder="<?= sanitize_html($storeNameCurrent); ?>">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">E-mail remetente</label>
            <input class="input w-full" name="smtp_from_email" value="<?= sanitize_html($smtpConfig['from_email'] ?? $storeEmailCurrent); ?>" placeholder="<?= sanitize_html($storeEmailCurrent); ?>">
          </div>
        </div>
        <p class="text-xs text-gray-500">Para Gmail use <code>smtp.gmail.com</code>, porta <strong>587</strong>, segurança <strong>TLS</strong>, usuário = seu e-mail completo e senha = <strong>Senha de app</strong> criada em <em>Conta Google &gt; Segurança &gt; Senhas de app</em>. Outros provedores devem informar host, porta e usuário próprios. Ao deixar em branco, o sistema usa <code>mail()</code>.</p>
      </div>

      <div class="md:col-span-2 border border-gray-200 rounded-xl p-4 bg-white space-y-4">
        <div>
          <h3 class="text-md font-semibold flex items-center gap-2"><i class="fa-brands fa-whatsapp text-[#25D366]"></i> WhatsApp (Twilio)</h3>
          <p class="text-xs text-gray-500">Dispare mensagens de confirmação de pedido para cliente e admin via Twilio. Use números em formato E.164 (ex.: +15551234567).</p>
        </div>
        <div class="grid md:grid-cols-2 gap-4">
          <label class="inline-flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" name="whatsapp_twilio_enabled" value="1" <?= $twilioEnabledCurrent ? 'checked' : ''; ?>>
            Ativar envio automático
          </label>
          <div>
            <label class="block text-sm font-medium mb-1">Account SID</label>
            <input class="input w-full" name="whatsapp_twilio_sid" value="<?= sanitize_html($twilioSidCurrent); ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Auth Token</label>
            <input class="input w-full" type="password" name="whatsapp_twilio_token" value="<?= sanitize_html($twilioTokenCurrent); ?>">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Número WhatsApp (Twilio)</label>
            <input class="input w-full" name="whatsapp_twilio_from" value="<?= sanitize_html($twilioFromCurrent); ?>" placeholder="+14155238886">
            <p class="hint mt-1">Informe o número habilitado no WhatsApp da Twilio. O prefixo <code>whatsapp:</code> é adicionado automaticamente.</p>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Número(s) admin</label>
            <input class="input w-full" name="whatsapp_twilio_admin_to" value="<?= sanitize_html($twilioAdminToCurrent); ?>" placeholder="+15551234567, +5511999999999">
            <p class="hint mt-1">Separe múltiplos números com vírgula ou quebra de linha.</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-1">Mensagem para cliente</label>
            <textarea class="textarea w-full font-mono text-sm h-28" name="whatsapp_twilio_customer_template"><?= sanitize_html($twilioCustomerTemplateCurrent); ?></textarea>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-1">Mensagem para admin</label>
            <textarea class="textarea w-full font-mono text-sm h-28" name="whatsapp_twilio_admin_template"><?= sanitize_html($twilioAdminTemplateCurrent); ?></textarea>
          </div>
          <div class="md:col-span-2 text-xs text-gray-500">
            Placeholders: <code>{{order_number}}</code>, <code>{{order_total}}</code>, <code>{{customer_name}}</code>, <code>{{order_date}}</code>, <code>{{store_name}}</code>.
          </div>
        </div>
      </div>

      <div class="md:col-span-2 border border-gray-200 rounded-xl p-4 bg-white">
        <h3 class="text-md font-semibold mb-2 flex items-center gap-2"><i class="fa-brands fa-whatsapp text-[#25D366]"></i> WhatsApp Flutuante</h3>
        <p class="text-xs text-gray-500 mb-3">Defina o número e a mensagem exibida no botão flutuante da loja. O link abre a conversa direto no WhatsApp.</p>
        <div class="grid md:grid-cols-2 gap-4">
          <label class="inline-flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" name="whatsapp_enabled" value="1" <?= $whatsappEnabled ? 'checked' : ''; ?>>
            Exibir botão flutuante
          </label>
          <div>
            <label class="block text-sm font-medium mb-1">Número com DDI e DDD</label>
            <input class="input w-full" name="whatsapp_number" value="<?= sanitize_html($whatsappNumber); ?>" placeholder="ex.: 1789101122" maxlength="30">
            <p class="hint mt-1">Informe apenas números (ex.: 1789101122 para +1 789 101 122).</p>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Texto do botão</label>
            <input class="input w-full" name="whatsapp_button_text" value="<?= sanitize_html($whatsappButtonText); ?>" maxlength="80" placeholder="Fale com nossa equipe">
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-1">Mensagem inicial enviada no WhatsApp</label>
            <textarea class="textarea w-full" name="whatsapp_message" rows="3" maxlength="400"><?= sanitize_html($whatsappMessage); ?></textarea>
            <p class="hint mt-1">Será preenchida automaticamente quando o cliente abrir a conversa.</p>
          </div>
        </div>
      </div>

      <div class="md:col-span-2 border border-gray-200 rounded-xl p-4 bg-white">
        <h3 class="text-md font-semibold mb-2 flex items-center gap-2"><i class="fa-solid fa-mobile-screen-button text-brand-600"></i> Identidade do App/PWA</h3>
        <p class="text-xs text-gray-500 mb-3">Personalize o título da aba, o nome exibido quando instalado e o ícone utilizado pelo aplicativo.</p>
        <div class="grid md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-1">Título da aba (meta title)</label>
            <input class="input w-full" name="store_meta_title" maxlength="160" value="<?= sanitize_html($metaTitleCurrent); ?>">
            <p class="hint mt-1">Aparece em <code>&lt;title&gt;</code> e no histórico do navegador. Ex.: "<?= sanitize_html($storeNameCurrent ?: 'Get Power Research'); ?> | Loja".</p>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Nome do app (PWA)</label>
            <input class="input w-full" name="pwa_name" maxlength="80" value="<?= sanitize_html($pwaNameCurrent); ?>" required>
            <p class="hint mt-1">Nome completo exibido ao instalar o app.</p>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Nome curto</label>
            <input class="input w-full" name="pwa_short_name" maxlength="40" value="<?= sanitize_html($pwaShortNameCurrent); ?>" required>
            <p class="hint mt-1">Usado em ícones e notificações. Máximo recomendado: 12 caracteres.</p>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Ícone do app (PNG fundo transparente)</label>
            <?php if ($pwaIconPreview): ?>
              <div class="flex items-center gap-4 mb-2">
                <img src="<?= sanitize_html($pwaIconPreview); ?>" alt="Ícone atual" class="h-16 w-16 rounded-lg border bg-white p-2">
                <span class="text-xs text-gray-500 leading-snug">Tamanhos gerados automaticamente (512x512, 192x192 e 180x180).</span>
              </div>
            <?php endif; ?>
            <input class="block w-full text-sm text-gray-600" type="file" name="pwa_icon" accept=".png">
            <p class="hint mt-1">Envie uma imagem quadrada, preferencialmente 512x512 px, em formato PNG.</p>
          </div>
        </div>
      </div>

      </fieldset>
      <div class="flex justify-end gap-3">
        <?php if ($canEditSettings): ?>
          <button type="submit" class="btn btn-primary px-5 py-2"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar alterações</button>
        <?php endif; ?>
        <a href="index.php" target="_blank" class="btn btn-ghost px-5 py-2"><i class="fa-solid fa-up-right-from-square mr-2"></i>Ver loja</a>
      </div>
    </form>
  </div>
  </div>

  <div data-tab-panel="checkout" class="card <?= $tab === 'checkout' ? '' : 'hidden'; ?>">
    <div class="card-body settings-form">
      <h2 class="text-lg font-semibold mb-1">Configurações do Checkout</h2>
      <?php if ($tab === 'checkout' && isset($_GET['saved'])): ?>
        <div class="alert alert-success">
          <i class="fa-solid fa-circle-check"></i>
          <span>Checkout atualizado com sucesso.</span>
        </div>
      <?php endif; ?>
      <form method="post" action="settings.php?tab=checkout">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="task" value="save_checkout">
        <?php if (!$canEditSettings): ?>
          <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>Você não tem permissão para editar estas configurações.</span>
          </div>
        <?php endif; ?>
        <fieldset class="space-y-6" <?= $canEditSettings ? '' : 'disabled'; ?>>
          <div class="grid md:grid-cols-2 gap-5">
            <div class="md:col-span-2">
              <label class="block text-sm font-medium mb-1">Países disponíveis</label>
              <textarea class="textarea w-full font-mono text-sm" name="checkout_countries" rows="4" placeholder="US|Estados Unidos" <?= $canEditSettings ? '' : 'readonly'; ?>><?= htmlspecialchars($checkoutCountriesText, ENT_QUOTES, 'UTF-8'); ?></textarea>
              <p class="hint mt-1">Use o formato <code>CODIGO|Nome</code>. Ex.: <code>US|Estados Unidos</code>.</p>
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium mb-1">Estados / Províncias</label>
              <textarea class="textarea w-full font-mono text-sm" name="checkout_states" rows="6" placeholder="US|AL|Alabama" <?= $canEditSettings ? '' : 'readonly'; ?>><?= htmlspecialchars($checkoutStatesText, ENT_QUOTES, 'UTF-8'); ?></textarea>
              <p class="hint mt-1">Formato <code>PAIS|CODIGO|Nome</code>. Se um país não tiver estados listados, o checkout exibirá um campo de texto livre.</p>
            </div>
            <div class="md:col-span-2">
              <label class="block text-sm font-medium mb-1">Métodos de entrega</label>
              <textarea class="textarea w-full font-mono text-sm" name="checkout_delivery_methods" rows="5" placeholder="standard|Entrega padrão (5-7 dias)|Envio com rastreio para todo o país." <?= $canEditSettings ? '' : 'readonly'; ?>><?= htmlspecialchars($checkoutDeliveryText, ENT_QUOTES, 'UTF-8'); ?></textarea>
              <p class="hint mt-1">Formato <code>codigo|Nome|Descrição</code>. Utilize códigos curtos, sem espaços.</p>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">País selecionado por padrão</label>
              <select class="select w-full" name="checkout_default_country" <?= $canEditSettings ? '' : 'disabled'; ?>>
                <?php foreach ($checkoutCountriesCurrent as $countryEntry): ?>
                  <?php $code = strtoupper($countryEntry['code'] ?? ''); ?>
                  <option value="<?= $code; ?>" <?= $code === $checkoutDefaultCountryCurrent ? 'selected' : ''; ?>><?= sanitize_html($countryEntry['name'] ?? $code); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="hint mt-1">Aplicado quando o cliente abre o checkout pela primeira vez.</p>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Entrega segura (valor adicional)</label>
              <input class="input w-full" name="checkout_secure_delivery_price" value="<?= htmlspecialchars(number_format($checkoutSecureDeliveryPriceCurrent, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="0.00">
              <p class="hint mt-1">Deixe 0 para ocultar a opção no checkout.</p>
            </div>
          </div>
          <?php if ($canEditSettings): ?>
            <div class="pt-4">
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar checkout</button>
            </div>
          <?php endif; ?>
        </fieldset>
      </form>
    </div>
  </div>

  <div data-tab-panel="payments" class="space-y-4 <?= $tab === 'payments' ? '' : 'hidden'; ?>">
    <div class="card p-6">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h2 class="text-lg font-semibold">Métodos de pagamento</h2>
          <p class="text-sm text-gray-500">Arraste para reordenar e clique para editar ou ativar/desativar.</p>
        </div>
        <?php if ($canManagePayments): ?>
          <div class="flex items-center gap-2">
            <a class="btn btn-primary" href="settings.php?tab=payments&action=new"><i class="fa-solid fa-plus mr-2"></i>Novo método</a>
            <a class="btn btn-ghost" href="payment_links.php"><i class="fa-solid fa-link mr-2"></i>Links por produto</a>
          </div>
        <?php endif; ?>
      </div>
      <?php if (!$methods): ?>
        <p class="text-center text-gray-500 mt-6">Nenhum método cadastrado.</p>
      <?php else: ?>
        <ul id="pm-sortable" class="divide-y divide-gray-200 mt-4" data-sortable-enabled="<?= $canManagePayments ? '1' : '0'; ?>">
          <?php foreach ($methods as $pm): $settings = pm_decode_settings($pm); ?>
            <li class="flex items-center justify-between gap-4 px-4 py-3 bg-white" data-id="<?= (int)$pm['id']; ?>">
              <div class="flex items-center gap-3">
                <span class="cursor-move text-gray-400"><i class="fa-solid fa-grip-lines"></i></span>
                <?php if (!empty($pm['icon_path'])): ?>
                  <img src="<?= sanitize_html($pm['icon_path']); ?>" class="h-8 w-8 rounded" alt="icon">
                <?php else: ?>
                  <div class="h-8 w-8 rounded flex items-center justify-center" style="background:rgba(32,96,200,.08);color:var(--brand-700);">
                    <i class="fa-solid fa-credit-card"></i>
                  </div>
                <?php endif; ?>
                <div>
                  <div class="font-semibold"><?= sanitize_html($pm['name']); ?></div>
                  <div class="text-xs text-gray-500">Código: <?= sanitize_html($pm['code']); ?></div>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <?= ((int)$pm['is_active'] === 1) ? '<span class="badge ok">Ativo</span>' : '<span class="badge danger">Inativo</span>'; ?>
                <?= !empty($pm['is_featured']) ? '<span class="badge ok"><i class="fa-solid fa-star"></i> Preferido</span>' : ''; ?>
                <?= !empty($pm['require_receipt']) ? '<span class="badge warn">Comprovante</span>' : ''; ?>
              </div>
              <div class="flex items-center gap-2">
                <?php if ($canManagePayments): ?>
                  <a class="btn btn-ghost" href="settings.php?tab=payments&action=edit&id=<?= (int)$pm['id']; ?>" title="Editar"><i class="fa-solid fa-pen"></i></a>
                  <a class="btn btn-ghost" href="settings.php?tab=payments&action=toggle&id=<?= (int)$pm['id']; ?>&csrf=<?= csrf_token(); ?>" title="Ativar/Inativar"><i class="fa-solid fa-power-off"></i></a>
                <?php else: ?>
                  <span class="text-xs text-gray-400">Somente leitura</span>
                <?php endif; ?>
                <?php if ($isSuperAdmin): ?>
                  <a class="btn btn-ghost text-red-600" href="settings.php?tab=payments&action=delete&id=<?= (int)$pm['id']; ?>&csrf=<?= csrf_token(); ?>" onclick="return confirm('Remover este método?')" title="Excluir"><i class="fa-solid fa-trash"></i></a>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="card p-6">
      <h3 class="text-md font-semibold mb-3"><?= $editRow ? 'Editar método' : 'Novo método'; ?></h3>
      <?php
        $formRow = $editRow ?: [];
        $formSettings = $editSettings ?: ['type' => 'custom'];
        $idForForm = (int)($formRow['id'] ?? 0);
        $formActionValue = $editRow ? 'update' : 'create';
      ?>
      <form class="space-y-4" method="post" enctype="multipart/form-data" action="settings.php?tab=payments">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="task" value="<?= $formActionValue; ?>">
        <input type="hidden" name="id" value="<?= $idForForm; ?>">
        <?php if (!$canManagePayments): ?>
          <div class="alert alert-warning">
            <i class="fa-solid fa-circle-info"></i>
            <span>Você não possui permissão para alterar métodos de pagamento.</span>
          </div>
        <?php endif; ?>
        <fieldset class="space-y-4" <?= $canManagePayments ? '' : 'disabled'; ?>>
        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">Nome</label>
            <input class="input w-full" name="name" value="<?= sanitize_html($formRow['name'] ?? ''); ?>" required>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Código</label>
            <?php $isDefaultCode = in_array($formRow['code'] ?? '', ['pix','zelle','venmo','paypal','square','stripe','whatsapp'], true); ?>
            <input class="input w-full" name="code" value="<?= sanitize_html($formRow['code'] ?? ''); ?>" <?= $isDefaultCode ? 'readonly' : ''; ?> placeholder="ex.: square">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Tipo</label>
            <?php $currentType = $isDefaultCode ? ($formRow['code'] ?? 'custom') : ($formSettings['type'] ?? 'custom'); ?>
            <select class="select w-full" name="method_type" <?= $isDefaultCode ? 'disabled' : ''; ?>>
            <?php $types = ['pix'=>'Pix','zelle'=>'Zelle','venmo'=>'Venmo','paypal'=>'PayPal','square'=>'Cartão de crédito (Square)','stripe'=>'Stripe','whatsapp'=>'WhatsApp','custom'=>'Personalizado']; ?>
              <?php foreach ($types as $value => $label): ?>
                <option value="<?= $value; ?>" <?= $currentType === $value ? 'selected' : ''; ?>><?= $label; ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($isDefaultCode): ?><input type="hidden" name="method_type" value="<?= sanitize_html($currentType); ?>"><?php endif; ?>
          </div>
          <div class="flex items-center gap-4">
            <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" <?= (!isset($formRow['is_active']) || (int)$formRow['is_active'] === 1) ? 'checked' : ''; ?>> Ativo</label>
            <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_featured" value="1" <?= !empty($formRow['is_featured']) ? 'checked' : ''; ?>> Preferencial</label>
            <label class="inline-flex items-center gap-2"><input type="checkbox" name="require_receipt" value="1" <?= !empty($formRow['require_receipt']) ? 'checked' : ''; ?>> Exigir comprovante</label>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Descrição interna</label>
          <input class="input w-full" name="description" value="<?= sanitize_html($formRow['description'] ?? ''); ?>" placeholder="Visível apenas no painel">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Instruções (placeholders: {valor_pedido}, {valor_produtos}, {valor_frete}, {numero_pedido}, {email_cliente}, {account_label}, {account_value}, {paypal_link}, {stripe_link}, {whatsapp_link}, {whatsapp_number}, {whatsapp_message})</label>
          <textarea class="textarea w-full" name="instructions" rows="4"><?= htmlspecialchars($formRow['instructions'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">Observação exibida no checkout (opcional)</label>
          <textarea class="textarea w-full" name="public_note" rows="3" placeholder="Ex.: Pagamento confirmado em até 1 hora."><?= htmlspecialchars($formRow['public_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">Legenda do campo</label>
            <input class="input w-full" name="account_label" value="<?= sanitize_html($formSettings['account_label'] ?? ''); ?>">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Valor/Conta</label>
            <input class="input w-full" name="account_value" value="<?= sanitize_html($formSettings['account_value'] ?? ''); ?>">
          </div>
        </div>

        <div class="grid md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium mb-1">Cor do botão</label>
            <input class="input w-full" type="color" name="button_bg" value="<?= sanitize_html($formSettings['button_bg'] ?? '#dc2626'); ?>">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Cor do texto</label>
            <input class="input w-full" type="color" name="button_text" value="<?= sanitize_html($formSettings['button_text'] ?? '#ffffff'); ?>">
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Cor ao hover</label>
            <input class="input w-full" type="color" name="button_hover_bg" value="<?= sanitize_html($formSettings['button_hover_bg'] ?? '#b91c1c'); ?>">
          </div>
        </div>

        <div id="type-fields" class="grid md:grid-cols-2 gap-4">
          <div data-type="pix">
            <label class="block text-sm font-medium mb-1">Chave Pix</label>
            <input class="input w-full" name="pix_key" value="<?= sanitize_html($formSettings['pix_key'] ?? ''); ?>">
          </div>
          <div data-type="pix">
            <label class="block text-sm font-medium mb-1">Nome do recebedor</label>
            <input class="input w-full" name="pix_merchant_name" value="<?= sanitize_html($formSettings['merchant_name'] ?? ''); ?>">
          </div>
          <div data-type="pix">
            <label class="block text-sm font-medium mb-1">Cidade</label>
            <input class="input w-full" name="pix_merchant_city" value="<?= sanitize_html($formSettings['merchant_city'] ?? ''); ?>">
          </div>

          <div data-type="zelle">
            <label class="block text-sm font-medium mb-1">Nome do recebedor</label>
            <input class="input w-full" name="zelle_recipient_name" value="<?= sanitize_html($formSettings['recipient_name'] ?? ''); ?>">
          </div>

          <div data-type="venmo">
            <label class="block text-sm font-medium mb-1">Link/Usuário do Venmo</label>
            <input class="input w-full" name="venmo_link" value="<?= sanitize_html($formSettings['venmo_link'] ?? ''); ?>">
          </div>

          <div data-type="whatsapp">
            <label class="block text-sm font-medium mb-1">Número WhatsApp</label>
            <input class="input w-full" name="whatsapp_number" value="<?= sanitize_html($formSettings['number'] ?? ''); ?>" placeholder="+55 8299999-0000">
            <p class="text-xs text-gray-500 mt-1">Informe com DDI/DD. Ex.: +55 82999990000</p>
          </div>
          <div data-type="whatsapp">
            <label class="block text-sm font-medium mb-1">Mensagem padrão</label>
            <textarea class="textarea w-full" name="whatsapp_message" rows="3" placeholder="Olá! Gostaria de finalizar meu pedido."><?= htmlspecialchars($formSettings['message'] ?? 'Olá! Gostaria de finalizar meu pedido.', ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>
          <div data-type="whatsapp">
            <label class="block text-sm font-medium mb-1">Link personalizado (opcional)</label>
            <input class="input w-full" name="whatsapp_link" value="<?= sanitize_html($formSettings['link'] ?? ''); ?>" placeholder="https://wa.me/...">
            <p class="text-xs text-gray-500 mt-1">Se o número estiver preenchido, o link é gerado automaticamente.</p>
          </div>

          <div data-type="paypal">
            <label class="block text-sm font-medium mb-1">Conta PayPal / Email</label>
            <input class="input w-full" name="paypal_business" value="<?= sanitize_html($formSettings['business'] ?? ''); ?>">
          </div>
          <?php $paypalMode = $formSettings['mode'] ?? 'standard'; ?>
          <div data-type="paypal">
            <label class="block text-sm font-medium mb-1">Modo</label>
            <select class="select w-full" name="paypal_mode">
              <option value="paypal_product_link" <?= $paypalMode === 'paypal_product_link' ? 'selected' : ''; ?>>Link definido por produto</option>
              <option value="standard" <?= $paypalMode === 'standard' ? 'selected' : ''; ?>>Conta PayPal (valor total)</option>
              <option value="direct_url" <?= $paypalMode === 'direct_url' ? 'selected' : ''; ?>>URL fixa</option>
            </select>
          </div>
          <div data-type="paypal">
            <label class="block text-sm font-medium mb-1">Moeda</label>
            <input class="input w-full" name="paypal_currency" value="<?= sanitize_html($formSettings['currency'] ?? 'USD'); ?>">
          </div>
          <div data-type="paypal">
            <label class="block text-sm font-medium mb-1">Return URL</label>
            <input class="input w-full" name="paypal_return_url" value="<?= sanitize_html($formSettings['return_url'] ?? ''); ?>">
          </div>
          <div data-type="paypal">
            <label class="block text-sm font-medium mb-1">Cancel URL</label>
            <input class="input w-full" name="paypal_cancel_url" value="<?= sanitize_html($formSettings['cancel_url'] ?? ''); ?>">
          </div>
          <div data-type="paypal">
            <label class="block text-sm font-medium mb-1">URL fixa (opcional)</label>
            <input class="input w-full" name="paypal_redirect_url" value="<?= sanitize_html($formSettings['redirect_url'] ?? ''); ?>" placeholder="https://">
            <p class="text-xs text-gray-500 mt-1">Usada quando o modo estiver como "URL fixa".</p>
          </div>
          <div data-type="paypal">
            <label class="inline-flex items-center gap-2 mt-6"><input type="checkbox" name="paypal_open_new_tab" value="1" <?= !empty($formSettings['open_new_tab']) ? 'checked' : ''; ?>> Abrir em nova aba</label>
          </div>

          <div data-type="square">
            <label class="block text-sm font-medium mb-1">Modo</label>
            <?php $squareMode = $formSettings['mode'] ?? 'square_product_link'; ?>
            <select class="select w-full" name="square_mode">
              <option value="square_product_link" <?= $squareMode === 'square_product_link' ? 'selected' : ''; ?>>Link definido por produto</option>
              <option value="direct_url" <?= $squareMode === 'direct_url' ? 'selected' : ''; ?>>URL fixa</option>
            </select>
          </div>
          <div data-type="square">
            <label class="block text-sm font-medium mb-1">Abrir em nova aba?</label>
            <label class="inline-flex items-center gap-2"><input type="checkbox" name="square_open_new_tab" value="1" <?= !empty($formSettings['open_new_tab']) ? 'checked' : ''; ?>> Nova aba</label>
          </div>
          <div data-type="square">
            <label class="block text-sm font-medium mb-1">URL fixa (opcional)</label>
            <input class="input w-full" name="square_redirect_url" value="<?= sanitize_html($formSettings['redirect_url'] ?? ''); ?>" placeholder="https://">
          </div>
          <div data-type="square" class="md:col-span-2 grid md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-1">Título (H1)</label>
              <input class="input w-full" name="square_badge_title" value="<?= sanitize_html($formSettings['badge_title'] ?? 'Seleção especial'); ?>" placeholder="Seleção especial">
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Texto complementar</label>
              <input class="input w-full" name="square_badge_text" value="<?= sanitize_html($formSettings['badge_text'] ?? 'Selecionados com carinho para você'); ?>" placeholder="Selecionados com carinho para você">
            </div>
          </div>
          <div data-type="square" class="md:col-span-2 grid md:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium mb-1">Crédito - rótulo</label>
              <input class="input w-full" name="square_credit_label" value="<?= sanitize_html($formSettings['credit_label'] ?? 'Cartão de crédito'); ?>">
              <label class="block text-xs font-medium mt-2">Crédito - link do checkout (Square)</label>
              <input class="input w-full" name="square_credit_link" value="<?= sanitize_html($formSettings['credit_link'] ?? ''); ?>" placeholder="https://square.link/...">
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Débito - rótulo</label>
              <input class="input w-full" name="square_debit_label" value="<?= sanitize_html($formSettings['debit_label'] ?? 'Cartão de débito'); ?>">
              <label class="block text-xs font-medium mt-2">Débito - link do checkout (Square)</label>
              <input class="input w-full" name="square_debit_link" value="<?= sanitize_html($formSettings['debit_link'] ?? ''); ?>" placeholder="https://square.link/...">
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Afterpay - rótulo</label>
              <input class="input w-full" name="square_afterpay_label" value="<?= sanitize_html($formSettings['afterpay_label'] ?? 'Afterpay'); ?>">
              <label class="block text-xs font-medium mt-2">Afterpay - link do checkout (Square)</label>
              <input class="input w-full" name="square_afterpay_link" value="<?= sanitize_html($formSettings['afterpay_link'] ?? ''); ?>" placeholder="https://square.link/...">
            </div>
          </div>

          <div data-type="stripe">
            <label class="block text-sm font-medium mb-1">Modo</label>
            <?php $stripeMode = $formSettings['mode'] ?? 'stripe_product_link'; ?>
            <select class="select w-full" name="stripe_mode">
              <option value="stripe_product_link" <?= $stripeMode === 'stripe_product_link' ? 'selected' : ''; ?>>Link definido por produto</option>
              <option value="direct_url" <?= $stripeMode === 'direct_url' ? 'selected' : ''; ?>>URL fixa</option>
            </select>
          </div>
          <div data-type="stripe">
            <label class="block text-sm font-medium mb-1">Abrir em nova aba?</label>
            <label class="inline-flex items-center gap-2"><input type="checkbox" name="stripe_open_new_tab" value="1" <?= !empty($formSettings['open_new_tab']) ? 'checked' : ''; ?>> Nova aba</label>
          </div>
          <div data-type="stripe">
            <label class="block text-sm font-medium mb-1">URL fixa (opcional)</label>
            <input class="input w-full" name="stripe_redirect_url" value="<?= sanitize_html($formSettings['redirect_url'] ?? ''); ?>" placeholder="https://">
          </div>

          <div data-type="custom">
            <label class="block text-sm font-medium mb-1">Modo</label>
            <input class="input w-full" name="custom_mode" value="<?= sanitize_html($formSettings['mode'] ?? 'manual'); ?>">
            <p class="text-xs text-gray-500 mt-1">Use <code>product_link</code> para usar links definidos por produto.</p>
          </div>
          <div data-type="custom">
            <label class="block text-sm font-medium mb-1">URL de redirecionamento</label>
            <input class="input w-full" name="custom_redirect_url" value="<?= sanitize_html($formSettings['redirect_url'] ?? ''); ?>">
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-1">Ícone (PNG/SVG opcional)</label>
          <input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/svg+xml">
          <?php if (!empty($formRow['icon_path'])): ?>
            <div class="mt-2"><img src="<?= sanitize_html($formRow['icon_path']); ?>" alt="ícone" class="h-10"></div>
          <?php endif; ?>
        </div>
        </fieldset>

        <div class="flex items-center gap-2 pt-2">
          <?php if ($canManagePayments): ?>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar</button>
          <?php endif; ?>
          <a class="btn btn-ghost" href="settings.php?tab=payments">Cancelar</a>
        </div>
      </form>
    </div>
  </div>

  <div data-tab-panel="builder" class="card p-6 <?= $tab === 'builder' ? '' : 'hidden'; ?>">
    <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
      <div>
        <h2 class="text-lg font-semibold">Editor visual da home</h2>
        <p class="text-sm text-gray-500">Arraste blocos, edite textos e publique a nova página inicial.</p>
      </div>
      <div class="flex gap-2">
        <?php if ($canManageBuilder): ?>
          <button id="btn-preview" class="btn btn-ghost"><i class="fa-solid fa-eye mr-2"></i>Preview</button>
          <button id="btn-save" class="btn btn-ghost"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar rascunho</button>
          <button id="btn-publish" class="btn btn-primary"><i class="fa-solid fa-rocket mr-2"></i>Publicar</button>
        <?php else: ?>
          <span class="text-xs text-gray-400">Somente leitura</span>
        <?php endif; ?>
      </div>
    </div>
    <div id="builder-alert" class="hidden px-4 py-3 rounded-lg text-sm"></div>
    <div class="border border-gray-200 rounded-xl overflow-hidden">
      <?php if ($canManageBuilder): ?>
        <div id="gjs" style="min-height:600px;background:#f5f5f5;"></div>
      <?php else: ?>
        <div class="p-6 text-sm text-gray-500 bg-white">Você não possui permissão para editar o layout. Solicite a um administrador com acesso.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</section>

<div id="email-preview-overlay" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,0.6);z-index:10000;padding:20px;">
  <div style="background:#ffffff;width:min(900px,90vw);height:min(90vh,820px);border-radius:18px;box-shadow:0 25px 50px rgba(15,23,42,0.25);display:flex;flex-direction:column;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e5e7eb;background:#f9fafb;">
      <div style="font-weight:600;font-size:14px;color:#111827;">Pré-visualização do e-mail</div>
      <button type="button" data-close-preview style="border:none;background:transparent;font-size:20px;line-height:1;color:#111827;cursor:pointer;padding:4px 8px;">×</button>
    </div>
    <div class="email-preview-subject" style="padding:10px 20px;font-size:12px;color:#4b5563;border-bottom:1px solid #f3f4f6;">Assunto</div>
    <iframe title="Prévia do e-mail" style="flex:1;border:0;background:#fff;"></iframe>
  </div>
</div>

<script>
  document.querySelectorAll('[data-tab-panel]').forEach(panel => {
    panel.classList.toggle('hidden', panel.getAttribute('data-tab-panel') !== '<?= $tab; ?>');
  });
  document.querySelectorAll('[href^="settings.php?tab="]').forEach(link => {
    link.addEventListener('click', function(e){
      if (this.pathname === window.location.pathname && this.search === window.location.search) {
        e.preventDefault();
      }
    });
  });

  const EMAIL_PREVIEW_SAMPLE = Object.freeze(<?= $emailPreviewSampleJson; ?>);

  function renderEmailTemplate(template, vars) {
    let output = template || '';
    Object.keys(vars).forEach((key) => {
      const value = vars[key];
      const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const pattern = new RegExp(`{{\\s*${escapedKey}\\s*}}`, 'gi');
      output = output.replace(pattern, value);
    });
    return output;
  }

  const previewOverlay = document.getElementById('email-preview-overlay');
  const previewIframe = previewOverlay ? previewOverlay.querySelector('iframe') : null;
  const previewSubjectEl = previewOverlay ? previewOverlay.querySelector('.email-preview-subject') : null;

  function closeEmailPreview() {
    if (!previewOverlay) return;
    previewOverlay.style.display = 'none';
    if (previewIframe) {
      previewIframe.srcdoc = '';
    }
  }

  if (previewOverlay) {
    previewOverlay.addEventListener('click', (event) => {
      if (event.target === previewOverlay || event.target.hasAttribute('data-close-preview')) {
        closeEmailPreview();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeEmailPreview();
      }
    });
  }

  function openEmailPreview(type) {
    const bodyField = document.querySelector(`textarea[name="email_${type}_body"]`);
    if (!bodyField) return;
    const subjectField = document.querySelector(`input[name="email_${type}_subject"]`);
    const renderedSubject = renderEmailTemplate(subjectField ? subjectField.value : '', EMAIL_PREVIEW_SAMPLE) || '(sem assunto)';
    const renderedBody = renderEmailTemplate(bodyField.value || '', EMAIL_PREVIEW_SAMPLE);
    if (!renderedBody.trim()) {
      alert('Preencha o conteúdo do e-mail antes de pré-visualizar.');
      return;
    }
    if (!previewOverlay || !previewIframe || !previewSubjectEl) {
      alert('Não foi possível abrir a prévia no navegador.');
      return;
    }
    previewSubjectEl.textContent = 'Assunto: ' + renderedSubject;
    previewIframe.srcdoc = renderedBody;
    previewOverlay.style.display = 'flex';
  }

  document.querySelectorAll('[data-email-preview]').forEach(btn => {
    btn.addEventListener('click', () => openEmailPreview(btn.dataset.emailPreview));
  });
</script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const gmailBtn = document.getElementById('smtpGmailFill');
    if (!gmailBtn) return;
    gmailBtn.addEventListener('click', function () {
      const form = gmailBtn.closest('form');
      if (!form) return;
      const host = form.querySelector('[name="smtp_host"]');
      const port = form.querySelector('[name="smtp_port"]');
      const secure = form.querySelector('[name="smtp_secure"]');
      const user = form.querySelector('[name="smtp_user"]');
      const pass = form.querySelector('[name="smtp_pass"]');
      const fromName = form.querySelector('[name="smtp_from_name"]');
      const fromEmail = form.querySelector('[name="smtp_from_email"]');
      if (host) host.value = 'smtp.gmail.com';
      if (port) port.value = '587';
      if (secure) secure.value = 'tls';
      const storeEmail = gmailBtn.dataset.storeEmail || '';
      const storeName = gmailBtn.dataset.storeName || '';
      if (fromName && !fromName.value) fromName.value = storeName;
      if (fromEmail && !fromEmail.value) fromEmail.value = storeEmail;
      if (user && !user.value) user.value = fromEmail && fromEmail.value ? fromEmail.value : storeEmail;
      if (pass) pass.placeholder = 'Use uma senha de app do Gmail';
      alert('Campos preenchidos com o padrão do Gmail. Informe seu e-mail completo e uma Senha de app gerada na Conta Google.');
    });
  });
</script>

<?php if ($tab === 'general'): ?>
<script>
(function(){
  const form = document.getElementById('general-settings-form');
  if (!form) return;
  function encodeField(field){
    if (!field || !field.value) return;
    const encoded = btoa(unescape(encodeURIComponent(field.value)));
    field.value = '__B64__' + encoded;
  }
  form.addEventListener('submit', function(){
    const fields = [
      'privacy_policy_content',
      'refund_policy_content',
      'google_analytics_code',
      'email_customer_body',
      'email_admin_body',
      'footer_copy'
    ];
    fields.forEach(name => {
      const el = form.querySelector('[name="'+name+'"]');
      if (el && el.value.trim() !== '') {
        encodeField(el);
      }
    });
  });
})();
</script>
<?php endif; ?>

<?php if ($tab === 'payments'): ?>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
  <script>
    const list = document.getElementById("pm-sortable");
    if (list && list.dataset.sortableEnabled === '1') {
      new Sortable(list, {
        animation: 150,
        handle: ".fa-grip-lines",
        onEnd: function(){
          const ids = Array.from(list.querySelectorAll("li[data-id]")).map(el => el.dataset.id);
          fetch("settings.php?action=reorder", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify({ ids, csrf: '<?= csrf_token(); ?>' })
          });
        }
      });
    }
    const typeSelect = document.querySelector("select[name=method_type]");
    const groups = document.querySelectorAll("#type-fields [data-type]");
    function toggleTypeFields(){
      const current = typeSelect ? typeSelect.value : 'custom';
      groups.forEach(el => {
        el.style.display = (el.dataset.type === current) ? 'block' : 'none';
      });
    }
    if (typeSelect) {
      typeSelect.addEventListener('change', toggleTypeFields);
    }
    toggleTypeFields();
  </script>
<?php endif; ?>

<?php if ($tab === 'builder' && $canManageBuilder): ?>
  <link rel="stylesheet" href="https://unpkg.com/grapesjs@0.21.6/dist/css/grapes.min.css">
  <script src="https://unpkg.com/grapesjs@0.21.6/dist/grapes.min.js"></script>
  <script src="https://unpkg.com/grapesjs-blocks-basic@0.1.9/dist/grapesjs-blocks-basic.min.js"></script>
  <script>
    const API_URL = 'admin_api_layouts.php';
    const PAGE_SLUG = 'home';
    const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
    const EXISTING_LAYOUT = <?= $layoutJson; ?>;

    function showMessage(msg, type='info') {
      const alertBox = document.getElementById('builder-alert');
      alertBox.textContent = msg;
      alertBox.className = '';
      alertBox.classList.add('px-4','py-3','rounded-lg','text-sm');
      if (type === 'success') alertBox.classList.add('bg-emerald-100','text-emerald-800');
      else if (type === 'warning') alertBox.classList.add('bg-amber-100','text-amber-800');
      else if (type === 'error') alertBox.classList.add('bg-red-100','text-red-800');
      else alertBox.classList.add('bg-gray-100','text-gray-800');
      alertBox.classList.remove('hidden');
      setTimeout(()=>alertBox.classList.add('hidden'), 7000);
    }

    const editor = grapesjs.init({
      container: '#gjs',
      height: '100%','storageManager': false,
      plugins: ['gjs-blocks-basic'],
      pluginsOpts: { 'gjs-blocks-basic': { flexGrid: true } },
      blockManager: { appendTo: '.gjs-pn-blocks-container' },
      selectorManager: { appendTo: '.gjs-sm-sectors' },
      styleManager: {
        appendTo: '.gjs-style-manager',
        sectors: [
          { name: 'Layout', open: true, buildProps: ['display','position','width','height','margin','padding'] },
          { name: 'Tipografia', open: false, buildProps: ['font-family','font-size','font-weight','letter-spacing','color','line-height','text-align'] },
          { name: 'Decoração', open: false, buildProps: ['background-color','background','border-radius','box-shadow'] }
        ]
      },
      canvas: { styles: ['https://cdn.tailwindcss.com'] }
    });

    function addCustomBlocks(){
      const bm = editor.BlockManager;
      bm.add('hero-banner', {
        category: 'Seções',
        label: '<i class="fa-solid fa-image mr-2"></i>Hero Banner',
        content: `
          <section class="hero-section" style="padding:60px 20px;background:linear-gradient(135deg,#dc2626,#f59e0b);color:#fff;text-align:center;">
            <div style="max-width:700px;margin:0 auto;">
              <h1 style="font-size:42px;font-weight:700;margin-bottom:20px;">Título chamativo para sua campanha</h1>
              <p style="font-size:19px;opacity:.9;margin-bottom:30px;">Conte ao cliente o benefício principal da loja e adicione um call-to-action para o produto mais importante.</p>
              <a href="#" class="cta-btn" style="display:inline-block;padding:14px 28px;background:#fff;color:#dc2626;font-weight:600;border-radius:999px;text-decoration:none;">Comprar agora</a>
            </div>
          </section>
        `
      });
      bm.add('product-grid', {
        category: 'Seções',
        label: '<i class="fa-solid fa-table-cells mr-2"></i>Grade de Produtos',
        content: `
          <section style="padding:50px 20px;background:#f9fafb;">
            <div style="max-width:1100px;margin:0 auto;">
              <h2 style="font-size:32px;text-align:center;margin-bottom:24px;">Destaques da semana</h2>
              <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;">
                <div class="product-card" style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.08);text-align:center;">
                  <div style="height:160px;background:#f1f5f9;border-radius:12px;margin-bottom:16px;"></div>
                  <h3 style="font-size:18px;font-weight:600;margin-bottom:8px;">Nome do Produto</h3>
                  <p style="color:#475569;font-size:14px;margin-bottom:12px;">Descrição breve do produto e benefícios.</p>
                  <strong style="font-size:20px;color:#dc2626;">$ 29,90</strong>
                </div>
                <div class="product-card" style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.08);text-align:center;">
                  <div style="height:160px;background:#f1f5f9;border-radius:12px;margin-bottom:16px;"></div>
                  <h3 style="font-size:18px;font-weight:600;margin-bottom:8px;">Nome do Produto</h3>
                  <p style="color:#475569;font-size:14px;margin-bottom:12px;">Descrição breve do produto e benefícios.</p>
                  <strong style="font-size:20px;color:#dc2626;">$ 39,90</strong>
                </div>
                <div class="product-card" style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.08);text-align:center;">
                  <div style="height:160px;background:#f1f5f9;border-radius:12px;margin-bottom:16px;"></div>
                  <h3 style="font-size:18px;font-weight:600;margin-bottom:8px;">Nome do Produto</h3>
                  <p style="color:#475569;font-size:14px;margin-bottom:12px;">Descrição breve do produto e benefícios.</p>
                  <strong style="font-size:20px;color:#dc2626;">$ 19,90</strong>
                </div>
              </div>
            </div>
          </section>
        `
      });
      bm.add('testimonial', {
        category: 'Seções',
        label: '<i class="fa-solid fa-comment-dots mr-2"></i>Depoimentos',
        content: `
          <section style="padding:60px 20px;">
            <div style="max-width:900px;margin:0 auto;text-align:center;">
              <h2 style="font-size:32px;margin-bottom:30px;">O que nossos clientes dizem</h2>
              <div style="display:grid;gap:20px;">
                <blockquote style="background:#fff;border-radius:16px;padding:30px;box-shadow:0 10px 40px rgba(15,23,42,.08);">
                  <p style="font-style:italic;color:#475569;">“Excelente atendimento, entrega rápida e produtos de qualidade. Recomendo muito!”</p>
                  <footer style="margin-top:18px;font-weight:600;">Maria Andrade — Fort Lauderdale</footer>
                </blockquote>
                <blockquote style="background:#fff;border-radius:16px;padding:30px;box-shadow:0 10px 40px rgba(15,23,42,.08);">
                  <p style="font-style:italic;color:#475569;">“A loja online é super intuitiva e o suporte me ajudou rapidamente com minhas dúvidas.”</p>
                  <footer style="margin-top:18px;font-weight:600;">João Silva — Orlando</footer>
                </blockquote>
              </div>
            </div>
          </section>
        `
      });
    }
    addCustomBlocks();

    const DEFAULT_TEMPLATE = `
      <section style="padding:80px 20px;background:linear-gradient(135deg,#dc2626,#f59e0b);color:#fff;text-align:center;">
        <div style="max-width:760px;margin:0 auto;">
          <h1 style="font-size:48px;font-weight:700;margin-bottom:18px;">Tudo para sua saúde em poucos cliques</h1>
          <p style="font-size:20px;opacity:0.92;margin-bottom:28px;">Entrega rápida, atendimento humano e os melhores medicamentos do Brasil para os Estados Unidos.</p>
          <a href="#catalogo" style="display:inline-block;padding:16px 36px;border-radius:999px;background:#fff;color:#dc2626;font-weight:600;text-decoration:none;">Ver catálogo</a>
        </div>
      </section>
      <section id="catalogo" style="padding:60px 20px;background:#f9fafb;">
        <div style="max-width:1100px;margin:0 auto;">
          <h2 style="font-size:34px;font-weight:700;text-align:center;margin-bottom:16px;">Categorias em destaque</h2>
          <p style="text-align:center;color:#475569;margin-bottom:36px;">Escolha a linha de produtos que melhor atende à sua necessidade e receba tudo no conforto da sua casa.</p>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:24px;">
            <div style="background:#fff;border-radius:18px;padding:26px;box-shadow:0 10px 40px rgba(15,23,42,.08);">
              <h3 style="font-size:20px;font-weight:600;margin-bottom:6px;">Medicamentos</h3>
              <p style="color:#64748b;font-size:15px;margin-bottom:14px;">Genéricos, manipulados e medicamentos de alto custo com procedência garantida.</p>
              <a href="?route=home&category=1" style="color:#dc2626;font-weight:600;">Ver produtos →</a>
            </div>
            <div style="background:#fff;border-radius:18px;padding:26px;box-shadow:0 10px 40px rgba(15,23,42,.08);">
              <h3 style="font-size:20px;font-weight:600;margin-bottom:6px;">Suplementos</h3>
              <p style="color:#64748b;font-size:15px;margin-bottom:14px;">Vitaminas, minerais e boosters energéticos selecionados por especialistas.</p>
              <a href="?route=home&category=4" style="color:#dc2626;font-weight:600;">Ver suplementos →</a>
            </div>
            <div style="background:#fff;border-radius:18px;padding:26px;box-shadow:0 10px 40px rgba(15,23,42,.08);">
              <h3 style="font-size:20px;font-weight:600;margin-bottom:6px;">Dermocosméticos</h3>
              <p style="color:#64748b;font-size:15px;margin-bottom:14px;">Tratamentos faciais, linhas anti-idade e cuidados específicos para a pele.</p>
              <a href="?route=home&category=8" style="color:#dc2626;font-weight:600;">Ver dermocosméticos →</a>
            </div>
          </div>
        </div>
      </section>
      <section style="padding:60px 20px;">
        <div style="max-width:960px;margin:0 auto;border-radius:24px;background:linear-gradient(135deg,#22c55e,#14b8a6);padding:50px;color:#fff;">
          <h2 style="font-size:36px;font-weight:700;margin-bottom:18px;">Atendimento humano e entrega garantida</h2>
          <ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;font-size:16px;">
            <li>✔️ Pagamentos por Pix, Zelle, Venmo, PayPal, Cartão de crédito (Square) ou WhatsApp</li>
            <li>✔️ Equipe especializada para auxiliar na compra e prescrição</li>
            <li>✔️ Acompanhamento do pedido em tempo real pelo painel</li>
            <li>✔️ Entregas expressas em todo território norte-americano</li>
          </ul>
        </div>
      </section>
    `;
    const DEFAULT_STYLES = ``;

    function loadDraft(){
      try {
        const data = EXISTING_LAYOUT || {};
        let loaded = false;
        if (data.draft && data.draft.content) {
          editor.setComponents(data.draft.content);
          if (data.draft.styles) editor.setStyle(data.draft.styles);
          loaded = true;
        } else if (data.published && data.published.content) {
          showMessage('Nenhum rascunho encontrado. Carregando versão publicada.', 'warning');
          editor.setComponents(data.published.content);
          if (data.published.styles) editor.setStyle(data.published.styles);
          loaded = true;
        }
        if (!loaded) {
          editor.setComponents(DEFAULT_TEMPLATE);
          editor.setStyle(DEFAULT_STYLES);
          showMessage('Layout padrão carregado. Publique para substituir a home atual.', 'info');
        }
      } catch (err) {
        console.error(err);
        showMessage('Não foi possível carregar o layout: '+err.message, 'error');
        editor.setComponents(DEFAULT_TEMPLATE);
        editor.setStyle(DEFAULT_STYLES);
      }
    }

    function getPayload(){
      return {
        page: PAGE_SLUG,
        content: editor.getHtml({ componentFirst: true }),
        styles: editor.getCss(),
        meta: {
          updated_by: <?= json_encode($_SESSION['admin_email'] ?? 'admin', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
          updated_at: new Date().toISOString()
        },
        csrf: CSRF_TOKEN
      };
    }

    async function saveDraft(){
      showMessage('Salvando rascunho...', 'info');
      const payload = getPayload();
      try {
        const res = await fetch(API_URL+'?action=save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Erro ao salvar');
        showMessage('Rascunho salvo com sucesso!', 'success');
      } catch (err) {
        showMessage('Erro ao salvar: '+err.message, 'error');
      }
    }

    async function publishDraft(){
      showMessage('Publicando alterações...', 'info');
      await saveDraft();
      try {
        const res = await fetch(API_URL+'?action=publish', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ page: PAGE_SLUG, csrf: CSRF_TOKEN }),
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Erro ao publicar');
        showMessage('Página publicada! As mudanças já estão na home.', 'success');
      } catch (err) {
        showMessage('Erro ao publicar: '+err.message, 'error');
      }
    }

    function previewDraft(){
      const html = editor.getHtml({ componentFirst: true });
      const css = editor.getCss();
      const win = window.open('', '_blank');
      const doc = win.document;
      doc.open();
      doc.write(`
        <!doctype html>
        <html lang="pt-br">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <title>Preview - Home personalizada</title>
          <style>${css}</style>
        </head>
        <body>${html}</body>
        </html>
      `);
      doc.close();
    }

    document.getElementById('btn-save').addEventListener('click', saveDraft);
    document.getElementById('btn-publish').addEventListener('click', publishDraft);
    document.getElementById('btn-preview').addEventListener('click', previewDraft);

    if (editor.isReady) {
      loadDraft();
    } else {
      editor.on('load', loadDraft);
    }
  </script>
<?php endif; ?>
<?php
admin_footer();
