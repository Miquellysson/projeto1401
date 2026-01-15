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
require_admin_capability('manage_users');

$rolesMap = [
  'super_admin' => 'Super Admin',
  'admin'       => 'Administrador',
  'manager'     => 'Gestor',
  'viewer'      => 'Leitor'
];

$action = $_GET['action'] ?? 'list';
$currentAdmin = current_admin();
$isSuperAdmin = is_super_admin();
$assignableRoles = $rolesMap;
if (!$isSuperAdmin) {
  unset($assignableRoles['super_admin']);
}

if ($action==='new') {
  render_user_form(['name'=>'','email'=>'','role'=>'admin','active'=>1], $assignableRoles, 'users.php?action=create', 'Novo usuário', 'Salvar');
}

if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $name = sanitize_string($_POST['name'] ?? '');
  $email= sanitize_string($_POST['email'] ?? '');
  $active= (int)($_POST['active'] ?? 1);
  $pass = (string)($_POST['password'] ?? '');
  $role = sanitize_string($_POST['role'] ?? 'admin', 50);
  if (!isset($assignableRoles[$role])) { $role = 'admin'; }
  if (!$isSuperAdmin && $role === 'super_admin') {
    $role = 'admin';
  }
  if (!$name || !validate_email($email) || strlen($pass)<6) {
    $alert = '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700"><i class="fa-solid fa-circle-exclamation mr-1"></i> Informe nome, e-mail válido e senha com pelo menos 6 caracteres.</div>';
    render_user_form(['name'=>$name,'email'=>$email,'role'=>$role,'active'=>$active], $assignableRoles, 'users.php?action=create', 'Novo usuário', 'Salvar', false, $alert);
  }
  $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
  $dup->execute([$email]);
  if ($dup->fetchColumn()) {
    $alert = '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i> E-mail já cadastrado. Escolha outro.</div>';
    render_user_form(['name'=>$name,'email'=>$email,'role'=>$role,'active'=>$active], $assignableRoles, 'users.php?action=create', 'Novo usuário', 'Salvar', false, $alert);
  }
  $hash = password_hash($pass, PASSWORD_BCRYPT);
  $st=$pdo->prepare("INSERT INTO users(name,email,pass,role,active,created_at) VALUES(?,?,?,?,?,NOW())");
  $st->execute([$name,$email,$hash,$role,$active]);
  header('Location: users.php'); exit;
}

if ($action==='edit') {
  $id=(int)($_GET['id'] ?? 0);
  $st=$pdo->prepare("SELECT * FROM users WHERE id=?");
  $st->execute([$id]);
  $u=$st->fetch();
  if (!$u){ header('Location: users.php'); exit; }
  if (($u['role'] ?? '') === 'super_admin' && !$isSuperAdmin) {
    http_response_code(403);
    admin_header('Acesso negado');
    echo '<div class="card"><div class="card-title">Acesso negado</div><div class="p-4 text-red-600">Apenas super administradores podem editar este usuário.</div></div>';
    admin_footer(); exit;
  }
  render_user_form($u, $assignableRoles, 'users.php?action=update&id='.$id, 'Editar usuário', 'Atualizar', true);
}

if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) die('CSRF');
  $id=(int)($_GET['id'] ?? 0);
  $st = $pdo->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $existing = $st->fetch(PDO::FETCH_ASSOC);
  if (!$existing) { header('Location: users.php'); exit; }
  if (($existing['role'] ?? '') === 'super_admin' && !$isSuperAdmin) {
    http_response_code(403);
    admin_header('Acesso negado');
    echo '<div class="card"><div class="card-title">Acesso negado</div><div class="p-4 text-red-600">Apenas super administradores podem alterar este usuário.</div></div>';
    admin_footer(); exit;
  }
  $name = sanitize_string($_POST['name'] ?? '');
  $email= sanitize_string($_POST['email'] ?? '');
  $active= (int)($_POST['active'] ?? 1);
  $pass = (string)($_POST['password'] ?? '');
  $role = sanitize_string($_POST['role'] ?? 'admin', 50);
  if (!isset($assignableRoles[$role])) { $role = 'admin'; }
  if (!$isSuperAdmin && $role === 'super_admin') {
    $role = $existing['role'] ?? 'admin';
  }
  if (!$name || !validate_email($email)) {
    $alert = '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700"><i class="fa-solid fa-circle-exclamation mr-1"></i> Informe nome e e-mail válidos.</div>';
    render_user_form(['id'=>$id,'name'=>$name,'email'=>$email,'role'=>$role,'active'=>$active], $assignableRoles, 'users.php?action=update&id='.$id, 'Editar usuário', 'Atualizar', true, $alert);
  }
  $dup = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
  $dup->execute([$email,$id]);
  if ($dup->fetchColumn()) {
    $alert = '<div class="p-4 mb-2 rounded border border-red-200 bg-red-50 text-red-700"><i class="fa-solid fa-triangle-exclamation mr-1"></i> E-mail já cadastrado em outro usuário.</div>';
    render_user_form(['id'=>$id,'name'=>$name,'email'=>$email,'role'=>$role,'active'=>$active], $assignableRoles, 'users.php?action=update&id='.$id, 'Editar usuário', 'Atualizar', true, $alert);
  }
  if ($pass!==''){
    $hash=password_hash($pass, PASSWORD_BCRYPT);
    $st=$pdo->prepare("UPDATE users SET name=?,email=?,pass=?,role=?,active=? WHERE id=?");
    $st->execute([$name,$email,$hash,$role,$active,$id]);
  } else {
    $st=$pdo->prepare("UPDATE users SET name=?,email=?,role=?,active=? WHERE id=?");
    $st->execute([$name,$email,$role,$active,$id]);
  }
  header('Location: users.php'); exit;
}

