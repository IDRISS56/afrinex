<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'settings');

// Seul le superadmin peut modifier les paramètres (même l'admin n'y a pas accès)
if (!isSuperAdmin()) {
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

// ═══════════════════════════════════════════════════════════
// LAYOUT : titre de page + assets spécifiques à Paramètres
// ═══════════════════════════════════════════════════════════
$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
)['count'] ?? 0);

$pageTitle = 'Paramètres';
$pageIcon  = 'bi-gear';

// Styles propres à Paramètres (le socle sidebar/navbar/layout est déjà géré par layout.php)
$extraStyles = <<<CSS
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
CSS;

// layout.php ouvre : <html><head>...</head><body><div class="admin-layout">
//   <aside>...sidebar...</aside><div class="admin-main"><nav>...navbar...</nav>
//   <div class="admin-content">   ← reste ouvert, on continue le contenu ici
require_once __DIR__ . '/layout.php';

if (ob_get_level() > 0) { ob_end_flush(); }
?>

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
<?= csrf_field() ?>
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

        </div><!-- /admin-content -->
    </div><!-- /admin-main -->
</div><!-- /admin-layout -->

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