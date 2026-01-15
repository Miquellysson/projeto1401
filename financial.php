<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';

if (!function_exists('require_admin')) {
    function require_admin(){
        if (empty($_SESSION['admin_id'])) {
            header('Location: admin.php?route=login');
            exit;
        }
    }
}

require_admin();
require_admin_capability('manage_orders');

$pdo = db();

/**
 * Normaliza o status bruto do pedido em buckets pagos/pendentes/cancelados.
 */
function finance_status_case(): string
{
    return "CASE
        WHEN payment_status = 'paid' OR status = 'paid' THEN 'paid'
        WHEN status = 'canceled' OR payment_status IN ('canceled','failed','refunded','chargeback') THEN 'canceled'
        ELSE 'pending'
    END";
}

function finance_empty_breakdown(): array
{
    return [
        'paid' => ['quantity' => 0, 'total' => 0.0],
        'pending' => ['quantity' => 0, 'total' => 0.0],
        'canceled' => ['quantity' => 0, 'total' => 0.0],
    ];
}

function finance_enrich_totals(array $breakdown): array
{
    $totalGeral = 0.0;
    foreach ($breakdown as $bucket) {
        $totalGeral += (float)($bucket['total'] ?? 0);
    }
    $breakdown['total_geral'] = $totalGeral;
    return $breakdown;
}

function finance_fetch_breakdown(PDO $pdo, ?string $start, ?string $end): array
{
    $sql = "SELECT ".finance_status_case()." AS bucket, COUNT(*) AS qty, COALESCE(SUM(total),0) AS total
            FROM orders WHERE 1=1";
    $params = [];
    if ($start !== null) {
        $sql .= " AND created_at >= ?";
        $params[] = $start;
    }
    if ($end !== null) {
        $sql .= " AND created_at <= ?";
        $params[] = $end;
    }
    $sql .= " GROUP BY bucket";

    $rows = finance_empty_breakdown();
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
    return finance_enrich_totals($rows);
}

function finance_fetch_summary(PDO $pdo, int $days): array {
    $start = (new DateTimeImmutable('today'))->sub(new DateInterval('P'.($days-1).'D'))->format('Y-m-d 00:00:00');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS orders_count, SUM(total) AS gross_total, SUM(subtotal) AS subtotal_total, SUM(shipping_cost) AS shipping_total FROM orders WHERE created_at >= ?");
    $stmt->execute([$start]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $gross = (float)($row['gross_total'] ?? 0);
    $count = (int)($row['orders_count'] ?? 0);
    return [
        'orders' => $count,
        'gross' => $gross,
        'average' => $count ? $gross / $count : 0,
        'shipping' => (float)($row['shipping_total'] ?? 0),
        'subtotal' => (float)($row['subtotal_total'] ?? 0),
        'breakdown' => finance_fetch_breakdown($pdo, $start, null),
    ];
}

$periods = [
    ['label' => 'Últimos 7 dias', 'days' => 7],
    ['label' => 'Últimos 15 dias', 'days' => 15],
    ['label' => 'Últimos 30 dias', 'days' => 30],
];
$summaries = [];
foreach ($periods as $period) {
    $summaries[] = array_merge($period, finance_fetch_summary($pdo, $period['days']));
}

// Dados diários dos últimos 30 dias
$statusesMeta = [
    'paid' => ['label' => 'Pagos', 'color' => '#16a34a', 'bg' => 'rgba(22,163,74,0.15)', 'icon' => 'fa-circle-check'],
    'pending' => ['label' => 'Pendentes', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.15)', 'icon' => 'fa-clock'],
    'canceled' => ['label' => 'Cancelados', 'color' => '#dc2626', 'bg' => 'rgba(220,38,38,0.15)', 'icon' => 'fa-circle-xmark'],
];
$overallBreakdown = finance_fetch_breakdown($pdo, null, null);

// Séries diárias com buckets de status
$dailyStmt = $pdo->prepare("SELECT DATE(created_at) AS day, ".finance_status_case()." AS bucket, SUM(total) AS total, COUNT(*) AS qty FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY day, bucket ORDER BY day");
$dailyStmt->execute();
$dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
$chartLabels = [];
$chartSeries = [
    'paid' => [],
    'pending' => [],
    'canceled' => [],
];
$dailyTable = [];
$startChart = (new DateTimeImmutable('today'))->sub(new DateInterval('P29D'));
for ($i = 0; $i < 30; $i++) {
    $day = $startChart->add(new DateInterval('P'.$i.'D'))->format('Y-m-d');
    $chartLabels[] = $day;
    foreach ($chartSeries as $key => $series) {
        $chartSeries[$key][] = 0;
    }
    $dailyTable[$day] = finance_empty_breakdown();
}
$labelIndex = array_flip($chartLabels);
foreach ($dailyRows as $row) {
    $day = $row['day'];
    $bucket = $row['bucket'] ?? 'pending';
    if (!isset($labelIndex[$day]) || !isset($chartSeries[$bucket])) {
        continue;
    }
    $chartSeries[$bucket][$labelIndex[$day]] = (float)($row['total'] ?? 0);
    $dailyTable[$day][$bucket]['quantity'] = (int)($row['qty'] ?? 0);
    $dailyTable[$day][$bucket]['total'] = (float)($row['total'] ?? 0);
}
$dailyTable = array_reverse($dailyTable, true);

// Resumos mensais (últimos 12 meses)
$monthlyBreakdown = [];
try {
    $monthlyStmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m-01') AS month, ".finance_status_case()." AS bucket, SUM(total) AS total, COUNT(*) AS qty FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH) GROUP BY month, bucket ORDER BY month DESC");
    $monthlyStmt->execute();
    foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $month = $row['month'];
        $bucket = $row['bucket'] ?? 'pending';
        if (!isset($monthlyBreakdown[$month])) {
            $monthlyBreakdown[$month] = finance_empty_breakdown();
        }
        $monthlyBreakdown[$month][$bucket]['quantity'] = (int)($row['qty'] ?? 0);
        $monthlyBreakdown[$month][$bucket]['total'] = (float)($row['total'] ?? 0);
        $monthlyBreakdown[$month] = finance_enrich_totals($monthlyBreakdown[$month]);
    }
} catch (Throwable $e) {
    $monthlyBreakdown = [];
}

