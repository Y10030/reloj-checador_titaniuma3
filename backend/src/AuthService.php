<?php
declare(strict_types=1);

/**
 * Autenticación y manejo seguro de sesiones del panel administrativo.
 */
final class AuthService
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('TITANIUM_CHK_SESS');
        session_start();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = CryptoService::randomHex(32);
        }
    }

    public function login(string $usuario, string $password): ?array
    {
        self::startSession();
        $stmt = $this->pdo->prepare(
            'SELECT id_admin, usuario, password_hash, nombre, rol, activo FROM usuarios_admin WHERE usuario = ?'
        );
        $stmt->execute([trim($usuario)]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['activo'] !== 1) {
            return null;
        }
        if (!password_verify($password, (string)$row['password_hash'])) {
            return null;
        }

        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id_admin' => (int)$row['id_admin'],
            'usuario' => (string)$row['usuario'],
            'nombre' => (string)$row['nombre'],
            'rol' => (string)$row['rol'],
        ];
        $_SESSION['csrf_token'] = CryptoService::randomHex(32);
        return $_SESSION['admin'];
    }

    public function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function currentAdmin(): ?array
    {
        self::startSession();
        return isset($_SESSION['admin']) && is_array($_SESSION['admin']) ? $_SESSION['admin'] : null;
    }

    public function csrfToken(): string
    {
        self::startSession();
        return (string)$_SESSION['csrf_token'];
    }

    public function verifyCsrf(?string $token): bool
    {
        self::startSession();
        if ($token === null || $token === '' || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals((string)$_SESSION['csrf_token'], $token);
    }
}
