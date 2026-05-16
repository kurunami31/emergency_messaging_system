<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Config\Database;
use App\Models\EmergencyEvent;
use App\Models\Message;
use App\Models\AuditLog;
use App\Models\User;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = AuthMiddleware::requireAuth();
$db = Database::getInstance()->getConnection();
$eventModel = new EmergencyEvent();
$messageModel = new Message();
$auditLog = new AuditLog();
$method = $_SERVER['REQUEST_METHOD'];

function ensureChatEvent(EmergencyEvent $model, PDO $db, int $createdBy): int
{
    $stmt = $db->prepare(
        "SELECT id FROM emergency_events WHERE title = 'Live Chat' AND status = 'active' LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];

    return $model->create([
        'title' => 'Live Chat',
        'severity' => EmergencyEvent::SEVERITY_LOW,
        'description' => 'General live chat for all users',
        'status' => EmergencyEvent::STATUS_ACTIVE,
        'created_by' => $createdBy,
    ]);
}

switch ($method) {
    case 'GET':
        $chatEventId = ensureChatEvent($eventModel, $db, (int)$user['id']);
        $since = isset($_GET['since']) ? trim($_GET['since']) : null;
        $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

        if ($since) {
            $stmt = $db->prepare(
                "SELECT m.*, u.display_name as sender_name
                 FROM messages m
                 LEFT JOIN users u ON m.sender_id = u.id
                 WHERE m.event_id = :event_id AND m.created_at > :since
                 ORDER BY m.created_at ASC"
            );
            $stmt->execute([':event_id' => $chatEventId, ':since' => $since]);
        } else {
            $stmt = $db->prepare(
                "SELECT m.*, u.display_name as sender_name
                 FROM messages m
                 LEFT JOIN users u ON m.sender_id = u.id
                 WHERE m.event_id = :event_id
                 ORDER BY m.created_at DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':event_id', $chatEventId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }

        $messages = $stmt->fetchAll();
        if (!$since) $messages = array_reverse($messages);

        echo json_encode(['event_id' => $chatEventId, 'messages' => $messages]);
        break;

    case 'POST':
        $chatEventId = ensureChatEvent($eventModel, $db, (int)$user['id']);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty(trim($input['content'] ?? ''))) {
            http_response_code(400); echo json_encode(['error' => 'Content is required']); exit;
        }

        $content = trim($input['content']);
        $messageId = $messageModel->createMessage($chatEventId, $user['id'], $content, Message::TYPE_TEXT, Message::PRIORITY_NORMAL);
        $message = $messageModel->findById($messageId);
        $message['sender_name'] = $user['display_name'];

        $auditLog->logAction($user['id'], 'chat_message', "Live chat message #$messageId: $content");

        http_response_code(201);
        echo json_encode(['message' => $message]);
        break;
}
