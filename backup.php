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
    function require_admin() {
        if (empty($_SESSION['admin_id'])) {
            header('Location: admin.php?route=login');
            exit;
        }
    }
}

require_admin();
if (!function_exists('is_super_admin') || !is_super_admin()) {
    admin_forbidden('Apenas super administradores podem acessar os backups.');
}

$pdo = db();
$backupDir = __DIR__ . '/storage/backups';
@mkdir($backupDir, 0775, true);

function backup_list_files(string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path) && preg_match('/\\.zip$/i', $entry)) {
            $files[] = [
                'name' => $entry,
                'path' => $path,
                'size' => filesize($path),
                'mtime' => filemtime($path),
            ];
        }
    }
    usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $files;
}

function backup_safe_filename(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\\-\\.]/', '_', $name);
}

function backup_add_path(ZipArchive $zip, string $path, string $relativePrefix = ''): void {
    if (!file_exists($path)) {
        return;
    }

    $path = realpath($path);
    if ($path === false) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getRealPath();
        if ($itemPath === false) {
            continue;
        }
        $localName = ltrim($relativePrefix . substr($itemPath, strlen($path)), '/\\');
        $localName = str_replace('\\', '/', $localName);
        if ($item->isDir()) {
            if ($localName !== '') {
                $zip->addEmptyDir($localName.'/');
            }
        } else {
            $zip->addFile($itemPath, $localName);
        }
    }
}

$backupTables = [
    'settings',
    'users',
    'products',
    'categories',
    'orders',
    'order_items',
    'customers',
    'payment_methods',
];

function backup_recursive_delete(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        backup_recursive_delete($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function backup_copy_directory(string $source, string $destination): void {
    if (!is_dir($source)) {
        return;
    }
    if (!is_dir($destination)) {
        @mkdir($destination, 0775, true);
    }
    $items = scandir($source) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($src)) {
            backup_copy_directory($src, $dst);
        } else {
            @mkdir(dirname($dst), 0775, true);
            @copy($src, $dst);
        }
    }
}

$action = $_GET['action'] ?? '';
if ($action === 'download') {
    $file = $_GET['file'] ?? '';
    $normalized = backup_safe_filename($file);
    $fullPath = $backupDir . DIRECTORY_SEPARATOR . $normalized;
    if (!is_file($fullPath)) {
        http_response_code(404);
        echo 'Arquivo não encontrado.';
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$normalized.'"');
    header('Content-Length: '.filesize($fullPath));
    readfile($fullPath);
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF inválido');
    }
    $file = $_POST['file'] ?? '';
    $normalized = backup_safe_filename($file);
    $fullPath = $backupDir . DIRECTORY_SEPARATOR . $normalized;
    if (is_file($fullPath)) {
        @unlink($fullPath);
        $_SESSION['backup_notice'] = 'Backup removido: '.$normalized;
    }
    header('Location: backup.php');
    exit;
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF inválido');
    }
    $timestamp = date('Ymd_His');
    $zipName = "backup_{$timestamp}.zip";
    $zipPath = $backupDir . DIRECTORY_SEPARATOR . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $_SESSION['backup_error'] = 'Não foi possível iniciar o arquivo ZIP.';
        header('Location: backup.php');
        exit;
    }

    $pivot = [];
    try {
        foreach ($backupTables as $table) {
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $zip->addFromString("database/{$table}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $createStmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $schemaRow = $createStmt ? $createStmt->fetch(PDO::FETCH_NUM) : null;
            if ($schemaRow && isset($schemaRow[1])) {
                $zip->addFromString("database/{$table}.sql", $schemaRow[1] . ";\n");
            }

            $pivot[$table] = [
                'rows' => is_array($rows) ? count($rows) : 0,
            ];
        }
    } catch (Throwable $e) {
        $zip->close();
        @unlink($zipPath);
        $_SESSION['backup_error'] = 'Falha ao exportar dados: '.$e->getMessage();
        header('Location: backup.php');
        exit;
    }

    $storagePaths = [
        'storage/logo' => 'storage/logo',
        'storage/products' => 'storage/products',
        'storage/zelle_receipts' => 'storage/zelle_receipts',
        'storage/logs' => 'storage/logs',
    ];
    foreach ($storagePaths as $path => $relative) {
        $full = realpath(__DIR__ . '/' . $path);
        if ($full && is_dir($full)) {
            backup_add_path($zip, $full, $relative);
        }
    }

    $configPath = realpath(__DIR__ . '/config.php');
    if ($configPath && is_file($configPath)) {
        $zip->addFile($configPath, 'config.php');
    }

    $manifest = [
        'generated_at' => date('c'),
        'app_base_url' => cfg()['store']['base_url'] ?? '',
        'tables' => $pivot,
        'includes' => [
            'database' => true,
            'storage_logo' => is_dir(__DIR__.'/storage/logo'),
            'storage_products' => is_dir(__DIR__.'/storage/products'),
            'storage_zelle_receipts' => is_dir(__DIR__.'/storage/zelle_receipts'),
            'storage_logs' => is_dir(__DIR__.'/storage/logs'),
            'config_php' => is_file(__DIR__.'/config.php'),
        ],
    ];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $_SESSION['backup_notice'] = 'Backup gerado com sucesso: '.$zipName;
    header('Location: backup.php');
    exit;
}

