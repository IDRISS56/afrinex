<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'contacts');

// Seuls les rôles admin et editor peuvent gérer ce contenu
if (!isEditor()) {
    header('Location: dashboard');
    exit;
}

// ═══════════════════════════════════════════════════════════
// HELPER : envoyer du JSON propre
// ═══════════════════════════════════════════════════════════
function jsonResponse(array $data): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$db = Database::getInstance();

// ═══════════════════════════════════════════════════════════
// AJAX : Récupérer un contact  →  GET (compatible avec l'existant)
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_contact' && isset($_GET['id'])) {
    try {
        $id = (int)$_GET['id'];
        if ($id <= 0) throw new Exception('ID invalide');
        $contact = $db->fetchOne("SELECT * FROM contacts WHERE id = ?", [$id]);
        if ($contact) {
            $contact['date_formatted'] = !empty($contact['date']) ? date('d/m/Y H:i', strtotime($contact['date'])) : '';
            jsonResponse(['success' => true, 'data' => $contact]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Contact introuvable']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// AJAX : Marquer comme lu  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read']) && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    if ($id > 0) {
        $db->query("UPDATE contacts SET is_read = 1 WHERE id = ?", [$id]);
    }
    jsonResponse(['success' => true]);
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_contact']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $db->delete('contacts', 'id = ?', [$id]);
    }
    $_SESSION['flash_success'] = 'Contact supprimé avec succès';
    header('Location: ' . BASE_ROUTE);
    exit;
}

