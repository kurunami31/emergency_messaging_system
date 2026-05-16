<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Services\MessagingService;
use App\Services\XMLTransformer;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = AuthMiddleware::requireAuth();
$messagingService = new MessagingService();
$xmlTransformer = new XMLTransformer();
$method = $_SERVER['REQUEST_METHOD'];
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : null;
$format = $_GET['format'] ?? 'json';

switch ($method) {
    case 'GET':
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id parameter required']); exit; }
        $messages = $messagingService->getEventMessages($eventId);
        if ($format === 'xml') {
            header('Content-Type: application/xml');
            echo $xmlTransformer->exportMessagesToXml($messages);
        } else {
            echo json_encode(['messages' => $messages]);
        }
        break;

    case 'POST':
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id parameter required']); exit; }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['content'])) { http_response_code(400); echo json_encode(['error' => 'Content is required']); exit; }

        $priority = $input['priority'] ?? 'normal';
        if ($user['role'] === User::ROLE_VICTIM) $priority = 'normal';

        $message = $messagingService->sendMessage($eventId, $user['id'], $input['content'], $priority);
        http_response_code(201);
        echo json_encode(['message' => $message]);
        break;
}
