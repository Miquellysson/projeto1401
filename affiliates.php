<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('require_admin')){
  function require_admin(){
    if (empty($_SESSION['admin_id'])) {
      header('Location: admin.php?route=login'); exit;
    }
  }
}
if (!function_exists('csrf_token')){
  function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_check')){
  function csrf_check($t){ $t=(string)$t; return !empty($t) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
}
if (!function_exists('sanitize_string')){
  function sanitize_string($s,$max=255){ $s=trim((string)$s); if (strlen($s)>$max) $s=substr($s,0,$max); return $s; }
}

$pdo = db();
require_admin();

if (function_exists('affiliate_maybe_upgrade_schema')) {
  affiliate_maybe_upgrade_schema();
}

$action = $_GET['action'] ?? 'list';
$canManageAffiliates = admin_can('manage_affiliates');
$writeActions = ['new','create','edit','update','delete'];
if (!$canManageAffiliates && in_array($action, $writeActions, true)) {
  require_admin_capability('manage_affiliates');
}

function affiliate_unique_code(PDO $pdo, string $code, ?int $ignoreId = null): string {
  $base = function_exists('affiliate_normalize_code') ? affiliate_normalize_code($code) : slugify($code);
  if ($base === '') {
    $base = 'afiliado';
  }
  $candidate = $base;
  $suffix = 2;
  while (true) {
    if ($ignoreId) {
      $st = $pdo->prepare("SELECT id FROM affiliates WHERE code = ? AND id <> ? LIMIT 1");
      $st->execute([$candidate, $ignoreId]);
    } else {
      $st = $pdo->prepare("SELECT id FROM affiliates WHERE code = ? LIMIT 1");
      $st->execute([$candidate]);
    }
    if (!$st->fetchColumn()) {
      break;
    }
    $candidate = $base.'-'.$suffix;
    $suffix++;
  }
  return $candidate;
}

function affiliate_base_url(): string {
  $cfg = cfg();
  $settingBase = setting_get('store_base_url', '');
  $base = trim((string)($settingBase ?: ($cfg['store']['base_url'] ?? '')));
  if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme.'://'.$_SERVER['HTTP_HOST'];
  }
  return rtrim($base, '/');
}

function affiliate_build_link(string $baseUrl, string $code, string $landingUrl = ''): string {
  $target = $baseUrl !== '' ? $baseUrl : 'index.php';
  $landingUrl = trim($landingUrl);
  if ($landingUrl !== '') {
    if (preg_match('~^https?://~i', $landingUrl)) {
      $target = $landingUrl;
    } else {
      $target = rtrim($baseUrl !== '' ? $baseUrl : '', '/');
      $target .= '/'.ltrim($landingUrl, '/');
      $target = $target !== '' ? $target : 'index.php';
    }
  }
  $sep = strpos($target, '?') !== false ? '&' : '?';
  return $target.$sep.'ref='.rawurlencode($code);
}

