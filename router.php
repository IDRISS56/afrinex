<?php
// appel des controllers 
$fichiers = scandir("./controllers");

for ($i=2; $i < count($fichiers); $i++) { 
    require "controllers/".$fichiers[$i];
}

// ─── Détecter c et a depuis GET ou POST ───
$controller = $_GET['c'] ?? $_POST['c'] ?? null;
$action     = $_GET['a'] ?? $_POST['a'] ?? null;

if ($controller && $action) {
    if (class_exists($controller) && method_exists($controller, $action)) {
        $cont = new $controller();
        $cont->$action();
    } else {
        echo "404";
    }
} else {
    // Page par défaut
    $cont = new app();
    $cont->accueil();
}
?>