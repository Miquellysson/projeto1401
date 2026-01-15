<?php
// img.php — proxy simples de imagens remotas com whitelist de hosts
// Uso: /img.php?u=https%3A%2F%2Fbase.rhemacriativa.com%2Fwp-content%2Fuploads%2F2025%2F08%2FAZITROMICINA.png

ini_set('display_errors', 0);
error_reporting(0);

$config = require __DIR__.'/config.php';
$allow = $config['media']['proxy_whitelist'] ?? ['base.rhemacriativa.com'];

$u = $_GET['u'] ?? '';
if (!$u) { http_response_code(400); exit('missing u'); }

$u = urldecode($u);
if (!preg_match('~^https?://~i', $u)) { http_response_code(400); exit('bad url'); }

$host = parse_url($u, PHP_URL_HOST);
if (!$host || !in_array($host, $allow, true)) {
  header('Location: '.$u, true, 302);
  exit;
}

// Baixa via cURL
$ch = curl_init($u);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_CONNECTTIMEOUT => 8,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_USERAGENT => 'ImageProxy/1.0',
  CURLOPT_HTTPHEADER => ['Referer: ', 'Origin: '], // sem referrer
]);
$bin = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($bin === false || $code >= 400) {
  http_response_code(502);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'proxy error'; exit;
}

// Ajusta content-type apenas se plausível
if (!$ctype || stripos($ctype, 'image/') !== 0) {
  // tenta deduzir pela extensão
  $ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
  $map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'];
  $ctype = $map[$ext] ?? 'image/*';
}
header('Content-Type: '.$ctype);
header('Cache-Control: public, max-age=86400, immutable');
echo $bin;
