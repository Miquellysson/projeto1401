<?php

declare(strict_types=1);

if (!function_exists('push_provider_config')) {
    function push_provider_config(): array
    {
        $config = cfg();
        $onesignal = $config['push']['onesignal'] ?? [];
        $appId = getenv('FF_PUSH_ONESIGNAL_APP_ID');
        $restKey = getenv('FF_PUSH_ONESIGNAL_REST_KEY');
        $segment = getenv('FF_PUSH_ONESIGNAL_SEGMENT');
        $safariWebId = getenv('FF_PUSH_ONESIGNAL_SAFARI_WEB_ID');

        if ($appId === false || $appId === '') {
            $appId = $onesignal['app_id'] ?? '';
        }
        if ($restKey === false || $restKey === '') {
            $restKey = $onesignal['rest_key'] ?? '';
        }
        if ($segment === false || $segment === '') {
            $segment = $onesignal['segment'] ?? 'Admins';
        }
        if ($safariWebId === false) {
            $safariWebId = $onesignal['safari_web_id'] ?? '';
        }

        if (!$appId || !$restKey) {
            return [];
        }

        return [
            'provider'     => 'onesignal',
            'app_id'       => $appId,
            'rest_key'     => $restKey,
            'segment'      => $segment ?: 'Admins',
            'safari_web_id'=> $safariWebId ?: '',
        ];
    }
}

if (!function_exists('push_dispatch_notification')) {
    function push_dispatch_notification(string $title, string $message, array $data = []): bool
    {
        $provider = push_provider_config();
        if (!$provider) {
            return false;
        }
        if ($provider['provider'] === 'onesignal') {
            return push_dispatch_onesignal($provider, $title, $message, $data);
        }
        return false;
    }
}

if (!function_exists('push_dispatch_onesignal')) {
    function push_dispatch_onesignal(array $provider, string $title, string $message, array $data = []): bool
    {
        $payload = [
            'app_id'            => $provider['app_id'],
            'included_segments' => [$provider['segment'] ?: 'Admins'],
            'headings'          => ['en' => $title, 'pt' => $title],
            'contents'          => ['en' => $message, 'pt' => $message],
            'data'              => $data ?: new stdClass(),
            'chrome_web_icon'   => '/assets/icons/admin-192.png',
            'web_push_topic'    => 'orders',
        ];
        if (!empty($data['url'])) {
            $payload['url'] = $data['url'];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $endpoint = 'https://onesignal.com/api/v1/notifications';
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic '.$provider['rest_key'],
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) {
                error_log('OneSignal push error: '.$err);
                return false;
            }
            return true;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers),
                'content' => $json,
                'timeout' => 10,
            ],
        ]);
        $result = @file_get_contents($endpoint, false, $context);
        if ($result === false) {
            error_log('OneSignal push error: unable to reach endpoint');
            return false;
        }
        return true;
    }
}