if ($action==='delete') {
  $id=(int)($_GET['id'] ?? 0);
  $csrf=$_GET['csrf'] ?? '';
  if (!csrf_check($csrf)) die('CSRF');
  require_super_admin();
  $st=$pdo->prepare("DELETE FROM users WHERE id=?");
  $st->execute([$id]);
  header('Location: users.php'); exit;
}

// list
admin_header('Usuários');
$q = trim((string)($_GET['q'] ?? ''));
$w=' WHERE 1=1 '; $p=[];
if ($q!==''){ $w.=" AND (name LIKE ? OR email LIKE ?) "; $p=["%$q%","%$q%"]; }
$st=$pdo->prepare("SELECT id,name,email,role,active,created_at FROM users $w ORDER BY id DESC LIMIT 200");
$st->execute($p);
echo '<div class="card"><div class="card-title">Usuários</div>';
echo '<div class="card-toolbar">';
echo '  <form class="search-form" method="get">';
echo '    <input class="input" name="q" value="'.sanitize_html($q).'" placeholder="Buscar por nome/e-mail">';
echo '    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass mr-2"></i>Buscar</button>';
echo '  </form>';
echo '  <div class="toolbar-actions">';
echo '    <a class="btn btn-alt btn-sm" href="users.php?action=new"><i class="fa-solid fa-plus mr-2"></i>Novo usuário</a>';
echo '  </div>';
echo '</div>';
echo '<div class="p-3 overflow-x-auto"><table class="table"><thead><tr><th>#</th><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th><th>Criado</th><th></th></tr></thead><tbody>';
foreach($st as $u){
  echo '<tr>';
  echo '<td>'.(int)$u['id'].'</td>';
  echo '<td>'.sanitize_html($u['name']).'</td>';
  echo '<td>'.sanitize_html($u['email']).'</td>';
  $roleLabel = $rolesMap[$u['role']] ?? ucfirst($u['role']);
  echo '<td>'.sanitize_html($roleLabel).'</td>';
  echo '<td>'.((int)$u['active']?'<span class="badge ok">Sim</span>':'<span class="badge danger">Não</span>').'</td>';
  echo '<td>'.sanitize_html($u['created_at'] ?? '').'</td>';
  echo '<td><div class="action-buttons">';
  echo '<a class="btn btn-alt btn-sm" href="users.php?action=edit&id='.(int)$u['id'].'"><i class="fa-solid fa-pen"></i> Editar</a>';
  if ($isSuperAdmin) {
    $deleteUrl = 'users.php?action=delete&id='.(int)$u['id'].'&csrf='.csrf_token();
    echo '<a class="btn btn-danger btn-sm" href="'.$deleteUrl.'" onclick="return confirm(\'Excluir usuário?\')"><i class="fa-solid fa-trash"></i> Excluir</a>';
  }
  echo '</div></td>';
  echo '</tr>';
}
echo '</tbody></table></div></div>';
admin_footer();

function render_user_form(array $user, array $rolesMap, string $actionUrl, string $title, string $buttonLabel, bool $passwordOptional = false, ?string $alertHtml = null) {
  admin_header($title);
  echo '<div class="card"><div class="card-title">'.$title.'</div><div class="p-4">';
  if ($alertHtml) {
    echo $alertHtml;
  }
  echo '<form method="post" action="'.$actionUrl.'"><input type="hidden" name="csrf" value="'.csrf_token().'">';
  echo '<div class="grid md:grid-cols-2 gap-3">';
  echo '<div class="field"><span>Nome</span><input class="input" name="name" value="'.sanitize_html($user['name'] ?? '').'" required></div>';
  echo '<div class="field"><span>E-mail</span><input class="input" type="email" name="email" value="'.sanitize_html($user['email'] ?? '').'" required></div>';
  echo '<div class="field"><span>'.($passwordOptional?'Nova senha (opcional)':'Senha').'</span><input class="input" type="password" name="password" '.($passwordOptional?'':'required').'></div>';
  echo '<div class="field"><span>Perfil</span><select class="select" name="role">';
  $currentRole = $user['role'] ?? 'admin';
  foreach ($rolesMap as $roleKey => $roleLabel) {
    $sel = ($roleKey === $currentRole) ? 'selected' : '';
    echo '<option value="'.$roleKey.'" '.$sel.'>'.$roleLabel.'</option>';
  }
  echo '</select></div>';
  echo '<div class="field"><span>Ativo</span><select class="select" name="active">';
  $activeVal = isset($user['active']) ? (int)$user['active'] : 1;
  echo '<option value="1" '.($activeVal===1?'selected':'').'>Sim</option>';
  echo '<option value="0" '.($activeVal===0?'selected':'').'>Não</option>';
  echo '</select></div>';
  echo '</div><div class="pt-2"><button class="btn alt"><i class="fa-solid fa-floppy-disk"></i> '.$buttonLabel.'</button> <a class="btn" href="users.php">Voltar</a></div></form>';
  echo '</div></div>';
  admin_footer();
  exit;
}
