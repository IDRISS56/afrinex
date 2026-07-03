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
 * 
 * Cause réelle : appeler requireAuth() APRÈS un include qui génère du HTML.
 * Solution structurelle : toujours appeler requireAuth() en TOUT DÉBUT de fichier,
 * avant tout include de layout ou affichage.
 */
function requireAuth(): void {
    if (!isLoggedIn()) {
        if (headers_sent()) {
            // Fallback JS si du HTML a déjà été envoyé
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

function login(string $email, string $password): bool {
    $db   = Database::getInstance();
    $user = $db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);

    if (!$user) return false;

    if (password_verify($password, $user['password'])) {
        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_email'] = $user['email'];
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