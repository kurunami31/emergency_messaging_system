<?php

namespace App\Middleware;

class AuthMiddleware
{
    public static function requireAuth(): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
                $payload = self::validateToken($m[1]);
                if ($payload && isset($payload['user_id'])) {
                    $_SESSION['user_id'] = $payload['user_id'];
                    $_SESSION['user_role'] = $payload['role'] ?? '';
                    $userId = $payload['user_id'];
                }
            }

            if (!$userId) {
                if (self::isApiRequest()) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Authentication required']);
                    exit;
                }
                header('Location: /login.php');
                exit;
            }
        }

        $userModel = new \App\Models\User();
        $user = $userModel->findById($userId);

        if (!$user || !$user['is_active']) {
            session_destroy();
            if (self::isApiRequest()) {
                http_response_code(403);
                echo json_encode(['error' => 'Account is inactive']);
                exit;
            }
            header('Location: /login.php');
            exit;
        }

        return $user;
    }

    public static function requireRole(string $minimumRole): ?array
    {
        $user = self::requireAuth();

        $roleHierarchy = [
            'admin' => 5,
            'responder' => 4,
            'operator' => 3,
            'viewer' => 2,
            'victim' => 1,
        ];

        $userLevel = $roleHierarchy[$user['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$minimumRole] ?? 0;

        if ($userLevel < $requiredLevel) {
            if (self::isApiRequest()) {
                http_response_code(403);
                echo json_encode(['error' => 'Insufficient permissions']);
                exit;
            }
            header('HTTP/1.1 403 Forbidden');
            echo 'Insufficient permissions';
            exit;
        }

        return $user;
    }

    private static function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    }

    public static function generateToken(array $user): string
    {
        $payload = [
            'user_id' => $user['id'],
            'role' => $user['role'],
            'exp' => time() + 3600,
        ];
        return base64_encode(json_encode($payload));
    }

    public static function validateToken(string $token): ?array
    {
        $payload = json_decode(base64_decode($token), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }
}
