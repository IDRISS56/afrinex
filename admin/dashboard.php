<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'dashboard');

$db = Database::getInstance();

// ═══════════════════════════════════════════════════════════
// HELPER : envoyer du JSON propre
// ═══════════════════════════════════════════════════════════
function jsonResponse(array $data): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ═══════════════════════════════════════════════════════════
// GESTION AJAX : Récupérer un article au format JSON
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_article' && isset($_GET['id'])) {
    try {
        $id = (int)$_GET['id'];
        if ($id <= 0) throw new Exception('ID invalide');
        $article = $db->fetchOne("SELECT * FROM content WHERE id = ? AND type = 'article'", [$id]);
        if ($article) {
            jsonResponse(['success' => true, 'data' => $article]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Article introuvable']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE ARTICLE (CREATE/UPDATE)  →  POST
// ═══════════════════════════════════════════════════════════
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;
    $title = trim($_POST['title'] ?? '');
    $slug = slugify($title);
    $excerpt = trim($_POST['excerpt'] ?? '');
    $content_body = trim($_POST['content'] ?? '');
    $category = $_POST['category'] ?? 'Data Story';
    $author = trim($_POST['author'] ?? 'Équipe AFRINEX');
    $status = isset($_POST['is_published']) ? 'published' : 'draft';

    // Gestion image
    $image = null;
    if ($editId) {
        $existing = $db->fetchOne("SELECT image FROM content WHERE id = ? AND type = 'article'", [$editId]);
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
        $errors[] = "Le titre est obligatoire.";
    }

    if (empty($errors)) {
        if ($editId) {
            $db->query("
                UPDATE content SET
                    title = ?, slug = ?, excerpt = ?, content = ?,
                    category = ?, image = ?, author = ?, status = ?, mise_ajour = NOW()
                WHERE id = ? AND type = 'article'
            ", [$title, $slug, $excerpt, $content_body, $category, $image, $author, $status, $editId]);
        } else {
            $db->query("
                INSERT INTO content
                    (title, slug, excerpt, content, category, image, author, status, type, user_id, date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'article', ?, NOW())
            ", [$title, $slug, $excerpt, $content_body, $category, $image, $author, $status, (int)($_SESSION['user_id'] ?? 1)]);
        }
        header('Location: ' . BASE_ROUTE . '?success=1');
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// STATS & DONNÉES
// ═══════════════════════════════════════════════════════════
$unreadCount = (int)($db->fetchOne(
    "SELECT COUNT(*) as count FROM contacts WHERE is_read = 0 AND type = 'message'"
)['count'] ?? 0);

$stats = [
    'articles'     => (int)($db->fetchOne("SELECT COUNT(*) as count FROM content WHERE type = 'article'")['count'] ?? 0),
    'published'    => (int)($db->fetchOne("SELECT COUNT(*) as count FROM content WHERE type = 'article' AND status = 'published'")['count'] ?? 0),
    'services'     => (int)($db->fetchOne("SELECT COUNT(*) as count FROM content WHERE type = 'service'")['count'] ?? 0),
    'temoignages'  => (int)($db->fetchOne("SELECT COUNT(*) as count FROM content WHERE type = 'temoignage'")['count'] ?? 0),
    'case_studies' => (int)($db->fetchOne("SELECT COUNT(*) as count FROM content WHERE type = 'case_study'")['count'] ?? 0),
    'contacts'     => (int)($db->fetchOne("SELECT COUNT(*) as count FROM contacts WHERE type = 'message'")['count'] ?? 0),
    'unread'       => $unreadCount,
    'subscribers'  => (int)($db->fetchOne("SELECT COUNT(*) as count FROM contacts WHERE type = 'subscriber'")['count'] ?? 0),
];

// Métriques BI
$biMetrics = $db->fetchAll("
    SELECT * FROM bi_metrics WHERE status = 1 ORDER BY sort_order ASC
");

// Données graphiques (contacts par mois)
$contactsByMonth = $db->fetchAll("
    SELECT DATE_FORMAT(date, '%b') as month, COUNT(*) as count
    FROM contacts
    WHERE date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY MONTH(date)
    ORDER BY MIN(date)
");
$lineLabels = array_column($contactsByMonth, 'month') ?: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin'];
$lineData   = array_column($contactsByMonth, 'count') ?: [12, 19, 15, 25, 22, 30];

// Répartition des articles par catégorie
$articlesByCategory = $db->fetchAll("
    SELECT category, COUNT(*) as count
    FROM content
    WHERE type = 'article' AND category IS NOT NULL
    GROUP BY category
    ORDER BY count DESC
");
$donutLabels = array_column($articlesByCategory, 'category') ?: ['Data Story', 'Consumer Insights', 'Intelligence Éco'];
$donutData   = array_column($articlesByCategory, 'count') ?: [3, 2, 2];

// Articles récents
$recentArticles = $db->fetchAll("
    SELECT id, title, category, status, date
    FROM content
    WHERE type = 'article'
    ORDER BY id DESC
    LIMIT 5
");

// Messages récents non lus
$recentMessages = $db->fetchAll("
    SELECT id, name, firstname, email, company, study_type, message, date
    FROM contacts
    WHERE type = 'message' AND is_read = 0
    ORDER BY date DESC
    LIMIT 4
");

$success = isset($_GET['success']);

// Helper : badge couleur par catégorie
function categoryBadge(string $cat): string {
    return match ($cat) {
        'Data Story'        => 'info',
        'Consumer Insights' => 'warning',
        'Intelligence Éco'  => 'primary',
        default             => 'secondary',
    };
}

// ═══════════════════════════════════════════════════════════
// LAYOUT : titre de page + assets spécifiques au dashboard
// ═══════════════════════════════════════════════════════════
$pageTitle = 'Tableau de bord';
$pageIcon  = 'bi-grid-1x2-fill';

// CSS/JS propres à cette page (Chart.js, Summernote), injectés par layout.php avant </head>
$extraHead = <<<HTML
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
HTML;

// Styles propres au dashboard (le socle sidebar/navbar/layout est déjà géré par layout.php)
$extraStyles = <<<CSS
        /* ═══════════════ STAT CARDS ═══════════════ */
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.25rem 1.4rem;
            border: 1px solid #e1e4e8;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: box-shadow .2s;
        }
        .stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
        .stat-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--navy);
            line-height: 1;
        }
        .stat-label { color: #6b7280; font-size: .8rem; margin-top: .2rem; }
        .stat-sub   { font-size: .75rem; color: #9ca3af; margin-top: .15rem; }

        /* ═══════════════ BI METRICS ═══════════════ */
        .bi-metric-card {
            background: var(--navy);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            color: #fff;
            border: 1px solid rgba(255,255,255,.08);
        }
        .bi-metric-value { font-size: 1.6rem; font-weight: 800; color: var(--gold); }
        .bi-metric-label { font-size: .78rem; opacity: .7; margin-top: .15rem; }
        .bi-metric-change { font-size: .75rem; margin-top: .3rem; }
        .change-up   { color: #34d399; }
        .change-down { color: #f87171; }

        /* ═══════════════ CHART CARDS ═══════════════ */
        .chart-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e1e4e8;
            padding: 1.25rem 1.5rem;
        }
        .chart-card-title    { font-weight: 600; color: var(--navy); font-size: .92rem; }
        .chart-card-subtitle { font-size: .8rem; color: #9ca3af; }
        .chart-container    { position: relative; height: 270px; }
        .chart-container-sm { position: relative; height: 230px; }

        /* ═══════════════ MESSAGES PANEL ═══════════════ */
        .msg-item {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            padding: .75rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .msg-item:last-child { border-bottom: none; }
        .msg-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--navy);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem; font-weight: 700;
            flex-shrink: 0;
        }
        .msg-name    { font-weight: 600; font-size: .85rem; color: var(--navy); }
        .msg-preview { font-size: .78rem; color: #6b7280; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; max-width: 220px; }
        .msg-time    { font-size: .72rem; color: #9ca3af; white-space: nowrap; }

        /* ═══════════════ TABLE ═══════════════ */
        .table > thead th { font-size: .8rem; font-weight: 600; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .table > tbody td { font-size: .85rem; vertical-align: middle; }

        /* ═══════════════ BUTTON GOLD ═══════════════ */
        .btn-gold { background: var(--gold); color: #fff; border: none; }
        .btn-gold:hover { background: #b8921f; color: #fff; }

        /* ═══════════════ RESPONSIVE ═══════════════ */
        @media (max-width: 768px) {
            .admin-content { padding: 1rem; }
            .stat-card { padding: 0.8rem 1rem; }
            .stat-value { font-size: 1.3rem; }
            .stat-icon { width: 38px; height: 38px; font-size: 1rem; }
        }

        /* Summernote */
        .note-editor .note-editable img { max-width: 100%; height: auto; display: block; margin: 10px auto; }
        .video-pending, .video-embed {
            position: relative; padding-bottom: 56.25%; height: 0;
            overflow: hidden; border-radius: 8px; margin: 15px 0; background: #000;
        }
        .video-pending iframe, .video-pending video,
        .video-embed iframe,   .video-embed video {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none;
        }
CSS;

// layout.php ouvre : <html><head>...</head><body><div class="admin-layout">
//   <aside>...sidebar...</aside><div class="admin-main"><nav>...navbar...</nav>
//   <div class="admin-content">   ← reste ouvert, on continue le contenu ici
require_once __DIR__ . '/layout.php';

if (ob_get_level() > 0) { ob_end_flush(); }
?>
            <!-- Alerte succès -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show mb-3">
                <i class="bi bi-check-circle-fill me-2"></i>Opération réalisée avec succès.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- ─── LIGNE 1 : Stat cards principales ─── -->
            <div class="row g-3 mb-3">

                <!-- Articles -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#eff6ff;color:#3b82f6">
                            <i class="bi bi-file-text-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['articles'] ?></div>
                            <div class="stat-label">Articles</div>
                            <div class="stat-sub"><?= $stats['published'] ?> publiés</div>
                        </div>
                    </div>
                </div>

                <!-- Services -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4;color:#22c55e">
                            <i class="bi bi-postcard-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['services'] ?></div>
                            <div class="stat-label">Services</div>
                        </div>
                    </div>
                </div>

                <!-- Témoignages -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fdf4ff;color:#a855f7">
                            <i class="bi bi-chat-quote-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['temoignages'] ?></div>
                            <div class="stat-label">Témoignages</div>
                        </div>
                    </div>
                </div>

                <!-- Études de cas -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fff7ed;color:#f97316">
                            <i class="bi bi-briefcase-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['case_studies'] ?></div>
                            <div class="stat-label">Études de cas</div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card" style="<?= $stats['unread'] > 0 ? 'border-color:#fca5a5' : '' ?>">
                        <div class="stat-icon" style="background:#fef2f2;color:#ef4444">
                            <i class="bi bi-envelope-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['contacts'] ?></div>
                            <div class="stat-label">Messages</div>
                            <?php if ($stats['unread'] > 0): ?>
                            <div class="stat-sub" style="color:#ef4444;font-weight:600"><?= $stats['unread'] ?> non lus</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Abonnés -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#f0fdf4;color:#10b981">
                            <i class="bi bi-person-check-fill"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $stats['subscribers'] ?></div>
                            <div class="stat-label">Abonnés</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── LIGNE 2 : KPI BI Metrics ─── -->
            <?php if (!empty($biMetrics)): ?>
            <div class="row g-3 mb-3">
                <?php foreach ($biMetrics as $kpi): ?>
                <div class="col-6 col-md-3">
                    <div class="bi-metric-card">
                        <div class="d-flex align-items-center gap-2 mb-1" style="opacity:.7;font-size:.78rem">
                            <i class="fas <?= htmlspecialchars($kpi['icon']) ?>"></i>
                            <?= htmlspecialchars($kpi['label']) ?>
                        </div>
                        <div class="bi-metric-value"><?= htmlspecialchars($kpi['value']) ?></div>
                        <?php if ($kpi['change']): 
                            $isPositive = str_starts_with(ltrim($kpi['change']), '+');
                        ?>
                        <div class="bi-metric-change <?= $isPositive ? 'change-up' : 'change-down' ?>">
                            <i class="bi bi-arrow-<?= $isPositive ? 'up' : 'down' ?>-right-circle-fill me-1"></i>
                            <?= htmlspecialchars($kpi['change']) ?> ce mois
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ─── LIGNE 3 : Graphiques ─── -->
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="chart-card">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <div class="chart-card-title">Évolution des contacts</div>
                                <div class="chart-card-subtitle">Demandes reçues sur 6 mois</div>
                            </div>
                            <a href="contacts" class="btn btn-sm btn-outline-secondary btn-sm">
                                Voir tout
                            </a>
                        </div>
                        <div class="chart-container">
                            <canvas id="lineChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="chart-card">
                        <div class="mb-3">
                            <div class="chart-card-title">Répartition articles</div>
                            <div class="chart-card-subtitle">Par catégorie</div>
                        </div>
                        <div class="chart-container-sm">
                            <canvas id="donutChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── LIGNE 4 : Articles récents + Messages non lus ─── -->
            <div class="row g-3">

                <!-- Articles récents -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-3 px-4">
                            <h6 class="mb-0 fw-bold">Articles récents</h6>
                            <button type="button" class="btn btn-sm btn-gold" id="btnNewArticle">
                                <i class="bi bi-plus-lg me-1"></i>Nouvel article
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th class="px-4">Titre</th>
                                            <th>Catégorie</th>
                                            <th>Statut</th>
                                            <th class="text-end pe-4">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recentArticles)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                <i class="bi bi-file-earmark-x fs-3 d-block mb-2 opacity-30"></i>
                                                Aucun article pour l'instant
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($recentArticles as $article): ?>
                                        <tr>
                                            <td class="px-4">
                                                <div style="max-width:220px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">
                                                    <?= htmlspecialchars($article['title']) ?>
                                                </div>
                                                <small class="text-muted">
                                                    <?= !empty($article['date']) ? date('d/m/Y', strtotime($article['date'])) : date('d/m/Y') ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= categoryBadge($article['category']) ?> bg-opacity-75">
                                                    <?= htmlspecialchars($article['category']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($article['status'] === 'published'): ?>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Publié</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Brouillon</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary btn-edit-article"
                                                        data-id="<?= $article['id'] ?>"
                                                        title="Modifier">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 text-end py-2 pe-4">
                            <a href="articles" class="text-muted" style="font-size:.8rem">
                                Tous les articles <i class="bi bi-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Messages non lus -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-3 h-100">
                        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center py-3 px-4">
                            <h6 class="mb-0 fw-bold">
                                Messages non lus
                                <?php if ($stats['unread'] > 0): ?>
                                <span class="badge bg-danger rounded-pill ms-1" style="font-size:.72rem"><?= $stats['unread'] ?></span>
                                <?php endif; ?>
                            </h6>
                            <a href="contacts" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-envelope me-1"></i>Voir tout
                            </a>
                        </div>
                        <div class="card-body px-4 py-2">
                            <?php if (empty($recentMessages)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-check-circle fs-3 d-block mb-2 opacity-30"></i>
                                <span style="font-size:.85rem">Aucun message en attente</span>
                            </div>
                            <?php else: ?>
                            <?php foreach ($recentMessages as $msg):
                                $fullName = trim(($msg['firstname'] ?? '') . ' ' . ($msg['name'] ?? '')) ?: $msg['email'];
                                $initial  = mb_strtoupper(mb_substr($fullName, 0, 1));
                            ?>
                            <div class="msg-item">
                                <div class="msg-avatar"><?= $initial ?></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="msg-name"><?= htmlspecialchars($fullName) ?></div>
                                    <div class="msg-preview"><?= htmlspecialchars($msg['message'] ?? $msg['study_type'] ?? '—') ?></div>
                                </div>
                                <div class="msg-time">
                                    <?= date('d/m', strtotime($msg['date'])) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div><!-- /row -->
        </div><!-- /admin-content -->
    </div><!-- /admin-main -->
</div><!-- /admin-layout -->


<!-- ═══════════════ MODAL ARTICLE ═══════════════ -->
<div class="modal fade" id="articleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="articleModalLabel">Nouvel article</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <?php if ($errors): ?>
                <div class="alert alert-danger" id="formErrors">
                    <?php foreach ($errors as $err): ?>
                    <div><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($err) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data" id="articleForm">
                    <input type="hidden" name="save_article" value="1">
                    <input type="hidden" name="edit_id" id="editId" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Titre *</label>
                        <input type="text" name="title" id="formTitle" class="form-control" required placeholder="Titre de l'article">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Extrait</label>
                        <textarea name="excerpt" id="formExcerpt" class="form-control" rows="2" placeholder="Résumé court affiché dans les listings..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contenu</label>
                        <textarea name="content" id="summernote"></textarea>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Catégorie</label>
                            <select name="category" id="formCategory" class="form-select">
                                <option value="Data Story">Data Story</option>
                                <option value="Consumer Insights">Consumer Insights</option>
                                <option value="Intelligence Éco">Intelligence Éco</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Auteur</label>
                            <input type="text" name="author" id="formAuthor" class="form-control" value="Équipe AFRINEX">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Image principale</label>
                        <input type="file" name="image" class="form-control" accept="image/*">
                        <div id="currentImage" class="mt-2"></div>
                    </div>

                    <div class="mb-3 form-check form-switch">
                        <input type="checkbox" name="is_published" class="form-check-input" id="formIsPublished" value="1" checked>
                        <label class="form-check-label" for="formIsPublished">
                            Publier immédiatement
                            <small class="text-muted">(sinon enregistré comme brouillon)</small>
                        </label>
                    </div>

                    <div class="d-flex gap-2 pt-1">
                        <button type="submit" class="btn btn-gold px-4" id="submitBtn">
                            <i class="bi bi-check-lg me-1"></i>Créer l'article
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ SCRIPTS ═══════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
}
// Fermer le sidebar en cliquant sur un lien (optionnel)
document.querySelectorAll('.admin-sidebar a').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            toggleSidebar();
        }
    });
});

$(function () {

    /* ─── Summernote ─── */
    $('#summernote').summernote({
        height: 280,
        placeholder: 'Rédigez le contenu de votre article ici…',
        toolbar: [
            ['style',   ['style']],
            ['font',    ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
            ['color',   ['color']],
            ['para',    ['ul', 'ol', 'paragraph']],
            ['table',   ['table']],
            ['insert',  ['link', 'picture', 'video', 'hr']],
            ['view',    ['fullscreen', 'codeview', 'help']]
        ],
        callbacks: {
            onImageUpload: function (files) {
                for (let i = 0; i < files.length; i++) {
                    const reader = new FileReader();
                    reader.onload = e => {
                        $('#summernote').summernote('insertImage', e.target.result, function ($img) {
                            $img.css({'max-width':'100%','height':'auto','display':'block','margin':'10px auto'});
                            $img.addClass('img-fluid');
                        });
                    };
                    reader.readAsDataURL(files[i]);
                }
            }
        }
    });

    /* ─── Instance unique du modal ─── */
    let articleModalInstance = null;

    function getArticleModal() {
        if (!articleModalInstance) {
            const modalElement = document.getElementById('articleModal');
            if (!modalElement) {
                console.error('Élément #articleModal introuvable');
                return null;
            }
            articleModalInstance = new bootstrap.Modal(modalElement, {
                backdrop: true,
                keyboard: true
            });
        }
        return articleModalInstance;
    }

    /* ─── Reset du formulaire ─── */
    function resetForm() {
        $('#articleForm')[0].reset();
        $('#editId').val('');
        $('#summernote').summernote('code', '');
        $('#currentImage').html('');
        $('#formErrors').remove();
        $('input[name="image"]').val('');
        $('#articleModalLabel').text("Nouvel article");
        $('#submitBtn').html('<i class="bi bi-check-lg me-1"></i>Créer l\'article');
    }

    /* ─── Bouton Nouvel article ─── */
    $('#btnNewArticle').on('click', function () {
        resetForm();
        const modal = getArticleModal();
        if (modal) modal.show();
    });

    /* ─── Bouton Modifier ─── */
    $(document).on('click', '.btn-edit-article', function (e) {
        e.stopPropagation();

        const id = $(this).data('id');
        const $btn = $(this);

        $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i>');

        $.getJSON('dashboard?action=get_article&id=' + id + '&_=' + Date.now())
            .done(function (response) {
                $btn.prop('disabled', false).html('<i class="bi bi-pencil"></i>');

                if (!response.success || !response.data) {
                    alert('Article introuvable ou erreur serveur.');
                    return;
                }

                const article = response.data;

                $('#editId').val(article.id);
                $('#formTitle').val(article.title);
                $('#formExcerpt').val(article.excerpt || '');
                $('#summernote').summernote('code', article.content || '');
                $('#formCategory').val(article.category);
                $('#formAuthor').val(article.author || 'Équipe AFRINEX');
                $('#formIsPublished').prop('checked', article.status === 'published');

                const imgContainer = $('#currentImage');
                imgContainer.empty();
                if (article.image) {
                    const imageUrl = '../uploads/images/' + article.image;
                    imgContainer.html(
                        '<img src="' + imageUrl + '" height="80" class="rounded border me-2">' +
                        '<small class="text-muted">Image actuelle</small>'
                    );
                }

                $('#articleModalLabel').text("Modifier l'article");
                $('#submitBtn').html('<i class="bi bi-check-lg me-1"></i>Mettre à jour');

                const modal = getArticleModal();
                if (modal) modal.show();
            })
            .fail(function (xhr, status, error) {
                $btn.prop('disabled', false).html('<i class="bi bi-pencil"></i>');
                var raw = xhr.responseText ? xhr.responseText.substring(0, 500) : 'Pas de réponse';
                console.error('=== ERREUR AJAX MODIFIER ===');
                console.error('Status:', status);
                console.error('Error:', error);
                console.error('HTTP:', xhr.status);
                console.error('Réponse:', raw);
                alert('Erreur de chargement.\n\nStatus: ' + status + '\nHTTP: ' + xhr.status + '\n\n' + raw);
            });
    });

    /* ─── Réinitialisation à la fermeture du modal ─── */
    $('#articleModal').on('hidden.bs.modal', function () {
        resetForm();
    });

    /* ─────────────────────────────────────────
       GRAPHIQUES
    ───────────────────────────────────────── */
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color       = '#6b7280';

    const colors = {
        cyan     : '#00B4D8',
        cyanFill : 'rgba(0,180,216,.15)',
        gold     : '#C9A227',
        blue     : '#004D80',
        terra    : '#C4715A',
        navy     : '#1A253A',
        green    : '#10b981',
    };

    /* Line chart ── contacts / 6 mois */
    const lineCanvas = document.getElementById('lineChart');
    if (lineCanvas) {
        const ctx = lineCanvas.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 270);
        grad.addColorStop(0, 'rgba(0,180,216,.3)');
        grad.addColorStop(1, 'rgba(0,180,216,.0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels  : <?= json_encode($lineLabels) ?>,
                datasets: [{
                    label           : 'Contacts',
                    data            : <?= json_encode($lineData) ?>,
                    borderColor     : colors.cyan,
                    backgroundColor : grad,
                    borderWidth     : 3,
                    pointBackgroundColor : '#fff',
                    pointBorderColor     : colors.cyan,
                    pointBorderWidth     : 2,
                    pointRadius     : 4,
                    pointHoverRadius: 6,
                    fill            : true,
                    tension         : .4,
                }]
            },
            options: {
                responsive            : true,
                maintainAspectRatio   : false,
                plugins: {
                    legend : { display: false },
                    tooltip: { backgroundColor: colors.navy, padding: 12, cornerRadius: 8, displayColors: false }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 5 }, grid: { borderDash: [4,4] } },
                    x: { ticks: { font: { size: 11 } }, grid: { display: false } }
                },
                interaction: { intersect: false, mode: 'index' }
            }
        });
    }

    /* Donut chart ── articles par catégorie */
    const donutCanvas = document.getElementById('donutChart');
    if (donutCanvas) {
        const dCtx = donutCanvas.getContext('2d');
        new Chart(dCtx, {
            type: 'doughnut',
            data: {
                labels  : <?= json_encode($donutLabels) ?>,
                datasets: [{
                    data           : <?= json_encode($donutData) ?>,
                    backgroundColor: [colors.cyan, colors.gold, colors.blue, colors.terra],
                    borderWidth    : 0,
                    hoverOffset    : 8,
                }]
            },
            options: {
                responsive          : true,
                maintainAspectRatio : false,
                cutout              : '65%',
                plugins: {
                    legend : { position: 'bottom', labels: { padding: 12, usePointStyle: true, pointStyle: 'circle', font: { size: 11 } } },
                    tooltip: { backgroundColor: colors.navy, padding: 12, cornerRadius: 8 }
                }
            },
            plugins: [{
                id: 'centerText',
                beforeDraw(chart) {
                    const { ctx, width: w, height: h } = chart;
                    const total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                    ctx.save();
                    ctx.textAlign    = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.font         = 'bold 22px Inter, sans-serif';
                    ctx.fillStyle    = colors.navy;
                    ctx.fillText(total, w / 2, h / 2 - 8);
                    ctx.font         = '11px Inter, sans-serif';
                    ctx.fillStyle    = '#9ca3af';
                    ctx.fillText('articles', w / 2, h / 2 + 12);
                    ctx.restore();
                }
            }]
        });
    }

}); // end $(function)
</script>
</body>
</html>