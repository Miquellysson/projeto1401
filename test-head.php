<?php
// test-head.php — testa HEAD/GET server-side (cURL) com/sem Referer
ini_set('display_errors', 1); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$u = $_GET['u'] ?? '';
$refOpt = $_GET['ref'] ?? 'default'; // default|none|victor|custom
$method = $_GET['m'] ?? 'HEAD';      // HEAD|GET

if (!$u) { echo "Use: test-head.php?u=URL&ref=(default|none|victor|custom)&m=(HEAD|GET)\n"; exit; }

$ch = curl_init($u);
$hdr = ["User-Agent: VF-Diag/1.0", "Accept: */*"];
if ($refOpt === 'victor') $hdr[] = "Referer: https://victorfarmafacil.com/";
elseif ($refOpt === 'none') $hdr[] = "Referer:";
elseif ($refOpt === 'custom' && !empty($_GET['refval'])) $hdr[] = "Referer: ".$_GET['refval'];

curl_setopt_array($ch, [
  CURLOPT_NOBODY => ($method === 'HEAD'),
  CURLOPT_HEADER => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => false,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_HTTPHEADER => $hdr
]);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
$err  = curl_error($ch);
curl_close($ch);

echo "URL: $u\nMETHOD: $method\nREF: $refOpt\n\n";
if ($resp === false) {
  echo "CURL ERROR: $err\n";
} else {
  echo "HTTP_CODE: ".$info['http_code']."\n";
  echo "CONTENT_TYPE: ".($info['content_type'] ?? '')."\n";
  echo "---- RAW HEADERS ----\n";
  echo $resp;
}
