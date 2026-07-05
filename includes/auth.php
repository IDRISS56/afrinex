<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

/**
 * Redirige vers index.php si non connecté.
 * Si les headers sont déjà envoyés (HTML déjà produit),
 * utilise un fallback JS pour éviter le warning.
 */
function requireAuth(): void {
    if (!isLoggedIn()) {
        if (headers_sent()) {
            echo '<script>window.location.replace("accueil");</script>';
        } else {
            header('Location: accueil');
        }
        exit;
    }
}

function isAdmin(): bool {
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'admin';
}

function isEditor(): bool {
    return isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['admin', 'editor']);
}

function login(string $email, string $password): bool {
    $db   = Database::getInstance();
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ? AND is_active = 1", [$email]);

    if (!$user) return false;

    if (password_verify($password, $user['password'])) {
        session_regenerate_id(true);

        $_SESSION['user_id']      = (int)$user['id'];
        $_SESSION['user_name']    = $user['username'];
        $_SESSION['user_role']    = $user['role'];
        $_SESSION['user_email']   = $user['email'];
        $_SESSION['user_avatar']  = $user['avatar'];
        return true;
    }
    return false;
}

function logout(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: accueil');
    exit;
}