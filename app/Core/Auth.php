<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public static function user(): ?array
    {
        static $loaded = false;
        static $user = null;
        if ($loaded) {
            return $user;
        }
        $loaded = true;
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $statement = \db()->pdo()->prepare('SELECT id, login, email, role, timezone, theme FROM users WHERE id = ? AND deleted_at IS NULL');
        $statement->execute([$id]);
        $row = $statement->fetch();
        $user = $row ?: null;
        return $user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function requireUser(bool $json = false): array
    {
        $user = self::user();
        if ($user) {
            return $user;
        }
        if ($json) {
            \json_response(['error' => 'Требуется вход.'], 401);
        }
        \redirect('/login');
    }

    public static function attempt(string $login, string $password, string $ip): bool
    {
        $pdo = \db()->pdo();
        $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
        $cleanup->execute([gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $normalizedLogin = mb_strtolower(trim($login));
        $ip = mb_substr(trim($ip), 0, 45);
        $key = hash('sha256', $normalizedLogin . '|' . $ip);
        $limit = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE successful = 0 AND attempted_at > ? AND (attempt_key = ? OR ip_address = ?)');
        $limit->execute([gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60), $key, $ip]);
        if ((int) $limit->fetchColumn() >= self::MAX_ATTEMPTS) {
            usleep(500000);
            return false;
        }

        $statement = $pdo->prepare('SELECT id, password_hash FROM users WHERE (login = ? OR email = ?) AND deleted_at IS NULL LIMIT 1');
        $statement->execute([$normalizedLogin, $normalizedLogin]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        $hash = $user['password_hash'] ?? '$2y$12$Q9qH5YkgFZp2nV8j9L4H9OU5rVZcXyDqaRbBDyZqH9g2jY9fM4VfS';
        $ok = password_verify($password, $hash) && $user !== false;

        $record = $pdo->prepare('INSERT INTO login_attempts (attempt_key, ip_address, successful, attempted_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
        $record->execute([$key, $ip, $ok ? 1 : 0]);
        if (!$ok) {
            usleep(250000);
            return false;
        }

        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
        $_SESSION['user_id'] = (int) $user['id'];
        return true;
    }

    public static function register(string $login, string $email, string $password): int
    {
        $id = \db()->transaction(static function (PDO $pdo) use ($login, $email, $password): int {
            $statement = $pdo->prepare('INSERT INTO users (login, email, password_hash, role, timezone, theme, created_at, updated_at) VALUES (?, ?, ?, \'user\', ?, \'system\', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $statement->execute([
                mb_strtolower(trim($login)),
                mb_strtolower(trim($email)),
                password_hash($password, PASSWORD_DEFAULT),
                \env('APP_TIMEZONE', 'Europe/Moscow'),
            ]);
            $userId = (int) $pdo->lastInsertId();
            $schedule = $pdo->prepare('INSERT INTO schedules (user_id,weekday,workout_type,label,active,version,created_at,updated_at) VALUES (?,?,?,?,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
            foreach ([[1,'strength','Зал'],[3,'strength','Зал'],[4,'swimming','Бассейн']] as $row) $schedule->execute([$userId,...$row]);
            return $userId;
        });
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
        $_SESSION['user_id'] = $id;
        return $id;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
