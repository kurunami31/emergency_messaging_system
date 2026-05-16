<?php

namespace App\Services;

use App\Models\EmergencyEvent;
use App\Models\Alert;
use App\Models\AuditLog;

class SystemAutomationService
{
    private EmergencyEvent $eventModel;
    private Alert $alertModel;
    private AuditLog $auditLog;

    public function __construct()
    {
        $this->eventModel = new EmergencyEvent();
        $this->alertModel = new Alert();
        $this->auditLog = new AuditLog();
    }

    public function runHealthCheck(): array
    {
        $results = [];
        try {
            $db = \App\Config\Database::getInstance()->getConnection();
            $db->query("SELECT 1");
            $results['database'] = 'ok';
        } catch (\Exception $e) {
            $results['database'] = 'failed: ' . $e->getMessage();
        }

        $results['active_events'] = $this->eventModel->getActiveEvents();
        $results['unacknowledged_alerts'] = $this->alertModel->getUnacknowledgedCount();
        $results['timestamp'] = date('Y-m-d H:i:s');
        return $results;
    }

    public function generateDailyReport(): array
    {
        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'total_events' => $this->eventModel->count(),
            'active_events' => count($this->eventModel->getActiveEvents()),
            'critical_events' => $this->eventModel->getCriticalActiveCount(),
            'total_alerts' => $this->alertModel->count(),
            'unacknowledged_alerts' => $this->alertModel->getUnacknowledgedCount(),
        ];
    }

    public function autoArchive(int $days = 7): array
    {
        $db = \App\Config\Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "UPDATE emergency_events SET status = 'archived' WHERE status = 'resolved' AND resolved_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $stmt->execute([':days' => $days]);
        $count = $stmt->rowCount();
        if ($count > 0) $this->auditLog->logAction(null, 'auto_archive', "Archived $count resolved events older than $days days");
        return ['archived_count' => $count];
    }

    public function escalateAlerts(int $minutes = 30): array
    {
        $db = \App\Config\Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT a.*, e.title as event_title FROM alerts a JOIN emergency_events e ON a.event_id = e.id WHERE a.is_acknowledged = 0 AND a.created_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)"
        );
        $stmt->execute([':minutes' => $minutes]);
        $escalated = $stmt->fetchAll();
        foreach ($escalated as $alert) {
            $this->auditLog->logAction(null, 'alert_escalated', "Alert #{$alert['id']} ({$alert['title']}) escalated");
        }
        return ['escalated_count' => count($escalated), 'alerts' => $escalated];
    }

    public function retryDeadLetter(): array
    {
        $db = \App\Config\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM dead_letter_queue WHERE retry_count < 3 ORDER BY created_at ASC LIMIT 10");
        $stmt->execute();
        $letters = $stmt->fetchAll();
        $retried = 0;

        foreach ($letters as $letter) {
            $payload = json_decode($letter['payload'], true);
            if (!$payload) continue;

            $exchange = (new \App\Config\RabbitMQConfig())->getEmergencyExchange();
            $config = new \App\Config\RabbitMQConfig();
            $url = sprintf('http://%s:%s@%s:15672/api/exchanges/%%2f/%s/publish', $config->getUser(), $config->getPassword(), $config->getHost(), $exchange);
            $body = json_encode([
                'properties' => ['content_type' => 'application/json', 'delivery_mode' => 2],
                'routing_key' => $payload['type'] ?? 'message',
                'payload' => $letter['payload'],
                'payload_encoding' => 'string',
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 10]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 200) {
                $deleteStmt = $db->prepare("DELETE FROM dead_letter_queue WHERE id = :id");
                $deleteStmt->execute([':id' => $letter['id']]);
                $retried++;
            } else {
                $updateStmt = $db->prepare("UPDATE dead_letter_queue SET retry_count = retry_count + 1, error_message = :error WHERE id = :id");
                $updateStmt->execute([':id' => $letter['id'], ':error' => "HTTP $httpCode"]);
            }
        }

        return ['retried_count' => $retried];
    }
}
