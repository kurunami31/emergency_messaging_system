<?php

namespace App\Models;

class AuditLog extends BaseModel
{
    protected string $table = 'audit_log';

    public function logAction(?int $userId, string $action, ?string $details = null, ?string $ipAddress = null): int
    {
        return $this->create([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    public function getLogs(int $limit = 100, int $offset = 0, ?string $search = null, ?int $userId = null, ?string $action = null, ?string $from = null, ?string $to = null): array
    {
        $conditions = [];
        $params = [];

        if ($search) {
            $conditions[] = '(al.action LIKE :search OR al.details LIKE :search)';
            $params[':search'] = "%{$search}%";
        }
        if ($userId) {
            $conditions[] = 'al.user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        if ($action) {
            $conditions[] = 'al.action = :action';
            $params[':action'] = $action;
        }
        if ($from) {
            $conditions[] = 'al.created_at >= :from_date';
            $params[':from_date'] = $from . ' 00:00:00';
        }
        if ($to) {
            $conditions[] = 'al.created_at <= :to_date';
            $params[':to_date'] = $to . ' 23:59:59';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT al.*, u.display_name as user_name
                FROM {$this->table} al
                LEFT JOIN users u ON al.user_id = u.id
                {$where}
                ORDER BY al.created_at DESC
                LIMIT :lim OFFSET :off";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getLogsByUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :lim"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function cleanupOlderThan(int $days): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM {$this->table} WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
