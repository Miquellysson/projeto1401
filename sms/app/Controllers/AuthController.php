<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\User;

class AuthController extends Controller
{
    public function login(): void
    {
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            $userModel = new User($this->db);
            $user = $userModel->findByEmail($email);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $this->redirect('dashboard');
            } else {
                $error = 'Credenciais inválidas.';
            }
        }

        // Renderiza sem header completo; login tem layout próprio simples.
        $baseUrl = $this->config['app_url'];
        require __DIR__ . '/../Views/auth/login.php';
    }

    public function logout(): void
    {
        session_destroy();
        $this->redirect('login');
    }
}