if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF inválido');
    }
    $file = $_POST['file'] ?? '';
    $normalized = backup_safe_filename($file);
    $fullPath = $backupDir . DIRECTORY_SEPARATOR . $normalized;
    if (!is_file($fullPath)) {
        $_SESSION['backup_error'] = 'Backup não encontrado.';
        header('Location: backup.php');
        exit;
    }

    $tmpDir = $backupDir . DIRECTORY_SEPARATOR . ('restore_' . bin2hex(random_bytes(8)));
    @mkdir($tmpDir, 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($fullPath) !== true) {
        $_SESSION['backup_error'] = 'Não foi possível abrir o arquivo de backup.';
        header('Location: backup.php');
        exit;
    }
    $zip->extractTo($tmpDir);
    $zip->close();

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($backupTables as $table) {
            $jsonPath = $tmpDir . "/database/{$table}.json";
            if (!is_file($jsonPath)) {
                continue;
            }
            $json = file_get_contents($jsonPath);
            $rows = json_decode($json, true);
            if (!is_array($rows)) {
                $rows = [];
            }
            $pdo->exec("TRUNCATE TABLE `$table`");
            if (!$rows) {
                continue;
            }
            $columns = array_keys($rows[0]);
            $columnsQuoted = array_map(fn($col) => '`'.str_replace('`', '``', $col).'`', $columns);
            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $insertSql = "INSERT INTO `$table` (".implode(',', $columnsQuoted).") VALUES ($placeholders)";
            $stmt = $pdo->prepare($insertSql);
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? null;
                }
                $stmt->execute($values);
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $e) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        backup_recursive_delete($tmpDir);
        $_SESSION['backup_error'] = 'Falha ao restaurar o banco: '.$e->getMessage();
        header('Location: backup.php');
        exit;
    }

    $storageMap = [
        'storage/logo' => __DIR__ . '/storage/logo',
        'storage/products' => __DIR__ . '/storage/products',
        'storage/zelle_receipts' => __DIR__ . '/storage/zelle_receipts',
        'storage/logs' => __DIR__ . '/storage/logs',
    ];
    foreach ($storageMap as $from => $to) {
        $src = $tmpDir . '/' . $from;
        if (is_dir($src)) {
            backup_copy_directory($src, $to);
        }
    }

    $backupConfigPath = $tmpDir . '/config.php';
    if (is_file($backupConfigPath)) {
        $currentConfig = __DIR__ . '/config.php';
        if (is_file($currentConfig)) {
            @copy($currentConfig, __DIR__ . '/config.php.bak_'.date('Ymd_His'));
        }
        @copy($backupConfigPath, $currentConfig);
    }

    backup_recursive_delete($tmpDir);
    $_SESSION['backup_notice'] = 'Backup restaurado com sucesso: '.$normalized;
    header('Location: backup.php');
    exit;
}

$backups = backup_list_files($backupDir);
$notice = $_SESSION['backup_notice'] ?? '';
$error = $_SESSION['backup_error'] ?? '';
unset($_SESSION['backup_notice'], $_SESSION['backup_error']);

