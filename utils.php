<?php
// lib/utils.php - Utilitários do sistema Get Power Research (com settings, upload de logo e helpers)

/* =========================================================================
   Carregamento de configuração (cfg)
   ========================================================================= */
if (!function_exists('cfg')) {
    function cfg() {
        static $config = null;
        if ($config === null) {
            // config.php retorna um array (além de definir constantes)
            $config = require __DIR__ . '/../config.php';
        }
        return $config;
    }
}

require_once __DIR__.'/push.php';

/* =========================================================================
   Settings persistentes (tabela settings)
   ========================================================================= */
if (!function_exists('settings_bootstrap')) {
    function settings_bootstrap(): void {
        static $bootstrapped = false;
        if ($bootstrapped) {
            return;
        }
        try {
            $pdo = db();
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS settings (
                    skey VARCHAR(191) PRIMARY KEY,
                    svalue LONGTEXT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
            $bootstrapped = true;
        } catch (Throwable $e) {
            // Ignoramos para evitar fatal em ambientes sem permissão de migração automática.
            $bootstrapped = true;
        }
    }
}

if (!function_exists('setting_get')) {
    /**
     * Recupera um valor armazenado em settings, retornando $default quando ausente.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function setting_get(string $key, $default = null) {
        settings_bootstrap();
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                return $default;
            }
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
            return $value;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('setting_set')) {
    /**
     * Persiste um valor na tabela settings. Objetos/arrays são serializados em JSON.
     *
     * @param string $key
     * @param mixed  $value
     * @return bool
     */
    function setting_set(string $key, $value): bool {
        settings_bootstrap();
        try {
            $pdo = db();
            $serialized = (is_array($value) || is_object($value))
                ? json_encode($value, JSON_UNESCAPED_UNICODE)
                : (string)$value;
            $stmt = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
            return (bool)$stmt->execute([$key, $serialized]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* =========================================================================
   Migração automática mínima para campos do checkout
   ========================================================================= */
if (!function_exists('checkout_maybe_upgrade_schema')) {
    function checkout_maybe_upgrade_schema(): void {
        static $verified = false;
        if ($verified) {
            return;
        }

        try {
            $pdo = db();
        } catch (Throwable $e) {
            $verified = true;
            return;
        }

        try {
            $customerCols = [];
            try {
                $stmt = $pdo->query('SHOW COLUMNS FROM customers');
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($row['Field'])) {
                            $customerCols[] = $row['Field'];
                        }
                    }
                }
            } catch (Throwable $e) {
                $customerCols = [];
            }

            if ($customerCols) {
                if (!in_array('first_name', $customerCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE customers ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER id");
                    } catch (Throwable $e) {}
                }
                if (!in_array('last_name', $customerCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE customers ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name");
                    } catch (Throwable $e) {}
                }
                if (!in_array('address2', $customerCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE customers ADD COLUMN address2 VARCHAR(255) NULL AFTER address");
                    } catch (Throwable $e) {}
                }
                if (!in_array('country', $customerCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE customers ADD COLUMN country VARCHAR(50) DEFAULT 'US' AFTER zipcode");
                    } catch (Throwable $e) {}
                }
            }

            $orderCols = [];
            try {
                $stmt = $pdo->query('SHOW COLUMNS FROM orders');
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($row['Field'])) {
                            $orderCols[] = $row['Field'];
                        }
                    }
                }
            } catch (Throwable $e) {
                $orderCols = [];
            }

            if ($orderCols) {
                if (!in_array('delivery_method_code', $orderCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_method_code VARCHAR(60) NULL AFTER payment_method");
                    } catch (Throwable $e) {}
                }
                if (!in_array('delivery_method_label', $orderCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_method_label VARCHAR(120) NULL AFTER delivery_method_code");
                    } catch (Throwable $e) {}
                }
                if (!in_array('delivery_method_details', $orderCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_method_details VARCHAR(255) NULL AFTER delivery_method_label");
                    } catch (Throwable $e) {}
                }
            }
        } finally {
            $verified = true;
        }
    }
}

/* =========================================================================
   Sistema de afiliados (migração leve)
   ========================================================================= */
if (!function_exists('affiliate_maybe_upgrade_schema')) {
    function affiliate_maybe_upgrade_schema(): void {
        static $verified = false;
        if ($verified) {
            return;
        }

        try {
            $pdo = db();
        } catch (Throwable $e) {
            $verified = true;
            return;
        }

        try {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS affiliates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(80) NOT NULL,
                    name VARCHAR(140) NOT NULL,
                    landing_url VARCHAR(255) NULL,
                    notes TEXT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_affiliate_code (code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Throwable $e) {}

            $orderCols = [];
            try {
                $stmt = $pdo->query('SHOW COLUMNS FROM orders');
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($row['Field'])) {
                            $orderCols[] = $row['Field'];
                        }
                    }
                }
            } catch (Throwable $e) {
                $orderCols = [];
            }

            if ($orderCols) {
                if (!in_array('affiliate_id', $orderCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE orders ADD COLUMN affiliate_id INT NULL AFTER order_origin");
                    } catch (Throwable $e) {}
                }
                if (!in_array('affiliate_code', $orderCols, true)) {
                    try {
                        $pdo->exec("ALTER TABLE orders ADD COLUMN affiliate_code VARCHAR(80) NULL AFTER affiliate_id");
                    } catch (Throwable $e) {}
                }
            }
        } finally {
            $verified = true;
        }
    }
}

if (!function_exists('affiliate_normalize_code')) {
    function affiliate_normalize_code(string $raw): string {
        $code = remove_accents($raw);
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9\-_]+/i', '-', $code);
        $code = trim($code, '-');
        return $code;
    }
}

/* =========================================================================
   Internacionalização
   ========================================================================= */
if (!function_exists('lang')) {
    function lang($key = null) {
        static $dict = null;

        if ($dict === null) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $lang = $_SESSION['lang'] ?? (defined('DEFAULT_LANG') ? DEFAULT_LANG : 'pt_BR');
            $lang_code = substr($lang, 0, 2); // pt_BR -> pt

            $lang_files = [
                'pt' => __DIR__ . '/../i18n/pt.php',
                'en' => __DIR__ . '/../i18n/en.php',
                'es' => __DIR__ . '/../i18n/es.php',
            ];

            $file = $lang_files[$lang_code] ?? $lang_files['pt'];
            $dict = file_exists($file) ? require $file : get_default_dict();
            $dict['_lang'] = $lang_code;
        }

        return $key === null ? $dict : ($dict[$key] ?? $key);
    }
}

if (!function_exists('set_lang')) {
    function set_lang($lang) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $allowed = ['pt', 'pt_BR', 'en', 'en_US', 'es', 'es_ES'];
        if (in_array($lang, $allowed, true)) {
            $_SESSION['lang'] = $lang;
        }
    }
}

if (!function_exists('t')) {
    function t($key) { return lang($key); }
}

if (!function_exists('get_default_dict')) {
    function get_default_dict() {
        return [
            'title' => 'Get Power Research',
            'cart' => 'Carrinho',
            'search' => 'Buscar',
            'lang' => 'Idioma',
            'products' => 'Produtos',
            'subtotal' => 'Subtotal',
            'checkout' => 'Finalizar Compra',
            'name' => 'Nome',
            'email' => 'E-mail',
            'phone' => 'Telefone',
            'address' => 'Endereço',
            'city' => 'Cidade',
            'state' => 'Estado',
            'zipcode' => 'CEP',
            'customer_info' => 'Dados do Cliente',
            'payment_info' => 'Pagamento',
            'order_details' => 'Resumo do Pedido',
            'continue_shopping' => 'Continuar Comprando',
            'thank_you_order' => 'Obrigado pelo seu pedido!',
            'zelle' => 'Zelle',
            'venmo' => 'Venmo',
            'pix'   => 'PIX',
            'paypal'=> 'PayPal',
            'square'=> 'Cartão de crédito',
            'whatsapp'=> 'WhatsApp',
            'upload_receipt' => 'Enviar Comprovante',
            'place_order' => 'Finalizar Pedido',
            'add_to_cart' => 'Adicionar ao Carrinho',
            'order_received' => 'Pedido Recebido',
            'status' => 'Status',
            'pending' => 'Pendente',
            'processing' => 'Processando',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
        ];
    }
}

/* =========================================================================
   CSRF
   ========================================================================= */
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}

/* =========================================================================
   Admin helpers
   ========================================================================= */
if (!function_exists('normalize_admin_role')) {
    function normalize_admin_role($role): string {
        $role = strtolower(trim((string)$role));
        switch ($role) {
            case 'super_admin':
            case 'superadmin':
            case 'super-admin':
            case 'owner':
            case 'proprietario':
            case 'proprietário':
                return 'super_admin';
            case 'admin':
            case 'administrator':
            case 'administrador':
            case 'adm':
                return 'admin';
            case 'manager':
            case 'gestor':
                return 'manager';
            case 'viewer':
            case 'reader':
            case 'leitor':
                return 'viewer';
            default:
                return 'admin';
        }
    }
}

if (!function_exists('set_admin_session')) {
    function set_admin_session(array $adminData) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $id    = isset($adminData['id']) ? (int)$adminData['id'] : 0;
        $email = $adminData['email'] ?? null;
        $role  = normalize_admin_role($adminData['role'] ?? 'admin');
        $name  = $adminData['name'] ?? null;

        $_SESSION['admin'] = [
            'id'    => $id,
            'email' => $email,
            'role'  => $role,
            'name'  => $name,
        ];

        // Mantém compatibilidade com verificações existentes
        $_SESSION['admin_id']      = $id ?: 1;
        $_SESSION['admin_user_id'] = $id ?: null;
        $_SESSION['admin_email']   = $email;
        $_SESSION['admin_role']    = $role;
        if ($name) {
            $_SESSION['admin_name'] = $name;
        }
    }
}

if (!function_exists('current_admin')) {
    function current_admin(): ?array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (!empty($_SESSION['admin']) && is_array($_SESSION['admin'])) {
            return $_SESSION['admin'];
        }
        if (!empty($_SESSION['admin_id'])) {
            return [
                'id'    => $_SESSION['admin_user_id'] ?? (int)$_SESSION['admin_id'],
                'email' => $_SESSION['admin_email'] ?? null,
                'role'  => $_SESSION['admin_role'] ?? 'admin',
                'name'  => $_SESSION['admin_name'] ?? null,
            ];
        }
        return null;
    }
}

if (!function_exists('current_admin_role')) {
    function current_admin_role(): string {
        $admin = current_admin();
        return normalize_admin_role($admin['role'] ?? 'admin');
    }
}

if (!function_exists('is_super_admin')) {
    function is_super_admin(): bool {
        return current_admin_role() === 'super_admin';
    }
}

if (!function_exists('require_super_admin')) {
    function require_super_admin(): void {
        if (!is_super_admin()) {
            admin_forbidden('Apenas super administradores podem executar esta ação.');
        }
    }
}

