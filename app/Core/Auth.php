<?php
declare(strict_types=1);
namespace App\Core;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        $stmt = Database::connection()->prepare('SELECT id, name, username, role, assigned_counter_id FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }

    public static function attempt(string $username, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE username = ? AND active = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        return true;
    }

    public static function require(array $roles = []): array
    {
        $user = self::user();
        if (!$user) { header('Location: /login'); exit; }
        if ($roles && !in_array($user['role'], $roles, true)) { http_response_code(403); exit('Akses ditolak'); }
        return $user;
    }
}

