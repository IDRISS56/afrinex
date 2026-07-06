<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'articles');

// Tous les rôles connectés peuvent accéder à cette page ; les "author"
// sont limités à leur propre contenu (cf. $restrictToOwn plus bas).
$restrictToOwn  = !isEditor();
$currentUserId  = (int)($_SESSION['user_id'] ?? 0);

// ═══════════════════════════════════════════════════════════
// FONCTIONS UTILITAIRES  →  AVANT les blocs AJAX !
// ═══════════════════════════════════════════════════════════
if (!function_exists('getCategoryLabel')) {
    function getCategoryLabel(string $category): string {
        return match ($category) {
            'data-story'        => 'Data Story',
            'consumer-insights' => 'Consumer Insights',
            'intelligence-eco'  => 'Intelligence Éco',
            default             => ucfirst(str_replace('-', ' ', $category))
        };
    }
}
if (!function_exists('formatDate')) {
    function formatDate($date): string { return date('d/m/Y', strtotime($date)); }
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
// AJAX : Récupérer un article  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_article' && isset($_POST['id'])) {
    try {
        $id = (int)$_POST['id'];
        if ($id <= 0) throw new Exception('ID invalide');
        
        $article = $db->fetchOne("SELECT * FROM content WHERE id = ? AND type = 'article'", [$id]);
        
        if (!$article) {
            jsonResponse(['success' => false, 'message' => 'Article introuvable']);
        }
        if ($restrictToOwn && (int)$article['user_id'] !== $currentUserId) {
            jsonResponse(['success' => false, 'message' => 'Vous n\'êtes pas autorisé à consulter cet article.']);
        }
        
        $article['category_label']         = getCategoryLabel($article['category'] ?? '');
        $article['published_at_formatted'] = !empty($article['date']) ? date('d/m/Y', strtotime($article['date'])) : date('d/m/Y');
        $article['is_published']           = ($article['status'] === 'published') ? 1 : 0;
        
        jsonResponse(['success' => true, 'data' => $article]);
        
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// AJAX : Upload images éditeur  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_editor_image') {
    try {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Aucun fichier reçu ou erreur d\'upload');
        }
        $file = $_FILES['file'];
        $targetDir = __DIR__ . '/../uploads/images/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            throw new Exception('Format non autorisé');
        }
        $filename = uniqid() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
            throw new Exception('Erreur lors du déplacement du fichier');
        }
        $url = '../uploads/images/' . $filename;
        jsonResponse(['success' => true, 'url' => $url]);
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_article']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $art = $db->fetchOne("SELECT image, user_id, status FROM content WHERE id=? AND type='article'", [$id]);
        if ($art && (!$restrictToOwn || (int)$art['user_id'] === $currentUserId)) {
            // ── Authors cannot delete published articles ──
            if ($restrictToOwn && $art['status'] === 'published') {
                $_SESSION['flash_error'] = 'Vous ne pouvez pas supprimer un article déjà publié.';
            } else {
                if ($art['image']) {
                    $imgPath = __DIR__ . '/../uploads/images/' . $art['image'];
                    if (file_exists($imgPath)) unlink($imgPath);
                }
                $db->delete('content', 'id=? AND type=?', [$id, 'article']);
                $_SESSION['flash_success'] = 'Article supprimé avec succès';
            }
        } else {
            $_SESSION['flash_error'] = 'Vous n\'êtes pas autorisé à supprimer cet article.';
        }
    }
    header('Location: ' . BASE_ROUTE);
    exit;
}

