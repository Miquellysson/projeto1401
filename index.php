<?php
// index.php — Loja Get Power Research com UI estilo app (responsiva + PWA + categorias + carrinho/checkout)
// Versão com: tema vermelho/âmbar, cache-busting, endpoint CSRF ao vivo, CSRF em header, e fetch com credenciais.
// Requisitos: config.php, lib/db.php, lib/utils.php, (opcional) bootstrap.php para no-cache e asset_url()

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Anti-cache + sessão estável (recomendado)
if (file_exists(__DIR__.'/bootstrap.php')) require __DIR__.'/bootstrap.php';

require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/utils.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* ======================
   Idioma & Config
   ====================== */
if (isset($_GET['lang'])) set_lang($_GET['lang']);
$d   = lang();
$cfg = cfg();

/* ======================
   Afiliados (captura por link)
   ====================== */
$affiliateParam = $_GET['ref'] ?? ($_GET['aff'] ?? '');
if (is_string($affiliateParam) && $affiliateParam !== '') {
  $affiliateCode = function_exists('affiliate_normalize_code')
    ? affiliate_normalize_code($affiliateParam)
    : strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', $affiliateParam)));
  if ($affiliateCode !== '') {
    $_SESSION['affiliate_code'] = $affiliateCode;
    setcookie('affiliate_code', $affiliateCode, time() + (86400 * 30), '/');
  }
}
if (empty($_SESSION['affiliate_code']) && !empty($_COOKIE['affiliate_code'])) {
  $cookieAffiliate = function_exists('affiliate_normalize_code')
    ? affiliate_normalize_code((string)$_COOKIE['affiliate_code'])
    : strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', (string)$_COOKIE['affiliate_code'])));
  if ($cookieAffiliate !== '') {
    $_SESSION['affiliate_code'] = $cookieAffiliate;
  }
}

/* ======================
   Router
   ====================== */
$route = $_GET['route'] ?? 'home';

/* ======================
   Endpoint CSRF ao vivo (não-cacheável)
   ====================== */
if ($route === 'csrf') {
  header('Content-Type: application/json; charset=utf-8');
  if (!headers_sent()) {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
  }
  echo json_encode(['csrf' => csrf_token(), 'sid' => session_id()]);
  exit;
}

/* ======================
   Páginas institucionais
   ====================== */
if ($route === 'privacy') {
  $policyHtml = setting_get('privacy_policy_content', '');
  if (trim($policyHtml) === '') {
    $policyHtml = '<p>Atualize a política de privacidade pelo painel administrativo para exibir o conteúdo correspondente.</p>';
  }
  $safeContent = sanitize_builder_output($policyHtml);
  app_header();
  echo '<section class="max-w-4xl mx-auto px-4 py-12">';
  echo '  <h1 class="text-3xl font-bold mb-6">Política de Privacidade</h1>';
  echo '  <div class="prose prose-sm sm:prose lg:prose-lg text-gray-700 leading-relaxed">'. $safeContent .'</div>';
  echo '</section>';
  app_footer();
  exit;
}

if ($route === 'refund') {
  $policyHtml = setting_get('refund_policy_content', '');
  if (trim($policyHtml) === '') {
    $policyHtml = '<p>Configure a política de reembolso no painel para exibir as condições aos clientes.</p>';
  }
  $safeContent = sanitize_builder_output($policyHtml);
  app_header();
  echo '<section class="max-w-4xl mx-auto px-4 py-12">';
  echo '  <h1 class="text-3xl font-bold mb-6">Política de Reembolso</h1>';
  echo '  <div class="prose prose-sm sm:prose lg:prose-lg text-gray-700 leading-relaxed">'. $safeContent .'</div>';
  echo '</section>';
  app_footer();
  exit;
}

/* ======================
   Helpers — Header / Footer
   ====================== */
function store_logo_path() {
  $opt = setting_get('store_logo_url');
  if ($opt && file_exists(__DIR__.'/'.$opt)) return $opt;
  foreach (['storage/logo/logo.png','storage/logo/logo.jpg','storage/logo/logo.jpeg','storage/logo/logo.webp','assets/logo.png'] as $c) {
    if (file_exists(__DIR__ . '/' . $c)) return $c;
  }
  return null;
}

function proxy_allowed_hosts(): array {
  static $hosts = null;
  if ($hosts !== null) {
    return $hosts;
  }
  $config = cfg();
  $raw = $config['media']['proxy_whitelist'] ?? [];
  if (!is_array($raw)) {
    $raw = [];
  }
  $hosts = array_values(array_filter(array_map(function ($h) {
    return strtolower(trim((string)$h));
  }, $raw)));
  return $hosts;
}

/* === Proxy de Imagem (apenas esta adição para contornar hotlink) === */
function proxy_img($url) {
  $url = trim((string)$url);
  if ($url === '') {
    return '';
  }
  $url = str_replace('\\', '/', $url);
  $url = preg_replace('#^(\./)+#', '', $url);
  while (strpos($url, '../') === 0) {
    $url = substr($url, 3);
  }
  // Se for link http/https absoluto, passa pelo proxy local img.php
  if (preg_match('~^https?://~i', $url)) {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host !== '' && in_array($host, proxy_allowed_hosts(), true)) {
      return '/img.php?u=' . rawurlencode($url);
    }
    return $url;
  }
  // Garante caminho absoluto relativo ao root da aplicação
  if ($url !== '' && $url[0] !== '/') {
    $url = '/' . ltrim($url, '/');
  }
  return $url;
}

function feature_allow_html($value) {
  $value = trim((string)$value);
  $value = strip_tags($value, '<br><strong><em><span>');
  return $value;
}

if (!function_exists('sanitize_builder_output')) {
  function sanitize_builder_output($html) {
    if ($html === '' || $html === null) {
      return '';
    }
    $clean = preg_replace('#<script\b[^>]*>(.*?)</script>#is', '', (string)$html);
    $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean);
    $clean = preg_replace('/javascript\s*:/i', '', $clean);
    return $clean;
  }
}

if (!function_exists('whatsapp_widget_config')) {
  function whatsapp_widget_config() {
    static $cacheReady = false;
    static $cache = null;
    if ($cacheReady) {
      return $cache;
    }
    $cacheReady = true;
    $enabled = setting_get('whatsapp_enabled', '0');
    if ((int)$enabled !== 1) {
      return null;
    }
    $rawNumber = setting_get('whatsapp_number', '');
    $number = preg_replace('/\D+/', '', (string)$rawNumber);
    if ($number === '') {
      return null;
    }
    $buttonText = setting_get('whatsapp_button_text', 'Fale com a gente');
    $message = setting_get('whatsapp_message', 'Olá! Gostaria de tirar uma dúvida sobre os produtos.');
    $displayNumber = $number;
    if ($displayNumber !== '') {
      $displayNumber = '+' . $displayNumber;
    }
    $cache = [
      'number' => $number,
      'button_text' => $buttonText,
      'message' => $message,
      'display_number' => $displayNumber
    ];
    return $cache;
  }
}

function load_payment_methods(PDO $pdo, array $cfg): array {
  static $cache = null;
  if ($cache !== null) {
    return $cache;
  }

  $cache = [];
  try {
    $rows = $pdo->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
      $settings = [];
      if (!empty($row['settings'])) {
        $decoded = json_decode($row['settings'], true);
        if (is_array($decoded)) {
          $settings = $decoded;
        }
      }
      if (!isset($settings['type'])) {
        $settings['type'] = $row['code'];
      }
      if (!isset($settings['account_label'])) {
        $settings['account_label'] = '';
      }
      if (!isset($settings['account_value'])) {
        $settings['account_value'] = '';
      }
      $cache[] = [
        'id' => (int)$row['id'],
        'code' => (string)$row['code'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'instructions' => (string)($row['instructions'] ?? ''),
        'public_note' => (string)($row['public_note'] ?? ''),
        'settings' => $settings,
        'icon_path' => $row['icon_path'] ?? null,
        'require_receipt' => (int)($row['require_receipt'] ?? 0),
        'is_featured' => (int)($row['is_featured'] ?? 0),
      ];
    }
  } catch (Throwable $e) {
    $cache = [];
  }

  $cacheCodes = [];
  foreach ($cache as $m) {
    $cacheCodes[$m['code']] = true;
  }
  $paymentsCfg = $cfg['payments'] ?? [];
  if (!isset($cacheCodes['whatsapp']) && !empty($paymentsCfg['whatsapp']['enabled'])) {
    $whatsMethod = [
      'code' => 'whatsapp',
      'name' => 'WhatsApp',
      'description' => '',
      'instructions' => $paymentsCfg['whatsapp']['instructions'] ?? 'Converse com nossa equipe pelo WhatsApp para concluir: {whatsapp_link}.',
      'public_note' => '',
      'settings' => [
        'type' => 'whatsapp',
        'account_label' => 'WhatsApp',
        'account_value' => $paymentsCfg['whatsapp']['number'] ?? '',
        'number' => $paymentsCfg['whatsapp']['number'] ?? '',
        'message' => $paymentsCfg['whatsapp']['message'] ?? 'Olá! Gostaria de finalizar meu pedido.',
        'link' => $paymentsCfg['whatsapp']['link'] ?? ''
      ],
      'icon_path' => null,
      'require_receipt' => 0,
      'is_featured' => 0,
    ];
    $cache[] = $whatsMethod;
  }

  if (!$cache) {
    $paymentsCfg = $cfg['payments'] ?? [];
    $defaults = [];
    if (!empty($paymentsCfg['pix']['enabled'])) {
      $defaults[] = [
        'code' => 'pix',
        'name' => 'Pix',
        'instructions' => "Use o Pix para pagar seu pedido. Valor: {valor_pedido}.\nChave: {pix_key}",
        'settings' => [
          'type' => 'pix',
          'account_label' => 'Chave Pix',
          'account_value' => $paymentsCfg['pix']['pix_key'] ?? '',
          'pix_key' => $paymentsCfg['pix']['pix_key'] ?? '',
          'merchant_name' => $paymentsCfg['pix']['merchant_name'] ?? '',
          'merchant_city' => $paymentsCfg['pix']['merchant_city'] ?? ''
        ],
        'require_receipt' => 0
      ];
    }
    if (!empty($paymentsCfg['zelle']['enabled'])) {
      $defaults[] = [
        'code' => 'zelle',
        'name' => 'Zelle',
        'instructions' => "Envie {valor_pedido} via Zelle ({valor_produtos} + frete {valor_frete}) para {account_value}.",
        'settings' => [
          'type' => 'zelle',
          'account_label' => 'Conta Zelle',
          'account_value' => $paymentsCfg['zelle']['recipient_email'] ?? '',
          'recipient_name' => $paymentsCfg['zelle']['recipient_name'] ?? ''
        ],
        'require_receipt' => (int)($paymentsCfg['zelle']['require_receipt_upload'] ?? 1)
      ];
    }
    if (!empty($paymentsCfg['venmo']['enabled'])) {
      $defaults[] = [
        'code' => 'venmo',
        'name' => 'Venmo',
        'instructions' => "Pague {valor_pedido} no Venmo. Link: {venmo_link}.",
        'settings' => [
          'type' => 'venmo',
          'account_label' => 'Link Venmo',
          'account_value' => $paymentsCfg['venmo']['handle'] ?? '',
          'venmo_link' => $paymentsCfg['venmo']['handle'] ?? ''
        ],
        'require_receipt' => 1
      ];
    }
    if (!empty($paymentsCfg['paypal']['enabled'])) {
      $defaults[] = [
        'code' => 'paypal',
        'name' => 'PayPal',
        'instructions' => "Após finalizar, você será direcionado ao PayPal com o valor {valor_pedido}.",
        'settings' => [
          'type' => 'paypal',
          'account_label' => 'Conta PayPal',
          'account_value' => $paymentsCfg['paypal']['business'] ?? '',
          'business' => $paymentsCfg['paypal']['business'] ?? '',
          'currency' => $paymentsCfg['paypal']['currency'] ?? 'USD',
          'return_url' => $paymentsCfg['paypal']['return_url'] ?? '',
          'cancel_url' => $paymentsCfg['paypal']['cancel_url'] ?? '',
          'mode' => 'standard',
          'redirect_url' => '',
          'open_new_tab' => !empty($paymentsCfg['paypal']['open_new_tab'])
        ],
        'require_receipt' => 0
      ];
    }
    if (!empty($paymentsCfg['whatsapp']['enabled'])) {
      $defaults[] = [
        'code' => 'whatsapp',
        'name' => 'WhatsApp',
        'instructions' => $paymentsCfg['whatsapp']['instructions'] ?? 'Converse com nossa equipe pelo WhatsApp para concluir: {whatsapp_link}.',
        'settings' => [
          'type' => 'whatsapp',
          'account_label' => 'WhatsApp',
          'account_value' => $paymentsCfg['whatsapp']['number'] ?? '',
          'number' => $paymentsCfg['whatsapp']['number'] ?? '',
          'message' => $paymentsCfg['whatsapp']['message'] ?? 'Olá! Gostaria de finalizar meu pedido.',
          'link' => $paymentsCfg['whatsapp']['link'] ?? ''
        ],
        'require_receipt' => 0
      ];
    }
    if (!empty($paymentsCfg['square']['enabled'])) {
      $defaults[] = [
        'code' => 'square',
        'name' => 'Cartão de crédito',
        'instructions' => $paymentsCfg['square']['instructions'] ?? 'Abriremos o checkout de cartão de crédito em uma nova aba.',
        'settings' => [
          'type' => 'square',
          'account_label' => 'Pagamento com cartão de crédito',
          'account_value' => '',
          'mode' => 'square_product_link',
          'open_new_tab' => !empty($paymentsCfg['square']['open_new_tab'])
        ],
        'require_receipt' => 0
      ];
    }
    if (!empty($paymentsCfg['stripe']['enabled'])) {
      $defaults[] = [
        'code' => 'stripe',
        'name' => 'Stripe',
        'instructions' => $paymentsCfg['stripe']['instructions'] ?? 'Abriremos o checkout Stripe em uma nova aba.',
        'settings' => [
          'type' => 'stripe',
          'account_label' => 'Pagamento Stripe',
          'account_value' => '',
          'mode' => 'stripe_product_link',
          'open_new_tab' => !empty($paymentsCfg['stripe']['open_new_tab'])
        ],
        'require_receipt' => 0
      ];
    }
    $cache = $defaults;
  }

  return $cache;
}

function payment_placeholders(
  array $method,
  float $totalValue,
  ?int $orderId = null,
  ?string $customerEmail = null,
  ?float $subtotalValue = null,
  ?float $shippingValue = null,
  ?string $currencyOverride = null
): array {
  $settings = $method['settings'] ?? [];
  $currency = $currencyOverride ?: ($settings['currency'] ?? (cfg()['store']['currency'] ?? 'USD'));
  $subtotalValue = ($subtotalValue === null) ? $totalValue : $subtotalValue;
  $shippingValue = ($shippingValue === null) ? 0.0 : $shippingValue;
  $placeholders = [
    '{valor_pedido}' => format_currency($totalValue, $currency),
    '{valor_total}' => format_currency($totalValue, $currency),
    '{valor_total_com_frete}' => format_currency($totalValue, $currency),
    '{valor_produtos}' => format_currency($subtotalValue, $currency),
    '{valor_subtotal}' => format_currency($subtotalValue, $currency),
    '{valor_frete}' => format_currency($shippingValue, $currency),
    '{numero_pedido}' => $orderId ? (string)$orderId : '',
    '{email_cliente}' => $customerEmail ?? '',
    '{account_label}' => $settings['account_label'] ?? '',
    '{account_value}' => $settings['account_value'] ?? '',
    '{pix_key}' => $settings['pix_key'] ?? ($settings['account_value'] ?? ''),
    '{pix_merchant_name}' => $settings['merchant_name'] ?? '',
    '{pix_merchant_city}' => $settings['merchant_city'] ?? '',
    '{venmo_link}' => $settings['venmo_link'] ?? ($settings['account_value'] ?? ''),
    '{paypal_business}' => $settings['business'] ?? '',
    '{paypal_link}' => $settings['redirect_url'] ?? '',
    '{stripe_link}' => $settings['redirect_url'] ?? '',
  ];
  $waNumberRaw = trim((string)($settings['number'] ?? $settings['account_value'] ?? ''));
  $waMessage = trim((string)($settings['message'] ?? ''));
  $waLinkCustom = trim((string)($settings['link'] ?? ''));
  $waNumberDigits = preg_replace('/\D+/', '', $waNumberRaw);
  $waLink = $waLinkCustom;
  if ($waNumberDigits !== '') {
    $waLink = 'https://wa.me/'.$waNumberDigits;
    if ($waMessage !== '') {
      $waLink .= '?text='.rawurlencode($waMessage);
    }
  }
  if ($waLink === '' && $waNumberRaw !== '') {
    $waLink = $waNumberRaw;
  }
  if ($waLink === '' && !empty($settings['account_value'])) {
    $waLink = $settings['account_value'];
  }
  $placeholders['{whatsapp_number}'] = $waNumberRaw !== '' ? $waNumberRaw : ($waNumberDigits !== '' ? '+' . $waNumberDigits : '');
  $placeholders['{whatsapp_link}'] = $waLink;
  $placeholders['{whatsapp_message}'] = $waMessage;

  return $placeholders;
}

function render_payment_instructions(string $template, array $placeholders): string {
  if ($template === '') {
    return '';
  }
  $text = strtr($template, $placeholders);
  return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
}

