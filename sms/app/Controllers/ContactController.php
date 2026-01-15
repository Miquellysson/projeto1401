<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Contact;

class ContactController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $contactModel = new Contact($this->db);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        $contacts = $contactModel->paginate($perPage, $offset);
        $total = $contactModel->countAll();
        $totalPages = (int)ceil($total / $perPage);

        $success = $this->getFlash('success');
        $error = $this->getFlash('error');

        $this->render('contacts/index', compact('contacts', 'page', 'totalPages', 'success', 'error'));
    }

    public function store(): void
    {
        $this->requireAuth();
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (!$this->isValidPhone($phone)) {
            $this->setFlash('error', 'Telefone inválido. Use formato internacional, ex: +5511999999999');
            $this->redirect('contacts');
        }

        $contactModel = new Contact($this->db);
        $contactModel->create($name, $phone);
        $this->setFlash('success', 'Contato adicionado com sucesso.');
        $this->redirect('contacts');
    }

    public function import(): void
    {
        $this->requireAuth();
        if (empty($_FILES['csv']['tmp_name'])) {
            $this->setFlash('error', 'Nenhum arquivo enviado.');
            $this->redirect('contacts');
        }

        $contactModel = new Contact($this->db);
        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        $added = 0;
        $skipped = 0;

        // Espera colunas: nome, telefone
        while (($row = fgetcsv($handle, 1000, ';')) !== false) {
            if (count($row) < 2) {
                $skipped++;
                continue;
            }
            [$name, $phone] = $row;
            $name = trim($name);
            $phone = trim($phone);
            if ($this->isValidPhone($phone)) {
                $contactModel->create($name, $phone);
                $added++;
            } else {
                $skipped++;
            }
        }
        fclose($handle);

        $this->setFlash('success', "Importação concluída. Adicionados: {$added}. Ignorados: {$skipped}.");
        $this->redirect('contacts');
    }

    private function isValidPhone(string $phone): bool
    {
        return (bool)preg_match('/^\\+[1-9]\\d{7,14}$/', $phone);
    }
}