// ═══════════════════════════════════════════════════════════
// PAGINATION, FILTRES, RECHERCHE  →  POST
// ═══════════════════════════════════════════════════════════
$page    = max(1, intval($_POST['page'] ?? $_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$search   = trim($_POST['search']   ?? $_GET['search']   ?? '');
$category = trim($_POST['category'] ?? $_GET['category'] ?? '');
$status   = trim($_POST['status']   ?? $_GET['status']   ?? '');

$where  = ["type = 'article'"];
$params = [];

if ($restrictToOwn) {
    $where[]  = "user_id = ?";
    $params[] = $currentUserId;
}
if ($search) {
    $where[]  = "(title LIKE ? OR excerpt LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category) {
    $where[]  = "category = ?";
    $params[] = $category;
}
if ($status !== '') {
    $where[]  = "status = ?";
    $params[] = ($status == '1') ? 'published' : 'draft';
}

$whereClause = implode(' AND ', $where);
$total       = (int)($db->fetchOne("SELECT COUNT(*) as total FROM content WHERE $whereClause", $params)['total'] ?? 0);
$totalPages  = (int)ceil($total / $perPage);
$articles    = $db->fetchAll("SELECT * FROM content WHERE $whereClause ORDER BY date DESC LIMIT $perPage OFFSET $offset", $params);

$success        = false;
$successMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $success        = true;
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
$flashError = '';
if (!empty($_SESSION['flash_error'])) {
    $flashError = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE (CREATE/UPDATE)  →  POST
// ═══════════════════════════════════════════════════════════
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    $editId       = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;
    $title        = trim($_POST['title']   ?? '');
    $slug         = slugify($title);
    $excerpt      = trim($_POST['excerpt'] ?? '');
    $content_body = trim($_POST['content'] ?? '');
    $category     = $_POST['category']     ?? 'data-story';
    $author       = trim($_POST['author']  ?? 'Équipe AFRINEX');
    
    // ── Authors: status is ALWAYS draft ──
    // Editors: can choose via checkbox
    $status_val = $restrictToOwn ? 'draft' : (isset($_POST['is_published']) ? 'published' : 'draft');

    $image = null;
    if ($editId) {
        $existing = $db->fetchOne("SELECT image, user_id, status FROM content WHERE id = ? AND type = 'article'", [$editId]);
        $image    = $existing['image'] ?? null;
        if ($restrictToOwn && (!$existing || (int)$existing['user_id'] !== $currentUserId)) {
            $errors[] = "Vous n'êtes pas autorisé à modifier cet article.";
        }
        // ── Authors cannot edit published articles ──
        if ($restrictToOwn && $existing && $existing['status'] === 'published') {
            $errors[] = "Vous ne pouvez pas modifier un article déjà publié.";
        }
    }
    if (!empty($_FILES['image']['tmp_name'])) {
        try   { $image = uploadImage($_FILES['image'], 'images'); }
        catch (Throwable $e) { $errors[] = $e->getMessage(); }
    }
    if (empty($title)) $errors[] = "Le titre est obligatoire";

    if (empty($errors)) {
        if ($editId) {
            $db->query("
                UPDATE content SET
                    title=?, slug=?, excerpt=?, content=?,
                    category=?, image=?, author=?, status=?, mise_ajour=NOW()
                WHERE id=? AND type='article'
            ", [$title, $slug, $excerpt, $content_body, $category, $image, $author, $status_val, $editId]);
        } else {
            $db->query("
                INSERT INTO content (title,slug,excerpt,content,category,image,author,status,type,user_id,date)
                VALUES (?,?,?,?,?,?,?,?,'article',?,NOW())
            ", [$title,$slug,$excerpt,$content_body,$category,$image,$author,$status_val,(int)($_SESSION['user_id']??1)]);
        }
        $_SESSION['flash_success'] = 'Opération réalisée avec succès';
        header('Location: ' . BASE_ROUTE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// LAYOUT : titre de page + assets spécifiques à Articles
// ═══════════════════════════════════════════════════════════
$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read=0 AND type='message'"
)['count'] ?? 0);

$pageTitle = 'Articles';
$pageIcon  = 'bi-newspaper';

// CSS/JS propres à cette page (Summernote), injectés par layout.php avant </head>
$extraHead = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
HTML;

// Styles propres à Articles (le socle sidebar/navbar/layout est déjà géré par layout.php)
$extraStyles = <<<CSS
        .table-card { background:#fff; border-radius:12px; border:1px solid #e1e4e8; }
        .badge-published { background:#34d399; color:#064e3b; }
        .badge-draft     { background:#9ca3af; color:#fff; }
        .btn-gold       { background:var(--gold); color:#fff; border:none; }
        .btn-gold:hover  { background:#b8921f; color:#fff; }
        .search-box { max-width:300px; }
        .video-pending, .video-embed {
            position:relative; padding-bottom:56.25%; height:0;
            overflow:hidden; border-radius:8px; margin:15px 0; background:#000;
        }
        .video-pending iframe, .video-pending video,
        .video-embed  iframe, .video-embed  video {
            position:absolute; top:0; left:0; width:100%; height:100%; border:none;
        }
        .note-editor .note-editable img { max-width:100%; height:auto; display:block; margin:10px auto; }
        .article-view-content img,
        .article-view-content iframe,
        .article-view-content video { max-width:100%; border-radius:8px; margin:15px 0; }
        #deleteConfirmModal .modal-content { border-radius:16px; }
        #deleteConfirmModal .modal-body   { padding:2rem; }
        #deleteConfirmModal i.bi-exclamation-triangle-fill { animation:pulse-warning 2s infinite; }
        @keyframes pulse-warning { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.1);opacity:.8} }
        #confirmDeleteBtn { min-width:120px; }
        /* Author restrictions: disabled state for published items */
        .btn-disabled-row { opacity:0.5; pointer-events:none; }
CSS;

// layout.php ouvre : <html><head>...</head><body><div class="admin-layout">
//   <aside>...sidebar...</aside><div class="admin-main"><nav>...navbar...</nav>
//   <div class="admin-content">   ← reste ouvert, on continue le contenu ici
require_once __DIR__ . '/layout.php';

if (ob_get_level() > 0) { ob_end_flush(); }
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Articles</h2>
                <button type="button" class="btn btn-gold" id="btnNewArticle">
                    <i class="bi bi-plus-lg"></i> Nouvel article
                </button>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if ($flashError): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($flashError) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Filtres → POST -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="POST" class="row g-3 align-items-end" id="filterForm">
<?= csrf_field() ?>
                        <input type="hidden" name="c" value="app">
                        <input type="hidden" name="a" value="articles">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="category" class="form-select">
                                <option value="">Toutes catégories</option>
                                <option value="data-story"        <?= $category === 'data-story'        ? 'selected' : '' ?>>Data Story</option>
                                <option value="consumer-insights" <?= $category === 'consumer-insights' ? 'selected' : '' ?>>Consumer Insights</option>
                                <option value="intelligence-eco"  <?= $category === 'intelligence-eco'  ? 'selected' : '' ?>>Intelligence Éco</option>
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
                                <th>Image</th>
                                <th>Titre</th>
                                <th>Catégorie</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($articles)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">Aucun article trouvé</td></tr>
                            <?php else: ?>
                            <?php foreach ($articles as $article): 
                                $isAuthorRestricted = $restrictToOwn && $article['status'] === 'published';
                            ?>
                            <tr>
                                <td>
                                    <?php if ($article['image']): ?>
                                    <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($article['image']) ?>" height="40" class="rounded" style="object-fit:cover;width:60px">
                                    <?php else: ?>
                                    <div class="bg-secondary rounded" style="width:60px;height:40px"></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($article['title']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars(truncate($article['excerpt'] ?? '', 60)) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= match($article['category']) {
                                        'data-story'        => 'info',
                                        'consumer-insights' => 'warning',
                                        'intelligence-eco'  => 'primary',
                                        default             => 'secondary'
                                    } ?>">
                                        <?= getCategoryLabel($article['category']) ?>
                                    </span>
                                </td>
                                <td><?= !empty($article['date']) ? date('d/m/Y', strtotime($article['date'])) : date('d/m/Y') ?></td>
                                <td>
                                    <?php if ($article['status'] === 'published'): ?>
                                    <span class="badge badge-published">Publié</span>
                                    <?php else: ?>
                                    <span class="badge badge-draft">Brouillon</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" style="white-space:nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-view py-1 px-2"
                                            data-id="<?= $article['id'] ?>" title="Voir" style="font-size:.75rem">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if (!$isAuthorRestricted): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit py-1 px-2"
                                            data-id="<?= $article['id'] ?>" title="Modifier" style="font-size:.75rem">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete py-1 px-2"
                                            data-id="<?= $article['id'] ?>"
                                            title="Supprimer" style="font-size:.75rem">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php else: ?>
                                    <span class="badge bg-secondary ms-1" title="Article publié — non modifiable">Verrouillé</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination → POST -->
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-3 border-top">
                    <small class="text-muted">
                        Affichage <?= (($page-1)*$perPage)+1 ?> – <?= min($page*$perPage,$total) ?> sur <?= $total ?>
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <button type="button" class="page-link btn-page" data-page="<?= $page-1 ?>">Précédent</button>
                            </li>
                            <?php endif; ?>
                            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                            <li class="page-item <?= $i===$page?'active':'' ?>">
                                <button type="button" class="page-link btn-page" data-page="<?= $i ?>"><?= $i ?></button>
                            </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <button type="button" class="page-link btn-page" data-page="<?= $page+1 ?>">Suivant</button>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div><!-- /table-card -->
        </div><!-- /admin-content -->
    </div><!-- /admin-main -->
</div><!-- /admin-layout -->


<!-- ===== MODAL : FORMULAIRE ARTICLE ===== -->
<div class="modal fade" id="articleModal" tabindex="-1" aria-labelledby="articleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="articleModalLabel">Nouvel article</h5>
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

                <form method="POST" action="articles" enctype="multipart/form-data" id="articleForm">
<?= csrf_field() ?>
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="articles">
                    <input type="hidden" name="save_article" value="1">
                    <input type="hidden" name="edit_id" id="editId" value="">

                    <div class="mb-3">
                        <label class="form-label">Titre *</label>
                        <input type="text" name="title" id="formTitle" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Extrait</label>
                        <textarea name="excerpt" id="formExcerpt" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contenu</label>
                        <textarea name="content" id="summernote"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Catégorie</label>
                            <select name="category" id="formCategory" class="form-select">
                                <option value="data-story">Data Story</option>
                                <option value="consumer-insights">Consumer Insights</option>
                                <option value="intelligence-eco">Intelligence Éco</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Auteur</label>
                            <input type="text" name="author" id="formAuthor" class="form-control" value="Équipe AFRINEX">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Image principale</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div id="currentImage" class="mt-2"></div>
                    </div>
                    <?php if (!$restrictToOwn): ?>
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_published" class="form-check-input" id="formIsPublished" value="1" checked>
                        <label class="form-check-label" for="formIsPublished">Publier immédiatement</label>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info d-flex align-items-center mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>Votre article sera soumis en <strong>brouillon</strong> et examiné par un éditeur avant publication.</small>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBtn">Créer l'article</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR ARTICLE ===== -->
<div class="modal fade" id="viewArticleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTitle">Article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <img id="viewImage" src="" class="w-100 rounded mb-3" style="max-height:300px;object-fit:cover;display:none">
                <span id="viewCategory" class="badge bg-primary mb-2">Catégorie</span>
                <h2 id="viewArticleTitle">Titre</h2>
                <div class="text-muted mb-3">
                    <i class="bi bi-calendar"></i> <span id="viewDate">Date</span>
                    <span class="mx-2">|</span>
                    <i class="bi bi-person"></i> <span id="viewAuthor">Auteur</span>
                </div>
                <p id="viewExcerpt" class="lead fst-italic"></p>
                <hr>
                <div id="viewContent" class="article-view-content"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : CONFIRMATION SUPPRESSION ===== -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xs">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:3rem"></i></div>
                <h5 class="modal-title mb-2">Confirmer la suppression</h5>
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer cet article ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression POST -->
<form id="deleteForm" method="POST" action="articles" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="articles">
    <input type="hidden" name="delete_article" value="1">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour la pagination POST -->
<form id="pageForm" method="POST" action="articles" style="display:none;">
<?= csrf_field() ?>
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="articles">
    <input type="hidden" name="page" id="pageFormPage" value="">
    <input type="hidden" name="search" id="pageFormSearch" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="category" id="pageFormCategory" value="<?= htmlspecialchars($category) ?>">
    <input type="hidden" name="status" id="pageFormStatus" value="<?= htmlspecialchars($status) ?>">
</form>


<!-- FIX scripts — jQuery puis Bootstrap puis Summernote -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>

<script>
/* ─── Sidebar toggle ─── */
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

$(document).ready(function () {

    var pendingImages = [];
    var pendingVideos = [];
    var articleModalInstance = null;
    var viewModalInstance = null;
    var deleteModalInstance = null;

    /* ══════════════════════════════════════════════════════════
       Summernote : initialisé une seule fois au chargement
    ══════════════════════════════════════════════════════════ */
    var summernoteConfig = {
        height: 300,
        placeholder: 'Rédigez le contenu de votre article ici...',
        toolbar: [
            ['style',    ['style']],
            ['font',     ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
            ['fontname', ['fontname']],
            ['color',    ['color']],
            ['para',     ['ul', 'ol', 'paragraph']],
            ['table',    ['table']],
            ['insert',   ['link', 'picture', 'video', 'hr']],
            ['view',     ['fullscreen', 'codeview', 'help']]
        ],
        popover: {
            image: [
                ['image', ['resizeFull', 'resizeHalf', 'resizeQuarter', 'resizeNone']],
                ['float', ['floatLeft', 'floatRight', 'floatNone']],
                ['remove', ['removeMedia']]
            ],
            link:  [['link', ['linkDialogShow', 'unlink']]],
            table: [
                ['add',    ['addRowDown', 'addRowUp', 'addColLeft', 'addColRight']],
                ['delete', ['deleteRow', 'deleteCol', 'deleteTable']]
            ]
        },
        callbacks: {
            onImageUpload: function (files) {
                for (var i = 0; i < files.length; i++) {
                    (function (file) {
                        var reader = new FileReader();
                        reader.onload = function (e) {
                            var base64 = e.target.result;
                            pendingImages.push({ base64: base64, file: file });
                            $('#summernote').summernote('insertImage', base64, function ($img) {
                                $img.css({ 'max-width': '100%', 'height': 'auto', 'display': 'block', 'margin': '10px auto' });
                                $img.addClass('img-fluid').attr('data-pending', 'true');
                            });
                        };
                        reader.readAsDataURL(file);
                    })(files[i]);
                }
            }
        }
    };

    /* Initialiser Summernote une seule fois */
    $('#summernote').summernote(summernoteConfig);
    setTimeout(replaceVideoButton, 150);

    /* ─── Nettoyer le modal à la fermeture ─── */
    $('#articleModal').on('hidden.bs.modal', function () {
        resetForm();
    });

    /* ─── Bouton vidéo personnalisé ─── */
    function replaceVideoButton() {
        var $btn = $('.note-btn[title="Video"], .note-btn[aria-label="Video"]');
        if ($btn.length === 0) $btn = $('.note-icon-video').closest('.note-btn');
        if ($btn.length === 0) { setTimeout(replaceVideoButton, 500); return; }
        $btn.off('click').on('click', function (e) {
            e.preventDefault(); e.stopImmediatePropagation();
            var url = prompt('Collez l\'URL de la vidéo (YouTube, Vimeo, MP4…) :');
            if (!url || !url.trim()) return;
            var videoHtml = createVideoPreview(url.trim());
            $('#summernote').summernote('editor.restoreRange');
            $('#summernote').summernote('editor.focus');
            $('#summernote').summernote('pasteHTML', videoHtml);
            pendingVideos.push({ url: url, insertedAt: new Date().toISOString() });
        });
    }

    function createVideoPreview(url) {
        var youtubeId = extractYouTubeId(url);
        var vimeoId   = extractVimeoId(url);
        var isDirect  = /\.(mp4|webm|ogg)(\?.*)?$/i.test(url);
        if (youtubeId) {
            return '<div class="video-pending" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;background:#000;border-radius:8px;margin:15px 0"><iframe src="https://www.youtube.com/embed/'+youtubeId+'" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none" allowfullscreen></iframe></div><p></p>';
        }
        if (vimeoId) {
            return '<div class="video-pending" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;background:#000;border-radius:8px;margin:15px 0"><iframe src="https://player.vimeo.com/video/'+vimeoId+'" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none" allowfullscreen></iframe></div><p></p>';
        }
        if (isDirect) {
            var ext = url.match(/\.(mp4|webm|ogg)/i)[1].toLowerCase();
            var mime = ext==='mp4'?'video/mp4':ext==='webm'?'video/webm':'video/ogg';
            return '<div class="video-pending" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;background:#000;border-radius:8px;margin:15px 0"><video controls style="position:absolute;top:0;left:0;width:100%;height:100%"><source src="'+escapeHtml(url)+'" type="'+mime+'"></video></div><p></p>';
        }
        return '<div class="video-pending" style="background:#f8f9fa;border:2px dashed #c9a96e;border-radius:8px;padding:20px;text-align:center;margin:15px 0"><span style="font-size:32px">🎬</span><p style="margin:10px 0 0;color:#666">Lien vidéo : <a href="'+escapeHtml(url)+'" target="_blank" style="color:#c9a96e">'+escapeHtml(url)+'</a></p></div><p></p>';
    }

    function extractYouTubeId(url) {
        var m = url.match(/^.*(youtu\.be\/|v\/|embed\/|watch\?v=|&v=)([^#&?]*).*/);
        return (m && m[2].length===11) ? m[2] : null;
    }
    function extractVimeoId(url) {
        var m = url.match(/vimeo\.com\/(\d+)/);
        return m ? m[1] : null;
    }
    function escapeHtml(text) {
        var d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    /* ─── Nouvel article ─── */
    $('#btnNewArticle').on('click', function () {
        resetForm();
        $('#articleModalLabel').text('Nouvel article');
        $('#submitBtn').text("Créer l'article");
        if (!articleModalInstance) {
            articleModalInstance = new bootstrap.Modal(document.getElementById('articleModal'));
        }
        articleModalInstance.show();
    });

    /* ══════════════════════════════════════════════════════════
       AJAX — Voir article  →  POST
    ══════════════════════════════════════════════════════════ */
    $(document).on('click', '.btn-view', function () {
        var id = $(this).data('id');
        $.ajax({
            url: 'articles',
            type: 'POST',
            data: { c: 'app', a: 'articles', action: 'get_article', id: id, csrf_token: '<?= csrf_token() ?>' },
            dataType: 'json',
            success: function (response) {
                if (response.success && response.data) {
                    var a = response.data;
                    $('#viewTitle').text(a.title);
                    $('#viewArticleTitle').text(a.title);
                    $('#viewCategory').text(a.category_label || a.category)
                        .removeClass().addClass('badge bg-' + getCategoryColor(a.category));
                    $('#viewDate').text(a.published_at_formatted || a.date);
                    $('#viewAuthor').text(a.author || 'AFRINEX Research');
                    $('#viewExcerpt').text(a.excerpt || '');
                    if (a.image) {
                        $('#viewImage').attr('src', '../uploads/images/' + a.image).show();
                    } else {
                        $('#viewImage').hide();
                    }
                    var content = (a.content || '')
                        .replace(/src="uploads\/images\//g,    'src="../uploads/images/')
                        .replace(/src="\.\/uploads\/images\//g,'src="../uploads/images/');
                    $('#viewContent').html(content);
                    if (!viewModalInstance) {
                        viewModalInstance = new bootstrap.Modal(document.getElementById('viewArticleModal'));
                    }
                    viewModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Article non trouvé'));
                }
            },
            error: function (xhr, status, error) {
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

    /* ══════════════════════════════════════════════════════════
       AJAX — Modifier article  →  POST
    ══════════════════════════════════════════════════════════ */
    $(document).on('click', '.btn-edit', function () {
        var id = $(this).data('id');
        $.ajax({
            url: 'articles',
            type: 'POST',
            data: { c: 'app', a: 'articles', action: 'get_article', id: id, csrf_token: '<?= csrf_token() ?>' },
            dataType: 'json',
            success: function (response) {
                if (response.success && response.data) {
                    var a = response.data;
                    $('#editId').val(a.id);
                    $('#formTitle').val(a.title);
                    $('#formExcerpt').val(a.excerpt || '');
                    $('#formCategory').val(a.category);
                    $('#formAuthor').val(a.author || 'Équipe AFRINEX');
                    $('#formIsPublished').prop('checked', a.status === 'published');
                    if (a.image) {
                        $('#currentImage').html('<img src="../uploads/images/' + a.image + '" height="100" class="rounded"><small class="text-muted ms-2">Image actuelle</small>');
                    } else {
                        $('#currentImage').html('');
                    }
                    $('#articleModalLabel').text("Modifier l'article");
                    $('#submitBtn').text('Mettre à jour');

                    var content = (a.content || '')
                        .replace(/src="uploads\/images\//g,    'src="../uploads/images/')
                        .replace(/src="\.\/uploads\/images\//g,'src="../uploads/images/');
                    $('#summernote').summernote('code', content);

                    if (!articleModalInstance) {
                        articleModalInstance = new bootstrap.Modal(document.getElementById('articleModal'));
                    }
                    articleModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Article non trouvé'));
                }
            },
            error: function (xhr, status, error) {
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

    /* ─── Couleur badge ─── */
    function getCategoryColor(cat) {
        return { 'data-story':'info', 'consumer-insights':'warning', 'intelligence-eco':'primary' }[cat] || 'secondary';
    }

    /* ─── Reset formulaire ─── */
    function resetForm() {
        $('#articleForm')[0].reset();
        $('#editId').val('');
        $('#currentImage').html('');
        $('#formErrors').remove();
        $('#summernote').summernote('code', '');
        pendingImages = [];
    }

    /* ─── Submit formulaire ─── */
    $('#articleForm').on('submit', function (e) {
        e.preventDefault();
        var content = $('#summernote').summernote('code');
        var form = this;
        if (content.includes('data:image')) {
            uploadPendingImages(content, function (html) {
                var final = processVideos(html)
                    .replace(/src="\.\.\/uploads\/images\/editor\//g, 'src="uploads/images/editor/');
                $('#summernote').val(final);
                form.submit();
            });
        } else {
            var final = processVideos(content)
                .replace(/src="\.\.\/uploads\/images\/editor\//g, 'src="uploads/images/editor/');
            $('#summernote').val(final);
            form.submit();
        }
    });

    function processVideos(html) {
        var d = document.createElement('div');
        d.innerHTML = html;
        d.querySelectorAll('.video-pending').forEach(function (v) {
            v.classList.replace('video-pending', 'video-embed');
        });
        return d.innerHTML;
    }

    /* ─── Upload des images de l'éditeur  →  POST ─── */
    function uploadPendingImages(html, callback) {
        if (pendingImages.length === 0) { callback(html); return; }
        var newHtml = html, done = 0;
        var $btn = $('#articleForm button[type="submit"]');
        var origText = $btn.text();
        $btn.prop('disabled', true).text('Upload en cours...');

        pendingImages.forEach(function (imgData) {
            var parts  = imgData.base64.split(',');
            var mime   = parts[0].match(/:(.*?);/)[1];
            var bytes  = atob(parts[1]);
            var ab     = new ArrayBuffer(bytes.length);
            var ua     = new Uint8Array(ab);
            for (var i = 0; i < bytes.length; i++) ua[i] = bytes.charCodeAt(i);
            var fd = new FormData();
            fd.append('file', new File([ab], imgData.file.name, { type: mime }));
            fd.append('c', 'app');
            fd.append('a', 'articles');
            fd.append('action', 'upload_editor_image');

            fd.append('csrf_token', '<?= csrf_token() ?>');

            $.ajax({
                url: 'articles',
                type: 'POST',
                data: fd,
                contentType: false,
                processData: false,
                success: function (r) {
                    if (r.success && r.url) {
                        var escaped = imgData.base64.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        newHtml = newHtml.replace(new RegExp(escaped, 'g'), r.url);
                    }
                    if (++done === pendingImages.length) {
                        $btn.prop('disabled', false).text(origText);
                        pendingImages = [];
                        callback(newHtml);
                    }
                },
                error: function () {
                    if (++done === pendingImages.length) {
                        $btn.prop('disabled', false).text(origText);
                        callback(newHtml);
                    }
                }
            });
        });
    }

    /* ─── Suppression avec confirmation  →  POST ─── */
    var deleteId = null;
    $(document).on('click', '.btn-delete', function () {
        deleteId = $(this).data('id');
        if (!deleteModalInstance) {
            deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        }
        deleteModalInstance.show();
    });

    $('#confirmDeleteBtn').on('click', function () {
        if (deleteId) {
            $('#deleteFormId').val(deleteId);
            $('#deleteForm').submit();
        }
    });

    /* ─── Pagination  →  POST ─── */
    $(document).on('click', '.btn-page', function () {
        var page = $(this).data('page');
        $('#pageFormPage').val(page);
        $('#pageForm').submit();
    });

});
</script>
</body>
</html>