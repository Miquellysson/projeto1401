<?php
// diagnose_500.php — Diagnóstico seguro para HTTP 500

// Mostra erros nesta página (não altera seu php.ini)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== Get Power Research — Diagnóstico 500 ===\n\n";

// 1) Ambiente
printf("PHP: %s\n", PHP_VERSION);
printf("SAPI: %s\n", PHP_SAPI);
echo "Timezone: ".date_default_timezone_get()."\n\n";

// 2) Extensões
$exts = ['pdo','pdo_mysql','json','mbstring','fileinfo'];
foreach ($exts as $ex) {
  echo sprintf("[%s] %s\n", extension_loaded($ex)?'OK ':'NOK', $ex);
}
echo "\n";

// 3) Função de lint via opcache (não executa o arquivo)
function lint($path) {
  if (!file_exists($path)) return ['ok'=>false,'msg'=>'Arquivo não existe'];
  if (function_exists('opcache_compile_file')) {
    $ok = @opcache_compile_file($path);
    if ($ok) return ['ok'=>true,'msg'=>'Sintaxe OK (opcache_compile_file)'];
    return ['ok'=>false,'msg'=>'Falha de sintaxe (opcache_compile_file false)'];
  } else {
    // Fallback: include em modo sandbox — ainda pode executar; evitar.
    return ['ok'=>true,'msg'=>'opcache_compile_file ausente; não foi possível validar sintaxe com segurança'];
  }
}

// 4) Arquivos para validar
$base = __DIR__;
$files = [
  'config.php',
  'lib/utils.php',
  'lib/db.php',
  'index.php',
  'admin.php',
  'install.php',
];

echo "== Lint de arquivos ==\n";
foreach ($files as $f) {
  $p = $base . '/' . $f;
  $r = lint($p);
  echo str_pad($f, 20) . ' : ' . ($r['ok'] ? 'OK' : 'ERRO') . ' — ' . $r['msg'] . "\n";
}
echo "\n";

// 5) Teste de require config/DB sem executar index
echo "== Config e conexão MySQL ==\n";
try {
  require_once __DIR__.'/config.php';
  echo "config.php carregado.\n";
} catch (Throwable $e) {
  echo "ERRO ao carregar config.php: ".$e->getMessage()."\n";
}
try {
  require_once __DIR__.'/lib/db.php';
  $pdo = db();
  $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
  echo "Conexão PDO OK. Versão MySQL/MariaDB: $ver\n";
} catch (Throwable $e) {
  echo "ERRO PDO: ".$e->getMessage()."\n";
}
echo "\n";

// 6) Constantes e dependências de i18n
echo "== Constantes e i18n ==\n";
$consts = ['DB_HOST','DB_NAME','DB_USER','DB_PASS','DB_CHARSET','DEFAULT_LANG','ADMIN_EMAIL','ADMIN_PASS_HASH'];
foreach ($consts as $c) {
  echo (defined($c) ? "OK  " : "NOK ").$c."\n";
}
foreach (['i18n/pt.php','i18n/en.php','i18n/es.php'] as $i18) {
  echo (file_exists(__DIR__.'/'.$i18) ? "OK  " : "NOK ").$i18."\n";
}
echo "\n";

// 7) Permissões de escrita
echo "== Permissões de escrita ==\n";
$dirs = [
  'storage',
  'storage/zelle_receipts',
  'storage/products',
  'storage/logo',
];
foreach ($dirs as $d) {
  $full = __DIR__.'/'.$d;
  $ok = is_dir($full);
  echo ($ok?'OK  ':'NOK ').$d.' (existe? '.($ok?'sim':'não').')';
  if ($ok) {
    $test = $full.'/.__test_'.bin2hex(random_bytes(3)).'.tmp';
    $w = @file_put_contents($test, 'ok');
    if ($w !== false) { @unlink($test); echo " — gravável\n"; }
    else echo " — NÃO gravável\n";
  }
  echo "\n";
}
echo "\n";

// 8) .htaccess (se diretivas proibidas podem causar 500)
echo "== .htaccess em storage ==\n";
$ht = __DIR__.'/storage/.htaccess';
if (file_exists($ht)) {
  $c = file_get_contents($ht);
  echo "storage/.htaccess encontrado. Tamanho: ".strlen($c)." bytes\n";
} else {
  echo "storage/.htaccess não encontrado (ok, não é obrigatório)\n";
}

echo "\nFIM DO DIAGNÓSTICO\n";
