<?php
/**
 * check_mifp.php
 * Endpoint AJAX — vérifie si un N° MIFP existe déjà en base de données
 * Retourne JSON : { "exists": true|false }
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'identite_db');
define('DB_USER', 'root');
define('DB_PASS', '');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

// Récupérer le numéro passé en GET
$num = trim($_GET['num_mifp'] ?? '');

if ($num === '') {
    echo json_encode(['exists' => false]);
    exit;
}

// Paramètre optionnel : exclure un ID lors d'une mise à jour (admin edit)
$excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

try {
    $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($excludeId > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM personne WHERE num_mifp = :num AND id != :id");
        $stmt->execute([':num' => $num, ':id' => $excludeId]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM personne WHERE num_mifp = :num");
        $stmt->execute([':num' => $num]);
    }

    $count = (int)$stmt->fetchColumn();
    echo json_encode(['exists' => $count > 0]);

} catch (PDOException $e) {
    // En cas d'erreur BD, on renvoie exists=false (la BD elle-même bloquera les doublons)
    echo json_encode(['exists' => false, 'error' => 'db_error']);
}
