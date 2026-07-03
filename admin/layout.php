<?php
/**
 * layout.php — Sidebar + Navbar admin (fichier unique)
 *   ...
 *   <aside class="admin-sidebar"> <?php renderSidebar(); ?> </aside>
 *   <div class="admin-main">     <?php renderNavbar('Articles', 'bi-newspaper');  ?> ...content... </div>
 *
 * Variables lues depuis le contexte global :
 *   $pdo         (PDO)    — connexion base de données
 *   $unreadCount (int)    — pré-calculé par la page parente, sinon calculé ici
 */

// ── Calcul du badge une seule fois ──────────────────────────────────────────
if (!isset($unreadCount)) {
    $unreadCount = 0;
    if (isset($pdo)) {
        $unreadCount = (int)(fetchOne($pdo,
            "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
        )['count'] ?? 0);
    }
}

// ── Fichier courant pour le lien actif ──────────────────────────────────────
$_layoutCurrentFile = basename($_SERVER['PHP_SELF']);

// ── Helper interne : génère un <a> de nav ───────────────────────────────────
function _sidebarLink(string $href, string $icon, string $label, ?int $badge = null): void
{
    global $_layoutCurrentFile;
    $active    = (basename($href) === $_layoutCurrentFile) ? ' active' : '';
    $badgeHtml = $badge > 0
        ? '<span class="sb-badge">' . ($badge > 99 ? '99+' : $badge) . '</span>'
        : '';
    printf(
        '<a href="%s" class="sb-link%s"><i class="bi %s"></i>%s%s</a>',
        htmlspecialchars($href), $active,
        htmlspecialchars($icon),
        htmlspecialchars($label),
        $badgeHtml
    );
}

// ════════════════════════════════════════════════════════════════════════════
//  renderSidebar()  —  à appeler à l'intérieur de <aside class="admin-sidebar">
// ════════════════════════════════════════════════════════════════════════════
function renderSidebar(): void
{
    global $unreadCount;
    $userName = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
    $userRole = htmlspecialchars($_SESSION['user_role'] ?? 'Administrateur');
    $initial  = mb_strtoupper(mb_substr($_SESSION['user_name'] ?? 'A', 0, 1));
    ?>

    <!-- ── Brand ── -->
    <a href="<?= BASE_URL ?>/app/dashboard" class="sb-brand">
        <span class="sb-brand-icon">A</span>
        <span>AFRINEX <span class="sb-brand-muted">Admin</span></span>
    </a>

    <!-- ── Navigation ── -->
    <nav class="sb-nav">
        <?php _sidebarLink(BASE_URL .'/app/dashboard',                       'bi-speedometer2',        'Dashboard')          ?>
        <?php _sidebarLink(BASE_URL . '/app/articles',            'bi-file-text',           'Articles')           ?>
        <?php _sidebarLink(BASE_URL . '/app/services',            'bi-postcard',            'Services')           ?>
        <?php _sidebarLink(BASE_URL . '/app/testimonials',        'bi-chat-quote',          'Témoignages')        ?>
        <?php _sidebarLink(BASE_URL . '/app/cases',               'bi-briefcase',           'Études de cas')      ?>
        <?php _sidebarLink(BASE_URL . '/app/contacts',            'bi-envelope',            'Messages', $unreadCount) ?>
        <?php _sidebarLink(BASE_URL . '/app/partenaire',         'bi-briefcase-fill',      'Partenaires') ?>
        <?php _sidebarLink(BASE_URL . '/app/contents',               'bi-layout-text-sidebar', 'Pages')              ?>
        <?php _sidebarLink(BASE_URL . '/app/users',               'bi-people',              'Utilisateurs')             ?>
        <?php _sidebarLink(BASE_URL . '/app/settings',            'bi-gear',                'Paramètres')         ?>

        <hr class="sb-divider">

        <a href="<?= BASE_URL ?>/page/accueil" class="sb-link" target="_blank">
            <i class="bi bi-box-arrow-up-right"></i>Voir le site
        </a>
        <a href="<?= BASE_URL ?>/app/logout" class="sb-link sb-link-danger">
            <i class="bi bi-box-arrow-left"></i>Déconnexion
        </a>
    </nav>

    <!-- ── Pied de sidebar ── -->
    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-user-avatar"><?= $initial ?></div>
            <div>
                <div class="sb-user-name"><?= $userName ?></div>
                <div class="sb-user-role"><?= $userRole ?></div>
            </div>
        </div>
    </div>

    <?php
}

