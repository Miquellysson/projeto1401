<?php
// lib/utils.php - utilitários básicos + settings persistentes
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function csrf_token(){
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
  return $_SESSION['csrf_token'];
}
function csrf_check($t){ return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'],$t); }

function validate_email($email){ return filter_var($email, FILTER_VALIDATE_EMAIL)!==false; }
function sanitize_string($s,$max=255){ $s=trim(strip_tags($s)); return mb_substr($s,0,$max,'UTF-8'); }
function sanitize_html($h){ return htmlspecialchars($h, ENT_QUOTES|ENT_HTML5,'UTF-8'); }

// -------- SETTINGS TABLE (auto) --------
function settings_bootstrap(){
  try{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings(
      skey VARCHAR(191) PRIMARY KEY,
      svalue LONGTEXT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  }catch(Throwable $e){}
}
settings_bootstrap();

function setting_get($key, $default=null){
  try{
    $pdo = db();
    $st = $pdo->prepare("SELECT svalue FROM settings WHERE skey=?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    if ($v===false) return $default;
    $decoded = json_decode($v, true);
    return (json_last_error()===JSON_ERROR_NONE) ? $decoded : $v;
  }catch(Throwable $e){
    return $default;
  }
}
function setting_set($key, $value){
  try{
    $pdo = db();
    $val = is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
    $st = $pdo->prepare("INSERT INTO settings(skey,svalue) VALUES(?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
    return $st->execute([$key,$val]);
  }catch(Throwable $e){ return false; }
}

if (!function_exists('find_logo_path')) {
  function find_logo_path(){
    $cfgLogo = setting_get('store_logo_url');
    if($cfgLogo) return $cfgLogo;
    $candidates = ['storage/logo/logo.png','storage/logo/logo.jpg','storage/logo/logo.jpeg','storage/logo/logo.webp'];
    foreach($candidates as $c){ if(file_exists(__DIR__.'/../'.$c)) return $c; }
    return null;
  }
}

// Email simplificado (usa suporte do PHP)
function send_email($to,$subject,$html,$from=null){
  if(!$from){
    $cfg = cfg();
    $from = $cfg['store']['support_email'] ?? ('no-reply@'.($_SERVER['HTTP_HOST'] ?? 'localhost'));
  }
  $headers = [
    'From: '.$from,
    'Reply-To: '.$from,
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8'
  ];
  return @mail($to,$subject,$html,implode("\r\n",$headers));
}

// Carrega cfg() do config.php sem tocar credenciais
function cfg(){
  static $config=null;
  if($config===null){
    $config = require __DIR__.'/../config.php';
  }
  return $config;
}
