<?php
// lint_all.php — varredura de sintaxe em TODOS os .php (sem executar a app)
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$root = __DIR__;
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$errors = [];
$total = 0;
$phpBinary = PHP_BINARY ?: 'php';

/**
 * Executa php -l para validar sintaxe sem executar o arquivo.
 */
function lint_file(string $binary, string $path): array {
  $cmd = escapeshellarg($binary) . ' -d display_errors=1 -l ' . escapeshellarg($path);
  $output = [];
  $exit = 0;

  if (function_exists('exec')) {
    exec($cmd, $output, $exit);
  } elseif (function_exists('shell_exec')) {
    $raw = shell_exec($cmd . ' 2>&1');
    $exit = is_string($raw) ? (preg_match('/No syntax errors/', $raw) ? 0 : 1) : 1;
    $output = $raw !== null ? preg_split("/\r?\n/", trim((string)$raw)) : [];
  } else {
    return [false, 'Nenhuma função de shell disponível para executar php -l.'];
  }

  return [$exit === 0, implode("\n", $output)];
}

foreach ($rii as $file) {
  if ($file->isDir()) continue;
  $path = $file->getPathname();
  if (substr($path, -4) !== '.php') continue;
  if (basename($path) === 'lint_all.php') continue;
  $total++;
  [$ok, $msg] = lint_file($phpBinary, $path);
  if (!$ok) {
    $errors[] = [$path, $msg];
  }
}

echo "=== Lint ALL PHP ===\n";
echo "Arquivos verificados: $total\n";
if (empty($errors)) {
  echo "[OK] Nenhum erro de sintaxe detectado.\n";
} else {
  echo "[ERROS] Arquivos com problema:\n";
  foreach ($errors as [$file, $msg]) {
    echo " - $file\n";
    if ($msg !== '') {
      echo "     $msg\n";
    }
  }
}
