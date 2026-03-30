<?php
/**
 * enregistrement.php
 * Traite le formulaire de la carte d'identité burundaise
 * et insère les données dans la base MySQL.
 */

// ─── 1. CONFIGURATION BASE DE DONNÉES ─────────────────────────────────────────
define('DB_HOST',     'localhost');
define('DB_NAME',     'ikarata_db');
define('DB_USER',     'root');      // Changer selon votre config
define('DB_PASS',     '');          // Changer selon votre config
define('DB_CHARSET',  'utf8mb4');

define('UPLOAD_DIR', __DIR__ . '/uploads/photos/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2 Mo
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// ─── 2. CONNEXION PDO ─────────────────────────────────────────────────────────
try{
    $db = new PDO('mysql:host=localhost;dbname=ikarata_db;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
echo "Connexion réussie à la base de données.";

}catch(PDOException $e){
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}
// ─── 3. CRÉATION DE LA TABLE (si elle n'existe pas) ───────────────────────────
function createTableIfNotExists($db): void {
    $sql = "
    CREATE TABLE IF NOT EXISTS cartes_identite (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        numero_mifp         VARCHAR(30)  NOT NULL UNIQUE,
        nom                 VARCHAR(80)  NOT NULL,
        prenom              VARCHAR(80)  NOT NULL,
        sexe                ENUM('M','F') NOT NULL,
        date_naissance      DATE         NOT NULL,
        nom_mere            VARCHAR(80)  DEFAULT NULL,
        nom_pere            VARCHAR(80)  DEFAULT NULL,
        etat_civil          VARCHAR(20)  DEFAULT NULL,
        profession          VARCHAR(100) DEFAULT NULL,
        ntarubaka           VARCHAR(100) DEFAULT NULL,
        province            VARCHAR(80)  NOT NULL,
        commune             VARCHAR(80)  NOT NULL,
        colline             VARCHAR(80)  DEFAULT NULL,
        date_emission       DATE         NOT NULL,
        commune_emission    VARCHAR(80)  NOT NULL,
        officier_etat_civil VARCHAR(120) DEFAULT NULL,
        photo_path          VARCHAR(255) DEFAULT NULL,
        created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $db->exec($sql);
    echo "Table 'cartes_identite' vérifiée/créée avec succès.";
}

// ─── 4. FONCTIONS UTILITAIRES ─────────────────────────────────────────────────

/**
 * Nettoie et valide une chaîne de caractères.
 */
function clean(string $value): string {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Valide une date au format Y-m-d.
 */
function validateDate(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Gère l'upload de la photo et retourne le chemin relatif.
 */
function handlePhotoUpload(array $file): ?string {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // Photo optionnelle
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Erreur lors de l'upload de la photo (code: {$file['error']}).");
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new RuntimeException("La photo dépasse la taille maximale autorisée (2 Mo).");
    }

    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_TYPES, true)) {
        throw new RuntimeException("Format d'image non autorisé. Utilisez JPEG, PNG ou WebP.");
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $extension  = pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniqueName = uniqid('photo_', true) . '.' . strtolower($extension);
    $destination = UPLOAD_DIR . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException("Impossible de déplacer le fichier uploadé.");
    }

    return 'uploads/photos/' . $uniqueName;
}

// ─── 5. TRAITEMENT PRINCIPAL ──────────────────────────────────────────────────

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Récupération et nettoyage des champs
    $data = [
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

    // ── Validations ──────────────────────────────────────────────────────────
    if (empty($data['numero_mifp'])) {
        $errors[] = "Le numéro MIFP est obligatoire.";
    } elseif (!preg_match('/^[\d\/\.\-]+$/', $data['numero_mifp'])) {
        $errors[] = "Format du numéro MIFP invalide (ex: 1504/111.147).";
    }

    if (empty($data['nom'])) {
        $errors[] = "Le nom est obligatoire.";
    }

    if (empty($data['prenom'])) {
        $errors[] = "Le prénom est obligatoire.";
    }

    if (!in_array($data['sexe'], ['M', 'F'], true)) {
        $errors[] = "Le sexe doit être M ou F.";
    }

    if (empty($data['date_naissance']) || !validateDate($data['date_naissance'])) {
        $errors[] = "La date de naissance est invalide ou manquante.";
    }

    if (empty($data['province'])) {
        $errors[] = "La province est obligatoire.";
    }

    if (empty($data['commune'])) {
        $errors[] = "La commune est obligatoire.";
    }

    if (empty($data['date_emission']) || !validateDate($data['date_emission'])) {
        $errors[] = "La date d'émission est invalide ou manquante.";
    }

    if (empty($data['commune_emission'])) {
        $errors[] = "La commune d'émission est obligatoire.";
    }

    // ── Upload photo ──────────────────────────────────────────────────────────
    $photoPath = null;
    if (empty($errors)) {
        try {
            $photoPath = handlePhotoUpload($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    // ── Insertion en base ─────────────────────────────────────────────────────
    if (empty($errors)) {
        try {
            //$pdo = getConnection();
            createTableIfNotExists($db);

            $stmt = $db->prepare("
                INSERT INTO cartes_identite (
                    numero_mifp, nom, prenom, sexe, date_naissance, nom_mere,nom_pere,
                    etat_civil, profession, ntarubaka,
                    province, commune, colline,
                    date_emission, commune_emission, officier_etat_civil,
                    photo_path
                ) VALUES (
                    :numero_mifp, :nom, :prenom, :sexe, :date_naissance, :nom_mere, :nom_pere,
                    :etat_civil, :profession, :ntarubaka,
                    :province, :commune, :colline,
                    :date_emission, :commune_emission, :officier_etat_civil,
                    :photo_path
                )
            ");

            $stmt->execute([
                ':numero_mifp'         => $data['numero_mifp'],
                ':nom'                 => $data['nom'],
                ':prenom'              => $data['prenom'],
                ':sexe'                => $data['sexe'],
                ':date_naissance'      => $data['date_naissance'],
                ':nom_mere'            => $data['nom_mere']             ?: null,
                ':nom_pere'            => $data['nom_pere']             ?: null,
                ':etat_civil'          => $data['etat_civil']           ?: null,
                ':profession'          => $data['profession']           ?: null,
                ':ntarubaka'           => $data['ntarubaka']            ?: null,
                ':province'            => $data['province'],
                ':commune'             => $data['commune'],
                ':colline'             => $data['colline']              ?: null,
                ':date_emission'       => $data['date_emission'],
                ':commune_emission'    => $data['commune_emission'],
                ':officier_etat_civil' => $data['officier_etat_civil']  ?: null,
                ':photo_path'          => $photoPath,
            ]);

            $insertedId = $db->lastInsertId();
            $success    = true;

        } catch (PDOException $e) {
            // Doublon sur numero_mifp
            if ($e->getCode() === '23000') {
                $errors[] = "Ce numéro MIFP existe déjà dans la base de données.";
            } else {
                $errors[] = "Erreur de base de données : " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Résultat — Ikarata Karangamuntu</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Source+Sans+3:wght@300;400;600&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Source Sans 3', sans-serif;
      background: linear-gradient(135deg, #0d2440 0%, #1a4a7a 50%, #0d2440 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 16px;
    }
    .result-card {
      background: #fff;
      border-radius: 16px;
      max-width: 520px;
      width: 100%;
      overflow: hidden;
      box-shadow: 0 24px 80px rgba(0,0,0,0.4);
    }
    .stripe { height: 8px; }
    .stripe-success { background: linear-gradient(90deg, #1a5c28, #4caf50, #c9922a); }
    .stripe-error   { background: linear-gradient(90deg, #7f1d1d, #c0392b, #c9922a); }
    .result-body { padding: 40px; }
    .icon { font-size: 3rem; text-align: center; margin-bottom: 16px; }
    h2 {
      font-family: 'Playfair Display', serif;
      text-align: center;
      font-size: 1.4rem;
      margin-bottom: 20px;
    }
    .success-msg { color: #1a5c28; }
    .error-msg   { color: #7f1d1d; }
    .detail {
      background: #f4f8fd;
      border-radius: 8px;
      padding: 16px 20px;
      font-size: 0.9rem;
      color: #2c3e50;
      margin-bottom: 8px;
    }
    .detail span { font-weight: 600; }
    ul.errors {
      list-style: none;
      padding: 0;
    }
    ul.errors li {
      background: #fde8e8;
      border-left: 4px solid #c0392b;
      padding: 10px 14px;
      margin-bottom: 8px;
      border-radius: 4px;
      font-size: 0.88rem;
      color: #7f1d1d;
    }
    .back-link {
      display: block;
      text-align: center;
      margin-top: 28px;
      padding: 12px;
      background: #1a3a5c;
      color: #fff;
      border-radius: 8px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.9rem;
      letter-spacing: 0.06em;
    }
    .back-link:hover { background: #2563a8; }
  </style>
</head>
<body>
<div class="result-card">
  <?php if ($success): ?>
    <div class="stripe stripe-success"></div>
    <div class="result-body">
      <div class="icon">✅</div>
      <h2 class="success-msg">Enregistrement réussi</h2>
      <div class="detail">N° MIFP : <span><?= htmlspecialchars($data['numero_mifp']) ?></span></div>
      <div class="detail">Nom complet : <span><?= htmlspecialchars($data['nom'] . ' ' . $data['prenom']) ?></span></div>
      <div class="detail">ID base de données : <span>#<?= (int)$insertedId ?></span></div>
      <?php if ($photoPath): ?>
      <div class="detail">Photo : <span><?= htmlspecialchars($photoPath) ?></span></div>
      <?php endif; ?>
      <a href="formulaire_identite.html" class="back-link">← Nouveau formulaire</a>
    </div>
  <?php else: ?>
    <div class="stripe stripe-error"></div>
    <div class="result-body">
      <div class="icon">❌</div>
      <h2 class="error-msg">Erreurs de validation</h2>
      <ul class="errors">
        <?php foreach ($errors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
      <a href="javascript:history.back()" class="back-link">← Corriger le formulaire</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>