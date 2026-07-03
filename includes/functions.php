<?php
// ============================================================
//  Fonctions utilitaires centralisées
// ============================================================

function getSetting($key, $default = '') {
    $row = Database::getInstance()->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $row ? $row['setting_value'] : $default;
}

function updateSetting($key, $value) {
    $db = Database::getInstance();
    $exists = $db->fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
    if ($exists) {
        $db->update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
    } else {
        $db->insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
    }
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    if (empty($text)) return 'n-a';
    return $text;
}

function generateUniqueSlug($slug, $table, $id = 0) {
    $db = Database::getInstance();
    $base = $slug;
    $i = 1;
    while (true) {
        $existing = $db->fetchOne("SELECT id FROM $table WHERE slug = ? AND id != ?", [$slug, $id]);
        if (!$existing) break;
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function formatDate($date, $format = 'd/m/Y') {
    return date($format, strtotime($date));
}

function truncateText($text, $length = 100, $suffix = '...') {
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . $suffix;
}

function uploadFile($file, $targetDir, $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']) {
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if (!in_array($file['type'], $allowedTypes)) return false;
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $basename = slugify(pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = $basename . '-' . time() . '.' . $ext;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $targetPath = $targetDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) return false;
    return $filename;
}

function getMenus($location = 'main') {
    return Database::getInstance()->fetchAll(
        "SELECT * FROM menus WHERE status = 1 AND menu_location = ? ORDER BY sort_order",
        [$location]
    );
}

function getPagesByType($type, $status = 'published') {
    return Database::getInstance()->fetchAll(
        "SELECT * FROM pages WHERE type = ? AND status = ? ORDER BY sort_order",
        [$type, $status]
    );
}

function getTemoignages($status = 'published') {
    return Database::getInstance()->fetchAll(
        "SELECT * FROM temoignages WHERE status = ? ORDER BY sort_order",
        [$status]
    );
}

function getPageBySlug($slug) {
    return Database::getInstance()->fetchOne("SELECT * FROM pages WHERE slug = ? AND status = 'published'", [$slug]);
}

function isMaintenance() {
    return getSetting('maintenance_mode', '0') === '1';
}

if (!function_exists('uploadImage')) {
    function uploadImage(array $file, string $subdir = 'images'): string {
        $targetDir = __DIR__ . '/../uploads/images/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
            throw new Exception('Erreur lors de l\'upload');
        }
        return $filename;
    }
}