if (!function_exists('admin_forbidden')) {
    function admin_forbidden(string $message = 'Você não tem permissão para executar esta ação.'): void {
        http_response_code(403);
        if (function_exists('admin_header') && function_exists('admin_footer')) {
            admin_header('Acesso negado');
            echo '<div class="card p-6 mx-auto max-w-xl mt-10">';
            echo '<div class="card-title">Permissão negada</div>';
            echo '<div class="text-sm text-gray-600">'.sanitize_html($message).'</div>';
            echo '<div class="mt-4"><a class="btn" href="dashboard.php"><i class="fa-solid fa-arrow-left"></i> Voltar ao painel</a></div>';
            echo '</div>';
            admin_footer();
        } else {
            echo sanitize_html($message);
        }
        exit;
    }
}

if (!function_exists('admin_role_capabilities')) {
    function admin_role_capabilities(string $role): array {
        $role = normalize_admin_role($role);
        switch ($role) {
            case 'super_admin':
                return ['*'];
            case 'admin':
                return [
                    'manage_products',
                    'manage_categories',
                    'manage_orders',
                    'manage_customers',
                    'manage_affiliates',
                    'manage_settings',
                    'manage_payment_methods',
                    'manage_users',
                    'manage_builder',
                ];
            case 'manager':
                return [
                    'manage_products',
                    'manage_categories',
                    'manage_orders',
                    'manage_customers',
                    'manage_affiliates',
                    'manage_settings',
                ];
            case 'viewer':
            default:
                return [];
        }
    }
}

if (!function_exists('admin_can')) {
    function admin_can(string $capability): bool {
        if (is_super_admin()) {
            return true;
        }
        $role = current_admin_role();
        $caps = admin_role_capabilities($role);
        if (in_array('*', $caps, true)) {
            return true;
        }
        return in_array($capability, $caps, true);
    }
}

if (!function_exists('require_admin_capability')) {
    function require_admin_capability(string $capability): void {
        if (!admin_can($capability)) {
            admin_forbidden('Você não tem permissão para executar esta ação.');
        }
    }
}

if (!function_exists('normalize_hex_color')) {
    function normalize_hex_color(string $hex): string {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $hex = strtoupper(preg_replace('/[^0-9A-F]/i', '', $hex));
        if (strlen($hex) !== 6) {
            return '2060C8';
        }
        return $hex;
    }
}

if (!function_exists('adjust_color_brightness')) {
    function adjust_color_brightness(string $hex, float $factor): string {
        $hex = normalize_hex_color($hex);
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $adjust = function ($channel) use ($factor) {
            if ($factor >= 0) {
                $channel = $channel + (255 - $channel) * $factor;
            } else {
                $channel = $channel * (1 + $factor);
            }
            return (int)max(0, min(255, round($channel)));
        };

        $r = $adjust($r);
        $g = $adjust($g);
        $b = $adjust($b);

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}

if (!function_exists('hero_default_highlights')) {
    function hero_default_highlights(): array
    {
        return [
            [
                'icon'  => 'fa-shield-halved',
                'title' => 'Produtos Originais',
                'desc'  => 'Nossos produtos são 100% originais e testados em laboratório.',
            ],
            [
                'icon'  => 'fa-award',
                'title' => 'Qualidade e Segurança',
                'desc'  => 'Compre com quem se preocupa com a qualidade dos produtos.',
            ],
            [
                'icon'  => 'fa-plane-departure',
                'title' => 'Enviamos para todo EUA',
                'desc'  => 'Entrega rápida e segura em todo o território norte-americano.',
            ],
            [
                'icon'  => 'fa-lock',
                'title' => 'Site 100% Seguro',
                'desc'  => 'Pagamentos protegidos pela nossa rede de segurança privada.',
            ],
        ];
    }
}

if (!function_exists('generate_brand_palette')) {
    function generate_brand_palette(string $baseColor): array {
        $base = '#'.normalize_hex_color($baseColor);
        return [
            '50'      => adjust_color_brightness($base, 0.85),
            '100'     => adjust_color_brightness($base, 0.7),
            '200'     => adjust_color_brightness($base, 0.5),
            '300'     => adjust_color_brightness($base, 0.3),
            '400'     => adjust_color_brightness($base, 0.15),
            '500'     => adjust_color_brightness($base, 0.05),
            '600'     => $base,
            '700'     => adjust_color_brightness($base, -0.15),
            '800'     => adjust_color_brightness($base, -0.25),
            '900'     => adjust_color_brightness($base, -0.35),
            'DEFAULT' => $base,
        ];
    }
}

/* =========================================================================
   PIX - Payload EMV
   ========================================================================= */
if (!function_exists('pix_payload')) {
    function pix_payload($pix_key, $merchant_name, $merchant_city, $amount = 0.00, $txid = null) {
        // Payload Format Indicator
        $payload = "000201";

        // Point of Initiation Method
        if ($amount > 0) { $payload .= "010212"; } else { $payload .= "010211"; }

        // Merchant Account Information (GUI + chave + TXID opcional)
        $gui = "br.gov.bcb.pix";
        // GUI (00) + chave (01) + (02)TXID opcional
        $ma = "00" . sprintf("%02d", strlen($gui)) . $gui
            . "01" . sprintf("%02d", strlen($pix_key)) . $pix_key;
        if ($txid) {
            $ma .= "02" . sprintf("%02d", strlen($txid)) . $txid;
        }
        // ID 26 => Merchant Account Info template
        $payload .= "26" . sprintf("%02d", strlen($ma)) . $ma;

        // MCC
        $payload .= "52040000";

        // Currency BRL
        $payload .= "5303986";

        // Amount
        if ($amount > 0) {
            $amount_str = number_format((float)$amount, 2, '.', '');
            $payload .= "54" . sprintf("%02d", strlen($amount_str)) . $amount_str;
        }

        // Country
        $payload .= "5802BR";

        // Merchant Name (sem acentos, máx 25)
        $mname = substr(remove_accents($merchant_name), 0, 25);
        $payload .= "59" . sprintf("%02d", strlen($mname)) . $mname;

        // Merchant City (sem acentos, máx 15)
        $mcity = substr(remove_accents($merchant_city), 0, 15);
        $payload .= "60" . sprintf("%02d", strlen($mcity)) . $mcity;

        // CRC16
        $payload .= "6304";
        $crc = crc16_ccitt($payload);
        $payload .= strtoupper(sprintf("%04X", $crc));

        return $payload;
    }
}

if (!function_exists('remove_accents')) {
    function remove_accents($str) {
        $accents = [
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A',
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'Ç'=>'C','ç'=>'c','Ñ'=>'N','ñ'=>'n'
        ];
        return strtr($str, $accents);
    }
}

if (!function_exists('crc16_ccitt')) {
    function crc16_ccitt($data) {
        $crc = 0xFFFF;
        $poly = 0x1021;

        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8) & 0xFFFF;
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ $poly) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return $crc & 0xFFFF;
    }
}

/* =========================================================================
   Validações e sanitização
   ========================================================================= */
