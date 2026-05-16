<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\User;
use App\Models\AuditLog;

class NotificationService
{
    private Alert $alertModel;
    private User $userModel;
    private AuditLog $auditLog;
    private MessagingService $messagingService;

    public function __construct()
    {
        $this->alertModel = new Alert();
        $this->userModel = new User();
        $this->auditLog = new AuditLog();
        $this->messagingService = new MessagingService();
    }

    public function dispatchAlert(int $eventId, string $type, string $targetRole, string $title, string $message, int $dispatchedBy): array
    {
        $alertId = $this->alertModel->createAlert($eventId, $type, $targetRole, $title, $message);
        $alert = $this->alertModel->findById($alertId);
        $this->messagingService->sendSystemMessage($eventId, "[ALERT] $title: $message", 'urgent');
        $this->auditLog->logAction($dispatchedBy, 'alert_dispatched', "Alert '$title' dispatched to role '$targetRole' for event $eventId");
        return $alert;
    }

    public function acknowledgeAlert(int $alertId, int $userId): bool
    {
        $result = $this->alertModel->acknowledge($alertId, $userId);
        if ($result) $this->auditLog->logAction($userId, 'alert_acknowledged', "Alert $alertId acknowledged");
        return $result;
    }

    public function getActiveAlerts(): array { return $this->alertModel->getActiveAlerts(); }
    public function getAlertsByEvent(int $eventId): array { return $this->alertModel->getAlertsByEvent($eventId); }
    public function getUnacknowledgedCount(): int { return $this->alertModel->getUnacknowledgedCount(); }
}
