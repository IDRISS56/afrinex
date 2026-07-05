<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'cases');

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
// AJAX : Récupérer une étude de cas  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_case' && isset($_POST['id'])) {
    try {
        $id = (int)$_POST['id'];
        if ($id <= 0) {
            throw new Exception('ID invalide');
        }
        $case = $db->fetchOne("SELECT * FROM content WHERE id = ? AND type = 'case_study'", [$id]);
        if ($case) {
            $case['status_label'] = ($case['status'] === 'published') ? 'Publié' : 'Brouillon';
            $case['status_badge'] = ($case['status'] === 'published') ? 'badge-published' : 'badge-draft';
            $case['published_at_formatted'] = !empty($case['date']) ? date('d/m/Y', strtotime($case['date'])) : date('d/m/Y');
            jsonResponse(['success' => true, 'data' => $case]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Étude de cas introuvable']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_case']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $case = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'case_study'", [$id]);
        if ($case && $case['image']) {
            $imgPath = __DIR__ . '/../uploads/images/' . $case['image'];
            if (file_exists($imgPath)) {
                unlink($imgPath);
            }
        }
        $db->delete('content', 'id = ? AND type = ?', [$id, 'case_study']);
    }
    $_SESSION['flash_success'] = 'Étude de cas supprimée avec succès';
    header('Location: ' . BASE_ROUTE);
    exit;
}

// ═══════════════════════════════════════════════════════════
// PAGINATION, FILTRES, RECHERCHE  →  POST
// ═══════════════════════════════════════════════════════════
$page = max(1, intval($_POST['page'] ?? $_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$search = trim($_POST['search'] ?? $_GET['search'] ?? '');
$status = trim($_POST['status'] ?? $_GET['status'] ?? '');

$where = ["type = 'case_study'"];
$params = [];

if ($search) {
    $where[] = "(title LIKE ? OR subtitle LIKE ? OR context LIKE ?)";
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

// Récupération des études de cas
$sql = "SELECT * FROM content WHERE $whereClause ORDER BY date DESC LIMIT $perPage OFFSET $offset";
$cases = $db->fetchAll($sql, $params);

// Flash messages
$success = false;
$successMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $success = true;
    $successMessage = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE (CREATE/UPDATE)  →  POST
// ═══════════════════════════════════════════════════════════
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_case'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;

    $title = trim($_POST['title'] ?? '');
    $slug = slugify($title);
    $subtitle = trim($_POST['subtitle'] ?? '');
    $period = trim($_POST['period'] ?? '');
    $context = trim($_POST['context'] ?? '');
    $approach = trim($_POST['approach'] ?? '');
    $impact = trim($_POST['impact'] ?? '');
    $precision = trim($_POST['precision'] ?? '');
    $precision_label = trim($_POST['precision_label'] ?? '');
    $countries = isset($_POST['countries']) ? (int)$_POST['countries'] : 0;
    $personas = isset($_POST['personas']) ? (int)$_POST['personas'] : 0;
    $status = isset($_POST['is_published']) ? 'published' : 'draft';

    // Gestion image
    $image = null;
    if ($editId) {
        $existing = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'case_study'", [$editId]);
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
                    title = ?, slug = ?, subtitle = ?, period = ?, context = ?,
                    approach = ?, impact = ?, `precision` = ?, precision_label = ?,
                    countries = ?, personas = ?, image = ?, status = ?, mise_ajour = NOW()
                WHERE id = ? AND type = 'case_study'
            ", [$title, $slug, $subtitle, $period, $context, $approach, $impact, $precision, $precision_label, $countries, $personas, $image, $status, $editId]);
        } else {
            $db->query("
                INSERT INTO content
                    (title, slug, subtitle, period, context, approach, impact, `precision`, precision_label, countries, personas, image, status, type, user_id, date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'case_study', ?, NOW())
            ", [$title, $slug, $subtitle, $period, $context, $approach, $impact, $precision, $precision_label, $countries, $personas, $image, $status, (int)($_SESSION['user_id'] ?? 1)]);
        }
        $_SESSION['flash_success'] = 'Opération réalisée avec succès';
        header('Location: ' . BASE_ROUTE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// LAYOUT : titre de page + assets spécifiques à Études de cas
// ═══════════════════════════════════════════════════════════
$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
)['count'] ?? 0);

$pageTitle = 'Études de cas';
$pageIcon  = 'bi-briefcase';

// CSS/JS propres à cette page (Summernote), injectés par layout.php avant </head>
$extraHead = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
HTML;

// Styles propres à Études de cas (le socle sidebar/navbar/layout est déjà géré par layout.php)
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
        .search-box { max-width: 300px; }
        #deleteConfirmModal .modal-content {
            border-radius: 16px;
        }
        #deleteConfirmModal .modal-body {
            padding: 2rem;
        }
        #deleteConfirmModal i.bi-exclamation-triangle-fill {
            animation: pulse-warning 2s infinite;
        }
        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        #confirmDeleteBtn {
            min-width: 120px;
        }
        .stat-badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
        }
