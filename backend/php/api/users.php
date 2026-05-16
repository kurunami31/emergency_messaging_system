<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Models\AuditLog;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$currentUser = AuthMiddleware::requireAuth();
$userModel = new User();
$auditLog = new AuditLog();
$method = $_SERVER['REQUEST_METHOD'];
$userId = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($method) {
    case 'GET':
        if ($userId) {
            $user = $userModel->findById($userId);
            if (!$user) { http_response_code(404); echo json_encode(['error' => 'User not found']); exit; }
            unset($user['google_id']);
            echo json_encode(['user' => $user]);
        } else {
            $users = $userModel->getActiveUsers();
            foreach ($users as &$u) { unset($u['google_id']); }
            echo json_encode(['users' => $users]);
        }
        break;

    case 'PUT':
        AuthMiddleware::requireRole('admin');
        if (!$userId) { http_response_code(400); echo json_encode(['error' => 'User ID required']); exit; }
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['role'])) {
            $userModel->updateRole($userId, $input['role']);
            $auditLog->logAction($currentUser['id'], 'user_role_changed', "User $userId role changed to {$input['role']}");
        }
        if (isset($input['is_active'])) {
            $input['is_active'] ? $userModel->activate($userId) : $userModel->deactivate($userId);
            $auditLog->logAction($currentUser['id'], 'user_status_changed', "User $userId active status set to {$input['is_active']}");
        }
        $user = $userModel->findById($userId);
        unset($user['google_id']);
        echo json_encode(['user' => $user]);
        break;
}
