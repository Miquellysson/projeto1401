<?php
// ==========================
// CONFIGURAÇÕES DO SISTEMA
// ==========================

// Banco de Dados
if (!defined('DB_HOST')) {
    $env = getenv('FF_DB_HOST');
    define('DB_HOST', $env !== false ? $env : '2.59.150.3');
}
if (!defined('DB_NAME')) {
    $env = getenv('FF_DB_NAME');
    define('DB_NAME', $env !== false ? $env : 'u100060033_get_power_ap5');
}
if (!defined('DB_USER')) {
    $env = getenv('FF_DB_USER');
    define('DB_USER', $env !== false ? $env : 'u100060033_get_power_ap5');
}
if (!defined('DB_PASS')) {
    $env = getenv('FF_DB_PASS');
    define('DB_PASS', $env !== false ? $env : 'Get_power_ap5!@#');
}
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Idioma padrão
if (!defined('DEFAULT_LANG')) define('DEFAULT_LANG', getenv('FF_DEFAULT_LANG') ?: 'pt_BR');

// Admin (use variáveis de ambiente; deixe vazio se não quiser seedar credencial)
if (!defined('ADMIN_EMAIL')) {
    $env = getenv('FF_ADMIN_EMAIL');
    define('ADMIN_EMAIL', $env !== false ? $env : '');
}
if (!defined('ADMIN_PASS_HASH')) {
    $env = getenv('FF_ADMIN_PASS_HASH');
    define('ADMIN_PASS_HASH', $env !== false ? $env : '');
}

// ==========================
// CONFIGURAÇÕES DO SITE
// ==========================

return [
    'app_base_url' => getenv('FF_APP_BASE_URL') ?: '',
    'store' => [
        'name'          => 'Get Power Research',
        'currency'      => 'USD',
        'support_email' => 'support@getpowerresearch.com',
        'phone'         => '(00) 00000-0000',
        'address'       => 'Atualize este endereço nas configurações.',
        'base_url'      => getenv('FF_BASE_URL') ?: '',
    ],
    'payments' => [
        'zelle' => [
            'enabled'                => false,
            'recipient_name'         => '',
            'recipient_email'        => '',
            'require_receipt_upload' => true,
        ],
        'venmo' => [
            'enabled' => false,
            'handle'  => '',
        ],
        'pix' => [
            'enabled'       => false,
            'pix_key'       => '',
            'merchant_name' => '',
            'merchant_city' => '',
        ],
        'paypal' => [
            'enabled'    => false,
            'business'   => '',
            'currency'      => 'USD',
            'return_url' => '',
            'cancel_url' => '',
        ],
        'square' => [
            'enabled'      => false,
            'instructions' => 'Abriremos o checkout de cartão de crédito em uma nova aba para concluir o pagamento.',
            'open_new_tab' => true,
        ],
        'whatsapp' => [
            'enabled'   => false,
            'number'    => '',
            'message'   => 'Olá! Gostaria de finalizar meu pedido.',
            'link'      => '',
            'instructions' => 'Finalize seu pedido conversando com nossa equipe pelo WhatsApp: {whatsapp_link}.',
        ],
    ],
    'paths' => [
        'zelle_receipts' => __DIR__ . '/storage/zelle_receipts',
        'products'       => __DIR__ . '/storage/products',
        'logo'           => __DIR__ . '/storage/logo',
    ],
    'media' => [
        'proxy_whitelist' => [
            'base.rhemacriativa.com',
            'store.nestgeneralservices.company',
        ],
    ],
    'notifications' => [
        'sound_enabled'       => true,
        'email_notifications' => true,
        'check_interval'      => 5000, // 5 segundos
    ],
    'push' => [
        'onesignal' => [
            'app_id'        => getenv('FF_PUSH_ONESIGNAL_APP_ID') ?: '80568474-0664-430b-a04f-9d5f4e54f2b6',
            'rest_key'      => getenv('FF_PUSH_ONESIGNAL_REST_KEY') ?: 'os_v2_app_qblii5agmrbqxicptvpu4vhsw3dftspd7e7em7uzo6lqlwtzid7vjrsch4u23awjm6p3tkcsxujvnu5qg4tk4wb26smjerpgtyb3gri',
            'segment'       => getenv('FF_PUSH_ONESIGNAL_SEGMENT') ?: 'Admins',
            'safari_web_id' => getenv('FF_PUSH_ONESIGNAL_SAFARI_WEB_ID') ?: '',
        ],
    ],
    'custom_scripts' => [
        'head'       => getenv('FF_SNIPPET_HEAD') ?: '',
        'body_start' => getenv('FF_SNIPPET_BODY_START') ?: '',
        'body_end'   => getenv('FF_SNIPPET_BODY_END') ?: '',
    ],
];