admin_header('Backups');

echo '<section class="card p-6 mb-6">';
echo '  <div class="flex items-center justify-between gap-4 flex-wrap">';
echo '    <div>';
echo '      <h1 class="text-2xl font-bold">Backups completos</h1>';
echo '      <p class="text-sm text-gray-500">Gere um pacote ZIP com dados (produtos, pedidos, configurações) e arquivos principais.</p>';
echo '      <p class="text-xs text-amber-600 mt-2">Cuidado: restaurar um backup substituirá os dados e arquivos atuais. Faça download antes se precisar preservar o estado atual.</p>';
echo '    </div>';
echo '    <form method="post" action="backup.php?action=create">';
echo '      <input type="hidden" name="csrf" value="'.csrf_token().'">';
echo '      <button type="submit" class="btn btn-primary px-4 py-2"><i class="fa-solid fa-cloud-arrow-down mr-2"></i>Gerar backup</button>';
echo '    </form>';
echo '  </div>';
echo '</section>';

if ($notice) {
    echo '<div class="card p-4 mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center gap-3">';
    echo '  <i class="fa-solid fa-circle-check"></i> <span>'.sanitize_html($notice).'</span>';
    echo '</div>';
}
if ($error) {
    echo '<div class="card p-4 mb-4 bg-rose-50 border border-rose-200 text-rose-800 flex items-center gap-3">';
    echo '  <i class="fa-solid fa-circle-exclamation"></i> <span>'.sanitize_html($error).'</span>';
    echo '</div>';
}

echo '<section class="card p-6">';
if (!$backups) {
    echo '<p class="text-sm text-gray-500">Nenhum backup disponível ainda. Gere um novo usando o botão acima.</p>';
} else {
    echo '<div class="overflow-x-auto">';
    echo '<table class="table-auto w-full text-sm">';
    echo '  <thead><tr class="text-left text-gray-500 uppercase text-xs">';
    echo '    <th class="py-2">Arquivo</th>';
    echo '    <th class="py-2">Criado em</th>';
    echo '    <th class="py-2">Tamanho</th>';
    echo '    <th class="py-2 text-right">Ações</th>';
    echo '  </tr></thead><tbody>';
    foreach ($backups as $file) {
        $sizeMb = $file['size'] / (1024 * 1024);
        $formattedSize = number_format($sizeMb, 2, ',', '.').' MB';
        $date = date('d/m/Y H:i', $file['mtime']);
        $safeName = sanitize_html($file['name']);
        echo '<tr class="border-t border-gray-200">';
        echo '  <td class="py-2">'.$safeName.'</td>';
        echo '  <td class="py-2">'.$date.'</td>';
        echo '  <td class="py-2">'.$formattedSize.'</td>';
        echo '  <td class="py-2 text-right flex items-center justify-end gap-2">';
        echo '    <a class="btn btn-ghost px-3 py-1" href="backup.php?action=download&file='.urlencode($file['name']).'"><i class="fa-solid fa-download mr-1"></i>Download</a>';
        echo '    <form method="post" action="backup.php?action=restore" onsubmit="return confirm(\'Restaurar este backup irá substituir os dados atuais. Confirmar?\');">';
        echo '      <input type="hidden" name="csrf" value="'.csrf_token().'">';
        echo '      <input type="hidden" name="file" value="'.$safeName.'">';
        echo '      <button type="submit" class="btn btn-ghost px-3 py-1 text-amber-600 hover:text-amber-700"><i class="fa-solid fa-clock-rotate-left mr-1"></i>Restaurar</button>';
        echo '    </form>';
        echo '    <form method="post" action="backup.php?action=delete" onsubmit="return confirm(\'Remover este backup?\');">';
        echo '      <input type="hidden" name="csrf" value="'.csrf_token().'">';
        echo '      <input type="hidden" name="file" value="'.$safeName.'">';
        echo '      <button type="submit" class="btn btn-ghost px-3 py-1 text-rose-600 hover:text-rose-700"><i class="fa-solid fa-trash-can mr-1"></i>Excluir</button>';
        echo '    </form>';
        echo '  </td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

admin_footer();
