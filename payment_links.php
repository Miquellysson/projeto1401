<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';
require __DIR__.'/admin_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('require_admin')) {
    function require_admin()
    {
        if (empty($_SESSION['admin_id'])) {
            header('Location: admin.php?route=login');
            exit;
        }
    }
}
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }
}
if (!function_exists('csrf_check')) {
    function csrf_check($token)
    {
        return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token);
    }
}

require_admin();
require_admin_capability('manage_products');

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS product_payment_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    gateway_code VARCHAR(80) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_prod_gateway (product_id, gateway_code),
    KEY idx_product_id (product_id)
)");

$coreGateways = ['square','stripe','paypal'];
$ignoredGateways = ['pix','zelle','venmo'];
function pl_get_extra_gateways(PDO $pdo, array $core, array $ignored): array
{
    try {
        $skip = array_unique(array_merge($core, $ignored));
        $stmt = $pdo->prepare("SELECT code, name FROM payment_methods WHERE code NOT IN ('".implode("','", array_map('addslashes', $skip))."') ORDER BY sort_order ASC, id ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function pl_flash(string $type, string $message): void
{
    $_SESSION['pl_flash'] = ['type' => $type, 'message' => $message];
}

function pl_take_flash(): ?array
{
    $flash = $_SESSION['pl_flash'] ?? null;
    unset($_SESSION['pl_flash']);
    return $flash;
}

function pl_sanitize_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

$extraGateways = pl_get_extra_gateways($pdo, $coreGateways, $ignoredGateways);
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'export') {
    if (!csrf_check($_GET['csrf'] ?? '')) {
        die('CSRF');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payment-links.csv"');
    $out = fopen('php://output', 'w');
    $headers = ['sku','square_payment_link','stripe_payment_link','paypal_payment_link'];
    foreach ($extraGateways as $g) {
        $headers[] = $g['code'];
    }
    fputcsv($out, $headers);
$rows = $pdo->query("SELECT id, sku, square_payment_link, stripe_payment_link, paypal_payment_link FROM products ORDER BY id ASC");
    $linksStmt = $pdo->query("SELECT product_id, gateway_code, link FROM product_payment_links");
    $linkMap = [];
    foreach ($linksStmt as $lr) {
        $linkMap[(int)$lr['product_id']][$lr['gateway_code']] = $lr['link'] ?? '';
    }
    foreach ($rows as $row) {
        $pid = (int)$row['id'];
        $csv = [
            $row['sku'] ?? '',
            $row['square_payment_link'] ?? '',
            $row['stripe_payment_link'] ?? '',
            $row['paypal_payment_link'] ?? '',
        ];
        foreach ($extraGateways as $g) {
            $csv[] = $linkMap[$pid][$g['code']] ?? '';
        }
        fputcsv($out, $csv);
    }
    fclose($out);
    exit;
}

if ($action === 'save_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF');
    }
    $id = (int)($_POST['id'] ?? 0);
    $square = pl_sanitize_url($_POST['square_payment_link'] ?? '');
    $stripe = pl_sanitize_url($_POST['stripe_payment_link'] ?? '');
    $paypal = pl_sanitize_url($_POST['paypal_payment_link'] ?? '');
    $extraLinks = $_POST['extra_links'] ?? [];

    $stmt = $pdo->prepare("UPDATE products SET square_payment_link = ?, square_credit_link = ?, square_debit_link = ?, square_afterpay_link = ?, stripe_payment_link = ?, paypal_payment_link = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$square, $square, $square, $square, $stripe, $paypal, $id]);
    if ($extraGateways) {
        $del = $pdo->prepare("DELETE FROM product_payment_links WHERE product_id = ? AND gateway_code = ?");
        $ins = $pdo->prepare("INSERT INTO product_payment_links (product_id, gateway_code, link, created_at, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE link=VALUES(link), updated_at=VALUES(updated_at)");
        foreach ($extraGateways as $g) {
            $code = $g['code'];
            $link = pl_sanitize_url($extraLinks[$code] ?? '');
            $del->execute([$id, $code]);
            if ($link !== '') {
                $ins->execute([$id, $code, $link, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            }
        }
    }
    pl_flash('success', 'Links atualizados para o produto #'.$id);
    header('Location: payment_links.php');
    exit;
}

if ($action === 'clear_link' && isset($_GET['id'])) {
    if (!csrf_check($_GET['csrf'] ?? '')) {
        die('CSRF');
    }
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE products SET square_payment_link = NULL, square_credit_link = NULL, square_debit_link = NULL, square_afterpay_link = NULL, stripe_payment_link = NULL, paypal_payment_link = NULL, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$id]);
    $pdo->prepare("DELETE FROM product_payment_links WHERE product_id = ?")->execute([$id]);
    pl_flash('success', 'Links limpos para o produto #'.$id);
    header('Location: payment_links.php');
    exit;
}

if ($action === 'clear_all') {
    if (!csrf_check($_GET['csrf'] ?? '')) {
        die('CSRF');
    }
    $pdo->exec("UPDATE products SET square_payment_link = NULL, square_credit_link = NULL, square_debit_link = NULL, square_afterpay_link = NULL, stripe_payment_link = NULL, paypal_payment_link = NULL, updated_at = NOW()");
    $pdo->exec("DELETE FROM product_payment_links");
    pl_flash('success', 'Todos os links de cartão foram removidos (produtos permanecem ativos).');
    header('Location: payment_links.php');
    exit;
}

if ($action === 'bulk_clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF');
    }
    $rawIds = $_POST['ids'] ?? '';
    if (is_array($rawIds)) {
        $ids = array_map('intval', $rawIds);
    } else {
        $ids = array_filter(array_map('intval', explode(',', (string)$rawIds)));
    }
    if (!$ids) {
        pl_flash('warning', 'Selecione ao menos um produto para limpar.');
        header('Location: payment_links.php');
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE products SET square_payment_link = NULL, square_credit_link = NULL, square_debit_link = NULL, square_afterpay_link = NULL, stripe_payment_link = NULL, paypal_payment_link = NULL, updated_at = NOW() WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $del = $pdo->prepare("DELETE FROM product_payment_links WHERE product_id IN ($placeholders)");
    $del->execute($ids);
    pl_flash('success', 'Links limpos para '.count($ids).' produto(s).');
    header('Location: payment_links.php');
    exit;
}

if ($action === 'bulk_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF');
    }
    $productIds = array_map('intval', $_POST['product_ids'] ?? []);
    if (!$productIds) {
        pl_flash('warning', 'Nenhum produto enviado para salvar.');
        header('Location: payment_links.php');
        exit;
    }
    $squareLinks = $_POST['square'] ?? [];
    $stripeLinks = $_POST['stripe'] ?? [];
    $paypalLinks = $_POST['paypal'] ?? [];
    $extraLinksAll = $_POST['extra'] ?? [];

    $upd = $pdo->prepare("UPDATE products SET square_payment_link = ?, square_credit_link = ?, square_debit_link = ?, square_afterpay_link = ?, stripe_payment_link = ?, paypal_payment_link = ?, updated_at = NOW() WHERE id = ?");
    $delExtra = $pdo->prepare("DELETE FROM product_payment_links WHERE product_id = ? AND gateway_code = ?");
    $insExtra = $pdo->prepare("INSERT INTO product_payment_links (product_id, gateway_code, link, created_at, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE link=VALUES(link), updated_at=VALUES(updated_at)");
    $saved = 0;
    foreach ($productIds as $pid) {
        $square = pl_sanitize_url($squareLinks[$pid] ?? '');
        $stripe = pl_sanitize_url($stripeLinks[$pid] ?? '');
        $paypal = pl_sanitize_url($paypalLinks[$pid] ?? '');
        $upd->execute([$square, $square, $square, $square, $stripe, $paypal, $pid]);
        $saved++;
        if ($extraGateways) {
            $rowExtras = $extraLinksAll[$pid] ?? [];
            foreach ($extraGateways as $g) {
                $code = $g['code'];
                $link = pl_sanitize_url($rowExtras[$code] ?? '');
                $delExtra->execute([$pid, $code]);
                if ($link !== '') {
                    $insExtra->execute([$pid, $code, $link, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                }
            }
        }
    }
    pl_flash('success', 'Links salvos para '.$saved.' produto(s).');
    header('Location: payment_links.php');
    exit;
}

if ($action === 'import_links' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF');
    }
    if (empty($_FILES['csv']['name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        pl_flash('warning', 'Envie um arquivo CSV válido.');
        header('Location: payment_links.php');
        exit;
    }
    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$handle) {
        pl_flash('warning', 'Não foi possível ler o arquivo CSV.');
        header('Location: payment_links.php');
        exit;
    }
    $header = fgetcsv($handle);
    $map = ['sku' => null, 'square_payment_link' => null, 'stripe_payment_link' => null, 'paypal_payment_link' => null];
    foreach ($extraGateways as $g) {
        $map[$g['code']] = null;
    }
    foreach ((array)$header as $idx => $col) {
        $col = strtolower(trim((string)$col));
        if (array_key_exists($col, $map)) {
            $map[$col] = $idx;
        }
    }
    if ($map['sku'] === null) {
        fclose($handle);
        pl_flash('warning', 'Cabeçalho inválido. Necessário ao menos a coluna sku.');
        header('Location: payment_links.php');
        exit;
    }
    $updated = 0;
    $stmt = $pdo->prepare("UPDATE products SET square_payment_link = ?, square_credit_link = ?, square_debit_link = ?, square_afterpay_link = ?, stripe_payment_link = ?, paypal_payment_link = ?, updated_at = NOW() WHERE sku = ?");
    $pidBySku = [];
    $prodStmt = $pdo->query("SELECT id, sku FROM products");
    foreach ($prodStmt as $pr) {
        $pidBySku[strtolower($pr['sku'])] = (int)$pr['id'];
    }
    $delExtra = $pdo->prepare("DELETE FROM product_payment_links WHERE product_id = ? AND gateway_code = ?");
    $insExtra = $pdo->prepare("INSERT INTO product_payment_links (product_id, gateway_code, link, created_at, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE link=VALUES(link), updated_at=VALUES(updated_at)");
    while (($row = fgetcsv($handle)) !== false) {
        $sku = trim((string)($row[$map['sku']] ?? ''));
        if ($sku === '') {
            continue;
        }
        $pid = $pidBySku[strtolower($sku)] ?? null;
        if (!$pid) {
            continue;
        }
        $square = $map['square_payment_link'] !== null ? pl_sanitize_url($row[$map['square_payment_link']] ?? '') : '';
        $stripe = $map['stripe_payment_link'] !== null ? pl_sanitize_url($row[$map['stripe_payment_link']] ?? '') : '';
        $paypal = $map['paypal_payment_link'] !== null ? pl_sanitize_url($row[$map['paypal_payment_link']] ?? '') : '';
        $stmt->execute([$square, $square, $square, $square, $stripe, $paypal, $sku]);
        foreach ($extraGateways as $g) {
            $code = $g['code'];
            $idx = $map[$code];
            $link = $idx !== null ? pl_sanitize_url($row[$idx] ?? '') : '';
            $delExtra->execute([$pid, $code]);
            if ($link !== '') {
                $insExtra->execute([$pid, $code, $link, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            }
        }
        if ($stmt->rowCount() > 0) {
            $updated++;
        }
    }
    fclose($handle);
    pl_flash('success', "Importação concluída. Registros atualizados: $updated");
    header('Location: payment_links.php');
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE name LIKE ? OR sku LIKE ?";
    $params = ['%'.$search.'%', '%'.$search.'%'];
}
$stmt = $pdo->prepare("SELECT id, sku, name, price, currency, square_payment_link, square_credit_link, square_debit_link, square_afterpay_link, stripe_payment_link, paypal_payment_link FROM products $where ORDER BY id DESC LIMIT 300");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$extraLinkMap = [];
if ($extraGateways) {
    $linksStmt = $pdo->query("SELECT product_id, gateway_code, link FROM product_payment_links");
    foreach ($linksStmt as $lr) {
        $extraLinkMap[(int)$lr['product_id']][$lr['gateway_code']] = $lr['link'] ?? '';
    }
}
$flash = pl_take_flash();

admin_header('Links de Pagamento por Produto');
?>
<section class="space-y-4">
  <div class="dashboard-hero">
    <div>
      <h1 class="text-2xl font-bold">Links de pagamento por produto</h1>
      <p class="text-white/80">Edite ou limpe rapidamente os links de cartão (Square/Stripe/PayPal) associados a cada produto.</p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
      <a class="btn btn-ghost" href="products.php"><i class="fa-solid fa-arrow-left mr-2"></i>Voltar aos produtos</a>
      <a class="btn btn-alt" href="payment_links.php?action=clear_all&csrf=<?= csrf_token(); ?>" onclick="return confirm('Remover links de cartão de TODOS os produtos?');"><i class="fa-solid fa-eraser mr-2"></i>Limpar todos os links</a>
      <a class="btn btn-ghost" href="payment_links.php?action=export&csrf=<?= csrf_token(); ?>"><i class="fa-solid fa-download mr-2"></i>Exportar CSV</a>
      <form class="flex items-center gap-2" method="post" enctype="multipart/form-data" action="payment_links.php">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="action" value="import_links">
        <label class="btn btn-ghost cursor-pointer">
          <i class="fa-solid fa-upload mr-2"></i>Importar CSV
          <input class="hidden" type="file" name="csv" accept=".csv" onchange="this.form.submit();">
        </label>
      </form>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'warning'; ?>">
      <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
      <span><?= sanitize_html($flash['message']); ?></span>
    </div>
  <?php endif; ?>

  <form class="card p-4 flex flex-wrap items-center gap-3" method="get" action="payment_links.php">
    <input class="input w-full md:w-72" name="q" value="<?= sanitize_html($search); ?>" placeholder="Buscar por nome ou SKU">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass mr-2"></i>Buscar</button>
    <a class="btn btn-ghost" href="payment_links.php">Limpar</a>
  </form>

  <div class="card p-4">
    <?php if (!$products): ?>
      <p class="text-center text-gray-500">Nenhum produto encontrado.</p>
    <?php else: ?>
      <form id="bulk-form" method="post" action="payment_links.php" class="hidden">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="action" value="bulk_clear">
        <input type="hidden" name="ids" id="bulk-ids" value="">
      </form>
      <form method="post" action="payment_links.php">
        <input type="hidden" name="csrf" value="<?= csrf_token(); ?>">
        <input type="hidden" name="action" value="bulk_save">
        <div class="overflow-x-auto">
          <table class="table text-sm">
            <thead>
              <tr>
                <th><input type="checkbox" id="check-all"></th>
                <th>ID</th>
                <th>SKU</th>
                <th>Produto</th>
                <th>Link Square</th>
                <th>Link Stripe</th>
                <th>Link PayPal</th>
                <?php foreach ($extraGateways as $g): ?>
                  <th><?= sanitize_html($g['name'] ?? $g['code']); ?></th>
                <?php endforeach; ?>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): $priceDisplay = format_currency((float)($p['price'] ?? 0), strtoupper($p['currency'] ?? (cfg()['store']['currency'] ?? 'USD'))); ?>
              <tr>
                <td><input type="checkbox" class="row-check" value="<?= (int)$p['id']; ?>"></td>
                <td>#<?= (int)$p['id']; ?><input type="hidden" name="product_ids[]" value="<?= (int)$p['id']; ?>"></td>
                <td><?= sanitize_html($p['sku'] ?? ''); ?></td>
                <td>
                  <div class="font-semibold"><?= sanitize_html($p['name'] ?? ''); ?></div>
                  <div class="text-xs text-gray-500">Preço: <?= $priceDisplay; ?></div>
                </td>
                  <td>
                    <input class="input w-72" type="url" name="square[<?= (int)$p['id']; ?>]" placeholder="https://square.link/..." value="<?= sanitize_html($p['square_payment_link'] ?? ''); ?>">
                  </td>
                  <td>
                    <input class="input w-64" type="url" name="stripe[<?= (int)$p['id']; ?>]" placeholder="https://..." value="<?= sanitize_html($p['stripe_payment_link'] ?? ''); ?>">
                  </td>
                  <td>
                    <input class="input w-64" type="url" name="paypal[<?= (int)$p['id']; ?>]" placeholder="https://..." value="<?= sanitize_html($p['paypal_payment_link'] ?? ''); ?>">
                  </td>
                  <?php foreach ($extraGateways as $g): $code = $g['code']; $val = $extraLinkMap[(int)$p['id']][$code] ?? ''; ?>
                    <td>
                      <input class="input w-64" type="url" name="extra[<?= (int)$p['id']; ?>][<?= sanitize_html($code); ?>]" placeholder="https://..." value="<?= sanitize_html($val); ?>">
                    </td>
                  <?php endforeach; ?>
                  <td class="text-right whitespace-nowrap">
                    <div class="flex items-center gap-2">
                      <a class="btn btn-ghost btn-sm text-red-600" href="payment_links.php?action=clear_link&id=<?= (int)$p['id']; ?>&csrf=<?= csrf_token(); ?>" onclick="return confirm('Limpar links deste produto?');"><i class="fa-solid fa-eraser mr-1"></i>Limpar</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="mt-3 flex items-center gap-2 flex-wrap">
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk mr-2"></i>Salvar todos</button>
          <button class="btn btn-secondary" type="button" id="bulk-clear-btn"><i class="fa-solid fa-eraser mr-2"></i>Limpar selecionados</button>
          <span class="text-xs text-gray-500">Edite vários links e salve de uma vez; selecione e clique em limpar para remover links apenas dos produtos marcados.</span>
        </div>
      </form>
    <?php endif; ?>
  </div>
</section>
<script>
  (function(){
    const checkAll = document.getElementById('check-all');
    const bulkBtn = document.getElementById('bulk-clear-btn');
    if (checkAll) {
      checkAll.addEventListener('change', function(){
        document.querySelectorAll('.row-check').forEach(cb => { cb.checked = checkAll.checked; });
      });
    }
    if (bulkBtn) {
      bulkBtn.addEventListener('click', function(){
        const ids = [];
        document.querySelectorAll('.row-check:checked').forEach(cb => ids.push(cb.value));
        if (!ids.length) {
          alert('Selecione ao menos um produto.');
          return;
        }
        if (!confirm('Limpar links dos produtos selecionados?')) return;
        document.getElementById('bulk-ids').value = ids.join(',');
        document.getElementById('bulk-form').submit();
      });
    }
  })();
</script>
<?php admin_footer();
