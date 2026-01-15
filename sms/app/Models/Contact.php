<?php
namespace App\Models;

use App\Core\Model;

class Contact extends Model
{
    public function countAll(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    }

    public function paginate(int $limit, int $offset): array
    {
        $stmt = $this->db->prepare('SELECT * FROM contacts ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function create(string $name, string $phone): void
    {
        $stmt = $this->db->prepare('INSERT INTO contacts (name, phone, created_at, updated_at) VALUES (:name, :phone, NOW(), NOW())');
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM contacts ORDER BY name ASC')->fetchAll();
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM contacts WHERE id IN ($placeholders)");
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
