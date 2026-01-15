<?php
// cache.php — utilitário simples e válido em PHP (corrige parse error)
// Este arquivo é opcional e pode ser apagado após os testes.

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Força headers de no-cache (LiteSpeed/Cloudflare-friendly)
if (!headers_sent()) {
  header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  header('X-LiteSpeed-Cache-Control: no-cache');
}

// Diagnóstico rápido
echo "=== cache.php (OK) ===\n\n";
echo "PHP: ".PHP_VERSION."\n";
echo "SAPI: ".PHP_SAPI."\n";
echo "Time: ".date('c')."\n\n";

// Verifica se funções essenciais existem
$checks = [
  'session_start' => function_exists('session_start'),
  'ob_start' => function_exists('ob_start'),
  'header' => function_exists('header'),
];
foreach ($checks as $name => $ok) {
  echo sprintf("[%s] %s\n", $ok ? 'OK' : '!!', $name);
}

// Exemplo de helper para cache-busting (se quiser copiar para outro arquivo)
if (!function_exists('asset_url')) {
  function asset_url($path) {
    $v = @filemtime(__DIR__ . '/' . ltrim($path, '/'));
    if (!$v) { $v = time(); }
    return $path . '?v=' . $v;
  }
}
echo "\nExemplo asset_url: ".asset_url('assets/admin.css')."\n";

echo "\nSe você está vendo este texto, o arquivo está sintaticamente correto.\n";
