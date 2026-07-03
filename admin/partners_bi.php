<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'partners_bi');

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
// GESTION AJAX : Récupérer un partenaire ou une métrique BI
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && isset($_GET['type']) && isset($_GET['id'])) {
    try {
        $type = $_GET['type'];
        $id = (int)$_GET['id'];
        if ($id <= 0) throw new Exception('ID invalide');

        if ($type === 'partner') {
            $item = $db->fetchOne("SELECT * FROM partners WHERE id = ?", [$id]);
            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Partenaire introuvable']);
            }
            $item['status_label'] = $item['status'] ? 'Actif' : 'Inactif';
            jsonResponse(['success' => true, 'data' => $item]);

        } elseif ($type === 'bi') {
            $item = $db->fetchOne("SELECT * FROM bi_metrics WHERE id = ?", [$id]);
            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Métrique introuvable']);
            }
            $item['status_label'] = $item['status'] ? 'Actif' : 'Inactif';
            jsonResponse(['success' => true, 'data' => $item]);

        } else {
            jsonResponse(['success' => false, 'message' => 'Type invalide']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT PARTENAIRES  →  POST
// ═══════════════════════════════════════════════════════════
$partnerErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_partner'])) {
    $editId = isset($_POST['edit_partner_id']) && $_POST['edit_partner_id'] !== '' ? (int)$_POST['edit_partner_id'] : null;
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '#');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $status = isset($_POST['is_active']) ? 1 : 0;

    if (empty($name)) $partnerErrors[] = "Le nom du partenaire est obligatoire";

    $logo = null;
    if ($editId) {
        $existing = $db->fetchOne("SELECT logo FROM partners WHERE id = ?", [$editId]);
        $logo = $existing['logo'] ?? null;
    }
    if (!empty($_FILES['logo']['tmp_name'])) {
        try {
            $logo = uploadImage($_FILES['logo'], 'images');
        } catch (Throwable $e) {
            $partnerErrors[] = $e->getMessage();
        }
    }

    if (empty($partnerErrors)) {
        if ($editId) {
            $db->query("
                UPDATE partners SET name = ?, logo = ?, url = ?, sort_order = ?, status = ?
                WHERE id = ?
            ", [$name, $logo, $url, $sort_order, $status, $editId]);
        } else {
            $db->query("
                INSERT INTO partners (name, logo, url, sort_order, status)
                VALUES (?, ?, ?, ?, ?)
            ", [$name, $logo, $url, $sort_order, $status]);
        }
        $_SESSION['flash_success_partner'] = 'Opération réussie sur le partenaire';
        header('Location: ' . BASE_ROUTE . '#partners');
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION PARTENAIRE  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_partner']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $partner = $db->fetchOne("SELECT logo FROM partners WHERE id = ?", [$id]);
        if ($partner && $partner['logo']) {
            $imgPath = __DIR__ . '/../uploads/images/' . $partner['logo'];
            if (file_exists($imgPath)) unlink($imgPath);
        }
        $db->delete('partners', 'id = ?', [$id]);
        $_SESSION['flash_success_partner'] = 'Partenaire supprimé avec succès';
    }
    header('Location: ' . BASE_ROUTE . '#partners');
    exit;
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT BI METRICS  →  POST
// ═══════════════════════════════════════════════════════════
$biErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bi'])) {
    $editId = isset($_POST['edit_bi_id']) && $_POST['edit_bi_id'] !== '' ? (int)$_POST['edit_bi_id'] : null;
    $label = trim($_POST['label'] ?? '');
    $value = trim($_POST['value'] ?? '');
    $change = trim($_POST['change'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $status = isset($_POST['is_active']) ? 1 : 0;

    if (empty($label)) $biErrors[] = "Le label est obligatoire";
    if (empty($value)) $biErrors[] = "La valeur est obligatoire";

    if (empty($biErrors)) {
        if ($editId) {
            $db->query("
                UPDATE bi_metrics SET label = ?, value = ?, `change` = ?, icon = ?, sort_order = ?, status = ?
                WHERE id = ?
            ", [$label, $value, $change, $icon, $sort_order, $status, $editId]);
        } else {
            $db->query("
                INSERT INTO bi_metrics (label, value, `change`, icon, sort_order, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$label, $value, $change, $icon, $sort_order, $status]);
        }
        $_SESSION['flash_success_bi'] = 'Opération réussie sur la métrique BI';
        header('Location: ' . BASE_ROUTE . '#bi');
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION BI METRIC  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_bi']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        $db->delete('bi_metrics', 'id = ?', [$id]);
        $_SESSION['flash_success_bi'] = 'Métrique BI supprimée avec succès';
    }
    header('Location: ' . BASE_ROUTE . '#bi');
    exit;
}

// ═══════════════════════════════════════════════════════════
// RÉCUPÉRATION DES DONNÉES
// ═══════════════════════════════════════════════════════════
$partners = $db->fetchAll("SELECT * FROM partners ORDER BY sort_order ASC, id DESC");
$biMetrics = $db->fetchAll("SELECT * FROM bi_metrics ORDER BY sort_order ASC, id DESC");

// Flash messages
$successPartner = $_SESSION['flash_success_partner'] ?? null;
unset($_SESSION['flash_success_partner']);
$successBi = $_SESSION['flash_success_bi'] ?? null;
unset($_SESSION['flash_success_bi']);

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
    <title>Partenaires & BI - AFRINEX Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
        .logo-thumb {
            width: 60px;
            height: 40px;
            object-fit: contain;
            border-radius: 4px;
            border: 1px solid #e1e4e8;
            background: white;
            padding: 2px;
        }
        .badge-active { background: #34d399; color: #064e3b; }
        .badge-inactive { background: #9ca3af; color: white; }
        .section-title { font-size: 1.25rem; font-weight: 600; margin-top: 2rem; margin-bottom: 1rem; }
        .nav-tabs .nav-link { color: #1A253A; }
        .nav-tabs .nav-link.active { font-weight: 600; border-bottom: 2px solid var(--gold); }
        #deleteConfirmModal .modal-content { border-radius: 16px; }
        #deleteConfirmModal .modal-body { padding: 2rem; }
        #deleteConfirmModal i.bi-exclamation-triangle-fill { animation: pulse-warning 2s infinite; }
        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
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
        <?php renderNavbar('Partenaires & BI', 'bi-building'); ?>

        <div class="content-area">

            <!-- Onglets -->
            <ul class="nav nav-tabs mb-4" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="partners-tab" data-bs-toggle="tab" data-bs-target="#partners" type="button" role="tab">Partenaires</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="bi-tab" data-bs-toggle="tab" data-bs-target="#bi" type="button" role="tab">Métriques BI</button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- ==================== PARTENAIRES ==================== -->
                <div class="tab-pane fade show active" id="partners" role="tabpanel">

                    <?php if ($successPartner): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successPartner) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    <?php if ($partnerErrors): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php foreach ($partnerErrors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                        <?php endforeach; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="section-title mb-0">Liste des partenaires</h3>
                        <button type="button" class="btn btn-gold" id="btnNewPartner">
                            <i class="bi bi-plus-lg"></i> Nouveau partenaire
                        </button>
                    </div>

                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Logo</th>
                                        <th>Nom</th>
                                        <th>URL</th>
                                        <th>Ordre</th>
                                        <th>Statut</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($partners)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">Aucun partenaire</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($partners as $p): ?>
                                    <tr>
                                        <td>
                                            <?php if ($p['logo']): ?>
                                            <img src="../uploads/images/<?= htmlspecialchars($p['logo']) ?>" class="logo-thumb" alt="<?= htmlspecialchars($p['name']) ?>">
                                            <?php else: ?>
                                            <span class="text-muted">Aucun</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($p['name']) ?></td>
                                        <td><a href="<?= htmlspecialchars($p['url']) ?>" target="_blank"><?= htmlspecialchars($p['url']) ?></a></td>
                                        <td><?= (int)$p['sort_order'] ?></td>
                                        <td>
                                            <?php if ($p['status']): ?>
                                            <span class="badge badge-active">Actif</span>
                                            <?php else: ?>
                                            <span class="badge badge-inactive">Inactif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end" style="white-space: nowrap;">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-view-partner py-1 px-2" data-id="<?= $p['id'] ?>" title="Voir" style="font-size:0.75rem"><i class="bi bi-eye"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-partner py-1 px-2" data-id="<?= $p['id'] ?>" title="Modifier" style="font-size:0.75rem"><i class="bi bi-pencil"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-partner py-1 px-2" data-id="<?= $p['id'] ?>" title="Supprimer" style="font-size:0.75rem"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ==================== BI METRICS ==================== -->
                <div class="tab-pane fade" id="bi" role="tabpanel">

                    <?php if ($successBi): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successBi) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    <?php if ($biErrors): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php foreach ($biErrors as $e): ?>
                        <div><?= htmlspecialchars($e) ?></div>
                        <?php endforeach; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="section-title mb-0">Métriques BI</h3>
                        <button type="button" class="btn btn-gold" id="btnNewBi">
                            <i class="bi bi-plus-lg"></i> Nouvelle métrique
                        </button>
                    </div>

                    <div class="table-card">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Icône</th>
                                        <th>Label</th>
                                        <th>Valeur</th>
                                        <th>Évolution</th>
                                        <th>Ordre</th>
                                        <th>Statut</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($biMetrics)): ?>
                                    <tr><td colspan="7" class="text-center py-4 text-muted">Aucune métrique BI</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($biMetrics as $b): ?>
                                    <tr>
                                        <td><i class="fas <?= htmlspecialchars($b['icon']) ?>"></i></td>
                                        <td><?= htmlspecialchars($b['label']) ?></td>
                                        <td><?= htmlspecialchars($b['value']) ?></td>
                                        <td><?= htmlspecialchars($b['change'] ?? '—') ?></td>
                                        <td><?= (int)$b['sort_order'] ?></td>
                                        <td>
                                            <?php if ($b['status']): ?>
                                            <span class="badge badge-active">Actif</span>
                                            <?php else: ?>
                                            <span class="badge badge-inactive">Inactif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end" style="white-space: nowrap;">
                                            <button type="button" class="btn btn-sm btn-outline-secondary btn-view-bi py-1 px-2" data-id="<?= $b['id'] ?>" title="Voir" style="font-size:0.75rem"><i class="bi bi-eye"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-primary btn-edit-bi py-1 px-2" data-id="<?= $b['id'] ?>" title="Modifier" style="font-size:0.75rem"><i class="bi bi-pencil"></i></button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-bi py-1 px-2" data-id="<?= $b['id'] ?>" title="Supprimer" style="font-size:0.75rem"><i class="bi bi-trash"></i></button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL PARTENAIRE ===== -->
<div class="modal fade" id="partnerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="partnerModalLabel">Nouveau partenaire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="partners_bi" enctype="multipart/form-data" id="partnerForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="partners_bi">
                    <input type="hidden" name="save_partner" value="1">
                    <input type="hidden" name="edit_partner_id" id="editPartnerId" value="">

                    <div class="mb-3">
                        <label class="form-label">Nom *</label>
                        <input type="text" name="name" id="partnerName" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Logo</label>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div id="currentPartnerLogo" class="mt-2"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL (lien)</label>
                        <input type="url" name="url" id="partnerUrl" class="form-control" placeholder="#">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ordre d'affichage</label>
                        <input type="number" name="sort_order" id="partnerSortOrder" class="form-control" min="0" value="0">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="partnerIsActive" value="1" checked>
                        <label class="form-check-label" for="partnerIsActive">Actif</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitPartnerBtn">Créer le partenaire</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL BI METRIC ===== -->
<div class="modal fade" id="biModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="biModalLabel">Nouvelle métrique BI</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="partners_bi" id="biForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="partners_bi">
                    <input type="hidden" name="save_bi" value="1">
                    <input type="hidden" name="edit_bi_id" id="editBiId" value="">

                    <div class="mb-3">
                        <label class="form-label">Label *</label>
                        <input type="text" name="label" id="biLabel" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Valeur *</label>
                        <input type="text" name="value" id="biValue" class="form-control" required placeholder="ex: 34.2%">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Évolution (ex: +2.4% ou -1.2%)</label>
                        <input type="text" name="change" id="biChange" class="form-control" placeholder="+2.4%">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Icône FontAwesome (ex: fa-chart-pie)</label>
                        <input type="text" name="icon" id="biIcon" class="form-control" placeholder="fa-chart-pie">
                        <small class="text-muted">Consultez <a href="https://fontawesome.com/icons" target="_blank">FontAwesome</a> pour les noms d'icônes.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ordre d'affichage</label>
                        <input type="number" name="sort_order" id="biSortOrder" class="form-control" min="0" value="0">
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="biIsActive" value="1" checked>
                        <label class="form-check-label" for="biIsActive">Actif</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBiBtn">Créer la métrique</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR PARTENAIRE ===== -->
<div class="modal fade" id="viewPartnerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Détails du partenaire</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <img id="viewPartnerLogo" src="" alt="Logo" style="max-height:100px;max-width:200px;border:1px solid #e1e4e8;border-radius:4px;padding:4px;">
                </div>
                <dl class="row">
                    <dt class="col-sm-4">Nom</dt>
                    <dd class="col-sm-8" id="viewPartnerName">-</dd>
                    <dt class="col-sm-4">URL</dt>
                    <dd class="col-sm-8" id="viewPartnerUrl">-</dd>
                    <dt class="col-sm-4">Ordre</dt>
                    <dd class="col-sm-8" id="viewPartnerSortOrder">-</dd>
                    <dt class="col-sm-4">Statut</dt>
                    <dd class="col-sm-8" id="viewPartnerStatus">-</dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR BI ===== -->
<div class="modal fade" id="viewBiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Détails de la métrique BI</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <dl class="row">
                    <dt class="col-sm-4">Label</dt>
                    <dd class="col-sm-8" id="viewBiLabel">-</dd>
                    <dt class="col-sm-4">Valeur</dt>
                    <dd class="col-sm-8" id="viewBiValue">-</dd>
                    <dt class="col-sm-4">Évolution</dt>
                    <dd class="col-sm-8" id="viewBiChange">-</dd>
                    <dt class="col-sm-4">Icône</dt>
                    <dd class="col-sm-8" id="viewBiIcon">-</dd>
                    <dt class="col-sm-4">Ordre</dt>
                    <dd class="col-sm-8" id="viewBiSortOrder">-</dd>
                    <dt class="col-sm-4">Statut</dt>
                    <dd class="col-sm-8" id="viewBiStatus">-</dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL DE CONFIRMATION SUPPRESSION GÉNÉRIQUE ===== -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xs">
        <div class="modal-content border-0 shadow">
            <div class="modal-body text-center p-4">
                <div class="mb-3"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i></div>
                <h5 class="modal-title mb-2">Confirmer la suppression</h5>
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer cet élément ?</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression (POST) -->
<form id="deleteForm" method="POST" action="partners_bi" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="partners_bi">
    <input type="hidden" name="delete_type" id="deleteType" value="">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

$(document).ready(function() {

    var deleteType = null;
    var deleteId = null;

    // ========================================================
    // PARTENAIRES
    // ========================================================

    // Nouveau partenaire
    $('#btnNewPartner').on('click', function() {
        resetPartnerForm();
        $('#partnerModalLabel').text('Nouveau partenaire');
        $('#submitPartnerBtn').text('Créer le partenaire');
        $('#editPartnerId').val('');
        $('#currentPartnerLogo').html('');
        var modal = new bootstrap.Modal(document.getElementById('partnerModal'));
        modal.show();
    });

    // Voir partenaire
    $(document).on('click', '.btn-view-partner', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'partners_bi?action=get_section&type=partner&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var p = response.data;
                    $('#viewPartnerName').text(p.name);
                    $('#viewPartnerUrl').html('<a href="' + p.url + '" target="_blank">' + p.url + '</a>');
                    $('#viewPartnerSortOrder').text(p.sort_order);
                    $('#viewPartnerStatus').html(p.status ? '<span class="badge badge-active">Actif</span>' : '<span class="badge badge-inactive">Inactif</span>');
                    if (p.logo) {
                        $('#viewPartnerLogo').attr('src', '../uploads/images/' + p.logo).show();
                    } else {
                        $('#viewPartnerLogo').hide();
                    }
                    new bootstrap.Modal(document.getElementById('viewPartnerModal')).show();
                } else {
                    alert('Erreur : ' + (response.message || 'Partenaire non trouvé'));
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

    // Modifier partenaire
    $(document).on('click', '.btn-edit-partner', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'partners_bi?action=get_section&type=partner&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var p = response.data;
                    $('#editPartnerId').val(p.id);
                    $('#partnerName').val(p.name);
                    $('#partnerUrl').val(p.url || '#');
                    $('#partnerSortOrder').val(p.sort_order);
                    $('#partnerIsActive').prop('checked', p.status == 1);
                    if (p.logo) {
                        $('#currentPartnerLogo').html('<img src="../uploads/images/' + p.logo + '" height="60" class="logo-thumb"><small class="text-muted ms-2">Logo actuel</small>');
                    } else {
                        $('#currentPartnerLogo').html('');
                    }
                    $('#partnerModalLabel').text('Modifier le partenaire');
                    $('#submitPartnerBtn').text('Mettre à jour');
                    var modal = new bootstrap.Modal(document.getElementById('partnerModal'));
                    modal.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Partenaire non trouvé'));
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

    function resetPartnerForm() {
        $('#partnerForm')[0].reset();
        $('#editPartnerId').val('');
        $('#currentPartnerLogo').html('');
    }

    $('#partnerModal').on('hidden.bs.modal', function() {
        resetPartnerForm();
    });

    // ========================================================
    // BI METRICS
    // ========================================================

    // Nouvelle métrique
    $('#btnNewBi').on('click', function() {
        resetBiForm();
        $('#biModalLabel').text('Nouvelle métrique BI');
        $('#submitBiBtn').text('Créer la métrique');
        $('#editBiId').val('');
        var modal = new bootstrap.Modal(document.getElementById('biModal'));
        modal.show();
    });

    // Voir métrique
    $(document).on('click', '.btn-view-bi', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'partners_bi?action=get_section&type=bi&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var b = response.data;
                    $('#viewBiLabel').text(b.label);
                    $('#viewBiValue').text(b.value);
                    $('#viewBiChange').text(b.change || '—');
                    $('#viewBiIcon').html('<i class="fas ' + b.icon + '"></i> ' + b.icon);
                    $('#viewBiSortOrder').text(b.sort_order);
                    $('#viewBiStatus').html(b.status ? '<span class="badge badge-active">Actif</span>' : '<span class="badge badge-inactive">Inactif</span>');
                    new bootstrap.Modal(document.getElementById('viewBiModal')).show();
                } else {
                    alert('Erreur : ' + (response.message || 'Métrique non trouvée'));
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

    // Modifier métrique
    $(document).on('click', '.btn-edit-bi', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'partners_bi?action=get_section&type=bi&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var b = response.data;
                    $('#editBiId').val(b.id);
                    $('#biLabel').val(b.label);
                    $('#biValue').val(b.value);
                    $('#biChange').val(b.change || '');
                    $('#biIcon').val(b.icon || '');
                    $('#biSortOrder').val(b.sort_order);
                    $('#biIsActive').prop('checked', b.status == 1);
                    $('#biModalLabel').text('Modifier la métrique BI');
                    $('#submitBiBtn').text('Mettre à jour');
                    var modal = new bootstrap.Modal(document.getElementById('biModal'));
                    modal.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Métrique non trouvée'));
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

    function resetBiForm() {
        $('#biForm')[0].reset();
        $('#editBiId').val('');
    }

    $('#biModal').on('hidden.bs.modal', function() {
        resetBiForm();
    });

    // ========================================================
    // SUPPRESSION GÉNÉRIQUE (POST)
    // ========================================================
    $(document).on('click', '.btn-delete-partner', function() {
        deleteType = 'partner';
        deleteId = $(this).data('id');
        $('#deleteConfirmModal .modal-title').text('Confirmer la suppression du partenaire');
        $('#deleteConfirmModal .text-danger').text('Êtes-vous sûr de vouloir supprimer ce partenaire ?');
        new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
    });

    $(document).on('click', '.btn-delete-bi', function() {
        deleteType = 'bi';
        deleteId = $(this).data('id');
        $('#deleteConfirmModal .modal-title').text('Confirmer la suppression de la métrique');
        $('#deleteConfirmModal .text-danger').text('Êtes-vous sûr de vouloir supprimer cette métrique BI ?');
        new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (deleteId && deleteType) {
            $('#deleteType').val(deleteType);
            $('#deleteFormId').val(deleteId);
            $('#deleteForm').submit();
        }
    });
});
</script>
</body>
</html>