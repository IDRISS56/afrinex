<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard');
    exit;
}

// Fix : plus besoin de passer $pdo, login() gère la DB en interne via le singleton
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login($email, $password)) {
        header('Location: dashboard');
        exit;
    } else {
        $error = 'Email ou mot de passe incorrect';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - AFRINEX Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --dark-bg: #0d1117;
            --card-bg: #161b22;
            --gold: #d4a017;
            --gold-hover: #b8921f;
        }
        body {
            background: var(--dark-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }
        .login-card {
            background: var(--card-bg);
            border: 1px solid #30363d;
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
        }
        .login-brand {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-brand span {
            color: var(--gold);
            font-weight: 800;
            font-size: 1.8rem;
        }
        .login-brand .sub {
            color: #8b949e;
            font-size: 0.85rem;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        .form-control {
            background: rgba(255,255,255,0.05);
            border: 1px solid #30363d;
            color: white;
            padding: 0.75rem 1rem;
        }
        .form-control:focus {
            background: rgba(255,255,255,0.08);
            border-color: var(--gold);
            color: white;
            box-shadow: 0 0 0 3px rgba(212,160,23,0.1);
        }
        .form-control::placeholder { color: #6e7681; }
        .form-label {
            color: #c9d1d9;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .btn-login {
            background: var(--gold);
            color: white;
            border: none;
            padding: 0.75rem;
            font-weight: 600;
            width: 100%;
            margin-top: 1rem;
        }
        .btn-login:hover {
            background: var(--gold-hover);
            color: white;
        }
        .alert-danger {
            background: rgba(248,81,73,0.1);
            border-color: rgba(248,81,73,0.2);
            color: #f85149;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-brand">
            <div><span>A</span>FRINEX</div>
            <div class="sub">Administration</div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control"
                       placeholder="admin@afrinex.com" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Mot de passe</label>
                <input type="password" name="password" class="form-control"
                       placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-login">Se connecter</button>
        </form>
    </div>
</body>
</html>