CSS;

// layout.php ouvre : <html><head>...</head><body><div class="admin-layout">
//   <aside>...sidebar...</aside><div class="admin-main"><nav>...navbar...</nav>
//   <div class="admin-content">   ← reste ouvert, on continue le contenu ici
require_once __DIR__ . '/layout.php';

if (ob_get_level() > 0) { ob_end_flush(); }
?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Études de cas</h2>
                <button type="button" class="btn btn-gold" id="btnNewCase">
                    <i class="bi bi-plus-lg"></i> Nouvelle étude
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
                        <input type="hidden" name="a" value="cases">
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
                                <th>Image</th>
                                <th>Titre</th>
                                <th>Période</th>
                                <th>Stats</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cases)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">Aucune étude de cas trouvée</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($cases as $case): ?>
                            <tr>
                                <td>
                                    <?php if ($case['image']): ?>
                                    <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($case['image']) ?>" height="40" class="rounded" style="object-fit: cover; width: 60px;">
                                    <?php else: ?>
                                    <div class="bg-secondary rounded" style="width: 60px; height: 40px;"></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-medium"><?= htmlspecialchars($case['title']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars(truncate($case['subtitle'] ?? '', 60)) ?></small>
                                </td>
                                <td>
                                    <?= htmlspecialchars($case['period'] ?? '—') ?>
                                </td>
                                <td>
                                    <?php if ($case['precision']): ?>
                                    <span class="badge bg-info stat-badge"><?= htmlspecialchars($case['precision']) ?> <?= htmlspecialchars($case['precision_label'] ?? '') ?></span>
                                    <?php endif; ?>
                                    <?php if ($case['countries']): ?>
                                    <span class="badge bg-warning stat-badge"><?= (int)$case['countries'] ?> pays</span>
                                    <?php endif; ?>
                                    <?php if ($case['personas']): ?>
                                    <span class="badge bg-success stat-badge"><?= (int)$case['personas'] ?> personas</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= !empty($case['date']) ? date('d/m/Y', strtotime($case['date'])) : date('d/m/Y') ?>
                                </td>
                                <td>
                                    <?php if ($case['status'] === 'published'): ?>
                                    <span class="badge badge-published">Publié</span>
                                    <?php else: ?>
                                    <span class="badge badge-draft">Brouillon</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-view py-1 px-2" data-id="<?= $case['id'] ?>" title="Voir" style="font-size:0.75rem"><i class="bi bi-eye"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit py-1 px-2" data-id="<?= $case['id'] ?>" title="Modifier" style="font-size:0.75rem"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete py-1 px-2" data-id="<?= $case['id'] ?>" title="Supprimer" style="font-size:0.75rem"><i class="bi bi-trash"></i></button>
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

