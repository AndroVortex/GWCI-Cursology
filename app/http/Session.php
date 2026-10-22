<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Sesión PHP con cookie endurecida y token CSRF. Solo guarda el id del usuario:
 * su estado se vuelve a consultar en cada petición.
 */
final class Session
{
    private const USER_KEY = 'user_id';
    private const CSRF_KEY = 'csrf_token';

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('CURSOLOGYSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function userId(): ?string
    {
        $id = $_SESSION[self::USER_KEY] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Nuevo id de sesión (evita fijación de sesión) y nuevo token CSRF. */
    public function login(string $userId): void
    {
        session_regenerate_id(true);
        $_SESSION[self::USER_KEY] = $userId;
        unset($_SESSION[self::CSRF_KEY]);
    }

    /** Nuevo id de sesión conservando sus datos (p. ej. tras cambiar la contraseña). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Quita al usuario sin destruir la sesión (p. ej. si su cuenta se bloqueó). */
    public function clearUser(): void
    {
        unset($_SESSION[self::USER_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();
    }

    public function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }

        return $token;
    }

    public function isValidCsrf(?string $token): bool
    {
        $expected = $_SESSION[self::CSRF_KEY] ?? null;

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