if (!function_exists('validate_email')) {
    function validate_email($email) { return (bool)filter_var($email, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('validate_phone')) {
    function validate_phone($phone) {
        $clean = preg_replace('/\D+/', '', (string)$phone);
        return strlen($clean) >= 10;
    }
}
if (!function_exists('sanitize_string')) {
    function sanitize_string($str, $max_length = 255) {
        $clean = trim(strip_tags((string)$str));
        return mb_substr($clean, 0, $max_length, 'UTF-8');
    }
}
if (!function_exists('sanitize_html')) {
    function sanitize_html($html) {
        return htmlspecialchars((string)$html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/* =========================================================================
   Uploads seguros (genérico) + upload específico de logo
   ========================================================================= */
if (!function_exists('validate_file_upload')) {
    function validate_file_upload($file, $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], $max_size = 2097152) {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $err = isset($file['error']) ? (int)$file['error'] : -1;
            return ['success' => false, 'message' => 'Erro no upload: ' . $err];
        }
        if ((int)$file['size'] > (int)$max_size) {
            return ['success' => false, 'message' => 'Arquivo muito grande (máx: ' . formatBytes($max_size) . ').'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed_types, true)) {
            return ['success' => false, 'message' => 'Tipo de arquivo não permitido.'];
        }
        return ['success' => true, 'mime_type' => $mime];
    }
}

if (!function_exists('save_logo_upload')) {
    function save_logo_upload(array $file) {
        // Salva a logo em storage/logo/logo.(png|jpg|jpeg|webp) e retorna caminho relativo ("storage/logo/logo.png")
        $validation = validate_file_upload($file, ['image/jpeg','image/png','image/webp'], 2 * 1024 * 1024);
        if (!$validation['success']) {
            return ['success' => false, 'message' => $validation['message']];
        }
        $cfg = cfg();
        $dir = $cfg['paths']['logo'] ?? (__DIR__ . '/../storage/logo');
        @mkdir($dir, 0775, true);

        // extensão segura
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            // mapear pelo mime
            $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $ext = $map[$validation['mime_type']] ?? 'png';
        }

        $filename = 'logo.' . $ext;
        $destAbs  = rtrim($dir, '/\\') . '/' . $filename;
        $destRel  = 'storage/logo/' . $filename;

        if (!@move_uploaded_file($file['tmp_name'], $destAbs)) {
            return ['success' => false, 'message' => 'Falha ao mover arquivo de logo.'];
        }
        // opcionalmente: apagar outras extensões antigas para evitar conflito visual/cache
        foreach (['png','jpg','jpeg','webp'] as $e) {
            $p = rtrim($dir, '/\\') . '/logo.' . $e;
            if ($e !== $ext && file_exists($p)) { @unlink($p); }
        }
        // Grava a referência nas settings (logo_path)
        setting_set('store_logo', $destRel);

        return ['success' => true, 'path' => $destRel];
    }
}

if (!function_exists('save_hero_background_upload')) {
    function save_hero_background_upload(array $file)
    {
        $validation = validate_file_upload($file, ['image/jpeg','image/png','image/webp'], 4 * 1024 * 1024);
        if (!$validation['success']) {
            return ['success' => false, 'message' => $validation['message']];
        }
        $dir = __DIR__ . '/../storage/hero';
        @mkdir($dir, 0775, true);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
            $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            $ext = $map[$validation['mime_type']] ?? 'jpg';
        }

        $filename = 'hero_bg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destAbs  = rtrim($dir, '/\\') . '/' . $filename;
        $destRel  = 'storage/hero/' . $filename;

        if (!@move_uploaded_file($file['tmp_name'], $destAbs)) {
            return ['success' => false, 'message' => 'Falha ao enviar a imagem de fundo do hero.'];
        }

        return ['success' => true, 'path' => $destRel];
    }
}

if (!function_exists('get_logo_path')) {
    function get_logo_path() {
        $stored = (string)setting_get('store_logo', '');
        if ($stored && file_exists(__DIR__ . '/../' . $stored)) {
            return $stored;
        }
        // fallback: procurar logo física
        $candidates = [
            'storage/logo/logo.png',
            'storage/logo/logo.jpg',
            'storage/logo/logo.jpeg',
            'storage/logo/logo.webp',
        ];
        foreach ($candidates as $c) {
            if (file_exists(__DIR__ . '/../' . $c)) {
                return $c;
            }
        }
        return ''; // sem logo
    }
}

if (!function_exists('email_logo_url')) {
    function email_logo_url(): string {
        $logoRel = ltrim((string)get_logo_path(), '/');
        $emptyPixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
        if ($logoRel === '') {
            return $emptyPixel;
        }

        $publicPath = app_public_path($logoRel);
        $cfg = cfg();
        $baseUrl = rtrim((string)($cfg['store']['base_url'] ?? ''), '/');

        if ($baseUrl !== '') {
            $url = $baseUrl . $publicPath;
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if ($host !== '') {
                $url = $scheme . '://' . $host . $publicPath;
            } else {
                $url = $publicPath;
            }
        }

        $abs = __DIR__ . '/../' . $logoRel;
        if (is_file($abs)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'v=' . filemtime($abs);
        }

        return $url ?: $emptyPixel;
    }
}

if (!function_exists('save_pwa_icon_upload')) {
    function save_pwa_icon_upload(array $file) {
        $validation = validate_file_upload($file, ['image/png'], 2 * 1024 * 1024);
        if (!$validation['success']) {
            return ['success' => false, 'message' => $validation['message'] ?? 'Arquivo inválido'];
        }

        $dir = __DIR__ . '/../storage/pwa';
        @mkdir($dir, 0775, true);

        $data = @file_get_contents($file['tmp_name']);
        if ($data === false) {
            return ['success' => false, 'message' => 'Falha ao ler o arquivo enviado.'];
        }

        $targets = [
            512 => $dir . '/icon-512.png',
            192 => $dir . '/icon-192.png',
            180 => $dir . '/icon-180.png',
        ];

        $generated = [];
        $canResize = function_exists('imagecreatefromstring') && function_exists('imagecreatetruecolor') && function_exists('imagepng');

        if ($canResize) {
            $src = @imagecreatefromstring($data);
            if ($src !== false) {
                $srcWidth  = imagesx($src);
                $srcHeight = imagesy($src);
                $square    = min($srcWidth, $srcHeight);
                foreach ($targets as $size => $path) {
                    $canvas = imagecreatetruecolor($size, $size);
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
                    imagecopyresampled(
                        $canvas,
                        $src,
                        0, 0,
                        ($srcWidth > $srcHeight) ? (int)(($srcWidth - $square) / 2) : 0,
                        ($srcHeight > $srcWidth) ? (int)(($srcHeight - $square) / 2) : 0,
                        $size, $size,
                        $square, $square
                    );
                    if (!@imagepng($canvas, $path, 9)) {
                        imagedestroy($canvas);
                        imagedestroy($src);
                        $canResize = false;
                        break;
                    }
                    $generated[] = $path;
                    imagedestroy($canvas);
                }
                imagedestroy($src);
            } else {
                $canResize = false;
            }
        }

        if (!$canResize) {
            $target512 = $targets[512];
            if (!@move_uploaded_file($file['tmp_name'], $target512)) {
                return ['success' => false, 'message' => 'Falha ao salvar ícone do app.'];
            }
            @copy($target512, $targets[192]);
            @copy($target512, $targets[180]);
        }

        setting_set('pwa_icon_last_update', (string)time());

        return ['success' => true];
    }
}

if (!function_exists('app_base_uri')) {
    function app_base_uri(): string {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $appReal = str_replace('\\', '/', realpath(__DIR__ . '/..'));
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $scriptReal = $scriptFilename ? str_replace('\\', '/', realpath($scriptFilename)) : '';
        $scriptDirReal = $scriptReal ? str_replace('\\', '/', dirname($scriptReal)) : '';
        $scriptUrlDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

        $appBaseUri = '';
        if ($appReal && $scriptDirReal && strpos($scriptDirReal, $appReal) === 0) {
            $relativeDir = trim(substr($scriptDirReal, strlen($appReal)), '/');
            if ($relativeDir !== '') {
                $levels = substr_count($relativeDir, '/') + 1;
                $parts = $scriptUrlDir === '' ? [] : explode('/', $scriptUrlDir);
                if ($levels < count($parts)) {
                    $appBaseUri = implode('/', array_slice($parts, 0, count($parts) - $levels));
                }
            } else {
                $appBaseUri = $scriptUrlDir;
            }
        } else {
            $appBaseUri = $scriptUrlDir;
        }

        $appBaseUri = $appBaseUri !== '' ? '/' . ltrim($appBaseUri, '/') : '';
        $base = $appBaseUri;
        return $base;
    }
}

if (!function_exists('app_public_path')) {
    function app_public_path(string $path): string {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^(?:[a-z][a-z0-9+\-.]*:|//)~i', $path)) {
            return $path;
        }

        $fragment = '';
        if (false !== $hashPos = strpos($path, '#')) {
            $fragment = substr($path, $hashPos);
            $path = substr($path, 0, $hashPos);
        }

        $query = '';
        if (false !== $qPos = strpos($path, '?')) {
            $query = substr($path, $qPos);
            $path = substr($path, 0, $qPos);
        }

        $path = preg_replace('#^\./#', '', $path);
        while (strpos($path, '../') === 0) {
            $path = substr($path, 3);
        }

        $normalized = '/' . ltrim($path, '/');
        if ($normalized === '//') {
            $normalized = '/';
        }

        $base = app_base_uri();
        if ($base !== '') {
            if ($normalized === $base || strpos($normalized, $base . '/') === 0) {
                $uri = $normalized;
            } else {
                $uri = $base . $normalized;
            }
        } else {
            $uri = $normalized;
        }

        return $uri . $query . $fragment;
    }
}

if (!function_exists('versioned_public_url')) {
    function versioned_public_url(string $path): string {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('~^(?:[a-z][a-z0-9+\-.]*:|//)~i', $path)) {
            return $path;
        }
        $relative = app_public_path($path);
        $absolute = __DIR__ . '/../' . ltrim($path, '/');
        $url = $relative;
        if (is_file($absolute)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'v=' . filemtime($absolute);
        }
        $cfg = cfg();
        $base = rtrim((string)($cfg['store']['base_url'] ?? ''), '/');
        if ($base !== '' && strpos($url, '://') !== 0) {
            return $base . $url;
        }
        if (strpos($url, '://') === 0) {
            return $url;
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $_SERVER['HTTP_HOST'] . $url;
        }
        return $url;
    }
}

if (!function_exists('get_pwa_icon_paths')) {
    function get_pwa_icon_paths(): array {
        $defaults = [
            512 => 'assets/icons/admin-512.png',
            192 => 'assets/icons/admin-192.png',
            180 => 'assets/icons/admin-192.png',
        ];

        $storeLogo = null;
        if (function_exists('find_logo_path')) {
            $storeLogo = find_logo_path();
        } elseif (function_exists('get_logo_path')) {
            $storeLogo = get_logo_path();
        } else {
            $cfgLogo = function_exists('setting_get') ? setting_get('store_logo_url') : null;
            if ($cfgLogo) {
                $storeLogo = ltrim((string)$cfgLogo, '/');
            } else {
                $candidates = [
                    'storage/logo/logo.png',
                    'storage/logo/logo.jpg',
                    'storage/logo/logo.jpeg',
                    'storage/logo/logo.webp',
                    'assets/logo.png'
                ];
                foreach ($candidates as $c) {
                    if (file_exists(__DIR__ . '/../' . $c)) {
                        $storeLogo = $c;
                        break;
                    }
                }
            }
        }
        if ($storeLogo) {
            $storeLogo = ltrim($storeLogo, '/');
            foreach (array_keys($defaults) as $size) {
                $defaults[$size] = $storeLogo;
            }
        }

        $paths = [];
        foreach ($defaults as $size => $fallback) {
            $custom = 'storage/pwa/icon-' . $size . '.png';
            $rel = $fallback;
            if (file_exists(__DIR__ . '/../' . $custom)) {
                $rel = $custom;
            }
            $paths[$size] = [
                'relative' => $rel,
                'absolute' => __DIR__ . '/../' . $rel
            ];
        }
        return $paths;
    }
}

if (!function_exists('get_pwa_icon_path')) {
    function get_pwa_icon_path(int $size = 512): string {
        $icons = get_pwa_icon_paths();
        return $icons[$size]['relative'] ?? '';
    }
}

if (!function_exists('pwa_icon_url')) {
    function pwa_icon_url(int $size = 512): string {
        $icons = get_pwa_icon_paths();
        if (!isset($icons[$size])) {
            return '';
        }
        $rel = $icons[$size]['relative'];
        $abs = $icons[$size]['absolute'];
        $url = function_exists('app_public_path') ? app_public_path($rel) : '';
        if ($url === '') {
            $url = '/' . ltrim($rel, '/');
        }
        if (file_exists($abs)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'v=' . filemtime($abs);
        }
        return $url;
    }
}

/* =========================================================================
   Helpers de formatação
   ========================================================================= */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = (float)$bytes;
        if ($bytes <= 0) return "0 B";
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

if (!function_exists('format_currency')) {
    function format_currency($amount, $currency = null) {
        $amount = (float)$amount;
        $currency = strtoupper($currency ?? (cfg()['store']['currency'] ?? 'BRL'));
        switch ($currency) {
            case 'USD': return '$' . number_format($amount, 2, '.', ',');
            case 'EUR': return '€' . number_format($amount, 2, ',', '.');
            case 'BRL': return 'R$ ' . number_format($amount, 2, ',', '.');
            default:    return $currency . ' ' . number_format($amount, 2, ',', '.');
        }
    }
}

