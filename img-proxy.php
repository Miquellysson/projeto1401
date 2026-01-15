<?php
/**
 * img-proxy.php — Proxy de imagens com cache em disco e allowlist
 * Uso: /img-proxy.php?u=URL_COMPLETA (urlencoded)
 * Ex.: /img-proxy.php?u=https%3A%2F%2Fbase.rhemacriativa.com%2Fwp-content%2Fuploads%2F2025%2F08%2FAZITROMICINA.png
 */

declare(strict_types=1);
error_reporting(0);

// ---- Config ----
$ALLOW_HOSTS = [
  'base.rhemacriativa.com',   // origem WordPress
];
$CACHE_DIR  = __DIR__ . '/storage/proxy_cache';
$CACHE_TTL  = 3600; // 1h
$MAX_BYTES  = 8 * 1024 * 1024; // 8 MB por imagem
$UA         = 'GetPower Proxy/1.0';
$REFERER    = 'https://victorfarmafacil.com/'; // referer "amigável" para a origem

// Sanitiza e valida URL
$u = isset($_GET['u']) ? trim((string)$_GET['u']) : '';
if ($u === '' || !preg_match('~^https?://~i', $u)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "bad url";
  exit;
}
$parts = parse_url($u);
$host  = strtolower($parts['host'] ?? '');
if ($host === '' || !in_array($host, $ALLOW_HOSTS, true)) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "host not allowed";
  exit;
}

// Evita SSRF a endereços internos
$ip = gethostbyname($host);
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "blocked ip";
  exit;
}

// Prepara cache
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0775, true);
$key       = sha1($u) . '.bin';
$metaKey   = sha1($u) . '.json';
$cacheFile = $CACHE_DIR . '/' . $key;
$metaFile  = $CACHE_DIR . '/' . $metaKey;

// Se existir cache válido, retorna
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $CACHE_TTL)) {
  $ctype = 'image/*';
  if (is_file($metaFile)) {
    $meta = @json_decode(@file_get_contents($metaFile), true);
    if (isset($meta['content_type'])) $ctype = $meta['content_type'];
    if (isset($meta['etag'])) header('ETag: "'.$meta['etag'].'"');
    if (isset($meta['last_modified'])) header('Last-Modified: '.$meta['last_modified']);
  }
  header('Content-Type: '.$ctype);
  header('Cache-Control: public, max-age=300, s-maxage=300');
  readfile($cacheFile);
  exit;
}

// Baixa da origem
$ch = curl_init($u);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_USERAGENT => $UA,
  CURLOPT_REFERER => $REFERER,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSL_VERIFYHOST => false,
  CURLOPT_HEADER => true,
]);

$resp = curl_exec($ch);
if ($resp === false) {
  http_response_code(502);
  header('Content-Type: text/plain; charset=utf-8');
  echo "fetch error: ".curl_error($ch);
  curl_close($ch);
  exit;
}

$info       = curl_getinfo($ch);
$httpCode   = (int)($info['http_code'] ?? 0);
$headerSize = (int)($info['header_size'] ?? 0);
$headersRaw = substr($resp, 0, $headerSize);
$body       = substr($resp, $headerSize);
curl_close($ch);

if ($httpCode !== 200 || $body === '') {
  http_response_code($httpCode ?: 502);
  header('Content-Type: text/plain; charset=utf-8');
  echo "upstream status: ".$httpCode;
  exit;
}

// Respeita limite de tamanho
if (strlen($body) > $MAX_BYTES) {
  http_response_code(413); // Payload Too Large
  header('Content-Type: text/plain; charset=utf-8');
  echo "too large";
  exit;
}

// Descobre Content-Type do upstream
$ctype = 'image/*';
if (!empty($info['content_type'])) {
  $ctype = $info['content_type'];
} else {
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $ctype = $finfo->buffer($body) ?: 'image/*';
}

// Salva no cache (+ meta)
@file_put_contents($cacheFile, $body);
$etag = sha1($body);
$lastModified = gmdate('D, d M Y H:i:s').' GMT';
@file_put_contents($metaFile, json_encode([
  'content_type' => $ctype,
  'etag' => $etag,
  'last_modified' => $lastModified,
], JSON_UNESCAPED_SLASHES));

// Responde
header('Content-Type: '.$ctype);
header('Cache-Control: public, max-age=300, s-maxage=300');
header('ETag: "'.$etag.'"');
header('Last-Modified: '.$lastModified);
echo $body;
