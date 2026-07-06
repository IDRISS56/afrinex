<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

// ═══════════════════════════════════════════════════════════
// PROTECTION CSRF
// ═══════════════════════════════════════════════════════════

/**
 * Retourne le jeton CSRF de la session en cours (le génère s'il n'existe pas encore).
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Retourne un champ <input type="hidden"> prêt à insérer dans un <form method="POST">.
 * Usage : <?= csrf_field() ?>
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Vérifie le jeton CSRF pour toute requête POST. Bloque la requête (403) s'il est
 * absent ou invalide. Ne fait rien pour les requêtes GET (lecture seule).
 */
function csrf_verify(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($submitted) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        die('Requête invalide ou expirée (jeton de sécurité manquant). Merci de recharger la page et de réessayer.');
    }
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
    // Toute requête POST sur une page authentifiée doit porter un jeton CSRF valide.
    csrf_verify();
}

function isSuperAdmin(): bool {
    return isLoggedIn() && ($_SESSION['user_role'] ?? '') === 'isadmin';
}

function isAdmin(): bool {
    // Le superadmin a aussi tous les droits d'un admin.
    return isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['isadmin', 'admin']);
}

function isEditor(): bool {
    // Le superadmin et l'admin ont aussi tous les droits d'un editor.
    return isLoggedIn() && in_array($_SESSION['user_role'] ?? '', ['isadmin', 'admin', 'editor']);
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