if (!function_exists('format_date')) {
    function format_date($date, $format = 'd/m/Y') {
        if (empty($date)) return '-';
        return date($format, strtotime($date));
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime($datetime, $format = 'd/m/Y H:i') {
        if (empty($datetime)) return '-';
        return date($format, strtotime($datetime));
    }
}

if (!function_exists('slugify')) {
    function slugify($text) {
        $text = remove_accents((string)$text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text ?: 'n-a';
    }
}

/* =========================================================================
   Sistema de Notificações
   ========================================================================= */
if (!function_exists('send_notification')) {
    function send_notification($type, $title, $message, $data = null) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO notifications (type, title, message, data, created_at) VALUES (?, ?, ?, ?, NOW())");
            $payload = $data ? (array)$data : [];
            $stmt->execute([
                (string)$type,
                (string)$title,
                (string)$message,
                $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null
            ]);
            if (!empty($payload['order_id']) && empty($payload['url'])) {
                $payload['url'] = '/orders.php?action=view&id='.(int)$payload['order_id'];
            }
            $payload['type'] = (string)$type;
            push_dispatch_notification((string)$title, (string)$message, $payload);
            return true;
        } catch (Throwable $e) {
            error_log("Failed to send notification: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('get_unread_notifications')) {
    function get_unread_notifications($limit = 10) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT * FROM notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT ?");
            $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('mark_notifications_read')) {
    function mark_notifications_read($ids = null) {
        try {
            $pdo = db();
            if ($ids === null) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
                $stmt->execute();
            } else {
                if (!is_array($ids)) $ids = [$ids];
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id IN ($placeholders)");
                $stmt->execute(array_map('intval', $ids));
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* =========================================================================
   E-mail
   ========================================================================= */
if (!function_exists('email_log_entry')) {
    function email_log_entry(string $subject, string $to, string $status, string $detail = '', string $from = ''): void {
        $dir = __DIR__ . '/../storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $logLine = json_encode([
            'time' => date('c'),
            'status' => $status,
            'to' => $to,
            'from' => $from,
            'subject' => $subject,
            'detail' => $detail,
        ], JSON_UNESCAPED_UNICODE);
        if ($logLine) {
            @file_put_contents($dir . '/email.log', $logLine . PHP_EOL, FILE_APPEND);
        }
    }
}

if (!function_exists('smtp_socket_send')) {
    function smtp_socket_send(array $config, string $fromEmail, string $fromName, string $replyToEmail, string $toEmail, string $subject, string $bodyHtml): array {
        $host = trim((string)($config['host'] ?? ''));
        $port = (int)($config['port'] ?? 0);
        if ($host === '' || $port === 0) {
            return [false, 'missing_host_or_port'];
        }
        $secure = strtolower(trim((string)($config['secure'] ?? 'tls')));
        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(($transport ?: 'tcp://').$host.':'.$port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            return [false, 'connection_failed: '.$errstr];
        }
        stream_set_timeout($socket, 15);
        $readResponse = function() use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $sendCommand = function(string $command) use ($socket) {
            fwrite($socket, $command."\r\n");
        };
        $expectCode = function(string $response, array $expected) {
            $code = substr($response, 0, 3);
            return in_array($code, $expected, true);
        };
        $response = $readResponse();
        if (!$response || !preg_match('/^220/', $response)) {
            fclose($socket);
            return [false, 'no_greeting'];
        }
        $heloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $sendCommand('EHLO '.$heloHost);
        $response = $readResponse();
        if (!$expectCode($response, ['250'])) {
            fclose($socket);
            return [false, 'ehlo_failed'];
        }
        if ($secure === 'tls') {
            $sendCommand('STARTTLS');
            $response = $readResponse();
            if (!$expectCode($response, ['220'])) {
                fclose($socket);
                return [false, 'starttls_failed'];
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return [false, 'tls_negotiation_failed'];
            }
            $sendCommand('EHLO '.$heloHost);
            $response = $readResponse();
            if (!$expectCode($response, ['250'])) {
                fclose($socket);
                return [false, 'ehlo_after_tls_failed'];
            }
        }
        $username = trim((string)($config['user'] ?? ''));
        $password = (string)($config['pass'] ?? '');
        if ($username !== '' && $password !== '') {
            $sendCommand('AUTH LOGIN');
            $response = $readResponse();
            if (!$expectCode($response, ['334'])) {
                fclose($socket);
                return [false, 'auth_init_failed'];
            }
            $sendCommand(base64_encode($username));
            $response = $readResponse();
            if (!$expectCode($response, ['334'])) {
                fclose($socket);
                return [false, 'auth_user_failed'];
            }
            $sendCommand(base64_encode($password));
            $response = $readResponse();
            if (!$expectCode($response, ['235'])) {
                fclose($socket);
                return [false, 'auth_pass_failed'];
            }
        }
        $sendCommand('MAIL FROM:<'.$fromEmail.'>');
        $response = $readResponse();
        if (!$expectCode($response, ['250'])) {
            fclose($socket);
            return [false, 'mail_from_failed'];
        }
        $sendCommand('RCPT TO:<'.$toEmail.'>');
        $response = $readResponse();
        if (!$expectCode($response, ['250', '251'])) {
            fclose($socket);
            return [false, 'rcpt_to_failed'];
        }
        $sendCommand('DATA');
        $response = $readResponse();
        if (!$expectCode($response, ['354'])) {
            fclose($socket);
            return [false, 'data_cmd_failed'];
        }
        $fromHeader = '=?UTF-8?B?'.base64_encode($fromName).'?= <'.$fromEmail.'>';
        $replyHeader = $replyToEmail ? $replyToEmail : $fromEmail;
        $headers = [
            'Date: '.date('r'),
            'Subject: '.$subject,
            'From: '.$fromHeader,
            'Reply-To: '.$replyHeader,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $bodyHtml;
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = str_replace("\n", "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message);
        $sendCommand($message."\r\n.");
        $response = $readResponse();
        if (!$expectCode($response, ['250'])) {
            fclose($socket);
            return [false, 'data_send_failed'];
        }
        $sendCommand('QUIT');
        $readResponse();
        fclose($socket);
        return [true, 'smtp_sent'];
    }
}

if (!function_exists('system_mail_send')) {
    function system_mail_send(string $to, string $subject, string $body, string $headers) {
        return @mail($to, $subject, $body, $headers);
    }
}

if (!function_exists('send_email')) {
    function send_email($to, $subject, $body, $from = null) {
        $to = (string)$to;
        $subject = (string)$subject;
        $body = (string)$body;
        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $vendorAutoload = __DIR__.'/../vendor/autoload.php';
            if (file_exists($vendorAutoload)) {
                require_once $vendorAutoload;
            }
        }
        $config = cfg();
        $storeInfo = function_exists('store_info') ? store_info() : null;
        $storeName = $storeInfo['name'] ?? ($config['store']['name'] ?? 'Sua Loja');
        $supportEmail = $storeInfo['email'] ?? ($config['store']['support_email'] ?? 'no-reply@localhost');
        $fromAddress = $from ?: $supportEmail;
        $encodedName = '=?UTF-8?B?'.base64_encode($storeName).'?=';
        $subjectEncoded = '=?UTF-8?B?'.base64_encode($subject).'?=';
        $fromHeader = $encodedName.' <'.$fromAddress.'>';
        $smtpConfig = setting_get('smtp_config', []);
        $useSmtp = !empty($smtpConfig['host']) && !empty($smtpConfig['port']) && !empty($smtpConfig['user']) && !empty($smtpConfig['pass']);
        $mailHeaders = [
            'From: ' . $fromHeader,
            'Reply-To: ' . $fromHeader,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8'
        ];
        if ($useSmtp) {
            $smtpFromEmail = $smtpConfig['from_email'] ?: ($smtpConfig['user'] ?? $fromAddress);
            $smtpReplyTo = $supportEmail ?: $smtpFromEmail;
            [$smtpResult, $smtpDetail] = smtp_socket_send(
                $smtpConfig,
                $smtpFromEmail,
                $smtpConfig['from_name'] ?: $storeName,
                $smtpReplyTo,
                $to,
                $subjectEncoded,
                $body
            );
            if ($smtpResult) {
                email_log_entry($subject, $to, 'success', $smtpDetail, $smtpFromEmail ?: $fromHeader);
                return true;
            }
            // fallback para envio padrão (Hostinger/mail())
            $fallback = system_mail_send($to, $subjectEncoded, $body, implode("\r\n", $mailHeaders));
            $detail = 'smtp_failed: '.$smtpDetail.'; mail_fallback: '.($fallback ? 'sent' : 'failed');
            if (!$fallback) {
                $lastError = error_get_last();
                if ($lastError && isset($lastError['message'])) {
                    $detail .= ' | '.$lastError['message'];
                }
            }
            email_log_entry($subject, $to, $fallback ? 'success' : 'failure', $detail, $fromHeader);
            return $fallback;
        }

        $result = system_mail_send($to, $subjectEncoded, $body, implode("\r\n", $mailHeaders));
        $detail = $result ? 'sent' : 'mail() returned false';
        if (!$result) {
            $lastError = error_get_last();
            if ($lastError && isset($lastError['message'])) {
                $detail .= ' | '.$lastError['message'];
            }
        }
        email_log_entry($subject, $to, $result ? 'success' : 'failure', $detail, $fromHeader);
        return $result;
    }
}

if (!function_exists('email_templates_maybe_upgrade')) {
    function email_templates_maybe_upgrade(int $targetVersion = 3): void {
        $currentVersion = (int)setting_get('email_template_version', 0);
        if ($currentVersion >= $targetVersion) {
            return;
        }
        $defaults = email_template_defaults();
        setting_set('email_customer_subject', $defaults['customer_subject']);
        setting_set('email_customer_body', $defaults['customer_body']);
        setting_set('email_admin_subject', $defaults['admin_subject']);
        setting_set('email_admin_body', $defaults['admin_body']);
        setting_set('email_template_version', $targetVersion);
    }
}

if (!function_exists('email_template_defaults')) {
    function email_template_defaults($storeName = null) {
        $info = store_info();
        $detectedName = $storeName ?: ($info['name'] ?? 'Sua Loja');

        $customerSubject = "Pedido {{order_number}} confirmado - {$detectedName}";
        $customerBody = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{store_name}}</title>
</head>
<body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" style="padding:0;background-color:#f6f6f6;margin:0;-webkit-text-size-adjust:none;">
  <div id="wrapper" dir="ltr" style="background-color:#f6f6f6;margin:0;padding:70px 0;width:100%;">
    <table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%">
      <tr>
        <td align="center" valign="top">
          <table border="0" cellpadding="0" cellspacing="0" width="600" id="template_container" style="background-color:#ffffff;border:1px solid #dddddd;box-shadow:0 1px 4px rgba(0,0,0,0.1);border-radius:3px;">
            <tr>
              <td align="center" valign="top">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" style="background-color:#4d77b9;color:#ffffff;border-bottom:0;font-weight:bold;line-height:100%;vertical-align:middle;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;border-radius:3px 3px 0 0;">
                  <tr>
                    <td id="header_wrapper" style="padding:36px 48px;display:block;">
                      <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td align="left" style="padding-bottom:20px;">
                            <img src="{{store_logo_url}}" alt="{{store_name}}" style="border:none;display:block;font-size:14px;font-weight:bold;height:auto;outline:none;text-decoration:none;text-transform:capitalize;max-width:250px;color:#ffffff;">
                          </td>
                        </tr>
                      </table>
                      <h1 style="font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:30px;font-weight:300;line-height:150%;margin:0;text-align:left;color:#ffffff;background-color:inherit;">
                        Obrigado pelo seu pedido!
                      </h1>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td align="center" valign="top">
                <table border="0" cellpadding="0" cellspacing="0" width="600" id="template_body">
                  <tr>
                    <td valign="top" id="body_content" style="background-color:#ffffff;">
                      <table border="0" cellpadding="20" cellspacing="0" width="100%">
                        <tr>
                          <td valign="top" style="padding:48px 48px 32px;">
                            <div id="body_content_inner" style="color:#636363;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left;">
                              <p style="margin:0 0 16px;">Olá {{customer_name}},</p>
                              <p style="margin:0 0 16px;">Recebemos seu pedido <strong>#{{order_id}}</strong> na {{store_name}}. Seguem os detalhes:</p>

                              <h2 style="color:#4d77b9;display:block;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left;">
                                [Pedido #{{order_id}}]
                              </h2>

                              <div style="margin-bottom:40px;">
                                <table class="td" cellspacing="0" cellpadding="6" border="1" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;width:100%;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;border-collapse:collapse;">
                                  <thead>
                                    <tr>
                                      <th class="td" scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Produto</th>
                                      <th class="td" scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Quantidade</th>
                                      <th class="td" scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Preço</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {{order_items}}
                                  </tbody>
                                  <tfoot>
                                    <tr>
                                      <th class="td" scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px;">Subtotal:</th>
                                      <td class="td" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px;">{{order_subtotal}}</td>
                                    </tr>
                                    <tr>
                                      <th class="td" scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Frete:</th>
                                      <td class="td" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">{{order_shipping}}</td>
                                    </tr>
                                    <tr>
                                      <th class="td" scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Método de pagamento:</th>
                                      <td class="td" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">{{payment_method}}</td>
                                    </tr>
                                    <tr>
                                      <th class="td" scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Total:</th>
                                      <td class="td" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;"><strong>{{order_total}}</strong></td>
                                    </tr>
                                  </tfoot>
                                </table>
                              </div>

                              <h2 style="color:#4d77b9;display:block;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left;">
                                Endereço de Entrega
                              </h2>
                              <div style="margin-bottom:20px;color:#636363;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left;">
                                {{shipping_address_html}}
                              </div>

                              <h2 style="color:#4d77b9;display:block;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;line-height:130%;margin:0 0 18px;text-align:left;">
                                Método de Envio
                              </h2>
                              <div style="margin-bottom:30px;color:#636363;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left;">
                                <p style="margin:0;"><strong>{{shipping_method}}</strong><br>{{shipping_method_description}}</p>
                              </div>

                              <p style="margin:0 0 16px;">Qualquer dúvida, basta responder a este e-mail.</p>
                              <p style="margin:0 0 16px;">Atenciosamente,<br>Equipe {{store_name}}</p>
                            </div>
                          </td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td align="center" valign="top">
                <table border="0" cellpadding="10" cellspacing="0" width="600" id="template_footer">
                  <tr>
                    <td valign="top" style="padding:32px 48px;">
                      <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td valign="middle" id="credit" style="color:#999999;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:12px;line-height:150%;text-align:center;">
                            <p style="margin:0;">{{store_name}} - Todos os direitos reservados.</p>
                          </td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>
</body>
</html>
HTML;

        $adminSubject = "Novo pedido {{order_number}} - {$detectedName}";
        $adminBody = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{store_name}}</title>
</head>
<body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" style="padding:0;background-color:#f6f6f6;margin:0;-webkit-text-size-adjust:none;">
  <div id="wrapper" dir="ltr" style="background-color:#f6f6f6;margin:0;padding:70px 0;width:100%;">
    <table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%">
      <tr>
        <td align="center" valign="top">
          <table border="0" cellpadding="0" cellspacing="0" width="600" id="template_container" style="background-color:#ffffff;border:1px solid #dddddd;box-shadow:0 1px 4px rgba(0,0,0,0.1);border-radius:3px;">
            <tr>
              <td align="center" valign="top">
                <table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" style="background-color:#4d77b9;color:#ffffff;border-bottom:0;font-weight:bold;line-height:100%;vertical-align:middle;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;border-radius:3px 3px 0 0;">
                  <tr>
                    <td id="header_wrapper" style="padding:36px 48px;display:block;">
                      <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td align="left" style="padding-bottom:20px;">
                            <img src="{{store_logo_url}}" alt="{{store_name}}" style="border:none;display:block;font-size:14px;font-weight:bold;height:auto;outline:none;text-decoration:none;text-transform:capitalize;max-width:250px;color:#ffffff;">
                          </td>
                        </tr>
                      </table>
                      <h1 style="font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:30px;font-weight:300;line-height:150%;margin:0;text-align:left;color:#ffffff;background-color:inherit;">
                        Novo pedido recebido
                      </h1>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td align="center" valign="top">
                <table border="0" cellpadding="0" cellspacing="0" width="600" id="template_body">
                  <tr>
                    <td valign="top" id="body_content" style="background-color:#ffffff;">
                      <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td valign="top" style="padding:48px 48px 32px;">
                            <div style="color:#636363;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:14px;line-height:150%;text-align:left;">
                              <p style="margin:0 0 16px;">Pedido <strong>#{{order_id}}</strong> recebido em {{order_date}} por {{billing_full_name}}.</p>
                              <p style="margin:0 0 16px;">Resumo rápido:</p>
                              <table class="td" cellspacing="0" cellpadding="6" border="1" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;width:100%;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;border-collapse:collapse;margin-bottom:30px;">
                                <thead>
                                  <tr>
                                    <th class="td" scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Produto</th>
                                    <th class="td" scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Quantidade</th>
                                    <th class="td" scope="col" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Preço</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {{order_items}}
                                </tbody>
                                <tfoot>
                                  <tr>
                                    <th class="td" scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px;">Subtotal:</th>
                                    <td class="td" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;border-top-width:4px;">{{order_subtotal}}</td>
                                  </tr>
                                  <tr>
                                    <th class="td" scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Frete:</th>
                                    <td class="td" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">{{order_shipping}}</td>
                                  </tr>
                                  <tr>
                                    <th class="td" scope="row" colspan="2" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;">Total:</th>
                                    <td class="td" style="color:#636363;border:1px solid #e5e5e5;vertical-align:middle;padding:12px;text-align:left;"><strong>{{order_total}}</strong></td>
                                  </tr>
                                </tfoot>
                              </table>

                              <h2 style="color:#4d77b9;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;margin:0 0 18px;">Pagamentos e Entrega</h2>
                              <p style="margin:0 0 12px;"><strong>Pagamento:</strong> {{payment_method}} · {{payment_status}}</p>
                              <p style="margin:0 0 12px;"><strong>Entrega:</strong> {{shipping_method}}</p>
                              <p style="margin:0 0 20px;">{{shipping_method_description}}</p>

                              <h2 style="color:#4d77b9;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:18px;font-weight:bold;margin:0 0 18px;">Dados do cliente</h2>
                              <p style="margin:0 0 6px;"><strong>Nome:</strong> {{billing_full_name}}</p>
                              <p style="margin:0 0 6px;"><strong>E-mail:</strong> <a href="mailto:{{billing_email_href}}">{{billing_email}}</a></p>
                              <p style="margin:0 0 6px;"><strong>Telefone:</strong> {{billing_phone}}</p>
                              <p style="margin:0 0 20px;"><strong>Endereço:</strong><br>{{billing_address_html}}</p>
                              <p style="margin:0 0 16px;"><strong>Observações do cliente:</strong> {{customer_note}}</p>
                              <p style="margin:0 0 24px;"><a href="{{admin_order_url}}" style="color:#4d77b9;">Abrir pedido no painel</a></p>
                            </div>
                          </td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td align="center" valign="top">
                <table border="0" cellpadding="10" cellspacing="0" width="600" id="template_footer">
                  <tr>
                    <td valign="top" style="padding:32px 48px;">
                      <table border="0" cellpadding="0" cellspacing="0" width="100%">
                        <tr>
                          <td valign="middle" id="credit" style="color:#999999;font-family:'Helvetica Neue',Helvetica,Roboto,Arial,sans-serif;font-size:12px;line-height:150%;text-align:center;">
                            <p style="margin:0;">{{store_name}} · Central administrativa.</p>
                          </td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>
</body>
</html>
HTML;

        return [
            'customer_subject' => $customerSubject,
            'customer_body' => $customerBody,
            'admin_subject' => $adminSubject,
            'admin_body' => $adminBody,
        ];
    }
}

if (!function_exists('email_render_template')) {
    function email_render_template($template, array $vars) {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{{' . $key . '}}'] = (string)$value;
        }
        return strtr((string)$template, $replacements);
    }
}

if (!function_exists('email_build_order_rows')) {
    function email_build_order_rows(array $items, string $defaultCurrency): string {
        if (!$items) {
            return '<tr><td colspan="3">Nenhum item informado</td></tr>';
        }
        $rows = '';
        foreach ($items as $item) {
            $name = sanitize_html($item['name'] ?? '');
            $qty = max(1, (int)($item['qty'] ?? 0));
            $priceValue = (float)($item['price'] ?? 0);
            $itemCurrency = $item['currency'] ?? $defaultCurrency;
            $metaParts = [];
            if (!empty($item['sku'])) {
                $metaParts[] = 'SKU: ' . sanitize_html($item['sku']);
            }
            $meta = $metaParts ? '<br><span class="muted">' . implode(' • ', $metaParts) . '</span>' : '';
            $rows .= '<tr><td>' . $name . $meta . '</td><td>' . $qty . '</td><td>' . format_currency($priceValue * $qty, $itemCurrency) . '</td></tr>';
        }
        return $rows;
    }
}

if (!function_exists('send_order_confirmation')) {
    function send_order_confirmation($order_id, $customer_email) {
        email_templates_maybe_upgrade();
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT o.*,
                       c.first_name AS customer_first_name,
                       c.last_name  AS customer_last_name,
                       c.name       AS customer_name,
                       c.email      AS customer_email,
                       c.phone      AS customer_phone,
                       c.address    AS customer_address,
                       c.address2   AS customer_address2,
                       c.city       AS customer_city,
                       c.state      AS customer_state,
                       c.zipcode    AS customer_zipcode,
                       c.country    AS customer_country
                FROM orders o
                LEFT JOIN customers c ON c.id = o.customer_id
                WHERE o.id = ?
            ");
            $stmt->execute([(int)$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                return false;
            }

            $items = json_decode($order['items_json'] ?? '[]', true);
            if (!is_array($items)) {
                $items = [];
            }

            $cfg = cfg();
            $storeInfo = store_info();
            $storeName = $storeInfo['name'] ?? 'Sua Loja';
            $orderCurrency = strtoupper($order['currency'] ?? ($storeInfo['currency'] ?? ($cfg['store']['currency'] ?? 'USD')));

            $defaults   = email_template_defaults($storeName);
            $subjectTpl = setting_get('email_customer_subject', $defaults['customer_subject']);
            $bodyTpl    = setting_get('email_customer_body', $defaults['customer_body']);

            $orderItemsRows = email_build_order_rows($items, $orderCurrency);
            $itemsHtmlList = '<ul style="padding-left:18px;margin:0;">';
            foreach ($items as $item) {
                $nm = sanitize_html($item['name'] ?? '');
                $qt = max(1, (int)($item['qty'] ?? 0));
                $vl = (float)($item['price'] ?? 0);
                $itemCurrency = $item['currency'] ?? $orderCurrency;
                $itemsHtmlList .= '<li>'.$nm.' — Qtd: '.$qt.' — '.format_currency($vl * $qt, $itemCurrency).'</li>';
            }
            $itemsHtmlList .= '</ul>';

            $subtotalVal = (float)($order['subtotal'] ?? 0);
            $shippingVal = (float)($order['shipping_cost'] ?? 0);
            $totalVal    = (float)($order['total'] ?? 0);
            $taxFormatted = format_currency(0, $orderCurrency);
            $discountFormatted = format_currency(0, $orderCurrency);

            $baseUrl = rtrim($cfg['store']['base_url'] ?? '', '/');
            $trackToken = trim((string)($order['track_token'] ?? ''));
            $trackUrl = '';
            if ($trackToken !== '') {
                if ($baseUrl !== '') {
                    $trackUrl = rtrim($baseUrl, '/') . '/index.php?route=track&code=' . urlencode($trackToken);
                } else {
                    $trackUrl = '/index.php?route=track&code=' . urlencode($trackToken);
                }
            }
            $safeTrackUrl = $trackUrl ? sanitize_html($trackUrl) : '';
            $trackLink = $safeTrackUrl ? '<a href="'.$safeTrackUrl.'">'.$safeTrackUrl.'</a>' : '—';

            $paymentLabel = $order['payment_method'] ?? '-';
            try {
                $pm = $pdo->prepare("SELECT name FROM payment_methods WHERE code = ? LIMIT 1");
                $pm->execute([$order['payment_method'] ?? '']);
                $pmName = $pm->fetchColumn();
                if ($pmName) {
                    $paymentLabel = $pmName;
                }
            } catch (Throwable $e) {
                // ignore
            }

            $billingFirst = trim((string)($order['customer_first_name'] ?? ''));
            $billingLast  = trim((string)($order['customer_last_name'] ?? ''));
            $billingFull  = trim($billingFirst.' '.$billingLast);
            if ($billingFull === '') {
                $billingFull = trim((string)($order['customer_name'] ?? ''));
            }
            if ($billingFirst === '' && $billingFull !== '') {
                $billingFirst = $billingFull;
            }

            $addressLine1 = trim((string)($order['customer_address'] ?? ''));
            $addressLine2 = trim((string)($order['customer_address2'] ?? ''));
            $city = trim((string)($order['customer_city'] ?? ''));
            $state = trim((string)($order['customer_state'] ?? ''));
            $zipcode = trim((string)($order['customer_zipcode'] ?? ''));
            $country = trim((string)($order['customer_country'] ?? ''));

            $cityStateZip = trim($city);
            if ($state !== '') {
                $cityStateZip = $cityStateZip ? $cityStateZip.' - '.$state : $state;
            }
            if ($zipcode !== '') {
                $cityStateZip = $cityStateZip ? $cityStateZip.' '.$zipcode : $zipcode;
            }
            $addressParts = array_filter([$addressLine1, $addressLine2, $cityStateZip, $country], fn($part) => trim((string)$part) !== '');
            $billingAddressHtml = $addressParts ? implode('<br>', array_map('sanitize_html', $addressParts)) : '—';

            $deliveryCode = trim((string)($order['delivery_method_code'] ?? ''));
            $deliveryLabel = trim((string)($order['delivery_method_label'] ?? ''));
            $deliveryDetails = trim((string)($order['delivery_method_details'] ?? ''));
            if ($deliveryLabel === '' && $deliveryCode !== '') {
                $method = checkout_find_delivery_method($deliveryCode);
                if ($method) {
                    $deliveryLabel = $method['name'] ?? '';
                    if ($deliveryDetails === '' && !empty($method['description'])) {
                        $deliveryDetails = $method['description'];
                    }
                }
            }
            if ($deliveryLabel === '') {
                $deliveryLabel = 'Não informado';
            }
            if ($deliveryDetails === '') {
                $deliveryDetails = '—';
            }

            $supportEmail = $storeInfo['email'] ?? ($cfg['store']['support_email'] ?? '');
            $additionalContent = 'Dúvidas? Responda este e-mail';
            if ($supportEmail) {
                $safeSupport = sanitize_html($supportEmail);
                $additionalContent .= ' ou fale com nossa equipe em <a href="mailto:'.$safeSupport.'">'.$safeSupport.'</a>.';
            } else {
                $additionalContent .= '.';
            }

            $orderDate = $order['created_at'] ?? '';
            try {
                $dt = $orderDate ? new DateTimeImmutable($orderDate) : new DateTimeImmutable();
                $orderDateFormatted = $dt->format('d/m/Y H:i');
            } catch (Throwable $e) {
                $orderDateFormatted = date('d/m/Y H:i');
            }

            $formatValue = function ($value) {
                $value = trim((string)$value);
                return $value !== '' ? sanitize_html($value) : '—';
            };

            $billingFirstSafe   = $formatValue($billingFirst);
            $billingLastSafe    = $formatValue($billingLast);
            $billingFullSafe    = $formatValue($billingFull);
            $billingEmailRaw    = trim((string)($order['customer_email'] ?? $customer_email));
            $billingEmailSafe   = $billingEmailRaw !== '' ? sanitize_html($billingEmailRaw) : '—';
            $billingEmailHref   = $billingEmailRaw !== '' ? sanitize_html($billingEmailRaw) : '';
            $billingPhoneRaw    = trim((string)($order['customer_phone'] ?? ''));
            $billingPhoneSafe   = $billingPhoneRaw !== '' ? sanitize_html($billingPhoneRaw) : '—';
            $address1Safe       = $formatValue($addressLine1);
            $address2Safe       = $formatValue($addressLine2);
            $citySafe           = $formatValue($city);
            $stateSafe          = $formatValue($state);
            $zipcodeSafe        = $formatValue($zipcode);
            $countrySafe        = $formatValue($country);

            $deliveryCodeRaw    = trim((string)$deliveryMethodCode);
            $deliveryCodeSafe   = $deliveryCodeRaw !== '' ? sanitize_html($deliveryCodeRaw) : '';

            $vars = [
                'store_name' => sanitize_html($storeName),
                'site_name' => sanitize_html($storeName),
                'store_logo_url' => email_logo_url(),
                'order_id' => (string)$order_id,
                'order_number' => '#'.$order_id,
                'order_date' => sanitize_html($orderDateFormatted),
                'customer_name' => sanitize_html($order['customer_name'] ?? $billingFull),
                'customer_email' => sanitize_html($order['customer_email'] ?? $customer_email),
                'customer_phone' => $billingPhoneSafe,
                'billing_first_name' => $billingFirstSafe,
                'billing_last_name' => $billingLastSafe,
                'billing_full_name' => $billingFullSafe,
                'billing_email' => $billingEmailSafe,
                'billing_email_href' => $billingEmailHref,
                'billing_phone' => $billingPhoneSafe,
                'billing_address1' => $address1Safe,
                'billing_address2' => $address2Safe,
                'billing_city' => $citySafe,
                'billing_state' => $stateSafe,
                'billing_postcode' => $zipcodeSafe,
                'billing_country' => $countrySafe,
                'billing_address_html' => $billingAddressHtml,
                'billing_address' => $billingAddressHtml,
                'customer_full_address' => $billingAddressHtml,
                'shipping_address1' => $address1Safe,
                'shipping_address2' => $address2Safe,
                'shipping_city' => $citySafe,
                'shipping_state' => $stateSafe,
                'shipping_postcode' => $zipcodeSafe,
                'shipping_country' => $countrySafe,
                'shipping_address_html' => $billingAddressHtml,
                'shipping_address' => $billingAddressHtml,
                'shipping_full_address' => $billingAddressHtml,
                'order_total' => format_currency($totalVal, $orderCurrency),
                'order_subtotal' => format_currency($subtotalVal, $orderCurrency),
                'order_shipping' => format_currency($shippingVal, $orderCurrency),
                'order_shipping_total' => format_currency($shippingVal, $orderCurrency),
                'order_tax_total' => $taxFormatted,
                'order_discount_total' => $discountFormatted,
                'order_items' => $orderItemsRows,
                'order_items_rows' => $orderItemsRows,
                'order_items_list' => $itemsHtmlList,
                'payment_method' => sanitize_html($paymentLabel),
                'payment_status' => sanitize_html($order['payment_status'] ?? 'pending'),
                'payment_reference' => sanitize_html($order['payment_ref'] ?? ''),
                'customer_note' => sanitize_html($order['notes'] ?? '—'),
                'order_notes' => sanitize_html($order['notes'] ?? '—'),
                'track_link' => $trackLink,
                'track_url' => $safeTrackUrl,
                'support_email' => sanitize_html($supportEmail ?? ''),
                'shipping_method' => sanitize_html($deliveryLabel),
                'shipping_method_description' => sanitize_html($deliveryDetails),
                'shipping_address' => $billingAddressHtml,
                'delivery_method_code' => $deliveryCodeSafe,
                'additional_content' => $additionalContent,
                'year' => date('Y'),
            ];

            $subjectVars = $vars;
            $subjectVars['order_items'] = '';
            $subjectVars['order_items_rows'] = '';
            $subjectVars['track_link'] = $safeTrackUrl ?: '';

            $subject = email_render_template($subjectTpl, $subjectVars);
            $body    = email_render_template($bodyTpl, $vars);

            return send_email($customer_email, $subject, $body);
        } catch (Throwable $e) {
            error_log('Failed to send order confirmation: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('send_order_admin_alert')) {
    function send_order_admin_alert($order_id, $extraEmails = null) {
        email_templates_maybe_upgrade();
        try {
            $pdo = db();
            $stmt = $pdo->prepare("
                SELECT o.*,
                       c.first_name AS customer_first_name,
                       c.last_name  AS customer_last_name,
                       c.name       AS customer_name,
                       c.email      AS customer_email,
                       c.phone      AS customer_phone,
                       c.address    AS customer_address,
                       c.address2   AS customer_address2,
                       c.city       AS customer_city,
                       c.state      AS customer_state,
                       c.zipcode    AS customer_zipcode,
                       c.country    AS customer_country
                FROM orders o
                LEFT JOIN customers c ON c.id = o.customer_id
                WHERE o.id = ?
            ");
            $stmt->execute([(int)$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                return false;
            }

            $items = json_decode($order['items_json'] ?? '[]', true);
            if (!is_array($items)) {
                $items = [];
            }

            $cfg = cfg();
            $storeInfo = store_info();
            $storeName = $storeInfo['name'] ?? ($cfg['store']['name'] ?? 'Sua Loja');
            $orderCurrency = strtoupper($order['currency'] ?? ($storeInfo['currency'] ?? ($cfg['store']['currency'] ?? 'USD')));

            $defaults   = email_template_defaults($storeName);
            $subjectTpl = setting_get('email_admin_subject', $defaults['admin_subject']);
            $bodyTpl    = setting_get('email_admin_body', $defaults['admin_body']);

            $orderItemsRows = email_build_order_rows($items, $orderCurrency);
            $itemsHtmlList = '<ul style="padding-left:18px;margin:0;">';
            for ($i = 0, $n = count($items); $i < $n; $i++) {
                $item = $items[$i];
                $nm = sanitize_html($item['name'] ?? '');
                $qt = max(1, (int)($item['qty'] ?? 0));
                $vl = (float)($item['price'] ?? 0);
                $itemCurrency = $item['currency'] ?? $orderCurrency;
                $itemsHtmlList .= '<li>'.$nm.' — Qtd: '.$qt.' — '.format_currency($vl * $qt, $itemCurrency).'</li>';
            }
            $itemsHtmlList .= '</ul>';

            $subtotalVal = (float)($order['subtotal'] ?? 0);
            $shippingVal = (float)($order['shipping_cost'] ?? 0);
            $totalVal    = (float)($order['total'] ?? 0);
            $taxFormatted = format_currency(0, $orderCurrency);
            $discountFormatted = format_currency(0, $orderCurrency);

            $baseUrl = rtrim($cfg['store']['base_url'] ?? '', '/');
            $adminOrderUrl = $baseUrl ? $baseUrl.'/admin.php?route=orders&action=view&id='.$order_id : 'admin.php?route=orders&action=view&id='.$order_id;

            $trackToken = trim((string)($order['track_token'] ?? ''));
            $trackUrl = '';
            if ($trackToken !== '') {
                if ($baseUrl !== '') {
                    $trackUrl = rtrim($baseUrl, '/') . '/index.php?route=track&code=' . urlencode($trackToken);
                } else {
                    $trackUrl = '/index.php?route=track&code=' . urlencode($trackToken);
                }
            }
            $safeTrackUrl = $trackUrl ? sanitize_html($trackUrl) : '';
            $trackLink = $safeTrackUrl ? '<a href="'.$safeTrackUrl.'">'.$safeTrackUrl.'</a>' : '—';

            $paymentLabel = $order['payment_method'] ?? '-';
            try {
                $pm = $pdo->prepare("SELECT name FROM payment_methods WHERE code = ? LIMIT 1");
                $pm->execute([$order['payment_method'] ?? '']);
                $pmName = $pm->fetchColumn();
                if ($pmName) {
                    $paymentLabel = $pmName;
                }
            } catch (Throwable $e) {
            }

            $billingFirst = trim((string)($order['customer_first_name'] ?? ''));
            $billingLast  = trim((string)($order['customer_last_name'] ?? ''));
            $billingFull  = trim($billingFirst.' '.$billingLast);
            if ($billingFull === '') {
                $billingFull = trim((string)($order['customer_name'] ?? ''));
            }
            if ($billingFirst === '' && $billingFull !== '') {
                $billingFirst = $billingFull;
            }

            $addressLine1 = trim((string)($order['customer_address'] ?? ''));
            $addressLine2 = trim((string)($order['customer_address2'] ?? ''));
            $city = trim((string)($order['customer_city'] ?? ''));
            $state = trim((string)($order['customer_state'] ?? ''));
            $zipcode = trim((string)($order['customer_zipcode'] ?? ''));
            $country = trim((string)($order['customer_country'] ?? ''));

            $cityStateZip = trim($city);
            if ($state !== '') {
                $cityStateZip = $cityStateZip ? $cityStateZip.' - '.$state : $state;
            }
            if ($zipcode !== '') {
                $cityStateZip = $cityStateZip ? $cityStateZip.' '.$zipcode : $zipcode;
            }
            $addressParts = array_filter([$addressLine1, $addressLine2, $cityStateZip, $country], fn($part) => trim((string)$part) !== '');
            $billingAddressHtml = $addressParts ? implode('<br>', array_map('sanitize_html', $addressParts)) : '—';

            $deliveryCode = trim((string)($order['delivery_method_code'] ?? ''));
            $deliveryLabel = trim((string)($order['delivery_method_label'] ?? ''));
            $deliveryDetails = trim((string)($order['delivery_method_details'] ?? ''));
            if ($deliveryLabel === '' && $deliveryCode !== '') {
                $method = checkout_find_delivery_method($deliveryCode);
                if ($method) {
                    $deliveryLabel = $method['name'] ?? '';
                    if ($deliveryDetails === '' && !empty($method['description'])) {
                        $deliveryDetails = $method['description'];
                    }
                }
            }
            if ($deliveryLabel === '') {
                $deliveryLabel = 'Não informado';
            }
            if ($deliveryDetails === '') {
                $deliveryDetails = '—';
            }

            $supportEmail = $storeInfo['email'] ?? ($cfg['store']['support_email'] ?? null);
            if (!$supportEmail && defined('ADMIN_EMAIL')) {
                $supportEmail = ADMIN_EMAIL;
            }

            $additionalContent = 'Gerencie este pedido no painel: <a href="'.sanitize_html($adminOrderUrl).'">'.sanitize_html($adminOrderUrl).'</a>';

            $orderDate = $order['created_at'] ?? '';
            try {
                $dt = $orderDate ? new DateTimeImmutable($orderDate) : new DateTimeImmutable();
                $orderDateFormatted = $dt->format('d/m/Y H:i');
            } catch (Throwable $e) {
                $orderDateFormatted = date('d/m/Y H:i');
            }

            $formatValue = function ($value) {
                $value = trim((string)$value);
                return $value !== '' ? sanitize_html($value) : '—';
            };

            $billingFirstSafe   = $formatValue($billingFirst);
            $billingLastSafe    = $formatValue($billingLast);
            $billingFullSafe    = $formatValue($billingFull);
            $billingEmailRaw    = trim((string)($order['customer_email'] ?? ''));
            $billingEmailSafe   = $billingEmailRaw !== '' ? sanitize_html($billingEmailRaw) : '—';
            $billingEmailHref   = $billingEmailRaw !== '' ? sanitize_html($billingEmailRaw) : '';
            $billingPhoneRaw    = trim((string)($order['customer_phone'] ?? ''));
            $billingPhoneSafe   = $billingPhoneRaw !== '' ? sanitize_html($billingPhoneRaw) : '—';
            $address1Safe       = $formatValue($addressLine1);
            $address2Safe       = $formatValue($addressLine2);
            $citySafe           = $formatValue($city);
            $stateSafe          = $formatValue($state);
            $zipcodeSafe        = $formatValue($zipcode);
            $countrySafe        = $formatValue($country);

            $deliveryCodeRaw    = trim((string)$deliveryCode);
            $deliveryCodeSafe   = $deliveryCodeRaw !== '' ? sanitize_html($deliveryCodeRaw) : '';

            $vars = [
                'store_name' => sanitize_html($storeName),
                'site_name' => sanitize_html($storeName),
                'store_logo_url' => email_logo_url(),
                'order_id' => (string)$order_id,
                'order_number' => '#'.$order_id,
                'order_date' => sanitize_html($orderDateFormatted),
                'customer_name' => $billingFullSafe,
                'customer_email' => $billingEmailSafe,
                'customer_phone' => $billingPhoneSafe,
                'billing_first_name' => $billingFirstSafe,
                'billing_last_name' => $billingLastSafe,
                'billing_full_name' => $billingFullSafe,
                'billing_email' => $billingEmailSafe,
                'billing_email_href' => $billingEmailHref,
                'billing_phone' => $billingPhoneSafe,
                'billing_address1' => $address1Safe,
                'billing_address2' => $address2Safe,
                'billing_city' => $citySafe,
                'billing_state' => $stateSafe,
                'billing_postcode' => $zipcodeSafe,
                'billing_country' => $countrySafe,
                'billing_address_html' => $billingAddressHtml,
                'billing_address' => $billingAddressHtml,
                'customer_full_address' => $billingAddressHtml,
                'shipping_address1' => $address1Safe,
                'shipping_address2' => $address2Safe,
                'shipping_city' => $citySafe,
                'shipping_state' => $stateSafe,
                'shipping_postcode' => $zipcodeSafe,
                'shipping_country' => $countrySafe,
                'shipping_address_html' => $billingAddressHtml,
                'shipping_address' => $billingAddressHtml,
                'shipping_full_address' => $billingAddressHtml,
                'order_total' => format_currency($totalVal, $orderCurrency),
                'order_subtotal' => format_currency($subtotalVal, $orderCurrency),
                'order_shipping' => format_currency($shippingVal, $orderCurrency),
                'order_shipping_total' => format_currency($shippingVal, $orderCurrency),
                'order_tax_total' => $taxFormatted,
                'order_discount_total' => $discountFormatted,
                'order_items' => $orderItemsRows,
                'order_items_rows' => $orderItemsRows,
                'order_items_list' => $itemsHtmlList,
                'payment_method' => sanitize_html($paymentLabel),
                'payment_status' => sanitize_html($order['payment_status'] ?? 'pending'),
                'payment_reference' => sanitize_html($order['payment_ref'] ?? ''),
                'customer_note' => sanitize_html($order['notes'] ?? '—'),
                'order_notes' => sanitize_html($order['notes'] ?? '—'),
                'track_link' => $trackLink,
                'track_url' => $safeTrackUrl,
                'shipping_method' => sanitize_html($deliveryLabel),
                'shipping_method_description' => sanitize_html($deliveryDetails),
                'shipping_address' => $billingAddressHtml,
                'delivery_method_code' => $deliveryCodeSafe,
                'admin_order_url' => sanitize_html($adminOrderUrl),
                'additional_content' => $additionalContent,
                'year' => date('Y'),
            ];

            $subjectVars = $vars;
            $subjectVars['order_items'] = '';
            $subjectVars['order_items_rows'] = '';

            $subject = email_render_template($subjectTpl, $subjectVars);
            $body    = email_render_template($bodyTpl, $vars);

            $recipients = [];
            if ($extraEmails) {
                if (is_array($extraEmails)) {
                    $recipients = array_merge($recipients, $extraEmails);
                } else {
                    $recipients[] = (string)$extraEmails;
                }
            }
            if ($supportEmail) {
                $recipients[] = $supportEmail;
            }

            $recipients = array_filter(array_unique(array_map('trim', $recipients)), fn($email) => validate_email($email));
            if (!$recipients) {
                return false;
            }

            $success = true;
            foreach ($recipients as $recipient) {
                if (!send_email($recipient, $subject, $body)) {
                    $success = false;
                }
            }
            return $success;
        } catch (Throwable $e) {
            error_log('Failed to send admin alert: ' . $e->getMessage());
            return false;
        }
    }
}


/* =========================================================================
   Checkout — países, estados e métodos de entrega configuráveis
   ========================================================================= */
if (!function_exists('checkout_default_countries')) {
    function checkout_default_countries(): array {
        return [
            ['code' => 'US', 'name' => 'Estados Unidos'],
        ];
    }
}

if (!function_exists('checkout_default_states')) {
    function checkout_default_states(): array {
        return [
            ['country' => 'US', 'code' => 'AL', 'name' => 'Alabama'],
            ['country' => 'US', 'code' => 'AK', 'name' => 'Alaska'],
            ['country' => 'US', 'code' => 'AZ', 'name' => 'Arizona'],
            ['country' => 'US', 'code' => 'AR', 'name' => 'Arkansas'],
            ['country' => 'US', 'code' => 'CA', 'name' => 'Califórnia'],
            ['country' => 'US', 'code' => 'CO', 'name' => 'Colorado'],
            ['country' => 'US', 'code' => 'CT', 'name' => 'Connecticut'],
            ['country' => 'US', 'code' => 'DE', 'name' => 'Delaware'],
            ['country' => 'US', 'code' => 'DC', 'name' => 'Distrito de Columbia'],
            ['country' => 'US', 'code' => 'FL', 'name' => 'Flórida'],
            ['country' => 'US', 'code' => 'GA', 'name' => 'Geórgia'],
            ['country' => 'US', 'code' => 'HI', 'name' => 'Havaí'],
            ['country' => 'US', 'code' => 'ID', 'name' => 'Idaho'],
            ['country' => 'US', 'code' => 'IL', 'name' => 'Illinois'],
            ['country' => 'US', 'code' => 'IN', 'name' => 'Indiana'],
            ['country' => 'US', 'code' => 'IA', 'name' => 'Iowa'],
            ['country' => 'US', 'code' => 'KS', 'name' => 'Kansas'],
            ['country' => 'US', 'code' => 'KY', 'name' => 'Kentucky'],
            ['country' => 'US', 'code' => 'LA', 'name' => 'Louisiana'],
            ['country' => 'US', 'code' => 'ME', 'name' => 'Maine'],
            ['country' => 'US', 'code' => 'MD', 'name' => 'Maryland'],
            ['country' => 'US', 'code' => 'MA', 'name' => 'Massachusetts'],
            ['country' => 'US', 'code' => 'MI', 'name' => 'Michigan'],
            ['country' => 'US', 'code' => 'MN', 'name' => 'Minnesota'],
            ['country' => 'US', 'code' => 'MS', 'name' => 'Mississippi'],
            ['country' => 'US', 'code' => 'MO', 'name' => 'Missouri'],
            ['country' => 'US', 'code' => 'MT', 'name' => 'Montana'],
            ['country' => 'US', 'code' => 'NE', 'name' => 'Nebraska'],
            ['country' => 'US', 'code' => 'NV', 'name' => 'Nevada'],
            ['country' => 'US', 'code' => 'NH', 'name' => 'New Hampshire'],
            ['country' => 'US', 'code' => 'NJ', 'name' => 'New Jersey'],
            ['country' => 'US', 'code' => 'NM', 'name' => 'Novo México'],
            ['country' => 'US', 'code' => 'NY', 'name' => 'Nova Iorque'],
            ['country' => 'US', 'code' => 'NC', 'name' => 'Carolina do Norte'],
            ['country' => 'US', 'code' => 'ND', 'name' => 'Dacota do Norte'],
            ['country' => 'US', 'code' => 'OH', 'name' => 'Ohio'],
            ['country' => 'US', 'code' => 'OK', 'name' => 'Oklahoma'],
            ['country' => 'US', 'code' => 'OR', 'name' => 'Oregon'],
            ['country' => 'US', 'code' => 'PA', 'name' => 'Pensilvânia'],
            ['country' => 'US', 'code' => 'RI', 'name' => 'Rhode Island'],
            ['country' => 'US', 'code' => 'SC', 'name' => 'Carolina do Sul'],
            ['country' => 'US', 'code' => 'SD', 'name' => 'Dacota do Sul'],
            ['country' => 'US', 'code' => 'TN', 'name' => 'Tennessee'],
            ['country' => 'US', 'code' => 'TX', 'name' => 'Texas'],
            ['country' => 'US', 'code' => 'UT', 'name' => 'Utah'],
            ['country' => 'US', 'code' => 'VT', 'name' => 'Vermont'],
            ['country' => 'US', 'code' => 'VA', 'name' => 'Virgínia'],
            ['country' => 'US', 'code' => 'WA', 'name' => 'Washington'],
            ['country' => 'US', 'code' => 'WV', 'name' => 'Virgínia Ocidental'],
            ['country' => 'US', 'code' => 'WI', 'name' => 'Wisconsin'],
            ['country' => 'US', 'code' => 'WY', 'name' => 'Wyoming'],
        ];
    }
}

if (!function_exists('checkout_default_delivery_methods')) {
    function checkout_default_delivery_methods(): array {
        return [
            [
                'code' => 'standard',
                'name' => 'Entrega padrão (5-7 dias)',
                'description' => 'Envio com rastreio para todos os Estados Unidos. Prazo estimado de 5 a 7 dias úteis.'
            ],
        ];
    }
}

if (!function_exists('checkout_get_countries')) {
    function checkout_get_countries(): array {
        $raw = setting_get('checkout_countries', null);
        $entries = [];
        if (is_array($raw)) {
            $entries = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entries = $decoded;
            } else {
                foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line));
                    if (count($parts) >= 2) {
                        $entries[] = ['code' => $parts[0], 'name' => $parts[1]];
                    }
                }
            }
        }
        $result = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $parts = array_map('trim', explode('|', $entry));
                $code = strtoupper($parts[0] ?? '');
                $name = $parts[1] ?? '';
            } else {
                $code = strtoupper(trim((string)($entry['code'] ?? '')));
                $name = trim((string)($entry['name'] ?? ''));
            }
            if ($code === '' || $name === '') {
                continue;
            }
            $result[$code] = ['code' => $code, 'name' => $name];
        }
        if (!$result) {
            $defaults = checkout_default_countries();
            foreach ($defaults as $item) {
                $code = strtoupper($item['code']);
                $result[$code] = ['code' => $code, 'name' => $item['name']];
            }
        }
        return array_values($result);
    }
}

if (!function_exists('checkout_get_states')) {
    function checkout_get_states(): array {
        $raw = setting_get('checkout_states', null);
        $entries = [];
        if (is_array($raw)) {
            $entries = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entries = $decoded;
            } else {
                foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line));
                    if (count($parts) === 3) {
                        $entries[] = ['country' => $parts[0], 'code' => $parts[1], 'name' => $parts[2]];
                    } elseif (count($parts) === 2) {
                        $entries[] = ['country' => 'US', 'code' => $parts[0], 'name' => $parts[1]];
                    }
                }
            }
        }

        $result = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $parts = array_map('trim', explode('|', $entry));
                if (count($parts) === 3) {
                    [$country, $code, $name] = $parts;
                } elseif (count($parts) === 2) {
                    $country = 'US';
                    [$code, $name] = $parts;
                } else {
                    continue;
                }
            } else {
                $country = strtoupper(trim((string)($entry['country'] ?? 'US')));
                $code    = strtoupper(trim((string)($entry['code'] ?? '')));
                $name    = trim((string)($entry['name'] ?? ''));
            }
            if ($code === '' || $name === '') {
                continue;
            }
            $country = strtoupper($country ?: 'US');
            $result[] = [
                'country' => $country,
                'code'    => $code,
                'name'    => $name,
            ];
        }
        if (!$result) {
            $result = checkout_default_states();
        }
        return $result;
    }
}

