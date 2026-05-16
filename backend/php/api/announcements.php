<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\AuditLog;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$db = Database::getInstance()->getConnection();
$auditLog = new AuditLog();
$method = $_SERVER['REQUEST_METHOD'];
$announcementId = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($method) {
    case 'GET':
        $user = AuthMiddleware::requireAuth();
        $targetRole = $_GET['target_role'] ?? '';

        $sql = "SELECT * FROM announcements WHERE (target_role = :role1 OR target_role = 'all') AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':role1' => $targetRole]);
        $announcements = $stmt->fetchAll();

        echo json_encode(['announcements' => $announcements]);
        break;

    case 'POST':
        AuthMiddleware::requireRole('admin');
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['title']) || empty($input['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'title and content are required']);
            exit;
        }

        $data = [
            'title' => $input['title'],
            'content' => $input['content'],
            'target_role' => $input['target_role'] ?? 'all',
            'created_by' => $_SESSION['user_id'],
        ];

        if (!empty($input['expires_at'])) {
            $data['expires_at'] = $input['expires_at'];
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $stmt = $db->prepare("INSERT INTO announcements ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($data);
        $id = (int)$db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM announcements WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $announcement = $stmt->fetch();

        $auditLog->logAction($_SESSION['user_id'], 'announcement_created', "Created announcement: {$announcement['title']}");

        http_response_code(201);
        echo json_encode(['announcement' => $announcement]);
        break;

    case 'DELETE':
        AuthMiddleware::requireRole('admin');
        if (!$announcementId) {
            http_response_code(400);
            echo json_encode(['error' => 'Announcement ID required']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM announcements WHERE id = :id");
        $stmt->execute([':id' => $announcementId]);
        $announcement = $stmt->fetch();

        if (!$announcement) {
            http_response_code(404);
            echo json_encode(['error' => 'Announcement not found']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM announcements WHERE id = :id");
        $stmt->execute([':id' => $announcementId]);

        $auditLog->logAction($_SESSION['user_id'], 'announcement_deleted', "Deleted announcement: {$announcement['title']}");

        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
