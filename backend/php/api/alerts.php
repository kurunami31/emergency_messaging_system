<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\NotificationService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = AuthMiddleware::requireAuth();
$notificationService = new NotificationService();
$method = $_SERVER['REQUEST_METHOD'];
$alertId = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($method) {
    case 'GET':
        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;
        $alerts = $eventId ? $notificationService->getAlertsByEvent($eventId) : $notificationService->getActiveAlerts();
        echo json_encode(['alerts' => $alerts]);
        break;

    case 'POST':
        AuthMiddleware::requireRole('responder');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['event_id']) || empty($input['title']) || empty($input['message'])) {
            http_response_code(400); echo json_encode(['error' => 'event_id, title, and message are required']); exit;
        }
        $alert = $notificationService->dispatchAlert((int)$input['event_id'], $input['type'] ?? 'test', $input['target_role'] ?? 'all', $input['title'], $input['message'], $user['id']);
        http_response_code(201);
        echo json_encode(['alert' => $alert]);
        break;

    case 'PUT':
        if (!$alertId) { http_response_code(400); echo json_encode(['error' => 'Alert ID required']); exit; }
        $notificationService->acknowledgeAlert($alertId, $user['id']);
        echo json_encode(['success' => true]);
        break;
}
