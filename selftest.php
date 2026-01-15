<?php
// selftest.php — smoke tests sem depender de painel
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function line($ok, $msg) { echo ($ok ? "[OK] " : "[FAIL] ").$msg."\n"; }

// 1) Lint de arquivos principais via opcache_compile_file
$must = ['config.php','lib/db.php','lib/utils.php','index.php','install.php'];
$all_ok = true;
foreach ($must as $f) {
  $ok = function_exists('opcache_compile_file') ? @opcache_compile_file(__DIR__.'/'.$f) : is_readable(__DIR__.'/'.$f);
  $all_ok = $all_ok && $ok;
  line($ok, "Sintaxe: ".$f);
}

// 2) Verifica se existe a coluna track_token no SQL (apenas string no install.php)
$install = @file_get_contents(__DIR__.'/install.php');
$hasTrackInCreate = (strpos($install, 'track_token') !== false);
line($hasTrackInCreate, "install.php contém 'track_token' no CREATE/ALTER");

// 3) Procura concatenação inválida com '+' em index.php
$idx = @file_get_contents(__DIR__.'/index.php');
$hasPlusConcat = preg_match('/\+\s*strval\(|\+\s*\$status|\+\s*\$total|\+\s*htmlspecialchars\(/', $idx) === 1;
line(!$hasPlusConcat, "index.php sem concatenação com '+'");

// 4) Checa frete = 7.00
$hasShip7 = preg_match('/\$shipping\s*=\s*7\.00\b/', $idx) === 1;
line($hasShip7, "Frete fixo de $7.00 configurado");

// 5) Moeda USD no config
$cfg = @file_get_contents(__DIR__.'/config.php');
$hasUSD = strpos($cfg, "'currency'      => 'USD'") !== false || strpos($cfg, "'currency'   => 'USD'") !== false;
line($hasUSD, "Moeda configurada para USD ($)");

// 6) Pagamentos atualizados (checks básicos)
$checks = [
  ["Zelle recipient_name", "MHBS MULTISERVICES"],
  ["Zelle recipient_email", "8568794719"],
  ["Pix pix_key", "35.816.920/0001-67"],
  ["Pix merchant_name", "MH Baltazar de Souza"],
  ["Venmo handle", "https://venmo.com/code?user_id=4077225473213622325"],
  ["PayPal business", "@MarceloSouza972"],
];
foreach ($checks as $c) {
  $ok = strpos($cfg, $c[1]) !== false;
  line($ok, "Config: ".$c[0]." = ".$c[1]);
}

// 7) Relatório final
echo "\nSe alguma linha veio [FAIL], revise o arquivo indicado. Caso tudo [OK], publique e rode /install.php.\n";
