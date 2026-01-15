<?php
namespace App\Models;

use App\Core\Model;

class CampaignRecipient extends Model
{
    public function create(int $campaignId, int $contactId): int
    {
        $stmt = $this->db->prepare('INSERT INTO campaign_recipients (campaign_id, contact_id, status) VALUES (:campaign_id, :contact_id, :status)');
        $stmt->execute([
            'campaign_id' => $campaignId,
            'contact_id' => $contactId,
            'status' => 'pending',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateStatus(int $id, string $status, ?string $providerId, ?string $error): void
    {
        $stmt = $this->db->prepare('UPDATE campaign_recipients SET status = :status, provider_message_id = :provider_id, error_message = :error_message, sent_at = CASE WHEN :status = "sent" THEN NOW() ELSE sent_at END WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'provider_id' => $providerId,
            'error_message' => $error,
            'id' => $id,
        ]);
    }

    public function findByCampaign(int $campaignId): array
    {
        $sql = "SELECT cr.*, c.name as contact_name, c.phone as contact_phone
            FROM campaign_recipients cr
            JOIN contacts c ON cr.contact_id = c.id
            WHERE cr.campaign_id = :campaign_id
            ORDER BY cr.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['campaign_id' => $campaignId]);
        return $stmt->fetchAll();
    }
}
