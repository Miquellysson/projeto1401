<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';

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
if (!function_exists('validate_email')){
  function validate_email($e){ return (bool)filter_var($e,FILTER_VALIDATE_EMAIL); }
}
$pdo = db();
require_admin();

$action = $_GET['action'] ?? 'list';
$canManageCategories = admin_can('manage_categories');
$writeActions = ['new','create','edit','update','delete','bulk_delete'];
if (!$canManageCategories && in_array($action, $writeActions, true)) {
  require_admin_capability('manage_categories');
}

$currentAdminIsSuper = is_super_admin();

function category_unique_slug(PDO $pdo, string $name, ?int $ignoreId = null): string {
  $base = slugify($name);
  if ($base === '') {
    $base = 'categoria';
  }
  $slug = $base;
  $suffix = 2;
  while (true) {
    if ($ignoreId) {
      $st = $pdo->prepare("SELECT id FROM categories WHERE slug = ? AND id <> ? LIMIT 1");
      $st->execute([$slug, $ignoreId]);
    } else {
      $st = $pdo->prepare("SELECT id FROM categories WHERE slug = ? LIMIT 1");
      $st->execute([$slug]);
    }
    if (!$st->fetchColumn()) {
      break;
    }
    $slug = $base . '-' . $suffix;
    $suffix++;
  }
  return $slug;
}

if ($action==='new') {
  admin_header('Nova categoria');
  echo '<div class="card"><div class="card-title">Criar categoria</div><div class="p-4">';
  echo '<form method="post" action="categories.php?action=create"><input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '<div class="grid md:grid-cols-3 gap-3">';
  echo '<div class="field"><span>Nome</span><input class="input" name="name" required></div>';
  echo '<div class="field"><span>Ordem</span><input class="input" type="number" name="sort_order" value="0"></div>';
  echo '<div class="field"><span>Ativa</span><select class="select" name="active"><option value="1">Sim</option><option value="0">Não</option></select></div>';
  echo '</div><div class="pt-2"><button class="btn alt">Salvar</button> <a class="btn" href="categories.php">Voltar</a></div></form>';
  echo '</div></div>';
  admin_footer(); exit;
}

if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $name = sanitize_string($_POST['name'] ?? '');
  $sort = (int)($_POST['sort_order'] ?? 0);
  $active= (int)($_POST['active'] ?? 1);
  if ($name === '') {
    die('Nome obrigatório');
  }
  $slug = category_unique_slug($pdo, $name);
  $st=$pdo->prepare("INSERT INTO categories(name,slug,sort_order,active,created_at) VALUES(?,?,?,?,NOW())");
  $st->execute([$name,$slug,$sort,$active]);
  header('Location: categories.php'); exit;
}

if ($action==='edit') {
  $id=(int)($_GET['id'] ?? 0);
  $st=$pdo->prepare("SELECT * FROM categories WHERE id=?");
  $st->execute([$id]);
  $c=$st->fetch();
  if (!$c){ header('Location: categories.php'); exit; }
  admin_header('Editar categoria');
  echo '<div class="card"><div class="card-title">Editar categoria</div><div class="p-4">';
  echo '<form method="post" action="categories.php?action=update&id='.$id.'"><input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '<div class="grid md:grid-cols-3 gap-3">';
  echo '<div class="field"><span>Nome</span><input class="input" name="name" value="'.sanitize_html($c['name']).'" required></div>';
  echo '<div class="field"><span>Ordem</span><input class="input" type="number" name="sort_order" value="'.(int)$c['sort_order'].'"></div>';
  echo '<div class="field"><span>Ativa</span><select class="select" name="active"><option value="1" '.((int)$c['active']===1?'selected':'').'>Sim</option><option value="0" '.((int)$c['active']===0?'selected':'').'>Não</option></select></div>';
  echo '</div><div class="pt-2"><button class="btn alt">Atualizar</button> <a class="btn" href="categories.php">Voltar</a></div></form>';
  echo '</div></div>';
  admin_footer(); exit;
}

if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $id=(int)($_GET['id'] ?? 0);
  $name = sanitize_string($_POST['name'] ?? '');
  $sort = (int)($_POST['sort_order'] ?? 0);
  $active= (int)($_POST['active'] ?? 1);
  if ($name === '') {
    die('Nome obrigatório');
  }
  $slug = category_unique_slug($pdo, $name, $id);
  $st=$pdo->prepare("UPDATE categories SET name=?,slug=?,sort_order=?,active=? WHERE id=?");
  $st->execute([$name,$slug,$sort,$active,$id]);
  header('Location: categories.php'); exit;
}

