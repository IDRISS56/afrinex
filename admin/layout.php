<?php
/**
 * layout.php — Sidebar + Navbar admin AFRINEX
 * 
 * À inclure via : require_once __DIR__ . '/../includes/layout.php';
 * 
 * Variables attendues depuis la page parente :
 *   $pageTitle    (string)  — titre de la page, ex: 'Tableau de bord'
 *   $pageIcon     (string)  — classe Bootstrap Icons, ex: 'bi-grid-1x2-fill'
 *   $unreadCount  (int)     — messages non lus (pré-calculé par la page parente)
 *   $extraStyles  (string)  — optionnel : CSS additionnel spécifique à la page (injecté avant </head>)
 *   $extraHead    (string)  — optionnel : balises <link>/<script> additionnelles (injectées avant </head>)
 *
 * Important : ce fichier ouvre <html><head>...<body><div class="admin-layout">...
 *              <div class="admin-content"> et NE LES FERME PAS.
 *              La page parente doit fermer, dans l'ordre :
 *              </div><!-- /admin-content --></div><!-- /admin-main --></div><!-- /admin-layout -->
 *              puis ses <script> et </body></html>.
 */

// ── Sécurité : démarrer la session si pas déjà fait ─────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Sécurité : layout.php est aussi atteignable directement via le routeur
//    (?c=app&a=layout) — on vérifie donc l'authentification ici aussi,
//    même si chaque page appelante le fait déjà normalement.
require_once __DIR__ . '/../includes/auth.php';
requireAuth();

// ── Variables par défaut ────────────────────────────────────────────────────
if (!isset($pageTitle))  $pageTitle  = 'Dashboard';
if (!isset($pageIcon))   $pageIcon   = 'bi-speedometer2';
if (!isset($unreadCount)) $unreadCount = 0;

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
$userRole = htmlspecialchars($_SESSION['user_role'] ?? 'Administrateur');
$initial  = mb_strtoupper(mb_substr($_SESSION['user_name'] ?? 'A', 0, 1));

$currentFile = basename($_SERVER['PHP_SELF']);

// ═══════════════════════════════════════════════════════════
// PROFIL UTILISATEUR : dropdown navbar (voir / modifier)
// ═══════════════════════════════════════════════════════════
if (!function_exists('uploadImage')) {
    function uploadImage(array $file, string $subdir = 'images'): string {
        $targetDir = __DIR__ . '/../uploads/images/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            throw new Exception('Format non autorisé. Formats acceptés : ' . implode(', ', $allowed));
        }
        $filename = uniqid() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
            throw new Exception('Erreur lors de l\'upload');
        }
        return $filename;
    }
}

$errorsProfil   = [];
$errorsPassword = [];

// ── Mise à jour des informations du profil ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profil']) && isset($db)) {
    $profilUsername = trim($_POST['profil_username'] ?? '');
    $profilEmail    = trim($_POST['profil_email'] ?? '');

    if (empty($profilUsername)) $errorsProfil[] = "Le nom d'utilisateur est obligatoire";
    if (empty($profilEmail)) $errorsProfil[] = "L'email est obligatoire";
    if (!filter_var($profilEmail, FILTER_VALIDATE_EMAIL)) $errorsProfil[] = "Email invalide";

    if (empty($errorsProfil)) {
        $existing = $db->fetchOne(
            "SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?",
            [$profilUsername, $profilEmail, $_SESSION['user_id'] ?? 0]
        );
        if ($existing) {
            $errorsProfil[] = "Ce nom d'utilisateur ou cet email est déjà utilisé";
        }
    }

    $profilAvatarNew = null;
    if (empty($errorsProfil) && !empty($_FILES['profil_avatar']['tmp_name'])) {
        try {
            $profilAvatarNew = uploadImage($_FILES['profil_avatar'], 'images');
        } catch (Throwable $e) {
            $errorsProfil[] = $e->getMessage();
        }
    }

    if (empty($errorsProfil)) {
        if ($profilAvatarNew) {
            $db->query(
                "UPDATE users SET username = ?, email = ?, avatar = ?, mise_ajour = NOW() WHERE id = ?",
                [$profilUsername, $profilEmail, $profilAvatarNew, $_SESSION['user_id'] ?? 0]
            );
        } else {
            $db->query(
                "UPDATE users SET username = ?, email = ?, mise_ajour = NOW() WHERE id = ?",
                [$profilUsername, $profilEmail, $_SESSION['user_id'] ?? 0]
            );
        }
        $_SESSION['user_name'] = $profilUsername;
        $_SESSION['flash_success'] = 'Profil mis à jour avec succès';
        header('Location: ' . (defined('BASE_ROUTE') ? BASE_ROUTE : $currentFile));
        exit;
    }
}

