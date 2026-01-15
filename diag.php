<?php
/**
 * diagnostico.php — Get Power Research
 * Ferramenta de diagnóstico segura (somente leitura por padrão)
 * Parâmetros:
 *   ?format=json     -> exporta relatório em JSON
 *   ?write=1         -> testa escrita em disco (storage/*)
 *   ?dbwrite=1       -> testa escrita no MySQL (cria/dropa tabela temporária)
 */

declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
mb_internal_encoding('UTF-8');

$RESULT = [
  'meta' => [
    'app' => 'Get Power Research',
    'timestamp' => date('c'),
    'php_sapi' => PHP_SAPI,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'base_dir' => __DIR__,
  ],
  'checks' => [],
  'recommendations' => [],
  'errors' => [],
];

$MODE = [
  'format'  => ($_GET['format'] ?? 'html'),
  'writefs' => isset($_GET['write']) && $_GET['write'] == '1',
  'dbwrite' => isset($_GET['dbwrite']) && $_GET['dbwrite'] == '1',
];

function add_check(string $group, string $name, bool $ok, $value = null, $hint = null) {
  global $RESULT;
  $RESULT['checks'][$group][] = [
    'name' => $name,
    'ok'   => $ok,
    'value'=> $value,
    'hint' => $hint,
  ];
  if (!$ok && $hint) {
    $RESULT['recommendations'][] = $hint;
  }
}

function add_error(string $msg) {
  global $RESULT;
  $RESULT['errors'][] = $msg;
}

function mask($s, int $keepStart=2, int $keepEnd=2) {
  if (!$s) return '';
  $len = strlen($s);
  if ($len <= $keepStart+$keepEnd) return str_repeat('*', $len);
  return substr($s,0,$keepStart) . str_repeat('*', $len-$keepStart-$keepEnd) . substr($s,-$keepEnd);
}

function bytes_to_human($v) {
  $v = trim((string)$v);
  $last = strtolower(substr($v, -1));
  $n = (float)$v;
  if ($last === 'g') $n *= 1024*1024*1024;
  elseif ($last === 'm') $n *= 1024*1024;
  elseif ($last === 'k') $n *= 1024;
  return $n;
}

function human($b) {
  $b = (float)$b;
  $u = ['B','KB','MB','GB','TB'];
  $i=0; while ($b>=1024 && $i<count($u)-1) { $b/=1024; $i++; }
  return sprintf('%.1f %s', $b, $u[$i]);
}

/* 1) PHP / Ambiente */
add_check('php', 'PHP Version', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION,
  'Use PHP 8.0+ para melhor compatibilidade e performance.');

$required_ext = ['pdo', 'pdo_mysql', 'openssl', 'mbstring', 'json', 'curl', 'fileinfo'];
foreach ($required_ext as $ext) {
  add_check('php_extensions', "ext:$ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing',
    "Instale a extensão PHP {$ext}.");
}

$optional_ext = ['gd', 'exif', 'intl', 'dom'];
foreach ($optional_ext as $ext) {
  add_check('php_extensions_optional', "ext:$ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing',
    "Opcional: instale {$ext} para recursos adicionais (imagens, datas, HTML).");
}

$ini_keys = ['file_uploads','upload_max_filesize','post_max_size','memory_limit','max_execution_time','max_input_vars','session.save_path'];
$ini = [];
foreach ($ini_keys as $k) { $ini[$k] = ini_get($k); }
add_check('php_ini', 'file_uploads', (bool)ini_get('file_uploads'), $ini['file_uploads'], 'Habilite file_uploads em php.ini.');
add_check('php_ini', 'upload_max_filesize', bytes_to_human($ini['upload_max_filesize']) >= 5*1024*1024, $ini['upload_max_filesize'], 'Aumente upload_max_filesize (ex.: 16M).');
add_check('php_ini', 'post_max_size', bytes_to_human($ini['post_max_size']) >= 10*1024*1024, $ini['post_max_size'], 'Aumente post_max_size (ex.: 32M).');
add_check('php_ini', 'memory_limit', bytes_to_human($ini['memory_limit']) >= 128*1024*1024, $ini['memory_limit'], 'Aumente memory_limit (ex.: 256M).');
add_check('php_ini', 'max_execution_time', (int)$ini['max_execution_time'] >= 60, $ini['max_execution_time'], 'Aumente max_execution_time >= 60.');

