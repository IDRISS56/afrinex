<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'contents');

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
// AJAX : Récupérer une section  →  GET (compatible)
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_section' && isset($_GET['id'])) {
    try {
        $id = (int)$_GET['id'];
        if ($id <= 0) throw new Exception('ID invalide');
        $section = $db->fetchOne("SELECT * FROM content WHERE id = ? AND type = 'section'", [$id]);
        if ($section) {
            $section['status_label'] = ($section['status'] === 'published') ? 'Publié' : 'Brouillon';
            $section['status_badge'] = ($section['status'] === 'published') ? 'badge-published' : 'badge-draft';
            $section['date_formatted'] = !empty($section['date']) ? date('d/m/Y', strtotime($section['date'])) : date('d/m/Y');
            jsonResponse(['success' => true, 'data' => $section]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Section introuvable']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// FONCTIONS DE SYNCHRONISATION MENU
// ═══════════════════════════════════════════════════════════
function syncMenuCreate($db, $title, $slug, $sort_order, $status) {
    $existing = $db->fetchOne("SELECT id FROM menus WHERE url = ? AND menu_location = 'main'", ['#' . $slug]);
    if (!$existing) {
        $db->query("
            INSERT INTO menus (label, url, target, parent_id, sort_order, menu_location, status, date)
            VALUES (?, ?, '_self', NULL, ?, 'main', ?, NOW())
        ", [$title, '#' . $slug, $sort_order, ($status === 'published' ? 1 : 0)]);
    }
}

function syncMenuUpdate($db, $oldSlug, $newTitle, $newSlug, $newSortOrder, $newStatus) {
    $existing = $db->fetchOne("SELECT id FROM menus WHERE url = ? AND menu_location = 'main'", ['#' . $oldSlug]);
    if ($existing) {
        $db->query("
            UPDATE menus SET label = ?, url = ?, sort_order = ?, status = ?
            WHERE id = ? AND menu_location = 'main'
        ", [$newTitle, '#' . $newSlug, $newSortOrder, ($newStatus === 'published' ? 1 : 0), $existing['id']]);
    } else {
        syncMenuCreate($db, $newTitle, $newSlug, $newSortOrder, $newStatus);
    }
}

function syncMenuDelete($db, $slug) {
    $db->query("DELETE FROM menus WHERE url = ? AND menu_location = 'main'", ['#' . $slug]);
}

function syncMenuStatus($db, $slug, $status) {
    $db->query("
        UPDATE menus SET status = ? WHERE url = ? AND menu_location = 'main'
    ", [($status === 'published' ? 1 : 0), '#' . $slug]);
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE (CREATE/UPDATE)  →  POST
// ═══════════════════════════════════════════════════════════
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_section'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;

    $title = trim($_POST['title'] ?? '');
    $slug = slugify($title);
    $content = trim($_POST['content'] ?? '');
    $status = isset($_POST['is_published']) ? 'published' : 'draft';
    $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

    // Gestion image de fond
    $image = null;
    if ($editId) {
        $existing = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'section'", [$editId]);
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
            $oldSection = $db->fetchOne("SELECT slug FROM content WHERE id = ? AND type = 'section'", [$editId]);
            $oldSlug = $oldSection['slug'] ?? $slug;

            $db->query("
                UPDATE content SET
                    title = ?, slug = ?, content = ?, image = ?, status = ?, sort_order = ?, mise_ajour = NOW()
                WHERE id = ? AND type = 'section'
            ", [$title, $slug, $content, $image, $status, $sort_order, $editId]);

            syncMenuUpdate($db, $oldSlug, $title, $slug, $sort_order, $status);

        } else {
            $db->query("
                INSERT INTO content
                    (title, slug, content, image, status, sort_order, type, user_id, date)
                VALUES (?, ?, ?, ?, ?, ?, 'section', ?, NOW())
            ", [$title, $slug, $content, $image, $status, $sort_order, (int)($_SESSION['user_id'] ?? 1)]);

            syncMenuCreate($db, $title, $slug, $sort_order, $status);
        }
        $_SESSION['flash_success'] = 'Opération réalisée avec succès';
        header('Location: ' . BASE_ROUTE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_section']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $section = $db->fetchOne("SELECT slug, image FROM content WHERE id = ? AND type = 'section'", [$id]);
        if ($section) {
            syncMenuDelete($db, $section['slug']);
            if ($section['image']) {
                $imgPath = __DIR__ . '/../uploads/images/' . $section['image'];
                if (file_exists($imgPath)) {
                    unlink($imgPath);
                }
            }
        }
        $db->delete('content', 'id = ? AND type = ?', [$id, 'section']);
    }
    $_SESSION['flash_success'] = 'Section supprimée avec succès';
    header('Location: ' . BASE_ROUTE);
    exit;
}

// ═══════════════════════════════════════════════════════════
// PAGINATION, FILTRES, RECHERCHE  →  POST (priorité) ou GET
// ═══════════════════════════════════════════════════════════
$content = max(1, intval($_POST['content'] ?? $_GET['content'] ?? 1));
$percontent = 10;
$offset = ($content - 1) * $percontent;

$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$status = trim($_POST['status'] ?? $_GET['status'] ?? '');

$where = ["type = 'section'"];
$params = [];

if ($search) {
    $where[] = "(title LIKE ? OR slug LIKE ? OR content LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
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
$totalcontents = ceil($total / $percontent);

// Récupération des sections
$sql = "SELECT * FROM content WHERE $whereClause ORDER BY sort_order ASC, date DESC LIMIT $percontent OFFSET $offset";
$sections = $db->fetchAll($sql, $params);

// Flash messages
$success = false;
$successMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $success = true;
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ═══════════════════════════════════════════════════════════
// LAYOUT : titre de page + assets spécifiques à Sections
// ═══════════════════════════════════════════════════════════
$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
)['count'] ?? 0);

$pageTitle = 'Sections';
$pageIcon  = 'bi-layout-text-sidebar';

// CSS/JS propres à cette page (Summernote), injectés par layout.php avant </head>
$extraHead = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
HTML;

// Styles propres à Sections (le socle sidebar/navbar/layout est déjà géré par layout.php)
$extraStyles = <<<CSS
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
        .slug-badge {
            font-family: monospace;
            font-size: 0.75rem;
            background: #e1e4e8;
            color: #57606a;
            padding: 0.2em 0.5em;
            border-radius: 4px;
        }
        .menu-sync-badge { font-size: 0.7rem; }
        #deleteConfirmModal .modal-content { border-radius: 16px; }
        #deleteConfirmModal .modal-body { padding: 2rem; }
        #deleteConfirmModal i.bi-exclamation-triangle-fill { animation: pulse-warning 2s infinite; }
        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        .section-thumb {
            width: 80px;
            height: 50px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #e1e4e8;
        }
        .section-thumb-placeholder {
            width: 80px;
            height: 50px;
            background: linear-gradient(135deg, #1A253A 0%, #0F1923 100%);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.4);
            font-size: 0.7rem;
            border: 1px solid #e1e4e8;
        }
CSS;

// layout.php ouvre : <html><head>...</head><body><div class="admin-layout">
//   <aside>...sidebar...</aside><div class="admin-main"><nav>...navbar...</nav>
//   <div class="admin-content">   ← reste ouvert, on continue le contenu ici
require_once __DIR__ . '/layout.php';

if (ob_get_level() > 0) { ob_end_flush(); }
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Sections du site</h2>
                <button type="button" class="btn btn-gold" id="btnNewSection">
                    <i class="bi bi-plus-lg"></i> Nouvelle section
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

            <!-- Info synchronisation menu -->
            <div class="alert alert-info d-flex align-items-center mb-4">
                <i class="bi bi-info-circle me-2"></i>
                <small>Les sections sont automatiquement synchronisées avec le menu principal. Créer, modifier ou supprimer une section met à jour le menu en temps réel.</small>
            </div>

            <!-- Filtres → POST -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="POST" class="row g-3 align-items-end" id="filterForm">
                        <input type="hidden" name="c" value="app">
                        <input type="hidden" name="a" value="contents">
                        <div class="col-md-6">
                            <input type="text" name="search" class="form-control" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-4">
                            <select name="status" class="form-select">
                                <option value="">Tous statuts</option>
                                <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Publiées</option>
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
                                <th>Aperçu</th>
                                <th>Ordre</th>
                                <th>Titre</th>
                                <th>Slug / URL</th>
                                <th>Menu</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sections)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">Aucune section trouvée</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($sections as $section):
                                $menuLink = $db->fetchOne("SELECT id, status FROM menus WHERE url = ? AND menu_location = 'main'", ['#' . ($section['slug'] ?? '')]);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($section['image']): ?>
                                    <img src="../uploads/images/<?= htmlspecialchars($section['image']) ?>" class="section-thumb" alt="<?= htmlspecialchars($section['title']) ?>">
                                    <?php else: ?>
                                    <div class="section-thumb-placeholder">
                                        <i class="bi bi-image"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= (int)($section['sort_order'] ?? 0) ?></td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars(strip_tags($section['title'] ?? '')) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars(truncate(strip_tags($section['content'] ?? ''), 50)) ?></small>
                                </td>
                                <td>
                                    <span class="slug-badge">#<?= htmlspecialchars($section['slug'] ?? '') ?></span>
                                </td>
                                <td>
                                    <?php if ($menuLink): ?>
                                    <span class="badge bg-success menu-sync-badge"><i class="bi bi-check"></i> Synchronisé</span>
                                    <?php else: ?>
                                    <span class="badge bg-warning menu-sync-badge"><i class="bi bi-exclamation-triangle"></i> Non sync.</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($section['status'] === 'published'): ?>
                                    <span class="badge badge-published">Publiée</span>
                                    <?php else: ?>
                                    <span class="badge badge-draft">Brouillon</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-view py-1 px-2" data-id="<?= $section['id'] ?>" title="Voir" style="font-size:0.75rem"><i class="bi bi-eye"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit py-1 px-2" data-id="<?= $section['id'] ?>" title="Modifier" style="font-size:0.75rem"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete py-1 px-2" data-id="<?= $section['id'] ?>" title="Supprimer" style="font-size:0.75rem"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalcontents > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3 border-top">
                    <small class="text-muted">Affichage <?= (($content - 1) * $percontent) + 1 ?> - <?= min($content * $percontent, $total) ?> sur <?= $total ?></small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($content > 1): ?>
                            <li class="content-item"><button type="button" class="content-link btn-content" data-content="<?= $content - 1 ?>">Précédent</button></li>
                            <?php endif; ?>
                            <?php for ($i = max(1, $content - 2); $i <= min($totalcontents, $content + 2); $i++): ?>
                            <li class="content-item <?= $i === $content ? 'active' : '' ?>"><button type="button" class="content-link btn-content" data-content="<?= $i ?>"><?= $i ?></button></li>
                            <?php endfor; ?>
                            <?php if ($content < $totalcontents): ?>
                            <li class="content-item"><button type="button" class="content-link btn-content" data-content="<?= $content + 1 ?>">Suivant</button></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div><!-- /table-card -->
        </div><!-- /admin-content -->
    </div><!-- /admin-main -->
</div><!-- /admin-layout -->

<!-- ===== MODAL : FORMULAIRE SECTION (Création & Modification) ===== -->
<div class="modal fade" id="sectionModal" tabindex="-1" aria-labelledby="sectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sectionModalLabel">Nouvelle section</h5>
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
                <form method="POST" action="contents" enctype="multipart/form-data" id="sectionForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="contents">
                    <input type="hidden" name="save_section" value="1">
                    <input type="hidden" name="edit_id" id="editId" value="">

                    <div class="mb-3">
                        <label class="form-label">Titre de la section *</label>
                        <input type="text" name="title" id="formTitle" class="form-control" required>
                        <small class="text-muted">Ce titre sera utilisé comme label dans le menu principal.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Contenu HTML</label>
                        <textarea name="content" id="summernoteContent"></textarea>
                    </div>

                    <!-- IMAGE DE FOND -->
                    <div class="mb-3">
                        <label class="form-label">Image de fond</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div id="currentImage" class="mt-2"></div>
                        <small class="text-muted">Cette image sera utilisée comme fond de la section sur le site.</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ordre d'affichage</label>
                            <input type="number" name="sort_order" id="formSortOrder" class="form-control" min="0" value="0">
                            <small class="text-muted">Détermine la position dans le menu.</small>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <i class="bi bi-link me-1"></i>
                        <small>Le slug sera généré automatiquement à partir du titre et servira d'ancre (ex: <code>#mon-titre</code>).</small>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_published" class="form-check-input" id="formIsPublished" value="1" checked>
                        <label class="form-check-label" for="formIsPublished">Publier et ajouter au menu</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBtn">Créer la section</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR SECTION ===== -->
<div class="modal fade" id="viewSectionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTitle">Section</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <span id="viewSlug" class="slug-badge">#slug</span>
                    <span id="viewStatus" class="badge ms-2">Statut</span>
                </div>
                <div id="viewImageContainer" class="mb-3 rounded overflow-hidden" style="display:none; max-height:200px;">
                    <img id="viewImage" src="" class="w-100 object-cover" style="max-height:200px;">
                </div>
                <h3 id="viewSectionTitle">Titre</h3>
                <hr>
                <div id="viewContent" class="p-3 bg-light rounded"></div>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer cette section ?<br>Le menu sera également mis à jour.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression POST -->
<form id="deleteForm" method="POST" action="contents" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="contents">
    <input type="hidden" name="delete_section" value="1">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour la pagination POST -->
<form id="contentForm" method="POST" action="contents" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="contents">
    <input type="hidden" name="content" id="contentFormcontent" value="">
    <input type="hidden" name="search" id="contentFormSearch" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="status" id="contentFormStatus" value="<?= htmlspecialchars($status) ?>">
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

    var sectionModalInstance = null;
    var viewModalInstance = null;
    var deleteModalInstance = null;

    // ===== INITIALISER SUMMERNOTE =====
    $('#summernoteContent').summernote({
        height: 300,
        placeholder: 'Contenu HTML de la section...',
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', ['link', 'hr']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ]
    });

    // ===== NOUVELLE SECTION =====
    $('#btnNewSection').on('click', function() {
        resetForm();
        $('#sectionModalLabel').text('Nouvelle section');
        $('#submitBtn').text('Créer la section');
        if (!sectionModalInstance) {
            sectionModalInstance = new bootstrap.Modal(document.getElementById('sectionModal'));
        }
        sectionModalInstance.show();
        setTimeout(function() {
            $('#summernoteContent').summernote('code', '');
        }, 300);
    });

    // ===== VOIR SECTION  →  GET =====
    $(document).on('click', '.btn-view', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'contents?action=get_section&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var s = response.data;
                    $('#viewTitle').text('Section');
                    $('#viewSectionTitle').text(s.title || 'Sans titre');
                    $('#viewSlug').text('#' + (s.slug || ''));
                    $('#viewContent').html(s.content || '<span class="text-muted">Aucun contenu</span>');

                    if (s.image) {
                        $('#viewImage').attr('src', '../uploads/images/' + s.image);
                        $('#viewImageContainer').show();
                    } else {
                        $('#viewImageContainer').hide();
                    }

                    if (s.status === 'published') {
                        $('#viewStatus').removeClass().addClass('badge badge-published').text('Publiée');
                    } else {
                        $('#viewStatus').removeClass().addClass('badge badge-draft').text('Brouillon');
                    }

                    if (!viewModalInstance) {
                        viewModalInstance = new bootstrap.Modal(document.getElementById('viewSectionModal'));
                    }
                    viewModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Section non trouvée'));
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

    // ===== MODIFIER SECTION  →  GET =====
    $(document).on('click', '.btn-edit', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'contents?action=get_section&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var s = response.data;
                    $('#editId').val(s.id);
                    $('#formTitle').val(s.title || '');
                    $('#formSortOrder').val(s.sort_order || 0);
                    $('#formIsPublished').prop('checked', s.status === 'published');

                    if (s.image) {
                        $('#currentImage').html('<img src="../uploads/images/' + s.image + '" height="100" class="rounded section-thumb"><small class="text-muted ms-2">Image actuelle</small>');
                    } else {
                        $('#currentImage').html('');
                    }

                    $('#sectionModalLabel').text('Modifier la section');
                    $('#submitBtn').text('Mettre à jour');

                    if (!sectionModalInstance) {
                        sectionModalInstance = new bootstrap.Modal(document.getElementById('sectionModal'));
                    }
                    sectionModalInstance.show();
                    setTimeout(function() {
                        $('#summernoteContent').summernote('code', s.content || '');
                    }, 300);
                } else {
                    alert('Erreur : ' + (response.message || 'Section non trouvée'));
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
        $('#sectionForm')[0].reset();
        $('#editId').val('');
        $('#currentImage').html('');
        $('#formErrors').remove();
        if ($('#summernoteContent').summernote) {
            $('#summernoteContent').summernote('code', '');
        }
    }

    $('#sectionModal').on('hidden.bs.modal', function() {
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

    // ===== PAGINATION  →  POST (avec conservation des filtres) =====
    $(document).on('click', '.btn-content', function() {
        var content = $(this).data('content');
        $('#contentFormcontent').val(content);
        $('#contentForm').submit();
    });
});
</script>
</body>
</html>