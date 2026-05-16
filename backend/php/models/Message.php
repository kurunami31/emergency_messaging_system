<?php

namespace App\Models;

class Message extends BaseModel
{
    protected string $table = 'messages';

    public const TYPE_TEXT = 'text';
    public const TYPE_ALERT = 'alert';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_COMMAND = 'command';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public function createMessage(int $eventId, ?int $senderId, string $content, string $type = self::TYPE_TEXT, string $priority = self::PRIORITY_NORMAL): int
    {
        return $this->create([
            'event_id' => $eventId,
            'sender_id' => $senderId,
            'content' => $content,
            'message_type' => $type,
            'priority' => $priority,
        ]);
    }

    public function getEventMessages(int $eventId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, u.display_name as sender_name
             FROM {$this->table} m
             LEFT JOIN users u ON m.sender_id = u.id
             WHERE m.event_id = :event_id
             ORDER BY m.created_at ASC"
        );
        $stmt->execute([':event_id' => $eventId]);
        return $stmt->fetchAll();
    }
}
