<?php
/**
 * enregistrement.php
 * Reçoit les données du formulaire via AJAX (fetch POST depuis formulaire_identite.html)
 * et renvoie toujours du JSON — jamais de HTML.
 */

header('Content-Type: application/json; charset=utf-8');

// Accès direct GET → réponse 405 en JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée. Utilisez le formulaire.']);
    exit;
}

// ─── 1. CONFIGURATION ────────────────────────────────────────────────────────
define('DB_HOST',     'localhost');
define('DB_NAME',     'ikarata_db');
define('DB_USER',     'root');
define('DB_PASS',     '');           // ← Changer si nécessaire
define('DB_CHARSET',  'utf8mb4');

define('UPLOAD_DIR',    __DIR__ . '/uploads/photos/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024);
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// ─── 2. CONNEXION PDO ────────────────────────────────────────────────────────
try {
    $db = new PDO(
        'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Connexion BDD impossible : '.$e->getMessage()]);
    exit;
}

// ─── 3. CRÉATION TABLE ───────────────────────────────────────────────────────
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cartes_identite (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            numero_mifp         VARCHAR(30)   NOT NULL UNIQUE,
            nom                 VARCHAR(80)   NOT NULL,
            prenom              VARCHAR(80)   NOT NULL,
            sexe                ENUM('M','F') NOT NULL,
            date_naissance      DATE          NOT NULL,
            nom_mere            VARCHAR(80)   DEFAULT NULL,
            nom_pere            VARCHAR(80)   DEFAULT NULL,
            etat_civil          VARCHAR(20)   DEFAULT NULL,
            profession          VARCHAR(100)  DEFAULT NULL,
            ntarubaka           VARCHAR(100)  DEFAULT NULL,
            province            VARCHAR(80)   NOT NULL,
            commune             VARCHAR(80)   NOT NULL,
            colline             VARCHAR(80)   DEFAULT NULL,
            date_emission       DATE          NOT NULL,
            commune_emission    VARCHAR(80)   NOT NULL,
            officier_etat_civil VARCHAR(120)  DEFAULT NULL,
            photo_path          VARCHAR(255)  DEFAULT NULL,
            created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur création table : '.$e->getMessage()]);
    exit;
}

// ─── 4. UTILITAIRES ──────────────────────────────────────────────────────────
function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}
function validDate(string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function uploadPhoto(array $f): ?string {
    if ($f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("Erreur upload photo (code {$f['error']}).");
    if ($f['size'] > MAX_FILE_SIZE)    throw new RuntimeException("Photo trop lourde (max 2 Mo).");
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, ALLOWED_TYPES, true)) throw new RuntimeException("Format non autorisé (JPEG, PNG ou WebP).");
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $name = uniqid('photo_', true).'.'.$ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR.$name))
        throw new RuntimeException("Impossible de sauvegarder la photo.");
    return 'uploads/photos/'.$name;
}

// ─── 5. LECTURE DES CHAMPS POST ──────────────────────────────────────────────
$d = [
    'numero_mifp'         => clean($_POST['numero_mifp']         ?? ''),
    'nom'                 => clean($_POST['nom']                  ?? ''),
    'prenom'              => clean($_POST['prenom']               ?? ''),
    'sexe'                => clean($_POST['sexe']                 ?? ''),
    'date_naissance'      => clean($_POST['date_naissance']       ?? ''),
    'nom_mere'            => clean($_POST['nom_mere']             ?? ''),
    'nom_pere'            => clean($_POST['nom_pere']             ?? ''),
    'etat_civil'          => clean($_POST['etat_civil']           ?? ''),
    'profession'          => clean($_POST['profession']           ?? ''),
    'ntarubaka'           => clean($_POST['ntarubaka']            ?? ''),
    'province'            => clean($_POST['province']             ?? ''),
    'commune'             => clean($_POST['commune']              ?? ''),
    'colline'             => clean($_POST['colline']              ?? ''),
    'date_emission'       => clean($_POST['date_emission']        ?? ''),
    'commune_emission'    => clean($_POST['commune_emission']     ?? ''),
    'officier_etat_civil' => clean($_POST['officier_etat_civil']  ?? ''),
];

// ─── 6. VALIDATION ───────────────────────────────────────────────────────────
$errors = [];
if (empty($d['numero_mifp']))                                         $errors[] = "Le numéro MIFP est obligatoire.";
elseif (!preg_match('/^[\d\/\.\-]+$/', $d['numero_mifp']))            $errors[] = "Format MIFP invalide (ex: 1504/111.147).";
if (empty($d['nom']))                                                  $errors[] = "Le nom est obligatoire.";
if (empty($d['prenom']))                                               $errors[] = "Le prénom est obligatoire.";
if (!in_array($d['sexe'], ['M','F'], true))                           $errors[] = "Le sexe est obligatoire (M ou F).";
if (empty($d['date_naissance']) || !validDate($d['date_naissance']))  $errors[] = "Date de naissance invalide ou manquante.";
if (empty($d['province']))                                             $errors[] = "La province est obligatoire.";
if (empty($d['commune']))                                              $errors[] = "La commune est obligatoire.";
if (empty($d['date_emission'])  || !validDate($d['date_emission']))   $errors[] = "Date d'émission invalide ou manquante.";
if (empty($d['commune_emission']))                                     $errors[] = "La commune d'émission est obligatoire.";

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// ─── 7. UPLOAD PHOTO ─────────────────────────────────────────────────────────
$photoPath = null;
try {
    $photoPath = uploadPhoto($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'errors' => [$e->getMessage()]]);
    exit;
}

// ─── 8. INSERTION EN BASE ────────────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        INSERT INTO cartes_identite (
            numero_mifp, nom, prenom, sexe, date_naissance,
            nom_mere, nom_pere, etat_civil, profession, ntarubaka,
            province, commune, colline,
            date_emission, commune_emission, officier_etat_civil, photo_path
        ) VALUES (
            :numero_mifp, :nom, :prenom, :sexe, :date_naissance,
            :nom_mere, :nom_pere, :etat_civil, :profession, :ntarubaka,
            :province, :commune, :colline,
            :date_emission, :commune_emission, :officier_etat_civil, :photo_path
        )
    ");
    $stmt->execute([
        ':numero_mifp'         => $d['numero_mifp'],
        ':nom'                 => $d['nom'],
        ':prenom'              => $d['prenom'],
        ':sexe'                => $d['sexe'],
        ':date_naissance'      => $d['date_naissance'],
        ':nom_mere'            => $d['nom_mere']            ?: null,
        ':nom_pere'            => $d['nom_pere']            ?: null,
        ':etat_civil'          => $d['etat_civil']          ?: null,
        ':profession'          => $d['profession']          ?: null,
        ':ntarubaka'           => $d['ntarubaka']           ?: null,
        ':province'            => $d['province'],
        ':commune'             => $d['commune'],
        ':colline'             => $d['colline']             ?: null,
        ':date_emission'       => $d['date_emission'],
        ':commune_emission'    => $d['commune_emission'],
        ':officier_etat_civil' => $d['officier_etat_civil'] ?: null,
        ':photo_path'          => $photoPath,
    ]);

    echo json_encode([
        'success' => true,
        'id'      => (int) $db->lastInsertId(),
        'nom'     => $d['nom'],
        'prenom'  => $d['prenom'],
        'mifp'    => $d['numero_mifp'],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'errors' => ["Ce numéro MIFP existe déjà en base de données."]]);
    } else {
        echo json_encode(['success' => false, 'errors' => ["Erreur BDD : ".$e->getMessage()]]);
    }
}
