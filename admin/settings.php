<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'settings');

// Seul un administrateur peut modifier les paramètres
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard');
    exit;
}

$db = Database::getInstance();

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE (Mise à jour des settings)
// ═══════════════════════════════════════════════════════════
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // Récupérer tous les settings existants
    $allSettings = $db->fetchAll("SELECT setting_key FROM settings");
    $keys = array_column($allSettings, 'setting_key');

    foreach ($keys as $key) {
        $value = trim($_POST[$key] ?? '');
        // Mettre à jour la valeur
        $db->query("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
    }

    $_SESSION['flash_success'] = 'Paramètres mis à jour avec succès';
    header('Location: ' . BASE_ROUTE);
    exit;
}

// ═══════════════════════════════════════════════════════════
// RÉCUPÉRATION DES SETTINGS
// ═══════════════════════════════════════════════════════════
$settings = $db->fetchAll("SELECT * FROM settings ORDER BY id ASC");

// Flash messages
$successMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $success = true;
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

require_once __DIR__ . '/layout.php';

$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
)['count'] ?? 0);

if (ob_get_level() > 0) { ob_end_flush(); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - AFRINEX Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --sidebar-bg: #0d1117;
            --main-bg: #f6f8fa;
            --gold: #d4a017;
        }
        body { font-family: 'Inter', sans-serif; background: var(--main-bg); margin: 0; }

        .admin-layout { display: flex; min-height: 100vh; }

        .admin-sidebar {
            width: 240px;
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
                top: 0; left: 0;
                transform: translateX(-100%);
                width: 260px;
                box-shadow: 2px 0 20px rgba(0,0,0,.3);
            }
            .admin-sidebar.open { transform: translateX(0); }
            .sidebar-overlay {
                display: none;
                position: fixed; inset: 0;
                background: rgba(0,0,0,.4);
                z-index: 999;
            }
            .sidebar-overlay.active { display: block; }
        }

        .admin-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .content-area { padding: 1.5rem; flex: 1; }
        .table-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
        }
        .btn-gold {
            background: var(--gold);
            color: white;
            border: none;
        }
        .btn-gold:hover { background: #b8921f; color: white; }
        .setting-key {
            font-family: monospace;
            background: #f1f3f5;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
<div class="admin-layout">

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <aside class="admin-sidebar" id="adminSidebar">
        <?php renderSidebar(); ?>
    </aside>

    <div class="admin-main">

        <!-- Navbar unifiée -->
        <?php renderNavbar('Paramètres', 'bi-gear'); ?>

        <div class="content-area">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Paramètres du site</h2>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($errors): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Formulaire -->
            <div class="table-card">
                <form method="POST" action="settings" id="settingsForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="settings">
                    <input type="hidden" name="save_settings" value="1">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:30%">Clé</th>
                                    <th style="width:30%">Label</th>
                                    <th style="width:40%">Valeur</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($settings)): ?>
                                <tr><td colspan="3" class="text-center py-4 text-muted">Aucun paramètre trouvé</td></tr>
                                <?php else: ?>
                                <?php foreach ($settings as $setting): ?>
                                <tr>
                                    <td>
                                        <span class="setting-key"><?= htmlspecialchars($setting['setting_key']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($setting['label']) ?></td>
                                    <td>
                                        <?php
                                        // Si c'est un champ booléen (maintenance_mode) -> switch
                                        if ($setting['setting_key'] === 'maintenance_mode') {
                                            $checked = (int)$setting['setting_value'] === 1;
                                        ?>
                                        <div class="form-check form-switch">
                                            <input type="hidden" name="<?= htmlspecialchars($setting['setting_key']) ?>" value="0">
                                            <input class="form-check-input" type="checkbox" name="<?= htmlspecialchars($setting['setting_key']) ?>" value="1" id="switch_<?= htmlspecialchars($setting['setting_key']) ?>" <?= $checked ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="switch_<?= htmlspecialchars($setting['setting_key']) ?>">
                                                <?= $checked ? 'Activé' : 'Désactivé' ?>
                                            </label>
                                        </div>
                                        <?php
                                        } else {
                                            // Champ texte normal
                                        ?>
                                        <input type="text" name="<?= htmlspecialchars($setting['setting_key']) ?>" class="form-control form-control-sm" value="<?= htmlspecialchars($setting['setting_value']) ?>" style="max-width:100%;">
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 border-top">
                        <button type="submit" class="btn btn-gold">
                            <i class="bi bi-save me-1"></i> Enregistrer les modifications
                        </button>
                    </div>
                </form>
            </div>

            <!-- Info supplémentaire -->
            <div class="alert alert-info mt-4">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Conseil :</strong> Modifier ces paramètres affecte le comportement global du site. Le mode maintenance désactive l'accès au site public.
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

$(document).ready(function() {
    // Gestion du switch pour afficher "Activé/Désactivé" dynamiquement
    $('.form-check-input[type="checkbox"]').on('change', function() {
        var label = $(this).closest('.form-check').find('.form-check-label');
        if ($(this).is(':checked')) {
            label.text('Activé');
        } else {
            label.text('Désactivé');
        }
    });
});
</script>
</body>
</html>