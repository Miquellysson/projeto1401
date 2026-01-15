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

if ($action==='view') {
  $id=(int)($_GET['id'] ?? 0);
  $st=$pdo->prepare("SELECT * FROM customers WHERE id=?");
  $st->execute([$id]);
  $c=$st->fetch();
  if (!$c){ header('Location: customers.php'); exit; }
  admin_header('Cliente #'.$id);
  echo '<div class="card"><div class="card-title">Dados do cliente</div><div class="p-4">';
  echo '<div><strong>'.sanitize_html($c['name']).'</strong></div>';
  echo '<div>'.sanitize_html($c['email']).' • '.sanitize_html($c['phone']).'</div>';
  echo '<div>'.sanitize_html($c['address']).' — '.sanitize_html($c['city']).' / '.sanitize_html($c['state']).' — '.sanitize_html($c['zipcode']).'</div>';
  echo '</div></div>';

  echo '<div class="card"><div class="card-title">Pedidos do cliente</div><div class="p-3 overflow-x-auto">';
  $st=$pdo->prepare("SELECT id,total,currency,status,created_at FROM orders WHERE customer_id=? ORDER BY id DESC");
  $st->execute([$id]);
  echo '<table class="table"><thead><tr><th>#</th><th>Total</th><th>Status</th><th>Quando</th><th></th></tr></thead><tbody>';
  foreach($st as $o){
    echo '<tr>';
    echo '<td>#'.(int)$o['id'].'</td>';
    $orderCurrency = strtoupper($o['currency'] ?? (cfg()['store']['currency'] ?? 'USD'));
    echo '<td>'.format_currency((float)$o['total'], $orderCurrency).'</td>';
    echo '<td>'.sanitize_html($o['status']).'</td>';
    echo '<td>'.sanitize_html($o['created_at']).'</td>';
    echo '<td><a class="btn" href="orders.php?action=view&id='.(int)$o['id'].'">Abrir</a></td>';
    echo '</tr>';
  }
  echo '</tbody></table></div></div>';
  admin_footer(); exit;
}

// list
admin_header('Clientes');
$q = trim((string)($_GET['q'] ?? ''));
$w=' WHERE 1=1 '; $p=[];
if ($q!==''){ $w.=" AND (name LIKE ? OR email LIKE ? OR phone LIKE ?) "; $p=["%$q%","%$q%","%$q%"]; }
$st=$pdo->prepare("SELECT id,name,email,phone,created_at FROM customers $w ORDER BY id DESC LIMIT 200");
$st->execute($p);
echo '<div class="card"><div class="card-title">Clientes</div>';
echo '<div class="card-toolbar">';
echo '  <form class="search-form" method="get">';
echo '    <input class="input" name="q" value="'.sanitize_html($q).'" placeholder="Buscar por nome/e-mail/telefone">';
echo '    <button class="btn btn-primary btn-sm" type="submit"><i class="fa-solid fa-magnifying-glass mr-2"></i>Buscar</button>';
echo '  </form>';
echo '  <div class="toolbar-actions"></div>';
echo '</div>';
echo '<div class="p-3 overflow-x-auto"><table class="table"><thead><tr><th>#</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Cadastro</th><th></th></tr></thead><tbody>';
foreach($st as $c){
  echo '<tr>';
  echo '<td>'.(int)$c['id'].'</td>';
  echo '<td>'.sanitize_html($c['name']).'</td>';
  echo '<td>'.sanitize_html($c['email']).'</td>';
  echo '<td>'.sanitize_html($c['phone']).'</td>';
  echo '<td>'.sanitize_html($c['created_at'] ?? '').'</td>';
  echo '<td><div class="action-buttons"><a class="btn btn-alt btn-sm" href="customers.php?action=view&id='.(int)$c['id'].'"><i class="fa-solid fa-eye"></i> Ver</a></div></td>';
  echo '</tr>';
}
echo '</tbody></table></div></div>';
admin_footer();
