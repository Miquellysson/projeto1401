<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__.'/TestCase.php';

if (!function_exists('cfg')) {
    function cfg() {
        return [
            'store' => [
                'name'          => 'Test Store',
                'currency'      => 'USD',
                'support_email' => 'support@test.local',
            ],
        ];
    }
}

if (!function_exists('store_info')) {
    function store_info(): array
    {
        return [
            'name'  => 'Test Store',
            'email' => 'support@test.local',
        ];
    }
}

if (!function_exists('settings_bootstrap')) {
    function settings_bootstrap(): void
    {
        // noop in tests
    }
}

if (!function_exists('setting_get')) {
    $GLOBALS['__test_settings'] = [];
    function setting_get(string $key, $default = null) {
        return $GLOBALS['__test_settings'][$key] ?? $default;
    }
}

if (!function_exists('setting_set')) {
    function setting_set(string $key, $value): bool {
        $GLOBALS['__test_settings'][$key] = $value;
        return true;
    }
}

if (!function_exists('db')) {
    function db() {
        throw new RuntimeException('db() not available in tests');
    }
}
