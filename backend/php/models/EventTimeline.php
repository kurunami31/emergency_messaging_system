<?php

namespace App\Models;

class EventTimeline extends BaseModel
{
    protected string $table = 'event_timeline';

    public function addEntry(int $eventId, string $action, ?string $description = null, ?int $createdBy = null): int
    {
        return $this->create([
            'event_id' => $eventId,
            'action' => $action,
            'description' => $description,
            'created_by' => $createdBy,
        ]);
    }

    public function getEntry(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT et.*, u.display_name as created_by_name
             FROM {$this->table} et
             LEFT JOIN users u ON et.created_by = u.id
             WHERE et.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getTimeline(int $eventId): array
    {
        $stmt = $this->db->prepare(
            "SELECT et.*, u.display_name as created_by_name
             FROM {$this->table} et
             LEFT JOIN users u ON et.created_by = u.id
             WHERE et.event_id = :event_id
             ORDER BY et.created_at ASC"
        );
        $stmt->execute([':event_id' => $eventId]);
        return $stmt->fetchAll();
    }
}