// ── Changement de mot de passe ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password']) && isset($db)) {
    $oldPassword     = $_POST['old_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $currentHash = $db->fetchOne("SELECT password FROM users WHERE id = ?", [$_SESSION['user_id'] ?? 0])['password'] ?? '';

    if (!password_verify($oldPassword, $currentHash)) {
        $errorsPassword[] = "L'ancien mot de passe est incorrect";
    }
    if (strlen($newPassword) < 6) {
        $errorsPassword[] = "Le nouveau mot de passe doit comporter au moins 6 caractères";
    }
    if ($newPassword !== $confirmPassword) {
        $errorsPassword[] = "Les mots de passe ne correspondent pas";
    }

    if (empty($errorsPassword)) {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->query("UPDATE users SET password = ?, mise_ajour = NOW() WHERE id = ?", [$newHash, $_SESSION['user_id'] ?? 0]);
        $_SESSION['flash_success'] = 'Mot de passe modifié avec succès';
        header('Location: ' . (defined('BASE_ROUTE') ? BASE_ROUTE : $currentFile));
        exit;
    }
}

// ── Infos fraîches du profil pour le dropdown + la modal ───────────────────
$profilUser   = isset($db) ? $db->fetchOne("SELECT id, username, email, role, avatar FROM users WHERE id = ?", [$_SESSION['user_id'] ?? 0]) : null;
$profilAvatar = $profilUser['avatar'] ?? null;

