<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Services\SystemAutomationService;
use App\Services\XMLTransformer;
use App\Models\AuditLog;
use App\Models\EventTimeline;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = AuthMiddleware::requireAuth();
AuthMiddleware::requireRole('admin');
$automation = new SystemAutomationService();
$xmlTransformer = new XMLTransformer();
$auditLog = new AuditLog();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'health':
        echo json_encode($automation->runHealthCheck());
        break;
    case 'report':
        echo json_encode($automation->generateDailyReport());
        break;
    case 'archive':
        $days = (int)($_GET['days'] ?? 7);
        echo json_encode($automation->autoArchive($days));
        break;
    case 'escalate':
        $minutes = (int)($_GET['minutes'] ?? 30);
        echo json_encode($automation->escalateAlerts($minutes));
        break;
    case 'retry-dead-letter':
        echo json_encode($automation->retryDeadLetter());
        break;
    case 'logs':
        $limit = (int)($_GET['limit'] ?? 100);
        $search = $_GET['search'] ?? null;
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
        $action = $_GET['action'] ?? null;
        $from = $_GET['from'] ?? null;
        $to = $_GET['to'] ?? null;
        echo json_encode(['logs' => $auditLog->getLogs($limit, 0, $search, $userId, $action, $from, $to)]);
        break;
    case 'export-xml':
        $eventId = (int)($_GET['event_id'] ?? 0);
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); exit; }
        $messagingService = new \App\Services\MessagingService();
        $messages = $messagingService->getEventMessages($eventId);
        header('Content-Type: application/xml');
        echo $xmlTransformer->exportMessagesToXml($messages);
        break;
    case 'import-xml':
        $xml = file_get_contents('php://input');
        if (!$xml) { http_response_code(400); echo json_encode(['error' => 'XML data required']); exit; }
        echo json_encode($xmlTransformer->importMessagesFromXml($xml));
        break;
    case 'add-timeline':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }
        AuthMiddleware::requireRole('responder');
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['event_id']) || empty($input['action'])) {
            http_response_code(400); echo json_encode(['error' => 'event_id and action are required']); exit;
        }
        $timeline = new EventTimeline();
        $entryId = $timeline->addEntry((int)$input['event_id'], $input['action'], $input['description'] ?? null, $user['id']);
        $auditLog->logAction($user['id'], 'timeline_added', "Timeline entry added to event {$input['event_id']}: {$input['action']}");
        http_response_code(201);
        echo json_encode(['entry' => $timeline->getEntry($entryId)]);
        break;

    case 'timeline':
        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); exit; }
        $timeline = new EventTimeline();
        echo json_encode(['timeline' => $timeline->getTimeline($eventId)]);
        break;

    case 'export_csv':
        $type = $_GET['type'] ?? '';
        $db = \App\Config\Database::getInstance()->getConnection();
        switch ($type) {
            case 'users':
                $stmt = $db->query("SELECT id, email, display_name, role, is_active, created_at FROM users ORDER BY id");
                $rows = $stmt->fetchAll();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="users.csv"');
                $fh = fopen('php://output', 'w');
                fputcsv($fh, ['id', 'email', 'display_name', 'role', 'is_active', 'created_at']);
                foreach ($rows as $row) fputcsv($fh, $row);
                fclose($fh);
                exit;
            case 'events':
                $stmt = $db->query("SELECT e.id, e.title, e.description, e.status, e.severity, e.location, e.created_at, u.display_name AS created_by_name FROM events e LEFT JOIN users u ON e.created_by = u.id ORDER BY e.id");
                $rows = $stmt->fetchAll();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="events.csv"');
                $fh = fopen('php://output', 'w');
                fputcsv($fh, ['id', 'title', 'description', 'status', 'severity', 'location', 'created_at', 'created_by_name']);
                foreach ($rows as $row) fputcsv($fh, $row);
                fclose($fh);
                exit;
            case 'alerts':
                $stmt = $db->query("SELECT * FROM alerts ORDER BY id");
                $rows = $stmt->fetchAll();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="alerts.csv"');
                $fh = fopen('php://output', 'w');
                fputcsv($fh, array_keys($rows[0] ?? []));
                foreach ($rows as $row) fputcsv($fh, $row);
                fclose($fh);
                exit;
            case 'messages':
                $stmt = $db->query("SELECT * FROM messages ORDER BY id");
                $rows = $stmt->fetchAll();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="messages.csv"');
                $fh = fopen('php://output', 'w');
                fputcsv($fh, array_keys($rows[0] ?? []));
                foreach ($rows as $row) fputcsv($fh, $row);
                fclose($fh);
                exit;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid export type']);
                exit;
        }
        break;

    case 'resources':
        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); exit; }
        $db = \App\Config\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM event_resources WHERE event_id = :event_id ORDER BY id");
        $stmt->execute([':event_id' => $eventId]);
        echo json_encode(['resources' => $stmt->fetchAll()]);
        break;

    case 'add-resource':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }
        $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
        if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); exit; }
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $type = trim($input['type'] ?? '');
        $quantity = (int)($input['quantity'] ?? 0);
        if (!$name || !$type || $quantity <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'name, type, and quantity are required']);
            exit;
        }
        $validTypes = ['personnel', 'equipment', 'supplies'];
        if (!in_array($type, $validTypes)) {
            http_response_code(400);
            echo json_encode(['error' => 'type must be one of: personnel, equipment, supplies']);
            exit;
        }
        $db = \App\Config\Database::getInstance()->getConnection();
        $stmt = $db->prepare("INSERT INTO event_resources (event_id, name, type, quantity) VALUES (:event_id, :name, :type, :quantity)");
        $stmt->execute([':event_id' => $eventId, ':name' => $name, ':type' => $type, ':quantity' => $quantity]);
        $resourceId = $db->lastInsertId();
        $stmt = $db->prepare("SELECT * FROM event_resources WHERE id = :id");
        $stmt->execute([':id' => $resourceId]);
        $auditLog->logAction($user['id'], 'resource_added', "Resource '$name' added to event $eventId");
        http_response_code(201);
        echo json_encode(['resource' => $stmt->fetch()]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action']);
}
