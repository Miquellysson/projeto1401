<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require __DIR__.'/bootstrap.php';
$configData = require __DIR__.'/config.php';
require __DIR__.'/lib/db.php';
require __DIR__.'/lib/utils.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('is_admin')) {
  function is_admin(){ return !empty($_SESSION['admin_id']); }
}
if (!function_exists('require_admin')) {
  function require_admin(){ if (!is_admin()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; } }
}
if (!function_exists('csrf_check')) {
  function csrf_check($token){ return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token); }
}
if (!function_exists('csrf_token')) {
  function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_admin();
if (!admin_can('manage_builder')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

$pdo = db();
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS page_layouts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    content LONGTEXT,
    styles LONGTEXT,
    meta JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_layout_slug_status (page_slug, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
  // falha silenciosa: endpoint continuará com fallback
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? 'get');
$page   = trim($_GET['page'] ?? ($_POST['page'] ?? 'home'));
if ($page === '') $page = 'home';

function parse_json_body(): array {
  $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }
  return [];
}

function fetch_layout(PDO $pdo, string $page, string $status): ?array {
  try {
    $st = $pdo->prepare("SELECT id, page_slug, status, content, styles, meta, updated_at FROM page_layouts WHERE page_slug = ? AND status = ? LIMIT 1");
    $st->execute([$page, $status]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['meta'] = $row['meta'] ? json_decode($row['meta'], true) : null;
    return $row;
  } catch (Throwable $e) {
    return null;
  }
}

switch ($action) {
  case 'get':
    $draft = fetch_layout($pdo, $page, 'draft');
    $published = fetch_layout($pdo, $page, 'published');
    echo json_encode([
      'ok' => true,
      'draft' => $draft,
      'published' => $published,
      'csrf' => csrf_token(),
    ], JSON_UNESCAPED_UNICODE);
    exit;

  case 'save':
    if ($method !== 'POST') {
      http_response_code(405);
      echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
      exit;
    }
    $payload = parse_json_body();
    if (!$payload) {
      $payload = $_POST;
    }
    $csrf = $payload['csrf'] ?? ($_POST['csrf'] ?? '');
    if (!csrf_check($csrf)) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'invalid_csrf']);
      exit;
    }
    $content = (string)($payload['content'] ?? '');
    $styles  = (string)($payload['styles'] ?? '');
    $meta    = $payload['meta'] ?? null;
    if (!is_array($meta)) $meta = [];
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

    if (strlen($content) > 1024*512) { // ~512 KB
      http_response_code(413);
      echo json_encode(['ok'=>false,'error'=>'content_too_large']);
      exit;
    }

    $st = $pdo->prepare("INSERT INTO page_layouts (page_slug, status, content, styles, meta) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), styles=VALUES(styles), meta=VALUES(meta), updated_at=NOW()");
    $st->execute([$page, 'draft', $content, $styles, $metaJson]);

    echo json_encode(['ok'=>true, 'message'=>'draft_saved']);
    exit;

  case 'publish':
    if (!in_array($method, ['POST','PUT'], true)) {
      http_response_code(405);
      echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
      exit;
    }
    $payload = parse_json_body();
    if (!$payload) $payload = $_POST;
    $csrf = $payload['csrf'] ?? ($_POST['csrf'] ?? '');
    if (!csrf_check($csrf)) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'invalid_csrf']);
      exit;
    }
    $draft = fetch_layout($pdo, $page, 'draft');
    if (!$draft) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'no_draft']);
      exit;
    }
    $meta = $draft['meta'] ?? [];
    if (!is_array($meta)) $meta = [];
    $meta['published_at'] = date('c');
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

    $st = $pdo->prepare("INSERT INTO page_layouts (page_slug, status, content, styles, meta) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), styles=VALUES(styles), meta=VALUES(meta), updated_at=NOW()");
    $st->execute([$page, 'published', $draft['content'] ?? '', $draft['styles'] ?? '', $metaJson]);

    echo json_encode(['ok'=>true,'message'=>'published']);
    exit;

  default:
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_action']);
    exit;
}
