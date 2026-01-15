<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Models\Contact;
use App\Models\Campaign;
use App\Models\CampaignRecipient;

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $contactModel = new Contact($this->db);
        $campaignModel = new Campaign($this->db);
        $recipientModel = new CampaignRecipient($this->db);

        $totalContacts = $contactModel->countAll();
        $totalCampaigns = $campaignModel->countAll();
        $totalMessages = (int)$this->db->query("SELECT COUNT(*) FROM campaign_recipients WHERE status = 'sent'")->fetchColumn();

        $this->render('dashboard/index', compact('totalContacts', 'totalCampaigns', 'totalMessages'));
    }
}
