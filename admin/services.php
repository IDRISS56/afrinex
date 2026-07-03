<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'services');

if (!function_exists('formatDate')) {
    function formatDate($date): string {
        return date('d/m/Y', strtotime($date));
    }
}
if (!function_exists('truncate')) {
    function truncate(string $text, int $length = 100, string $suffix = '…'): string {
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . $suffix;
    }
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
// AJAX : Récupérer un service  →  GET
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_service' && isset($_GET['id'])) {
    try {
        $id = (int)$_GET['id'];
        if ($id <= 0) throw new Exception('ID invalide');
        $service = $db->fetchOne("SELECT * FROM content WHERE id = ? AND type = 'service'", [$id]);
        if ($service) {
            $service['status_label'] = ($service['status'] === 'published') ? 'Publié' : 'Brouillon';
            $service['status_badge'] = ($service['status'] === 'published') ? 'badge-published' : 'badge-draft';
            $service['date_formatted'] = !empty($service['date']) ? date('d/m/Y', strtotime($service['date'])) : date('d/m/Y');
            jsonResponse(['success' => true, 'data' => $service]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Service introuvable']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE (CREATE/UPDATE)  →  POST
// ═══════════════════════════════════════════════════════════
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_service'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;

    $title = trim($_POST['title'] ?? '');
    $slug = slugify($title);
    $content = trim($_POST['content'] ?? '');
    $icon = $_POST['icon'] ?? 'fas fa-chart-bar';
    $status = isset($_POST['is_published']) ? 'published' : 'draft';
    $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

    // Gestion image
    $image = null;
    if ($editId) {
        $existing = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'service'", [$editId]);
        $image = $existing['image'] ?? null;
    }
    if (!empty($_FILES['image']['tmp_name'])) {
        try {
            $image = uploadImage($_FILES['image'], 'images');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($title)) {
        $errors[] = "Le titre est obligatoire";
    }

    if (empty($errors)) {
        if ($editId) {
            $db->query("
                UPDATE content SET
                    title = ?, slug = ?, content = ?, icon = ?, image = ?,
                    status = ?, sort_order = ?, mise_ajour = NOW()
                WHERE id = ? AND type = 'service'
            ", [$title, $slug, $content, $icon, $image, $status, $sort_order, $editId]);
        } else {
            $db->query("
                INSERT INTO content
                    (title, slug, content, icon, image, status, sort_order, type, user_id, date)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'service', ?, NOW())
            ", [$title, $slug, $content, $icon, $image, $status, $sort_order, (int)($_SESSION['user_id'] ?? 1)]);
        }
        $_SESSION['flash_success'] = 'Opération réalisée avec succès';
        header('Location: ' . BASE_ROUTE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_service']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $service = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'service'", [$id]);
        if ($service && $service['image']) {
            $imgPath = __DIR__ . '/../uploads/images/' . $service['image'];
            if (file_exists($imgPath)) {
                unlink($imgPath);
            }
        }
        $db->delete('content', 'id = ? AND type = ?', [$id, 'service']);
    }
    $_SESSION['flash_success'] = 'Service supprimé avec succès';
    header('Location: ' . BASE_ROUTE);
    exit;
}

// ═══════════════════════════════════════════════════════════
// PAGINATION, FILTRES, RECHERCHE  →  POST (priorité) ou GET
// ═══════════════════════════════════════════════════════════
$page = max(1, intval($_POST['page'] ?? $_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$icon = trim($_POST['icon'] ?? $_GET['icon'] ?? '');
$status = trim($_POST['status'] ?? $_GET['status'] ?? '');

$where = ["type = 'service'"];
$params = [];

if ($search) {
    $where[] = "(title LIKE ? OR content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($icon) {
    $where[] = "icon = ?";
    $params[] = $icon;
}

if ($status !== '') {
    $statusValue = ($status == '1') ? 'published' : 'draft';
    $where[] = "status = ?";
    $params[] = $statusValue;
}

$whereClause = implode(' AND ', $where);

// Compte total
$countSql = "SELECT COUNT(*) as total FROM content WHERE $whereClause";
$total = (int)($db->fetchOne($countSql, $params)['total'] ?? 0);
$totalPages = ceil($total / $perPage);

// Récupération des services
$sql = "SELECT * FROM content WHERE $whereClause ORDER BY sort_order ASC, date DESC LIMIT $perPage OFFSET $offset";
$services = $db->fetchAll($sql, $params);

// Flash messages
$success = false;
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
    <title>Services - AFRINEX Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
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
        .badge-published { background: #34d399; color: #064e3b; }
        .badge-draft { background: #9ca3af; color: white; }
        .btn-gold {
            background: var(--gold);
            color: white;
            border: none;
        }
        .btn-gold:hover { background: #b8921f; color: white; }
        #deleteConfirmModal .modal-content { border-radius: 16px; }
        #deleteConfirmModal .modal-body { padding: 2rem; }
        #deleteConfirmModal i.bi-exclamation-triangle-fill { animation: pulse-warning 2s infinite; }
        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        .service-icon-large {
            font-size: 4rem;
            color: var(--gold);
        }
    </style>
</head>
<body>
<div class="admin-layout">

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="admin-sidebar" id="adminSidebar">
        <?php renderSidebar(); ?>
    </aside>

    <div class="admin-main">

        <!-- Navbar unifiée -->
        <?php renderNavbar('Services', 'bi-grid'); ?>

        <div class="content-area">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Services</h2>
                <button type="button" class="btn btn-gold" id="btnNewService">
                    <i class="bi bi-plus-lg"></i> Nouveau service
                </button>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($errors): ?>
            <div class="alert alert-danger alert-dismissible fade show" id="formErrors">
                <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Filtres → POST -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="POST" class="row g-3 align-items-end" id="filterForm">
                        <input type="hidden" name="c" value="app">
                        <input type="hidden" name="a" value="services">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="icon" class="form-select">
                                <option value="">Toutes icônes</option>
                                <option value="fas fa-database" <?= $icon === 'fas fa-database' ? 'selected' : '' ?>>Database</option>
                                <option value="fas fa-chart-bar" <?= $icon === 'fas fa-chart-bar' ? 'selected' : '' ?>>Chart Bar</option>
                                <option value="fas fa-users" <?= $icon === 'fas fa-users' ? 'selected' : '' ?>>Users</option>
                                <option value="fas fa-building" <?= $icon === 'fas fa-building' ? 'selected' : '' ?>>Building</option>
                                <option value="fas fa-heartbeat" <?= $icon === 'fas fa-heartbeat' ? 'selected' : '' ?>>Heartbeat</option>
                                <option value="fas fa-chart-line" <?= $icon === 'fas fa-chart-line' ? 'selected' : '' ?>>Chart Line</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-select">
                                <option value="">Tous statuts</option>
                                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Publiés</option>
                                <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Brouillons</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-outline-secondary w-100"><i class="bi bi-funnel"></i> Filtrer</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Icône</th>
                                <th>Titre</th>
                                <th>Ordre</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($services)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Aucun service trouvé</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($services as $service): ?>
                            <tr>
                                <td>
                                    <?php if ($service['image']): ?>
                                    <img src="../uploads/images/<?= htmlspecialchars($service['image']) ?>" height="40" class="rounded" style="object-fit: cover; width: 60px;">
                                    <?php else: ?>
                                    <div class="bg-secondary rounded d-flex align-items-center justify-content-center" style="width: 60px; height: 40px;">
                                        <i class="bi bi-<?= str_replace('fas fa-', '', $service['icon'] ?? 'globe') ?> text-white"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($service['title']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars(truncate(strip_tags($service['content'] ?? ''), 60)) ?></small>
                                </td>
                                <td><?= (int)($service['sort_order'] ?? 0) ?></td>
                                <td><?= !empty($service['date']) ? date('d/m/Y', strtotime($service['date'])) : date('d/m/Y') ?></td>
                                <td>
                                    <?php if ($service['status'] === 'published'): ?>
                                    <span class="badge badge-published">Publié</span>
                                    <?php else: ?>
                                    <span class="badge badge-draft">Brouillon</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-view py-1 px-2" data-id="<?= $service['id'] ?>" title="Voir" style="font-size:0.75rem"><i class="bi bi-eye"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit py-1 px-2" data-id="<?= $service['id'] ?>" title="Modifier" style="font-size:0.75rem"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete py-1 px-2" data-id="<?= $service['id'] ?>" title="Supprimer" style="font-size:0.75rem"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3 border-top">
                    <small class="text-muted">Affichage <?= (($page - 1) * $perPage) + 1 ?> - <?= min($page * $perPage, $total) ?> sur <?= $total ?></small>
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
            </div><!-- /table-card -->
        </div><!-- /content-area -->
    </div><!-- /admin-main -->
</div><!-- /admin-layout -->

<!-- ===== MODAL : FORMULAIRE SERVICE (Création & Modification) ===== -->
<div class="modal fade" id="serviceModal" tabindex="-1" aria-labelledby="serviceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="serviceModalLabel">Nouveau service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger" id="formErrors">
                    <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <form method="POST" action="services" enctype="multipart/form-data" id="serviceForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="services">
                    <input type="hidden" name="save_service" value="1">
                    <input type="hidden" name="edit_id" id="editId" value="">

                    <div class="mb-3">
                        <label class="form-label">Titre *</label>
                        <input type="text" name="title" id="formTitle" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="content" id="summernote"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div id="currentImage" class="mt-2"></div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Icône (Font Awesome)</label>
                            <select name="icon" id="formIcon" class="form-select">
                                <option value="fas fa-database">Database</option>
                                <option value="fas fa-chart-bar">Chart Bar</option>
                                <option value="fas fa-users">Users</option>
                                <option value="fas fa-building">Building</option>
                                <option value="fas fa-heartbeat">Heartbeat</option>
                                <option value="fas fa-chart-line">Chart Line</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ordre d'affichage</label>
                            <input type="number" name="sort_order" id="formSortOrder" class="form-control" min="0" value="0">
                        </div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_published" class="form-check-input" id="formIsPublished" value="1" checked>
                        <label class="form-check-label" for="formIsPublished">Publier immédiatement</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBtn">Créer le service</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR SERVICE ===== -->
<div class="modal fade" id="viewServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTitle">Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="viewIconContainer" class="mb-3">
                    <img id="viewImage" src="" class="rounded mb-3" style="max-height: 200px; object-fit: cover; display: none;">
                    <i id="viewIcon" class="bi service-icon-large"></i>
                </div>
                <span id="viewStatus" class="badge mb-2">Statut</span>
                <h3 id="viewServiceTitle">Titre</h3>
                <p id="viewDescription" class="text-muted"></p>
                <div id="viewSortOrder" class="text-muted small"></div>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer ce service ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression POST -->
<form id="deleteForm" method="POST" action="services" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="services">
    <input type="hidden" name="delete_service" value="1">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour la pagination POST -->
<form id="pageForm" method="POST" action="services" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="services">
    <input type="hidden" name="page" id="pageFormPage" value="">
    <input type="hidden" name="search" id="pageFormSearch" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="icon" id="pageFormIcon" value="<?= htmlspecialchars($icon) ?>">
    <input type="hidden" name="status" id="pageFormStatus" value="<?= htmlspecialchars($status) ?>">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

$(document).ready(function() {

    var serviceModalInstance = null;
    var viewModalInstance = null;
    var deleteModalInstance = null;

    // ===== INITIALISER SUMMERNOTE =====
    $('#summernote').summernote({
        height: 250,
        placeholder: 'Description du service...',
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', ['link', 'hr']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ]
    });

    // ===== NOUVEAU SERVICE =====
    $('#btnNewService').on('click', function() {
        resetForm();
        $('#serviceModalLabel').text('Nouveau service');
        $('#submitBtn').text('Créer le service');
        if (!serviceModalInstance) {
            serviceModalInstance = new bootstrap.Modal(document.getElementById('serviceModal'));
        }
        serviceModalInstance.show();
    });

    // ===== VOIR SERVICE =====
    $(document).on('click', '.btn-view', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'services?action=get_service&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var s = response.data;
                    $('#viewTitle').text(s.title);
                    $('#viewServiceTitle').text(s.title);
                    $('#viewDescription').html(s.content || '<span class="text-muted">Aucune description</span>');
                    $('#viewSortOrder').text('Ordre : ' + (s.sort_order || 0));

                    if (s.status === 'published') {
                        $('#viewStatus').removeClass().addClass('badge badge-published').text('Publié');
                    } else {
                        $('#viewStatus').removeClass().addClass('badge badge-draft').text('Brouillon');
                    }

                    if (s.image) {
                        $('#viewImage').attr('src', '../uploads/images/' + s.image).show();
                        $('#viewIcon').hide();
                    } else {
                        $('#viewImage').hide();
                        var iconClass = s.icon ? s.icon.replace('fas fa-', 'bi bi-') : 'bi bi-globe';
                        $('#viewIcon').removeClass().addClass(iconClass + ' service-icon-large').show();
                    }

                    if (!viewModalInstance) {
                        viewModalInstance = new bootstrap.Modal(document.getElementById('viewServiceModal'));
                    }
                    viewModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Service non trouvé'));
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

    // ===== MODIFIER SERVICE =====
    $(document).on('click', '.btn-edit', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'services?action=get_service&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var s = response.data;
                    $('#editId').val(s.id);
                    $('#formTitle').val(s.title);
                    $('#summernote').summernote('code', s.content || '');
                    $('#formIcon').val(s.icon || 'fas fa-chart-bar');
                    $('#formSortOrder').val(s.sort_order || 0);
                    $('#formIsPublished').prop('checked', s.status === 'published');

                    if (s.image) {
                        $('#currentImage').html('<img src="../uploads/images/' + s.image + '" height="100" class="rounded"><small class="text-muted ms-2">Image actuelle</small>');
                    } else {
                        $('#currentImage').html('');
                    }

                    $('#serviceModalLabel').text('Modifier le service');
                    $('#submitBtn').text('Mettre à jour');
                    if (!serviceModalInstance) {
                        serviceModalInstance = new bootstrap.Modal(document.getElementById('serviceModal'));
                    }
                    serviceModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Service non trouvé'));
                }
            },
            error: function(xhr, status, error) {
                var raw = xhr.responseText ? xhr.responseText.substring(0, 500) : 'Pas de réponse';
                console.error('=== ERREUR AJAX MODIFIER ===');
                console.error('Status:', status);
                console.error('Error:', error);
                console.error('HTTP:', xhr.status);
                console.error('Réponse:', raw);
                alert('Erreur de chargement.\n\nStatus: ' + status + '\nHTTP: ' + xhr.status + '\n\n' + raw);
            }
        });
    });

    function resetForm() {
        $('#serviceForm')[0].reset();
        $('#editId').val('');
        $('#currentImage').html('');
        $('#formErrors').remove();
        $('#summernote').summernote('code', '');
    }

    $('#serviceModal').on('hidden.bs.modal', function() {
        resetForm();
    });

    // ===== SUPPRESSION AVEC MODAL DE CONFIRMATION  →  POST =====
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

    // ===== PAGINATION  →  POST =====
    $(document).on('click', '.btn-page', function() {
        var page = $(this).data('page');
        $('#pageFormPage').val(page);
        $('#pageForm').submit();
    });
});
</script>
</body>
</html>