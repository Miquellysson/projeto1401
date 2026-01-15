<?php
namespace App\Core;

class Controller
{
    protected \PDO $db;
    protected array $config;

    public function __construct(\PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    protected function setFlash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    protected function getFlash(string $type): ?string
    {
        if (!empty($_SESSION['flash'][$type])) {
            $msg = $_SESSION['flash'][$type];
            unset($_SESSION['flash'][$type]);
            return $msg;
        }
        return null;
    }

    protected function render(string $view, array $data = []): void
    {
        extract($data);
        $baseUrl = $this->config['app_url'];
        require __DIR__ . '/../Views/layouts/header.php';
        require __DIR__ . '/../Views/' . $view . '.php';
        require __DIR__ . '/../Views/layouts/footer.php';
    }

    protected function redirect(string $route): void
    {
        header("Location: {$this->config['app_url']}?route={$route}");
        exit;
    }

    protected function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            $this->redirect('login');
        }
    }
}
