<?php

namespace App\Services;

use App\Config\RabbitMQConfig;
use App\Models\Message;
use App\Models\AuditLog;

class MessagingService
{
    private RabbitMQConfig $config;
    private Message $messageModel;
    private AuditLog $auditLog;

    public function __construct()
    {
        $this->config = new RabbitMQConfig();
        $this->messageModel = new Message();
        $this->auditLog = new AuditLog();
    }

    public function sendMessage(int $eventId, int $senderId, string $content, string $priority = 'normal'): array
    {
        $messageId = $this->messageModel->createMessage($eventId, $senderId, $content, Message::TYPE_TEXT, $priority);
        $message = $this->messageModel->findById($messageId);
        $this->publishToQueue(['type' => 'message', 'event_id' => $eventId, 'sender_id' => $senderId, 'content' => $content, 'priority' => $priority, 'message_id' => $messageId, 'timestamp' => date('c')]);
        $this->auditLog->logAction($senderId, 'message_sent', "Message $messageId sent to event $eventId");
        return $message;
    }

    public function sendSystemMessage(int $eventId, string $content, string $priority = 'normal'): array
    {
        $messageId = $this->messageModel->create(['event_id' => $eventId, 'sender_id' => null, 'content' => $content, 'message_type' => Message::TYPE_SYSTEM, 'priority' => $priority]);
        $this->publishToQueue(['type' => 'system', 'event_id' => $eventId, 'content' => $content, 'priority' => $priority, 'message_id' => $messageId, 'timestamp' => date('c')]);
        return $this->messageModel->findById($messageId);
    }

    public function sendCommand(int $eventId, int $senderId, string $command): array
    {
        $messageId = $this->messageModel->create(['event_id' => $eventId, 'sender_id' => $senderId, 'content' => $command, 'message_type' => Message::TYPE_COMMAND, 'priority' => Message::PRIORITY_URGENT]);
        $this->publishToQueue(['type' => 'command', 'event_id' => $eventId, 'sender_id' => $senderId, 'content' => $command, 'message_id' => $messageId, 'timestamp' => date('c')]);
        $this->auditLog->logAction($senderId, 'command_sent', "Command sent to event $eventId: $command");
        return $this->messageModel->findById($messageId);
    }

    public function getEventMessages(int $eventId): array
    {
        return $this->messageModel->getEventMessages($eventId);
    }

    private function publishToQueue(array $data): void
    {
        $payload = json_encode($data);
        $exchange = $this->config->getEmergencyExchange();
        $url = sprintf('http://%s:%s@%s:15672/api/exchanges/%%2f/%s/publish', $this->config->getUser(), $this->config->getPassword(), $this->config->getHost(), $exchange);
        $body = json_encode(['properties' => ['content_type' => 'application/json', 'delivery_mode' => 2], 'routing_key' => $data['type'] ?? 'message', 'payload' => $payload, 'payload_encoding' => 'string']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 10]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            error_log("RabbitMQ publish failed: HTTP $httpCode - $response");
            $this->storeDeadLetter($payload, "HTTP $httpCode");
        }
    }

    private function storeDeadLetter(string $payload, string $error): void
    {
        try {
            $stmt = \App\Config\Database::getInstance()->getConnection()->prepare(
                "INSERT INTO dead_letter_queue (queue_name, payload, error_message, retry_count) VALUES (:queue, :payload, :error, 0)"
            );
            $stmt->execute([':queue' => $this->config->getMessageQueue(), ':payload' => $payload, ':error' => $error]);
        } catch (\Exception $e) {
            error_log("Failed to store dead letter: " . $e->getMessage());
        }
    }
}
