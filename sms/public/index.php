<?php
declare(strict_types=1);

// Front controller simples: carrega configuração, inicia sessão e direciona para os controllers.

$rootPath = dirname(__DIR__);

$config = require $rootPath . '/config/config.php';

// Configura a sessão com nome customizado para evitar colisões.
if (!session_id()) {
    session_name($config['session_name']);
    session_start();
}

// Autoloader simples para classes em app/
spl_autoload_register(function ($class) use ($rootPath) {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = $rootPath . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($path)) {
            require $path;
        }
    }
});

// Carrega Composer autoload se existir (para twilio/sdk).
$composerAutoload = $rootPath . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require $composerAutoload;
}

// Cria conexão com banco.
$db = require $rootPath . '/config/database.php';

// Decide rota; se já logado cai no dashboard.
$route = $_GET['route'] ?? (isset($_SESSION['user_id']) ? 'dashboard' : 'login');

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ContactController;
use App\Controllers\CampaignController;

switch ($route) {
    case 'login':
        (new AuthController($db, $config))->login();
        break;
    case 'logout':
        (new AuthController($db, $config))->logout();
        break;
    case 'dashboard':
        (new DashboardController($db, $config))->index();
        break;
    case 'contacts':
        (new ContactController($db, $config))->index();
        break;
    case 'contacts/store':
        (new ContactController($db, $config))->store();
        break;
    case 'contacts/import':
        (new ContactController($db, $config))->import();
        break;
    case 'campaigns':
        (new CampaignController($db, $config))->index();
        break;
    case 'campaigns/new':
        (new CampaignController($db, $config))->create();
        break;
    case 'campaigns/store':
        (new CampaignController($db, $config))->store();
        break;
    case 'campaigns/show':
        (new CampaignController($db, $config))->show();
        break;
    default:
        http_response_code(404);
        echo 'Rota não encontrada.';
        break;
}