function app_header() {
  global $d, $cfg;

  $lang = $d['_lang'] ?? 'pt';
  $logo = store_logo_path();
  $logoUrl = $logo ? versioned_public_url($logo) : '';
  $metaTitle = setting_get('store_meta_title', (($cfg['store']['name'] ?? 'Get Power Research').' | Loja'));
  if (!empty($GLOBALS['app_meta_title'])) {
    $metaTitle = (string)$GLOBALS['app_meta_title'];
  }
  $pwaName = setting_get('pwa_name', $cfg['store']['name'] ?? 'Get Power Research');
  $pwaShortName = setting_get('pwa_short_name', $pwaName);
  $pwaIconApple = pwa_icon_url(180);
  $pwaIcon512 = pwa_icon_url(512);

  // Count carrinho
  $cart_count = 0;
  if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cart_count = array_sum($_SESSION['cart']);
  }

  echo '<!doctype html><html lang="'.htmlspecialchars($lang).'"><head>';
  echo '  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '  <title>'.htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8').'</title>';
  $defaultDescription = setting_get('store_meta_description', ($cfg['store']['name'] ?? 'Get Power Research').' — experiência tipo app: rápida, responsiva e segura.');
  if (!empty($GLOBALS['app_meta_description'])) {
    $defaultDescription = (string)$GLOBALS['app_meta_description'];
  }
  echo '  <meta name="description" content="'.htmlspecialchars($defaultDescription, ENT_QUOTES, 'UTF-8').'">';
  echo '  <link rel="manifest" href="/manifest.php">';
  $themeColor = setting_get('theme_color', '#2060C8');
  echo '  <meta name="theme-color" content="'.htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8').'">';
  echo '  <meta name="application-name" content="'.htmlspecialchars($pwaShortName, ENT_QUOTES, 'UTF-8').'">';

  // ====== iOS PWA (suporte ao Add to Home Screen) ======
  $appleIconHref = $pwaIconApple ?: '/assets/icons/admin-192.png';
  echo '  <link rel="apple-touch-icon" href="'.htmlspecialchars($appleIconHref, ENT_QUOTES, 'UTF-8').'">';
  echo '  <meta name="apple-mobile-web-app-capable" content="yes">';
  echo '  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
  echo '  <meta name="apple-mobile-web-app-title" content="'.htmlspecialchars($pwaName, ENT_QUOTES, 'UTF-8').'">';
  echo '  <link rel="icon" type="image/png" sizes="512x512" href="'.htmlspecialchars($pwaIcon512 ?: '/assets/icons/admin-512.png', ENT_QUOTES, 'UTF-8').'">';

  echo '  <script src="https://cdn.tailwindcss.com"></script>';
  $brandPalette = generate_brand_palette($themeColor);
  $accentPalette = ['400' => adjust_color_brightness($themeColor, 0.35)];
  $brandJson = json_encode($brandPalette, JSON_UNESCAPED_SLASHES);
  $accentJson = json_encode($accentPalette, JSON_UNESCAPED_SLASHES);
  echo "  <script>tailwind.config = { theme: { extend: { colors: { brand: $brandJson, accent: $accentJson }}}};</script>";
  echo '  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">';
  echo '  <script defer src="/assets/js/a2hs.js?v=3"></script>';

  // CSS do tema com cache-busting se disponível
  if (function_exists('asset_url')) {
    echo '  <link href="'.asset_url('assets/theme.css').'" rel="stylesheet">';
  } else {
    echo '  <link href="assets/theme.css" rel="stylesheet">';
  }
  echo '  <style>
          .btn{transition:all .2s}
          .btn:active{transform:translateY(1px)}
          .badge{min-width:1.5rem; height:1.5rem}
          .card{background:var(--bg, #fff)}
          .blur-bg{backdrop-filter: blur(12px)}
          .a2hs-btn{border:1px solid rgba(185,28,28,.25)}
          .chip{border:1px solid #e5e7eb}
          .line-clamp-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
          .product-card:hover img{transform: scale(1.05)}
        </style>';
  $badgeColorSetting = setting_get('badge_one_month_color', '#0EA5E9');
  $badgeColor = '#'.normalize_hex_color($badgeColorSetting);
  $badgeColorLight = adjust_color_brightness($badgeColor, 0.25);
  echo '<style>.product-ribbon{background:linear-gradient(135deg,'.htmlspecialchars($badgeColor, ENT_QUOTES, 'UTF-8').','.htmlspecialchars($badgeColorLight, ENT_QUOTES, 'UTF-8').');}</style>';
  if (!empty($cfg['custom_scripts']['head'])) {
    echo $cfg['custom_scripts']['head'];
  }
  $googleAnalyticsSnippet = setting_get('google_analytics_code', '');
  if (!empty($googleAnalyticsSnippet)) {
    echo "\n  " . $googleAnalyticsSnippet;
  }
  $squareLoadingUrl = 'square-loading.html';
  $squareLoadingFile = __DIR__.'/square-loading.html';
  if (is_file($squareLoadingFile)) {
    $squareLoadingUrl .= '?v='.filemtime($squareLoadingFile);
  }
  $bodyAttrs = 'class="bg-gray-50 text-gray-800 min-h-screen" data-square-loading-url="'.htmlspecialchars($squareLoadingUrl, ENT_QUOTES, 'UTF-8').'"';
  echo '</head><body '.$bodyAttrs.'>';
  if (!empty($cfg['custom_scripts']['body_start'])) {
    echo $cfg['custom_scripts']['body_start'];
  }

  // Topbar (estilo app) — sticky + blur
  echo '<header class="sticky top-0 z-40 border-b bg-white/90 blur-bg">';
  echo '  <div class="max-w-7xl mx-auto px-4 py-3 flex flex-col md:flex-row md:items-center md:justify-between gap-3">';
  echo '    <a href="?route=home" class="flex items-center gap-3">';
  if ($logoUrl) {
    echo '      <img src="'.htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8').'" class="w-16 h-16 rounded-2xl object-contain bg-white p-1 shadow-sm" alt="logo">';
  } else {
    echo '      <div class="w-16 h-16 rounded-2xl bg-brand-600 text-white grid place-items-center text-2xl"><i class="fas fa-pills"></i></div>';
  }
  echo '      <div>';
  $storeNameHeader = setting_get('store_name', $cfg['store']['name'] ?? 'Get Power Research');
  $headerSubline = setting_get('header_subline', 'Loja Online');
  echo '        <div class="font-semibold leading-tight">'.htmlspecialchars($storeNameHeader, ENT_QUOTES, 'UTF-8').'</div>';
  echo '        <div class="text-xs text-gray-500">'.htmlspecialchars($headerSubline, ENT_QUOTES, 'UTF-8').'</div>';
  echo '      </div>';
  echo '    </a>';

  echo '    <div class="flex flex-wrap items-center gap-2 w-full md:w-auto mt-2 md:mt-0">';
  // Botão A2HS (Add to Home Screen)
  echo '      <button id="btnA2HS" class="a2hs-btn hidden px-3 py-2 rounded-lg text-brand-600 bg-brand-50 hover:bg-brand-100 text-sm"><i class="fa-solid fa-mobile-screen-button mr-1"></i> Instalar app</button>';

  // Troca de idioma
  echo '      <div class="relative">';
  echo '        <select onchange="changeLanguage(this.value)" class="px-3 py-2 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-brand-500 text-sm">';
  $languages = ['pt'=>'🇧🇷 PT','en'=>'🇺🇸 EN','es'=>'🇪🇸 ES'];
  foreach ($languages as $code=>$label) {
    $selected = (($d["_lang"] ?? "pt") === $code) ? "selected" : "";
    echo '          <option value="'.$code.'" '.$selected.'>'.$label.'</option>';
  }
  echo '        </select>';
  echo '      </div>';

  // Minha conta
  echo '      <a href="?route=account" class="px-3 py-2 rounded-lg border border-brand-200 text-brand-600 bg-white hover:bg-brand-50 flex items-center gap-2 text-sm">';
  echo '        <i class="fa-solid fa-user-circle"></i>';
  echo '        <span class="hidden sm:inline">Minha conta</span>';
  echo '      </a>';

  // Carrinho
  echo '      <a href="?route=cart" class="relative">';
  echo '        <div class="btn flex items-center gap-2 px-3 py-2 rounded-lg bg-brand-600 text-white hover:bg-brand-700">';
  echo '          <i class="fas fa-shopping-cart"></i>';
  echo '          <span class="hidden sm:inline">'.htmlspecialchars($d['cart'] ?? 'Carrinho').'</span>';
  if ($cart_count > 0) {
    echo '        <span id="cart-badge" class="badge absolute -top-2 -right-2 rounded-full bg-red-500 text-white text-xs grid place-items-center px-1">'.(int)$cart_count.'</span>';
  } else {
    echo '        <span id="cart-badge" class="badge hidden absolute -top-2 -right-2 rounded-full bg-red-500 text-white text-xs grid place-items-center px-1">0</span>';
  }
  echo '        </div>';
  echo '      </a>';
  echo '    </div>';
  echo '  </div>';
  echo '</header>';

  echo '<main>';
}

function app_footer() {
  global $cfg;
  echo '</main>';

  // Footer enxuto tipo app
  echo '<footer class="mt-12 bg-white border-t">';
  echo '  <div class="max-w-7xl mx-auto px-4 py-8 grid md:grid-cols-4 gap-8 text-sm">';
  echo '    <div>';
  $footerTitle = setting_get('footer_title', 'Get Power Research');
  $footerDescription = setting_get('footer_description', 'Sua loja online com experiência de app.');
  echo '      <div class="font-semibold mb-2">'.htmlspecialchars($footerTitle, ENT_QUOTES, 'UTF-8').'</div>';
  echo '      <p class="text-gray-500">'.htmlspecialchars($footerDescription, ENT_QUOTES, 'UTF-8').'</p>';
  echo '    </div>';
  echo '    <div>';
  echo '      <div class="font-semibold mb-2">Links</div>';
  echo '      <ul class="space-y-2 text-gray-600">';
  echo '        <li><a class="hover:text-brand-700" href="?route=home">Início</a></li>';
  echo '        <li><a class="hover:text-brand-700" href="?route=cart">Carrinho</a></li>';
  echo '        <li><a class="hover:text-brand-700" href="#" onclick="installPrompt()"><i class="fa-solid fa-mobile-screen-button mr-1"></i> Instalar app</a></li>';
  echo '        <li><a class="hover:text-brand-700" href="?route=privacy">Política de Privacidade</a></li>';
  echo '        <li><a class="hover:text-brand-700" href="?route=refund">Política de Reembolso</a></li>';
  echo '      </ul>';
  echo '    </div>';
  echo '    <div>';
  echo '      <div class="font-semibold mb-2">Contato</div>';
  echo '      <ul class="space-y-2 text-gray-600">';
  $storeConfig = cfg();
  echo '        <li><i class="fa-solid fa-envelope mr-2"></i>'.htmlspecialchars(setting_get('store_email', $storeConfig['store']['support_email'] ?? 'contato@getpowerresearch.com')).'</li>';
  echo '        <li><i class="fa-solid fa-phone mr-2"></i>'.htmlspecialchars(setting_get('store_phone', $storeConfig['store']['phone'] ?? '(82) 99999-9999')).'</li>';
  echo '        <li><i class="fa-solid fa-location-dot mr-2"></i>'.htmlspecialchars(setting_get('store_address', $storeConfig['store']['address'] ?? 'Maceió - AL')).'</li>';
  echo '      </ul>';
  echo '    </div>';
  echo '    <div>';
  echo '      <div class="font-semibold mb-2">Idioma</div>';
  echo '      <div class="flex gap-2">';
  echo '        <button class="chip px-3 py-1 rounded" onclick="changeLanguage(\'pt\')">🇧🇷 PT</button>';
  echo '        <button class="chip px-3 py-1 rounded" onclick="changeLanguage(\'en\')">🇺🇸 EN</button>';
  echo '        <button class="chip px-3 py-1 rounded" onclick="changeLanguage(\'es\')">🇪🇸 ES</button>';
  echo '      </div>';
  echo '    </div>';
  echo '  </div>';
  $storeNameFooter = setting_get('store_name', $storeConfig['store']['name'] ?? 'Get Power Research');
  $footerCopyTpl = setting_get('footer_copy', '© {{year}} {{store_name}}. Todos os direitos reservados.');
  $footerCopyText = strtr($footerCopyTpl, [
    '{{year}}' => date('Y'),
    '{{store_name}}' => $storeNameFooter,
  ]);
  echo '  <div class="text-center text-xs text-gray-500 py-4 border-t">'.sanitize_html($footerCopyText).'</div>';
  echo '</footer>';
  $whats = whatsapp_widget_config();
  if ($whats) {
    $buttonText = htmlspecialchars($whats['button_text'], ENT_QUOTES, 'UTF-8');
    $displayNumber = htmlspecialchars($whats['display_number'], ENT_QUOTES, 'UTF-8');
    $message = $whats['message'] ?? '';
    $url = 'https://wa.me/'.$whats['number'];
    if ($message !== '') {
      $url .= '?text='.rawurlencode($message);
    }
    $urlAttr = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo '<div class="fixed z-50 bottom-5 right-4 sm:bottom-8 sm:right-6 whatsapp-button">';
    echo '  <a href="'.$urlAttr.'" target="_blank" rel="noopener noreferrer" class="group flex items-center gap-3 bg-[#25D366] text-white px-4 py-3 rounded-full shadow-lg hover:shadow-xl transition-all duration-200 hover:-translate-y-1">';
    echo '    <span class="text-2xl"><i class="fa-brands fa-whatsapp"></i></span>';
    echo '    <span class="flex flex-col leading-tight">';
    echo '      <span class="text-sm font-semibold">'.$buttonText.'</span>';
    if ($displayNumber !== '+') {
      echo '      <span class="text-xs opacity-80">'.$displayNumber.'</span>';
    }
    echo '    </span>';
    echo '  </a>';
    echo '</div>';
  }

  /* === Banner Add To Home Screen (A2HS) — Android + iOS === */
  echo '<div id="a2hsBanner" class="fixed bottom-4 left-1/2 -translate-x-1/2 bg-white shadow-lg rounded-xl px-4 py-3 flex items-center gap-3 border hidden z-50">';
  echo '  <span class="text-sm">📲 Instale o app para uma experiência melhor</span>';
  echo '  <button id="a2hsInstall" class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm hover:bg-brand-700">Instalar</button>';
  echo '  <button id="a2hsClose" class="ml-1 text-gray-500 text-lg leading-none">&times;</button>';
  echo '</div>';

  // Scripts (A2HS + helpers + carrinho AJAX com CSRF dinâmico)
  echo '<script>
    // ========= Utilidades A2HS =========
    let deferredPrompt = null;

    const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isStandalone = () => window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;

    function ensureBtn() {
      const b = document.getElementById("btnA2HS");
      if (b) b.classList.remove("hidden");
      return b;
    }

    function showBanner() {
      document.getElementById("a2hsBanner")?.classList.remove("hidden");
    }
    function hideBanner() {
      document.getElementById("a2hsBanner")?.classList.add("hidden");
    }

    function showIOSInstallHelp() {
      // overlay simples com instruções para iPhone
      document.getElementById("ios-a2hs-overlay")?.remove();
      const overlay = document.createElement("div");
      overlay.id = "ios-a2hs-overlay";
      overlay.className = "fixed inset-0 bg-black/50 z-[1000] grid place-items-center p-4";
      overlay.innerHTML = `
        <div class="max-w-sm w-full bg-white rounded-2xl shadow-xl p-5">
          <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-lg bg-brand-600 text-white grid place-items-center">
              <i class="fa-solid fa-mobile-screen-button"></i>
            </div>
            <div class="flex-1">
              <div class="font-semibold text-lg mb-1">Adicionar à Tela de Início</div>
              <p class="text-sm text-gray-600">
                No iPhone, toque em <b>Compartilhar</b>
                (ícone <i class="fa-solid fa-arrow-up-from-bracket"></i>)
                e depois em <b>Adicionar à Tela de Início</b>.
              </p>
              <ol class="text-sm text-gray-600 list-decimal ml-5 mt-3 space-y-1">
                <li>Toque em <b>Compartilhar</b> na barra inferior.</li>
                <li>Role as opções e toque em <b>Adicionar à Tela de Início</b>.</li>
                <li>Confirme com <b>Adicionar</b>.</li>
              </ol>
            </div>
          </div>
          <div class="mt-4 text-right">
            <button id="ios-a2hs-close" class="px-4 py-2 rounded-lg border hover:bg-gray-50">Fechar</button>
          </div>
        </div>
      `;
      document.body.appendChild(overlay);
      document.getElementById("ios-a2hs-close")?.addEventListener("click", () => overlay.remove());
      overlay.addEventListener("click", (e) => { if (e.target === overlay) overlay.remove(); });
    }

    // Deixa o botão visível por padrão como fallback
    ensureBtn();

    // Chrome/Android/desktop: evento nativo
    window.addEventListener("beforeinstallprompt", (e) => {
      e.preventDefault();
      deferredPrompt = e;
      ensureBtn();
      showBanner();
    });

    function installPrompt() {
      if (isIOS() && !isStandalone()) {
        // iOS não tem beforeinstallprompt — mostra instruções
        showIOSInstallHelp();
        hideBanner();
        return;
      }
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(() => { deferredPrompt = null; });
      hideBanner();
    }

    document.getElementById("btnA2HS")?.addEventListener("click", installPrompt);
    document.getElementById("a2hsInstall")?.addEventListener("click", installPrompt);
    document.getElementById("a2hsClose")?.addEventListener("click", hideBanner);

    // Ao carregar: em iOS que não está instalado, exibe o banner e o botão
    window.addEventListener("load", () => {
      if (isIOS() && !isStandalone()) {
        ensureBtn();
        showBanner();
      }
      // registra SW com versão (evita cache antigo)
      if ("serviceWorker" in navigator) {
        try { navigator.serviceWorker.register("sw.js?v=2"); } catch(e){}
      }
    });

    // ========= Resto (helpers) =========
    function changeLanguage(code){
      const url = new URL(window.location);
      url.searchParams.set("lang", code);
      window.location.href = url.toString();
    }

    function toast(msg, kind="success"){
      const div = document.createElement("div");
      div.className = "fixed bottom-4 left-1/2 -translate-x-1/2 z-50 px-4 py-3 rounded-lg text-white "+(kind==="error"?"bg-red-600":"bg-green-600");
      div.textContent = msg;
      document.body.appendChild(div);
      setTimeout(()=>div.remove(), 2500);
    }

    function updateCartBadge(val){
      const b = document.getElementById("cart-badge");
      if (!b) return;
      if (val>0) { b.textContent = val; b.classList.remove("hidden"); }
      else { b.classList.add("hidden"); }
    }

    // === CSRF dinâmico ===
    async function getCsrf() {
      const r = await fetch("?route=csrf", {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "X-Requested-With": "XMLHttpRequest" }
      });
      if (!r.ok) throw new Error("Falha ao obter CSRF");
      const j = await r.json();
      return j.csrf;
    }
    async function postWithCsrf(url, formData) {
      const token = await getCsrf();
      formData = formData || new FormData();
      if (!formData.has("csrf")) formData.append("csrf", token);
      const r = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": token
        },
        body: formData
      });
      return r;
    }

    async function addToCart(productId, productName, quantity){
      const qty = Math.max(1, parseInt(quantity, 10) || 1);
      const form = new FormData();
      form.append("id",productId);
      form.append("qty", qty);
      try {
        const r = await postWithCsrf("?route=add_cart", form);
        if(!r.ok){ throw new Error("Erro no servidor"); }
        const j = await r.json();
        if(j.success){
          if (j.message) {
            toast(j.message, "success");
          } else {
            toast(productName+" adicionado!", "success");
          }
          updateCartBadge(j.cart_count || 0);
          return true;
        }
        toast(j.error || "Falha ao adicionar", "error");
        return false;
      } catch(e){
        toast("Erro ao adicionar ao carrinho", "error");
        return false;
      }
    }

    async function updateQuantity(id, delta){
      const form = new FormData();
      form.append("id", id);
      form.append("delta", delta);
      try {
        const r = await postWithCsrf("?route=update_cart", form);
        if(r.ok){ location.reload(); }
        else { toast("Erro ao atualizar carrinho", "error"); }
      } catch(e){
        toast("Erro ao atualizar carrinho", "error");
      }
    }
  </script>';

  if (!empty($cfg['custom_scripts']['body_end'])) {
    echo $cfg['custom_scripts']['body_end'];
  }

  echo '</body></html>';
}

/* ======================
   ROUTES
   ====================== */