// ════════════════════════════════════════════════════════════════════════════
//  renderNavbar(string $title, string $icon = '')  
//  —  à appeler en tête de <div class="admin-main">
//  Paramètres :
//    $title  (string) — titre de la page affiché dans la navbar, ex: 'Articles'
//    $icon   (string) — classe icône Bootstrap, ex: 'bi-newspaper' (optionnel)
// ════════════════════════════════════════════════════════════════════════════
function renderNavbar(string $title, string $icon = ''): void
{
    global $unreadCount;
    $userName = htmlspecialchars($_SESSION['user_name'] ?? 'Admin');
    ?>

    <nav class="admin-navbar">
        <button class="navbar-toggle" onclick="toggleSidebar()">
            <i class="bi bi-list"></i>
        </button>
        <a href="<?= BASE_URL ?>/app/dashboard" class="navbar-brand">
            <?php if ($icon): ?><i class="bi <?= htmlspecialchars($icon) ?> me-1"></i><?php endif; ?>
            <?= htmlspecialchars($title) ?>
        </a>
        <div class="navbar-actions">
            <!-- CORRECTION: Condition if + href complet + style visible + cap 99+ -->
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
            <span class="text-muted small"><?= $userName ?></span>
            <a href="<?= BASE_URL ?>/app/logout" class="text-muted text-decoration-none" title="Déconnexion">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </nav>

    <?php
}

// ════════════════════════════════════════════════════════════════════════════
//  CSS — toutes les règles sidebar + navbar dans un seul <style>
//  (injecté une seule fois grâce au flag $_layoutStylesPrinted)
// ════════════════════════════════════════════════════════════════════════════
if (empty($GLOBALS['_layoutStylesPrinted'])) {
    $GLOBALS['_layoutStylesPrinted'] = true;
    ?>
<style>
/* ═══════════════════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════════════════ */

/* Brand */
.sb-brand {
    display: flex; align-items: center; gap: .5rem;
    font-weight: 800; font-size: 1.2rem;
    color: #fff; text-decoration: none;
    margin-bottom: 2rem; letter-spacing: .03em;
}
.sb-brand-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 8px;
    background: var(--gold, #d4a017);
    color: #fff; font-size: 1rem; font-weight: 900; flex-shrink: 0;
}
.sb-brand-muted { opacity: .45; font-weight: 400; }

/* Liens nav */
.sb-nav { display: flex; flex-direction: column; }
.sb-link {
    display: flex; align-items: center;
    color: rgba(255,255,255,.65);
    padding: .62rem .9rem;
    border-radius: 8px;
    margin-bottom: .18rem;
    font-size: .875rem;
    text-decoration: none;
    transition: background .15s, color .15s;
    position: relative;
}
.sb-link i { font-size: 1rem; margin-right: .7rem; flex-shrink: 0; }
.sb-link:hover       { color: #fff; background: rgba(255,255,255,.1); }
.sb-link.active      { color: #fff; background: rgba(255,255,255,.14); font-weight: 600; }
.sb-link.active::before {
    content: ''; position: absolute;
    left: 0; top: 20%; bottom: 20%;
    width: 3px; border-radius: 0 4px 4px 0;
    background: var(--gold, #d4a017);
}
.sb-link-danger       { color: #f87171 !important; }
.sb-link-danger:hover { background: rgba(239,68,68,.12) !important; }

/* Badge messages non lus (sidebar) */
.sb-badge {
    margin-left: auto;
    background: #ef4444; color: #fff;
    font-size: .68rem; font-weight: 700;
    min-width: 18px; height: 18px; border-radius: 9px;
    display: inline-flex; align-items: center; justify-content: center;
    padding: 0 4px; line-height: 1;
}

/* Séparateur */
.sb-divider { border-color: rgba(255,255,255,.12); margin: .65rem 0; }

/* Pied de sidebar */
.sb-footer {
    margin-top: auto; padding-top: .9rem;
    border-top: 1px solid rgba(255,255,255,.1);
}
.sb-user         { display: flex; align-items: center; gap: .6rem; }
.sb-user-avatar  {
    width: 32px; height: 32px; border-radius: 50%;
    background: var(--gold, #d4a017);
    color: #fff; font-weight: 700; font-size: .82rem;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sb-user-name { font-size: .82rem; font-weight: 600; color: #fff; }
.sb-user-role { font-size: .72rem; color: rgba(255,255,255,.45); }

/* ═══════════════════════════════════════════════════════
   NAVBAR (topnav)
═══════════════════════════════════════════════════════ */
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
    color: #1A253A;
    cursor: pointer;
    padding: 0 .5rem;
}
@media (min-width: 769px) {
    .navbar-toggle { display: none; }
}
.navbar-brand {
    font-weight: 700;
    font-size: 1.1rem;
    color: #1A253A;
    text-decoration: none;
}
.navbar-actions {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 1rem;
}

/* ═══════════════════════════════════════════════════════
   BADGE MESSAGES NAVBAR — CORRIGÉ
═══════════════════════════════════════════════════════ */
.navbar-msg-link {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #1A253A;
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

/* CORRECTION: fond rouge vif + texte blanc = visible sur navbar blanche */
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

/* Animation pulse quand il y a des messages non lus */
@keyframes navbarBadgePulse {
    0%   { transform: scale(1); }
    50%  { transform: scale(1.15); }
    100% { transform: scale(1); }
}
.navbar-badge-pulse {
    animation: navbarBadgePulse 2s ease-in-out infinite;
}

/* Responsive */
@media (max-width: 480px) {
    .navbar-brand { font-size: 1rem; }
    .navbar-actions .small { display: none; }
}
</style>
    <?php
}