if (!function_exists('checkout_group_states')) {
    function checkout_group_states(): array {
        $states = checkout_get_states();
        $grouped = [];
        foreach ($states as $state) {
            $country = strtoupper(trim((string)($state['country'] ?? 'US')));
            if (!isset($grouped[$country])) {
                $grouped[$country] = [];
            }
            $grouped[$country][] = [
                'country' => $country,
                'code'    => strtoupper(trim((string)$state['code'] ?? '')),
                'name'    => trim((string)$state['name'] ?? ''),
            ];
        }
        return $grouped;
    }
}

if (!function_exists('checkout_get_states_by_country')) {
    function checkout_get_states_by_country(string $country): array {
        $country = strtoupper(trim($country));
        $grouped = checkout_group_states();
        return $grouped[$country] ?? [];
    }
}

if (!function_exists('checkout_get_delivery_methods')) {
    function checkout_get_delivery_methods(): array {
        $raw = setting_get('checkout_delivery_methods', null);
        $entries = [];
        if (is_array($raw)) {
            $entries = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $entries = $decoded;
            } else {
                foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $parts = array_map('trim', explode('|', $line));
                    if (count($parts) >= 2) {
                        $entries[] = [
                            'code' => $parts[0],
                            'name' => $parts[1],
                            'description' => $parts[2] ?? ''
                        ];
                    }
                }
            }
        }

        $result = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $parts = array_map('trim', explode('|', $entry));
                $code = $parts[0] ?? '';
                $name = $parts[1] ?? '';
                $description = $parts[2] ?? '';
            } else {
                $code = trim((string)($entry['code'] ?? ''));
                $name = trim((string)($entry['name'] ?? ''));
                $description = trim((string)($entry['description'] ?? ''));
            }
            if ($name === '') {
                continue;
            }
            $slug = $code !== '' ? strtolower(preg_replace('/[^a-z0-9\-_]+/i', '-', $code)) : slugify($name);
            if ($slug === '') {
                continue;
            }
            $result[$slug] = [
                'code' => $slug,
                'name' => $name,
                'description' => $description,
            ];
        }
        if (!$result) {
            $defaults = checkout_default_delivery_methods();
            foreach ($defaults as $item) {
                $slug = strtolower(preg_replace('/[^a-z0-9\-_]+/i', '-', $item['code'] ?? slugify($item['name'] ?? '')));
                $result[$slug] = [
                    'code' => $slug ?: 'standard',
                    'name' => $item['name'] ?? 'Entrega padrão',
                    'description' => $item['description'] ?? '',
                ];
            }
        }
        return array_values($result);
    }
}

