<?php

namespace App\Models;

class Alert extends BaseModel
{
    protected string $table = 'alerts';

    public function createAlert(int $eventId, string $type, string $targetRole, string $title, string $message): int
    {
        return $this->create([
            'event_id' => $eventId,
            'type' => $type,
            'target_role' => $targetRole,
            'title' => $title,
            'message' => $message,
        ]);
    }

    public function getActiveAlerts(): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, e.title as event_title, u.display_name as acknowledged_by_name
             FROM {$this->table} a
             JOIN emergency_events e ON a.event_id = e.id
             LEFT JOIN users u ON a.acknowledged_by = u.id
             ORDER BY a.created_at DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getAlertsByEvent(int $eventId): array
    {
        return $this->findBy('event_id', $eventId);
    }

    public function acknowledge(int $alertId, int $userId): bool
    {
        return $this->update($alertId, [
            'is_acknowledged' => 1,
            'acknowledged_by' => $userId,
            'acknowledged_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getUnacknowledgedCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM {$this->table} WHERE is_acknowledged = 0");
        $stmt->execute();
        return (int)$stmt->fetch()['cnt'];
    }
}
