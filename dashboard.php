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

admin_header('Dashboard');

function dash_status_case(): string {
  return "CASE
    WHEN payment_status = 'paid' OR status = 'paid' THEN 'paid'
    WHEN status = 'canceled' OR payment_status IN ('canceled','failed','refunded','chargeback') THEN 'canceled'
    ELSE 'pending'
  END";
}

function dash_empty_breakdown(): array {
  return [
    'paid' => ['quantity' => 0, 'total' => 0.0],
    'pending' => ['quantity' => 0, 'total' => 0.0],
    'canceled' => ['quantity' => 0, 'total' => 0.0],
  ];
}

function dash_breakdown(PDO $pdo, ?string $startDate = null): array {
  $sql = "SELECT ".dash_status_case()." AS bucket, COUNT(*) AS qty, COALESCE(SUM(total),0) AS total FROM orders WHERE 1=1";
  $params = [];
  if ($startDate !== null) {
    $sql .= " AND created_at >= ?";
    $params[] = $startDate;
  }
  $sql .= " GROUP BY bucket";
  $rows = dash_empty_breakdown();
  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $bucket = $row['bucket'] ?? 'pending';
      if (!isset($rows[$bucket])) {
        $rows[$bucket] = ['quantity' => 0, 'total' => 0.0];
      }
      $rows[$bucket]['quantity'] = (int)($row['qty'] ?? 0);
      $rows[$bucket]['total'] = (float)($row['total'] ?? 0);
    }
  } catch (Throwable $e) {
    return $rows;
  }
  $rows['total_geral'] = ($rows['paid']['total'] ?? 0) + ($rows['pending']['total'] ?? 0) + ($rows['canceled']['total'] ?? 0);
  return $rows;
}