// HOME — busca + categorias + listagem
if ($route === 'home') {
  app_header();
  $pdo = db();

  $builderHtml = '';
  $builderCss  = '';
  try {
    $stLayout = $pdo->prepare("SELECT content, styles FROM page_layouts WHERE page_slug = ? AND status = 'published' LIMIT 1");
    $stLayout->execute(['home']);
    $layoutRow = $stLayout->fetch(PDO::FETCH_ASSOC);
    if ($layoutRow) {
      $builderHtml = sanitize_builder_output($layoutRow['content'] ?? '');
      $builderCss  = trim((string)($layoutRow['styles'] ?? ''));
    }
  } catch (Throwable $e) {
    $builderHtml = '';
    $builderCss = '';
  }
  $hasCustomLayout = ($builderHtml !== '');

  $q = trim((string)($_GET['q'] ?? ''));
  $category_id = (int)($_GET['category'] ?? 0);

  // categorias ativas
  $categories = [];
  try {
    $sqlCategories = "
      SELECT DISTINCT c.*
      FROM categories c
      INNER JOIN products p ON p.category_id = c.id AND p.active = 1
      WHERE c.active = 1
      ORDER BY c.sort_order, c.name
    ";
    $categories = $pdo->query($sqlCategories)->fetchAll();
  } catch (Throwable $e) { /* sem categorias ainda */ }

  $heroTitle = setting_get('home_hero_title', 'Tudo para sua saúde');
  $heroSubtitle = setting_get('home_hero_subtitle', 'Experiência de app, rápida e segura.');
  $heroTitleHtml = htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8');
  $heroSubtitleHtml = htmlspecialchars($heroSubtitle, ENT_QUOTES, 'UTF-8');
  $heroBackground = setting_get('hero_background', 'gradient');
  $heroBackgroundType = setting_get('hero_background_type', $heroBackground);
  $heroAccentColor = setting_get('hero_accent_color', '#F59E0B');
  $heroGradientFrom = '#'.normalize_hex_color(setting_get('hero_gradient_from', $heroAccentColor));
  $heroGradientTo = '#'.normalize_hex_color(setting_get('hero_gradient_to', '#0F172A'));
  $heroSolidColor = '#'.normalize_hex_color(setting_get('hero_solid_color', $heroAccentColor));
  $heroBackgroundImage = setting_get('hero_background_image', '');
  $heroSearchPlaceholder = setting_get('hero_search_placeholder', $d['search'] ?? 'Buscar');
  $heroSearchButtonLabel = setting_get('hero_search_button_label', $d['search'] ?? 'Buscar');
  $heroSupportLeft = setting_get('hero_support_text_left', 'Atendimento humano rápido');
  $heroSupportRight = setting_get('hero_support_text_right', 'Produtos verificados');
  $heroHighlights = setting_get('hero_highlights', []);
  if (!is_array($heroHighlights) || !$heroHighlights) {
    $heroHighlights = hero_default_highlights();
  } else {
    $defaultsHighlights = hero_default_highlights();
    foreach ($heroHighlights as $idx => &$entry) {
      $default = $defaultsHighlights[$idx] ?? $defaultsHighlights[0];
      $entry = array_merge($default, is_array($entry) ? $entry : []);
    }
    unset($entry);
  }
  $heroBackgroundType = in_array($heroBackgroundType, ['gradient','solid','image'], true) ? $heroBackgroundType : 'gradient';
  $heroSearchPlaceholderHtml = htmlspecialchars($heroSearchPlaceholder, ENT_QUOTES, 'UTF-8');
  $heroSearchButtonHtml = htmlspecialchars($heroSearchButtonLabel, ENT_QUOTES, 'UTF-8');
  $heroSupportLeftHtml = htmlspecialchars($heroSupportLeft, ENT_QUOTES, 'UTF-8');
  $heroSupportRightHtml = htmlspecialchars($heroSupportRight, ENT_QUOTES, 'UTF-8');
  $featuredEnabled = (int)setting_get('home_featured_enabled', '0') === 1;
  $featuredTitle = setting_get('home_featured_title', 'Ofertas em destaque');
  $featuredSubtitleHtml = feature_allow_html(setting_get('home_featured_subtitle', 'Seleção especial com preços imperdíveis.'));
  $featuredLabelHtml = feature_allow_html(setting_get('home_featured_label', 'Oferta destaque'));
  $featuredBadgeTitleHtml = feature_allow_html(setting_get('home_featured_badge_title', 'Seleção especial'));
  $featuredBadgeTextHtml = feature_allow_html(setting_get('home_featured_badge_text', 'Selecionados com carinho para você'));
  $featuredTitleHtml = htmlspecialchars($featuredTitle, ENT_QUOTES, 'UTF-8');

  if ($hasCustomLayout) {
    if ($builderCss !== '') {
      echo '<style id="home-builder-css">'.$builderCss.'</style>';
    }
    echo '<section class="home-custom-layout">'.$builderHtml.'</section>';
    echo '<section class="max-w-7xl mx-auto px-4 pt-6 pb-4">';
    echo '  <form method="get" class="bg-white rounded-2xl shadow px-4 py-4 flex flex-col lg:flex-row gap-3 items-stretch">';
    echo '    <input type="hidden" name="route" value="home">';
    echo '    <input class="flex-1 rounded-xl px-4 py-3 border border-gray-200" name="q" value="'.htmlspecialchars($q).'" placeholder="'.htmlspecialchars($d['search'] ?? 'Buscar').'...">';
    echo '    <button class="px-5 py-3 rounded-xl bg-brand-600 text-white font-semibold hover:bg-brand-700"><i class="fa-solid fa-search mr-2"></i>'.htmlspecialchars($d['search'] ?? 'Buscar').'</button>';
    echo '  </form>';
    echo '</section>';
  } else {
    $heroClasses = 'text-white py-12 md:py-16 mb-10 home-hero';
    $heroStyleAttr = '';
    if ($heroBackgroundType === 'gradient') {
      $heroStyleAttr = ' style="background: linear-gradient(135deg, '.htmlspecialchars($heroGradientFrom, ENT_QUOTES, 'UTF-8').', '.htmlspecialchars($heroGradientTo, ENT_QUOTES, 'UTF-8').');"';
    } elseif ($heroBackgroundType === 'solid') {
      $heroStyleAttr = ' style="background: '.htmlspecialchars($heroSolidColor, ENT_QUOTES, 'UTF-8').';"';
    } elseif ($heroBackgroundType === 'image' && $heroBackgroundImage !== '') {
      $bgUrl = strpos($heroBackgroundImage, 'http') === 0 ? $heroBackgroundImage : '/'.ltrim($heroBackgroundImage, '/');
      $heroStyleAttr = ' style="background:url('.htmlspecialchars($bgUrl, ENT_QUOTES, 'UTF-8').') center / cover no-repeat;"';
    }
    echo '<section class="'.$heroClasses.'"'.$heroStyleAttr.'>';
    echo '  <div class="max-w-7xl mx-auto px-4 hero-section">';
    echo '    <div class="grid lg:grid-cols-[1.1fr,0.9fr] gap-10 items-center">';
    echo '      <div class="text-left space-y-6">';
    echo '        <div>';
    echo '          <h2 class="text-3xl md:text-5xl font-bold mb-3 leading-tight hero-title">'.$heroTitleHtml.'</h2>';
    echo '          <p class="text-white/90 text-lg md:text-xl hero-subtitle">'.$heroSubtitleHtml.'</p>';
    echo '        </div>';
    echo '        <form method="get" class="flex flex-col sm:flex-row gap-3 bg-white/10 p-2 rounded-2xl backdrop-blur hero-search">';
    echo '          <input type="hidden" name="route" value="home">';
    echo '          <input class="flex-1 rounded-xl px-4 py-3 text-gray-900 placeholder-gray-500 focus:ring-4 focus:ring-brand-200 hero-input" name="q" value="'.htmlspecialchars($q).'" placeholder="'.$heroSearchPlaceholderHtml.'">';
    echo '          <button class="px-5 py-3 rounded-xl bg-white text-brand-700 font-semibold hover:bg-brand-50 cta-button transition"><i class="fa-solid fa-search mr-2"></i>'.$heroSearchButtonHtml.'</button>';
    echo '        </form>';
    echo '        <div class="flex flex-wrap items-center gap-4 text-white/80 text-sm hero-support">';
    echo '          <span class="flex items-center gap-2"><i class="fa-solid fa-clock text-white"></i> '.$heroSupportLeftHtml.'</span>';
    echo '          <span class="flex items-center gap-2"><i class="fa-solid fa-medal text-white"></i> '.$heroSupportRightHtml.'</span>';
    echo '        </div>';
    echo '      </div>';
    echo '      <div class="grid sm:grid-cols-2 gap-4">';
    foreach ($heroHighlights as $feature) {
      $title = htmlspecialchars($feature['title'], ENT_QUOTES, 'UTF-8');
      $desc  = htmlspecialchars($feature['desc'], ENT_QUOTES, 'UTF-8');
      $icon  = htmlspecialchars($feature['icon'], ENT_QUOTES, 'UTF-8');
      echo '        <div class="rounded-2xl border border-white/15 bg-white/10 p-5 shadow-lg backdrop-blur flex flex-col gap-2 hero-feature">';
      echo '          <span class="w-10 h-10 rounded-full bg-white/20 text-white grid place-items-center text-lg"><i class="fa-solid '.$icon.'"></i></span>';
      echo '          <div class="font-semibold">'.$title.'</div>';
      echo '          <p class="text-sm text-white/80 leading-relaxed">'.$desc.'</p>';
      echo '        </div>';
    }
    echo '      </div>';
    echo '    </div>';
    echo '  </div>';
    echo '</section>';
  }

  // Filtros de categoria (chips)
  echo '<section class="max-w-7xl mx-auto px-4">';
  echo '  <div class="flex items-center gap-3 flex-wrap mb-6">';
  echo '    <button onclick="window.location.href=\'?route=home\'" class="chip px-4 py-2 rounded-full '.($category_id===0?'bg-brand-600 text-white border-brand-600':'bg-white').'">Todas</button>';
  foreach ($categories as $cat) {
    $active = ($category_id === (int)$cat['id']);
    echo '    <button onclick="window.location.href=\'?route=home&category='.(int)$cat['id'].'\'" class="chip px-4 py-2 rounded-full '.($active?'bg-brand-600 text-white border-brand-600':'bg-white').'">'.htmlspecialchars($cat['name']).'</button>';
  }
  echo '  </div>';
  echo '</section>';

  // Sessão de destaque dinâmica (produtos marcados como "Destaque")
  $featuredProducts = [];
  $featuredIds = [];
  if ($featuredEnabled && $q === '' && $category_id === 0) {
    try {
      $featuredStmt = $pdo->prepare("SELECT p.*, c.name AS category_name, pd.short_description FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN product_details pd ON pd.product_id = p.id WHERE p.active = 1 AND p.featured = 1 ORDER BY p.updated_at DESC, p.id DESC LIMIT 8");
      $featuredStmt->execute();
      $featuredProducts = $featuredStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      $featuredProducts = [];
    }
    if ($featuredProducts) {
      $featuredIds = array_map('intval', array_column($featuredProducts, 'id'));
    } else {
      $featuredEnabled = false;
    }
  }

  if ($featuredEnabled && $featuredProducts) {
    echo '<section class="relative overflow-hidden py-12">';
    echo '  <div class="absolute inset-0 bg-gradient-to-r from-[#0f3d91] via-[#1f54c1] to-[#3a7bff] opacity-95"></div>';
    echo '  <div class="relative max-w-7xl mx-auto px-4 text-white space-y-6 text-center">';
    echo '    <div class="space-y-3">';
    echo '      <span class="inline-flex items-center justify-center gap-2 text-xs uppercase tracking-[0.35em] text-white/70"><i class="fa-solid fa-bolt"></i> '.$featuredLabelHtml.'</span>';
    echo '      <h1 class="text-4xl md:text-5xl font-bold">'.$featuredBadgeTitleHtml.'</h1>';
    if ($featuredBadgeTextHtml !== '') {
      echo '      <p class="text-white/80 text-base md:text-lg max-w-2xl mx-auto">'.$featuredBadgeTextHtml.'</p>';
    }
    echo '      <h2 class="text-2xl md:text-3xl font-semibold">'.$featuredTitleHtml.'</h2>';
    if ($featuredSubtitleHtml !== '') {
      echo '      <p class="text-white/80 text-base max-w-2xl mx-auto">'.$featuredSubtitleHtml.'</p>';
    }
    echo '    </div>';
    echo '    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">';
    foreach ($featuredProducts as $fp) {
      $img = $fp['image_path'] ?: 'assets/no-image.png';
      $img = proxy_img($img);
      $nameHtml = htmlspecialchars($fp['name'], ENT_QUOTES, 'UTF-8');
      $short = $fp['short_description'] ?? $fp['description'] ?? '';
      $descHtml = htmlspecialchars(mb_substr(strip_tags($short), 0, 120), ENT_QUOTES, 'UTF-8');
      $categoryHtml = $fp['category_name'] ? htmlspecialchars($fp['category_name'], ENT_QUOTES, 'UTF-8') : 'Sem categoria';
      $priceValue = (float)($fp['price'] ?? 0);
      $productCurrency = strtoupper($fp['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
      $priceFormatted = format_currency($priceValue, $productCurrency);
      $compareValue = isset($fp['price_compare']) ? (float)$fp['price_compare'] : null;
      $compareFormatted = ($compareValue && $compareValue > $priceValue) ? format_currency($compareValue, $productCurrency) : '';
      $discountBadge = '';
      if ($compareValue && $compareValue > $priceValue && $compareValue > 0) {
        $savingPercent = max(1, min(90, (int)round((($compareValue - $priceValue) / $compareValue) * 100)));
        $discountBadge = '<span class="absolute top-3 right-3 bg-amber-400 text-white text-xs px-3 py-1 rounded-full">'.$savingPercent.'% OFF</span>';
      }
      $productUrl = $fp['slug'] ? ('?route=product&slug='.urlencode($fp['slug'])) : ('?route=product&id='.(int)$fp['id']);
      $inStock = ((int)($fp['stock'] ?? 0) > 0);
      echo '      <div class="bg-white/10 border border-white/20 rounded-3xl p-5 backdrop-blur-lg hover:border-white/50 transition-shadow shadow-lg flex flex-col h-full">';
      echo '        <a href="'.$productUrl.'" class="block relative rounded-2xl overflow-hidden mb-4 bg-white">';
      echo '          <img src="'.htmlspecialchars($img, ENT_QUOTES, 'UTF-8').'" alt="'.$nameHtml.'" class="w-full h-44 object-cover transition-transform duration-700 hover:scale-105">';
      if (!empty($fp['category_name'])) {
        echo '          <span class="absolute top-3 left-3 bg-brand-600 text-white text-xs px-3 py-1 rounded-full">'.$categoryHtml.'</span>';
      }
      echo            $discountBadge;
      echo '        </a>';
      echo '        <div class="space-y-2 text-left flex-1">';
      echo '          <a href="'.$productUrl.'" class="text-lg font-semibold leading-tight hover:underline">'.$nameHtml.'</a>';
      echo '          <p class="text-sm text-white/80 line-clamp-3">'.$descHtml.'</p>';
      echo '          <div class="flex items-baseline gap-3">';
      if ($compareFormatted) {
        echo '            <span class="text-sm text-white/70 line-through">'.$compareFormatted.'</span>';
      }
      echo '            <span class="text-2xl font-bold text-white">'.$priceFormatted.'</span>';
      echo '          </div>';
      echo '        </div>';
      echo '        <div class="pt-4">';
      if ($inStock) {
        echo '          <button class="w-full px-4 py-3 rounded-xl bg-white text-brand-700 font-semibold shadow hover:bg-brand-50 transition" onclick="addToCart('.(int)$fp['id'].', \''.$nameHtml.'\', 1)"><i class="fa-solid fa-cart-plus mr-2"></i>Adicionar ao carrinho</button>';
      } else {
        echo '          <button class="w-full px-4 py-3 rounded-xl bg-white/30 text-white/70 font-semibold cursor-not-allowed"><i class="fa-solid fa-circle-exclamation mr-2"></i>Indisponível</button>';
      }
      echo '        </div>';
      echo '      </div>';
    }
    echo '    </div>';
    echo '  </div>';
    echo '</section>';
  }

// Busca produtos
  $where = ["p.active=1"];
  $params = [];
  if ($q !== '') {
    $where[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.description LIKE ? )";
    $like = "%$q%"; $params[]=$like; $params[]=$like; $params[]=$like;
  }
  if ($category_id > 0) {
    $where[] = "p.category_id = ?"; $params[] = $category_id;
  }
  if ($featuredIds) {
    $placeholders = implode(',', array_fill(0, count($featuredIds), '?'));
    $where[] = "p.id NOT IN ($placeholders)";
    $params = array_merge($params, $featuredIds);
  }
  $whereSql = 'WHERE '.implode(' AND ', $where);

  $sql = "SELECT p.*, c.name AS category_name, pd.short_description
          FROM products p
          LEFT JOIN categories c ON c.id = p.category_id
          LEFT JOIN product_details pd ON pd.product_id = p.id
          $whereSql
          ORDER BY p.featured DESC, p.created_at DESC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $products = $stmt->fetchAll();

  echo '<section class="max-w-7xl mx-auto px-4 pb-12">';
  if ($q || $category_id) {
    echo '<div class="mb-4 text-sm text-gray-600">'.count($products).' resultado(s)';
    if ($q) echo ' • busca: <span class="font-medium text-brand-700">'.htmlspecialchars($q).'</span>';
    echo '</div>';
  }

  if (!$products) {
    echo '<div class="text-center py-16">';
    echo '  <i class="fa-solid fa-magnifying-glass text-5xl text-gray-300 mb-4"></i>';
    echo '  <div class="text-lg text-gray-600">Nenhum produto encontrado</div>';
    echo '  <a href="?route=home" class="inline-block mt-6 px-6 py-3 rounded-lg bg-brand-600 text-white hover:bg-brand-700">Voltar</a>';
    echo '</div>';
  } else {
    echo '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mobile-grid-products">';
    foreach ($products as $p) {
      $img = $p['image_path'] ?: 'assets/no-image.png';
      $img = proxy_img($img); // passa pelo proxy se for URL absoluta
      $productUrl = !empty($p['slug']) ? ('?route=product&slug='.urlencode($p['slug'])) : ('?route=product&id='.(int)$p['id']);
      $in_stock = ((int)$p['stock'] > 0);
      $priceValue = (float)($p['price'] ?? 0);
      $productCurrency = strtoupper($p['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
      $priceFormatted = format_currency($priceValue, $productCurrency);
      $compareValue = isset($p['price_compare']) ? (float)$p['price_compare'] : null;
      $compareFormatted = ($compareValue && $compareValue > $priceValue) ? format_currency($compareValue, $productCurrency) : '';
      $short = $p['short_description'] ?? $p['description'] ?? '';
      $shortText = htmlspecialchars(mb_substr(strip_tags($short), 0, 140), ENT_QUOTES, 'UTF-8');
      echo '<div class="product-card card rounded-2xl shadow hover:shadow-lg transition overflow-hidden flex flex-col">';
      echo '  <a href="'.$productUrl.'" class="relative h-48 overflow-hidden block bg-gray-50">';
      echo '    <img src="'.htmlspecialchars($img).'" class="w-full h-full object-cover transition-transform duration-300 hover:scale-105" alt="'.htmlspecialchars($p['name']).'">';
      if (!empty($p['category_name'])) {
        echo '    <div class="absolute top-3 right-3 text-xs bg-white/90 rounded-full px-2 py-1 text-brand-700">'.htmlspecialchars($p['category_name']).'</div>';
      }
      if (!empty($p['featured'])) {
        echo '    <div class="absolute top-3 left-3 text-[10px] bg-amber-400 text-white rounded-full px-2 py-1 font-bold">DESTAQUE</div>';
      }
      if (!empty($p['badge_one_month'])) {
        echo '    <div class="product-ribbon">Tratamento para 1 mês</div>';
      }
      echo '  </a>';
      echo '  <div class="p-4 space-y-2 flex-1 flex flex-col">';
      echo '    <div class="text-sm text-gray-500">SKU: '.htmlspecialchars($p['sku']).'</div>';
      echo '    <a href="'.$productUrl.'" class="font-semibold text-lg text-gray-900 hover:text-brand-600">'.htmlspecialchars($p['name']).'</a>';
      echo '    <p class="text-sm text-gray-600 line-clamp-3">'.$shortText.'</p>';
      echo '    <div class="flex items-center justify-between pt-2 mt-auto">';
      if ($compareFormatted) {
        echo '      <div class="flex flex-col leading-tight">';
        echo '        <span class="text-xs text-gray-400 line-through">De '.$compareFormatted.'</span>';
        echo '        <span class="text-2xl font-bold text-gray-900">Por '.$priceFormatted.'</span>';
        echo '      </div>';
      } else {
        echo '      <div class="text-2xl font-bold text-gray-900">'.$priceFormatted.'</div>';
      }
      echo '      <div class="text-xs '.($in_stock?'text-green-600':'text-red-600').'">'.($in_stock?'Em estoque':'Indisponível').'</div>';
      echo '    </div>';
      echo '    <div class="grid grid-cols-1 gap-2 pt-2">';
      echo '      <a href="'.$productUrl.'" class="px-3 py-2 rounded-lg border text-center text-sm hover:border-brand-600 hover:text-brand-600 transition"><i class="fa-solid fa-eye mr-1"></i>Ver detalhes</a>';
      if ($in_stock) {
        echo '      <button class="px-3 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 transition" onclick="addToCart('.(int)$p['id'].', \''.htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8').'\', 1)"><i class="fa-solid fa-cart-plus mr-1"></i>Adicionar</button>';
      } else {
        echo '      <button class="px-3 py-2 rounded-lg bg-gray-300 text-gray-600 text-sm font-semibold cursor-not-allowed"><i class="fa-solid fa-ban mr-1"></i>Indisponível</button>';
      }
      echo '    </div>';
      echo '  </div>';
      echo '</div>';
    }
    echo '</div>';
  }

  echo '</section>';
  app_footer();
  exit;
}

// ACCOUNT AREA
if ($route === 'account') {
  app_header();
  $pdo = db();
  $errorMsg = null;

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
    if (!empty($_POST['logout'])) {
      unset($_SESSION['account_portal_email']);
      header('Location: ?route=account');
      exit;
    }
    $emailInput = strtolower(trim((string)($_POST['email'] ?? '')));
    $orderIdInput = (int)($_POST['order_id'] ?? 0);
    if (!validate_email($emailInput) || $orderIdInput <= 0) {
      $errorMsg = 'Informe um e-mail válido e o número do pedido.';
    } else {
      $verify = $pdo->prepare("SELECT COUNT(*) FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE o.id = ? AND LOWER(c.email) = ?");
      $verify->execute([$orderIdInput, $emailInput]);
      if ($verify->fetchColumn()) {
        $_SESSION['account_portal_email'] = $emailInput;
        header('Location: ?route=account');
        exit;
      } else {
        $errorMsg = 'Não encontramos nenhum pedido para esses dados. Verifique o número e o e-mail utilizados na compra.';
      }
    }
  }

  $accountEmail = $_SESSION['account_portal_email'] ?? null;
  $ordersList = [];
  if ($accountEmail) {
    $ordersStmt = $pdo->prepare("SELECT o.*, c.name AS customer_name, c.email AS customer_email FROM orders o INNER JOIN customers c ON c.id = o.customer_id WHERE LOWER(c.email) = ? ORDER BY o.created_at DESC");
    $ordersStmt->execute([$accountEmail]);
    $ordersList = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
  }

  $statusMap = [
    'pending' => ['Pendente', 'bg-amber-100 text-amber-700'],
    'paid' => ['Pago', 'bg-emerald-100 text-emerald-700'],
    'processing' => ['Em processamento', 'bg-blue-100 text-blue-700'],
    'shipped' => ['Enviado', 'bg-sky-100 text-sky-700'],
    'completed' => ['Concluído', 'bg-emerald-100 text-emerald-700'],
    'cancelled' => ['Cancelado', 'bg-red-100 text-red-700']
  ];

  echo '<section class="max-w-5xl mx-auto px-4 py-10">';
  echo '  <div class="bg-white rounded-3xl shadow-lg p-6 md:p-8 space-y-6">';
  echo '    <div class="flex items-start justify-between gap-4 flex-wrap">';
  echo '      <div>';
  echo '        <h2 class="text-2xl font-bold">Minha conta</h2>';
  echo '        <p class="text-sm text-gray-500">Consulte seus pedidos utilizando o e-mail cadastrado e o número do pedido.</p>';
  echo '      </div>';
  if ($accountEmail) {
    echo '      <form method="post" class="flex items-center gap-2">';
    echo '        <input type="hidden" name="csrf" value="'.csrf_token().'">';
    echo '        <input type="hidden" name="logout" value="1">';
    echo '        <span class="text-sm text-gray-600 hidden sm:inline">Logado como <strong>'.htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8').'</strong></span>';
    echo '        <button type="submit" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 text-sm"><i class="fa-solid fa-right-from-bracket mr-1"></i>Sair</button>';
    echo '      </form>';
  }
  echo '    </div>';

  if ($errorMsg) {
    echo '    <div class="px-4 py-3 rounded-xl bg-red-50 text-red-700 flex items-center gap-2"><i class="fa-solid fa-circle-exclamation"></i><span>'.htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8').'</span></div>';
  }

  if (!$accountEmail) {
    echo '    <form method="post" class="grid md:grid-cols-2 gap-4">';
    echo '      <input type="hidden" name="csrf" value="'.csrf_token().'">';
    echo '      <div class="md:col-span-2">';
    echo '        <label class="block text-sm font-medium mb-1">E-mail utilizado na compra</label>';
    echo '        <input class="input w-full" type="email" name="email" required placeholder="ex.: cliente@exemplo.com">';
    echo '      </div>';
    echo '      <div>';
    echo '        <label class="block text-sm font-medium mb-1">Número do pedido</label>';
    echo '        <input class="input w-full" type="number" name="order_id" min="1" required placeholder="ex.: 1024">';
    echo '      </div>';
    echo '      <div class="md:col-span-2 flex justify-end">';
    echo '        <button type="submit" class="btn btn-primary px-5 py-2"><i class="fa-solid fa-magnifying-glass mr-2"></i>Consultar pedidos</button>';
    echo '      </div>';
    echo '    </form>';
  } else {
    if (!$ordersList) {
      echo '    <div class="px-4 py-6 rounded-xl bg-gray-50 text-center text-gray-600">';
      echo '      <i class="fa-solid fa-box-open text-3xl mb-3"></i>';
      echo '      <p>Nenhum pedido encontrado para o e-mail informado.</p>';
      echo '    </div>';
    } else {
      foreach ($ordersList as $order) {
        $created = format_datetime($order['created_at'] ?? '');
        $statusKey = strtolower((string)($order['status'] ?? ''));
        $statusInfo = $statusMap[$statusKey] ?? [ucfirst($statusKey ?: 'Desconhecido'), 'bg-gray-100 text-gray-600'];
        $items = json_decode($order['items_json'] ?? '[]', true);
        if (!is_array($items)) { $items = []; }
        $orderCurrency = strtoupper($order['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
        $total = format_currency((float)($order['total'] ?? 0), $orderCurrency);
        $shippingCost = format_currency((float)($order['shipping_cost'] ?? 0), $orderCurrency);
        $subtotal = format_currency((float)($order['subtotal'] ?? 0), $orderCurrency);
        $track = trim((string)($order['track_token'] ?? ''));

        echo '    <div class="border border-gray-100 rounded-2xl p-5 space-y-4">';
        echo '      <div class="flex items-start justify-between gap-3 flex-wrap">';
        echo '        <div>';
        echo '          <div class="text-lg font-semibold">Pedido #'.(int)$order['id'].'</div>';
        echo '          <div class="text-xs text-gray-500">'.$created.'</div>';
        echo '        </div>';
        echo '        <span class="px-3 py-1 rounded-full text-xs font-semibold '.$statusInfo[1].'">'.$statusInfo[0].'</span>';
        echo '      </div>';

        if ($items) {
          echo '      <div class="space-y-2">';
          foreach ($items as $item) {
            $itemName = htmlspecialchars((string)($item['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $itemQty = (int)($item['qty'] ?? 0);
            $itemPrice = format_currency((float)($item['price'] ?? 0), $item['currency'] ?? $orderCurrency);
            echo '        <div class="flex items-center justify-between text-sm border-b border-dotted pb-1">';
            echo '          <span>'.$itemName.' <span class="text-gray-500">(Qtd: '.$itemQty.')</span></span>';
            echo '          <span>'.$itemPrice.'</span>';
            echo '        </div>';
          }
          echo '      </div>';
        }

        echo '      <div class="grid md:grid-cols-3 gap-3 text-sm bg-gray-50 rounded-xl p-4">';
        echo '        <div><span class="text-gray-500 block text-xs uppercase tracking-wide">Subtotal</span><strong>'.$subtotal.'</strong></div>';
        echo '        <div><span class="text-gray-500 block text-xs uppercase tracking-wide">Frete</span><strong>'.$shippingCost.'</strong></div>';
        echo '        <div><span class="text-gray-500 block text-xs uppercase tracking-wide">Total</span><strong class="text-brand-700">'.$total.'</strong></div>';
        echo '      </div>';

        echo '      <div class="flex items-center justify-between gap-3 text-sm flex-wrap">';
        echo '        <div><strong>Pagamento:</strong> '.htmlspecialchars((string)($order['payment_method'] ?? '-'), ENT_QUOTES, 'UTF-8').'</div>';
        if ($track !== '') {
          $trackSafe = htmlspecialchars($track, ENT_QUOTES, 'UTF-8');
          echo '        <a class="text-brand-700 hover:underline flex items-center gap-1" href="?route=track&code='.$trackSafe.'"><i class="fa-solid fa-location-dot"></i> Acompanhar pedido</a>';
        }
        echo '      </div>';
        echo '    </div>';
      }
    }
  }

  echo '  </div>';
  echo '</section>';
  app_footer();
  exit;
}

// ADD TO CART (AJAX)
if ($route === 'add_cart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');

  // Aceita CSRF do body ou do header
  $csrfIncoming = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!csrf_check($csrfIncoming)) {
    echo json_encode(['success'=>false, 'error'=>'CSRF inválido']); exit;
  }

  $pdo = db();
  $id  = (int)($_POST['id'] ?? 0);
  $qtyRequested = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;
  if ($qtyRequested < 1) {
    $qtyRequested = 1;
  }

  $st = $pdo->prepare("SELECT id, name, stock, active, currency FROM products WHERE id=? AND active=1");
  $st->execute([$id]);
  $prod = $st->fetch();
  if (!$prod) { echo json_encode(['success'=>false,'error'=>'Produto não encontrado']); exit; }
  if ((int)$prod['stock'] <= 0) { echo json_encode(['success'=>false,'error'=>'Produto fora de estoque']); exit; }

  $productCurrency = strtoupper($prod['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
  $cartCurrency = $_SESSION['cart_currency'] ?? null;
  if ($cartCurrency === null) {
    $_SESSION['cart_currency'] = $productCurrency;
  } elseif ($cartCurrency !== $productCurrency) {
    echo json_encode(['success'=>false,'error'=>'Carrinho aceita apenas produtos na moeda '.$cartCurrency.'. Remova itens anteriores para adicionar este.']);
    exit;
  }

  if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
  $currentQty = (int)($_SESSION['cart'][$id] ?? 0);
  $newQty = $currentQty + $qtyRequested;
  $stock = (int)$prod['stock'];
  $limited = false;
  if ($stock > 0 && $newQty > $stock) {
    $newQty = $stock;
    $limited = true;
  }
  $_SESSION['cart'][$id] = $newQty;

  // notificação (opcional)
  send_notification('cart_add','Produto ao carrinho', $prod['name'], ['product_id'=>$id]);

  $cartCount = array_sum($_SESSION['cart']);
  $message = $limited
    ? 'Adicionamos a quantidade máxima disponível em estoque.'
    : 'Produto adicionado ao carrinho!';

  echo json_encode([
    'success'=>true,
    'cart_count'=> $cartCount,
    'message' => $message
  ]);
  exit;
}

// CART
if ($route === 'cart') {
  app_header();
  $pdo = db();
  $cart = $_SESSION['cart'] ?? [];
  $ids  = array_keys($cart);
  $items = [];
  $subtotal = 0.0;
  $shippingTotal = 0.0;
  $cartCurrency = $_SESSION['cart_currency'] ?? null;
  $currencyMismatch = false;

  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM products WHERE id IN ($in) AND active=1");
    $st->execute($ids);
    foreach ($st as $p) {
      $pid = (int)$p['id'];
      if (!isset($cart[$pid])) { continue; }
      $qty = (int)$cart[$pid];
      if ($qty <= 0) { continue; }
      $productCurrency = strtoupper($p['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
      if ($cartCurrency === null) {
        $cartCurrency = $productCurrency;
        $_SESSION['cart_currency'] = $cartCurrency;
      } elseif ($cartCurrency !== $productCurrency) {
        $currencyMismatch = true;
      }
      $priceValue = (float)$p['price'];
      $line = $priceValue * $qty;
      $subtotal += $line;
      $ship = isset($p['shipping_cost']) ? (float)$p['shipping_cost'] : 7.00;
      if ($ship < 0) { $ship = 0; }
      $shippingTotal += $ship * $qty;
      $items[] = [
        'id'=>$pid,
        'sku'=>$p['sku'],
        'name'=>$p['name'],
        'price'=>$priceValue,
        'qty'=>$qty,
        'image'=>$p['image_path'],
        'stock'=>(int)$p['stock'],
        'shipping_cost'=>$ship,
        'currency'=>$productCurrency
      ];
    }
  }

  $shippingTotal = max(0, $shippingTotal);
  $cartTotal = $subtotal + $shippingTotal;
  if ($cartCurrency === null) {
    $cartCurrency = $cfg['store']['currency'] ?? 'USD';
  }

  echo '<section class="max-w-5xl mx-auto px-4 py-8">';
  echo '  <h2 class="text-2xl font-bold mb-6"><i class="fa-solid fa-bag-shopping mr-2 text-brand-700"></i>'.htmlspecialchars($d['cart'] ?? 'Carrinho').'</h2>';
  if ($currencyMismatch) {
    echo '  <div class="mb-4 p-4 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 flex items-start gap-3"><i class="fa-solid fa-circle-exclamation mt-1"></i><span>Há produtos com moedas diferentes no carrinho. Ajuste os itens para uma única moeda antes de finalizar.</span></div>';
  }

  if (!$items) {
    unset($_SESSION['cart_currency']);
    echo '<div class="text-center py-16">';
    echo '  <i class="fa-solid fa-cart-shopping text-6xl text-gray-300 mb-4"></i>';
    echo '  <div class="text-gray-600 mb-6">Seu carrinho está vazio</div>';
    echo '  <a href="?route=home" class="px-6 py-3 rounded-lg bg-brand-600 text-white hover:bg-brand-700">Continuar comprando</a>';
    echo '</div>';
  } else {
    echo '<div class="bg-white rounded-2xl shadow overflow-hidden">';
    echo '  <div class="divide-y">';
    foreach ($items as $it) {
      $img = $it['image'] ?: 'assets/no-image.png';
      $img = proxy_img($img); // passa pelo proxy se for URL absoluta
      echo '  <div class="p-4 flex flex-col gap-4 md:flex-row md:items-center">';
      echo '    <div class="flex-shrink-0 mx-auto md:mx-0">';
      echo '      <img src="'.htmlspecialchars($img).'" class="w-24 h-24 md:w-20 md:h-20 object-cover rounded-lg" alt="produto">';
      echo '    </div>';
      echo '    <div class="flex-1 text-center md:text-left">';
      echo '      <div class="font-semibold">'.htmlspecialchars($it['name']).'</div>';
      echo '      <div class="text-xs text-gray-500 mt-1">SKU: '.htmlspecialchars($it['sku']).'</div>';
      $itemCurrency = $it['currency'] ?? $cartCurrency;
      echo '      <div class="text-brand-700 font-bold mt-2">'.format_currency($it['price'], $itemCurrency).'</div>';
      echo '    </div>';
      echo '    <div class="flex items-center justify-center md:justify-start gap-2">';
      echo '      <button class="w-8 h-8 rounded-full bg-gray-200" onclick="updateQuantity('.(int)$it['id'].', -1)">-</button>';
      echo '      <span class="w-12 text-center font-semibold">'.(int)$it['qty'].'</span>';
      echo '      <button class="w-8 h-8 rounded-full bg-gray-200" onclick="updateQuantity('.(int)$it['id'].', 1)">+</button>';
      echo '    </div>';
      echo '    <div class="font-semibold text-lg md:text-right md:w-32 text-center">'.format_currency($it['price']*$it['qty'], $itemCurrency).'</div>';
      echo '    <a class="text-red-500 text-sm text-center md:text-left" href="?route=remove_cart&id='.(int)$it['id'].'&csrf='.csrf_token().'">Remover</a>';
      echo '  </div>';
    }
    echo '  </div>';
    echo '  <div class="p-4 bg-gray-50 space-y-2">';
    echo '    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">';
    echo '      <span class="text-lg font-semibold text-center sm:text-left">Subtotal</span>';
    echo '      <span class="text-2xl font-bold text-brand-700 text-center sm:text-right">'.format_currency($subtotal, $cartCurrency).'</span>';
    echo '    </div>';
    echo '    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between text-sm text-gray-600 gap-2">';
    echo '      <span class="text-center sm:text-left">Frete</span>';
    echo '      <span class="font-semibold text-center sm:text-right">'.format_currency($shippingTotal, $cartCurrency).'</span>';
    echo '    </div>';
    echo '    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between text-lg font-bold gap-2">';
    echo '      <span class="text-center sm:text-left">Total</span>';
    echo '      <span class="text-brand-700 text-2xl text-center sm:text-right">'.format_currency($cartTotal, $cartCurrency).'</span>';
    echo '    </div>';
    echo '  </div>';
    echo '  <div class="p-4 flex flex-col sm:flex-row gap-3">';
    echo '    <a href="?route=home" class="px-5 py-3 rounded-lg border text-center sm:w-auto">Continuar comprando</a>';
    echo '    <a href="?route=checkout" class="flex-1 px-5 py-3 rounded-lg bg-brand-600 text-white text-center hover:bg-brand-700">'.htmlspecialchars($d["checkout"] ?? "Finalizar Compra").'</a>';
    echo '  </div>';
    echo '</div>';
  }

  echo '</section>';
  app_footer();
  exit;
}

// REMOVE FROM CART
if ($route === 'remove_cart') {
  if (!csrf_check($_GET['csrf'] ?? '')) die('CSRF inválido');
  $id = (int)($_GET['id'] ?? 0);
  if ($id > 0 && isset($_SESSION['cart'][$id])) unset($_SESSION['cart'][$id]);
  if (empty($_SESSION['cart'])) {
    unset($_SESSION['cart_currency']);
  }
  header('Location: ?route=cart');
  exit;
}

// UPDATE CART (AJAX)
if ($route === 'update_cart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  $csrfIncoming = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!csrf_check($csrfIncoming)) { echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }
  $id = (int)($_POST['id'] ?? 0);
  $delta = (int)($_POST['delta'] ?? 0);
  if ($id <= 0 || $delta === 0) { echo json_encode(['ok'=>false]); exit; }
  $cart = $_SESSION['cart'] ?? [];
  $new = max(0, (int)($cart[$id] ?? 0) + $delta);
  if ($new === 0) { unset($cart[$id]); }
  else {
    $pdo = db();
    $st = $pdo->prepare("SELECT stock, currency FROM products WHERE id=? AND active=1");
    $st->execute([$id]);
    $prodRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$prodRow) {
      echo json_encode(['ok'=>false,'error'=>'Produto indisponível']); exit;
    }
    $productCurrency = strtoupper($prodRow['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
    $cartCurrency = $_SESSION['cart_currency'] ?? null;
    if ($cartCurrency === null) {
      $_SESSION['cart_currency'] = $productCurrency;
    } elseif ($cartCurrency !== $productCurrency) {
      echo json_encode(['ok'=>false,'error'=>'Carrinho aceita apenas produtos na moeda '.$cartCurrency.'.']); exit;
    }
    $stock = (int)($prodRow['stock'] ?? 0);
    if ($stock > 0) $new = min($new, $stock);
    $cart[$id] = $new;
  }
  $_SESSION['cart'] = $cart;
  if (empty($_SESSION['cart'])) {
    unset($_SESSION['cart_currency']);
  }
  echo json_encode(['ok'=>true,'qty'=>($cart[$id] ?? 0)]); exit;
}

// CHECKOUT
if ($route === 'checkout') {
  $cart = $_SESSION['cart'] ?? [];
  if (empty($cart)) { header('Location: ?route=cart'); exit; }

  app_header();
  $checkoutError = $_SESSION['checkout_error'] ?? null;
  unset($_SESSION['checkout_error']);

  $pdo = db();
  $ids = array_keys($cart);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $st  = $pdo->prepare("SELECT id, name, price, stock, shipping_cost, currency, square_payment_link, stripe_payment_link, paypal_payment_link FROM products WHERE id IN ($in) AND active=1");
  $st->execute($ids);
  $items = []; $subtotal = 0.0; $shipping = 0.0;
  $cartCurrency = $_SESSION['cart_currency'] ?? null;
  $currencyMismatch = false;
  foreach ($st as $p) {
    $pid = (int)$p['id'];
    if (!isset($cart[$pid])) { continue; }
    $qty = (int)$cart[$pid];
    if ($qty <= 0) { continue; }
    $productCurrency = strtoupper($p['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
    if ($cartCurrency === null) {
      $cartCurrency = $productCurrency;
    } elseif ($cartCurrency !== $productCurrency) {
      $currencyMismatch = true;
    }
    $shipCost = isset($p['shipping_cost']) ? (float)$p['shipping_cost'] : 7.00;
    if ($shipCost < 0) { $shipCost = 0; }
    $priceValue = (float)$p['price'];
    $items[] = [
      'id'=>$pid,
      'name'=>$p['name'],
      'price'=>$priceValue,
      'qty'=>$qty,
      'shipping_cost'=>$shipCost,
      'currency'=>$productCurrency,
      'square_link'=> trim((string)($p['square_payment_link'] ?? '')),
      'stripe_link'=> trim((string)($p['stripe_payment_link'] ?? '')),
      'paypal_link'=> trim((string)($p['paypal_payment_link'] ?? ''))
    ];
    $subtotal += $priceValue * $qty;
    $shipping += $shipCost * $qty;
  }
  $shipping = max(0, $shipping);
  $total = $subtotal + $shipping;
  $secureDeliveryPriceSetting = (float)setting_get('checkout_secure_delivery_price', 0);
  $secureDeliveryPriceSetting = $secureDeliveryPriceSetting < 0 ? 0 : round($secureDeliveryPriceSetting, 2);
  $totalWithSecure = $total + $secureDeliveryPriceSetting;
  if ($cartCurrency === null) {
    $cartCurrency = $cfg['store']['currency'] ?? 'USD';
  }
  if ($currencyMismatch) {
    $_SESSION['checkout_error'] = 'Carrinho contém produtos em moedas diferentes. Ajuste os itens antes de finalizar.';
    header('Location: ?route=cart');
    exit;
  }

  $_SESSION['cart_currency'] = $cartCurrency;
  $currencyCode = $cartCurrency;

  // Métodos de pagamento dinâmicos
  $paymentMethods = load_payment_methods($pdo, $cfg);
  $codesExisting = [];
  foreach ($paymentMethods as $pm) {
    $codesExisting[$pm['code']] = true;
  }
  $hasPaypalLinks = !empty(array_filter($items, function($it){ return trim((string)($it['paypal_link'] ?? '')) !== ''; }));
  $hasStripeLinks = !empty(array_filter($items, function($it){ return trim((string)($it['stripe_link'] ?? '')) !== ''; }));
  if ($hasPaypalLinks && !isset($codesExisting['paypal'])) {
    $paymentMethods[] = [
      'code' => 'paypal',
      'name' => 'PayPal',
      'description' => '',
      'instructions' => 'Após finalizar, abriremos o checkout PayPal.',
      'settings' => [
        'type' => 'paypal',
        'mode' => 'paypal_product_link',
        'open_new_tab' => false
      ],
      'icon_path' => null,
      'require_receipt' => 0
    ];
  }
  if ($hasStripeLinks && !isset($codesExisting['stripe'])) {
    $paymentMethods[] = [
      'code' => 'stripe',
      'name' => 'Stripe',
      'description' => '',
      'instructions' => 'Após finalizar, abriremos o checkout Stripe.',
      'settings' => [
        'type' => 'stripe',
        'mode' => 'stripe_product_link',
        'open_new_tab' => false
      ],
      'icon_path' => null,
      'require_receipt' => 0
    ];
  }
  $paymentMethods = array_values(array_filter($paymentMethods, function($pm) use ($items) {
    $settings = $pm['settings'] ?? [];
    $payType = $settings['type'] ?? $pm['code'];
    $mode = $settings['mode'] ?? null;
    $hasRedirect = trim((string)($settings['redirect_url'] ?? '')) !== '';
    $business = trim((string)($settings['business'] ?? '')) !== '';

    switch ($payType) {
      case 'square':
        $mode = $mode ?: 'square_product_link';
        if ($mode === 'direct_url') {
          return $hasRedirect;
        }
        return !array_filter($items, function($it){ return trim((string)($it['square_link'] ?? '')) === ''; });
      case 'stripe':
        $mode = $mode ?: 'stripe_product_link';
        if ($mode === 'direct_url') {
          return $hasRedirect;
        }
        return !array_filter($items, function($it){ return trim((string)($it['stripe_link'] ?? '')) === ''; });
      case 'paypal':
        $mode = $mode ?: 'standard';
        if ($mode === 'direct_url') {
          return $hasRedirect;
        }
        if ($mode === 'paypal_product_link') {
          return !array_filter($items, function($it){ return trim((string)($it['paypal_link'] ?? '')) === ''; });
        }
        return $business; // standard precisa de conta
      default:
        return true;
    }
  }));
  $countryOptions = checkout_get_countries();
  $defaultCountryOption = setting_get('checkout_default_country', $countryOptions[0]['code'] ?? 'US');
  $defaultCountryOption = strtoupper(trim((string)$defaultCountryOption));
  $countryCodes = array_map(function ($c) { return strtoupper($c['code']); }, $countryOptions);
  if (!in_array($defaultCountryOption, $countryCodes, true) && $countryOptions) {
    $defaultCountryOption = strtoupper($countryOptions[0]['code']);
  }
  $stateGroups = checkout_group_states();
  $initialStates = checkout_get_states_by_country($defaultCountryOption);
  $deliveryMethods = checkout_get_delivery_methods();
  $checkoutBrandLogos = [
    ['label' => 'Visa', 'path' => 'assets/payment-logos/visa.svg'],
    ['label' => 'Mastercard', 'path' => 'assets/payment-logos/mastercard.svg'],
    ['label' => 'American Express', 'path' => 'assets/payment-logos/american-express.svg'],
    ['label' => 'Discover', 'path' => 'assets/payment-logos/discover.svg'],
    ['label' => 'Afterpay', 'path' => 'assets/payment-logos/afterpay.svg'],
    ['label' => 'Klarna', 'path' => 'assets/payment-logos/klarna.svg'],
    ['label' => 'Bitcoin', 'path' => 'assets/payment-logos/bitcoin.svg'],
    ['label' => 'Ethereum', 'path' => 'assets/payment-logos/ethereum.svg'],
  ];
  $checkoutBrandLogoImgs = [];
  foreach ($checkoutBrandLogos as $logo) {
    $logoPath = $logo['path'];
    if (!is_file(__DIR__.'/'.$logoPath)) {
      continue;
    }
    $logoUrl = versioned_public_url($logoPath);
    $label = htmlspecialchars($logo['label'], ENT_QUOTES, 'UTF-8');
    $checkoutBrandLogoImgs[] = '<img src="'.htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8').'" alt="'.$label.' logo" class="h-8 object-contain">';
  }

  $checkoutToken = bin2hex(random_bytes(16));
  if (!isset($_SESSION['checkout_tokens_valid']) || !is_array($_SESSION['checkout_tokens_valid'])) {
    $_SESSION['checkout_tokens_valid'] = [];
  }
  $_SESSION['checkout_tokens_valid'][$checkoutToken] = time();
  if (count($_SESSION['checkout_tokens_valid']) > 10) {
    asort($_SESSION['checkout_tokens_valid']);
    $_SESSION['checkout_tokens_valid'] = array_slice($_SESSION['checkout_tokens_valid'], -10, null, true);
  }

  echo '<section class="max-w-6xl mx-auto px-4 py-8">';
  if ($checkoutError) {
    echo '  <div class="mb-4 p-4 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 flex items-start gap-3"><i class="fa-solid fa-triangle-exclamation mt-1"></i><span>'.htmlspecialchars($checkoutError, ENT_QUOTES, 'UTF-8').'</span></div>';
  }
  echo '  <h2 class="text-2xl font-bold mb-6"><i class="fa-solid fa-lock mr-2 text-brand-600"></i>'.htmlspecialchars($d['checkout'] ?? 'Finalizar Compra').'</h2>';
  echo '  <div class="checkout-steps-label">Seções</div>';
  echo '  <div class="checkout-steps mb-6">';
  echo '    <div class="checkout-step active"><span class="step-dot">1</span><span class="step-label">Dados</span></div>';
  echo '    <div class="checkout-step"><span class="step-dot">2</span><span class="step-label">Entrega</span></div>';
  echo '    <div class="checkout-step"><span class="step-dot">3</span><span class="step-label">Pagamento</span></div>';
  echo '  </div>';
  echo '  <form id="checkout-form" method="post" action="?route=place_order" enctype="multipart/form-data" class="grid lg:grid-cols-2 gap-6">';
  echo '    <input type="hidden" name="checkout_token" value="'.htmlspecialchars($checkoutToken, ENT_QUOTES, 'UTF-8').'">';
  echo '    <input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '    <input type="hidden" name="square_option" id="square_option_input" value="">';

  // Coluna 1 — Dados
  echo '    <div class="space-y-4">';
  echo '      <div class="bg-white rounded-2xl shadow p-5" id="checkout-section-customer">';
  echo '        <div class="font-semibold mb-3"><i class="fa-solid fa-user mr-2 text-brand-700"></i>'.htmlspecialchars($d["customer_info"] ?? "Dados do Cliente").'</div>';
  $countrySelectOptions = '';
  foreach ($countryOptions as $country) {
    $code = strtoupper($country['code']);
    $label = htmlspecialchars($country['name'], ENT_QUOTES, 'UTF-8');
    $selected = ($code === $defaultCountryOption) ? ' selected' : '';
    $countrySelectOptions .= '<option value="'.$code.'"'.$selected.'>'.$label.'</option>';
  }
  $initialStateOptions = '';
  if ($initialStates) {
    foreach ($initialStates as $stateItem) {
      $stateCode = strtoupper($stateItem['code'] ?? '');
      $stateName = htmlspecialchars($stateItem['name'] ?? $stateCode, ENT_QUOTES, 'UTF-8');
      $initialStateOptions .= '<option value="'.$stateCode.'">'.$stateName.'</option>';
    }
  }

  echo '        <div class="grid md:grid-cols-2 gap-3">';
  echo '          <input class="px-4 py-3 border rounded-lg" name="first_name" placeholder="Nome *" required>';
  echo '          <input class="px-4 py-3 border rounded-lg" name="last_name" placeholder="Sobrenome *" required>';
  echo '          <select class="px-4 py-3 border rounded-lg md:col-span-2" name="country" id="checkout-country" required>'.$countrySelectOptions.'</select>';
  echo '          <input class="px-4 py-3 border rounded-lg md:col-span-2" name="zipcode" placeholder="CEP *" required>';
  echo '          <div id="checkout-zipcode-hint" class="checkout-hint md:col-span-2 text-xs text-gray-500 mt-1 hidden">Ex.: 01001-000 (BR) ou 33101-1234 (EUA).</div>';
  echo '          <div id="checkout-zipcode-lookup" class="checkout-lookup md:col-span-2 text-xs text-gray-500 mt-1 hidden"></div>';
  echo '          <input class="px-4 py-3 border rounded-lg md:col-span-2" name="address1" placeholder="Nome da rua e número da casa *" required>';
  echo '          <input class="px-4 py-3 border rounded-lg md:col-span-2" name="address2" placeholder="Adicionar apartamento, suíte, unidade, etc." >';
  echo '          <input class="px-4 py-3 border rounded-lg" name="city" placeholder="Cidade *" required>';
  $stateSelectClass = $initialStates ? '' : ' hidden';
  $stateInputClass = $initialStates ? ' hidden' : '';
  $stateSelectName = $initialStates ? 'state' : 'state_select';
  $stateSelectAttr = $initialStates ? 'required' : 'disabled';
  $stateInputName = $initialStates ? 'state_text' : 'state';
  $stateInputAttr = $initialStates ? 'disabled' : 'required';
  echo '          <div id="state-select-wrapper" class="md:col-span-1'.$stateSelectClass.'">';
  echo '            <select class="px-4 py-3 border rounded-lg w-full" name="'.$stateSelectName.'" id="checkout-state" '.$stateSelectAttr.'>'.$initialStateOptions.'</select>';
  echo '          </div>';
  echo '          <div id="state-input-wrapper" class="md:col-span-1'.$stateInputClass.'">';
  echo '            <input class="px-4 py-3 border rounded-lg w-full" type="text" '.$stateInputAttr.' name="'.$stateInputName.'" id="checkout-state-text" placeholder="Estado *">';
  echo '          </div>';
  echo '          <input class="px-4 py-3 border rounded-lg md:col-span-2" name="email" type="email" placeholder="E-mail *" required>';
  echo '          <div class="phone-input-wrap md:col-span-2">';
  echo '            <span class="phone-flag" aria-hidden="true"></span>';
  echo '            <input class="px-4 py-3 border rounded-lg w-full" name="phone" placeholder="Telefone *" required>';
  echo '          </div>';
  echo '          <div id="checkout-phone-hint" class="checkout-hint md:col-span-2 text-xs text-gray-500 mt-1 hidden">Ex.: +55 11 9xxxx-xxxx ou +1 305 555-1234.</div>';
  $twilioOptInEnabled = (int)setting_get('whatsapp_twilio_enabled', '0') === 1;
  if ($twilioOptInEnabled) {
    echo '          <label class="md:col-span-2 flex items-start gap-3 p-3 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-900 text-sm">';
    echo '            <input class="mt-1" type="checkbox" name="whatsapp_opt_in" value="1">';
    echo '            <span>Quero receber atualizacoes do pedido via WhatsApp.</span>';
    echo '          </label>';
  }

  echo '        </div>';

  echo '      <div class="bg-white rounded-2xl shadow p-5 mt-4" id="checkout-section-delivery">';
  if ($deliveryMethods) {
    echo '        <div class="font-semibold mb-3"><i class="fa-solid fa-truck-fast mr-2 text-brand-700"></i>Método de Entrega</div>';
    echo '        <div class="space-y-3">';
    foreach ($deliveryMethods as $idx => $method) {
      $code = htmlspecialchars($method['code'], ENT_QUOTES, 'UTF-8');
      $label = htmlspecialchars($method['name'], ENT_QUOTES, 'UTF-8');
      $description = trim((string)($method['description'] ?? ''));
      $descHtml = $description !== '' ? '<div class="text-xs text-gray-500 mt-1">'.htmlspecialchars($description, ENT_QUOTES, 'UTF-8').'</div>' : '';
      $checked = $idx === 0 ? ' checked' : '';
      echo '          <label class="flex items-start gap-3 p-4 border rounded-xl hover:border-brand-300 cursor-pointer">';
      echo '            <input class="mt-1" type="radio" name="delivery_method" value="'.$code.'"'.$checked.' required>';
      echo '            <span class="text-sm text-gray-700">';
      echo '              <span class="font-semibold text-gray-900">'.$label.'</span>';
      if ($descHtml) {
        echo '              '.$descHtml;
      }
      echo '            </span>';
      echo '          </label>';
    }
    echo '        </div>';
  } else {
    echo '      <input type="hidden" name="delivery_method" value="">';
  }
  if ($secureDeliveryPriceSetting > 0) {
    $secureDeliveryFormatted = format_currency($secureDeliveryPriceSetting, $cartCurrency);
    echo '        <div class="mt-4">';
    echo '          <label class="flex items-start gap-3 p-4 border rounded-xl hover:border-brand-300 cursor-pointer">';
    echo '            <input class="mt-1" type="checkbox" name="secure_delivery" value="1" id="secure-delivery-checkbox">';
    echo '            <span class="text-sm text-gray-700">';
    echo '              <span class="font-semibold text-gray-900">Entrega segura</span>';
    echo '              <span class="ml-2 text-xs font-semibold text-emerald-600">+'.htmlspecialchars($secureDeliveryFormatted, ENT_QUOTES, 'UTF-8').'</span>';
    echo '              <div class="text-xs text-gray-500 mt-1">Entrega somente ao destinatário ou pessoa autorizada, mediante assinatura.</div>';
    echo '            </span>';
    echo '          </label>';
    echo '        </div>';
  }
  echo '      </div>';

  // Pagamento
  echo '      <div class="bg-white rounded-2xl shadow p-5" id="checkout-section-payment">';
  echo '        <div class="font-semibold mb-3"><i class="fa-solid fa-credit-card mr-2 text-brand-700"></i>'.htmlspecialchars($d["payment_info"] ?? "Pagamento").'</div>';
  if (!$paymentMethods) {
    echo '        <p class="text-sm text-red-600">Nenhum método de pagamento disponível. Atualize as configurações no painel.</p>';
  } else {
    if ($checkoutBrandLogoImgs) {
      echo '        <div class="flex flex-wrap items-center gap-3 mb-4 bg-gray-50 border border-gray-100 rounded-xl px-4 py-3">';
      echo '          <span class="text-[11px] uppercase tracking-[0.18em] text-gray-600 font-semibold">Aceitamos</span>';
      foreach ($checkoutBrandLogoImgs as $logoHtml) {
        echo '          '.$logoHtml;
      }
      echo '        </div>';
    }
    if (count($paymentMethods) > 1) {
      usort($paymentMethods, function ($a, $b) {
        $featuredA = !empty($a['is_featured']) ? 1 : 0;
        $featuredB = !empty($b['is_featured']) ? 1 : 0;
        if ($featuredA !== $featuredB) {
          return $featuredB <=> $featuredA;
        }
        $orderA = (int)($a['sort_order'] ?? 0);
        $orderB = (int)($b['sort_order'] ?? 0);
        if ($orderA !== $orderB) {
          return $orderA <=> $orderB;
        }
        $nameA = (string)($a['name'] ?? '');
        $nameB = (string)($b['name'] ?? '');
        return strcmp($nameA, $nameB);
      });
    }
    echo '        <div class="grid grid-cols-2 gap-3">';
    foreach ($paymentMethods as $pm) {
      $code = htmlspecialchars($pm['code']);
      $settings = $pm['settings'] ?? [];
      $payType = $settings['type'] ?? $pm['code'];
      $isFeatured = !empty($pm['is_featured']);
      $labelDisplay = $pm['name'];
      switch ($payType) {
        case 'stripe':
          $labelDisplay = 'Cartão de crédito (Stripe)';
          break;
        case 'square':
          $labelDisplay = 'Cartão de crédito (Square)';
          break;
        case 'paypal':
          $labelDisplay = 'PayPal';
          break;
        case 'pix':
          $labelDisplay = 'Pix';
          break;
        case 'zelle':
          $labelDisplay = 'Zelle';
          break;
        case 'venmo':
          $labelDisplay = 'Venmo';
          break;
        case 'whatsapp':
          $labelDisplay = 'WhatsApp';
          break;
      }
      $label = htmlspecialchars($labelDisplay);
      $icon = 'fa-credit-card';
      $iconPrefix = 'fa-solid';
      switch ($payType) {
        case 'pix': $icon = 'fa-qrcode'; break;
        case 'zelle': $icon = 'fa-university'; break;
        case 'venmo': $icon = 'fa-mobile-screen-button'; break;
        case 'paypal': $icon = 'fa-paypal'; break;
        case 'square': $icon = 'fa-arrow-up-right-from-square'; break;
        case 'stripe': $icon = 'fa-cc-stripe'; break;
        case 'whatsapp': $iconPrefix = 'fa-brands'; $icon = 'fa-whatsapp'; break;
      }
      $preferredBadge = $isFeatured ? '<span class="preferred-badge"><i class="fa-solid fa-star"></i> Preferido</span>' : '';
      $preferredClass = $isFeatured ? ' payment-card-preferred' : '';
      echo '  <label class="border rounded-xl p-4 cursor-pointer hover:border-brand-300 flex flex-col items-center gap-2 relative'.$preferredClass.'">';
      echo '    <input type="radio" name="payment" value="'.$code.'" class="sr-only" required data-code="'.$code.'">';
      if ($preferredBadge) {
        echo '    '.$preferredBadge;
      }
      if (!empty($pm['icon_path'])) {
        echo '    <img src="'.htmlspecialchars($pm['icon_path']).'" alt="'.$label.'" class="h-10">';
      } else {
        echo '    <i class="'.$iconPrefix.' '.$icon.' text-2xl text-brand-700"></i>';
      }
      echo '    <div class="font-medium">'.$label.'</div>';
      echo '  </label>';
    }
    echo '        </div>';

    foreach ($paymentMethods as $pm) {
      $code = htmlspecialchars($pm['code']);
      $settings = $pm['settings'] ?? [];
      $payType = $settings['type'] ?? $pm['code'];
      $accountLabel = htmlspecialchars($settings['account_label'] ?? '');
      $accountValue = htmlspecialchars($settings['account_value'] ?? '');
      $publicNote = trim((string)($pm['public_note'] ?? ''));
      $publicNoteHtml = $publicNote !== '' ? nl2br(htmlspecialchars($publicNote, ENT_QUOTES, 'UTF-8')) : '';
      $placeholders = payment_placeholders($pm, $total, null, null, $subtotal, $shipping, $currencyCode);
      $instructionsHtml = render_payment_instructions($pm['instructions'] ?? '', $placeholders);

      if ($payType === 'square') {
        $squareOptions = [];
        $squareOptions['credit'] = [
          'key' => 'credit',
          'label' => $settings['credit_label'] ?? 'Cartão de crédito',
          'link' => $settings['credit_link'] ?? ''
        ];
        $squareOptions['debit'] = [
          'key' => 'debit',
          'label' => $settings['debit_label'] ?? 'Cartão de débito',
          'link' => $settings['debit_link'] ?? ''
        ];
        $squareOptions['afterpay'] = [
          'key' => 'afterpay',
          'label' => $settings['afterpay_label'] ?? 'Afterpay',
          'link' => $settings['afterpay_link'] ?? ''
        ];
        $squareOptions = array_filter($squareOptions, function ($opt) {
          return !empty($opt['link']);
        });
        if (empty($squareOptions) && ($settings['mode'] ?? 'square_product_link') === 'direct_url' && !empty($settings['redirect_url'])) {
          $squareOptions = [
            'direct' => [
              'key' => 'direct',
              'label' => $settings['credit_label'] ?? $pm['name'],
              'link' => $settings['redirect_url']
            ]
          ];
        }

        $badgeTitle = htmlspecialchars($settings['badge_title'] ?? '', ENT_QUOTES, 'UTF-8');
        $badgeText = htmlspecialchars($settings['badge_text'] ?? '', ENT_QUOTES, 'UTF-8');
        echo '  <div data-payment-info="'.$code.'" class="hidden mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm text-gray-700 space-y-4">';
        if ($instructionsHtml !== '') {
          echo '    <div>'.$instructionsHtml.'</div>';
        }
        if ($badgeTitle !== '' || $badgeText !== '') {
          echo '    <div class="p-4 bg-white/80 border border-brand-100 rounded-xl text-left">';
          if ($badgeTitle !== '') {
            echo '      <div class="text-brand-700 font-semibold text-base">'.$badgeTitle.'</div>';
          }
          if ($badgeText !== '') {
            echo '      <div class="text-xs text-gray-600 mt-1">'.$badgeText.'</div>';
          }
          echo '    </div>';
        }
        if ($publicNoteHtml !== '') {
          echo '    <div class="text-xs text-gray-600"><strong>Observação:</strong> '.$publicNoteHtml.'</div>';
        }
        echo '    <div class="grid sm:grid-cols-3 gap-3 square-option-grid" data-square-options="'.$code.'">';
        if ($squareOptions) {
          foreach ($squareOptions as $opt) {
            $optLabel = htmlspecialchars($opt['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $optKey = htmlspecialchars($opt['key'], ENT_QUOTES, 'UTF-8');
            echo '      <button type="button" class="square-option-card border rounded-xl bg-white text-brand-700 px-4 py-3 flex flex-col items-start gap-1" data-square-option="'.$optKey.'">';
            echo '        <span class="text-sm font-semibold">'.$optLabel.'</span>';
            echo '        <span class="text-xs text-gray-500">Clique para pagar com '.$optLabel.'.</span>';
            echo '      </button>';
          }
        } else {
          echo '      <div class="text-sm text-gray-600">Configure os links das opções de cartão (crédito, débito e Afterpay) nas configurações do painel.</div>';
        }
        echo '    </div>';
        echo '  </div>';
      } else {
        echo '  <div data-payment-info="'.$code.'" class="hidden mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg text-sm text-gray-700">';
        if ($accountLabel || $accountValue) {
          echo '    <p class="mb-2"><strong>'.$accountLabel.'</strong>: '.$accountValue.'</p>';
        }
        if ($instructionsHtml !== '') {
          echo '    <p>'.$instructionsHtml.'</p>';
        }
        if ($publicNoteHtml !== '') {
          echo '    <p class="text-xs text-gray-600"><strong>Observação:</strong> '.$publicNoteHtml.'</p>';
        }
        if ($payType === 'zelle') {
          $subtotalFormatted = format_currency($subtotal, $currencyCode);
          $shippingFormatted = format_currency($shipping, $currencyCode);
          $totalFormatted = format_currency($total, $currencyCode);
          $totalSecureFormatted = format_currency($totalWithSecure, $currencyCode);
          $recipientName = htmlspecialchars($settings['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8');
          echo '    <div class="mt-3 p-3 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800">';
          echo '      <div class="font-semibold text-sm">Resumo da transferência</div>';
          echo '      <div class="text-sm mt-1"><strong>Total:</strong> <span id="checkout-zelle-total" data-base="'.$totalFormatted.'" data-secure="'.$totalSecureFormatted.'">'.$totalFormatted.'</span> (produtos '.$subtotalFormatted.' + frete '.$shippingFormatted.'<span id="checkout-zelle-secure" class="hidden"> + entrega segura</span>)</div>';
          if ($recipientName !== '') {
            echo '      <div class="text-xs mt-2 text-emerald-900/80">Beneficiário: '.$recipientName.'</div>';
          }
          echo '    </div>';
        }
        if ($payType === 'whatsapp') {
          $waNumberRaw = trim((string)($settings['number'] ?? $settings['account_value'] ?? ''));
          $waDisplay = $waNumberRaw !== '' ? $waNumberRaw : '';
          $waMessage = trim((string)($settings['message'] ?? ''));
          $waLink = trim((string)($settings['link'] ?? ''));
          $waDigits = preg_replace('/\D+/', '', $waNumberRaw);
          if ($waDigits !== '') {
            $waLink = 'https://wa.me/'.$waDigits;
            if ($waMessage !== '') {
              $waLink .= '?text='.rawurlencode($waMessage);
            }
          }
          echo '    <div class="mt-3 p-3 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 space-y-2">';
          if ($waDisplay !== '') {
            echo '      <div class="text-sm"><strong>Número:</strong> '.htmlspecialchars($waDisplay, ENT_QUOTES, 'UTF-8').'</div>';
          }
          if ($waMessage !== '') {
            echo '      <div class="text-xs text-emerald-900/80">Mensagem sugerida: '.htmlspecialchars($waMessage, ENT_QUOTES, 'UTF-8').'</div>';
          }
          if ($waLink !== '') {
            $safeLink = htmlspecialchars($waLink, ENT_QUOTES, 'UTF-8');
            echo '      <div><a class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold shadow hover:bg-emerald-700 transition" href="'.$safeLink.'" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Abrir conversa no WhatsApp</a></div>';
          } else {
            echo '      <div class="text-xs text-emerald-900/80">Envie uma mensagem para nossa equipe via WhatsApp informando os dados do pedido.</div>';
          }
          echo '    </div>';
        }
        echo '  </div>';
      }
      if (!empty($pm['require_receipt'])) {
        echo '  <div data-payment-receipt="'.$code.'" class="hidden mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">';
        echo '    <label class="block text-sm font-medium mb-2">Enviar Comprovante (JPG/PNG/PDF)</label>';
        echo '    <input class="w-full px-3 py-2 border rounded" type="file" name="payment_receipt" accept=".jpg,.jpeg,.png,.pdf">';
        echo '    <p class="text-xs text-gray-500 mt-2">Anexe o comprovante após realizar o pagamento.</p>';
        echo '  </div>';
      }
    }
  }

echo '      </div>';
  echo '    </div>';

  // Coluna 2 — Resumo
  echo '    <div>';
  echo '      <div class="bg-white rounded-2xl shadow p-5 sticky top-24">';
  echo '        <div class="font-semibold mb-3"><i class="fa-solid fa-clipboard-list mr-2 text-brand-600"></i>'.htmlspecialchars($d["order_details"] ?? "Resumo do Pedido").'</div>';
  foreach ($items as $it) {
    echo '        <div class="flex items-center justify-between py-2 border-b">';
    echo '          <div class="text-sm"><div class="font-medium">'.htmlspecialchars($it['name']).'</div><div class="text-gray-500">Qtd: '.(int)$it['qty'].'</div></div>';
    echo '          <div class="font-medium">'.format_currency($it['price']*$it['qty'], $it['currency'] ?? $cartCurrency).'</div>';
    echo '        </div>';
  }
  echo '        <div class="mt-4 space-y-1">';
  echo '          <div class="flex justify-between"><span>'.htmlspecialchars($d["subtotal"] ?? "Subtotal").'</span><span>'.format_currency($subtotal, $cartCurrency).'</span></div>';
  echo '          <div class="flex justify-between text-green-600"><span>Frete</span><span>'.format_currency($shipping, $cartCurrency).'</span></div>';
  echo '          <div id="checkout-secure-line" class="flex justify-between text-emerald-700 hidden"><span>Entrega segura</span><span id="checkout-secure-amount">'.format_currency($secureDeliveryPriceSetting, $cartCurrency).'</span></div>';
  echo '          <div class="flex justify-between text-lg font-bold border-t pt-2"><span>Total</span><span class="text-brand-600" id="checkout-total-amount" data-base="'.htmlspecialchars(format_currency($total, $cartCurrency), ENT_QUOTES, 'UTF-8').'" data-secure="'.htmlspecialchars(format_currency($totalWithSecure, $cartCurrency), ENT_QUOTES, 'UTF-8').'">'.format_currency($total, $cartCurrency).'</span></div>';
  echo '        </div>';
  echo '        <button type="submit" class="w-full mt-5 px-6 py-4 rounded-xl bg-brand-600 text-white hover:bg-brand-700 font-semibold"><i class="fa-solid fa-lock mr-2"></i>'.htmlspecialchars($d["place_order"] ?? "Finalizar Pedido").'</button>';
  $securityBadges = [
    [
      'icon' => 'fa-shield-check',
      'title' => 'Produtos Originais',
      'desc' => 'Nossos produtos são 100% originais e testados em laboratório.'
    ],
    [
      'icon' => 'fa-star',
      'title' => 'Qualidade e Segurança',
      'desc' => 'Compre com quem se preocupa com a qualidade dos produtos.'
    ],
    [
      'icon' => 'fa-truck-fast',
      'title' => 'Enviamos Para Todo EUA',
      'desc' => 'Entrega rápida e segura em todo o EUA.'
    ],
    [
      'icon' => 'fa-lock',
      'title' => 'Site 100% Seguro',
      'desc' => 'Seus pagamentos estão seguros com nossa rede de segurança privada.'
    ],
  ];
  echo '        <div class="mt-6 space-y-3">';
  foreach ($securityBadges as $badge) {
    $icon = htmlspecialchars($badge['icon'], ENT_QUOTES, 'UTF-8');
    $title = htmlspecialchars($badge['title'], ENT_QUOTES, 'UTF-8');
    $desc = htmlspecialchars($badge['desc'], ENT_QUOTES, 'UTF-8');
    echo '          <div class="flex items-start gap-3 p-3 rounded-xl border border-brand-100 bg-brand-50/40">';
    echo '            <div class="w-10 h-10 rounded-full bg-brand-600 text-white grid place-items-center text-lg"><i class="fa-solid '.$icon.'"></i></div>';
    echo '            <div>';
    echo '              <div class="font-semibold text-sm">'.$title.'</div>';
    echo '              <p class="text-xs text-gray-600 leading-snug">'.$desc.'</p>';
    echo '            </div>';
    echo '          </div>';
  }
  echo '        </div>';
  echo '      </div>';
  echo '    </div>';

  echo '  </form>';
  echo '</section>';

  $stateGroupsJson = json_encode($stateGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $defaultCountryJson = json_encode($defaultCountryOption, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  echo '<script>window.checkoutStateMap = '.$stateGroupsJson.';window.checkoutDefaultCountry = '.$defaultCountryJson.';</script>';

  echo "<script>
    (function(){
      const stateMap = window.checkoutStateMap || {};
      const countrySelectEl = document.getElementById('checkout-country');
      const stateSelectEl = document.getElementById('checkout-state');
      const stateSelectWrapper = document.getElementById('state-select-wrapper');
      const stateInputWrapper = document.getElementById('state-input-wrapper');
      const stateInputEl = document.getElementById('checkout-state-text');

      function applyStateMode(useSelect) {
        if (!stateSelectEl || !stateInputEl || !stateSelectWrapper || !stateInputWrapper) {
          return;
        }
        if (useSelect) {
          stateSelectWrapper.classList.remove('hidden');
          stateSelectEl.disabled = false;
          stateSelectEl.required = true;
          stateSelectEl.name = 'state';
          stateInputWrapper.classList.add('hidden');
          stateInputEl.disabled = true;
          stateInputEl.required = false;
          stateInputEl.name = 'state_text';
          stateInputEl.value = '';
          if (!stateSelectEl.value && stateSelectEl.options.length) {
            stateSelectEl.value = stateSelectEl.options[0].value;
          }
        } else {
          stateSelectWrapper.classList.add('hidden');
          stateSelectEl.disabled = true;
          stateSelectEl.required = false;
          stateSelectEl.name = 'state_select';
          stateInputWrapper.classList.remove('hidden');
          stateInputEl.disabled = false;
          stateInputEl.required = true;
          stateInputEl.name = 'state';
        }
      }

      function renderCountryStates(countryCode) {
        const country = (countryCode || '').toUpperCase();
        const states = Array.isArray(stateMap[country]) ? stateMap[country] : [];
        if (stateSelectEl) {
          stateSelectEl.innerHTML = '';
          if (states.length) {
            states.forEach(function(item, index) {
              const option = document.createElement('option');
              const code = (item.code || item.name || '').toString();
              option.value = code;
              option.textContent = item.name || item.code || code;
              if (index === 0) {
                option.selected = true;
              }
              stateSelectEl.appendChild(option);
            });
          }
        }
        applyStateMode(states.length > 0);
        if (country === 'US' && stateSelectEl && !stateSelectEl.disabled && stateSelectEl.options.length) {
          stateSelectEl.value = stateSelectEl.options[0].value;
        }
      }

      if (countrySelectEl) {
        renderCountryStates(countrySelectEl.value || window.checkoutDefaultCountry || '');
        countrySelectEl.addEventListener('change', function(event) {
          renderCountryStates(event.target.value);
        });
      } else {
        applyStateMode(false);
      }

      const phoneHint = document.getElementById('checkout-phone-hint');
      const zipHint = document.getElementById('checkout-zipcode-hint');
      const zipLookupHint = document.getElementById('checkout-zipcode-lookup');
      const phoneInput = document.querySelector(\"input[name='phone']\");
      const zipInput = document.querySelector(\"input[name='zipcode']\");
      const cityInput = document.querySelector(\"input[name='city']\");
      const phoneWrap = phoneInput ? phoneInput.closest('.phone-input-wrap') : null;
      const phoneFlag = phoneWrap ? phoneWrap.querySelector('.phone-flag') : null;

      function bindHint(inputEl, hintEl) {
        if (!inputEl || !hintEl) return;
        const toggle = function() {
          if (document.activeElement === inputEl || (inputEl.value || '').trim() !== '') {
            hintEl.classList.remove('hidden');
          } else {
            hintEl.classList.add('hidden');
          }
        };
        inputEl.addEventListener('focus', toggle);
        inputEl.addEventListener('blur', toggle);
        inputEl.addEventListener('input', toggle);
        toggle();
      }

      function detectPhoneCountry(rawValue) {
        const digits = (rawValue || '').replace(/\\D+/g, '');
        if (!digits) return '';
        if (digits.startsWith('55') && (digits.length === 12 || digits.length === 13)) {
          return 'br';
        }
        if (digits.startsWith('1') && digits.length === 11) {
          return 'us';
        }
        if (digits.length === 11) {
          const ddd = parseInt(digits.slice(0, 2), 10);
          if (ddd >= 11 && ddd <= 99) {
            return 'br';
          }
        }
        if (digits.length === 10) {
          const ddd = parseInt(digits.slice(0, 2), 10);
          const third = parseInt(digits.slice(2, 3), 10);
          if (ddd >= 11 && ddd <= 99 && third === 9) {
            return 'br';
          }
          return 'us';
        }
        return '';
      }

      function updatePhoneFlag() {
        if (!phoneWrap || !phoneFlag || !phoneInput) return;
        const country = detectPhoneCountry(phoneInput.value);
        phoneFlag.classList.remove('flag-br', 'flag-us');
        phoneWrap.classList.remove('has-flag');
        if (country) {
          phoneFlag.classList.add(country === 'br' ? 'flag-br' : 'flag-us');
          phoneWrap.classList.add('has-flag');
        }
      }

      function formatPhoneValue(rawValue) {
        const digits = (rawValue || '').replace(/\\D+/g, '');
        if (!digits) return '';
        if (digits.startsWith('55')) {
          const rest = digits.slice(2);
          if (rest.length <= 10) return '+55 ' + rest;
          return '+55 ' + rest.slice(0, 2) + ' ' + rest.slice(2, 7) + '-' + rest.slice(7, 11);
        }
        if (digits.startsWith('1')) {
          const rest = digits.slice(1);
          if (rest.length <= 3) return '+1 ' + rest;
          if (rest.length <= 6) return '+1 ' + rest.slice(0, 3) + ' ' + rest.slice(3);
          return '+1 ' + rest.slice(0, 3) + ' ' + rest.slice(3, 6) + '-' + rest.slice(6, 10);
        }
        if (digits.length === 10) {
          return '+1 ' + digits.slice(0, 3) + ' ' + digits.slice(3, 6) + '-' + digits.slice(6, 10);
        }
        if (digits.length === 11) {
          const ddd = parseInt(digits.slice(0, 2), 10);
          if (ddd >= 11 && ddd <= 99) {
            return '+55 ' + digits.slice(0, 2) + ' ' + digits.slice(2, 7) + '-' + digits.slice(7, 11);
          }
        }
        return digits;
      }

      if (phoneInput) {
        phoneInput.addEventListener('input', function(event) {
          updatePhoneFlag();
          const formatted = formatPhoneValue(event.target.value);
          if (formatted !== event.target.value) {
            event.target.value = formatted;
          }
        });
        phoneInput.addEventListener('blur', function() {
          updatePhoneFlag();
          const formatted = formatPhoneValue(phoneInput.value);
          if (formatted && formatted !== phoneInput.value) {
            phoneInput.value = formatted;
          }
        });
        updatePhoneFlag();
      }

      function formatZipValue(rawValue) {
        const digits = (rawValue || '').replace(/\\D+/g, '');
        if (!digits) return '';
        if (digits.length <= 5) {
          return digits;
        }
        if (digits.length === 8) {
          return digits.slice(0, 5) + '-' + digits.slice(5);
        }
        if (digits.length >= 9) {
          return digits.slice(0, 5) + '-' + digits.slice(5, 9);
        }
        return digits;
      }

      if (zipInput) {
        zipInput.addEventListener('input', function(event) {
          const formatted = formatZipValue(event.target.value);
          if (formatted !== event.target.value) {
            event.target.value = formatted;
          }
        });
      }

      bindHint(phoneInput, phoneHint);
      bindHint(zipInput, zipHint);

      const secureCheckbox = document.getElementById('secure-delivery-checkbox');
      const secureLine = document.getElementById('checkout-secure-line');
      const totalAmount = document.getElementById('checkout-total-amount');
      const zelleTotal = document.getElementById('checkout-zelle-total');
      const zelleSecure = document.getElementById('checkout-zelle-secure');

      function updateSecureTotals() {
        if (!totalAmount) return;
        const useSecure = secureCheckbox && secureCheckbox.checked;
        const nextTotal = useSecure ? totalAmount.dataset.secure : totalAmount.dataset.base;
        if (nextTotal) {
          totalAmount.textContent = nextTotal;
        }
        if (secureLine) {
          secureLine.classList.toggle('hidden', !useSecure);
        }
        if (zelleTotal) {
          zelleTotal.textContent = useSecure ? zelleTotal.dataset.secure : zelleTotal.dataset.base;
        }
        if (zelleSecure) {
          zelleSecure.classList.toggle('hidden', !useSecure);
        }
      }

      if (secureCheckbox) {
        secureCheckbox.addEventListener('change', updateSecureTotals);
        updateSecureTotals();
      }

      function setZipLookupStatus(message, isError) {
        if (!zipLookupHint) return;
        if (!message) {
          zipLookupHint.classList.add('hidden');
          zipLookupHint.classList.remove('error');
          zipLookupHint.textContent = '';
          return;
        }
        zipLookupHint.textContent = message;
        zipLookupHint.classList.remove('hidden');
        zipLookupHint.classList.toggle('error', Boolean(isError));
      }

      function applyStateValue(stateCode) {
        if (!stateCode) return;
        if (stateSelectEl && !stateSelectEl.disabled) {
          const target = stateCode.toUpperCase();
          const option = Array.from(stateSelectEl.options).find(opt => opt.value.toUpperCase() === target);
          if (option) {
            stateSelectEl.value = option.value;
            return;
          }
        }
        if (stateInputEl && !stateInputEl.disabled) {
          stateInputEl.value = stateCode;
        }
      }

      let zipLookupTimer = null;
      let zipLookupAbort = null;
      function lookupZipUS() {
        if (!zipInput) return;
        const digits = (zipInput.value || '').replace(/\\D+/g, '');
        if (digits.length < 5) {
          setZipLookupStatus('', false);
          return;
        }
        const zip5 = digits.slice(0, 5);
        if (zipLookupAbort) {
          zipLookupAbort.abort();
        }
        zipLookupAbort = new AbortController();
        setZipLookupStatus('Consultando ZIP...', false);
        fetch('https://api.zippopotam.us/us/' + zip5, { signal: zipLookupAbort.signal })
          .then(resp => {
            if (!resp.ok) throw new Error('not_found');
            return resp.json();
          })
          .then(data => {
            const place = data && data.places && data.places[0] ? data.places[0] : null;
            if (!place) throw new Error('empty');
            if (countrySelectEl) {
              countrySelectEl.value = 'US';
              countrySelectEl.dispatchEvent(new Event('change'));
            }
            const city = (place['place name'] || '').toString();
            const state = (place['state abbreviation'] || '').toString();
            if (cityInput && (cityInput.value.trim() === '' || cityInput.dataset.autofill === '1')) {
              cityInput.value = city;
              cityInput.dataset.autofill = '1';
            }
            const applyState = function() { applyStateValue(state); };
            setTimeout(applyState, 0);
            const addressInput = document.querySelector(\"input[name='address1']\");
            if (addressInput && addressInput.value.trim() === '') {
              addressInput.focus();
            }
            setZipLookupStatus('Endereco encontrado para ' + zip5 + '.', false);
          })
          .catch(err => {
            if (err && err.name === 'AbortError') return;
            setZipLookupStatus('ZIP nao encontrado. Preencha manualmente.', true);
          });
      }

      if (zipInput) {
        zipInput.addEventListener('blur', function() {
          lookupZipUS();
        });
        zipInput.addEventListener('input', function() {
          if (zipLookupTimer) {
            clearTimeout(zipLookupTimer);
          }
          zipLookupTimer = setTimeout(lookupZipUS, 600);
        });
      }

      const checkoutForm = document.getElementById('checkout-form');
      const touched = new Set();
      const validators = [
        { name: 'first_name', message: 'Informe um nome valido.', validate: v => v.trim().length >= 2 },
        { name: 'last_name', message: 'Informe um sobrenome valido.', validate: v => v.trim().length >= 2 },
        { name: 'email', message: 'Informe um e-mail valido.', validate: v => /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(v.trim()) },
        { name: 'address1', message: 'Informe um endereco valido.', validate: v => v.trim().length >= 5 },
        { name: 'city', message: 'Informe uma cidade valida.', validate: v => v.trim().length >= 2 },
        { name: 'zipcode', message: 'CEP/ZIP invalido. Use 8, 5 ou 9 digitos.', validate: v => {
          const digits = (v || '').replace(/\\D+/g, '');
          return digits.length === 8 || digits.length === 5 || digits.length === 9;
        }},
        { name: 'phone', message: 'Telefone invalido. Use um numero BR ou EUA.', validate: v => detectPhoneCountry(v) !== '' },
      ];

      function ensureErrorEl(inputEl) {
        if (!inputEl) return null;
        let errorEl = inputEl.parentElement ? inputEl.parentElement.querySelector('.field-error') : null;
        if (!errorEl) {
          errorEl = document.createElement('div');
          errorEl.className = 'field-error hidden';
          if (inputEl.nextSibling) {
            inputEl.parentElement.insertBefore(errorEl, inputEl.nextSibling);
          } else {
            inputEl.parentElement.appendChild(errorEl);
          }
        }
        return errorEl;
      }

      function setFieldInvalid(inputEl, message) {
        if (!inputEl) return;
        const errorEl = ensureErrorEl(inputEl);
        if (errorEl) {
          errorEl.textContent = message;
          errorEl.classList.remove('hidden');
        }
        inputEl.classList.add('is-invalid');
        inputEl.setAttribute('aria-invalid', 'true');
      }

      function clearFieldInvalid(inputEl) {
        if (!inputEl) return;
        const errorEl = ensureErrorEl(inputEl);
        if (errorEl) {
          errorEl.classList.add('hidden');
          errorEl.textContent = '';
        }
        inputEl.classList.remove('is-invalid');
        inputEl.removeAttribute('aria-invalid');
      }

      function validateField(inputEl) {
        if (!inputEl) return true;
        const name = inputEl.getAttribute('name');
        const rule = validators.find(r => r.name === name);
        if (!rule) return true;
        const isValid = rule.validate(inputEl.value || '');
        if (!isValid && touched.has(name)) {
          setFieldInvalid(inputEl, rule.message);
        } else {
          clearFieldInvalid(inputEl);
        }
        return isValid;
      }

      function validateStateField() {
        if (!stateSelectEl || !stateInputEl) return true;
        if (!stateSelectEl.disabled) {
          if (!stateSelectEl.value) {
            setFieldInvalid(stateSelectEl, 'Selecione um estado.');
            return false;
          }
          clearFieldInvalid(stateSelectEl);
          return true;
        }
        const val = stateInputEl.value || '';
        if (touched.has('state') && val.trim().length < 2) {
          setFieldInvalid(stateInputEl, 'Informe um estado/regiao valido.');
          return false;
        }
        clearFieldInvalid(stateInputEl);
        return val.trim().length >= 2;
      }

      validators.forEach(rule => {
        const inputEl = document.querySelector(\"[name='\" + rule.name + \"']\");
        if (!inputEl) return;
        inputEl.addEventListener('blur', function() {
          touched.add(rule.name);
          validateField(inputEl);
        });
        inputEl.addEventListener('input', function() {
          if (touched.has(rule.name)) {
            validateField(inputEl);
          }
        });
      });

      if (stateInputEl) {
        stateInputEl.addEventListener('blur', function() { touched.add('state'); validateStateField(); });
        stateInputEl.addEventListener('input', function() { if (touched.has('state')) validateStateField(); });
      }
      if (stateSelectEl) {
        stateSelectEl.addEventListener('change', function() { touched.add('state'); validateStateField(); });
      }

      if (checkoutForm) {
        checkoutForm.addEventListener('submit', function(event) {
          if (checkoutForm.dataset.submitting === '1') {
            event.preventDefault();
            return;
          }
          validators.forEach(rule => touched.add(rule.name));
          touched.add('state');
          let firstInvalid = null;
          validators.forEach(rule => {
            const inputEl = document.querySelector(\"[name='\" + rule.name + \"']\");
            const ok = validateField(inputEl);
            if (!ok && !firstInvalid) {
              firstInvalid = inputEl;
            }
          });
          const stateOk = validateStateField();
          if (!stateOk && !firstInvalid) {
            firstInvalid = stateSelectEl && !stateSelectEl.disabled ? stateSelectEl : stateInputEl;
          }
          if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus();
            return;
          }
          checkoutForm.dataset.submitting = '1';
          const submitBtn = checkoutForm.querySelector(\"button[type=submit]\");
          if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-70','cursor-not-allowed');
          }
        });
      }

      const stepEls = Array.from(document.querySelectorAll('.checkout-step'));
      const stepSections = [
        { el: document.getElementById('checkout-section-customer'), index: 0 },
        { el: document.getElementById('checkout-section-delivery'), index: 1 },
        { el: document.getElementById('checkout-section-payment'), index: 2 },
      ].filter(item => item.el);

      function setActiveStep(index) {
        stepEls.forEach((stepEl, idx) => {
          stepEl.classList.toggle('active', idx === index);
          stepEl.classList.toggle('done', idx < index);
        });
      }

      function updateActiveStepByScroll() {
        if (!stepSections.length) return;
        let bestIndex = 0;
        let bestDistance = Number.POSITIVE_INFINITY;
        stepSections.forEach(section => {
          const rect = section.el.getBoundingClientRect();
          const distance = Math.abs(rect.top - 120);
          if (distance < bestDistance) {
            bestDistance = distance;
            bestIndex = section.index;
          }
        });
        setActiveStep(bestIndex);
      }

      if (stepEls.length) {
        updateActiveStepByScroll();
        window.addEventListener('scroll', function() {
          window.requestAnimationFrame(updateActiveStepByScroll);
        }, { passive: true });
        window.addEventListener('resize', function() {
          window.requestAnimationFrame(updateActiveStepByScroll);
        });
      }

      if (checkoutForm) {
        checkoutForm.addEventListener('focusin', function(event) {
          const target = event.target;
          if (!target || !target.closest) return;
          const section = stepSections.find(item => item.el.contains(target));
          if (section) {
            setActiveStep(section.index);
          }
        });
      }

      const paymentRadios = document.querySelectorAll(\"input[name='payment']\");
      const style = document.createElement('style');
      style.innerHTML = '.square-option-card{transition:all .2s ease;border:1px solid rgba(32,96,200,.2);} .square-option-card:hover{border-color:rgba(32,96,200,.6);transform:translateY(-2px);} .square-option-card.selected{border-color:rgba(32,96,200,1);background:rgba(255,255,255,0.95);box-shadow:0 10px 25px -12px rgba(32,96,200,.7);} .checkout-hint{line-height:1.35;} .checkout-lookup{line-height:1.35;} .checkout-lookup.error{color:#b91c1c;} .checkout-steps-label{font-size:11px;text-transform:uppercase;letter-spacing:.18em;color:#94a3b8;font-weight:700;margin-bottom:8px;} .checkout-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;} .checkout-step{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid rgba(15,23,42,.08);border-radius:12px;background:#fff;} .checkout-step .step-dot{width:22px;height:22px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;background:#e2e8f0;color:#0f172a;font-weight:600;} .checkout-step.active{border-color:rgba(32,96,200,.35);background:#f8fafc;} .checkout-step.active .step-dot{background:#2060c8;color:#fff;} .checkout-step.done{border-color:rgba(22,163,74,.35);background:#f0fdf4;} .checkout-step.done .step-dot{background:#16a34a;color:#fff;} .checkout-step .step-label{font-size:12px;color:#475569;font-weight:600;} @media (max-width:640px){.checkout-hint{font-size:11px;margin-top:4px;}.checkout-lookup{font-size:11px;margin-top:4px;}.checkout-steps{grid-template-columns:1fr;}} .phone-input-wrap{position:relative;} .phone-input-wrap .phone-flag{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:22px;height:16px;border-radius:3px;box-shadow:0 0 0 1px rgba(0,0,0,.08);background-size:cover;background-position:center;opacity:0;transition:opacity .15s ease;} .phone-input-wrap.has-flag .phone-flag{opacity:1;} .phone-input-wrap.has-flag input{padding-left:44px;} .phone-flag.flag-br{background-image:url(\"data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMiIgaGVpZ2h0PSIxNiIgdmlld0JveD0iMCAwIDIyIDE2Ij48cmVjdCB3aWR0aD0iMjIiIGhlaWdodD0iMTYiIGZpbGw9IiMyRTg0MkYiLz48cG9seWdvbiBwb2ludHM9IjExLDEuNSAyMCwxMSA1LDExIiBmaWxsPSIjRkZEMDAwIi8+PGNpcmNsZSBjeD0iMTEiIGN5PSI5IiByPSI0IiBmaWxsPSIjMDAyNzc2Ii8+PC9zdmc+\");} .phone-flag.flag-us{background-image:url(\"data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyMiIgaGVpZ2h0PSIxNiIgdmlld0JveD0iMCAwIDIyIDE2Ij48cmVjdCB3aWR0aD0iMjIiIGhlaWdodD0iMTYiIGZpbGw9IiNGRkYiLz48cmVjdCB3aWR0aD0iMjIiIGhlaWdodD0iMiIgZmlsbD0iI0QwMDAwMCIvPjxyZWN0IHk9IjQiIHdpZHRoPSIyMiIgaGVpZ2h0PSIyIiBmaWxsPSIjRDAwMDAwIi8+PHJlY3QgeT0iOCIgd2lkdGg9IjIyIiBoZWlnaHQ9IjIiIGZpbGw9IiNEMDAwMDAiLz48cmVjdCB5PSIxMiIgd2lkdGg9IjIyIiBoZWlnaHQ9IjIiIGZpbGw9IiNEMDAwMDAiLz48cmVjdCB3aWR0aD0iMTAiIGhlaWdodD0iOCIgeT0iMCIgeD0iMCIgZmlsbD0iIzAwMkI3RiIvPjwvc3ZnPg==\");} .field-error{color:#b91c1c;font-size:11px;margin-top:4px;line-height:1.3;} .is-invalid{border-color:#dc2626;background:#fef2f2;} .payment-card-preferred{border-color:rgba(16,185,129,.6);background:linear-gradient(180deg, rgba(240,253,244,.95), #fff);box-shadow:0 14px 30px -22px rgba(16,185,129,.7);} .payment-card-preferred::before{content:\"\";position:absolute;left:0;right:0;top:0;height:3px;border-radius:12px 12px 0 0;background:linear-gradient(90deg,#10b981,#22c55e);} .preferred-badge{position:absolute;top:10px;right:10px;display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.12em;background:rgba(16,185,129,.12);color:#047857;border:1px solid rgba(16,185,129,.35);padding:3px 6px;border-radius:999px;}';
      document.head.appendChild(style);
      const infoBlocks = document.querySelectorAll('[data-payment-info]');
      const receiptBlocks = document.querySelectorAll('[data-payment-receipt]');
      const squareOptionInput = document.getElementById('square_option_input');
      const squareStorageKey = 'square_checkout_target';

      function resetSquareSelection() {
        document.querySelectorAll('.square-option-card.selected').forEach(btn => btn.classList.remove('selected'));
        if (squareOptionInput) squareOptionInput.value = '';
      }

      function selectSquareOption(option, btn) {
        if (!squareOptionInput) return;
        resetSquareSelection();
        squareOptionInput.value = option;
        if (btn) btn.classList.add('selected');
      }

      paymentRadios.forEach(radio => {
        radio.addEventListener('change', () => {
          document.querySelectorAll('.border-brand-300').forEach(el => el.classList.remove('border-brand-300'));
          const card = radio.closest('label');
          if (card) card.classList.add('border-brand-300');
          const code = radio.dataset.code;
          infoBlocks.forEach(block => {
            const show = block.getAttribute('data-payment-info') === code;
            block.classList.toggle('hidden', !show);
            if (show && code === 'square') {
              const firstBtn = block.querySelector('.square-option-card');
              if (firstBtn) {
                selectSquareOption(firstBtn.dataset.squareOption, firstBtn);
              }
            }
          });
          receiptBlocks.forEach(block => {
            block.classList.toggle('hidden', block.getAttribute('data-payment-receipt') !== code);
          });
          if (code !== 'square') {
            resetSquareSelection();
          }
        });
      });

      document.querySelectorAll('.square-option-card').forEach(btn => {
        btn.addEventListener('click', () => {
          selectSquareOption(btn.dataset.squareOption, btn);
        });
      });

      const form = document.querySelector('#checkout-form');
      if (form) {
        form.addEventListener('submit', function() {
          const selected = form.querySelector(\"input[name='payment']:checked\");
          if (!selected) { return; }
          const code = selected.dataset.code || selected.value;
          if (code === 'square') {
            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            const features = 'noopener=yes,noreferrer=yes,width=920,height=860,left=120,top=60,resizable=yes,scrollbars=yes';
            try { window.sessionStorage.setItem('square_checkout_pending', '1'); } catch (e) {}
            const loadingUrl = document.body.getAttribute('data-square-loading-url') || 'square-loading.html';
            if (!isMobile) {
              let popup = null;
              try {
                popup = window.open(loadingUrl, 'squareCheckout', features);
              } catch (err) {
                popup = null;
              }
              if (!popup) {
                try {
                  popup = window.open('about:blank', 'squareCheckout', features);
                  if (popup) {
                    popup.location.replace(loadingUrl);
                  }
                } catch (err) {
                  popup = null;
                }
              }
              if (popup) {
                try { popup.focus(); } catch (e) {}
              }
            }
          } else {
            try { window.sessionStorage.removeItem('square_checkout_pending'); } catch (e) {}
            try { localStorage.removeItem(squareStorageKey); } catch (e) {}
          }
        });
      }
    })();
  </script>";

  app_footer();
  exit;
}

// PLACE ORDER
if ($route === 'place_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF inválido');

  $pdo = db();
  if (function_exists('checkout_maybe_upgrade_schema')) {
    checkout_maybe_upgrade_schema();
  }
  if (function_exists('affiliate_maybe_upgrade_schema')) {
    affiliate_maybe_upgrade_schema();
  }
  $cart = $_SESSION['cart'] ?? [];
  if (!$cart) die('Carrinho vazio');

  $checkoutToken = sanitize_string($_POST['checkout_token'] ?? '', 80);
  $validTokens = $_SESSION['checkout_tokens_valid'] ?? [];
  $usedTokens = $_SESSION['checkout_tokens_used'] ?? [];
  if ($checkoutToken === '') {
    $_SESSION['checkout_error'] = 'Token de checkout inválido. Atualize a página e tente novamente.';
    header('Location: ?route=checkout');
    exit;
  }
  if (isset($usedTokens[$checkoutToken])) {
    $existingOrder = (int)($usedTokens[$checkoutToken]['order_id'] ?? 0);
    if ($existingOrder > 0) {
      header('Location: ?route=order_success&id='.$existingOrder);
      exit;
    }
    $_SESSION['checkout_error'] = 'Pedido já processado. Verifique seu e-mail ou tente novamente.';
    header('Location: ?route=checkout');
    exit;
  }
  if (!isset($validTokens[$checkoutToken])) {
    $_SESSION['checkout_error'] = 'Sessão de checkout expirada. Atualize a página e tente novamente.';
    header('Location: ?route=checkout');
    exit;
  }

  $availableCountries = checkout_get_countries();
  $countryCodes = array_map(function ($c) { return strtoupper($c['code']); }, $availableCountries);
  $defaultCountryOption = setting_get('checkout_default_country', $availableCountries[0]['code'] ?? 'US');
  $defaultCountryOption = strtoupper(trim((string)$defaultCountryOption));
  if (!in_array($defaultCountryOption, $countryCodes, true) && $availableCountries) {
    $defaultCountryOption = strtoupper($availableCountries[0]['code']);
  }

  $statesGrouped = checkout_group_states();
  $deliveryMethodsAvailable = checkout_get_delivery_methods();

  $firstName = sanitize_string($_POST['first_name'] ?? '', 120);
  $lastName = sanitize_string($_POST['last_name'] ?? '', 120);
  $email = trim((string)($_POST['email'] ?? ''));
  $phone = sanitize_string($_POST['phone'] ?? '', 60);
  $address1 = sanitize_string($_POST['address1'] ?? '', 255);
  $address2 = sanitize_string($_POST['address2'] ?? '', 255);
  $city = sanitize_string($_POST['city'] ?? '', 120);
  $stateInput = sanitize_string($_POST['state'] ?? ($_POST['state_text'] ?? ''), 80);
  $zipcode = sanitize_string($_POST['zipcode'] ?? '', 20);
  $country = strtoupper(trim((string)($_POST['country'] ?? '')));
  if ($country === '' || !in_array($country, $countryCodes, true)) {
    $country = $defaultCountryOption;
  }
  $payment_method = $_POST['payment'] ?? '';
  $selectedDeliveryCode = trim((string)($_POST['delivery_method'] ?? ''));
  $secureDeliverySelected = !empty($_POST['secure_delivery']);
  $whatsappOptIn = !empty($_POST['whatsapp_opt_in']);
  $secureDeliveryPriceSetting = (float)setting_get('checkout_secure_delivery_price', 0);
  $secureDeliveryPriceSetting = $secureDeliveryPriceSetting < 0 ? 0 : round($secureDeliveryPriceSetting, 2);
  $secureDeliveryFee = $secureDeliverySelected ? $secureDeliveryPriceSetting : 0.0;

  $errors = [];
  if ($firstName === '' || mb_strlen($firstName) < 2) {
    $errors[] = 'Informe um nome valido.';
  }
  if ($lastName === '' || mb_strlen($lastName) < 2) {
    $errors[] = 'Informe um sobrenome valido.';
  }
  if (!validate_email($email)) {
    $errors[] = 'Informe um e-mail valido.';
  }
  if ($address1 === '' || mb_strlen($address1) < 5) {
    $errors[] = 'Informe um endereco valido.';
  }
  if ($city === '' || mb_strlen($city) < 2) {
    $errors[] = 'Informe uma cidade valida.';
  }
  if ($zipcode === '') {
    $errors[] = 'Informe o CEP/ZIP.';
  }
  if ($phone === '') {
    $errors[] = 'Informe um telefone.';
  }
  if ($errors) {
    $_SESSION['checkout_error'] = $errors[0];
    header('Location: ?route=checkout');
    exit;
  }

  $phoneNormalized = normalize_phone_us_br($phone);
  if ($phoneNormalized === '') {
    $_SESSION['checkout_error'] = 'Telefone invalido. Informe um numero do Brasil ou EUA com DDD/area code.';
    header('Location: ?route=checkout');
    exit;
  }
  $phone = $phoneNormalized;

  $zipcodeNormalized = normalize_zipcode_us_br($zipcode, $country);
  if ($zipcodeNormalized === '') {
    $_SESSION['checkout_error'] = 'CEP/ZIP invalido. Use um CEP (8 digitos) ou ZIP (5 ou 9 digitos).';
    header('Location: ?route=checkout');
    exit;
  }
  $zipcode = $zipcodeNormalized;

  $stateNormalized = trim($stateInput);
  $countryStates = $statesGrouped[$country] ?? [];
  if ($countryStates) {
    $validCode = null;
    foreach ($countryStates as $stateOption) {
      $code = strtoupper(trim((string)($stateOption['code'] ?? '')));
      if ($code !== '' && strtoupper($stateNormalized) === $code) {
        $validCode = $code;
        break;
      }
    }
    if ($validCode === null) {
      $_SESSION['checkout_error'] = 'Selecione um estado válido.';
      header('Location: ?route=checkout');
      exit;
    }
    $state = $validCode;
  } else {
    if ($stateNormalized === '') {
      $_SESSION['checkout_error'] = 'Informe o estado ou região.';
      header('Location: ?route=checkout');
      exit;
    }
    if (mb_strlen($stateNormalized) < 2) {
      $_SESSION['checkout_error'] = 'Informe um estado/regiao valido.';
      header('Location: ?route=checkout');
      exit;
    }
    $state = sanitize_string($stateNormalized, 80);
  }

  $deliveryMethodCode = '';
  $deliveryMethodLabel = '';
  $deliveryMethodDetails = '';
  if ($deliveryMethodsAvailable) {
    $foundDelivery = null;
    foreach ($deliveryMethodsAvailable as $method) {
      if (strcasecmp($method['code'], $selectedDeliveryCode) === 0) {
        $foundDelivery = $method;
        break;
      }
    }
    if ($foundDelivery === null) {
      $_SESSION['checkout_error'] = 'Selecione um método de entrega disponível.';
      header('Location: ?route=checkout');
      exit;
    }
    $deliveryMethodCode = $foundDelivery['code'];
    $deliveryMethodLabel = $foundDelivery['name'] ?? '';
    $deliveryMethodDetails = $foundDelivery['description'] ?? '';
  }

  $fullName = trim($firstName.' '.$lastName);

  $storeCurrencyBase = strtoupper($cfg['store']['currency'] ?? 'USD');
  $cartCurrency = $_SESSION['cart_currency'] ?? null;
  if (is_string($cartCurrency)) {
    $cartCurrency = strtoupper(trim($cartCurrency));
    if ($cartCurrency === '') {
      $cartCurrency = null;
    }
  } else {
    $cartCurrency = null;
  }

  $ids = array_keys($cart);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $st  = $pdo->prepare("SELECT * FROM products WHERE id IN ($in) AND active=1");
  $st->execute($ids);

  $items = []; $subtotal = 0.0; $shipping = 0.0;
  foreach ($st as $p) {
    $qty = (int)($cart[$p['id']] ?? 0);
    if ((int)$p['stock'] < $qty) die('Produto '.$p['name'].' sem estoque');
    $shipCost = isset($p['shipping_cost']) ? (float)$p['shipping_cost'] : 7.00;
    if ($shipCost < 0) { $shipCost = 0; }
    $productCurrency = strtoupper($p['currency'] ?? $storeCurrencyBase);
    if ($cartCurrency === null) {
      $cartCurrency = $productCurrency;
    } elseif ($productCurrency !== $cartCurrency) {
      $_SESSION['checkout_error'] = 'Carrinho possui produtos com moedas diferentes. Remova itens ou finalize separadamente.';
      header('Location: ?route=checkout');
      exit;
    }
    $items[] = [
      'id'=>(int)$p['id'],
      'name'=>$p['name'],
      'price'=>(float)$p['price'],
      'qty'=>$qty,
      'sku'=>$p['sku'],
      'shipping_cost'=>$shipCost,
      'square_link'=> trim((string)($p['square_payment_link'] ?? '')),
      'stripe_link'=> trim((string)($p['stripe_payment_link'] ?? '')),
      'paypal_link'=> trim((string)($p['paypal_payment_link'] ?? '')),
      'currency'=>$productCurrency,
    ];
    $subtotal += (float)$p['price'] * $qty;
    $shipping += $shipCost * $qty;
  }
  $shipping = max(0, $shipping);
  $total = $subtotal + $shipping;
  if ($cartCurrency === null) {
    $cartCurrency = $storeCurrencyBase;
  }
  $_SESSION['cart_currency'] = $cartCurrency;

  $methods = load_payment_methods($pdo, $cfg);
  $codesExisting = [];
  foreach ($methods as $m) {
    $codesExisting[$m['code']] = true;
  }
  $hasPaypalLinks = !empty(array_filter($items, function($it){ return trim((string)($it['paypal_link'] ?? '')) !== ''; }));
  $hasStripeLinks = !empty(array_filter($items, function($it){ return trim((string)($it['stripe_link'] ?? '')) !== ''; }));
  if ($hasPaypalLinks && !isset($codesExisting['paypal'])) {
    $methods[] = [
      'code' => 'paypal',
      'name' => 'PayPal',
      'description' => '',
      'instructions' => 'Após finalizar, abriremos o checkout PayPal.',
      'settings' => [
        'type' => 'paypal',
        'mode' => 'paypal_product_link',
        'open_new_tab' => true
      ],
      'icon_path' => null,
      'require_receipt' => 0
    ];
    $codesExisting['paypal'] = true;
  }
  if ($hasStripeLinks && !isset($codesExisting['stripe'])) {
    $methods[] = [
      'code' => 'stripe',
      'name' => 'Stripe',
      'description' => '',
      'instructions' => 'Após finalizar, abriremos o checkout Stripe.',
      'settings' => [
        'type' => 'stripe',
        'mode' => 'stripe_product_link',
        'open_new_tab' => true
      ],
      'icon_path' => null,
      'require_receipt' => 0
    ];
    $codesExisting['stripe'] = true;
  }
  $methodMap = [];
  foreach ($methods as $m) {
    $methodMap[$m['code']] = $m;
  }
  if (!isset($methodMap[$payment_method])) {
    die('Método de pagamento inválido');
  }
  $selectedMethod = $methodMap[$payment_method];
  $methodSettings = $selectedMethod['settings'] ?? [];
  $methodType = $methodSettings['type'] ?? $selectedMethod['code'];
  $storeNameForEmails = setting_get('store_name', $cfg['store']['name'] ?? 'Sua Loja');
  $customMode = $methodSettings['mode'] ?? 'manual';
  $customProductRedirectUrl = null;
  $customProductWarning = null;
  $customProductLinks = [];
  $customProductMissing = [];
  if ($customMode === 'product_link' && !in_array($methodType, ['square','stripe','paypal','pix','zelle','venmo','whatsapp'], true)) {
    try {
      $linkStmt = $pdo->prepare("SELECT product_id, link FROM product_payment_links WHERE gateway_code = ? AND product_id IN ($in)");
      $params = array_merge([$payment_method], $ids);
      $linkStmt->execute($params);
      $linkMap = [];
      foreach ($linkStmt as $linkRow) {
        $pid = (int)($linkRow['product_id'] ?? 0);
        $linkMap[$pid] = trim((string)($linkRow['link'] ?? ''));
      }
      foreach ($items as $itemInfo) {
        $pid = (int)($itemInfo['id'] ?? 0);
        $link = $linkMap[$pid] ?? '';
        if ($link === '') {
          $customProductMissing[] = $itemInfo['name'];
        } else {
          $customProductLinks[$link] = true;
        }
      }
      if (!empty($customProductMissing)) {
        $cleanNames = array_map(function($name){ return sanitize_string($name ?? '', 80); }, $customProductMissing);
        $customProductWarning = 'Pagamento pendente para: '.implode(', ', $cleanNames);
      } elseif (count($customProductLinks) > 1) {
        $customProductWarning = 'Mais de um link encontrado no carrinho. Ajuste os produtos para usar o mesmo link.';
      } elseif (!empty($customProductLinks)) {
        $keys = array_keys($customProductLinks);
        $customProductRedirectUrl = $keys[0];
      }
    } catch (Throwable $e) {
      $customProductWarning = 'Não foi possível recuperar os links por produto.';
    }
  }

  $squareRedirectUrl = null;
  $squareWarning = null;
  $squareSelectedOption = sanitize_string(trim((string)($_POST['square_option'] ?? '')), 20);
  $squareOpenNewTab = !empty($methodSettings['open_new_tab']);
  $squareMode = $methodSettings['mode'] ?? 'square_product_link';
  $squareOptionMap = [];
  if ($methodType === 'square') {
    $squareOptionMap = array_filter([
      'credit'   => ['label' => $methodSettings['credit_label'] ?? 'Cartão de crédito', 'link' => $methodSettings['credit_link'] ?? ''],
      'debit'    => ['label' => $methodSettings['debit_label'] ?? 'Cartão de débito', 'link' => $methodSettings['debit_link'] ?? ''],
      'afterpay' => ['label' => $methodSettings['afterpay_label'] ?? 'Afterpay', 'link' => $methodSettings['afterpay_link'] ?? ''],
    ], function ($opt) {
      return !empty($opt['link']);
    });

    if ($squareOptionMap) {
      if ($squareSelectedOption === '' || empty($squareOptionMap[$squareSelectedOption])) {
        $firstKey = array_key_first($squareOptionMap);
        $squareSelectedOption = $firstKey ?: '';
      }
      if ($squareSelectedOption !== '' && !empty($squareOptionMap[$squareSelectedOption]['link'])) {
        $squareRedirectUrl = $squareOptionMap[$squareSelectedOption]['link'];
      } else {
        $squareWarning = 'Configuração do pagamento com cartão incompleta. Informe os links no painel.';
      }
    } elseif ($squareMode === 'direct_url' && !empty($methodSettings['redirect_url'])) {
      $squareRedirectUrl = $methodSettings['redirect_url'];
    } elseif ($squareMode === 'square_product_link') {
      $squareLinks = [];
      $squareMissing = [];
      foreach ($items as $itemInfo) {
        $link = $itemInfo['square_link'] ?? '';
        if ($link === '') {
          $squareMissing[] = $itemInfo['name'];
        } else {
          $squareLinks[$link] = true;
        }
      }
      if (!empty($squareMissing)) {
        $cleanNames = array_map(function($name){ return sanitize_string($name ?? '', 80); }, $squareMissing);
        $squareWarning = 'Pagamento com cartão pendente para: '.implode(', ', $cleanNames);
      } elseif (count($squareLinks) > 1) {
        $squareWarning = 'Mais de um link de cartão encontrado no carrinho. Ajuste os produtos para usar o mesmo link.';
      } elseif (!empty($squareLinks)) {
        $keys = array_keys($squareLinks);
        $squareRedirectUrl = $keys[0];
      }
    }
  }

  $stripeRedirectUrl = null;
  $stripeWarning = null;
  $stripeOpenNewTab = !empty($methodSettings['open_new_tab']);
  if ($methodType === 'stripe' && ($methodSettings['mode'] ?? 'stripe_product_link') === 'stripe_product_link') {
    $stripeLinks = [];
    $stripeMissing = [];
    foreach ($items as $itemInfo) {
      $link = $itemInfo['stripe_link'] ?? '';
      if ($link === '') {
        $stripeMissing[] = $itemInfo['name'];
      } else {
        $stripeLinks[$link] = true;
      }
    }
    if (!empty($stripeMissing)) {
      $cleanNames = array_map(function($name){ return sanitize_string($name ?? '', 80); }, $stripeMissing);
      $stripeWarning = 'Pagamento Stripe pendente para: '.implode(', ', $cleanNames);
    } elseif (count($stripeLinks) > 1) {
      $stripeWarning = 'Mais de um link Stripe encontrado no carrinho. Ajuste os produtos para usar o mesmo link.';
    } elseif (!empty($stripeLinks)) {
      $keys = array_keys($stripeLinks);
      $stripeRedirectUrl = $keys[0];
    }
  }

  $paypalRedirectUrl = null;
  $paypalWarning = null;
  $paypalOpenNewTab = !empty($methodSettings['open_new_tab']);
  if ($methodType === 'paypal') {
    $paypalMode = $methodSettings['mode'] ?? 'standard';
    if ($paypalMode === 'paypal_product_link') {
      $paypalLinks = [];
      $paypalMissing = [];
      foreach ($items as $itemInfo) {
        $link = $itemInfo['paypal_link'] ?? '';
        if ($link === '') {
          $paypalMissing[] = $itemInfo['name'];
        } else {
          $paypalLinks[$link] = true;
        }
      }
      if (!empty($paypalMissing)) {
        $cleanNames = array_map(function($name){ return sanitize_string($name ?? '', 80); }, $paypalMissing);
        $paypalWarning = 'Pagamento PayPal pendente para: '.implode(', ', $cleanNames);
      } elseif (count($paypalLinks) > 1) {
        $paypalWarning = 'Mais de um link PayPal encontrado no carrinho. Ajuste os produtos para usar o mesmo link.';
      } elseif (!empty($paypalLinks)) {
        $keys = array_keys($paypalLinks);
        $paypalRedirectUrl = $keys[0];
      }
    } elseif ($paypalMode === 'direct_url') {
      $paypalRedirectUrl = trim((string)($methodSettings['redirect_url'] ?? ''));
      if ($paypalRedirectUrl === '') {
        $paypalWarning = 'Configure um link PayPal válido ou altere o modo de pagamento.';
      }
    }
  }

  $receiptPath = null;
  $receiptError = null;
  $receiptSources = [];
  if (!empty($_FILES['payment_receipt']['name'])) {
    $receiptSources[] = $_FILES['payment_receipt'];
  }
  if (!empty($_FILES['zelle_receipt']['name'])) {
    $receiptSources[] = $_FILES['zelle_receipt'];
  }
  if ($receiptSources) {
    $destDir = $cfg['paths']['zelle_receipts'] ?? (__DIR__.'/storage/zelle_receipts');
    @mkdir($destDir, 0775, true);
    foreach ($receiptSources as $uploadInfo) {
      if (empty($uploadInfo['name'])) {
        continue;
      }
      $validation = validate_file_upload($uploadInfo, ['image/jpeg','image/png','image/webp','application/pdf'], 2 * 1024 * 1024);
      if (!$validation['success']) {
        $receiptError = $validation['message'] ?? 'Arquivo de comprovante inválido.';
        continue;
      }
      $mime = $validation['mime_type'] ?? '';
      $ext = strtolower(pathinfo((string)$uploadInfo['name'], PATHINFO_EXTENSION));
      $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf'
      ];
      if (!in_array($ext, $map, true)) {
        $ext = $map[$mime] ?? 'pdf';
      }
      $filename = 'receipt_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
      $destination = rtrim($destDir, '/\\').DIRECTORY_SEPARATOR.$filename;
      if (!@move_uploaded_file($uploadInfo['tmp_name'], $destination)) {
        $receiptError = 'Falha ao salvar o comprovante.';
        continue;
      }
      $projectRoot = realpath(__DIR__);
      $destReal = realpath($destination);
      $relative = null;
      if ($projectRoot && $destReal && strpos($destReal, $projectRoot) === 0) {
        $relative = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($destReal, strlen($projectRoot))), '/');
      }
      if (!$relative) {
        $relative = 'storage/zelle_receipts/'.$filename;
      }
      $receiptPath = $relative;
      $receiptError = null;
      break;
    }
  }

  if ($receiptError) {
    $_SESSION['checkout_error'] = $receiptError;
    header('Location: ?route=checkout');
    exit;
  }

  if (!empty($selectedMethod['require_receipt']) && !$receiptPath) {
    $_SESSION['checkout_error'] = 'Envie o comprovante de pagamento para concluir o pedido.';
    header('Location: ?route=checkout');
    exit;
  }

  $whatsappLink = '';
  $whatsappNumberDisplay = '';
  $whatsappMessageValue = '';
  if ($methodType === 'whatsapp') {
    $whatsappNumberDisplay = trim((string)($methodSettings['number'] ?? $methodSettings['account_value'] ?? ''));
    $whatsappMessageValue = trim((string)($methodSettings['message'] ?? ''));
    $whatsappLink = trim((string)($methodSettings['link'] ?? ''));
    $waDigits = preg_replace('/\D+/', '', $whatsappNumberDisplay);
    if ($waDigits !== '') {
      $whatsappLink = 'https://wa.me/'.$waDigits;
      if ($whatsappMessageValue !== '') {
        $whatsappLink .= '?text='.rawurlencode($whatsappMessageValue);
      }
    }
    if ($whatsappLink === '' && $whatsappNumberDisplay !== '') {
      $whatsappLink = $whatsappNumberDisplay;
    }
  }

  $orderCurrency = $cartCurrency;
  $payRef = '';
  switch ($methodType) {
    case 'pix':
      $pixKey = $methodSettings['pix_key'] ?? ($methodSettings['account_value'] ?? '');
      $merchantName = $methodSettings['merchant_name'] ?? $storeNameForEmails;
      $merchantCity = $methodSettings['merchant_city'] ?? 'MACEIO';
      if ($pixKey) {
        $payRef = pix_payload($pixKey, $merchantName, $merchantCity, $total);
      }
      break;
    case 'zelle':
      $payRef = $methodSettings['account_value'] ?? '';
      break;
    case 'venmo':
      $payRef = $methodSettings['venmo_link'] ?? ($methodSettings['account_value'] ?? '');
      break;
    case 'paypal':
      $paypalMode = $methodSettings['mode'] ?? 'standard';
      if ($paypalMode === 'paypal_product_link' || $paypalMode === 'direct_url') {
        $payRef = $paypalRedirectUrl ?: '';
      } else {
        $business = $methodSettings['business'] ?? '';
        $currency = $methodSettings['currency'] ?? 'USD';
        $returnUrl = $methodSettings['return_url'] ?? '';
        $cancelUrl = $methodSettings['cancel_url'] ?? '';
        $baseUrl = rtrim($cfg['store']['base_url'] ?? '', '/');
        if ($baseUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
          $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
          $baseUrl = $scheme.'://'.$_SERVER['HTTP_HOST'];
        }
        if ($returnUrl === '' && $baseUrl !== '') {
          $returnUrl = $baseUrl.'/index.php?route=order_success';
        }
        if ($cancelUrl === '' && $baseUrl !== '') {
          $cancelUrl = $baseUrl.'/index.php?route=checkout';
        }
        if ($business) {
          $payRef = 'https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business='.
                    rawurlencode($business).
                    '&currency_code='.rawurlencode($currency).
                    '&amount='.number_format($total, 2, '.', '').
                    '&item_name='.rawurlencode('Pedido '.$storeNameForEmails).'&return='.
                    rawurlencode($returnUrl).
                    '&cancel_return='.
                    rawurlencode($cancelUrl);
        }
      }
      break;
    case 'whatsapp':
      if ($whatsappLink !== '') {
        $payRef = $whatsappLink;
      } elseif ($whatsappNumberDisplay !== '') {
        $payRef = $whatsappNumberDisplay;
      } else {
        $payRef = 'WHATSAPP';
      }
      break;
    case 'square':
      if (!empty($squareOptionMap) && $squareSelectedOption !== '' && !empty($squareOptionMap[$squareSelectedOption]['label'])) {
        $payRef = 'SQUARE:'.$squareOptionMap[$squareSelectedOption]['label'];
      } else {
        if ($squareRedirectUrl) {
        $payRef = $squareRedirectUrl;
        } elseif (!empty($methodSettings['redirect_url'])) {
          $payRef = $methodSettings['redirect_url'];
        } else {
          $payRef = 'SQUARE:pendente';
        }
      }
      break;
    case 'stripe':
      $mode = $methodSettings['mode'] ?? 'stripe_product_link';
      if ($mode === 'stripe_product_link') {
        $payRef = $stripeRedirectUrl ?: 'STRIPE:pendente';
      } elseif (!empty($methodSettings['redirect_url'])) {
        $payRef = $methodSettings['redirect_url'];
      }
      break;
    default:
      if ($customMode === 'product_link') {
        $payRef = $customProductRedirectUrl ?: '';
      } else {
        $payRef = $methodSettings['redirect_url'] ?? ($methodSettings['account_value'] ?? '');
      }
      break;
  }

    if ($methodType === 'square') {
      if ($squareWarning || !$squareRedirectUrl) {
        $_SESSION['checkout_error'] = $squareWarning ?: 'Não encontramos um link de cartão configurado. Escolha outra forma de pagamento.';
        header('Location: ?route=checkout');
        exit;
      }
    }
    if ($methodType === 'stripe') {
      if ($stripeRedirectUrl === null || $stripeRedirectUrl === '' || $stripeWarning) {
        $_SESSION['checkout_error'] = $stripeWarning ?: 'Não encontramos um link Stripe para estes produtos. Escolha outra forma de pagamento.';
        header('Location: ?route=checkout');
        exit;
      }
    }
    if ($methodType === 'paypal') {
      if ($payRef === '' || $paypalWarning) {
        $_SESSION['checkout_error'] = $paypalWarning ?: 'Não encontramos um link PayPal configurado. Escolha outra forma de pagamento.';
        header('Location: ?route=checkout');
        exit;
      }
    }
    if ($customMode === 'product_link' && !in_array($methodType, ['square','stripe','paypal','pix','zelle','venmo','whatsapp'], true)) {
      if ($customProductWarning || $customProductRedirectUrl === null || $customProductRedirectUrl === '') {
        $_SESSION['checkout_error'] = $customProductWarning ?: 'Não encontramos um link configurado para estes produtos.';
        header('Location: ?route=checkout');
        exit;
      }
    }

  $deliveryMethodCodeDb = sanitize_string($deliveryMethodCode, 60);
  $deliveryMethodLabelDb = sanitize_string($deliveryMethodLabel, 120);
  $deliveryMethodDetailsDb = sanitize_string($deliveryMethodDetails, 255);

  $affiliateCode = '';
  $affiliateId = null;
  $affiliateCandidate = $_SESSION['affiliate_code'] ?? ($_COOKIE['affiliate_code'] ?? '');
  if (is_string($affiliateCandidate) && $affiliateCandidate !== '') {
    $affiliateCode = function_exists('affiliate_normalize_code')
      ? affiliate_normalize_code($affiliateCandidate)
      : strtolower(trim(preg_replace('/[^a-z0-9\-_]+/i', '-', $affiliateCandidate)));
  }
  if ($affiliateCode !== '') {
    try {
      $affStmt = $pdo->prepare("SELECT id, code FROM affiliates WHERE code = ? AND is_active = 1 LIMIT 1");
      $affStmt->execute([$affiliateCode]);
      $affRow = $affStmt->fetch(PDO::FETCH_ASSOC);
      if ($affRow) {
        $affiliateId = (int)$affRow['id'];
        $affiliateCode = (string)($affRow['code'] ?? $affiliateCode);
      } else {
        $affiliateCode = '';
      }
    } catch (Throwable $e) {
      $affiliateId = null;
      $affiliateCode = '';
    }
  }

  $customerColumnsExisting = [];
  try {
    $colsStmt = $pdo->query('SHOW COLUMNS FROM customers');
    if ($colsStmt) {
      while ($col = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        $fieldName = isset($col['Field']) ? strtolower((string)$col['Field']) : '';
        if ($fieldName !== '') {
          $customerColumnsExisting[] = $fieldName;
        }
      }
    }
  } catch (Throwable $e) {
    $customerColumnsExisting = [];
  }
  $customerColumnsLookup = $customerColumnsExisting ? array_flip($customerColumnsExisting) : [];
  $hasCustomerMetadata = !empty($customerColumnsLookup);

  $customerColumnsToInsert = [];
  $customerValuesToInsert = [];
  $customerFieldMap = [
    ['first_name', $firstName, true],
    ['last_name', $lastName, true],
    ['name', $fullName, false],
    ['email', $email, false],
    ['phone', $phone, false],
    ['address', $address1, false],
    ['address2', $address2, true],
    ['city', $city, false],
    ['state', $state, false],
    ['zipcode', $zipcode, false],
    ['country', $country, true],
  ];
  foreach ($customerFieldMap as $entry) {
    [$columnName, $columnValue, $optional] = $entry;
    if (!$optional) {
      $customerColumnsToInsert[] = $columnName;
      $customerValuesToInsert[] = $columnValue;
      continue;
    }
    if ($hasCustomerMetadata && isset($customerColumnsLookup[$columnName])) {
      $customerColumnsToInsert[] = $columnName;
      $customerValuesToInsert[] = $columnValue;
    }
  }
  if (!in_array('name', $customerColumnsToInsert, true)) {
    $customerColumnsToInsert[] = 'name';
    $customerValuesToInsert[] = $fullName;
  }
  if (!$customerColumnsToInsert) {
    $customerColumnsToInsert = ['name', 'email', 'phone'];
    $customerValuesToInsert = [$fullName, $email, $phone];
  }

  try {
    $pdo->beginTransaction();
    // cliente
    $customerPlaceholders = implode(',', array_fill(0, count($customerColumnsToInsert), '?'));
    $customerSql = 'INSERT INTO customers('.implode(',', $customerColumnsToInsert).') VALUES('.$customerPlaceholders.')';
    $cst = $pdo->prepare($customerSql);
    $cst->execute($customerValuesToInsert);
    $customer_id = (int)$pdo->lastInsertId();

    // pedido
    $hasTrack = false;
    try {
      $chk = $pdo->query("SHOW COLUMNS FROM orders LIKE 'track_token'");
      $hasTrack = (bool)($chk && $chk->fetch());
    } catch (Throwable $e) { $hasTrack = false; }

    $hasDeliveryCols = false;
    try {
      $chkDelivery = $pdo->query("SHOW COLUMNS FROM orders LIKE 'delivery_method_code'");
      $hasDeliveryCols = (bool)($chkDelivery && $chkDelivery->fetch());
    } catch (Throwable $e) { $hasDeliveryCols = false; }

    $hasSecureDeliveryCols = false;
    try {
      $chkSecure = $pdo->query("SHOW COLUMNS FROM orders LIKE 'secure_delivery'");
      $hasSecureDeliveryCols = (bool)($chkSecure && $chkSecure->fetch());
    } catch (Throwable $e) { $hasSecureDeliveryCols = false; }

    $hasAffiliateId = false;
    $hasAffiliateCode = false;
    try {
      $chkAffiliate = $pdo->query("SHOW COLUMNS FROM orders LIKE 'affiliate_id'");
      $hasAffiliateId = (bool)($chkAffiliate && $chkAffiliate->fetch());
    } catch (Throwable $e) { $hasAffiliateId = false; }
    try {
      $chkAffiliateCode = $pdo->query("SHOW COLUMNS FROM orders LIKE 'affiliate_code'");
      $hasAffiliateCode = (bool)($chkAffiliateCode && $chkAffiliateCode->fetch());
    } catch (Throwable $e) { $hasAffiliateCode = false; }

    $orderColumns = ['customer_id','items_json','subtotal','shipping_cost','total','currency','payment_method','payment_ref','status','zelle_receipt','order_origin'];
    $orderValues = [
      $customer_id,
      json_encode($items, JSON_UNESCAPED_UNICODE),
      $subtotal,
      $shipping,
      $total,
      $orderCurrency,
      $payment_method,
      $payRef,
      'pending',
      $receiptPath,
      'nova'
    ];

    if ($hasDeliveryCols) {
      $orderColumns[] = 'delivery_method_code';
      $orderColumns[] = 'delivery_method_label';
      $orderColumns[] = 'delivery_method_details';
      $orderValues[] = $deliveryMethodCodeDb;
      $orderValues[] = $deliveryMethodLabelDb;
      $orderValues[] = $deliveryMethodDetailsDb;
    }
    if ($hasSecureDeliveryCols) {
      $orderColumns[] = 'secure_delivery';
      $orderColumns[] = 'secure_delivery_fee';
      $orderValues[] = $secureDeliverySelected ? 1 : 0;
      $orderValues[] = $secureDeliveryFee;
    }

    if ($hasTrack) {
      $orderColumns[] = 'track_token';
      $track = bin2hex(random_bytes(16));
      $orderValues[] = $track;
    }

    if ($affiliateId && $hasAffiliateId) {
      $orderColumns[] = 'affiliate_id';
      $orderValues[] = $affiliateId;
    }
    if ($affiliateCode !== '' && $hasAffiliateCode) {
      $orderColumns[] = 'affiliate_code';
      $orderValues[] = $affiliateCode;
    }

    $placeholders = implode(',', array_fill(0, count($orderColumns), '?'));
    $ordersSql = 'INSERT INTO orders('.implode(',', $orderColumns).') VALUES('.$placeholders.')';
    $o = $pdo->prepare($ordersSql);
    $o->execute($orderValues);

    $order_id = (int)$pdo->lastInsertId();
    $orderCode = sprintf('GT%04d', $order_id);
    $updCode = $pdo->prepare("UPDATE orders SET order_code = ? WHERE id = ?");
    $updCode->execute([$orderCode, $order_id]);
    $pdo->commit();

    if (!isset($_SESSION['checkout_tokens_used']) || !is_array($_SESSION['checkout_tokens_used'])) {
      $_SESSION['checkout_tokens_used'] = [];
    }
    $_SESSION['checkout_tokens_used'][$checkoutToken] = ['order_id' => $order_id, 'ts' => time()];
    if (count($_SESSION['checkout_tokens_used']) > 20) {
      uasort($_SESSION['checkout_tokens_used'], function ($a, $b) {
        return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
      });
      $_SESSION['checkout_tokens_used'] = array_slice($_SESSION['checkout_tokens_used'], -20, null, true);
    }
    if (isset($_SESSION['checkout_tokens_valid'][$checkoutToken])) {
      unset($_SESSION['checkout_tokens_valid'][$checkoutToken]);
    }

    send_notification("new_order","Novo Pedido","Pedido #$orderCode de ".sanitize_html($fullName),["order_id"=>$order_id,"order_code"=>$orderCode,"total"=>$total,"payment_method"=>$payment_method]);
    $_SESSION["cart"] = [];
    unset($_SESSION['cart_currency']);
    send_order_confirmation($order_id, $email);
    send_order_admin_alert($order_id);
    send_order_whatsapp_alerts($order_id, $whatsappOptIn, true);
  if ($methodType === 'paypal') {
      $_SESSION['paypal_redirect_url'] = $payRef;
      $_SESSION['paypal_open_new_tab'] = 0;
    } else {
      unset($_SESSION['paypal_redirect_url'], $_SESSION['paypal_open_new_tab']);
    }
    if ($methodType === 'square') {
      $_SESSION['square_redirect_url'] = $squareRedirectUrl;
      $_SESSION['square_redirect_warning'] = $squareWarning;
      $_SESSION['square_open_new_tab'] = $squareOpenNewTab ? 1 : 0;
      if (!empty($squareOptionMap) && $squareSelectedOption !== '' && !empty($squareOptionMap[$squareSelectedOption]['label'])) {
        $_SESSION['square_option_label'] = sanitize_string($squareOptionMap[$squareSelectedOption]['label'], 80);
      } else {
        $_SESSION['square_option_label'] = '';
      }
    } else {
      unset($_SESSION['square_redirect_url'], $_SESSION['square_redirect_warning'], $_SESSION['square_open_new_tab'], $_SESSION['square_option_label']);
    }
    if ($methodType === 'stripe') {
      $_SESSION['stripe_redirect_url'] = $stripeRedirectUrl;
      $_SESSION['stripe_redirect_warning'] = $stripeWarning;
      $_SESSION['stripe_open_new_tab'] = $stripeOpenNewTab ? 1 : 0;
    } else {
      unset($_SESSION['stripe_redirect_url'], $_SESSION['stripe_redirect_warning'], $_SESSION['stripe_open_new_tab']);
    }
    if ($methodType === 'whatsapp') {
      $_SESSION['whatsapp_link'] = $whatsappLink;
      $_SESSION['whatsapp_number'] = $whatsappNumberDisplay;
      $_SESSION['whatsapp_message'] = $whatsappMessageValue;
    } else {
      unset($_SESSION['whatsapp_link'], $_SESSION['whatsapp_number'], $_SESSION['whatsapp_message']);
    }

    header("Location: ?route=order_success&id=".$order_id);
    exit;

  } catch (Throwable $e) {
    $pdo->rollBack();
    die("Erro ao processar pedido: ".$e->getMessage());
  }
}


// ORDER SUCCESS
if ($route === 'order_success') {
  $order_id = (int)($_GET['id'] ?? 0);
  if (!$order_id) { header('Location: ?route=home'); exit; }
  app_header();

  // fetch tracking token (safe)
  $track_code = '';
  $orderCodeSuccess = ''; 
  try {
    $pdo = db();
    $row = $pdo->query("SELECT track_token, order_code FROM orders WHERE id=".(int)$order_id)->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $track_code = (string)($row['track_token'] ?? '');
      $orderCodeSuccess = $row['order_code'] ?? '';
    }
  } catch (Throwable $e) {}
  if ($orderCodeSuccess === '') {
    $orderCodeSuccess = sprintf('GT%04d', $order_id);
  }
  $squareRedirectSession = $_SESSION['square_redirect_url'] ?? null;
  $squareWarningSession = $_SESSION['square_redirect_warning'] ?? null;
  $squareOpenNewTabSession = !empty($_SESSION['square_open_new_tab']);
  $squareOptionLabelSession = $_SESSION['square_option_label'] ?? '';
  $paypalRedirectSession = $_SESSION['paypal_redirect_url'] ?? null;
  $paypalOpenNewTabSession = !empty($_SESSION['paypal_open_new_tab']);
  $stripeRedirectSession = $_SESSION['stripe_redirect_url'] ?? null;
  $stripeWarningSession = $_SESSION['stripe_redirect_warning'] ?? null;
  $stripeOpenNewTabSession = !empty($_SESSION['stripe_open_new_tab']);
  $whatsappLinkSession = $_SESSION['whatsapp_link'] ?? null;
  $whatsappNumberSession = $_SESSION['whatsapp_number'] ?? null;
  $whatsappMessageSession = $_SESSION['whatsapp_message'] ?? null;
  unset($_SESSION['square_redirect_url'], $_SESSION['square_redirect_warning'], $_SESSION['square_open_new_tab'], $_SESSION['square_option_label'], $_SESSION['paypal_redirect_url'], $_SESSION['paypal_open_new_tab'], $_SESSION['stripe_redirect_url'], $_SESSION['stripe_redirect_warning'], $_SESSION['stripe_open_new_tab'], $_SESSION['whatsapp_link'], $_SESSION['whatsapp_number'], $_SESSION['whatsapp_message']);

  echo '<section class="max-w-3xl mx-auto px-4 py-16 text-center">';
  echo '  <div class="bg-white rounded-2xl shadow p-8">';
  echo '    <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4"><i class="fa-solid fa-check text-2xl"></i></div>';
  echo '    <h2 class="text-2xl font-bold mb-2">'.htmlspecialchars($d["thank_you_order"] ?? "Obrigado pelo seu pedido!").'</h2>';
  $orderCodeSuccess = sprintf('GT%04d', $order_id);
  echo '    <p class="text-gray-600 mb-2">Pedido '.$orderCodeSuccess.' recebido. Enviamos um e-mail com os detalhes.</p>';
  if (!empty($squareWarningSession)) {
    echo '    <div class="mt-4 p-4 rounded-lg border border-amber-200 bg-amber-50 text-amber-800"><i class="fa-solid fa-triangle-exclamation mr-2"></i>'.htmlspecialchars($squareWarningSession, ENT_QUOTES, "UTF-8").'</div>';
  }
  if (!empty($squareRedirectSession)) {
    $safeSquare = htmlspecialchars($squareRedirectSession, ENT_QUOTES, "UTF-8");
    $squareJs = json_encode($squareRedirectSession, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $squareLabelNote = $squareOptionLabelSession ? ' ('.$squareOptionLabelSession.')' : '';
    echo '    <div class="mt-4 p-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800">';
    echo '      <i class="fa-solid fa-arrow-up-right-from-square mr-2"></i> Redirecionaremos você para o pagamento com cartão'.$squareLabelNote.'. Caso não abra automaticamente, <a class="underline" id="squareManualLink" href="'.$safeSquare.'" target="_blank" rel="noopener">clique aqui</a>.';
    echo '    </div>';
    echo '    <script>
      window.addEventListener("load", function(){
        const redirectKey = "square_redirect_'.$order_id.'";
        if (window.sessionStorage.getItem(redirectKey)) {
          return;
        }
        window.sessionStorage.setItem(redirectKey, "1");
        const checkoutUrl = '.$squareJs.';
        const isMobile = window.matchMedia("(max-width: 768px)").matches;
        const openInNewTabConfigured = '.($squareOpenNewTabSession ? 'true' : 'false').';
        const openInNewTab = isMobile ? false : openInNewTabConfigured;
        const popupFeatures = "noopener=yes,noreferrer=yes,width=920,height=860,left=120,top=60,resizable=yes,scrollbars=yes";
        const storageKey = "square_checkout_target";
        const origin = window.location.origin || (window.location.protocol + "//" + window.location.host);
        const loadingUrl = document.body.getAttribute("data-square-loading-url") || "square-loading.html";
        const targetLoaderUrl = loadingUrl + (loadingUrl.includes("?") ? "&" : "?") + "target=" + encodeURIComponent(checkoutUrl);

        let opened = false;
        let storagePayload = null;
        let placeholderWindow = null;

        try {
          storagePayload = JSON.stringify({ url: checkoutUrl, ts: Date.now() });
          localStorage.setItem(storageKey, storagePayload);
          setTimeout(function(){
            try { localStorage.removeItem(storageKey); } catch (err) {}
          }, 180000);
        } catch (err) {
          storagePayload = null;
        }

        if (!isMobile) {
          try {
            const existingPopup = window.open("", "squareCheckout", popupFeatures);
            if (existingPopup) {
              placeholderWindow = existingPopup;
              if (storagePayload) {
                try {
                  existingPopup.postMessage({ type: "square_checkout_url", payload: storagePayload }, origin);
                } catch (err) {}
              }
              try {
                existingPopup.location.replace(checkoutUrl);
                opened = true;
              } catch (err) {}
              try { existingPopup.focus(); } catch (err) {}
            }
          } catch (err) {}
        }

        if (!opened) {
          if (openInNewTab) {
            try {
              const popup = window.open(checkoutUrl, "squareCheckout", popupFeatures);
              if (popup) {
                placeholderWindow = popup;
                try { popup.focus(); } catch (err) {}
                opened = true;
              }
            } catch (err) {
              opened = false;
            }
          } else {
            if (placeholderWindow) {
              try { placeholderWindow.close(); } catch (err) {}
              placeholderWindow = null;
            }
            try {
              window.location.assign(targetLoaderUrl);
              opened = true;
            } catch (err) {
              try {
                window.location.assign(checkoutUrl);
                opened = true;
              } catch (err2) {
                opened = false;
              }
            }
          }
        }

        // Fallback: botão manual
        if (!opened) {
          const manualLink = document.getElementById("squareManualLink");
          if (manualLink) {
            manualLink.textContent = "Abrir checkout do cartão";
            manualLink.classList.add("font-semibold");
          }
        }

        try { window.sessionStorage.removeItem("square_checkout_pending"); } catch (err) {}
      });
    </script>';
  }
  if (!empty($whatsappLinkSession) || !empty($whatsappNumberSession) || !empty($whatsappMessageSession)) {
    $safeWaLink = $whatsappLinkSession ? htmlspecialchars($whatsappLinkSession, ENT_QUOTES, 'UTF-8') : '';
    $safeWaNumber = $whatsappNumberSession ? htmlspecialchars($whatsappNumberSession, ENT_QUOTES, 'UTF-8') : '';
    $safeWaMessage = $whatsappMessageSession ? htmlspecialchars($whatsappMessageSession, ENT_QUOTES, 'UTF-8') : '';
    echo '    <div class="mt-4 p-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800">';
    echo '      <div class="font-semibold text-sm mb-1"><i class="fa-brands fa-whatsapp mr-2"></i>Finalize pelo WhatsApp</div>';
    if ($safeWaNumber !== '') {
      echo '      <div class="text-sm">Número: '.$safeWaNumber.'</div>';
    }
    if ($safeWaMessage !== '') {
      echo '      <div class="text-xs text-emerald-900/80 mt-1">Mensagem sugerida: '.$safeWaMessage.'</div>';
    }
    if ($safeWaLink !== '') {
      echo '      <div class="mt-2"><a class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold shadow hover:bg-emerald-700 transition" href="'.$safeWaLink.'" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Abrir conversa</a></div>';
    }
    echo '    </div>';
  }
  if (!empty($paypalRedirectSession)) {
    $safePaypal = htmlspecialchars($paypalRedirectSession, ENT_QUOTES, "UTF-8");
    $paypalJs = json_encode($paypalRedirectSession, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo '    <div class="mt-4 p-4 rounded-lg border border-blue-200 bg-blue-50 text-blue-800">';
    echo '      <i class="fa-brands fa-paypal mr-2"></i> Redirecionando para o pagamento PayPal... Caso não abra automaticamente, <a class="underline" href="'.$safePaypal.'" target="_blank" rel="noopener">clique aqui</a>.';
    echo '    </div>';
    echo '    <script>
      window.addEventListener("load", function(){
        const key = "paypal_redirect_'.$order_id.'";
        if (!window.sessionStorage.getItem(key)) {
          window.sessionStorage.setItem(key, "1");
          window.location.href = '.$paypalJs.';
        }
      });
    </script>';
  }
  if (!empty($stripeWarningSession)) {
    echo '    <div class="mt-4 p-4 rounded-lg border border-amber-200 bg-amber-50 text-amber-800"><i class="fa-solid fa-triangle-exclamation mr-2"></i>'.htmlspecialchars($stripeWarningSession, ENT_QUOTES, "UTF-8").'</div>';
  }
  if (!empty($stripeRedirectSession)) {
    $safeStripe = htmlspecialchars($stripeRedirectSession, ENT_QUOTES, "UTF-8");
    $stripeJs = json_encode($stripeRedirectSession, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    echo '    <div class="mt-4 p-4 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-800">';
    echo '      <i class="fa-brands fa-cc-stripe mr-2"></i> Redirecionando para o pagamento Stripe... Caso não avance automaticamente, <a class="underline" href="'.$safeStripe.'">clique aqui</a>.';
    echo '    </div>';
    echo '    <script>
      window.addEventListener("load", function(){
        const key = "stripe_redirect_'.$order_id.'";
        if (!window.sessionStorage.getItem(key)) {
          window.sessionStorage.setItem(key, "1");
          window.location.href = '.$stripeJs.';
        }
      });
    </script>';
  }
  if ($track_code !== '') {
    echo '    <p class="mb-6">Acompanhe seu pedido: <a class="text-brand-600 underline" href="?route=track&code='.htmlspecialchars($track_code, ENT_QUOTES, "UTF-8").'">clique aqui</a></p>';
  }
  echo '    <a href="?route=home" class="px-6 py-3 rounded-lg bg-brand-600 text-white hover:bg-brand-700">Voltar à loja</a>';
  echo '  </div>';
  echo '</section>';

  app_footer();
  exit;
}





// TRACK ORDER (public)
if ($route === 'track') {
  $code = isset($_GET['code']) ? (string)$_GET['code'] : '';
  app_header();
  echo '<section class="container mx-auto p-6">';
  echo '<div class="max-w-2xl mx-auto bg-white rounded-xl shadow p-6">';
  echo '<h2 class="text-2xl font-bold mb-4">Acompanhar Pedido</h2>';
  if ($code === '') {
    echo '<p class="text-gray-600">Código inválido.</p>';
  } else {
    try {
      $pdo = db();
      $st = $pdo->prepare("SELECT id, status, created_at, total FROM orders WHERE track_token = ?");
      $st->execute([substr($code, 0, 64)]);
      $ord = $st->fetch(PDO::FETCH_ASSOC);
      if (!$ord) {
        echo '<p class="text-gray-600">Pedido não encontrado.</p>';
      } else {
        $id     = (int)($ord['id'] ?? 0);
        $status = htmlspecialchars((string)($ord['status'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $total  = format_currency((float)($ord['total'] ?? 0), (cfg()['store']['currency'] ?? 'USD'));
        $created= htmlspecialchars((string)($ord['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');

        echo '<p class="mb-2">Pedido #'.strval($id).'</p>';
        echo '<p class="mb-2">Status: <span class="font-semibold">'.$status.'</span></p>';
        echo '<p class="mb-2">Total: <span class="font-semibold">'.$total.'</span></p>';
        echo '<p class="text-sm text-gray-500">Criado em: '.$created.'</p>';
      }
    } catch (Throwable $e) {
      echo '<p class="text-red-600">Erro: '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>';
    }
  }
  echo '</div></section>';
  app_footer();
  exit;
}

if ($route === 'product') {
  $pdo = db();
  $productId = (int)($_GET['id'] ?? 0);
  $slugParam = trim((string)($_GET['slug'] ?? ''));

  if ($productId <= 0 && $slugParam === '') {
    header('Location: ?route=home');
    exit;
  }

  if ($slugParam !== '') {
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.id AS category_id,
                                  d.short_description, d.detailed_description, d.specs_json,
                                  d.additional_info, d.payment_conditions, d.delivery_info,
                                  d.media_gallery, d.video_url
                           FROM products p
                           LEFT JOIN categories c ON c.id = p.category_id
                           LEFT JOIN product_details d ON d.product_id = p.id
                           WHERE p.slug = ? AND p.active = 1
                           LIMIT 1");
    $stmt->execute([$slugParam]);
  } else {
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.id AS category_id,
                                  d.short_description, d.detailed_description, d.specs_json,
                                  d.additional_info, d.payment_conditions, d.delivery_info,
                                  d.media_gallery, d.video_url
                           FROM products p
                           LEFT JOIN categories c ON c.id = p.category_id
                           LEFT JOIN product_details d ON d.product_id = p.id
                           WHERE p.id = ? AND p.active = 1
                           LIMIT 1");
    $stmt->execute([$productId]);
  }

  $product = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$product) {
    app_header();
    echo '<section class="max-w-xl mx-auto px-4 py-24 text-center">';
    echo '  <div class="text-6xl text-gray-300 mb-4"><i class="fa-solid fa-box-open"></i></div>';
    echo '  <h1 class="text-2xl font-semibold mb-2">Produto não encontrado</h1>';
    echo '  <p class="text-gray-500 mb-6">O item que você tentou acessar não está disponível ou foi retirado de nossa vitrine.</p>';
    echo '  <a class="px-6 py-3 rounded-lg bg-brand-600 text-white hover:bg-brand-700" href="?route=home">Voltar à loja</a>';
    echo '</section>';
    app_footer();
    exit;
  }

  $productId = (int)$product['id'];
  $productName = $product['name'] ?? 'Produto';
  $storeNameMeta = setting_get('store_name', $cfg['store']['name'] ?? 'Get Power Research');
  $metaTitleOverride = $product['meta_title'] ?? '';
  $metaDescOverride = $product['meta_description'] ?? '';
  $GLOBALS['app_meta_title'] = $metaTitleOverride !== '' ? $metaTitleOverride : ($productName.' | '.$storeNameMeta);
  $GLOBALS['app_meta_description'] = $metaDescOverride !== '' ? $metaDescOverride : mb_substr(strip_tags($product['short_description'] ?? $product['description'] ?? ''), 0, 150);

  $shortDescription = trim((string)($product['short_description'] ?? ''));
  if ($shortDescription === '') {
    $shortDescription = mb_substr(strip_tags($product['description'] ?? ''), 0, 220);
  }
  $detailedDescription = trim((string)($product['detailed_description'] ?? $product['description'] ?? ''));
  $detailedDescription = sanitize_builder_output($detailedDescription);
  $shortFallback = trim((string)$shortDescription) !== '' ? sanitize_html($shortDescription) : 'alívio de sintomas e suporte ao bem-estar diário';
  if ($detailedDescription === '') {
    $productNameSafe = sanitize_html($productName);
    $detailedDescription = <<<HTML
<p><strong>{$productNameSafe}</strong> é um medicamento formulado para oferecer cuidado consistente no dia a dia. Sua função principal é ajudar no {$shortFallback}, contribuindo para uma rotina mais equilibrada.</p>
<p>Mecanismo de ação: atua de forma direcionada para favorecer o equilíbrio do organismo, auxiliando na recuperação e na redução de desconfortos ao longo do tempo.</p>
<p>Sintomas que pode ajudar a aliviar: tensão leve, fadiga cotidiana, incômodos recorrentes e desconfortos associados à rotina.</p>
<p>Modo de uso recomendado: siga a orientação do seu médico ou farmacêutico. Geralmente recomenda-se uso em horários regulares, com água, sem exceder a dose indicada.</p>
<p>Cuidados necessários: mantenha o produto fora do alcance de crianças, armazene em local fresco e protegido da luz, e não utilize em caso de alergia conhecida aos componentes. Em sintomas persistentes, procure orientação médica.</p>
HTML;
  }

  $specs = [];
  if (!empty($product['specs_json'])) {
    $decodedSpecs = json_decode($product['specs_json'], true);
    if (is_array($decodedSpecs)) {
      foreach ($decodedSpecs as $entry) {
        if (!isset($entry['label']) && isset($entry['key'])) {
          $entry['label'] = $entry['key'];
        }
        $label = trim((string)($entry['label'] ?? ''));
        $value = trim((string)($entry['value'] ?? ''));
        if ($label !== '' && $value !== '') {
          $specs[] = [
            'label' => htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
            'value' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
          ];
        }
      }
    }
  }

  $additionalInfo = trim((string)($product['additional_info'] ?? ''));
  if ($additionalInfo !== '') {
    $additionalInfo = sanitize_builder_output($additionalInfo);
  }

  $paymentConditions = trim((string)($product['payment_conditions'] ?? ''));
  $deliveryInfo = trim((string)($product['delivery_info'] ?? ''));
  $videoUrl = trim((string)($product['video_url'] ?? ''));

  // Garante a foto principal primeiro e, na sequência, as imagens da galeria
  $galleryImages = [];
  if (!empty($product['image_path'])) {
    $galleryImages[] = proxy_img($product['image_path']);
  }
  if (!empty($product['media_gallery'])) {
    $decodedGallery = json_decode($product['media_gallery'], true);
    if (is_array($decodedGallery)) {
      foreach ($decodedGallery as $entry) {
        $path = is_array($entry) ? ($entry['path'] ?? '') : $entry;
        $path = trim((string)$path);
        if ($path !== '') {
          $galleryImages[] = proxy_img($path);
        }
      }
    }
  }
  $galleryImages = array_values(array_unique(array_filter($galleryImages)));
  $galleryImages = array_slice($galleryImages, 0, 4);

  $currencyCode = strtoupper($product['currency'] ?? ($cfg['store']['currency'] ?? 'USD'));
  $priceValue = (float)($product['price'] ?? 0);
  $priceFormatted = format_currency($priceValue, $currencyCode);
  $compareValue = isset($product['price_compare']) ? (float)$product['price_compare'] : null;
  $compareFormatted = ($compareValue && $compareValue > $priceValue) ? format_currency($compareValue, $currencyCode) : '';
  $discountPercent = ($compareValue && $compareValue > $priceValue) ? max(1, round(100 - ($priceValue / $compareValue * 100))) : null;

  $shippingCost = (float)($product['shipping_cost'] ?? 0);
  $shippingFormatted = format_currency($shippingCost, $currencyCode);

  $stock = (int)($product['stock'] ?? 0);
  $inStock = $stock > 0;

  $categoryName = $product['category_name'] ?? '';
  $categoryId = (int)($product['category_id'] ?? 0);

  $relatedStmt = $pdo->prepare("SELECT p.id, p.slug, p.name, p.price, p.price_compare, p.image_path, p.badge_one_month
                                FROM products p
                                WHERE p.active = 1 AND p.id <> ? AND p.category_id = ?
                                ORDER BY p.featured DESC, p.updated_at DESC
                                LIMIT 4");
  $relatedStmt->execute([$productId, $categoryId]);
  $relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$relatedProducts) {
    $relatedStmt = $pdo->prepare("SELECT p.id, p.slug, p.name, p.price, p.price_compare, p.image_path, p.badge_one_month
                                  FROM products p
                                  WHERE p.active = 1 AND p.id <> ?
                                  ORDER BY p.featured DESC, p.updated_at DESC
                                  LIMIT 4");
    $relatedStmt->execute([$productId]);
    $relatedProducts = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
  }

  app_header();

  echo '<section class="max-w-6xl mx-auto px-4 py-10 space-y-12">';

  echo '  <div class="grid lg:grid-cols-2 gap-10">';
  echo '    <div class="space-y-6">';
  if ($galleryImages) {
    $mainImage = htmlspecialchars($galleryImages[0], ENT_QUOTES, 'UTF-8');
    echo '      <div class="relative bg-white rounded-3xl shadow overflow-hidden">';
    echo '        <div class="aspect-square w-full bg-gray-50 flex items-center justify-center">';
    echo '          <img id="product-main-image" src="'.$mainImage.'" alt="'.htmlspecialchars($productName, ENT_QUOTES, 'UTF-8').'" class="max-h-full max-w-full object-contain transition-transform duration-300">';
    echo '        </div>';
    if (!empty($product['badge_one_month'])) {
      echo '        <div class="product-ribbon">Tratamento para 1 mês</div>';
    }
    echo '      </div>';
    if (count($galleryImages) > 1) {
      echo '      <div class="flex gap-3 flex-wrap">';
      foreach ($galleryImages as $idx => $imgPath) {
        $thumb = htmlspecialchars($imgPath, ENT_QUOTES, 'UTF-8');
        $isActive = $idx === 0 ? 'border-brand-600 ring-2 ring-brand-200' : 'border-transparent';
        echo '        <button type="button" class="w-20 h-20 rounded-xl border '.$isActive.' overflow-hidden bg-white shadow-sm hover:border-brand-400" data-gallery-image="'.$thumb.'">';
        echo '          <img src="'.$thumb.'" alt="thumb" class="w-full h-full object-cover">';
        echo '        </button>';
      }
      echo '      </div>';
    }
  }
  if ($videoUrl !== '') {
    $safeVideo = htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8');
    echo '      <div class="bg-white rounded-2xl shadow p-4">';
    echo '        <h3 class="font-semibold mb-3 flex items-center gap-2"><i class="fa-solid fa-circle-play text-brand-600"></i> Vídeo demonstrativo</h3>';
    if (strpos($videoUrl, 'youtube.com') !== false || strpos($videoUrl, 'youtu.be') !== false) {
      if (preg_match('~(youtu\\.be/|v=)([\\w-]+)~', $videoUrl, $match)) {
        $videoId = $match[2];
        echo '        <div class="relative aspect-video rounded-xl overflow-hidden">';
        echo '          <iframe class="w-full h-full" src="https://www.youtube.com/embed/'.htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8').'" frameborder="0" allowfullscreen></iframe>';
        echo '        </div>';
      } else {
        echo '        <a class="text-brand-600 underline" href="'.$safeVideo.'" target="_blank" rel="noopener">Assistir vídeo</a>';
      }
    } else {
      echo '        <a class="text-brand-600 underline" href="'.$safeVideo.'" target="_blank" rel="noopener">Assistir vídeo</a>';
    }
    echo '      </div>';
  }
  echo '    </div>';

  echo '    <div class="space-y-6">';
  if ($categoryName) {
    echo '      <div class="text-sm text-brand-600 uppercase font-semibold">'.htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8').'</div>';
  }
  echo '      <h1 class="text-3xl md:text-4xl font-bold text-gray-900">'.htmlspecialchars($productName, ENT_QUOTES, 'UTF-8').'</h1>';
  if ($shortDescription) {
    echo '      <p class="text-gray-600">'.$shortDescription.'</p>';
  }

  echo '      <div class="bg-white rounded-2xl shadow p-5 space-y-3">';
  echo '        <div class="flex items-center gap-3 flex-wrap">';
  if ($compareFormatted) {
    echo '          <span class="text-sm text-gray-400 line-through">De '.$compareFormatted.'</span>';
  }
  echo '          <span class="text-3xl font-bold text-brand-700">'.$priceFormatted.'</span>';
  if ($discountPercent) {
    echo '          <span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-sm font-semibold">'.$discountPercent.'% OFF</span>';
  }
  echo '        </div>';
  echo '        <div class="text-sm text-gray-600">Pagamento: '.($paymentConditions !== '' ? htmlspecialchars($paymentConditions, ENT_QUOTES, 'UTF-8') : 'Ver opções no checkout.').'</div>';
  echo '        <div class="text-sm text-gray-600">Frete padrão: <strong>'.$shippingFormatted.'</strong></div>';
  echo '        <div class="flex items-center gap-2 text-sm">';
  if ($inStock) {
    $stockLabel = $stock <= 5 ? 'Poucas unidades restantes' : 'Em estoque';
    echo '          <span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-700"><i class="fa-solid fa-circle-check mr-1"></i> '.$stockLabel.'</span>';
    echo '          <span class="text-gray-500">Estoque: '.$stock.' unidade(s)</span>';
  } else {
    echo '          <span class="px-3 py-1 rounded-full bg-rose-100 text-rose-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i> Esgotado</span>';
  }
  echo '        </div>';
  echo '      </div>';

  echo '      <div class="bg-white rounded-2xl shadow p-5 space-y-4">';
  echo '        <div class="flex items-center gap-3">';
  echo '          <div class="text-sm font-semibold">Quantidade</div>';
  echo '          <div class="flex items-center border rounded-full overflow-hidden">';
  echo '            <button type="button" class="w-10 h-10 flex items-center justify-center text-lg text-gray-600" id="qtyDecrease"><i class="fa-solid fa-minus"></i></button>';
  echo '            <input type="number" min="1" value="1" id="quantityInput" class="w-16 text-center border-x border-gray-200 focus:outline-none" />';
  echo '            <button type="button" class="w-10 h-10 flex items-center justify-center text-lg text-gray-600" id="qtyIncrease"><i class="fa-solid fa-plus"></i></button>';
  echo '          </div>';
  echo '        </div>';
  if ($inStock) {
    echo '        <div class="grid sm:grid-cols-2 gap-3">';
    echo '          <button type="button" class="px-5 py-3 rounded-xl bg-brand-600 text-white hover:bg-brand-700 text-sm font-semibold flex items-center justify-center gap-2" id="btnBuyNow"><i class="fa-solid fa-cart-shopping"></i> Adicionar ao carrinho</button>';
    echo '          <button type="button" class="px-5 py-3 rounded-xl border border-brand-200 text-brand-700 hover:bg-brand-50 text-sm font-semibold flex items-center justify-center gap-2" id="btnBuyNowGo"><i class="fa-solid fa-flash"></i> Comprar agora</button>';
    echo '        </div>';
  } else {
    echo '        <div class="px-5 py-3 rounded-xl bg-gray-200 text-gray-500 text-center font-semibold">Avise-me quando disponível</div>';
  }
  echo '      </div>';

  echo '      <div class="bg-white rounded-2xl shadow p-5 space-y-4">';
  echo '        <h3 class="font-semibold text-gray-800 flex items-center gap-2"><i class="fa-solid fa-truck-fast text-brand-600"></i> Estimativa de frete</h3>';
  echo '        <p class="text-sm text-gray-500">Informe seu CEP para estimar o prazo de entrega. O valor base é configurado no painel administrativo.</p>';
  echo '        <div class="flex gap-3 flex-col sm:flex-row">';
  echo '          <input type="text" maxlength="9" placeholder="00000-000" id="cepInput" class="flex-1 px-4 py-3 border rounded-lg" />';
  echo '          <button type="button" class="px-5 py-3 rounded-lg bg-gray-900 text-white hover:bg-gray-800" id="calcFreightBtn">Calcular frete</button>';
  echo '        </div>';
  echo '        <div id="freightResult" class="text-sm text-gray-600 hidden"></div>';
  echo '      </div>';

  echo '      <div class="bg-white rounded-2xl shadow p-5 space-y-3">';
  echo '        <h3 class="font-semibold text-gray-800 flex items-center gap-2"><i class="fa-solid fa-shield-check text-brand-600"></i> Compra segura</h3>';
  echo '        <ul class="text-sm text-gray-600 space-y-2">';
  echo '          <li><i class="fa-solid fa-lock text-brand-600 mr-2"></i> Pagamento criptografado e seguro</li>';
  echo '          <li><i class="fa-solid fa-arrow-rotate-left text-brand-600 mr-2"></i> Troca e devolução garantidas (consulte nossas políticas)</li>';
  echo '          <li><i class="fa-solid fa-headset text-brand-600 mr-2"></i> Suporte ao cliente dedicado via WhatsApp</li>';
  echo '        </ul>';
  echo '        <div class="text-xs text-gray-500 space-x-3 pt-2">';
  echo '          <a class="underline hover:text-brand-600" href="?route=privacy" target="_blank">Política de privacidade</a>';
  echo '          <a class="underline hover:text-brand-600" href="?route=refund" target="_blank">Política de reembolso</a>';
  echo '        </div>';
  echo '      </div>';
  echo '    </div>';
  echo '  </div>';

  echo '  <div class="bg-white rounded-3xl shadow p-6 space-y-6">';
  echo '    <nav class="flex flex-wrap gap-4 text-sm font-semibold text-gray-600 border-b pb-3" id="productTabs">';
  echo '      <button type="button" data-tab="description" class="px-3 py-2 rounded-lg bg-brand-50 text-brand-700">Descrição</button>';
  if ($specs) {
    echo '      <button type="button" data-tab="specs" class="px-3 py-2 rounded-lg hover:bg-gray-100">Especificações</button>';
  }
  if ($additionalInfo !== '') {
    echo '      <button type="button" data-tab="additional" class="px-3 py-2 rounded-lg hover:bg-gray-100">Informações adicionais</button>';
  }
  echo '      <button type="button" data-tab="reviews" class="px-3 py-2 rounded-lg hover:bg-gray-100">Avaliações</button>';
  echo '    </nav>';
  echo '    <div class="space-y-8" id="productTabPanels">';
  echo '      <div data-panel="description">';
  echo '        <div class="prose prose-sm sm:prose md:prose-lg max-w-none text-gray-700">'.$detailedDescription.'</div>';
  echo '      </div>';
  if ($specs) {
    echo '      <div data-panel="specs" class="hidden">';
    echo '        <div class="overflow-x-auto">';
    echo '          <table class="min-w-full text-sm">';
    echo '            <tbody>';
    foreach ($specs as $entry) {
      echo '              <tr class="border-b last:border-0">';
      echo '                <th class="text-left font-semibold py-3 pr-6 text-gray-600">'. $entry['label'] .'</th>';
      echo '                <td class="py-3 text-gray-700">'. $entry['value'] .'</td>';
      echo '              </tr>';
    }
    echo '            </tbody>';
    echo '          </table>';
    echo '        </div>';
    echo '      </div>';
  }
  if ($additionalInfo !== '') {
    echo '      <div data-panel="additional" class="hidden">';
    echo '        <div class="prose prose-sm sm:prose md:prose-lg max-w-none text-gray-700">'.$additionalInfo.'</div>';
    echo '      </div>';
  }
  echo '      <div data-panel="reviews" class="hidden">';
  echo '        <div class="flex items-center gap-3 mb-4">';
  echo '          <div class="text-3xl font-bold text-brand-600">5.0</div>';
  echo '          <div class="text-sm text-gray-500">Feedbacks recentes de clientes</div>';
  echo '        </div>';
  echo '        <div class="space-y-4">';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-gray-50">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Me ajudou na rotina</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Comecei a usar achando que não faria muita diferença, mas percebi que me ajudou a manter uma rotina mais disciplinada. Senti mais disposição para me movimentar durante o dia.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-white">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Ótimo complemento</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Uso como complemento da minha alimentação e gostei bastante. Me ajudou a controlar melhor minha vontade de beliscar fora de hora.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-gray-50">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Resultado gradual e natural</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Notei mudanças leves ao longo das semanas, principalmente no meu bem-estar e energia. Gostei da sensação de leveza.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-white">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Me motivou a seguir hábitos melhores</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Adorei! Senti que me ajudou a manter o foco em hábitos mais saudáveis. Para mim, fez diferença na constância.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-gray-50">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Muito satisfeito(a)</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Produto de ótima qualidade. Percebi que meu corpo ficou mais regulado e minha digestão melhorou bastante.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-white">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Ajuda na sensação de saciedade</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Depois de alguns dias de uso, notei que ficava satisfeita por mais tempo entre as refeições. Isso facilitou meu controle alimentar.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-gray-50">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Indico!</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Foi uma boa surpresa! Senti um aumento sutil na energia e isso me ajudou a manter meus treinos com mais regularidade.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-white">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Experiência positiva</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Não faz milagres, mas senti uma melhora no meu bem-estar geral. Estou gostando bastante.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-gray-50">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Fácil de usar e eficaz na rotina</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“O que mais gostei foi a praticidade. Incorporar na minha rotina foi simples e percebi benefícios poucos dias depois.”</p>';
  echo '          </div>';
  echo '          <div class="rounded-2xl border border-gray-100 p-4 bg-white">';
  echo '            <div class="flex items-center justify-between mb-1"><span class="font-semibold text-gray-800">Sensação de leveza</span><span class="text-amber-500">⭐⭐⭐⭐⭐</span></div>';
  echo '            <p class="text-sm text-gray-700 leading-relaxed">“Me deu uma sensação de leveza e bem-estar ao longo do dia. Para quem está buscando apoio para uma rotina mais saudável, vale a pena experimentar.”</p>';
  echo '          </div>';
  echo '        </div>';
  echo '      </div>';
  echo '    </div>';
  echo '  </div>';

  if ($relatedProducts) {
    echo '  <div>';
    echo '    <div class="flex items-center justify-between mb-4">';
    echo '      <h2 class="text-xl font-bold">Produtos relacionados</h2>';
    echo '      <a class="text-sm text-brand-600 hover:underline" href="?route=home">Ver todos</a>';
    echo '    </div>';
    echo '    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">';
    foreach ($relatedProducts as $rel) {
      $relImg = $rel['image_path'] ? proxy_img($rel['image_path']) : 'assets/no-image.png';
      $relImgSafe = htmlspecialchars($relImg, ENT_QUOTES, 'UTF-8');
      $relPrice = format_currency((float)$rel['price'], $currencyCode);
      $relCompare = isset($rel['price_compare']) && $rel['price_compare'] > $rel['price'] ? format_currency((float)$rel['price_compare'], $currencyCode) : '';
      $relDiscount = ($relCompare !== '') ? max(1, round(100 - ($rel['price'] / $rel['price_compare'] * 100))) : null;
      $relUrl = $rel['slug'] ? ('?route=product&slug='.urlencode($rel['slug'])) : ('?route=product&id='.(int)$rel['id']);
      echo '      <div class="bg-white rounded-2xl shadow hover:shadow-lg transition overflow-hidden flex flex-col">';
      echo '        <a href="'.$relUrl.'" class="block relative h-44 bg-gray-50 overflow-hidden">';
      echo '          <img src="'.$relImgSafe.'" alt="'.htmlspecialchars($rel['name'], ENT_QUOTES, 'UTF-8').'" class="w-full h-full object-cover transition-transform duration-300 hover:scale-105">';
      echo '          '.($relDiscount ? '<span class="absolute top-3 left-3 bg-brand-600 text-white text-xs font-semibold px-2 py-1 rounded-full">'.$relDiscount.'% OFF</span>' : '').'';
      if (!empty($rel['badge_one_month'])) {
        echo '          <div class="product-ribbon">Tratamento para 1 mês</div>';
      }
      echo '        </a>';
      echo '        <div class="p-4 flex flex-col space-y-2 flex-1">';
      echo '          <a href="'.$relUrl.'" class="font-semibold text-gray-900 hover:text-brand-600">'.htmlspecialchars($rel['name'], ENT_QUOTES, 'UTF-8').'</a>';
      if ($relCompare) {
        echo '          <div class="text-sm text-gray-400 line-through">'.$relCompare.'</div>';
      }
      echo '          <div class="text-lg font-bold text-brand-700">'.$relPrice.'</div>';
      echo '          <button type="button" class="mt-auto px-4 py-2 rounded-lg bg-brand-600 text-white hover:bg-brand-700 text-sm" onclick="addToCart('.(int)$rel['id'].', \''.htmlspecialchars($rel['name'], ENT_QUOTES, 'UTF-8').'\', 1)">Adicionar</button>';
      echo '        </div>';
      echo '      </div>';
    }
    echo '    </div>';
    echo '  </div>';
  }

  echo '</section>';

  echo '<script>
    (function(){
      const mainImage = document.getElementById("product-main-image");
      const buttons = document.querySelectorAll("[data-gallery-image]");
      buttons.forEach(btn => {
        btn.addEventListener("click", () => {
          const url = btn.getAttribute("data-gallery-image");
          if (mainImage && url) {
            mainImage.src = url;
            buttons.forEach(b => b.classList.remove("border-brand-600","ring-2","ring-brand-200"));
            btn.classList.add("border-brand-600","ring-2","ring-brand-200");
          }
        });
      });

      const qtyInput = document.getElementById("quantityInput");
      const decrease = document.getElementById("qtyDecrease");
      const increase = document.getElementById("qtyIncrease");
      if (decrease && increase && qtyInput) {
        decrease.addEventListener("click", () => {
          let current = parseInt(qtyInput.value, 10) || 1;
          current = Math.max(1, current - 1);
          qtyInput.value = current;
        });
        increase.addEventListener("click", () => {
          let current = parseInt(qtyInput.value, 10) || 1;
          qtyInput.value = current + 1;
        });
      }

      const buyBtn = document.getElementById("btnBuyNow");
      const buyNowGo = document.getElementById("btnBuyNowGo");
      const handleAdd = async (redirect) => {
        const qty = parseInt(qtyInput.value, 10) || 1;
        const ok = await addToCart('.$productId.', "'.htmlspecialchars($productName, ENT_QUOTES, 'UTF-8').'", qty);
        if (ok && redirect) {
          window.location.href = "?route=checkout";
        }
      };
      if (buyBtn && qtyInput) {
        buyBtn.addEventListener("click", () => handleAdd(false));
      }
      if (buyNowGo && qtyInput) {
        buyNowGo.addEventListener("click", () => handleAdd(true));
      }

      const tabs = document.querySelectorAll("#productTabs button[data-tab]");
      const panels = document.querySelectorAll("#productTabPanels [data-panel]");
      tabs.forEach(tab => {
        tab.addEventListener("click", () => {
          const target = tab.getAttribute("data-tab");
          tabs.forEach(t => t.classList.remove("bg-brand-50","text-brand-700"));
          tab.classList.add("bg-brand-50","text-brand-700");
          panels.forEach(panel => {
            panel.classList.toggle("hidden", panel.getAttribute("data-panel") !== target);
          });
        });
      });

      const freightBtn = document.getElementById("calcFreightBtn");
      const cepInput = document.getElementById("cepInput");
      const resultBox = document.getElementById("freightResult");
      if (freightBtn && cepInput && resultBox) {
        freightBtn.addEventListener("click", () => {
          const cep = (cepInput.value || "").replace(/\\D+/g, "");
          if (cep.length !== 8) {
            resultBox.textContent = "Informe um CEP válido com 8 dígitos.";
            resultBox.classList.remove("hidden");
            resultBox.classList.add("text-rose-600");
            return;
          }
          resultBox.classList.remove("text-rose-600");
          resultBox.classList.add("text-emerald-700");
          resultBox.textContent = "Frete padrão disponível para o CEP "+cep.substr(0,5)+"-"+cep.substr(5)+" por '.$shippingFormatted.' (valor configurado pelo administrador).";
          resultBox.classList.remove("hidden");
        });
      }
    })();
  </script>';

  app_footer();
  unset($GLOBALS['app_meta_title'], $GLOBALS['app_meta_description']);
  exit;
}

// Fallback — volta pra home
header('Location: ?route=home');
exit;