// Resumos anuais (últimos 4 anos)
$yearlyBreakdown = [];
try {
    $yearStmt = $pdo->prepare("SELECT YEAR(created_at) AS year_label, ".finance_status_case()." AS bucket, SUM(total) AS total, COUNT(*) AS qty FROM orders GROUP BY year_label, bucket ORDER BY year_label DESC LIMIT 4");
    $yearStmt->execute();
    foreach ($yearStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $year = $row['year_label'];
        $bucket = $row['bucket'] ?? 'pending';
        if (!isset($yearlyBreakdown[$year])) {
            $yearlyBreakdown[$year] = finance_empty_breakdown();
        }
        $yearlyBreakdown[$year][$bucket]['quantity'] = (int)($row['qty'] ?? 0);
        $yearlyBreakdown[$year][$bucket]['total'] = (float)($row['total'] ?? 0);
        $yearlyBreakdown[$year] = finance_enrich_totals($yearlyBreakdown[$year]);
    }
} catch (Throwable $e) {
    $yearlyBreakdown = [];
}

// Relatório por produto (últimos 90 dias)
$productsBreakdown = [];
try {
    $productStmt = $pdo->prepare("SELECT oi.product_id, oi.name AS product_name, ".finance_status_case()." AS bucket, SUM(oi.price * oi.quantity) AS total, SUM(oi.quantity) AS qty FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY oi.product_id, oi.name, bucket ORDER BY total DESC");
    $productStmt->execute();
    foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int)($row['product_id'] ?? 0) ?: md5((string)$row['product_name']);
        if (!isset($productsBreakdown[$key])) {
            $productsBreakdown[$key] = [
                'name' => $row['product_name'] ?: 'Sem nome',
                'breakdown' => finance_empty_breakdown(),
            ];
        }
        $bucket = $row['bucket'] ?? 'pending';
        $productsBreakdown[$key]['breakdown'][$bucket]['quantity'] = (int)($row['qty'] ?? 0);
        $productsBreakdown[$key]['breakdown'][$bucket]['total'] = (float)($row['total'] ?? 0);
        $productsBreakdown[$key]['breakdown'] = finance_enrich_totals($productsBreakdown[$key]['breakdown']);
    }
} catch (Throwable $e) {
    $productsBreakdown = [];
}

$paymentStmt = $pdo->prepare("SELECT payment_method, COUNT(*) AS qty, SUM(total) AS total FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY payment_method ORDER BY total DESC");
$paymentStmt->execute();
$paymentRows = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

