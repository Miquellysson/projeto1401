<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Services\TwilioSmsService;

class CampaignController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();
        $campaignModel = new Campaign($this->db);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        $campaigns = $campaignModel->paginateWithStats($perPage, $offset);
        $total = $campaignModel->countAll();
        $totalPages = (int)ceil($total / $perPage);

        $success = $this->getFlash('success');
        $error = $this->getFlash('error');

        $this->render('campaigns/index', compact('campaigns', 'page', 'totalPages', 'success', 'error'));
    }

    public function create(): void
    {
        $this->requireAuth();
        $contacts = (new Contact($this->db))->findAll();
        $error = $this->getFlash('error');
        $success = $this->getFlash('success');
        $this->render('campaigns/create', compact('contacts', 'error', 'success'));
    }

    public function store(): void
    {
        $this->requireAuth();

        $name = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $sendTo = $_POST['send_to'] ?? 'all';
        $selectedIds = isset($_POST['contacts']) ? array_map('intval', $_POST['contacts']) : [];

        if (!$name || !$message) {
            $this->setFlash('error', 'Preencha nome e mensagem.');
            $this->redirect('campaigns/new');
        }

        $contactModel = new Contact($this->db);
        $contacts = $sendTo === 'all' ? $contactModel->findAll() : $contactModel->findByIds($selectedIds);

        if (empty($contacts)) {
            $this->setFlash('error', 'Nenhum contato selecionado.');
            $this->redirect('campaigns/new');
        }

        $campaignModel = new Campaign($this->db);
        $recipientModel = new CampaignRecipient($this->db);

        $campaignId = $campaignModel->create($name, $message);

        // Verifica credenciais do provedor
        $twilio = $this->config['twilio'];
        if (empty($twilio['sid']) || empty($twilio['token']) || empty($twilio['from'])) {
            $this->setFlash('error', 'Configure SID, Token e número remetente do Twilio no .env.');
            $this->redirect('campaigns/new');
        }
        if (!class_exists(\Twilio\Rest\Client::class)) {
            $this->setFlash('error', 'Pacote Twilio não encontrado. Rode "composer install".');
            $this->redirect('campaigns/new');
        }

        $smsService = new TwilioSmsService($twilio['sid'], $twilio['token'], $twilio['from']);

        foreach ($contacts as $contact) {
            $recipientId = $recipientModel->create($campaignId, (int)$contact['id']);
            $result = $smsService->sendSms($contact['phone'], $message);
            $status = $result['success'] ? 'sent' : 'failed';
            $recipientModel->updateStatus($recipientId, $status, $result['message_id'], $result['error']);
        }

        $this->setFlash('success', 'Campanha enviada.');
        $this->redirect('campaigns');
    }

    public function show(): void
    {
        $this->requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $campaignModel = new Campaign($this->db);
        $campaign = $campaignModel->findWithStats($id);
        if (!$campaign) {
            $this->setFlash('error', 'Campanha não encontrada.');
            $this->redirect('campaigns');
        }

        $recipientModel = new CampaignRecipient($this->db);
        $recipients = $recipientModel->findByCampaign($id);

        $this->render('campaigns/show', compact('campaign', 'recipients'));
    }
}