<!-- ===== MODAL : FORMULAIRE ÉTUDE DE CAS (Création & Modification) ===== -->
<div class="modal fade" id="caseModal" tabindex="-1" aria-labelledby="caseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="caseModalLabel">Nouvelle étude de cas</h5>
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
                <form method="POST" action="cases" enctype="multipart/form-data" id="caseForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="cases">
                    <input type="hidden" name="save_case" value="1">
                    <input type="hidden" name="edit_id" id="editId" value="">

                    <div class="mb-3">
                        <label class="form-label">Titre principal *</label>
                        <input type="text" name="title" id="formTitle" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Sous-titre / Badge</label>
                        <input type="text" name="subtitle" id="formSubtitle" class="form-control" placeholder="Ex: Étude phare 2024">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Période</label>
                        <input type="text" name="period" id="formPeriod" class="form-control" placeholder="Ex: Jan-Mars 2024">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Contexte & Enjeux</label>
                        <textarea name="context" id="summernoteContext"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notre approche</label>
                        <textarea name="approach" id="summernoteApproach"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Impact mesurable</label>
                        <textarea name="impact" id="summernoteImpact"></textarea>
                    </div>

                    <!-- Stats -->
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Stat principale - Valeur</label>
                            <input type="text" name="precision" id="formPrecision" class="form-control" placeholder="Ex: 94%">
                            <input type="text" name="precision_label" id="formPrecisionLabel" class="form-control mt-1" placeholder="Label (ex: Précision prédictive)">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Pays couverts</label>
                            <input type="number" name="countries" id="formCountries" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Personas</label>
                            <input type="number" name="personas" id="formPersonas" class="form-control" min="0" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Image</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div id="currentImage" class="mt-2"></div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_published" class="form-check-input" id="formIsPublished" value="1" checked>
                        <label class="form-check-label" for="formIsPublished">Publier immédiatement</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBtn">Créer l'étude</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR ÉTUDE DE CAS ===== -->
<div class="modal fade" id="viewCaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTitle">Étude de cas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <img id="viewImage" src="" class="w-100 rounded mb-3" style="max-height: 300px; object-fit: cover; display: none;">
                <span id="viewSubtitle" class="badge bg-warning mb-2">Badge</span>
                <h2 id="viewCaseTitle">Titre</h2>
                <div class="text-muted mb-3">
                    <i class="bi bi-calendar"></i> <span id="viewDate">Date</span>
                    <span class="mx-2">|</span>
                    <i class="bi bi-clock"></i> <span id="viewPeriod">Période</span>
                </div>
                <div id="viewStats" class="mb-3"></div>
                <hr>
                <h5><i class="bi bi-clipboard-data text-primary"></i> Contexte & Enjeux</h5>
                <div id="viewContext" class="mb-3"></div>
                <h5><i class="bi bi-gear text-primary"></i> Notre approche</h5>
                <div id="viewApproach" class="mb-3"></div>
                <h5><i class="bi bi-graph-up-arrow text-success"></i> Impact mesurable</h5>
                <div id="viewImpact" class="mb-3"></div>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer cette étude de cas ?<br>Cette action est irréversible.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression POST -->
<form id="deleteForm" method="POST" action="cases" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="cases">
    <input type="hidden" name="delete_case" value="1">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour la pagination POST (avec conservation des filtres) -->
