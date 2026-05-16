<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\EmergencyEvent;
use App\Models\AuditLog;
use App\Models\EventTimeline;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = AuthMiddleware::requireAuth();
$eventModel = new EmergencyEvent();
$auditLog = new AuditLog();
$timeline = new EventTimeline();
$method = $_SERVER['REQUEST_METHOD'];
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($method) {
    case 'GET':
        if ($eventId) {
            $event = $eventModel->findById($eventId);
            if (!$event) { http_response_code(404); echo json_encode(['error' => 'Event not found']); exit; }
            echo json_encode(['event' => $event]);
        } else {
            $status = $_GET['status'] ?? null;
            $events = $status ? $eventModel->getEventsByStatus($status) : $eventModel->getActiveEvents();
            echo json_encode(['events' => $events]);
        }
        break;

    case 'POST':
        AuthMiddleware::requireRole('operator');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['title'])) { http_response_code(400); echo json_encode(['error' => 'Title is required']); exit; }

        $eventId = $eventModel->create([
            'title' => $input['title'],
            'severity' => $input['severity'] ?? EmergencyEvent::SEVERITY_MEDIUM,
            'description' => $input['description'] ?? null,
            'location' => $input['location'] ?? null,
            'status' => EmergencyEvent::STATUS_ACTIVE,
            'created_by' => $user['id'],
        ]);

        $event = $eventModel->findById($eventId);
        $timeline->addEntry($eventId, 'Event Created', "Event '{$input['title']}' created with severity {$input['severity']}", $user['id']);
        $auditLog->logAction($user['id'], 'event_created', "Event '{$input['title']}' created with severity {$input['severity']}");
        http_response_code(201);
        echo json_encode(['event' => $event]);
        break;

    case 'PUT':
        AuthMiddleware::requireRole('operator');
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'Event ID required']); exit; }
        $input = json_decode(file_get_contents('php://input'), true);
        $updateData = [];
        if (isset($input['title'])) $updateData['title'] = $input['title'];
        if (isset($input['severity'])) $updateData['severity'] = $input['severity'];
        if (isset($input['description'])) $updateData['description'] = $input['description'];
        if (isset($input['location'])) $updateData['location'] = $input['location'];
        if (isset($input['status'])) $updateData['status'] = $input['status'];
        if (isset($input['status']) && $input['status'] === EmergencyEvent::STATUS_RESOLVED) {
            $updateData['resolved_by'] = $user['id'];
            $updateData['resolved_at'] = date('Y-m-d H:i:s');
        }
        $eventModel->update($eventId, $updateData);
        $event = $eventModel->findById($eventId);
        if (isset($input['status']) && $input['status'] === EmergencyEvent::STATUS_RESOLVED) {
            $timeline->addEntry($eventId, 'Event Resolved', "Event #{$eventId} resolved by {$user['display_name']}", $user['id']);
        }
        $auditLog->logAction($user['id'], 'event_updated', "Event $eventId updated");
        echo json_encode(['event' => $event]);
        break;

    case 'DELETE':
        AuthMiddleware::requireRole('admin');
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'Event ID required']); exit; }
        $eventModel->delete($eventId);
        $auditLog->logAction($user['id'], 'event_deleted', "Event $eventId deleted");
        echo json_encode(['success' => true]);
        break;
}
