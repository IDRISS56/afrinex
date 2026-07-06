<?php
// ============================================================
//  Fonctions utilitaires centralisées
// ============================================================

if (!function_exists('env')) {
    /**
     * Lit une variable du fichier .env (à la racine du projet).
     * Le fichier n'est lu qu'une seule fois par requête (cache statique).
     *
     * Usage : env('MAIL_USERNAME'), env('MAIL_PORT', 587)
     */
    function env(string $key, $default = null) {
        static $env = null;
        if ($env === null) {
            $path = __DIR__ . '/../.env';
            $env  = is_file($path) ? (parse_ini_file($path) ?: []) : [];
        }
        return $env[$key] ?? $default;
    }
}

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
    /**
     * Upload sécurisé d'une image.
     * - Vérifie le type MIME réel du fichier (magic bytes via finfo), jamais $_FILES[...]['type']
     *   qui est fourni par le client et donc falsifiable.
     * - Vérifie que le fichier est une image décodable (getimagesize).
     * - Le nom de fichier stocké est généré côté serveur (jamais le nom d'origine).
     *
     * @throws Exception si le fichier est absent, trop volumineux, ou n'est pas une image autorisée.
     */
    function uploadImage(array $file, string $subdir = 'images'): string {
        if (!isset($file['tmp_name'], $file['error']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Fichier invalide ou absent.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Erreur lors de l\'upload (code ' . $file['error'] . ').');
        }

        // Taille maximale : 5 Mo
        $maxSize = 5 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxSize) {
            throw new Exception('Le fichier dépasse la taille maximale autorisée (5 Mo).');
        }

        // Liste blanche : type MIME réel (vérifié via finfo) → extension de sortie
        $allowedMimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']);

        if ($realMime === false || !isset($allowedMimeToExt[$realMime])) {
            throw new Exception('Format non autorisé. Formats acceptés : JPG, PNG, GIF, WEBP.');
        }

        // Vérification supplémentaire : le contenu doit être une image réellement décodable
        // (empêche un fichier .php renommé avec un type MIME falsifié de passer le filtre)
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new Exception('Le fichier n\'est pas une image valide.');
        }

        $ext = $allowedMimeToExt[$realMime];

        $subdir    = trim(str_replace(['..', '\\'], '', $subdir), '/');
        $targetDir = __DIR__ . '/../uploads/' . ($subdir !== '' ? $subdir : 'images') . '/';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Nom de fichier généré côté serveur : jamais le nom d'origine (path traversal / double extension)
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $targetDir . $filename)) {
            throw new Exception('Erreur lors du déplacement du fichier.');
        }

        return $filename;
    }
}