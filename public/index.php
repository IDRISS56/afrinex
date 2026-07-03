<?php
// ============================================================
//  Front-office - Page d'accueil (VERSION DYNAMIQUE)
//  Étude de cas + Newsletter intégrées avant la section contact
// ============================================================
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$base_url = $protocol . $host . $uri;

// --- Configuration AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
}

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

function ajaxResponse($success, $message, $data = null) {
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

// --- Maintenance ---
$db = Database::getInstance();
$maintenance = (int)($db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode'")['setting_value'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $maintenance) {
    ajaxResponse(false, 'Le site est actuellement en maintenance.');
}

if ($maintenance && !($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']))) {
    ?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site en maintenance</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; background: #0d1117; color: #f0f6fc; display: flex; align-items: center; justify-content: center; min-height: 100vh; text-align: center; }
        .container { max-width: 600px; padding: 2rem; }
        .icon { font-size: 4rem; margin-bottom: 1rem; color: #d4a017; }
        h1 { font-size: 2.5rem; font-weight: 700; margin-bottom: 0.5rem; }
        p { font-size: 1.1rem; color: #9ca3af; line-height: 1.6; }
        .gold { color: #d4a017; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🔧</div>
        <h1>Site en <span class="gold">maintenance</span></h1>
        <p>Nous effectuons actuellement des améliorations.<br>Nous revenons très vite.</p>
        <p style="font-size: 0.9rem; color: #6b7280;">Merci de votre compréhension.</p>
    </div>
</body>
</html><?php
    exit;
}

// --- Traitement AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    try {
        $action = $_POST['ajax_action'];
        switch ($action) {
            case 'newsletter':
                $email = trim($_POST['email'] ?? '');
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Veuillez saisir une adresse email valide.');
                }
                $existing = $db->fetchOne("SELECT id FROM contacts WHERE email = ? AND type = 'subscriber'", [$email]);
                if ($existing) throw new Exception('Cette adresse est déjà abonnée.');
                $db->query("INSERT INTO contacts (email, type, name, date) VALUES (?, 'subscriber', ?, NOW())", [$email, $email]);
                ajaxResponse(true, 'Merci ! Vous êtes maintenant abonné à notre newsletter.');
                break;

            case 'contact':
                $nom = trim($_POST['nom'] ?? '');
                $prenom = trim($_POST['prenom'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $entreprise = trim($_POST['entreprise'] ?? '');
                $study_type = trim($_POST['study_type'] ?? '');
                $message = trim($_POST['message'] ?? '');

                if (empty($nom) || empty($prenom) || empty($email) || empty($entreprise)) {
                    throw new Exception('Veuillez remplir tous les champs obligatoires.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Adresse email invalide.');
                }

                $db->query("INSERT INTO contacts (name, firstname, email, company, study_type, message, type, is_read, date) VALUES (?, ?, ?, ?, ?, ?, 'message', 0, NOW())", [$nom, $prenom, $email, $entreprise, $study_type, $message]);
                ajaxResponse(true, 'Votre demande a été envoyée avec succès. Nous vous répondrons sous 24h.');
                break;

            default:
                throw new Exception('Action non reconnue.');
        }
    } catch (Exception $e) {
        ajaxResponse(false, $e->getMessage());
    }
}

// --- Récupération des données ---
$theme = getSetting('theme', 'afrinex');
$templatePath = TEMPLATE_PATH . $theme . '/';
$templateUrl = TEMPLATE_URL . $theme . '/';

// Récupérer les sections
$sections = $db->fetchAll("SELECT * FROM content WHERE type = 'section' AND status = 'published' ORDER BY sort_order");
$services = $db->fetchAll("SELECT * FROM content WHERE type = 'service' AND status = 'published' ORDER BY sort_order");
$testimonials = $db->fetchAll("SELECT * FROM content WHERE type = 'temoignage' AND status = 'published' ORDER BY sort_order");
$articles = $db->fetchAll("SELECT * FROM content WHERE type = 'article' AND status = 'published' ORDER BY date DESC LIMIT 3");
$case_study = $db->fetchOne("SELECT * FROM content WHERE type = 'case_study' AND status = 'published' ORDER BY sort_order, id DESC LIMIT 1");

$menus = getMenus('main');
$bi_metrics = $db->fetchAll("SELECT * FROM bi_metrics WHERE status = 1 ORDER BY sort_order");
$partners = $db->fetchAll("SELECT * FROM partners WHERE status = 1 ORDER BY sort_order");

// Helper pour trouver une section par slug
function getSectionBySlug($sections, $slug) {
    foreach ($sections as $s) {
        if ($s['slug'] === $slug) return $s;
    }
    return null;
}

// Récupérer les slugs pour les liens
$contact_section = getSectionBySlug($sections, 'contact');
$contact_slug = $contact_section ? htmlspecialchars($contact_section['slug']) : 'contact';

$services_section = getSectionBySlug($sections, 'services');
$services_slug = $services_section ? htmlspecialchars($services_section['slug']) : 'services';

// --- Construction de l'ordre des sections : case study avant insights, newsletter avant contact ---
$ordered_sections = [];
$case_study_inserted = false;
$newsletter_inserted = false;

foreach ($sections as $section) {
    // Insérer le case study AVANT la section insights
    if ($section['slug'] === 'insights' && $case_study && !$case_study_inserted) {
        $case_item = $case_study;
        $case_item['slug'] = 'case-study';
        $case_item['type'] = 'section';
        $ordered_sections[] = $case_item;
        $case_study_inserted = true;
    }
    
    // Insérer la newsletter AVANT la section contact
    if ($section['slug'] === 'contact' && !$newsletter_inserted) {
        $newsletter_item = [
            'slug' => 'newsletter',
            'type' => 'section',
            'title' => 'Newsletter',
            'content' => '',
            'status' => 'published',
            'sort_order' => 4.6,
        ];
        $ordered_sections[] = $newsletter_item;
        $newsletter_inserted = true;
    }
    
    $ordered_sections[] = $section;
}

// Fallback si insights ou contact n'existent pas dans les sections
if ($case_study && !$case_study_inserted) {
    $case_item = $case_study;
    $case_item['slug'] = 'case-study';
    $case_item['type'] = 'section';
    $ordered_sections[] = $case_item;
}

if (!$newsletter_inserted) {
    $newsletter_item = [
        'slug' => 'newsletter',
        'type' => 'section',
        'title' => 'Newsletter',
        'content' => '',
        'status' => 'published',
    ];
    $ordered_sections[] = $newsletter_item;
}

$sections_to_render = $ordered_sections;

// --- Site name & description ---
$site_name = getSetting('site_name', SITE_NAME);
$site_desc = getSetting('site_description', SITE_DESC);
$page_title = $site_name;
$page_description = $site_desc;

include $templatePath . 'header.php';
?>

<!-- ════════════════════════════════════════════════════════════════
     RENDU DYNAMIQUE DES SECTIONS (incluant case study et newsletter)
     ════════════════════════════════════════════════════════════════ -->

<?php foreach ($sections_to_render as $section): ?>
<?php $slug = $section['slug']; ?>

    <?php if ($slug === 'accueil'): ?>
    <!-- ─── HERO ─────────────────────────────────── -->
    <section id="<?= htmlspecialchars($slug) ?>" class="position-relative min-vh-100 d-flex align-items-center overflow-hidden" style="background: linear-gradient(135deg, #1A253A 0%, #0F1923 100%);">
        <div class="position-absolute top-0 start-0 end-0 bottom-0">
            <?php if (!empty($section['image'])): ?>
            <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($section['image']) ?>" alt="" class="w-100">
            <?php else: ?>
            <img src="<?= PUBLIC_URL ?>assets/images/service-ml.jpg" alt="" class="w-100">
            <?php endif; ?>
            <div class="position-absolute top-0 start-0 end-0 bottom-0 hero-overlay"></div>
        </div>
        <div class="container-custom position-relative z-10">
            <div class="max-w-6xl mx-auto" style="margin-top: 80px;">
                <div class="d-inline-flex align-items-center gap-2 glass rounded-pill px-4 py-2 mb-4">
                    <span class="rounded-circle bg-afrinex-gold animate-pulse" style="width:8px;height:8px;"></span>
                    <span class="text-white-80" style="font-size:0.875rem;">Cabinet d'études & intelligence de marché — Afrique</span>
                </div>
                <?= $section['content'] ?>
                <div class="d-flex flex-wrap gap-3 mb-5">
                    <a href="#<?= $contact_slug ?>" class="btn-primary text-decoration-none">Demander une étude <i class="fas fa-arrow-right"></i></a>
                    <a href="#<?= $services_slug ?>" class="btn-secondary text-decoration-none">Découvrir nos méthodologies</a>
                </div>
                <div class="row g-4 max-w-3xl">
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-2 fs-md-1 font-display fw-bold text-afrinex-gold" data-count="500">0</div>
                        <div class="text-white-60 mt-2" style="font-size:0.875rem;">Études réalisées</div>
                    </div>
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-2 fs-md-1 font-display fw-bold text-afrinex-gold" data-count="35">0</div>
                        <div class="text-white-60 mt-2" style="font-size:0.875rem;">Pays couverts</div>
                    </div>
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-2 fs-md-1 font-display fw-bold text-afrinex-gold" data-count="1200">0</div>
                        <div class="text-white-60 mt-2" style="font-size:0.875rem;">Décideurs accompagnés</div>
                    </div>
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-2 fs-md-1 font-display fw-bold text-afrinex-gold" data-count="98" data-suffix="%">0</div>
                        <div class="text-white-60 mt-2" style="font-size:0.875rem;">Taux de satisfaction</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="position-absolute bottom-0 start-50 translate-middle-x d-flex flex-column align-items-center gap-2 animate-bounce mb-4">
            <span class="text-white-40 text-uppercase tracking-wider" style="font-size:0.75rem;">Découvrir</span>
            <i class="fas fa-chevron-down text-afrinex-gold"></i>
        </div>
    </section>

    <!-- ─── SOCIAL PROOF ───────── -->
    <?php if (!empty($partners) || !empty($testimonials)): ?>
    <section class="py-5 bg-white border-bottom" style="border-color:#f3f4f6;">
        <div class="container-custom">
            <?php if (!empty($partners)): ?>
            <p class="text-center text-afrinex-muted text-uppercase tracking-wider mb-4 font-body" style="font-size:0.875rem;">Ils nous font confiance</p>
            <div class="overflow-hidden position-relative">
                <div class="d-flex gap-5 align-items-center marquee whitespace-nowrap">
                    <?php foreach ($partners as $p): ?>
                    <span class="fs-4 font-display fw-bold text-gray-300 flex-shrink-0"><?= htmlspecialchars($p['name']) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($partners as $p): ?>
                    <span class="fs-4 font-display fw-bold text-gray-300 flex-shrink-0"><?= htmlspecialchars($p['name']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!empty($testimonials)): ?>
            <div class="max-w-4xl mx-auto mt-5">
                <div id="testimonialCarousel" class="carousel slide bg-afrinex-light rounded-4 p-4 p-md-5 position-relative" data-bs-ride="carousel" data-bs-interval="5000">
                    <div class="carousel-inner">
                        <?php foreach ($testimonials as $index => $t): ?>
                        <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                            <div class="text-afrinex-gold font-serif leading-none mb-4" style="font-size:3.75rem;">"</div>
                            <p class="fs-5 text-afrinex-navy font-body leading-relaxed mb-4"><?= htmlspecialchars($t['content']) ?></p>
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-afrinex-blue d-flex align-items-center justify-content-center text-white font-display fw-bold" style="width:48px;height:48px;flex-shrink:0;">
                                    <?php 
                                    $name = $t['author'] ?? $t['title'];
                                    $initials = '';
                                    $parts = explode(' ', $name);
                                    foreach ($parts as $part) { $initials .= strtoupper(substr($part, 0, 1)); }
                                    echo substr($initials, 0, 2);
                                    ?>
                                </div>
                                <div>
                                    <div class="font-display fw-semibold text-afrinex-navy"><?= htmlspecialchars($name) ?></div>
                                    <div class="text-afrinex-muted" style="font-size:0.875rem;">
                                        <?= htmlspecialchars($t['role']) ?>
                                        <?php if ($t['company']): ?>, <?= htmlspecialchars($t['company']) ?><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($testimonials) > 1): ?>
                    <div class="d-flex justify-content-center gap-2 mt-4" id="customDots">
                        <?php foreach ($testimonials as $index => $t): ?>
                        <button type="button" class="custom-dot <?= $index === 0 ? 'active' : '' ?>" data-slide="<?= $index ?>" style="width:14px;height:14px;border-radius:50%;border:2px solid #C9A227;background:<?= $index === 0 ? '#C9A227' : 'transparent' ?>;padding:0;cursor:pointer;transition:background 0.3s;"></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php elseif ($slug === 'services'): ?>
    <!-- ─── SERVICES ─────────────────────────────────── -->
    <?php if ($section || !empty($services)): ?>
    <section id="<?= htmlspecialchars($slug) ?>" class="py-5 bg-afrinex-light">
        <div class="container-custom">
            <?php if ($section): ?>
            <div class="text-center max-w-3xl mx-auto mb-5">
                <span class="text-afrinex-cyan font-display fw-semibold text-uppercase tracking-wider" style="font-size:0.875rem;">Nos Expertises</span>
                <?= $section['content'] ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($services)): ?>
            <div class="row g-4">
                <?php foreach ($services as $service): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="group bg-white rounded-4 overflow-hidden border card-hover h-100 d-flex flex-column">
                        <div class="position-relative overflow-hidden" style="height: 200px;">
                            <?php if ($service['image']): ?>
                            <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($service['image']) ?>" alt="<?= htmlspecialchars($service['title']) ?>" class="w-100 h-100 object-cover group-hover-scale-105 transition-all" style="transition-duration: 500ms;">
                            <?php else: ?>
                            <div class="w-100 h-100 d-flex align-items-center justify-content-center" style="background: linear-gradient(135deg, #1A253A 0%, #0F1923 100%);">
                                <i class="<?= htmlspecialchars($service['icon'] ?? 'fas fa-chart-bar') ?> fs-1 text-white-50"></i>
                            </div>
                            <?php endif; ?>
                            <div class="position-absolute top-0 start-0 m-3">
                                <div class="rounded-3 bg-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px;">
                                    <i class="<?= htmlspecialchars($service['icon'] ?? 'fas fa-chart-bar') ?> text-afrinex-blue fs-5"></i>
                                </div>
                            </div>
                        </div>
                        <div class="p-4 flex-fill d-flex flex-column">
                            <h3 class="fs-5 font-display fw-bold text-afrinex-navy mb-3"><?= htmlspecialchars($service['title']) ?></h3>
                            <div class="text-afrinex-muted font-body flex-fill" style="font-size:0.875rem; line-height:1.625;">
                                <?= strip_tags($service['content'] ?? '') ?>
                            </div>
                            <a href="#<?= $contact_slug ?>" class="d-inline-flex align-items-center gap-2 text-afrinex-gold font-display fw-semibold text-decoration-none mt-3 pt-3 border-top" style="font-size:0.875rem;">
                                En savoir plus <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php elseif ($slug === 'bi-data'): ?>
    <!-- ─── BI & DATA ────────────────────────── -->
    <?php if ($section || !empty($bi_metrics)): ?>
    <section id="<?= htmlspecialchars($slug) ?>" class="py-5 gradient-navy position-relative overflow-hidden">
        <div class="position-absolute top-0 start-0 end-0 bottom-0 grid-bg" style="opacity:0.05;"></div>
        <div class="container-custom position-relative z-10">
            <?php if ($section): ?>
            <div class="text-center max-w-3xl mx-auto mb-5">
                <span class="text-afrinex-cyan font-display fw-semibold text-uppercase tracking-wider" style="font-size:0.875rem;">Business Intelligence</span>
                <?= $section['content'] ?>
            </div>
            <?php endif; ?>
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <div class="bg-afrinex-dark rounded-4 p-4 shadow-2xl" style="backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,0.1);">
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <div class="d-flex align-items-center gap-3">
                                <i class="fas fa-chart-bar text-afrinex-cyan"></i>
                                <span class="text-white font-display fw-semibold" style="font-size:0.875rem;">Tableau de bord — Telecom Afrique</span>
                            </div>
                            <span class="d-flex align-items-center gap-2 text-white-60" style="font-size:0.875rem;"><i class="fas fa-filter" style="font-size:0.75rem;"></i> Filtres</span>
                        </div>
                        <div class="row g-3 mb-4">
                            <?php foreach ($bi_metrics as $metric): ?>
                            <div class="col-6">
                                <div class="bg-white-5 rounded-3 p-3 border-white-5">
                                    <div class="text-white-50 mb-1" style="font-size:0.75rem;"><?= htmlspecialchars($metric['label']) ?></div>
                                    <div class="d-flex align-items-end gap-2">
                                        <span class="fs-4 font-display fw-bold text-white"><?= htmlspecialchars($metric['value']) ?></span>
                                        <?php if ($metric['change']): ?>
                                        <span class="<?= strpos($metric['change'], '+') === 0 ? 'text-emerald-400' : 'text-red-400' ?>" style="font-size:0.75rem;"><?= htmlspecialchars($metric['change']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="row g-4">
                            <div class="col-6">
                                <div class="bg-white-5 rounded-3 p-3 border-white-5">
                                    <div class="text-white-50 mb-3" style="font-size:0.75rem;">Évolution trimestrielle</div>
                                    <div class="d-flex align-items-end gap-1" style="height:96px;">
                                        <?php for ($i = 0; $i < 10; $i++): ?>
                                        <div class="flex-fill rounded-top" style="height: <?= rand(30, 95) ?>%; background: linear-gradient(to top, #004D80, #00B4D8); opacity:0.8;"></div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="bg-white-5 rounded-3 p-3 border-white-5">
                                    <div class="text-white-50 mb-3" style="font-size:0.75rem;">Répartition par pays</div>
                                    <div class="position-relative mx-auto" style="width:128px;height:128px;">
                                        <svg viewBox="0 0 100 100" class="w-100 h-100 rotate-neg90">
                                            <circle cx="50" cy="50" r="40" fill="none" stroke="#374151" stroke-width="12"></circle>
                                            <circle cx="50" cy="50" r="40" fill="none" stroke="#00B4D8" stroke-width="12" stroke-dasharray="88 163" stroke-linecap="round"></circle>
                                            <circle cx="50" cy="50" r="40" fill="none" stroke="#C9A227" stroke-width="12" stroke-dasharray="70 163" stroke-dashoffset="-88" stroke-linecap="round"></circle>
                                            <circle cx="50" cy="50" r="40" fill="none" stroke="#004D80" stroke-width="12" stroke-dasharray="55 163" stroke-dashoffset="-158" stroke-linecap="round"></circle>
                                        </svg>
                                        <div class="position-absolute top-50 start-50 translate-middle d-flex align-items-center justify-content-center">
                                            <span class="fs-5 font-display fw-bold text-white">35%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 bg-white-5 rounded-3 p-3 border-white-5">
                            <div class="text-white-50 mb-3" style="font-size:0.75rem;">Tendance 12 mois</div>
                            <svg viewBox="0 0 100 50" preserveAspectRatio="none" class="w-100" style="height:80px;">
                                <defs><linearGradient id="lineGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#00B4D8" stop-opacity="0.3"></stop><stop offset="100%" stop-color="#00B4D8" stop-opacity="0"></stop></linearGradient></defs>
                                <polygon points="0,50 0,40 10,30 20,35 30,20 40,28 50,15 60,22 70,10 80,18 90,5 100,12 100,50" fill="url(#lineGrad)"></polygon>
                                <polyline points="0,40 10,30 20,35 30,20 40,28 50,15 60,22 70,10 80,18 90,5 100,12" fill="none" stroke="#00B4D8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></polyline>
                            </svg>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <?php
                    $bi_features = [
                        ['icon' => 'fa-chart-bar', 'title' => 'Tableaux de bord interactifs & KPI', 'desc' => 'Visualisez vos indicateurs clés en temps réel avec des dashboards personnalisables.'],
                        ['icon' => 'fa-layer-group', 'title' => 'Consolidation de données multi-sources', 'desc' => 'Agrégez vos données terrain, digitales et internes en un seul référentiel unifié.'],
                        ['icon' => 'fa-activity', 'title' => 'Monitoring de performance en temps réel', 'desc' => "Suivez l'avancement de vos études et la qualité des collectes en direct."],
                        ['icon' => 'fa-chart-pie', 'title' => 'Visualisation de données avancée', 'desc' => 'Cartographies interactives, graphiques dynamiques et rapports automatisés.'],
                    ];
                    foreach ($bi_features as $feature):
                    ?>
                    <div class="d-flex gap-3 p-3 rounded-3 hover-bg-white-10 transition-colors group">
                        <div class="rounded-3 bg-cyan-10 d-flex align-items-center justify-content-center flex-shrink-0 group-hover-bg-cyan-20" style="width:48px;height:48px;">
                            <i class="fas <?= $feature['icon'] ?> text-afrinex-cyan fs-4"></i>
                        </div>
                        <div>
                            <h3 class="text-white font-display fw-semibold"><?= $feature['title'] ?></h3>
                            <p class="text-white-50 font-body" style="font-size:0.875rem;"><?= $feature['desc'] ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <a href="#<?= $contact_slug ?>" class="btn-primary d-inline-flex align-items-center gap-2 mt-3 text-decoration-none">Découvrir nos solutions BI <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php elseif ($slug === 'innovation'): ?>
    <!-- ─── INNOVATION ────────────────────────────────── -->
    <?php if ($section): ?>
    <section id="<?= htmlspecialchars($slug) ?>" class="py-5 bg-white position-relative overflow-hidden">
        <div class="container-custom">
            <div class="text-center max-w-3xl mx-auto mb-5">
                <div class="d-inline-flex align-items-center gap-2 bg-cyan-10 rounded-pill px-4 py-2 mb-4">
                    <i class="fas fa-sparkles text-afrinex-cyan"></i>
                    <span class="text-afrinex-cyan font-display fw-semibold">Innovation</span>
                </div>
                <?= $section['content'] ?>
            </div>
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <div class="position-relative rounded-4 overflow-hidden shadow-2xl">
                        <?php if (!empty($section['image'])): ?>
                        <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($section['image']) ?>" alt="Machine Learning" class="w-100">
                        <?php else: ?>
                        <img src="<?= PUBLIC_URL ?>assets/images/service-ml.jpg" alt="Machine Learning" class="w-100">
                        <?php endif; ?>
                        <div class="position-absolute top-0 start-0 end-0 bottom-0" style="background:linear-gradient(to top, rgba(26,37,58,0.6), transparent, transparent);"></div>
                        <div class="position-absolute bottom-0 start-0 end-0 p-4">
                            <div class="glass rounded-3 p-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle bg-afrinex-gold d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                                        <i class="fas fa-brain text-white"></i>
                                    </div>
                                    <div>
                                        <div class="text-white font-display fw-semibold" style="font-size:0.875rem;">Pipeline ML Actif</div>
                                        <div class="text-white-60" style="font-size:0.75rem;">Collecte → Nettoyage → Modélisation → Prédiction</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="group p-4 rounded-4 bg-afrinex-light border group-hover-border-cyan-30 group-hover-shadow-lg transition-all">
                        <div class="d-flex align-items-start gap-3">
                            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0 shadow-lg" style="width:56px;height:56px;background:linear-gradient(to bottom right, #004D80, #00B4D8);">
                                <i class="fas fa-brain text-white fs-3"></i>
                            </div>
                            <div class="flex-fill">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h3 class="fs-5 font-display fw-bold text-afrinex-navy group-hover-text-blue transition-colors">Modélisation Prédictive</h3>
                                    <div class="text-end">
                                        <div class="fs-4 font-display fw-bold text-afrinex-gold">94%</div>
                                        <div class="text-afrinex-muted" style="font-size:0.75rem;">Précision moyenne</div>
                                    </div>
                                </div>
                                <p class="text-afrinex-muted font-body" style="font-size:0.875rem;line-height:1.625;">Prédisez les comportements d'achat et les tendances de marché avec précision grâce à nos algorithmes propriétaires adaptés au contexte africain.</p>
                            </div>
                        </div>
                    </div>
                    <div class="group p-4 rounded-4 bg-afrinex-light border group-hover-border-cyan-30 group-hover-shadow-lg transition-all mt-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0 shadow-lg" style="width:56px;height:56px;background:linear-gradient(to bottom right, #004D80, #00B4D8);">
                                <i class="fas fa-crosshairs text-white fs-3"></i>
                            </div>
                            <div class="flex-fill">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h3 class="fs-5 font-display fw-bold text-afrinex-navy group-hover-text-blue transition-colors">Segmentation Algorithmique</h3>
                                    <div class="text-end">
                                        <div class="fs-4 font-display fw-bold text-afrinex-gold">12</div>
                                        <div class="text-afrinex-muted" style="font-size:0.75rem;">Segments définis</div>
                                    </div>
                                </div>
                                <p class="text-afrinex-muted font-body" style="font-size:0.875rem;line-height:1.625;">Clustering avancé pour définir des personas clients ultra-pertinents et cibler vos actions marketing avec une granularité inédite.</p>
                            </div>
                        </div>
                    </div>
                    <div class="group p-4 rounded-4 bg-afrinex-light border group-hover-border-cyan-30 group-hover-shadow-lg transition-all mt-4">
                        <div class="d-flex align-items-start gap-3">
                            <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0 shadow-lg" style="width:56px;height:56px;background:linear-gradient(to bottom right, #004D80, #00B4D8);">
                                <i class="fas fa-comment-dots text-white fs-3"></i>
                            </div>
                            <div class="flex-fill">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h3 class="fs-5 font-display fw-bold text-afrinex-navy group-hover-text-blue transition-colors">Analyse de Sentiment</h3>
                                    <div class="text-end">
                                        <div class="fs-4 font-display fw-bold text-afrinex-gold">3M+</div>
                                        <div class="text-afrinex-muted" style="font-size:0.75rem;">Mentions analysées</div>
                                    </div>
                                </div>
                                <p class="text-afrinex-muted font-body" style="font-size:0.875rem;line-height:1.625;">Décodage automatique des opinions sur les réseaux et médias digitaux en français, anglais et langues locales africaines.</p>
                            </div>
                        </div>
                    </div>
                    <a href="#<?= $contact_slug ?>" class="d-inline-flex align-items-center gap-2 text-afrinex-gold font-display fw-semibold group text-decoration-none mt-3">Explorer une démo ML <i class="fas fa-arrow-right group-hover-translate-x transition-transform"></i></a>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php elseif ($slug === 'insights'): ?>
    <!-- ─── INSIGHTS ───────────────────────────────────── -->
    <?php if ($section || !empty($articles)): ?>
    <section id="<?= htmlspecialchars($slug) ?>" class="py-5 bg-white">
        <div class="container-custom">
            <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between mb-5">
                <div>
                    <?php if ($section): ?>
                    <span class="text-afrinex-cyan font-display fw-semibold text-uppercase tracking-wider" style="font-size:0.875rem;">Insights Hub</span>
                    <?= $section['content'] ?>
                    <?php endif; ?>
                </div>
                <a href="#<?= $contact_slug ?>" class="d-inline-flex align-items-center gap-2 text-afrinex-gold font-display fw-semibold mt-3 mt-md-0 group text-decoration-none">Voir toutes les ressources <i class="fas fa-arrow-right group-hover-translate-x transition-transform"></i></a>
            </div>
            <div class="row g-4">
                <?php if (empty($articles)): ?>
                <div class="col-12 text-center text-muted py-5">Aucun article pour le moment.</div>
                <?php else: ?>
                <?php foreach ($articles as $article): ?>
                <div class="col-md-6 col-lg-4">
                    <article class="group bg-afrinex-light rounded-4 overflow-hidden border card-hover">
                        <?php if ($article['image']): ?>
                        <div class="position-relative overflow-hidden" style="height:208px;">
                            <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($article['image']) ?>" alt="<?= htmlspecialchars($article['title']) ?>" class="w-100 h-100 object-cover group-hover-scale-105 transition-all" style="transition-duration:700ms;">
                            <div class="position-absolute top-0 start-0 end-0 bottom-0" style="background:linear-gradient(to top, rgba(26,37,58,0.4), transparent);"></div>
                            <?php if ($article['category']): ?>
                            <div class="position-absolute top-0 start-0 m-3 bg-afrinex-cyan text-white font-display fw-semibold px-3 py-1 rounded-pill" style="font-size:0.75rem;"><?= htmlspecialchars($article['category']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="p-4">
                            <div class="d-flex align-items-center gap-3 text-afrinex-muted mb-3" style="font-size:0.75rem;">
                                <span><?= date('d M Y', strtotime($article['date'])) ?></span>
                                <span class="d-flex align-items-center gap-1"><i class="far fa-clock" style="font-size:0.625rem;"></i> <?= rand(4, 12) ?> min</span>
                            </div>
                            <h3 class="fs-6 font-display fw-bold text-afrinex-navy mb-3 group-hover-text-blue transition-colors leading-tight"><?= htmlspecialchars($article['title']) ?></h3>
                            <p class="text-afrinex-muted font-body mb-3" style="font-size:0.875rem;"><?= htmlspecialchars($article['excerpt']) ?></p>
                            <a href="#<?= $contact_slug ?>" class="d-inline-flex align-items-center gap-2 text-afrinex-gold font-display fw-semibold text-decoration-none" style="font-size:0.875rem;">Lire l'article <i class="fas fa-arrow-right group-hover-translate-x transition-transform" style="font-size:0.75rem;"></i></a>
                        </div>
                    </article>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php elseif ($slug === 'case-study'): ?>
    <!-- ─── CASE STUDY (intégré dynamiquement avant la section contact) ─── -->
    <section class="py-5 mb-5 bg-afrinex-light" id="case-study">
        <div class="container-custom">
            <div class="text-center max-w-3xl mx-auto mb-5">
                <span class="text-afrinex-cyan font-display fw-semibold text-uppercase tracking-wider" style="font-size:0.875rem;">Étude de cas</span>
                <h2 class="section-title font-display fw-bold text-afrinex-navy mt-3 mb-4"><?= htmlspecialchars($section['title']) ?></h2>
            </div>
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <div class="position-relative rounded-4 overflow-hidden mb-4">
                        <?php if ($section['image']): ?>
                            <img src="<?= BASE_URL ?>/uploads/images/<?= htmlspecialchars($section['image']) ?>" alt="<?= htmlspecialchars($section['title']) ?>" class="w-100">
                        <?php else: ?>
                            <img src="<?= PUBLIC_URL ?>assets/images/case-telecom.jpg" alt="<?= htmlspecialchars($section['title']) ?>" class="w-100">
                        <?php endif; ?>
                        <div class="position-absolute top-0 start-0 m-3 bg-afrinex-gold text-white font-display fw-semibold px-3 py-2 rounded-3" style="font-size:0.875rem;">Étude phare 2024</div>
                    </div>
                    <div class="row g-2">
                        <div class="col-3 text-center">
                            <div class="rounded-circle bg-afrinex-blue text-white font-display fw-bold d-flex align-items-center justify-content-center mx-auto mb-2" style="width:40px;height:40px;font-size:0.875rem;">01</div>
                            <div class="font-display fw-semibold text-afrinex-navy" style="font-size:0.75rem;">Briefing</div>
                            <div class="text-afrinex-muted mt-1" style="font-size:0.625rem;">Immersion</div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="rounded-circle bg-afrinex-blue text-white font-display fw-bold d-flex align-items-center justify-content-center mx-auto mb-2" style="width:40px;height:40px;font-size:0.875rem;">02</div>
                            <div class="font-display fw-semibold text-afrinex-navy" style="font-size:0.75rem;">Design</div>
                            <div class="text-afrinex-muted mt-1" style="font-size:0.625rem;">Conception</div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="rounded-circle bg-afrinex-blue text-white font-display fw-bold d-flex align-items-center justify-content-center mx-auto mb-2" style="width:40px;height:40px;font-size:0.875rem;">03</div>
                            <div class="font-display fw-semibold text-afrinex-navy" style="font-size:0.75rem;">Terrain</div>
                            <div class="text-afrinex-muted mt-1" style="font-size:0.625rem;">Collecte</div>
                        </div>
                        <div class="col-3 text-center">
                            <div class="rounded-circle bg-afrinex-blue text-white font-display fw-bold d-flex align-items-center justify-content-center mx-auto mb-2" style="width:40px;height:40px;font-size:0.875rem;">04</div>
                            <div class="font-display fw-semibold text-afrinex-navy" style="font-size:0.75rem;">Insights</div>
                            <div class="text-afrinex-muted mt-1" style="font-size:0.625rem;">Analyse</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="d-flex align-items-center gap-4 mb-4 text-afrinex-muted" style="font-size:0.875rem;">
                        <?php if ($section['period']): ?>
                        <span class="d-flex align-items-center gap-1"><i class="fas fa-calendar text-afrinex-cyan"></i> <?= htmlspecialchars($section['period']) ?></span>
                        <?php endif; ?>
                        <?php if ($section['countries']): ?>
                        <span class="d-flex align-items-center gap-1"><i class="fas fa-globe-africa text-afrinex-cyan"></i> <?= $section['countries'] ?> pays</span>
                        <?php endif; ?>
                    </div>
                    <h3 class="fs-4 font-display fw-bold text-afrinex-navy mb-3">Contexte & Enjeux</h3>
                    <p class="text-afrinex-muted font-body leading-relaxed mb-4"><?= nl2br(htmlspecialchars($section['context'])) ?></p>
                    <h3 class="fs-4 font-display fw-bold text-afrinex-navy mb-3">Notre approche</h3>
                    <p class="text-afrinex-muted font-body leading-relaxed mb-5"><?= nl2br(htmlspecialchars($section['approach'])) ?></p>
                    <div class="row g-3 mb-4">
                        <?php if ($section['precision']): ?>
                        <div class="col-4">
                            <div class="bg-white rounded-3 p-3 text-center border">
                                <i class="fas fa-chart-line text-afrinex-cyan mb-2"></i>
                                <div class="fs-4 font-display fw-bold text-afrinex-navy"><?= htmlspecialchars($section['precision']) ?></div>
                                <div class="text-afrinex-muted" style="font-size:0.75rem;"><?= htmlspecialchars($section['precision_label'] ?? 'Précision') ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($section['countries']): ?>
                        <div class="col-4">
                            <div class="bg-white rounded-3 p-3 text-center border">
                                <i class="fas fa-globe-africa text-afrinex-cyan mb-2"></i>
                                <div class="fs-4 font-display fw-bold text-afrinex-navy"><?= $section['countries'] ?></div>
                                <div class="text-afrinex-muted" style="font-size:0.75rem;">Pays couverts</div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($section['personas']): ?>
                        <div class="col-4">
                            <div class="bg-white rounded-3 p-3 text-center border">
                                <i class="fas fa-users text-afrinex-cyan mb-2"></i>
                                <div class="fs-4 font-display fw-bold text-afrinex-navy"><?= $section['personas'] ?></div>
                                <div class="text-afrinex-muted" style="font-size:0.75rem;">Personas identifiés</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($section['impact']): ?>
                    <div class="rounded-3 p-4 border-cyan-20 mb-4" style="background:linear-gradient(to right, rgba(0,77,128,0.05), rgba(0,180,216,0.05));">
                        <h4 class="font-display fw-semibold text-afrinex-navy mb-2">Impact mesurable</h4>
                        <p class="text-afrinex-muted font-body" style="font-size:0.875rem;"><?= nl2br(htmlspecialchars($section['impact'])) ?></p>
                    </div>
                    <?php endif; ?>
                    <a href="#<?= $contact_slug ?>" class="btn-primary d-inline-flex align-items-center gap-2 text-decoration-none">Un projet similaire ? Discutons-en <i class="fas fa-arrow-right" style="font-size:0.875rem;"></i></a>
                </div>
            </div>
        </div>
    </section>

    <?php elseif ($slug === 'newsletter'): ?>
    <!-- ─── NEWSLETTER ─── -->
    <section id="newsletter" class="py-5 bg-white">
        <div class="container-custom">
            <div class="rounded-4 p-4 p-md-5 text-center" style="background: linear-gradient(to right, #004D80, #00B4D8);">
                <h3 class="fs-4 font-display fw-bold text-white mb-3">Recevez nos insights hebdomadaires</h3>
                <p class="text-white-70 font-body mb-4 max-w-xl mx-auto">Data stories, analyses de marché et tendances consommateur livrées directement dans votre boîte mail.</p>
                <form id="newsletterForm" class="d-flex flex-column flex-sm-row gap-3 max-w-lg mx-auto">
                    <input type="email" name="email" placeholder="Votre adresse email" class="form-glass-light flex-fill" required style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:white;padding:1rem 1.5rem;border-radius:1rem;">
                    <button type="submit" class="bg-afrinex-gold text-white font-display fw-semibold px-4 py-3 rounded-4 border-0 hover-shadow-lg transition-all" style="white-space:nowrap;">S'abonner</button>
                </form>
                <div id="newsletterMessage" class="mt-3 text-white-80" style="font-size:0.875rem;"></div>
            </div>
        </div>
    </section>

    <?php elseif ($slug === 'contact'): ?>
    <!-- ─── CONTACT ───────────────────────────────────── -->
    <?php if ($section): ?>
    <section id="<?= htmlspecialchars($slug) ?>" class="py-5 gradient-navy position-relative overflow-hidden">
        <div class="position-absolute top-0 start-0 end-0 bottom-0 grid-bg" style="opacity:0.1;"></div>
        <div class="position-absolute top-0 end-0 rounded-circle bg-cyan-10 blur-3xl" style="width:384px;height:384px;"></div>
        <div class="position-absolute bottom-0 start-0 rounded-circle bg-gold-10 blur-3xl" style="width:384px;height:384px;"></div>
        <div class="container-custom position-relative z-10">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <?= $section['content'] ?>
                    <div class="d-flex flex-column gap-4 mb-5">
                        <div class="d-flex align-items-center gap-3 text-white-80">
                            <div class="rounded-3 glass d-flex align-items-center justify-content-center" style="width:48px;height:48px;"><i class="fas fa-phone text-afrinex-gold"></i></div>
                            <div>
                                <div class="text-white-40 text-uppercase tracking-wider" style="font-size:0.75rem;">Téléphone</div>
                                <div class="font-display fw-semibold">+225 27 XX XX XX XX</div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 text-white-80">
                            <div class="rounded-3 glass d-flex align-items-center justify-content-center" style="width:48px;height:48px;"><i class="fas fa-envelope text-afrinex-gold"></i></div>
                            <div>
                                <div class="text-white-40 text-uppercase tracking-wider" style="font-size:0.75rem;">Email</div>
                                <div class="font-display fw-semibold"><a href="mailto:contact@afrinex.com" class="text-decoration-none text-white">contact@afrinex.com</a></div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 text-white-80">
                            <div class="rounded-3 glass d-flex align-items-center justify-content-center" style="width:48px;height:48px;"><i class="fas fa-map-marker-alt text-afrinex-gold"></i></div>
                            <div>
                                <div class="text-white-40 text-uppercase tracking-wider" style="font-size:0.75rem;">Siège social</div>
                                <div class="font-display fw-semibold">Abidjan, Côte d'Ivoire</div>
                            </div>
                        </div>
                    </div>
                    <a href="#" class="d-inline-flex align-items-center gap-3 bg-emerald-20 border border-emerald-30 text-emerald-400 font-display fw-semibold px-4 py-3 rounded-4 text-decoration-none hover-bg-emerald-30 transition-colors"><i class="fab fa-whatsapp fs-4"></i> Discuter sur WhatsApp</a>
                </div>
                <div class="col-lg-6">
                    <div class="glass rounded-4 p-4">
                        <h3 class="fs-5 font-display fw-bold text-white mb-4">Demander un devis</h3>
                        <form id="contactForm" class="d-flex flex-column gap-3">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <input type="text" name="nom" placeholder="Nom *" required class="form-glass" style="background:rgba(255,255,255,0.08);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);color:white;padding:1rem 1.25rem;border-radius:1rem;width:100%;">
                                </div>
                                <div class="col-sm-6">
                                    <input type="text" name="prenom" placeholder="Prénom *" required class="form-glass" style="background:rgba(255,255,255,0.08);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);color:white;padding:1rem 1.25rem;border-radius:1rem;width:100%;">
                                </div>
                            </div>
                            <input type="email" name="email" placeholder="Email professionnel *" required class="form-glass" style="background:rgba(255,255,255,0.08);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);color:white;padding:1rem 1.25rem;border-radius:1rem;width:100%;">
                            <input type="text" name="entreprise" placeholder="Entreprise *" required class="form-glass" style="background:rgba(255,255,255,0.08);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);color:white;padding:1rem 1.25rem;border-radius:1rem;width:100%;">
                            <select name="study_type" class="form-glass appearance-none cursor-pointer" style="background:rgba(255,255,255,0.08);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);color:white;padding:1rem 1.25rem;border-radius:1rem;width:100%;">
                                <option value="" class="text-afrinex-navy">Type d'étude souhaitée</option>
                                <option value="quantitative" class="text-afrinex-navy">Étude Quantitative</option>
                                <option value="qualitative" class="text-afrinex-navy">Étude Qualitative</option>
                                <option value="bi" class="text-afrinex-navy">Business Intelligence</option>
                                <option value="ml" class="text-afrinex-navy">Machine Learning</option>
                                <option value="sectoriel" class="text-afrinex-navy">Étude Sectorielle</option>
                                <option value="other" class="text-afrinex-navy">Autre</option>
                            </select>
                            <textarea name="message" rows="4" placeholder="Décrivez votre projet..." class="form-glass resize-none" style="background:rgba(255,255,255,0.08);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);color:white;padding:1rem 1.25rem;border-radius:1rem;width:100%;"></textarea>
                            <button type="submit" class="btn-primary w-100 justify-content-center">Envoyer ma demande <i class="fas fa-arrow-right"></i></button>
                            <p class="text-white-30 text-center" style="font-size:0.75rem;">Réponse sous 24h ouvrées. Vos données sont protégées conformément au RGPD.</p>
                            <div id="contactMessage" class="text-white-80 text-center" style="font-size:0.875rem;"></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php else: ?>
    <!-- ─── SECTION PERSONNALISÉE ─────────────────────── -->
    <section id="<?= htmlspecialchars($slug) ?>" class="py-5 bg-white">
        <div class="container-custom">
            <div class="text-center max-w-3xl mx-auto">
                <?= $section['content'] ?? '<p class="text-muted">Contenu de la section <strong>' . htmlspecialchars($slug) . '</strong></p>' ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

<?php endforeach; ?>

<!-- ============================================================ -->
<!-- JAVASCRIPT AJAX -->
<!-- ============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const carousel = document.getElementById('testimonialCarousel');
    if (carousel) {
        const dots = document.querySelectorAll('#customDots .custom-dot');
        if (dots.length) {
            carousel.addEventListener('slid.bs.carousel', function (event) {
                const index = event.to;
                dots.forEach((dot, i) => {
                    if (i === index) {
                        dot.classList.add('active');
                        dot.style.background = '#C9A227';
                    } else {
                        dot.classList.remove('active');
                        dot.style.background = 'transparent';
                    }
                });
            });
            dots.forEach(dot => {
                dot.addEventListener('click', function() {
                    const index = parseInt(this.dataset.slide);
                    const bsCarousel = bootstrap.Carousel.getInstance(carousel);
                    if (bsCarousel) {
                        bsCarousel.to(index);
                    } else {
                        const newCarousel = new bootstrap.Carousel(carousel);
                        newCarousel.to(index);
                    }
                });
            });
        }
    }

    let newsletterTimeout = null;
    let contactTimeout = null;

    const newsletterForm = document.getElementById('newsletterForm');
    if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const formData = new FormData(this);
            formData.append('ajax_action', 'newsletter');
            const msgEl = document.getElementById('newsletterMessage');
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            if (newsletterTimeout) { clearTimeout(newsletterTimeout); newsletterTimeout = null; }
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi...';
            msgEl.innerHTML = '';
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => { if (!response.ok) throw new Error('Erreur réseau: ' + response.status); return response.json(); })
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                if (data.success) {
                    msgEl.innerHTML = '<span style="color:#4ade80;">✅ ' + data.message + '</span>';
                    newsletterForm.reset();
                } else {
                    msgEl.innerHTML = '<span style="color:#f87171;">❌ ' + data.message + '</span>';
                }
                newsletterTimeout = setTimeout(() => { msgEl.innerHTML = ''; newsletterTimeout = null; }, 5000);
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                msgEl.innerHTML = '<span style="color:#f87171;">❌ ' + error.message + '</span>';
                newsletterTimeout = setTimeout(() => { msgEl.innerHTML = ''; newsletterTimeout = null; }, 5000);
            });
            return false;
        });
    }

    const contactForm = document.getElementById('contactForm');
    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const formData = new FormData(this);
            formData.append('ajax_action', 'contact');
            const msgEl = document.getElementById('contactMessage');
            const btn = this.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            if (contactTimeout) { clearTimeout(contactTimeout); contactTimeout = null; }
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
            msgEl.innerHTML = '';
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => { if (!response.ok) throw new Error('Erreur réseau: ' + response.status); return response.json(); })
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                if (data.success) {
                    msgEl.innerHTML = '<span style="color:#4ade80;">✅ ' + data.message + '</span>';
                    contactForm.reset();
                } else {
                    msgEl.innerHTML = '<span style="color:#f87171;">❌ ' + data.message + '</span>';
                }
                contactTimeout = setTimeout(() => { msgEl.innerHTML = ''; contactTimeout = null; }, 5000);
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                msgEl.innerHTML = '<span style="color:#f87171;">❌ ' + error.message + '</span>';
                contactTimeout = setTimeout(() => { msgEl.innerHTML = ''; contactTimeout = null; }, 5000);
            });
            return false;
        });
    }
});
</script>

<?php include $templatePath . 'footer.php'; ?>