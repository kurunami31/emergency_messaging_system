<?php

namespace App\Models;

class User extends BaseModel
{
    protected string $table = 'users';

    public const ROLE_ADMIN = 'admin';
    public const ROLE_RESPONDER = 'responder';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_VICTIM = 'victim';

    public function findByGoogleId(string $googleId): ?array
    {
        return $this->findOneBy('google_id', $googleId);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->findOneBy('email', $email);
    }

    public function findByResetToken(string $token): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE reset_token = :token AND reset_token_expires > NOW() LIMIT 1");
        $stmt->execute([':token' => $token]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function createOrUpdateFromGoogle(array $googleUser): array
    {
        $existing = $this->findByGoogleId($googleUser['id']);
        $userData = [
            'google_id' => $googleUser['id'],
            'email' => $googleUser['email'],
            'display_name' => $googleUser['name'],
            'avatar_url' => $googleUser['picture'] ?? null,
            'last_login' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->update($existing['id'], $userData);
            return $this->findById($existing['id']);
        }

        $userData['role'] = self::ROLE_VIEWER;
        $id = $this->create($userData);
        return $this->findById($id);
    }

    public function register(string $displayName, string $email, string $password, string $role = self::ROLE_VICTIM): int
    {
        return $this->create([
            'google_id' => null,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'display_name' => $displayName,
            'role' => $role,
            'is_active' => 1,
            'last_login' => date('Y-m-d H:i:s'),
        ]);
    }

    public function verifyPassword(string $email, string $password): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE email = :email AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || empty($user['password_hash'])) return null;
        if (!password_verify($password, $user['password_hash'])) return null;
        return $user;
    }

    public function getActiveUsers(): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY display_name ASC"
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getUsersByRole(string $role): array
    {
        return $this->findBy('role', $role);
    }

    public function updateRole(int $userId, string $role): bool
    {
        $validRoles = [self::ROLE_ADMIN, self::ROLE_RESPONDER, self::ROLE_OPERATOR, self::ROLE_VIEWER, self::ROLE_VICTIM];
        if (!in_array($role, $validRoles)) return false;
        return $this->update($userId, ['role' => $role]);
    }

    public function deactivate(int $userId): bool { return $this->update($userId, ['is_active' => 0]); }
    public function activate(int $userId): bool { return $this->update($userId, ['is_active' => 1]); }

    public function hasRole(int $userId, string $requiredRole): bool
    {
        $user = $this->findById($userId);
        if (!$user || !$user['is_active']) return false;

        $roleHierarchy = [
            self::ROLE_ADMIN => 5, self::ROLE_RESPONDER => 4,
            self::ROLE_OPERATOR => 3, self::ROLE_VIEWER => 2, self::ROLE_VICTIM => 1,
        ];
        $userLevel = $roleHierarchy[$user['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$requiredRole] ?? 0;
        return $userLevel >= $requiredLevel;
    }
}
