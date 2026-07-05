<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'testimonials');

// ═══════════════════════════════════════════════════════════
// FONCTIONS UTILITAIRES (définies AVANT usage)
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
// AJAX : Récupérer un témoignage  →  GET
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_testimonial' && isset($_GET['id'])) {
    try {
        $id = (int)$_GET['id'];
        if ($id <= 0) throw new Exception('ID invalide');
        $testimonial = $db->fetchOne("SELECT * FROM content WHERE id = ? AND type = 'temoignage'", [$id]);
        if ($testimonial) {
            $testimonial['status_label'] = ($testimonial['status'] === 'published') ? 'Publié' : 'Brouillon';
            $testimonial['status_badge'] = ($testimonial['status'] === 'published') ? 'badge-published' : 'badge-draft';
            $testimonial['date_formatted'] = !empty($testimonial['date']) ? date('d/m/Y', strtotime($testimonial['date'])) : date('d/m/Y');
            jsonResponse(['success' => true, 'data' => $testimonial]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Témoignage introuvable']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE (CREATE/UPDATE)  →  POST
// ═══════════════════════════════════════════════════════════
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_testimonial'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;

    $title = trim($_POST['title'] ?? '');  // Citation du témoignage
    $slug = slugify($title);
    $content = trim($_POST['content'] ?? '');  // Texte additionnel si besoin
    $author = trim($_POST['author'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $company = trim($_POST['company'] ?? '');
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 5;
    $status = isset($_POST['is_published']) ? 'published' : 'draft';
    $sort_order = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

    // Gestion image (avatar)
    $image = null;
    if ($editId) {
        $existing = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'temoignage'", [$editId]);
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
        $errors[] = "La citation est obligatoire";
    }
    if (empty($author)) {
        $errors[] = "Le nom de l'auteur est obligatoire";
    }

    if (empty($errors)) {
        if ($editId) {
            $db->query("
                UPDATE content SET
                    title = ?, slug = ?, content = ?, author = ?, role = ?,
                    company = ?, rating = ?, image = ?, status = ?, sort_order = ?, mise_ajour = NOW()
                WHERE id = ? AND type = 'temoignage'
            ", [$title, $slug, $content, $author, $role, $company, $rating, $image, $status, $sort_order, $editId]);
        } else {
            $db->query("
                INSERT INTO content
                    (title, slug, content, author, role, company, rating, image, status, sort_order, type, user_id, date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'temoignage', ?, NOW())
            ", [$title, $slug, $content, $author, $role, $company, $rating, $image, $status, $sort_order, (int)($_SESSION['user_id'] ?? 1)]);
        }
        $_SESSION['flash_success'] = 'Opération réalisée avec succès';
        header('Location: ' . BASE_ROUTE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_testimonial']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $testimonial = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'temoignage'", [$id]);
        if ($testimonial && $testimonial['image']) {
            $imgPath = __DIR__ . '/../uploads/images/' . $testimonial['image'];
            if (file_exists($imgPath)) {
                unlink($imgPath);
            }
        }
        $db->delete('content', 'id = ? AND type = ?', [$id, 'temoignage']);
    }
    $_SESSION['flash_success'] = 'Témoignage supprimé avec succès';
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
$status = trim($_POST['status'] ?? $_GET['status'] ?? '');

$where = ["type = 'temoignage'"];
$params = [];

if ($search) {
    $where[] = "(title LIKE ? OR content LIKE ? OR author LIKE ? OR role LIKE ?)";
    $params[] = "%$search%";
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
$totalPages = ceil($total / $perPage);

// Récupération des témoignages
$sql = "SELECT * FROM content WHERE $whereClause ORDER BY sort_order ASC, date DESC LIMIT $perPage OFFSET $offset";
$testimonials = $db->fetchAll($sql, $params);

// Flash messages
$success = false;
$successMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $success = true;
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ═══════════════════════════════════════════════════════════
// LAYOUT : titre de page + assets spécifiques à Témoignages
// ═══════════════════════════════════════════════════════════
$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
)['count'] ?? 0);

$pageTitle = 'Témoignages';
$pageIcon  = 'bi-chat-quote';

// CSS/JS propres à cette page (Summernote), injectés par layout.php avant </head>
$extraHead = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
HTML;

// Styles propres à Témoignages (le socle sidebar/navbar/layout est déjà géré par layout.php)
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
        #deleteConfirmModal .modal-content { border-radius: 16px; }
        #deleteConfirmModal .modal-body { padding: 2rem; }
        #deleteConfirmModal i.bi-exclamation-triangle-fill { animation: pulse-warning 2s infinite; }
        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        .quote-text {
            font-style: italic;
            color: #374151;
            border-left: 3px solid var(--gold);
            padding-left: 1rem;
        }
        .avatar-circle {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: #1A253A;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .star-rating {
            color: var(--gold);
        }
CSS;

// layout.php ouvre : <html><head>...</head><body><div class="admin-layout">
//   <aside>...sidebar...</aside><div class="admin-main"><nav>...navbar...</nav>
//   <div class="admin-content">   ← reste ouvert, on continue le contenu ici
require_once __DIR__ . '/layout.php';

if (ob_get_level() > 0) { ob_end_flush(); }
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Témoignages</h2>
                <button type="button" class="btn btn-gold" id="btnNewTestimonial">
                    <i class="bi bi-plus-lg"></i> Nouveau témoignage
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
                        <input type="hidden" name="a" value="testimonials">
                        <div class="col-md-6">
                            <input type="text" name="search" class="form-control" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-4">
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
                                <th>Auteur</th>
                                <th>Citation</th>
                                <th>Note</th>
                                <th>Ordre</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($testimonials)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">Aucun témoignage trouvé</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($testimonials as $t): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($t['image']): ?>
                                        <img src="../uploads/images/<?= htmlspecialchars($t['image']) ?>" class="rounded-circle" style="width: 40px; height: 40px; object-fit: cover;">
                                        <?php else: ?>
                                        <div class="avatar-circle"><?= strtoupper(substr($t['author'] ?? 'A', 0, 1)) ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-medium"><?= htmlspecialchars($t['author'] ?? 'Anonyme') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($t['role'] ?? '') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="quote-text"><?= htmlspecialchars(truncate(strip_tags($t['title'] ?? ''), 80)) ?></div>
                                </td>
                                <td>
                                    <div class="star-rating">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi bi-star<?= $i <= ($t['rating'] ?? 5) ? '-fill' : '' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </td>
                                <td><?= (int)($t['sort_order'] ?? 0) ?></td>
                                <td>
                                    <?php if ($t['status'] === 'published'): ?>
                                    <span class="badge badge-published">Publié</span>
                                    <?php else: ?>
                                    <span class="badge badge-draft">Brouillon</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-view py-1 px-2" data-id="<?= $t['id'] ?>" title="Voir" style="font-size:0.75rem"><i class="bi bi-eye"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit py-1 px-2" data-id="<?= $t['id'] ?>" title="Modifier" style="font-size:0.75rem"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete py-1 px-2" data-id="<?= $t['id'] ?>" title="Supprimer" style="font-size:0.75rem"><i class="bi bi-trash"></i></button>
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
        </div><!-- /admin-content -->
    </div><!-- /admin-main -->
</div><!-- /admin-layout -->

<!-- ===== MODAL : FORMULAIRE TÉMOIGNAGE (Création & Modification) ===== -->
<div class="modal fade" id="testimonialModal" tabindex="-1" aria-labelledby="testimonialModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="testimonialModalLabel">Nouveau témoignage</h5>
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
                <form method="POST" action="testimonials" enctype="multipart/form-data" id="testimonialForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="testimonials">
                    <input type="hidden" name="save_testimonial" value="1">
                    <input type="hidden" name="edit_id" id="editId" value="">

                    <div class="mb-3">
                        <label class="form-label">Citation *</label>
                        <textarea name="title" id="summernoteTitle" class="form-control" required></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Nom de l'auteur *</label>
                            <input type="text" name="author" id="formAuthor" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Fonction / Rôle</label>
                            <input type="text" name="role" id="formRole" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Entreprise</label>
                            <input type="text" name="company" id="formCompany" class="form-control">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Note</label>
                            <select name="rating" id="formRating" class="form-select">
                                <option value="5">5 étoiles</option>
                                <option value="4">4 étoiles</option>
                                <option value="3">3 étoiles</option>
                                <option value="2">2 étoiles</option>
                                <option value="1">1 étoile</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ordre d'affichage</label>
                            <input type="number" name="sort_order" id="formSortOrder" class="form-control" min="0" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Texte additionnel (optionnel)</label>
                        <textarea name="content" id="formContent" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Photo / Avatar</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div id="currentImage" class="mt-2"></div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_published" class="form-check-input" id="formIsPublished" value="1" checked>
                        <label class="form-check-label" for="formIsPublished">Publier immédiatement</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBtn">Créer le témoignage</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR TÉMOIGNAGE ===== -->
<div class="modal fade" id="viewTestimonialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTitle">Témoignage</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="viewAvatar" class="mb-3">
                    <img id="viewImage" src="" class="rounded-circle mb-3" style="width: 80px; height: 80px; object-fit: cover; display: none;">
                    <div id="viewInitials" class="avatar-circle mx-auto" style="width: 80px; height: 80px; font-size: 1.5rem;">A</div>
                </div>
                <div id="viewRating" class="star-rating mb-2"></div>
                <span id="viewStatus" class="badge mb-2">Statut</span>
                <div id="viewQuote" class="quote-text mb-3"></div>
                <div class="text-muted">
                    <div id="viewAuthor" class="fw-medium"></div>
                    <div id="viewRole"></div>
                    <div id="viewCompany" class="small"></div>
                </div>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer ce témoignage ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression POST -->
<form id="deleteForm" method="POST" action="testimonials" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="testimonials">
    <input type="hidden" name="delete_testimonial" value="1">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour la pagination POST -->
<form id="pageForm" method="POST" action="testimonials" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="testimonials">
    <input type="hidden" name="page" id="pageFormPage" value="">
    <input type="hidden" name="search" id="pageFormSearch" value="<?= htmlspecialchars($search) ?>">
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

    var testimonialModalInstance = null;
    var viewModalInstance = null;
    var deleteModalInstance = null;

    // ===== INITIALISER SUMMERNOTE =====
    $('#summernoteTitle').summernote({
        height: 120,
        placeholder: 'Saisissez la citation du témoignage...',
        toolbar: [
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['paragraph']]
        ]
    });

    // ===== NOUVEAU TÉMOIGNAGE =====
    $('#btnNewTestimonial').on('click', function() {
        resetForm();
        $('#testimonialModalLabel').text('Nouveau témoignage');
        $('#submitBtn').text('Créer le témoignage');
        if (!testimonialModalInstance) {
            testimonialModalInstance = new bootstrap.Modal(document.getElementById('testimonialModal'));
        }
        testimonialModalInstance.show();
        setTimeout(function() {
            $('#summernoteTitle').summernote('code', '');
        }, 300);
    });

    // ===== VOIR TÉMOIGNAGE =====
    $(document).on('click', '.btn-view', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'testimonials?action=get_testimonial&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var t = response.data;
                    $('#viewTitle').text('Témoignage');
                    $('#viewQuote').html(t.title || '<span class="text-muted">Aucune citation</span>');
                    $('#viewAuthor').text(t.author || 'Anonyme');
                    $('#viewRole').text(t.role || '');
                    $('#viewCompany').text(t.company || '');

                    // Rating
                    var starsHtml = '';
                    for (var i = 1; i <= 5; i++) {
                        starsHtml += '<i class="bi bi-star' + (i <= (t.rating || 5) ? '-fill' : '') + '"></i> ';
                    }
                    $('#viewRating').html(starsHtml);

                    // Status
                    if (t.status === 'published') {
                        $('#viewStatus').removeClass().addClass('badge badge-published').text('Publié');
                    } else {
                        $('#viewStatus').removeClass().addClass('badge badge-draft').text('Brouillon');
                    }

                    // Avatar
                    if (t.image) {
                        $('#viewImage').attr('src', '../uploads/images/' + t.image).show();
                        $('#viewInitials').hide();
                    } else {
                        $('#viewImage').hide();
                        $('#viewInitials').text((t.author || 'A').charAt(0).toUpperCase()).show();
                    }

                    if (!viewModalInstance) {
                        viewModalInstance = new bootstrap.Modal(document.getElementById('viewTestimonialModal'));
                    }
                    viewModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Témoignage non trouvé'));
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

    // ===== MODIFIER TÉMOIGNAGE =====
    $(document).on('click', '.btn-edit', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'testimonials?action=get_testimonial&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var t = response.data;
                    $('#editId').val(t.id);
                    $('#formAuthor').val(t.author || '');
                    $('#formRole').val(t.role || '');
                    $('#formCompany').val(t.company || '');
                    $('#formRating').val(t.rating || 5);
                    $('#formSortOrder').val(t.sort_order || 0);
                    $('#formContent').val(t.content || '');
                    $('#formIsPublished').prop('checked', t.status === 'published');

                    if (t.image) {
                        $('#currentImage').html('<img src="../uploads/images/' + t.image + '" height="100" class="rounded"><small class="text-muted ms-2">Image actuelle</small>');
                    } else {
                        $('#currentImage').html('');
                    }

                    $('#testimonialModalLabel').text('Modifier le témoignage');
                    $('#submitBtn').text('Mettre à jour');

                    if (!testimonialModalInstance) {
                        testimonialModalInstance = new bootstrap.Modal(document.getElementById('testimonialModal'));
                    }
                    testimonialModalInstance.show();
                    setTimeout(function() {
                        $('#summernoteTitle').summernote('code', t.title || '');
                    }, 300);
                } else {
                    alert('Erreur : ' + (response.message || 'Témoignage non trouvé'));
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
        $('#testimonialForm')[0].reset();
        $('#editId').val('');
        $('#currentImage').html('');
        $('#formErrors').remove();
        if ($('#summernoteTitle').summernote) {
            $('#summernoteTitle').summernote('code', '');
        }
    }

    $('#testimonialModal').on('hidden.bs.modal', function() {
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