<?php
// test-proxy.php — proxy simples para diagnóstico (NÃO deixe em produção)
ini_set('display_errors', 1); error_reporting(E_ALL);

$u = $_GET['u'] ?? '';
if (!$u) { http_response_code(400); echo "missing u"; exit; }
$host = parse_url($u, PHP_URL_HOST);
if (!preg_match('/^base\.rhemacriativa\.com$/i', $host)) {
  http_response_code(403); echo "host not allowed"; exit;
}

$ch = curl_init($u);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_HTTPHEADER => [
    "User-Agent: VF-Proxy/1.0",
    "Accept: */*",
    "Referer: https://victorfarmafacil.com/" // force referer
  ]
]);
$data = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
curl_close($ch);

http_response_code($code);
header("Content-Type: $type");
if ($data !== false) echo $data;