if ($action === 'new') {
  admin_header('Novo afiliado');
  echo '<div class="card"><div class="card-title">Criar afiliado</div><div class="p-4">';
  echo '<form method="post" action="affiliates.php?action=create"><input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '<div class="grid md:grid-cols-2 gap-3">';
  echo '<div class="field"><span>Nome</span><input class="input" name="name" required></div>';
  echo '<div class="field"><span>Código do link</span><input class="input" name="code" placeholder="ex: joao-silva"></div>';
  echo '<div class="field md:col-span-2"><span>URL de destino (opcional)</span><input class="input" name="landing_url" placeholder="/?route=produtos"></div>';
  echo '<div class="field md:col-span-2"><span>Notas internas</span><textarea class="textarea" name="notes" rows="3" placeholder="Observações"></textarea></div>';
  echo '<div class="field"><span>Status</span><select class="select" name="active"><option value="1">Ativo</option><option value="0">Inativo</option></select></div>';
  echo '</div><div class="pt-2"><button class="btn alt">Salvar</button> <a class="btn" href="affiliates.php">Voltar</a></div></form>';
  echo '</div></div>';
  admin_footer(); exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $name = sanitize_string($_POST['name'] ?? '', 140);
  $rawCode = sanitize_string($_POST['code'] ?? '', 80);
  $landingUrl = sanitize_string($_POST['landing_url'] ?? '', 255);
  $notes = trim((string)($_POST['notes'] ?? ''));
  if (strlen($notes) > 2000) {
    $notes = substr($notes, 0, 2000);
  }
  $active = (int)($_POST['active'] ?? 1);
  if ($name === '') {
    die('Nome obrigatório');
  }
  $codeBase = $rawCode !== '' ? $rawCode : $name;
  $code = affiliate_unique_code($pdo, $codeBase);
  $st=$pdo->prepare("INSERT INTO affiliates(name, code, landing_url, notes, is_active, created_at, updated_at) VALUES(?,?,?,?,?,NOW(),NOW())");
  $st->execute([$name,$code,$landingUrl,$notes,$active]);
  header('Location: affiliates.php'); exit;
}

if ($action === 'edit') {
  $id=(int)($_GET['id'] ?? 0);
  $st=$pdo->prepare("SELECT * FROM affiliates WHERE id=?");
  $st->execute([$id]);
  $a=$st->fetch(PDO::FETCH_ASSOC);
  if (!$a){ header('Location: affiliates.php'); exit; }
  admin_header('Editar afiliado');
  echo '<div class="card"><div class="card-title">Editar afiliado</div><div class="p-4">';
  echo '<form method="post" action="affiliates.php?action=update&id='.$id.'"><input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '<div class="grid md:grid-cols-2 gap-3">';
  echo '<div class="field"><span>Nome</span><input class="input" name="name" value="'.sanitize_html($a['name'] ?? '').'" required></div>';
  echo '<div class="field"><span>Código do link</span><input class="input" name="code" value="'.sanitize_html($a['code'] ?? '').'"></div>';
  echo '<div class="field md:col-span-2"><span>URL de destino (opcional)</span><input class="input" name="landing_url" value="'.sanitize_html($a['landing_url'] ?? '').'"></div>';
  echo '<div class="field md:col-span-2"><span>Notas internas</span><textarea class="textarea" name="notes" rows="3">'.sanitize_html($a['notes'] ?? '').'</textarea></div>';
  echo '<div class="field"><span>Status</span><select class="select" name="active"><option value="1" '.((int)($a['is_active'] ?? 1)===1?'selected':'').'>Ativo</option><option value="0" '.((int)($a['is_active'] ?? 1)===0?'selected':'').'>Inativo</option></select></div>';
  echo '</div><div class="pt-2"><button class="btn alt">Atualizar</button> <a class="btn" href="affiliates.php">Voltar</a></div></form>';
  echo '</div></div>';
  admin_footer(); exit;
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $id=(int)($_GET['id'] ?? 0);
  $name = sanitize_string($_POST['name'] ?? '', 140);
  $rawCode = sanitize_string($_POST['code'] ?? '', 80);
  $landingUrl = sanitize_string($_POST['landing_url'] ?? '', 255);
  $notes = trim((string)($_POST['notes'] ?? ''));
  if (strlen($notes) > 2000) {
    $notes = substr($notes, 0, 2000);
  }
  $active = (int)($_POST['active'] ?? 1);
  if ($name === '') {
    die('Nome obrigatório');
  }
  $codeBase = $rawCode !== '' ? $rawCode : $name;
  $code = affiliate_unique_code($pdo, $codeBase, $id);
  $st=$pdo->prepare("UPDATE affiliates SET name=?, code=?, landing_url=?, notes=?, is_active=? WHERE id=?");
  $st->execute([$name,$code,$landingUrl,$notes,$active,$id]);
  header('Location: affiliates.php'); exit;
}

