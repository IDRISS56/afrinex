<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? SITE_NAME) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description ?? SITE_DESC) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= TEMPLATE_URL ?>afrinex/theme.css">
    <style>
        /* Styles globaux du site (repris du index.html original) */
        * { scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; background-color: #F7F9FC; color: #1A253A; -webkit-font-smoothing: antialiased; }
        h1, h2, h3, h4, h5, h6 { font-family: 'Montserrat', sans-serif; }
        .gradient-navy { background: linear-gradient(135deg, #1A253A 0%, #0F1923 100%); }
        .gradient-navy-cyan { background: linear-gradient(135deg, #1A253A 0%, #004D80 50%, #00B4D8 100%); }
        .gradient-gold { background: linear-gradient(135deg, #C9A227 0%, #D4AF37 100%); }
        .text-gradient-gold { background: linear-gradient(135deg, #C9A227 0%, #D4AF37 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .btn-primary { background: linear-gradient(135deg, #C9A227 0%, #D4AF37 100%); color: white; font-family: 'Montserrat', sans-serif; font-weight: 600; padding: 1rem 2rem; border-radius: 0.5rem; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.5rem; border: none; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(201,162,39,0.3); }
        .btn-secondary { border: 2px solid rgba(255,255,255,0.4); color: white; font-family: 'Montserrat', sans-serif; font-weight: 600; padding: 1rem 2rem; border-radius: 0.5rem; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.5rem; background: transparent; }
        .btn-secondary:hover { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.6); }
        .card-hover { transition: all 0.3s; }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,180,216,0.1); border-color: rgba(0,180,216,0.3); }
        .glass { background: rgba(255,255,255,0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); }
        .container-custom { width: 100%; padding-right: 1rem; padding-left: 1rem; margin-right: auto; margin-left: auto; }
        @media (min-width: 576px) { .container-custom { padding-right: 1.5rem; padding-left: 1.5rem; } }
        @media (min-width: 768px) { .container-custom { padding-right: 2rem; padding-left: 2rem; } }
        @media (min-width: 992px) { .container-custom { padding-right: 3rem; padding-left: 3rem; } }
        @media (min-width: 1200px) { .container-custom { padding-right: 4rem; padding-left: 4rem; } }
        @media (min-width: 1400px) { .container-custom { padding-right: 6rem; padding-left: 6rem; } }
        .hero-title { font-size: 3rem; } @media (min-width: 768px) { .hero-title { font-size: 3.75rem; } } @media (min-width: 992px) { .hero-title { font-size: 4.5rem; } }
        .text-afrinex-navy { color: #1A253A; }
        .text-afrinex-blue { color: #004D80; }
        .text-afrinex-gold { color: #C9A227; }
        .text-afrinex-cyan { color: #00B4D8; }
        .text-afrinex-muted { color: #5A6A7A; }
        .text-white-80 { color: rgba(255,255,255,0.8); }
        .text-white-70 { color: rgba(255,255,255,0.7); }
        .text-white-60 { color: rgba(255,255,255,0.6); }
        .text-white-50 { color: rgba(255,255,255,0.5); }
        .text-white-40 { color: rgba(255,255,255,0.4); }
        .bg-afrinex-light { background-color: #F7F9FC; }
        .bg-afrinex-dark { background-color: #0F1923; }
        .bg-afrinex-gold { background-color: #C9A227; }
        .bg-afrinex-blue { background-color: #004D80; }
        .bg-afrinex-cyan { background-color: #00B4D8; }
        .bg-white-5 { background-color: rgba(255,255,255,0.05); }
        .bg-white-10 { background-color: rgba(255,255,255,0.1); }
        .bg-cyan-10 { background-color: rgba(0,180,216,0.1); }
        .border-white-5 { border-color: rgba(255,255,255,0.05); }
        .border-cyan-20 { border-color: rgba(0,180,216,0.2); }
        .font-display { font-family: 'Montserrat', sans-serif; }
        .font-body { font-family: 'Inter', sans-serif; }
        .animate-pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .animate-bounce { animation: bounce 1s infinite; }
        @keyframes bounce { 0%, 100% { transform: translateY(-25%); } 50% { transform: translateY(0); } }
        .tracking-wider { letter-spacing: 0.05em; }
        .tracking-widest { letter-spacing: 0.1em; }
        .navbar-custom { transition: all 0.5s; padding: 0; }
        .navbar-custom.scrolled { background: rgba(255,255,255,0.95) !important; backdrop-filter: blur(10px); box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .navbar-custom.scrolled .nav-link-custom { color: #1A253A !important; }
        .navbar-custom.scrolled .brand-text { color: #1A253A !important; }
        .nav-link-custom { padding: 0.5rem 1rem !important; font-size: 0.875rem; font-weight: 500; border-radius: 0.5rem; transition: all 0.3s; color: rgba(255,255,255,0.8) !important; text-decoration: none; display: block; }
        .nav-link-custom:hover { background: rgba(255,255,255,0.1); color: white !important; }
        .footer-link { transition: color 0.3s; color: rgba(255,255,255,0.6); text-decoration: none; }
        .footer-link:hover { color: #C9A227 !important; }
    </style>
</head>
<body>
<!-- Navbar -->
<nav id="navbar" class="navbar-custom fixed-top z-50 bg-transparent">
    <div class="container-custom">
        <div class="d-flex align-items-center justify-content-between" style="height:80px;">
            <a href="<?= PUBLIC_URL ?>" class="d-flex align-items-center gap-2 text-decoration-none">
                <div id="nav-logo" class="fs-3 font-display fw-bold text-white brand-text">
                    <span class="text-afrinex-gold">A</span>FRINEX
                </div>
                <span class="d-none d-sm-block text-uppercase tracking-widest text-white-70 font-body" style="font-size:0.75rem;">Research</span>
            </a>
            <div class="d-none d-lg-flex align-items-center gap-1">
                <?php foreach ($menus as $item): ?>
                <a href="<?= htmlspecialchars($item['url']) ?>" class="nav-link-custom" <?= $item['target'] === '_blank' ? 'target="_blank"' : '' ?>><?= htmlspecialchars($item['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="d-none d-lg-block">
                <a href="#contact" class="btn-primary text-decoration-none" style="font-size:0.875rem;padding:0.75rem 1.5rem;">Demander une étude</a>
            </div>
            <button id="mobile-menu-btn" class="d-lg-none p-2 rounded toggler-btn text-white" style="background:none;border:none;">
                <i class="fas fa-bars fs-5"></i>
            </button>
        </div>
    </div>
    <div id="mobile-menu" class="mobile-menu d-lg-none" style="display:none;background:white;border-radius:1rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);padding:1.5rem;margin:0 1rem 1rem;">
        <?php foreach ($menus as $item): ?>
        <a href="<?= htmlspecialchars($item['url']) ?>" class="d-block p-2 text-decoration-none text-afrinex-navy"><?= htmlspecialchars($item['label']) ?></a>
        <?php endforeach; ?>
        <a href="#contact" class="btn-primary d-block text-center mt-3 text-decoration-none">Demander une étude</a>
    </div>
</nav>
<main>