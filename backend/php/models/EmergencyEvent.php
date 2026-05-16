<?php

namespace App\Models;

class EmergencyEvent extends BaseModel
{
    protected string $table = 'emergency_events';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ARCHIVED = 'archived';

    public function getActiveEvents(): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, u.display_name as created_by_name
             FROM {$this->table} e
             JOIN users u ON e.created_by = u.id
             WHERE e.status = :status
             ORDER BY FIELD(e.severity, 'critical', 'high', 'medium', 'low'), e.created_at DESC"
        );
        $stmt->execute([':status' => self::STATUS_ACTIVE]);
        return $stmt->fetchAll();
    }

    public function getEventsByStatus(string $status): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, u.display_name as created_by_name
             FROM {$this->table} e
             JOIN users u ON e.created_by = u.id
             WHERE e.status = :status
             ORDER BY e.created_at DESC"
        );
        $stmt->execute([':status' => $status]);
        return $stmt->fetchAll();
    }

    public function getEventsBySeverity(string $severity): array
    {
        return $this->findBy('severity', $severity);
    }

    public function resolve(int $eventId, int $resolvedBy): bool
    {
        return $this->update($eventId, [
            'status' => self::STATUS_RESOLVED,
            'resolved_by' => $resolvedBy,
            'resolved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function archive(int $eventId): bool
    {
        return $this->update($eventId, ['status' => self::STATUS_ARCHIVED]);
    }

    public function getCriticalActiveCount(): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as cnt FROM {$this->table}
             WHERE status = :status AND severity = :severity"
        );
        $stmt->execute([':status' => self::STATUS_ACTIVE, ':severity' => self::SEVERITY_CRITICAL]);
        return (int)$stmt->fetch()['cnt'];
    }
}