<form id="pageForm" method="POST" action="cases" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="cases">
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

    var caseModalInstance = null;
    var viewModalInstance = null;
    var deleteModalInstance = null;

    // ===== INITIALISER SUMMERNOTE =====
    var summernoteConfig = {
        height: 200,
        placeholder: 'Rédigez ici...',
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', ['link', 'hr']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ]
    };

    $('#summernoteContext').summernote(summernoteConfig);
    $('#summernoteApproach').summernote(summernoteConfig);
    $('#summernoteImpact').summernote(summernoteConfig);

    // ===== NOUVELLE ÉTUDE =====
    $('#btnNewCase').on('click', function() {
        resetForm();
        $('#caseModalLabel').text('Nouvelle étude de cas');
        $('#submitBtn').text('Créer l\'étude');
        if (!caseModalInstance) {
            caseModalInstance = new bootstrap.Modal(document.getElementById('caseModal'));
        }
        caseModalInstance.show();
    });

    // ===== VOIR ÉTUDE  →  POST =====
    $(document).on('click', '.btn-view', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'cases',
            type: 'POST',
            data: { c: 'app', a: 'cases', action: 'get_case', id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var c = response.data;
                    $('#viewTitle').text(c.title);
                    $('#viewCaseTitle').text(c.title);
                    $('#viewSubtitle').text(c.subtitle || 'Étude de cas').toggle(!!c.subtitle);
                    $('#viewDate').text(c.published_at_formatted || c.date);
                    $('#viewPeriod').text(c.period || '—');

                    // Stats
                    var statsHtml = '';
                    if (c.precision) {
                        statsHtml += '<span class="badge bg-info me-1">' + escapeHtml(c.precision) + ' ' + escapeHtml(c.precision_label || '') + '</span>';
                    }
                    if (c.countries) {
                        statsHtml += '<span class="badge bg-warning me-1">' + c.countries + ' pays</span>';
                    }
                    if (c.personas) {
                        statsHtml += '<span class="badge bg-success">' + c.personas + ' personas</span>';
                    }
                    $('#viewStats').html(statsHtml || '<span class="text-muted">Aucune statistique</span>');

                    if (c.image) {
                        $('#viewImage').attr('src', '../uploads/images/' + c.image).show();
                    } else {
                        $('#viewImage').hide();
                    }

                    $('#viewContext').html(c.context || '<span class="text-muted">Non renseigné</span>');
                    $('#viewApproach').html(c.approach || '<span class="text-muted">Non renseigné</span>');
                    $('#viewImpact').html(c.impact || '<span class="text-muted">Non renseigné</span>');

                    if (!viewModalInstance) {
                        viewModalInstance = new bootstrap.Modal(document.getElementById('viewCaseModal'));
                    }
                    viewModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Étude non trouvée'));
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

    // ===== MODIFIER ÉTUDE  →  POST =====
    $(document).on('click', '.btn-edit', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'cases',
            type: 'POST',
            data: { c: 'app', a: 'cases', action: 'get_case', id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var c = response.data;
                    $('#editId').val(c.id);
                    $('#formTitle').val(c.title);
                    $('#formSubtitle').val(c.subtitle || '');
                    $('#formPeriod').val(c.period || '');
                    $('#summernoteContext').summernote('code', c.context || '');
                    $('#summernoteApproach').summernote('code', c.approach || '');
                    $('#summernoteImpact').summernote('code', c.impact || '');
                    $('#formPrecision').val(c.precision || '');
                    $('#formPrecisionLabel').val(c.precision_label || '');
                    $('#formCountries').val(c.countries || 0);
                    $('#formPersonas').val(c.personas || 0);
                    $('#formIsPublished').prop('checked', c.status === 'published');

                    if (c.image) {
                        $('#currentImage').html('<img src="../uploads/images/' + c.image + '" height="100" class="rounded"><small class="text-muted ms-2">Image actuelle</small>');
                    } else {
                        $('#currentImage').html('');
                    }

                    $('#caseModalLabel').text('Modifier l\'étude de cas');
                    $('#submitBtn').text('Mettre à jour');
                    if (!caseModalInstance) {
                        caseModalInstance = new bootstrap.Modal(document.getElementById('caseModal'));
                    }
                    caseModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Étude non trouvée'));
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

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function resetForm() {
        $('#caseForm')[0].reset();
        $('#editId').val('');
        $('#currentImage').html('');
        $('#formErrors').remove();
        $('#summernoteContext').summernote('code', '');
        $('#summernoteApproach').summernote('code', '');
        $('#summernoteImpact').summernote('code', '');
    }

    $('#caseModal').on('hidden.bs.modal', function() {
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
    $(document).on('click', '.btn-page', function() {
        var page = $(this).data('page');
        $('#pageFormPage').val(page);
        $('#pageForm').submit();
    });
});
</script>
</body>
</html>