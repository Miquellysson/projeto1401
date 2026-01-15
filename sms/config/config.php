<?php
// Simple configuration loader. Reads .env (key=value) and exposes helper functions.

// Load .env if present
$envPath = __DIR__ . '/../.env';
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!getenv($key)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function env(string $key, $default = null)
{
    return $_ENV[$key] ?? getenv($key) ?? $default;
}

return [
    'db' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'sms_app'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
    ],
    'session_name' => env('SESSION_NAME', 'sms_app_session'),
    'app_url' => env('APP_URL', 'http://localhost'),
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],
];