if ($action==='delete') {
  $id=(int)($_GET['id'] ?? 0);
  $csrf=$_GET['csrf'] ?? '';
  if (!csrf_check($csrf)) die('CSRF');
  require_super_admin();
  $st=$pdo->prepare("DELETE FROM categories WHERE id=?");
  $st->execute([$id]);
  header('Location: categories.php'); exit;
}

if ($action==='bulk_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  require_super_admin();
  $ids = array_filter(array_map('intval', $_POST['selected'] ?? []));
  if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("DELETE FROM categories WHERE id IN ($placeholders)");
    $st->execute($ids);
  }
  header('Location: categories.php'); exit;
}

admin_header('Categorias');
if (!$canManageCategories) {
  echo '<div class="alert alert-warning mx-auto max-w-3xl mb-4"><i class="fa-solid fa-circle-info mr-2"></i>Você possui acesso somente leitura nesta seção.</div>';
}
$q = trim((string)($_GET['q'] ?? ''));
$w=' WHERE 1=1 '; $p=[];
if ($q!==''){ $w.=" AND (name LIKE ?) "; $p=["%$q%"]; }
$st=$pdo->prepare("SELECT * FROM categories $w ORDER BY sort_order, name LIMIT 200");
$st->execute($p);
echo '<div class="card"><div class="card-title">Categorias</div>';
echo '<div class="card-toolbar">';
echo '  <form class="search-form" method="get">';
echo '    <input class="input" name="q" value="'.sanitize_html($q).'" placeholder="Buscar por nome">';
echo '    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass mr-2"></i>Buscar</button>';
echo '  </form>';
echo '  <div class="toolbar-actions">';
if ($canManageCategories) {
  echo '    <a class="btn btn-alt btn-sm" href="categories.php?action=new"><i class="fa-solid fa-plus mr-2"></i>Nova categoria</a>';
}
echo '  </div>';
echo '</div>';
echo '<form method="post" action="categories.php?action=bulk_delete">';
echo '  <input type="hidden" name="csrf" value="'.csrf_token().'">';
echo '<div class="p-3 overflow-x-auto"><table class="table"><thead><tr>';
if ($currentAdminIsSuper) {
  echo '<th><input type="checkbox" id="checkAllCategories"></th>';
} else {
  echo '<th></th>';
}
echo '<th>#</th><th>Nome</th><th>Ordem</th><th>Ativa</th><th></th></tr></thead><tbody>';
foreach($st as $c){
  echo '<tr>';
  echo '<td>';
  if ($currentAdminIsSuper) {
    echo '<input type="checkbox" name="selected[]" value="'.(int)$c['id'].'">';
  }
  echo '</td>';
  echo '<td>'.(int)$c['id'].'</td>';
  echo '<td>'.sanitize_html($c['name']).'</td>';
  echo '<td>'.(int)$c['sort_order'].'</td>';
  echo '<td>'.((int)$c['active']?'<span class="badge ok">Sim</span>':'<span class="badge danger">Não</span>').'</td>';
  echo '<td><div class="action-buttons">';
  if ($canManageCategories) {
    echo '<a class="btn btn-alt btn-sm" href="categories.php?action=edit&id='.(int)$c['id'].'"><i class="fa-solid fa-pen"></i> Editar</a>';
  }
  if ($currentAdminIsSuper) {
    $deleteUrl = 'categories.php?action=delete&id='.(int)$c['id'].'&csrf='.csrf_token();
    echo '<a class="btn btn-danger btn-sm" href="'.$deleteUrl.'" onclick="return confirm(\'Excluir categoria?\')"><i class="fa-solid fa-trash"></i> Excluir</a>';
  }
  if (!$canManageCategories && !$currentAdminIsSuper) {
    echo '<span class="text-xs text-gray-400">Somente leitura</span>';
  }
  echo '</div></td>';
  echo '</tr>';
}
echo '</tbody></table></div>';
if ($currentAdminIsSuper) {
  echo '<div class="p-3 flex justify-end border-t"><button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'Excluir as categorias selecionadas?\')"><i class="fa-solid fa-trash-can mr-2"></i>Excluir selecionadas</button></div>';
}
echo '</form></div>';
echo '<script>
document.getElementById("checkAllCategories")?.addEventListener("change",function(e){
  const checked=e.target.checked;
  document.querySelectorAll("input[name=\\"selected[]\\"]").forEach(cb=>cb.checked=checked);
});
</script>';
admin_footer();
