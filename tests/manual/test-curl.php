<?php
// test-curl.php
// Uso: /test-curl.php?u=URL_COMPLETA&method=head|get&ref=none|victor|custom&custom_ref=https://foo.bar
// Ex.: /test-curl.php?u=https://base.rhemacriativa.com/wp-content/uploads/2025/08/AZITROMICINA.png&method=head&ref=victor

error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$u = isset($_GET['u']) ? trim($_GET['u']) : '';
$m = strtolower($_GET['method'] ?? 'head');
$r = strtolower($_GET['ref'] ?? 'none');
$cr = $_GET['custom_ref'] ?? '';

if (!$u || !preg_match('~^https?://~i', $u)) {
  http_response_code(400);
  echo "ERRO: passe ?u=URL_COMPLETA (http/https)\n";
  exit;
}
if (!in_array($m, ['head','get'], true)) $m = 'head';

$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL            => $u,
  CURLOPT_NOBODY         => ($m === 'head'),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HEADER         => true,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_TIMEOUT        => 20,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSL_VERIFYHOST => false,
  CURLOPT_USERAGENT      => 'DiagHotlink/1.0 (+victorfarmafacil.com)'
]);

// Referer
if ($r === 'victor') {
  curl_setopt($ch, CURLOPT_REFERER, 'https://victorfarmafacil.com/');
} elseif ($r === 'custom' && $cr) {
  curl_setopt($ch, CURLOPT_REFERER, $cr);
} elseif ($r === 'none') {
  curl_setopt($ch, CURLOPT_REFERER, null); // sem referer
}

// Executa
$resp = curl_exec($ch);
if ($resp === false) {
  echo "CURL ERROR: ".curl_error($ch)."\n";
  curl_close($ch);
  exit;
}
$info = curl_getinfo($ch);
curl_close($ch);

// Se GET, separe headers/body
$headers = $resp;
$bodyLen = 0;
if ($m === 'get') {
  $headerSize = $info['header_size'] ?? 0;
  $headers = substr($resp, 0, $headerSize);
  $body    = substr($resp, $headerSize);
  $bodyLen = strlen($body);
}

echo "URL: {$u}\nMETHOD: ".strtoupper($m)."\n";
echo "REF MODE: {$r}".($cr ? " ({$cr})" : "")."\n\n";
echo "HTTP CODE: {$info['http_code']}\n";
echo "CONTENT-TYPE: ".($info['content_type'] ?? 'n/d')."\n";
echo "REDIRECT_URL: ".($info['redirect_url'] ?? 'n/d')."\n";
echo "SIZE (body): ".$bodyLen."\n";
echo "==== HEADERS ====\n".$headers."\n";
