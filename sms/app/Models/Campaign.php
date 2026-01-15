<?php
namespace App\Models;

use App\Core\Model;

class Campaign extends Model
{
    public function countAll(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM campaigns')->fetchColumn();
    }

    public function create(string $name, string $message): int
    {
        $stmt = $this->db->prepare('INSERT INTO campaigns (name, message, created_at) VALUES (:name, :message, NOW())');
        $stmt->execute([
            'name' => $name,
            'message' => $message,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function paginateWithStats(int $limit, int $offset): array
    {
        $sql = "SELECT c.*,
                COUNT(cr.id) as total,
                SUM(CASE WHEN cr.status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN cr.status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM campaigns c
            LEFT JOIN campaign_recipients cr ON c.id = cr.campaign_id
            GROUP BY c.id
            ORDER BY c.created_at DESC
            LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findWithStats(int $id): ?array
    {
        $sql = "SELECT c.*,
                COUNT(cr.id) as total,
                SUM(CASE WHEN cr.status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN cr.status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM campaigns c
            LEFT JOIN campaign_recipients cr ON c.id = cr.campaign_id
            WHERE c.id = :id
            GROUP BY c.id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $campaign = $stmt->fetch();
        return $campaign ?: null;
    }
}