$session_path = $ini['session.save_path'] ?: sys_get_temp_dir();
add_check('php_ini', 'session.save_path writable', is_writable($session_path), $session_path, "Permissão de escrita em {$session_path} é necessária para sessões.");

/* 2) Servidor Web / .htaccess */
$is_apache = function_exists('apache_get_modules');
$mods = $is_apache ? @apache_get_modules() : [];
$has_rewrite = $is_apache ? in_array('mod_rewrite', $mods ?? []) : null;
add_check('webserver', 'Servidor Apache', $is_apache, $RESULT['meta']['server_software'], $is_apache ? null : 'Se estiver em Nginx, garanta regras equivalentes ao .htaccess.');
if ($is_apache) {
  add_check('webserver', 'mod_rewrite', (bool)$has_rewrite, $has_rewrite ? 'enabled' : 'disabled', 'Ative mod_rewrite para roteamento amigável.');
}

$root_ht = is_file(__DIR__.'/.htaccess');
$storage_ht = is_file(__DIR__.'/storage/.htaccess');
add_check('filesystem', 'Raiz .htaccess presente', $root_ht, $root_ht ? 'ok' : 'ausente', 'Crie .htaccess na raiz (headers + cache).');
add_check('filesystem', 'storage/.htaccess presente', $storage_ht, $storage_ht ? 'ok' : 'ausente', 'Crie storage/.htaccess (negar listagem, permitir imagens/pdf).');

/* 3) Permissões de diretórios */
$dirs = [
  'storage'                => __DIR__.'/storage',
  'storage/products'       => __DIR__.'/storage/products',
  'storage/categories'     => __DIR__.'/storage/categories',
  'storage/logo'           => __DIR__.'/storage/logo',
  'storage/zelle_receipts' => __DIR__.'/storage/zelle_receipts',
  'storage/cache'          => __DIR__.'/storage/cache',
  'storage/logs'           => __DIR__.'/storage/logs',
  'storage/backups'        => __DIR__.'/storage/backups',
  'assets'                 => __DIR__.'/assets',
];
foreach ($dirs as $label => $path) {
  $exists = is_dir($path);
  add_check('paths', $label.' existe', $exists, $path, "Crie o diretório: {$path}");
  if ($exists) {
    $w = is_writable($path);
    add_check('paths', $label.' gravável', $w, $w ? 'writable' : 'not-writable', "Dê permissão de escrita: chmod -R 775 {$path}");
    if ($w && $MODE['writefs']) {
      $tmp = $path.'/_diag_'.bin2hex(random_bytes(4)).'.tmp';
      $okWrite = @file_put_contents($tmp, 'ok:'.date('c')) !== false;
      $okRead  = $okWrite ? (trim(@file_get_contents($tmp)) !== '') : false;
      $okDel   = $okWrite ? @unlink($tmp) : false;
      add_check('paths_write_test', $label.' write->read->delete', ($okWrite && $okRead && $okDel),
        ['write'=>$okWrite,'read'=>$okRead,'delete'=>$okDel],
        "Verifique owner (www-data) e permissões (775) em {$path}.");
    }
  }
}

/* 4) Config / DB */
$cfg = null;
$configLoaded = false;
try {
  require __DIR__.'/config.php';
  if (function_exists('cfg')) {
    $cfg = cfg();
    $configLoaded = true;
  }
} catch (Throwable $e) {
  add_error('Falha ao carregar config.php: '.$e->getMessage());
}
add_check('config', 'config.php carregado', $configLoaded, $configLoaded ? 'ok' : 'erro', 'Confirme sintaxe e include de config.php.');