// KPIs
$counts = ['orders'=>0,'customers'=>0,'products'=>0,'categories'=>0];
try{ $counts['orders']     = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(); }catch(Throwable $e){}
try{ $counts['customers']  = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn(); }catch(Throwable $e){}
try{ $counts['products']   = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE active=1")->fetchColumn(); }catch(Throwable $e){}
try{ $counts['categories'] = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE active=1")->fetchColumn(); }catch(Throwable $e){}
$statusMeta = [
  'paid' => ['label'=>'Pagos','color'=>'#16a34a','icon'=>'fa-circle-check'],
  'pending' => ['label'=>'Pendentes','color'=>'#f59e0b','icon'=>'fa-clock'],
  'canceled' => ['label'=>'Cancelados','color'=>'#dc2626','icon'=>'fa-circle-xmark'],
];
$status30d = dash_breakdown($pdo, date('Y-m-d 00:00:00', strtotime('-29 days')));

$quickLinks = [
  ['icon'=>'fa-receipt','label'=>'Pedidos','desc'=>'Acompanhe os pedidos recentes','href'=>'orders.php'],
  ['icon'=>'fa-pills','label'=>'Produtos','desc'=>'Gerencie catálogo e estoque','href'=>'products.php'],
  ['icon'=>'fa-tags','label'=>'Categorias','desc'=>'Organize suas vitrines','href'=>'categories.php'],
  ['icon'=>'fa-users','label'=>'Clientes','desc'=>'Consulte informações dos clientes','href'=>'customers.php'],
  ['icon'=>'fa-user-shield','label'=>'Usuários','desc'=>'Controle acessos do time','href'=>'users.php'],
  ['icon'=>'fa-sliders','label'=>'Configurações','desc'=>'Pagamentos, layout e integrações','href'=>'settings.php?tab=general'],
];

echo '<section class="dashboard-hero">';
echo '  <div class="flex flex-col gap-4">';
echo '    <div>';
echo '      <h1 class="text-2xl md:text-3xl font-bold">Visão geral da loja</h1>';
echo '      <p class="text-white/90 text-sm md:text-base mt-1">Monitore pedidos, catálogo e configurações em um só lugar.</p>';
echo '    </div>';
echo '    <div class="quick-links">';
foreach ($quickLinks as $link) {
  echo '      <a class="quick-link" href="'.$link['href'].'">';
  echo '        <span class="icon"><i class="fa-solid '.$link['icon'].'"></i></span>';
  echo '        <span>';
  echo '          <div class="font-semibold">'.$link['label'].'</div>';
  echo '          <div class="text-xs opacity-80">'.$link['desc'].'</div>';
  echo '        </span>';
  echo '      </a>';
}
echo '    </div>';
echo '  </div>';
echo '</section>';

echo '<section class="kpis">';
echo '  <div class="kpi">';
echo '    <div class="kpi-header"><div class="icon"><i class="fa-solid fa-receipt"></i></div><div>';
echo '      <div class="lbl">Pedidos</div>';
echo '      <div class="val">'.$counts['orders'].'</div>';
echo '    </div></div>';
echo '    <div class="text-xs text-gray-500">Total de pedidos registrados</div>';
echo '  </div>';

echo '  <div class="kpi">';
echo '    <div class="kpi-header"><div class="icon"><i class="fa-solid fa-users"></i></div><div>';
echo '      <div class="lbl">Clientes</div>';
echo '      <div class="val">'.$counts['customers'].'</div>';
echo '    </div></div>';
echo '    <div class="text-xs text-gray-500">Clientes cadastrados no sistema</div>';
echo '  </div>';

echo '  <div class="kpi">';
echo '    <div class="kpi-header"><div class="icon"><i class="fa-solid fa-box-open"></i></div><div>';
echo '      <div class="lbl">Produtos ativos</div>';
echo '      <div class="val">'.$counts['products'].'</div>';
echo '    </div></div>';
echo '    <div class="text-xs text-gray-500">Itens visíveis na loja</div>';
echo '  </div>';

echo '  <div class="kpi">';
echo '    <div class="kpi-header"><div class="icon"><i class="fa-solid fa-layer-group"></i></div><div>';
echo '      <div class="lbl">Categorias</div>';
echo '      <div class="val">'.$counts['categories'].'</div>';
echo '    </div></div>';
echo '    <div class="text-xs text-gray-500">Coleções publicadas</div>';
echo '  </div>';
echo '</section>';

echo '<div class="grid md:grid-cols-4 gap-3 mb-6">';
foreach ($statusMeta as $key => $meta) {
  $row = $status30d[$key] ?? ['quantity'=>0,'total'=>0];
  echo '<div class="card p-3" style="border-top:4px solid '.$meta['color'].'">';
  echo '  <div class="flex items-center justify-between">';
  echo '    <div class="text-xs uppercase tracking-wide text-gray-500">'.$meta['label'].' (30d)</div>';
  echo '    <span style="color:'.$meta['color'].'"><i class="fa-solid '.$meta['icon'].'"></i></span>';
  echo '  </div>';
  echo '  <div class="text-xl font-bold mt-1">'.format_currency((float)$row['total']).'</div>';
  echo '  <div class="text-xs text-gray-500">Pedidos: <strong>'.(int)($row['quantity'] ?? 0).'</strong></div>';
  echo '</div>';
}
echo '<div class="card p-3 bg-slate-900 text-white">';
echo '  <div class="flex items-center justify-between"><div class="text-xs uppercase tracking-wide text-white/80">Total geral (30d)</div><i class="fa-solid fa-coins"></i></div>';
echo '  <div class="text-xl font-bold mt-1">'.format_currency((float)($status30d['total_geral'] ?? 0)).'</div>';
echo '  <div class="text-xs text-white/70">Pagos + Pendentes + Cancelados</div>';
echo '</div>';
echo '</div>';

// Últimos pedidos
echo '<div class="card">';
echo '  <div class="card-title">Últimos pedidos</div>';
echo '  <div class="card-body overflow-x-auto">';
try{
  $st=$pdo->query("SELECT o.id,o.total,o.currency,o.status,o.created_at,c.name AS customer_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC LIMIT 10");
  echo '<table class="table"><thead><tr><th>#</th><th>Cliente</th><th>Total</th><th>Status</th><th>Quando</th><th></th></tr></thead><tbody>';
  foreach($st as $row){
    $badge = '<span class="badge">'.sanitize_html($row['status']).'</span>';
    if ($row['status']==='paid') $badge='<span class="badge ok">Pago</span>';
    elseif ($row['status']==='pending') $badge='<span class="badge warn">Pendente</span>';
    elseif ($row['status']==='canceled') $badge='<span class="badge danger">Cancelado</span>';
    echo '<tr>';
    echo '<td>#'.(int)$row['id'].'</td>';
    echo '<td>'.sanitize_html($row['customer_name'] ?: '-').'</td>';
    $rowCurrency = strtoupper($row['currency'] ?? (cfg()['store']['currency'] ?? 'USD'));
    echo '<td>'.format_currency((float)$row['total'], $rowCurrency).'</td>';
    echo '<td>'.$badge.'</td>';
    echo '<td>'.sanitize_html($row['created_at'] ?? '').'</td>';
    echo '<td><a class="btn btn-ghost" href="orders.php?action=view&id='.(int)$row['id'].'"><i class="fa-solid fa-eye"></i> Ver</a></td>';
    echo '</tr>';
  }
  echo '</tbody></table>';
}catch(Throwable $e){
  echo '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i>Erro ao carregar pedidos recentes.</div>';
}
echo '  </div>';
echo '</div>';

admin_footer();
