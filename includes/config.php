<?php
// ============================================================
//  Configuration du CMS
// ============================================================

// Chemins absolus
define('ROOT_PATH', dirname(__DIR__) . '/');
define('ADMIN_PATH', ROOT_PATH . 'admin/');
define('PUBLIC_PATH', ROOT_PATH . 'public/');
define('INCLUDES_PATH', ROOT_PATH . 'includes/');
define('UPLOAD_PATH', ROOT_PATH . 'uploads/');
define('TEMPLATE_PATH', ROOT_PATH . 'templates/');
define('VENDOR_PATH', ROOT_PATH . 'vendor/');

// URLs
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$base = str_replace('/admin', '', $base);
$base = str_replace('/public', '', $base);
define('SITE_URL', $protocol . $host . $base . '/');
define('ADMIN_URL', SITE_URL . 'admin/');
define('PUBLIC_URL', SITE_URL . 'public/');
define('TEMPLATE_URL', SITE_URL . 'templates/');
define('UPLOAD_URL', SITE_URL . 'uploads/');


// Autres
define('SITE_NAME', 'AFRINEX Research');
define('SITE_DESC', 'Intelligence de Marché & Business Intelligence');
define('ADMIN_EMAIL', 'contact@afrinex.com');

// Erreurs (désactiver en production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Fuseau horaire
date_default_timezone_set('Europe/Paris');

// Démarrage de session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}