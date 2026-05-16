<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Config\GoogleAuthConfig;
use App\Models\User;
use App\Models\AuditLog;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = new GoogleAuthConfig();
$userModel = new User();
$auditLog = new AuditLog();

$action = $_GET['action'] ?? '';
$code = $_GET['code'] ?? '';

if (!$action && $code) {
    $action = 'callback';
}

switch ($action) {
    case 'login':
        $authUrl = $config->getAuthUrl();
        echo json_encode(['auth_url' => $authUrl]);
        break;

    case 'email_login':
        $input = json_decode(file_get_contents('php://input'), true);
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and password are required']);
            exit;
        }

        $user = $userModel->verifyPassword($email, $password);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid email or password']);
            exit;
        }

        $userModel->update($user['id'], ['last_login' => date('Y-m-d H:i:s')]);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];

        $auditLog->logAction($user['id'], 'email_login', "User {$user['display_name']} logged in via email");

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'phone' => $user['phone'],
                'emergency_contact_name' => $user['emergency_contact_name'],
                'emergency_contact_phone' => $user['emergency_contact_phone'],
                'avatar_url' => $user['avatar_url'],
                'role' => $user['role'],
            ],
            'token' => \App\Middleware\AuthMiddleware::generateToken($user),
        ]);
        break;

    case 'register':
        $input = json_decode(file_get_contents('php://input'), true);
        $displayName = trim($input['display_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? User::ROLE_VICTIM;

        if (!$displayName || !$email || !$password) {
            http_response_code(400);
            echo json_encode(['error' => 'Display name, email, and password are required']);
            exit;
        }

        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 6 characters']);
            exit;
        }

        $validRoles = [User::ROLE_RESPONDER, User::ROLE_OPERATOR, User::ROLE_VIEWER, User::ROLE_VICTIM];

        if ($role === User::ROLE_ADMIN) {
            $admins = $userModel->getUsersByRole(User::ROLE_ADMIN);
            if (count($admins) > 0) $role = User::ROLE_RESPONDER;
        }

        if (!in_array($role, $validRoles)) $role = User::ROLE_VICTIM;

        $existing = $userModel->findByEmail($email);
        if ($existing) {
            http_response_code(409);
            echo json_encode(['error' => 'An account with this email already exists']);
            exit;
        }

        $userId = $userModel->register($displayName, $email, $password, $role);
        $user = $userModel->findById($userId);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];

        $auditLog->logAction($user['id'], 'user_registered', "User {$user['display_name']} registered as {$role}");

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'phone' => $user['phone'],
                'emergency_contact_name' => $user['emergency_contact_name'],
                'emergency_contact_phone' => $user['emergency_contact_phone'],
                'avatar_url' => $user['avatar_url'],
                'role' => $user['role'],
            ],
            'token' => \App\Middleware\AuthMiddleware::generateToken($user),
        ]);
        break;

    case 'dev_login':
        $displayName = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!$displayName) {
            http_response_code(400);
            echo json_encode(['error' => 'Display name is required']);
            exit;
        }

        if (!$email) $email = strtolower(str_replace(' ', '.', $displayName)) . '@local.dev';

        $existing = $userModel->findByEmail($email);
        if ($existing) {
            $user = $existing;
            $userModel->update($user['id'], ['last_login' => date('Y-m-d H:i:s')]);
        } else {
            $userId = $userModel->create([
                'google_id' => 'dev_' . uniqid(),
                'email' => $email,
                'display_name' => $displayName,
                'role' => User::ROLE_ADMIN,
                'is_active' => 1,
                'last_login' => date('Y-m-d H:i:s'),
            ]);
            $user = $userModel->findById($userId);
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $auditLog->logAction($user['id'], 'dev_login', "Dev login: {$user['display_name']}");

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'phone' => $user['phone'],
                'emergency_contact_name' => $user['emergency_contact_name'],
                'emergency_contact_phone' => $user['emergency_contact_phone'],
                'role' => $user['role'],
            ],
            'token' => \App\Middleware\AuthMiddleware::generateToken($user),
        ]);
        break;

    case 'callback':
        $code = $_GET['code'] ?? '';
        if (!$code) {
            http_response_code(400);
            echo json_encode(['error' => 'No authorization code provided']);
            exit;
        }

        $tokenData = $config->exchangeCode($code);
        if (!$tokenData || !isset($tokenData['access_token'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Failed to exchange authorization code']);
            exit;
        }

        $googleUser = $config->getUserInfo($tokenData['access_token']);
        if (!$googleUser) {
            http_response_code(401);
            echo json_encode(['error' => 'Failed to fetch user info']);
            exit;
        }

        $user = $userModel->createOrUpdateFromGoogle($googleUser);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $auditLog->logAction($user['id'], 'user_login', "User {$user['display_name']} logged in via Google");

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $user['id'], 'email' => $user['email'],
                'display_name' => $user['display_name'], 'phone' => $user['phone'],
                'emergency_contact_name' => $user['emergency_contact_name'],
                'emergency_contact_phone' => $user['emergency_contact_phone'],
                'avatar_url' => $user['avatar_url'], 'role' => $user['role'],
            ],
            'token' => \App\Middleware\AuthMiddleware::generateToken($user),
        ]);
        break;

    case 'update_profile':
        $currentUser = \App\Middleware\AuthMiddleware::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        $updateData = [];

        if (isset($input['display_name'])) {
            $name = trim($input['display_name']);
            if ($name) $updateData['display_name'] = $name;
        }
        if (isset($input['email'])) {
            $email = trim($input['email']);
            if ($email && $email !== $currentUser['email']) {
                $existing = $userModel->findByEmail($email);
                if ($existing) { http_response_code(409); echo json_encode(['error' => 'Email already in use']); exit; }
                $updateData['email'] = $email;
            }
        }
        if (isset($input['phone'])) $updateData['phone'] = trim($input['phone']);
        if (isset($input['avatar_url'])) $updateData['avatar_url'] = trim($input['avatar_url']);
        if (isset($input['emergency_contact_name'])) $updateData['emergency_contact_name'] = trim($input['emergency_contact_name']);
        if (isset($input['emergency_contact_phone'])) $updateData['emergency_contact_phone'] = trim($input['emergency_contact_phone']);

        if (!empty($input['current_password']) && !empty($input['new_password'])) {
            if (empty($currentUser['password_hash']) || !password_verify($input['current_password'], $currentUser['password_hash'])) {
                http_response_code(400); echo json_encode(['error' => 'Current password is incorrect']); exit;
            }
            if (strlen($input['new_password']) < 6) { http_response_code(400); echo json_encode(['error' => 'New password must be at least 6 characters']); exit; }
            $updateData['password_hash'] = password_hash($input['new_password'], PASSWORD_BCRYPT);
        }

        if (empty($updateData)) { http_response_code(400); echo json_encode(['error' => 'No fields to update']); exit; }

        $userModel->update($currentUser['id'], $updateData);
        $updated = $userModel->findById($currentUser['id']);
        $auditLog->logAction($currentUser['id'], 'profile_updated', 'Profile updated');

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $updated['id'], 'email' => $updated['email'],
                'display_name' => $updated['display_name'], 'phone' => $updated['phone'],
                'emergency_contact_name' => $updated['emergency_contact_name'],
                'emergency_contact_phone' => $updated['emergency_contact_phone'],
                'avatar_url' => $updated['avatar_url'], 'role' => $updated['role'],
            ],
        ]);
        break;

    case 'upload_avatar':
        $currentUser = \App\Middleware\AuthMiddleware::requireAuth();

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error']);
            exit;
        }

        $file = $_FILES['avatar'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $allowed)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file type. Allowed: jpg, png, gif, webp']);
            exit;
        }

        $filename = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $ext;
        $uploadDir = __DIR__ . '/../../frontend/public/avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $dest = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file']);
            exit;
        }

        $avatarUrl = '/avatars/' . $filename;
        $userModel->update($currentUser['id'], ['avatar_url' => $avatarUrl]);

        echo json_encode([
            'success' => true,
            'avatar_url' => $avatarUrl,
        ]);
        break;

    case 'forgot_password':
        $input = json_decode(file_get_contents('php://input'), true);
        $email = trim($input['email'] ?? '');
        $user = $userModel->findByEmail($email);
        $response = ['success' => true, 'message' => 'If the email exists, a reset link has been sent'];
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $userModel->update($user['id'], [
                'reset_token' => $token,
                'reset_token_expires' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ]);
            $auditLog->logAction($user['id'], 'forgot_password', "Password reset requested for {$user['display_name']}");
            $response['reset_token'] = $token;
        }
        echo json_encode($response);
        break;

    case 'reset_password':
        $input = json_decode(file_get_contents('php://input'), true);
        $token = trim($input['token'] ?? '');
        $newPassword = $input['new_password'] ?? '';
        if (!$token || !$newPassword) {
            http_response_code(400);
            echo json_encode(['error' => 'Token and new_password are required']);
            exit;
        }
        if (strlen($newPassword) < 6) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 6 characters']);
            exit;
        }
        $user = $userModel->findByResetToken($token);
        if (!$user) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or expired reset token']);
            exit;
        }
        $userModel->update($user['id'], [
            'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT),
            'reset_token' => null,
            'reset_token_expires' => null,
        ]);
        $auditLog->logAction($user['id'], 'reset_password', "Password reset for {$user['display_name']}");
        echo json_encode(['success' => true]);
        break;

    case 'logout':
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) $auditLog->logAction($userId, 'user_logout', 'User logged out');
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'session':
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
                $payload = \App\Middleware\AuthMiddleware::validateToken($m[1]);
                if ($payload && isset($payload['user_id'])) {
                    $_SESSION['user_id'] = $payload['user_id'];
                    $_SESSION['user_role'] = $payload['role'] ?? '';
                    $userId = $payload['user_id'];
                }
            }
        }
        if (!$userId) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

        $user = $userModel->findById($userId);
        if (!$user) { session_destroy(); http_response_code(401); echo json_encode(['error' => 'User not found']); exit; }

        echo json_encode([
            'authenticated' => true,
            'user' => [
                'id' => $user['id'], 'email' => $user['email'],
                'display_name' => $user['display_name'], 'phone' => $user['phone'],
                'emergency_contact_name' => $user['emergency_contact_name'],
                'emergency_contact_phone' => $user['emergency_contact_phone'],
                'avatar_url' => $user['avatar_url'], 'role' => $user['role'],
            ],
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Unknown action']);
}