if ($action === 'delete') {
  if (!csrf_check($_GET['csrf'] ?? '')) die('CSRF');
  $id=(int)($_GET['id'] ?? 0);
  $st=$pdo->prepare("DELETE FROM affiliates WHERE id=?");
  $st->execute([$id]);
  header('Location: affiliates.php'); exit;
}

admin_header('Afiliados');
if (!$canManageAffiliates) {
  echo '<div class="alert alert-warning mx-auto max-w-3xl mb-4"><i class="fa-solid fa-circle-info mr-2"></i>Você possui acesso somente leitura nesta seção.</div>';
}
$q = trim((string)($_GET['q'] ?? ''));
$w=' WHERE 1=1 '; $p=[];
if ($q!==''){ $w.=" AND (name LIKE ? OR code LIKE ?) "; $p=["%$q%","%$q%"]; }
$st=$pdo->prepare("SELECT * FROM affiliates $w ORDER BY id DESC LIMIT 200");
$st->execute($p);
$baseUrl = affiliate_base_url();

echo '<div class="card"><div class="card-title">Afiliados</div>';
echo '<div class="card-toolbar">';
echo '  <form class="search-form" method="get">';
echo '    <input class="input" name="q" value="'.sanitize_html($q).'" placeholder="Buscar por nome ou código">';
echo '    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass mr-2"></i>Buscar</button>';
echo '  </form>';
echo '  <div class="toolbar-actions">';
if ($canManageAffiliates) {
  echo '    <a class="btn btn-alt btn-sm" href="affiliates.php?action=new"><i class="fa-solid fa-plus mr-2"></i>Novo afiliado</a>';
}
echo '  </div>';
echo '</div>';
echo '<div class="p-3 overflow-x-auto"><table class="table"><thead><tr><th>#</th><th>Nome</th><th>Código</th><th>Link</th><th>Status</th><th></th></tr></thead><tbody>';
foreach($st as $a){
  $code = (string)($a['code'] ?? '');
  $link = $code !== '' ? affiliate_build_link($baseUrl, $code, (string)($a['landing_url'] ?? '')) : '';
  echo '<tr>';
  echo '<td data-label="#">'.(int)$a['id'].'</td>';
  echo '<td data-label="Nome">'.sanitize_html($a['name'] ?? '').'</td>';
  echo '<td data-label="Código"><span class="badge">'.sanitize_html($code).'</span></td>';
  echo '<td data-label="Link">';
  if ($link !== '') {
    echo '<a class="text-blue-600 underline" href="'.sanitize_html($link).'" target="_blank">'.sanitize_html($link).'</a>';
  } else {
    echo '—';
  }
  echo '</td>';
  echo '<td data-label="Status">'.((int)($a['is_active'] ?? 1) ? '<span class="badge ok">Ativo</span>' : '<span class="badge danger">Inativo</span>').'</td>';
  echo '<td data-label="Ações"><div class="action-buttons">';
  if ($canManageAffiliates) {
    echo '<a class="btn btn-alt btn-sm" href="affiliates.php?action=edit&id='.(int)$a['id'].'"><i class="fa-solid fa-pen"></i> Editar</a>';
    $deleteUrl = 'affiliates.php?action=delete&id='.(int)$a['id'].'&csrf='.csrf_token();
    echo '<a class="btn btn-danger btn-sm" href="'.$deleteUrl.'" onclick="return confirm(\'Excluir afiliado?\')"><i class="fa-solid fa-trash"></i> Excluir</a>';
  }
  if (!$canManageAffiliates) {
    echo '<span class="text-xs text-gray-400">Somente leitura</span>';
  }
  echo '</div></td>';
  echo '</tr>';
}
echo '</tbody></table></div>';
if (!$st->rowCount()) {
  echo '<div class="p-4 text-sm text-gray-500">Nenhum afiliado encontrado.</div>';
}
echo '</div>';

admin_footer();
