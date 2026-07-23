<?php
/**
 * ColdAisle - Local username/password authentication
 */
declare(strict_types=1);

class LocalAuth
{
    public static function authenticate(string $username, string $password): ?array
    {
        $user = Database::fetchOne(
            'SELECT u.*, r.name AS role_name, r.permissions AS role_permissions
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.username = ? AND u.is_active = 1 AND u.auth_source = \'local\'',
            [$username]
        );

        if (!$user || empty($user['password_hash'])) {
            // Timing-safe dummy verify
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012');
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ], 'user_id = :id', [':id' => (int)$user['user_id']]);
        }

        return $user;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