// ── Menu items ──────────────────────────────────────────────────────────────
$menuItems = [
    ['file' => 'dashboard',    'icon' => 'bi-speedometer2',      'label' => 'Dashboard',      'badge' => null],
    ['file' => 'articles',     'icon' => 'bi-file-text',         'label' => 'Articles',       'badge' => null],
    ['file' => 'services',     'icon' => 'bi-postcard',          'label' => 'Services',       'badge' => null],
    ['file' => 'testimonials', 'icon' => 'bi-chat-quote',        'label' => 'Témoignages',    'badge' => null],
    ['file' => 'cases',        'icon' => 'bi-briefcase',         'label' => 'Études de cas',  'badge' => null],
    ['file' => 'contacts',     'icon' => 'bi-envelope',          'label' => 'Messages',       'badge' => $unreadCount],
    ['file' => 'partenaire',   'icon' => 'bi-briefcase-fill',    'label' => 'Partenaires',    'badge' => null],
    ['file' => 'contents',     'icon' => 'bi-layout-text-sidebar','label' => 'Pages',         'badge' => null],
    ['file' => 'users',        'icon' => 'bi-people',            'label' => 'Utilisateurs',   'badge' => null],
    ['file' => 'settings',     'icon' => 'bi-gear',              'label' => 'Paramètres',     'badge' => null],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — AFRINEX Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* ═══════════════════════════════════════════════════════
           VARIABLES
        ════════════════════════════════════════════════════════ */
        :root {
            --sidebar-bg : #0d1117;
            --main-bg    : #f6f8fa;
            --gold       : #d4a017;
            --gold-hover : #b8921f;
            --navy       : #1A253A;
            --sidebar-w  : 240px;
        }

        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--main-bg);
            margin: 0;
        }

        /* ═══════════════════════════════════════════════════════
           LAYOUT
        ════════════════════════════════════════════════════════ */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        .admin-sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            background: var(--sidebar-bg);
            color: #fff;
            padding: 1.25rem 1rem;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }
        @media (max-width: 768px) {
            .admin-sidebar {
                position: fixed;
                top: 0;
                left: 0;
                transform: translateX(-100%);
                width: 260px;
                height: 100vh;
                box-shadow: 2px 0 20px rgba(0,0,0,0.3);
            }
            .admin-sidebar.open {
                transform: translateX(0);
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.4);
                z-index: 999;
            }
            .sidebar-overlay.active {
                display: block;
            }
        }

        /* ── Main ── */
        .admin-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .admin-content {
            padding: 1.5rem;
            flex: 1;
        }

        /* ═══════════════════════════════════════════════════════
           SIDEBAR BRAND
        ════════════════════════════════════════════════════════ */
        .sb-brand {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-weight: 800;
            font-size: 1.2rem;
            color: #fff;
            text-decoration: none;
            margin-bottom: 2rem;
            letter-spacing: .03em;
        }
        .sb-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: var(--gold);
            color: #fff;
            font-size: 1rem;
            font-weight: 900;
            flex-shrink: 0;
        }
        .sb-brand-muted { opacity: .45; font-weight: 400; }

        /* ═══════════════════════════════════════════════════════
           SIDEBAR NAV LINKS
        ════════════════════════════════════════════════════════ */
        .sb-nav {
            display: flex;
            flex-direction: column;
            gap: .18rem;
        }
        .sb-link {
            display: flex;
            align-items: center;
            color: rgba(255,255,255,.65);
            padding: .62rem .9rem;
            border-radius: 8px;
            font-size: .875rem;
            text-decoration: none;
            transition: background .15s, color .15s;
            position: relative;
        }
        .sb-link i {
            font-size: 1rem;
            margin-right: .7rem;
            flex-shrink: 0;
            width: 20px;
            text-align: center;
        }
        .sb-link:hover {
            color: #fff;
            background: rgba(255,255,255,.1);
        }
        .sb-link.active {
            color: #fff;
            background: rgba(255,255,255,.14);
            font-weight: 600;
        }
        .sb-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 20%;
            bottom: 20%;
            width: 3px;
            border-radius: 0 4px 4px 0;
            background: var(--gold);
        }
        .sb-link-danger {
            color: #f87171 !important;
        }
        .sb-link-danger:hover {
            background: rgba(239,68,68,.12) !important;
        }

        /* Badge sidebar */
        .sb-badge {
            margin-left: auto;
            background: #ef4444;
            color: #fff;
            font-size: .68rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            line-height: 1;
        }

        /* Divider */
        .sb-divider {
            border-color: rgba(255,255,255,.12);
            margin: .65rem 0;
        }

        /* Footer sidebar */
        .sb-footer {
            margin-top: auto;
            padding-top: .9rem;
            border-top: 1px solid rgba(255,255,255,.1);
        }
        .sb-user {
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .sb-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--gold);
            color: #fff;
            font-weight: 700;
            font-size: .82rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .sb-user-name {
            font-size: .82rem;
            font-weight: 600;
            color: #fff;
        }
        .sb-user-role {
            font-size: .72rem;
            color: rgba(255,255,255,.45);
        }

        /* ═══════════════════════════════════════════════════════
           NAVBAR (topnav)
        ════════════════════════════════════════════════════════ */
        .admin-navbar {
            background: #fff;
            border-bottom: 1px solid #e1e4e8;
            padding: .75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 1010;
        }
        .navbar-toggle {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--navy);
            cursor: pointer;
            padding: 0 .5rem;
        }
        @media (min-width: 769px) {
            .navbar-toggle { display: none; }
        }
        .navbar-brand {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--navy);
            text-decoration: none;
        }
        .navbar-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* Badge messages navbar */
        .navbar-msg-link {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--navy);
            text-decoration: none;
            padding: .4rem;
            border-radius: .5rem;
            transition: background .15s;
        }
        .navbar-msg-link:hover {
            background: rgba(0,0,0,.05);
            color: #004D80;
        }
        .navbar-msg-link i {
            font-size: 1.35rem;
            position: relative;
            z-index: 1;
        }
        .navbar-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            background: #dc2626;
            color: #fff;
            font-size: .65rem;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            line-height: 1;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,.2);
            z-index: 2;
            pointer-events: none;
        }
        @keyframes navbarBadgePulse {
            0%   { transform: scale(1); }
            50%  { transform: scale(1.15); }
            100% { transform: scale(1); }
        }
        .navbar-badge-pulse {
            animation: navbarBadgePulse 2s ease-in-out infinite;
        }

        /* Bouton doré réutilisé par la modal profil (et par la plupart des pages) */
        .btn-gold {
            background: var(--gold);
            color: #fff;
            border: none;
        }
        .btn-gold:hover {
            background: var(--gold-hover);
            color: #fff;
        }

        /* ═══════════════════════════════════════════════════════
           DROPDOWN PROFIL (navbar)
        ════════════════════════════════════════════════════════ */
        .navbar-user-toggle {
            display: flex;
            align-items: center;
            gap: .55rem;
            text-decoration: none;
            color: var(--navy);
            padding: .3rem .6rem .3rem .3rem;
            border-radius: 999px;
            transition: background .15s;
        }
        .navbar-user-toggle:hover {
            background: rgba(0,0,0,.05);
            color: var(--navy);
        }
        .navbar-user-toggle::after {
            margin-left: .15rem;
            vertical-align: .1em;
        }
        .navbar-user-avatar,
        .navbar-user-avatar-img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .navbar-user-avatar {
            background: var(--gold);
            color: #fff;
            font-weight: 700;
            font-size: .82rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .navbar-user-avatar-img { object-fit: cover; }
        .navbar-user-name {
            font-size: .85rem;
            font-weight: 600;
            color: var(--navy);
        }
        .navbar-user-menu {
            min-width: 240px;
            border: none;
            border-radius: 12px;
            padding: .5rem;
            box-shadow: 0 8px 24px rgba(0,0,0,.12);
        }
        .navbar-user-menu-header {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .5rem .65rem .75rem;
            border-bottom: 1px solid #f1f3f5;
            margin-bottom: .35rem;
        }
        .navbar-user-menu-header img,
        .navbar-user-avatar-lg {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .navbar-user-menu-header img { object-fit: cover; }
        .navbar-user-avatar-lg { font-size: 1rem; }
        .navbar-user-menu-name {
            font-weight: 600;
            font-size: .88rem;
            color: var(--navy);
        }
        .navbar-user-menu-role {
            font-size: .75rem;
            color: #9ca3af;
            text-transform: capitalize;
        }
        .navbar-user-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: .55rem;
            border-radius: 8px;
            padding: .5rem .65rem;
            font-size: .85rem;
        }
        .navbar-user-menu .dropdown-item i { font-size: 1rem; color: #6b7280; }
        .navbar-user-menu .dropdown-item:hover { background: #f6f8fa; }
        .navbar-user-menu .dropdown-item.text-danger i { color: inherit; }

        /* Modal profil */
        #profilModal .modal-content { border-radius: 16px; }
        #profilModal .nav-tabs .nav-link { color: #6b7280; font-weight: 500; border: none; }
        #profilModal .nav-tabs .nav-link.active {
            color: var(--navy);
            font-weight: 600;
            border: none;
            border-bottom: 2px solid var(--gold);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .navbar-brand { font-size: 1rem; }
            .navbar-actions .small { display: none; }
        }
    </style>
    <?php if (!empty($extraStyles)): ?>
    <style>
<?= $extraStyles ?>
    </style>
    <?php endif; ?>
    <?php if (!empty($extraHead)): ?>
    <?= $extraHead ?>
    <?php endif; ?>
</head>
<body>

<div class="admin-layout">

    <!-- SIDEBAR OVERLAY (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- ═══════════════ SIDEBAR ═══════════════ -->
    <aside class="admin-sidebar" id="adminSidebar">

        <!-- Brand -->
        <a href="<?= BASE_URL ?>/app/dashboard" class="sb-brand">
            <span class="sb-brand-icon">A</span>
            <span>AFRINEX <span class="sb-brand-muted">Admin</span></span>
        </a>

        <!-- Navigation -->
        <nav class="sb-nav">
            <?php foreach ($menuItems as $item):
                $isActive = ($currentFile === $item['file']) ? ' active' : '';
                $badgeHtml = ($item['badge'] !== null && $item['badge'] > 0)
                    ? '<span class="sb-badge">' . ($item['badge'] > 99 ? '99+' : $item['badge']) . '</span>'
                    : '';
            ?>
            <a href="<?= BASE_URL ?>/app/<?= htmlspecialchars($item['file']) ?>" class="sb-link<?= $isActive ?>">
                <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
                <?= htmlspecialchars($item['label']) ?>
                <?= $badgeHtml ?>
            </a>
            <?php endforeach; ?>

            <hr class="sb-divider">

            <a href="<?= BASE_URL ?>/page/accueil" class="sb-link" target="_blank">
                <i class="bi bi-box-arrow-up-right"></i>Voir le site
            </a>
            <a href="<?= BASE_URL ?>/app/logout" class="sb-link sb-link-danger">
                <i class="bi bi-box-arrow-left"></i>Déconnexion
            </a>
        </nav>

        <!-- Footer -->
        <div class="sb-footer">
            <div class="sb-user">
                <div class="sb-user-avatar"><?= $initial ?></div>
                <div>
                    <div class="sb-user-name"><?= $userName ?></div>
                    <div class="sb-user-role"><?= $userRole ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- ═══════════════ MAIN ═══════════════ -->
    <div class="admin-main">

        <!-- NAVBAR -->
        <nav class="admin-navbar">
            <button class="navbar-toggle" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
            <a href="<?= BASE_URL ?>/app/dashboard" class="navbar-brand">
                <?php if ($pageIcon): ?><i class="bi <?= htmlspecialchars($pageIcon) ?> me-1"></i><?php endif; ?>
                <?= htmlspecialchars($pageTitle) ?>
            </a>
            <div class="navbar-actions">
                <a href="<?= BASE_URL ?>/app/contacts" class="navbar-msg-link" title="Messages">
                    <i class="bi bi-envelope-fill fs-5"></i>
                    <?php if ($unreadCount > 0): ?>
                    <span class="navbar-badge<?= $unreadCount > 0 ? ' navbar-badge-pulse' : '' ?>">
                        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                        <span class="visually-hidden">messages non lus</span>
                    </span>
                    <?php endif; ?>
                </a>
                <span class="text-muted">|</span>

                <!-- ═══════════════ DROPDOWN PROFIL ═══════════════ -->
                <div class="dropdown">
                    <a href="#" class="navbar-user-toggle dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($profilAvatar)): ?>
                        <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($profilAvatar) ?>" class="navbar-user-avatar-img" alt="<?= $userName ?>">
                        <?php else: ?>
                        <span class="navbar-user-avatar"><?= $initial ?></span>
                        <?php endif; ?>
                        <span class="navbar-user-name d-none d-sm-inline"><?= $userName ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end navbar-user-menu">
                        <li class="navbar-user-menu-header">
                            <?php if (!empty($profilAvatar)): ?>
                            <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($profilAvatar) ?>" alt="<?= $userName ?>">
                            <?php else: ?>
                            <span class="navbar-user-avatar navbar-user-avatar-lg"><?= $initial ?></span>
                            <?php endif; ?>
                            <div>
                                <div class="navbar-user-menu-name"><?= $userName ?></div>
                                <div class="navbar-user-menu-role"><?= $userRole ?></div>
                            </div>
                        </li>
                        <li>
                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profilModal">
                                <i class="bi bi-person-circle"></i> Mon profil
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/app/logout">
                                <i class="bi bi-box-arrow-left"></i> Déconnexion
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- ═══════════════ MODAL : MON PROFIL ═══════════════ -->
        <div class="modal fade" id="profilModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-circle me-2"></i>Mon profil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">
                        <ul class="nav nav-tabs px-4 pt-2">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#profil-tab-infos">Informations</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#profil-tab-password">Mot de passe</a>
                            </li>
                        </ul>
                        <div class="tab-content p-4">
                            <!-- Onglet Informations -->
                            <div class="tab-pane fade show active" id="profil-tab-infos">
                                <?php if (!empty($errorsProfil)): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($errorsProfil as $err): ?>
                                    <div><?= htmlspecialchars($err) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <form method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>
                                    <div class="text-center mb-4">
                                        <?php if (!empty($profilAvatar)): ?>
                                        <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($profilAvatar) ?>" class="rounded-circle" style="width:80px;height:80px;object-fit:cover;">
                                        <?php else: ?>
                                        <div class="navbar-user-avatar mx-auto" style="width:80px;height:80px;font-size:1.8rem;"><?= $initial ?></div>
                                        <?php endif; ?>
                                        <div class="mt-2 fw-semibold"><?= $userName ?></div>
                                        <span class="badge mt-1" style="background:var(--navy);"><?= ucfirst($userRole) ?></span>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nom d'utilisateur *</label>
                                        <input type="text" name="profil_username" class="form-control" value="<?= htmlspecialchars($profilUser['username'] ?? ($_SESSION['user_name'] ?? '')) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Email *</label>
                                        <input type="email" name="profil_email" class="form-control" value="<?= htmlspecialchars($profilUser['email'] ?? '') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Photo de profil</label>
                                        <input type="file" name="profil_avatar" class="form-control" accept="image/*">
                                    </div>
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" name="save_profil" class="btn btn-gold">
                                            <i class="bi bi-save me-1"></i> Enregistrer
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <!-- Onglet Mot de passe -->
                            <div class="tab-pane fade" id="profil-tab-password">
                                <?php if (!empty($errorsPassword)): ?>
                                <div class="alert alert-danger">
                                    <?php foreach ($errorsPassword as $err): ?>
                                    <div><?= htmlspecialchars($err) ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <form method="POST">
<?= csrf_field() ?>
                                    <div class="mb-3">
                                        <label class="form-label">Ancien mot de passe *</label>
                                        <input type="password" name="old_password" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Nouveau mot de passe *</label>
                                        <input type="password" name="new_password" class="form-control" required minlength="6">
                                        <div class="form-text">Minimum 6 caractères</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Confirmer le mot de passe *</label>
                                        <input type="password" name="confirm_password" class="form-control" required>
                                    </div>
                                    <div class="d-flex justify-content-end">
                                        <button type="submit" name="change_password" class="btn btn-warning text-white">
                                            <i class="bi bi-shield-lock me-1"></i> Changer le mot de passe
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTENU (sera fermé dans la page parente) -->
        <div class="admin-content">