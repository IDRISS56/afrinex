<?php
declare(strict_types=1);

// Buffer pour capturer toute sortie parasite
if (ob_get_level() === 0) { ob_start(); }

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

define('BASE_ROUTE', 'users');

// Seul un administrateur peut gérer les utilisateurs
if ($_SESSION['user_role'] !== 'admin') {
    header('Location: dashboard');
    exit;
}

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
// AJAX : Récupérer un utilisateur  →  GET
// ═══════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['id'])) {
    try {
        $id = (int)$_GET['id'];
        if ($id <= 0) throw new Exception('ID invalide');
        $user = $db->fetchOne("SELECT id, username, email, role, avatar, is_active, date, mise_ajour FROM users WHERE id = ?", [$id]);
        if ($user) {
            $user['status_label'] = $user['is_active'] ? 'Actif' : 'Inactif';
            $user['status_badge'] = $user['is_active'] ? 'badge-active' : 'badge-inactive';
            $user['date_formatted'] = date('d/m/Y H:i', strtotime($user['date']));
            jsonResponse(['success' => true, 'data' => $user]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Utilisateur introuvable']);
        }
    } catch (Throwable $e) {
        jsonResponse(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════
// TRAITEMENT FORMULAIRE (CREATE/UPDATE)  →  POST
// ═══════════════════════════════════════════════════════════
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    $editId = isset($_POST['edit_id']) && $_POST['edit_id'] !== '' ? (int)$_POST['edit_id'] : null;

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'author';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    // Validation
    if (empty($username)) $errors[] = "Le nom d'utilisateur est obligatoire";
    if (empty($email)) $errors[] = "L'email est obligatoire";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email invalide";

    if (!$editId && empty($password)) {
        $errors[] = "Le mot de passe est obligatoire pour un nouvel utilisateur";
    }
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = "Le mot de passe doit comporter au moins 6 caractères";
    }

    // Vérifier unicité
    if (empty($errors)) {
        $existing = $db->fetchOne("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?", [$username, $email, $editId ?? 0]);
        if ($existing) {
            $errors[] = "Ce nom d'utilisateur ou email est déjà utilisé";
        }
    }

    // Gestion de l'avatar
    $avatar = null;
    if ($editId) {
        $existingUser = $db->fetchOne("SELECT avatar FROM users WHERE id = ?", [$editId]);
        $avatar = $existingUser['avatar'] ?? null;
    }
    if (!empty($_FILES['avatar']['tmp_name'])) {
        try {
            $avatar = uploadImage($_FILES['avatar'], 'images');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (empty($errors)) {
        if ($editId) {
            // Mise à jour
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $db->query("
                    UPDATE users SET
                        username = ?, email = ?, password = ?, role = ?, avatar = ?, is_active = ?, mise_ajour = NOW()
                    WHERE id = ?
                ", [$username, $email, $hashed, $role, $avatar, $is_active, $editId]);
            } else {
                $db->query("
                    UPDATE users SET
                        username = ?, email = ?, role = ?, avatar = ?, is_active = ?, mise_ajour = NOW()
                    WHERE id = ?
                ", [$username, $email, $role, $avatar, $is_active, $editId]);
            }
        } else {
            // Création
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $db->query("
                INSERT INTO users (username, email, password, role, avatar, is_active, date)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ", [$username, $email, $hashed, $role, $avatar, $is_active]);
        }
        $_SESSION['flash_success'] = 'Opération réalisée avec succès';
        header('Location: ' . BASE_ROUTE);
        exit;
    }
}

// ═══════════════════════════════════════════════════════════
// SUPPRESSION  →  POST
// ═══════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    if ($id > 0) {
        // Empêcher la suppression de son propre compte
        if ($id == $_SESSION['user_id']) {
            $_SESSION['flash_success'] = "Vous ne pouvez pas supprimer votre propre compte.";
            header('Location: ' . BASE_ROUTE);
            exit;
        }
        $user = $db->fetchOne("SELECT avatar FROM users WHERE id = ?", [$id]);
        if ($user && $user['avatar']) {
            $imgPath = __DIR__ . '/../uploads/images/' . $user['avatar'];
            if (file_exists($imgPath)) unlink($imgPath);
        }
        $db->delete('users', 'id = ?', [$id]);
        $_SESSION['flash_success'] = 'Utilisateur supprimé avec succès';
    }
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
$roleFilter = trim($_POST['role'] ?? $_GET['role'] ?? '');
$statusFilter = isset($_POST['is_active']) && $_POST['is_active'] !== '' ? (int)$_POST['is_active'] : (isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (int)$_GET['is_active'] : null);

$where = ["1=1"];
$params = [];

if ($search) {
    $where[] = "(username LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($roleFilter !== '') {
    $where[] = "role = ?";
    $params[] = $roleFilter;
}
if ($statusFilter !== null) {
    $where[] = "is_active = ?";
    $params[] = $statusFilter;
}

$whereClause = implode(' AND ', $where);

// Compte total
$countSql = "SELECT COUNT(*) as total FROM users WHERE $whereClause";
$total = (int)($db->fetchOne($countSql, $params)['total'] ?? 0);
$totalPages = ceil($total / $perPage);

// Récupération des utilisateurs (sans le mot de passe)
$sql = "SELECT id, username, email, role, avatar, is_active, date, mise_ajour 
        FROM users 
        WHERE $whereClause 
        ORDER BY id DESC 
        LIMIT $perPage OFFSET $offset";
$users = $db->fetchAll($sql, $params);

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
    <title>Gestion des utilisateurs - AFRINEX Admin</title>
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
        .badge-active { background: #34d399; color: #064e3b; }
        .badge-inactive { background: #9ca3af; color: white; }
        .btn-gold {
            background: var(--gold);
            color: white;
            border: none;
        }
        .btn-gold:hover { background: #b8921f; color: white; }
        .avatar-thumb {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #e1e4e8;
        }
        .avatar-thumb-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e1e4e8;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            font-size: 0.8rem;
        }
        #deleteConfirmModal .modal-content { border-radius: 16px; }
        #deleteConfirmModal .modal-body { padding: 2rem; }
        #deleteConfirmModal i.bi-exclamation-triangle-fill { animation: pulse-warning 2s infinite; }
        @keyframes pulse-warning {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        .modal .modal-dialog { max-width: 600px; }
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
        <?php renderNavbar('Utilisateurs', 'bi-people'); ?>

        <div class="content-area">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">Gestion des utilisateurs</h2>
                <button type="button" class="btn btn-gold" id="btnNewUser">
                    <i class="bi bi-plus-lg"></i> Nouvel utilisateur
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
                        <input type="hidden" name="a" value="users">
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="Rechercher..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <select name="role" class="form-select">
                                <option value="">Tous rôles</option>
                                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="editor" <?= $roleFilter === 'editor' ? 'selected' : '' ?>>Éditeur</option>
                                <option value="author" <?= $roleFilter === 'author' ? 'selected' : '' ?>>Auteur</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="is_active" class="form-select">
                                <option value="">Tous statuts</option>
                                <option value="1" <?= $statusFilter === 1 ? 'selected' : '' ?>>Actif</option>
                                <option value="0" <?= $statusFilter === 0 ? 'selected' : '' ?>>Inactif</option>
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
                                <th>Avatar</th>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Rôle</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">Aucun utilisateur trouvé</td></tr>
                            <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <?php if ($user['avatar']): ?>
                                    <img src="../uploads/images/<?= htmlspecialchars($user['avatar']) ?>" class="avatar-thumb" alt="<?= htmlspecialchars($user['username']) ?>">
                                    <?php else: ?>
                                    <div class="avatar-thumb-placeholder">
                                        <i class="bi bi-person"></i>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'editor' ? 'info' : 'secondary') ?>">
                                        <?= htmlspecialchars($user['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['is_active']): ?>
                                    <span class="badge badge-active">Actif</span>
                                    <?php else: ?>
                                    <span class="badge badge-inactive">Inactif</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($user['date'])) ?></td>
                                <td class="text-end" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline-secondary btn-view py-1 px-2" data-id="<?= $user['id'] ?>" title="Voir" style="font-size:0.75rem"><i class="bi bi-eye"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-edit py-1 px-2" data-id="<?= $user['id'] ?>" title="Modifier" style="font-size:0.75rem"><i class="bi bi-pencil"></i></button>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete py-1 px-2" data-id="<?= $user['id'] ?>" title="Supprimer" style="font-size:0.75rem"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
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
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : FORMULAIRE UTILISATEUR ===== -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalLabel">Nouvel utilisateur</h5>
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
                <form method="POST" action="users" enctype="multipart/form-data" id="userForm">
                    <input type="hidden" name="c" value="app">
                    <input type="hidden" name="a" value="users">
                    <input type="hidden" name="save_user" value="1">
                    <input type="hidden" name="edit_id" id="editId" value="">

                    <div class="mb-3">
                        <label class="form-label">Nom d'utilisateur *</label>
                        <input type="text" name="username" id="formUsername" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" id="formEmail" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Mot de passe <?= isset($_GET['edit_id']) ? '(laisser vide pour conserver)' : '*' ?></label>
                        <input type="password" name="password" id="formPassword" class="form-control" <?= isset($_GET['edit_id']) ? '' : 'required' ?> minlength="6">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Rôle</label>
                        <select name="role" id="formRole" class="form-select">
                            <option value="admin">Admin</option>
                            <option value="editor">Éditeur</option>
                            <option value="author" selected>Auteur</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Avatar</label>
                        <input type="file" name="avatar" class="form-control" accept="image/*">
                        <div id="currentAvatar" class="mt-2"></div>
                    </div>

                    <div class="mb-3 form-check">
                        <input type="checkbox" name="is_active" class="form-check-input" id="formIsActive" value="1" checked>
                        <label class="form-check-label" for="formIsActive">Actif</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="submitBtn">Créer l'utilisateur</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL : VOIR UTILISATEUR ===== -->
<div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Détails de l'utilisateur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <img id="viewAvatar" src="" alt="Avatar" class="rounded-circle" style="width:100px;height:100px;object-fit:cover;border:2px solid #e1e4e8;">
                </div>
                <div class="row">
                    <div class="col-sm-4">Nom</div>
                    <div class="col-sm-8" id="viewUsername">-</div>
                    <div class="col-sm-4">Email</div>
                    <div class="col-sm-8" id="viewEmail">-</div>
                    <div class="col-sm-4">Rôle</div>
                    <div class="col-sm-8" id="viewRole">-</div>
                    <div class="col-sm-4">Statut</div>
                    <div class="col-sm-8" id="viewStatus">-</div>
                    <div class="col-sm-4">Date d'inscription</div>
                    <div class="col-sm-8" id="viewDate">-</div>
                    <div class="col-sm-4">Dernière mise à jour</div>
                    <div class="col-sm-8" id="viewUpdated">-</div>
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
                <p class="text-danger mb-4">Êtes-vous sûr de vouloir supprimer cet utilisateur ?</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="bi bi-trash"></i> Supprimer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire caché pour la suppression POST -->
<form id="deleteForm" method="POST" action="users" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="users">
    <input type="hidden" name="delete_user" value="1">
    <input type="hidden" name="delete_id" id="deleteFormId" value="">
</form>

<!-- Formulaire caché pour la pagination POST -->
<form id="pageForm" method="POST" action="users" style="display:none;">
    <input type="hidden" name="c" value="app">
    <input type="hidden" name="a" value="users">
    <input type="hidden" name="page" id="pageFormPage" value="">
    <input type="hidden" name="search" id="pageFormSearch" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="role" id="pageFormRole" value="<?= htmlspecialchars($roleFilter) ?>">
    <input type="hidden" name="is_active" id="pageFormIsActive" value="<?= $statusFilter !== null ? htmlspecialchars($statusFilter) : '' ?>">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function toggleSidebar() {
    document.getElementById('adminSidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

$(document).ready(function() {
    var userModalInstance = null;
    var viewModalInstance = null;
    var deleteModalInstance = null;

    // ===== NOUVEL UTILISATEUR =====
    $('#btnNewUser').on('click', function() {
        resetForm();
        $('#userModalLabel').text('Nouvel utilisateur');
        $('#submitBtn').text('Créer l\'utilisateur');
        if (!userModalInstance) {
            userModalInstance = new bootstrap.Modal(document.getElementById('userModal'));
        }
        userModalInstance.show();
    });

    // ===== VOIR UTILISATEUR =====
    $(document).on('click', '.btn-view', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'users?action=get_user&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var u = response.data;
                    $('#viewUsername').text(u.username);
                    $('#viewEmail').text(u.email);
                    $('#viewRole').text(u.role);
                    $('#viewStatus').html(u.is_active ? '<span class="badge badge-active">Actif</span>' : '<span class="badge badge-inactive">Inactif</span>');
                    $('#viewDate').text(u.date_formatted);
                    $('#viewUpdated').text(u.mise_ajour ? new Date(u.mise_ajour).toLocaleString('fr-FR') : '-');
                    if (u.avatar) {
                        $('#viewAvatar').attr('src', '../uploads/images/' + u.avatar).show();
                    } else {
                        $('#viewAvatar').hide();
                    }
                    if (!viewModalInstance) {
                        viewModalInstance = new bootstrap.Modal(document.getElementById('viewUserModal'));
                    }
                    viewModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Utilisateur non trouvé'));
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

    // ===== MODIFIER UTILISATEUR =====
    $(document).on('click', '.btn-edit', function() {
        var id = $(this).data('id');
        $.ajax({
            url: 'users?action=get_user&id=' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success && response.data) {
                    var u = response.data;
                    $('#editId').val(u.id);
                    $('#formUsername').val(u.username);
                    $('#formEmail').val(u.email);
                    $('#formRole').val(u.role);
                    $('#formIsActive').prop('checked', u.is_active == 1);
                    $('#formPassword').val('').prop('required', false);
                    if (u.avatar) {
                        $('#currentAvatar').html('<img src="../uploads/images/' + u.avatar + '" height="80" class="rounded avatar-thumb"><small class="text-muted ms-2">Avatar actuel</small>');
                    } else {
                        $('#currentAvatar').html('');
                    }
                    $('#userModalLabel').text('Modifier l\'utilisateur');
                    $('#submitBtn').text('Mettre à jour');
                    if (!userModalInstance) {
                        userModalInstance = new bootstrap.Modal(document.getElementById('userModal'));
                    }
                    userModalInstance.show();
                } else {
                    alert('Erreur : ' + (response.message || 'Utilisateur non trouvé'));
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
        $('#userForm')[0].reset();
        $('#editId').val('');
        $('#currentAvatar').html('');
        $('#formErrors').remove();
        $('#formPassword').prop('required', true);
    }

    $('#userModal').on('hidden.bs.modal', function() {
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