// ═══════════════════════════════════════════════════════════
// PAGINATION, FILTRES, RECHERCHE  →  POST (priorité) ou GET
// ═══════════════════════════════════════════════════════════
$page = max(1, intval($_POST['page'] ?? $_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$filterType = trim($_POST['type'] ?? $_GET['type'] ?? '');
$filterRead = isset($_POST['is_read']) && $_POST['is_read'] !== '' ? $_POST['is_read'] : ($_GET['is_read'] ?? '');

$where = [];
$params = [];

if ($search) {
    $where[] = "(name LIKE ? OR firstname LIKE ? OR email LIKE ? OR company LIKE ? OR message LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filterType) {
    $where[] = "type = ?";
    $params[] = $filterType;
}

if ($filterRead !== '') {
    $where[] = "is_read = ?";
    $params[] = (int)$filterRead;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Compte total
$countSql = "SELECT COUNT(*) as total FROM contacts $whereClause";
$total = (int)($db->fetchOne($countSql, $params)['total'] ?? 0);
$totalPages = ceil($total / $perPage);

// Récupération des contacts
$sql = "SELECT * FROM contacts $whereClause ORDER BY date DESC LIMIT $perPage OFFSET $offset";
$contacts = $db->fetchAll($sql, $params);

// Flash messages
$success = false;
$successMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $success = true;
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ═══════════════════════════════════════════════════════════
// FONCTIONS UTILITAIRES
// ═══════════════════════════════════════════════════════════
if (!function_exists('formatDate')) {
    function formatDate($date): string {
        return date('d/m/Y H:i', strtotime($date));
    }
}
if (!function_exists('truncate')) {
    function truncate(string $text, int $length = 100, string $suffix = '…'): string {
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . $suffix;
    }
}

// ═══════════════════════════════════════════════════════════
// LAYOUT : titre de page + assets spécifiques à Messages
// ═══════════════════════════════════════════════════════════
$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
)['count'] ?? 0);

$pageTitle = 'Messages';
$pageIcon  = 'bi-envelope';

// Styles propres à Messages (le socle sidebar/navbar/layout est déjà géré par layout.php)
$extraStyles = <<<CSS
        .table-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
        }
        .contact-row {
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e4e8;
            margin-bottom: 0.75rem;
            padding: 1rem;
            transition: all 0.2s ease;
        }
        .contact-row:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .contact-row.unread {
            border-left: 3px solid var(--gold);
        }
        .badge-gold { background: var(--gold); color: white; }
        .badge-read { background: #34d399; color: #064e3b; }
        .badge-unread { background: #fbbf24; color: #78350f; }
        #deleteConfirmModal .modal-content { border-radius: 16px; }
        #deleteConfirmModal .modal-body { padding: 2rem; }
        #deleteConfirmModal i.bi-exclamation-triangle-fill { animation: pulse-warning 2s infinite; }
        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        /* ─── FIX : Boutons restent dans le card ─── */
        .contact-row .btn-group-actions {
            display: flex;
            gap: 0.25rem;
            flex-wrap: nowrap;
            flex-shrink: 0;
        }
        .contact-row .btn-group-actions .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
            line-height: 1;
            white-space: nowrap;
        }
        .contact-row .contact-main {
            min-width: 0;
            flex: 1;
        }
        .contact-row .contact-meta {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            flex-shrink: 0;
        }
        @media (max-width: 576px) {
            .contact-row {
                padding: 0.75rem;
            }
            .contact-row .contact-meta {
                flex-direction: column;
                align-items: flex-end;
                gap: 0.5rem;
                min-width: auto;
            }
            .contact-row .btn-group-actions {
                gap: 0.2rem;
            }
            .contact-row .btn-group-actions .btn {
                padding: 0.2rem 0.4rem;
                font-size: 0.8rem;
            }
            .contact-row .contact-date {
                font-size: 0.7rem;
            }
        }
CSS;

// layout.php ouvre : <html><head>...</head><body><div class="admin-layout">
//   <aside>...sidebar...</aside><div class="admin-main"><nav>...navbar...</nav>
//   <div class="admin-content">   ← reste ouvert, on continue le contenu ici
require_once __DIR__ . '/layout.php';

if (ob_get_level() > 0) { ob_end_flush(); }
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Messages de contact</h2>
                <small class="text-muted"><?= $total ?> message(s) total</small>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Filtres → POST -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="POST" class="row g-3 align-items-end" id="filterForm">
<?= csrf_field() ?>
                        <input type="hidden" name="c" value="app">
                        <input type="hidden" name="a" value="contacts">
                        <div class="col-md-5">
                            <input type="text" name="search" class="form-control" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="type" class="form-select">
                                <option value="">Tous types</option>
                                <option value="message" <?= $filterType === 'message' ? 'selected' : '' ?>>Messages</option>
                                <option value="subscriber" <?= $filterType === 'subscriber' ? 'selected' : '' ?>>Abonnés</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="is_read" class="form-select">
                                <option value="">Tous</option>
                                <option value="0" <?= $filterRead === '0' ? 'selected' : '' ?>>Non lus</option>
                                <option value="1" <?= $filterRead === '1' ? 'selected' : '' ?>>Lus</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-outline-secondary w-100"><i class="bi bi-funnel"></i> Filtrer</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste des contacts -->
            <?php if (empty($contacts)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1"></i>
                <p class="mt-2">Aucun message trouvé</p>
            </div>
            <?php else: ?>
            <?php foreach ($contacts as $contact): ?>
            <div class="contact-row <?= empty($contact['is_read']) ? 'unread' : '' ?>" id="contact-<?= $contact['id'] ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <!-- Partie gauche : infos -->
                    <div class="contact-main min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <div class="fw-medium text-truncate" style="max-width: 100%;">
                                <?= htmlspecialchars(($contact['name'] ?? '') . ' ' . ($contact['firstname'] ?? '')) ?: 'Anonyme' ?>
                            </div>
                            <?php if (empty($contact['is_read'])): ?>
                            <span class="badge badge-unread" style="font-size:0.65rem">Nouveau</span>
                            <?php endif; ?>
                            <span class="badge bg-secondary" style="font-size:0.65rem"><?= htmlspecialchars($contact['type'] ?? 'message') ?></span>
                        </div>
                        <div class="small text-muted mb-1 text-truncate">
                            <i class="bi bi-envelope"></i> <?= htmlspecialchars($contact['email']) ?>
                            <?php if ($contact['company']): ?>
                            <span class="mx-1">|</span><i class="bi bi-building"></i> <?= htmlspecialchars($contact['company']) ?>
                            <?php endif; ?>
                            <?php if ($contact['study_type']): ?>
                            <span class="mx-1">|</span><i class="bi bi-tag"></i> <?= htmlspecialchars($contact['study_type']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($contact['message']): ?>
                        <div class="mt-2 p-2 bg-light rounded small text-secondary">
                            <?= nl2br(htmlspecialchars(truncate($contact['message'], 200))) ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Partie droite : date + boutons -->
                    <div class="contact-meta">
                        <small class="text-muted contact-date d-block text-end" style="white-space:nowrap;">
                            <?= date('d/m/Y H:i', strtotime($contact['date'])) ?>
                        </small>
                        <div class="btn-group-actions">
                            <?php if (empty($contact['is_read'])): ?>
                            <button type="button" class="btn btn-sm btn-outline-warning btn-mark-read" data-id="<?= $contact['id'] ?>" title="Marquer comme lu">
                                <i class="bi bi-envelope-open"></i>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-view" data-id="<?= $contact['id'] ?>" title="Voir">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $contact['id'] ?>" title="Supprimer">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Pagination → POST -->
            <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <small class="text-muted">Affichage <?= (($page - 1) * $perPage) + 1 ?> – <?= min($page * $perPage, $total) ?> sur <?= $total ?></small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                        <li class="page-item"><button type="button" class="page-link btn-page" data-page="<?= $page - 1 ?>">Précédent</button></li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>"><button type="button" class="page-link btn-page" data-page="<?= $i ?>"><?= $i ?></button></li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                        <li class="page-item"><button type="button" class="page-link btn-page" data-page="<?= $page + 1 ?>">Suivant</button></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div><!-- /admin-content -->
    </div><!-- /admin-main -->
</div><!-- /admin-layout -->

<!-- ===== MODAL : VOIR CONTACT ===== -->
<div class="modal fade" id="viewContactModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTitle">Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 id="viewName" class="mb-1">Nom</h5>
                        <div class="text-muted small">
                            <i class="bi bi-envelope"></i> <span id="viewEmail">Email</span>
                            <span class="mx-2">|</span>
                            <i class="bi bi-building"></i> <span id="viewCompany">Entreprise</span>
                        </div>
                    </div>
                    <span id="viewType" class="badge bg-secondary">Type</span>
                </div>
                <div class="mb-2">
                    <span id="viewStudyType" class="badge bg-info">Type d'étude</span>
                    <span id="viewDate" class="text-muted small ms-2">Date</span>
                </div>
                <hr>
                <div id="viewMessage" class="p-3 bg-light rounded"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL DE CONFIRMATION SUPPRESSION ===== -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xs">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="modal-title mb-2">Confirmer la suppression</h5>
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer ce message ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression POST -->
<form id="deleteForm" method="POST" action="contacts" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="contacts">
    <input type="hidden" name="delete_contact" value="1">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour la pagination POST -->
<form id="pageForm" method="POST" action="contacts" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="contacts">
    <input type="hidden" name="page" id="pageFormPage" value="">
    <input type="hidden" name="search" id="pageFormSearch" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="type" id="pageFormType" value="<?= htmlspecialchars($filterType) ?>">
    <input type="hidden" name="is_read" id="pageFormIsRead" value="<?= htmlspecialchars($filterRead) ?>">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

$(document).ready(function() {

    var viewModalInstance = null;
    var deleteModalInstance = null;

    // ===== VOIR CONTACT (AJAX GET) =====
    $(document).on('click', '.btn-view', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'contacts?action=get_contact&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var c = response.data;
                    var fullName = (c.name || '') + ' ' + (c.firstname || '');
                    $('#viewTitle').text(fullName.trim() || 'Message');
                    $('#viewName').text(fullName.trim() || 'Anonyme');
                    $('#viewEmail').text(c.email || '—');
                    $('#viewCompany').text(c.company || '—');
                    $('#viewType').text(c.type || 'message')
                        .removeClass('bg-secondary bg-info')
                        .addClass(c.type === 'subscriber' ? 'bg-info' : 'bg-secondary');
                    $('#viewStudyType').text(c.study_type || 'Non spécifié').toggle(!!c.study_type);
                    $('#viewDate').text(c.date_formatted || c.date);
                    $('#viewMessage').html(c.message ? nl2br(escapeHtml(c.message)) : '<span class="text-muted">Aucun message</span>');

                    if (!viewModalInstance) {
                        viewModalInstance = new bootstrap.Modal(document.getElementById('viewContactModal'));
                    }
                    viewModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Contact non trouvé'));
                }
            },
            error: function(xhr, status, error) {
                var raw = xhr.responseText ? xhr.responseText.substring(0, 500) : 'Pas de réponse';
                console.error('=== ERREUR AJAX VOIR ===');
                console.error('Status:', status);
                console.error('Error:', error);
                console.error('HTTP:', xhr.status);
                console.error('Réponse:', raw);
                alert('Erreur de chargement.\n\nStatus: ' + status + '\nHTTP: ' + xhr.status + '\n\n' + raw);
            }
        });
    });

    // ===== MARQUER COMME LU (AJAX POST) =====
    $(document).on('click', '.btn-mark-read', function() {
        var id = $(this).data('id');
        var $btn = $(this);
        var $row = $('#contact-' + id);

        $.ajax({
            url: 'contacts',
            type: 'POST',
            data: { mark_read: 1, id: id, csrf_token: '<?= csrf_token() ?>' },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $row.removeClass('unread');
                    $row.find('.badge-unread').remove();
                    $btn.fadeOut(200, function() { $(this).remove(); });
                    // Mettre à jour le compteur dans la navbar
                    var $badge = $('.navbar-actions .navbar-badge');
                    if ($badge.length) {
                        var count = parseInt($badge.text()) - 1;
                        if (count <= 0) {
                            $badge.remove();
                        } else {
                            $badge.text(count);
                        }
                    }
                }
            },
            error: function() {
                alert('Erreur lors du marquage comme lu.');
            }
        });
    });

    // ===== SUPPRESSION AVEC MODAL DE CONFIRMATION =====
    var deleteId = null;
    $(document).on('click', '.btn-delete', function() {
        deleteId = $(this).data('id');
        if (!deleteModalInstance) {
            deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        }
        deleteModalInstance.show();
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (deleteId) {
            $('#deleteFormId').val(deleteId);
            $('#deleteForm').submit();
        }
    });

    // ===== PAGINATION  →  POST (avec conservation des filtres) =====
    $(document).on('click', '.btn-page', function() {
        var page = $(this).data('page');
        $('#pageFormPage').val(page);
        $('#pageForm').submit();
    });

    // ===== UTILITAIRES =====
    function nl2br(str) {
        if (!str) return '';
        return str.replace(/\n/g, '<br>');
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>
</body>
</html>