$dbInfo = [
  'host' => defined('DB_HOST') ? DB_HOST : null,
  'name' => defined('DB_NAME') ? DB_NAME : null,
  'user' => defined('DB_USER') ? DB_USER : null,
  'pass' => defined('DB_PASS') ? mask(DB_PASS) : null,
];
add_check('config', 'DB definidos', (bool)$dbInfo['host'] && $dbInfo['name'] && $dbInfo['user'] !== null, $dbInfo, 'Preencha DB_HOST, DB_NAME, DB_USER, DB_PASS em config.php.');

$pdo = null;
if ($dbInfo['host'] && $dbInfo['name'] && $dbInfo['user'] !== null) {
  try {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_TIMEOUT => 5,
    ]);
    add_check('database', 'Conexão MySQL', true, DB_HOST.' / '.DB_NAME, null);
    $st = $pdo->query('SELECT 1');
    add_check('database', 'SELECT 1', (bool)$st->fetchColumn(), 'ok', null);
  } catch (Throwable $e) {
    add_check('database', 'Conexão MySQL', false, $e->getMessage(), 'Verifique host, usuário, senha e liberação do IP no provedor.');
  }
}

/* 5) Estrutura de tabelas */
$tables_expected = ['products','categories','orders','order_items','customers'];
if ($pdo) {
  try {
    $tbls = [];
    $q = $pdo->query('SHOW TABLES');
    while ($r = $q->fetch(PDO::FETCH_NUM)) { $tbls[] = $r[0]; }
    foreach ($tables_expected as $t) {
      $ok = in_array($t, $tbls, true);
      add_check('db_tables', "Tabela {$t}", $ok, $ok ? 'existe' : 'ausente', $ok ? null : "Execute install.php ou crie a tabela {$t}.");
    }
  } catch (Throwable $e) {
    add_error('Erro ao listar tabelas: '.$e->getMessage());
  }

  /* Teste de escrita de tabela temporária (opcional) */
  if ($MODE['dbwrite']) {
    try {
      $pdo->exec('CREATE TABLE IF NOT EXISTS _ff_diag_tmp (id INT PRIMARY KEY AUTO_INCREMENT, info VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
      $pdo->exec("INSERT INTO _ff_diag_tmp(info) VALUES('ok')");
      $count = (int)$pdo->query('SELECT COUNT(*) FROM _ff_diag_tmp')->fetchColumn();
      $pdo->exec('DROP TABLE _ff_diag_tmp');
      add_check('db_write', 'Permissão de escrita em DB', $count > 0, "inserts: {$count}", $count>0?null:'Usuário do DB sem CREATE/INSERT. Ajuste privilégios.');
    } catch (Throwable $e) {
      add_check('db_write', 'Permissão de escrita em DB', false, $e->getMessage(), 'Conceda CREATE/INSERT/UPDATE/DELETE para o usuário do DB.');
    }
  }
}

/* 6) Rotas principais acessíveis */
$routes = ['index.php','admin.php','install.php'];
foreach ($routes as $r) {
  $exists = is_file(__DIR__.'/'.$r);
  add_check('routes', $r.' presente', $exists, $exists ? 'ok' : 'ausente', "Envie o arquivo {$r} para o servidor.");
}

/* 7) Pagamentos no config (quando disponível) */
if ($cfg && isset($cfg['payments'])) {
  foreach (['pix','zelle','venmo','paypal'] as $pm) {
    if (isset($cfg['payments'][$pm])) {
      $enabled = !empty($cfg['payments'][$pm]['enabled']);
      add_check('payments', strtoupper($pm).' habilitado', $enabled, $enabled ? 'on' : 'off', $enabled?null:"Ative {$pm} em config.php conforme necessário.");
    }
  }
}

/* 8) Export / Render */
if ($MODE['format'] === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($RESULT, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  exit;
}

/* HTML */
function b($ok){ return $ok ? '#065f46' : '#991b1b'; }
function bg($ok){ return $ok ? '#ecfdf5' : '#fef2f2'; }

?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Diagnóstico — Get Power Research</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Ubuntu,Helvetica,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0}
  header{padding:24px 20px;background:#0ea5e9;color:white}
  main{max-width:1080px;margin:0 auto;padding:20px}
  .card{background:white;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin:14px 0}
  .item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px dashed #e5e7eb}
  .item:last-child{border-bottom:none}
  .status{font-weight:600;padding:4px 10px;border-radius:999px}
  .hint{font-size:12px;color:#475569;margin-top:4px}
  .pill{background:#e2e8f0;border-radius:999px;padding:2px 8px;font-size:12px;margin-left:8px}
  .btn{display:inline-block;background:#0ea5e9;color:white;text-decoration:none;padding:10px 14px;border-radius:10px;margin-right:10px}
  .small{font-size:12px;color:#475569}
  pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:10px;overflow:auto}
</style>
</head>
<body>
<header>
  <h1>🏥 Diagnóstico — Get Power Research</h1>
  <div class="small">Gerado em <?=htmlspecialchars($RESULT['meta']['timestamp'])?></div>
</header>
<main>

  <div class="card">
    <div class="item"><div><strong>Aplicação</strong></div><div><?=htmlspecialchars($RESULT['meta']['app'])?></div></div>
    <div class="item"><div><strong>Servidor</strong></div><div><?=htmlspecialchars($RESULT['meta']['server_software'] ?? 'N/A')?></div></div>
    <div class="item"><div><strong>Base dir</strong></div><div><code><?=htmlspecialchars($RESULT['meta']['base_dir'])?></code></div></div>
    <div class="item"><div><strong>Exportar JSON</strong></div><div><a class="btn" href="?format=json">Baixar JSON</a></div></div>
    <div class="item"><div><strong>Testes ativos</strong></div>
      <div>
        <span class="pill">writefs: <?=$MODE['writefs']?'on':'off'?></span>
        <span class="pill">dbwrite: <?=$MODE['dbwrite']?'on':'off'?></span>
      </div>
    </div>
  </div>

  <?php foreach ($RESULT['checks'] as $group => $items): ?>
    <div class="card">
      <h3 style="margin:4px 0 10px 0;"><?=htmlspecialchars(strtoupper($group))?></h3>
      <?php foreach ($items as $it): ?>
        <div class="item">
          <div>
            <div><?=htmlspecialchars($it['name'])?></div>
            <?php if (!empty($it['hint']) && !$it['ok']): ?>
              <div class="hint">🔧 Sugestão: <?=htmlspecialchars($it['hint'])?></div>
            <?php endif; ?>
            <?php if ($it['value'] !== null && !is_array($it['value'])): ?>
              <div class="small">Valor: <code><?=htmlspecialchars((string)$it['value'])?></code></div>
            <?php elseif (is_array($it['value'])): ?>
              <div class="small">Detalhes:
                <code><?=htmlspecialchars(json_encode($it['value'], JSON_UNESCAPED_UNICODE))?></code>
              </div>
            <?php endif; ?>
          </div>
          <div><span class="status" style="background:<?=bg($it['ok'])?>;color:<?=b($it['ok'])?>;"><?= $it['ok'] ? 'OK' : 'FALHA' ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($RESULT['errors'])): ?>
    <div class="card">
      <h3>Erros</h3>
      <pre><?=htmlspecialchars(implode("\n", $RESULT['errors']))?></pre>
    </div>
  <?php endif; ?>

  <?php if (!empty($RESULT['recommendations'])): ?>
    <div class="card">
      <h3>Recomendações Gerais</h3>
      <ul>
        <?php foreach (array_unique($RESULT['recommendations']) as $rec): ?>
          <li style="margin:6px 0;"><?=htmlspecialchars($rec)?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3>Como usar os testes de escrita</h3>
    <p class="small">
      • <strong>Testar escrita em disco:</strong> <code>?write=1</code> — cria/ler/apaga arquivos temporários nas pastas de <em>storage/</em><br>
      • <strong>Testar escrita no MySQL:</strong> <code>?dbwrite=1</code> — cria/insere/seleciona/drop em tabela temporária <code>_ff_diag_tmp</code><br>
      • <strong>Exportar JSON:</strong> <code>?format=json</code>
    </p>
  </div>

</main>
</body>
</html>
