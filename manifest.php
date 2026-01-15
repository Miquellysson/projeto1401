<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=600');

$cfg = cfg();
$name = setting_get('pwa_name', $cfg['store']['name'] ?? 'Get Power Research');
$short = setting_get('pwa_short_name', $name);
$themeColor = setting_get('theme_color', '#2060C8');
$backgroundColor = setting_get('pwa_background_color', $themeColor);
$description = setting_get('store_meta_title', $name.' | Loja');

$icons = [];
foreach ([192, 512] as $size) {
    $path = get_pwa_icon_path($size);
    if ($path === '') {
        continue;
    }
    $icons[] = [
        'src' => pwa_icon_url($size),
        'sizes' => $size . 'x' . $size,
        'type' => 'image/png',
        'purpose' => 'any maskable'
    ];
}
if (!$icons) {
    $icons = [
        [
            'src' => app_public_path('assets/icons/admin-192.png') ?: '/assets/icons/admin-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => app_public_path('assets/icons/admin-512.png') ?: '/assets/icons/admin-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ];
}

$manifest = [
    'name' => $name,
    'short_name' => $short,
    'start_url' => './?source=pwa',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => $backgroundColor,
    'theme_color' => $themeColor,
    'description' => $description,
    'icons' => $icons
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