admin_header('Financeiro');
?>
<section class="space-y-6">
  <div class="dashboard-hero">
    <div>
      <h1 class="text-3xl font-bold">Painel Financeiro</h1>
      <p class="text-white/80">Resumo de vendas com separação por status de pagamento, ticket médio e desempenho dos últimos períodos.</p>
    </div>
  </div>

  <div class="grid md:grid-cols-4 gap-4">
    <?php foreach ($statusesMeta as $key => $meta): $row = $overallBreakdown[$key] ?? ['quantity' => 0, 'total' => 0]; ?>
      <div class="card p-4" style="border-top: 4px solid <?= $meta['color']; ?>">
        <div class="flex items-center justify-between">
          <div class="text-xs uppercase tracking-wide text-gray-500"><?= $meta['label']; ?></div>
          <span class="text-sm" style="color: <?= $meta['color']; ?>"><i class="fa-solid <?= $meta['icon']; ?>"></i></span>
        </div>
        <div class="text-2xl font-bold mt-2"><?= format_currency((float)$row['total']); ?></div>
        <div class="text-sm text-gray-500">Valor acumulado</div>
        <div class="mt-3 flex items-center justify-between text-sm">
          <span class="text-gray-600">Pedidos</span>
          <strong><?= (int)($row['quantity'] ?? 0); ?></strong>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="card p-4 bg-slate-900 text-white">
      <div class="flex items-center justify-between">
        <div class="text-xs uppercase tracking-wide text-white/80">Total geral</div>
        <i class="fa-solid fa-coins"></i>
      </div>
      <div class="text-2xl font-bold mt-2"><?= format_currency((float)($overallBreakdown['total_geral'] ?? 0)); ?></div>
      <div class="text-sm text-white/70">Soma de pagos, pendentes e cancelados</div>
    </div>
  </div>

  <div class="grid md:grid-cols-3 gap-4">
    <?php foreach ($summaries as $summary): ?>
      <div class="card p-4 space-y-2">
        <div class="flex items-center justify-between">
          <div>
            <div class="text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars($summary['label']); ?></div>
            <div class="text-2xl font-bold mt-1"><?= format_currency($summary['gross']); ?></div>
          </div>
          <div class="text-sm text-gray-500 text-right">
            <div>Pedidos: <strong><?= (int)$summary['orders']; ?></strong></div>
            <div>Ticket: <strong><?= format_currency($summary['average']); ?></strong></div>
          </div>
        </div>
        <div class="text-xs text-gray-500">Frete: <?= format_currency($summary['shipping']); ?> | Subtotal: <?= format_currency($summary['subtotal']); ?></div>
        <div class="grid grid-cols-3 gap-2 text-xs">
          <?php foreach ($statusesMeta as $key => $meta): $row = $summary['breakdown'][$key] ?? ['quantity'=>0,'total'=>0]; ?>
            <div class="p-2 rounded" style="background: <?= $meta['bg']; ?>; color: <?= $meta['color']; ?>">
              <div class="font-semibold"><?= $meta['label']; ?></div>
              <div class="text-gray-700 text-[11px]">Pedidos: <?= (int)$row['quantity']; ?></div>
              <div class="text-gray-900 text-[12px] font-semibold"><?= format_currency((float)$row['total']); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="text-xs text-gray-500">Total do período: <strong><?= format_currency((float)($summary['breakdown']['total_geral'] ?? 0)); ?></strong></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="grid lg:grid-cols-3 gap-4">
    <div class="card p-4 lg:col-span-2">
      <div class="flex items-center justify-between">
        <div class="card-title">Faturamento diário (últimos 30 dias)</div>
        <div class="flex items-center gap-3 text-xs text-gray-600">
          <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background:#16a34a;"></span>Pagos</span>
          <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background:#f59e0b;"></span>Pendentes</span>
          <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background:#dc2626;"></span>Cancelados</span>
        </div>
      </div>
      <canvas id="revenueChart" height="120"></canvas>
    </div>
    <div class="card p-4 space-y-3">
      <div class="card-title">Pagamentos (30 dias)</div>
      <?php if (!$paymentRows): ?>
        <p class="text-sm text-gray-500">Sem registros recentes.</p>
      <?php else: ?>
        <ul class="space-y-2">
          <?php foreach ($paymentRows as $row): ?>
            <li class="flex items-center justify-between text-sm">
              <span><?= sanitize_html($row['payment_method'] ?: '—'); ?> (<?= (int)$row['qty']; ?>)</span>
              <strong><?= format_currency((float)$row['total']); ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="border-t pt-3">
        <div class="text-xs text-gray-500 mb-1">Filtrar status</div>
        <div class="flex flex-wrap gap-2 text-xs">
          <?php foreach ($statusesMeta as $key => $meta): ?>
            <label class="inline-flex items-center gap-1 cursor-pointer">
              <input type="checkbox" class="status-filter" value="<?= $key; ?>" checked>
              <span style="color: <?= $meta['color']; ?>"><?= $meta['label']; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="card p-4">
      <div class="card-title">Relatório diário por status</div>
      <div class="overflow-x-auto">
        <table class="table text-sm">
          <thead>
            <tr><th>Dia</th><th>Pagos</th><th>Pendentes</th><th>Cancelados</th><th>Total</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dailyTable as $day => $row): ?>
              <tr>
                <td><?= sanitize_html($day); ?></td>
                <td><?= format_currency((float)$row['paid']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['paid']['quantity']; ?>)</span></td>
                <td><?= format_currency((float)$row['pending']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['pending']['quantity']; ?>)</span></td>
                <td><?= format_currency((float)$row['canceled']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['canceled']['quantity']; ?>)</span></td>
                <td><?= format_currency((float)($row['total_geral'] ?? array_sum(array_column($row, 'total')))); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card p-4">
      <div class="card-title">Relatório mensal (12 meses)</div>
      <div class="overflow-x-auto">
        <table class="table text-sm">
          <thead><tr><th>Mês</th><th>Pagos</th><th>Pendentes</th><th>Cancelados</th><th>Total</th></tr></thead>
          <tbody>
            <?php if (!$monthlyBreakdown): ?>
              <tr><td colspan="5" class="text-center text-gray-500">Sem dados mensais.</td></tr>
            <?php else: ?>
              <?php foreach ($monthlyBreakdown as $month => $row): ?>
                <tr>
                  <td><?= sanitize_html(date('M/Y', strtotime($month))); ?></td>
                  <td><?= format_currency((float)$row['paid']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['paid']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)$row['pending']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['pending']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)$row['canceled']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['canceled']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)($row['total_geral'] ?? 0)); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card p-4">
      <div class="card-title">Relatório anual</div>
      <div class="overflow-x-auto">
        <table class="table text-sm">
          <thead><tr><th>Ano</th><th>Pagos</th><th>Pendentes</th><th>Cancelados</th><th>Total</th></tr></thead>
          <tbody>
            <?php if (!$yearlyBreakdown): ?>
              <tr><td colspan="5" class="text-center text-gray-500">Sem dados anuais.</td></tr>
            <?php else: ?>
              <?php foreach ($yearlyBreakdown as $year => $row): ?>
                <tr>
                  <td><?= (int)$year; ?></td>
                  <td><?= format_currency((float)$row['paid']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['paid']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)$row['pending']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['pending']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)$row['canceled']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['canceled']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)($row['total_geral'] ?? 0)); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card p-4">
      <div class="card-title">Por produto (últimos 90 dias)</div>
      <div class="overflow-x-auto">
        <table class="table text-sm">
          <thead><tr><th>Produto</th><th>Pagos</th><th>Pendentes</th><th>Cancelados</th><th>Total</th></tr></thead>
          <tbody>
            <?php if (!$productsBreakdown): ?>
              <tr><td colspan="5" class="text-center text-gray-500">Sem dados recentes.</td></tr>
            <?php else: ?>
              <?php foreach ($productsBreakdown as $row): ?>
                <tr>
                  <td><?= sanitize_html($row['name']); ?></td>
                  <td><?= format_currency((float)$row['breakdown']['paid']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['breakdown']['paid']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)$row['breakdown']['pending']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['breakdown']['pending']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)$row['breakdown']['canceled']['total']); ?> <span class="text-xs text-gray-500">(<?= (int)$row['breakdown']['canceled']['quantity']; ?>)</span></td>
                  <td><?= format_currency((float)($row['breakdown']['total_geral'] ?? 0)); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
  (function(){
    const ctx = document.getElementById('revenueChart');
    if (!ctx) return;
    const dataLabels = <?= json_encode($chartLabels); ?>;
    const series = <?= json_encode($chartSeries); ?>;
    const chartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels: dataLabels,
        datasets: [
          {
            label: 'Pagos',
            data: series.paid || [],
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22,163,74,0.2)',
            tension: 0.3,
            fill: true,
            pointRadius: 0
          },
          {
            label: 'Pendentes',
            data: series.pending || [],
            borderColor: '#f59e0b',
            backgroundColor: 'rgba(245,158,11,0.2)',
            tension: 0.3,
            fill: true,
            pointRadius: 0
          },
          {
            label: 'Cancelados',
            data: series.canceled || [],
            borderColor: '#dc2626',
            backgroundColor: 'rgba(220,38,38,0.2)',
            tension: 0.3,
            fill: true,
            pointRadius: 0
          }
        ]
      },
      options: {
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { callback: value => '<?= cfg()['store']['currency'] ?? 'USD'; ?> ' + (value.toFixed ? value.toFixed(2) : value) } },
          x: { ticks: { maxTicksLimit: 10 } }
        }
      }
    });

    document.querySelectorAll('.status-filter').forEach(function(cb){
      cb.addEventListener('change', function(){
        const key = this.value;
        const datasetIndex = {paid:0,pending:1,canceled:2}[key];
        if (typeof datasetIndex === 'undefined') return;
        chartInstance.getDatasetMeta(datasetIndex).hidden = !this.checked;
        chartInstance.update();
      });
    });
  })();
</script>
<?php admin_footer();