if (!function_exists('checkout_find_delivery_method')) {
    function checkout_find_delivery_method(string $code): ?array {
        $code = strtolower(trim($code));
        foreach (checkout_get_delivery_methods() as $method) {
            if (strtolower($method['code']) === $code) {
                return $method;
            }
        }
        return null;
    }
}

/* =========================================================================
   Helper para carregar nome/contatos exibidos na loja (com fallback)
   ========================================================================= */
if (!function_exists('store_info')) {
    function store_info() {
        $cfg = cfg();
        return [
            'name'   => setting_get('store_name',   $cfg['store']['name']   ?? 'Get Power Research'),
            'email'  => setting_get('store_email',  $cfg['store']['support_email'] ?? 'contato@example.com'),
            'phone'  => setting_get('store_phone',  $cfg['store']['phone']  ?? '(00) 00000-0000'),
            'addr'   => setting_get('store_address',$cfg['store']['address']?? 'Endereço não configurado'),
            'logo'   => get_logo_path(),
            'currency' => $cfg['store']['currency'] ?? 'BRL',
        ];
    }
}
if (!function_exists('find_logo_path')) {
    function find_logo_path(): ?string {
        $cfgLogo = function_exists('setting_get') ? setting_get('store_logo_url') : null;
        if ($cfgLogo) {
            return ltrim((string)$cfgLogo, '/');
        }
        $candidates = [
            'storage/logo/logo.png',
            'storage/logo/logo.jpg',
            'storage/logo/logo.jpeg',
            'storage/logo/logo.webp',
            'assets/logo.png'
        ];
        foreach ($candidates as $c) {
            if (file_exists(__DIR__ . '/../' . $c)) {
                return $c;
            }
        }
        return null;
    }
}
