<?php
/**
 * enregistrement.php — v2
 * Traite l'enregistrement de la carte d'identité burundaise
 * Nouveautés : champ téléphone, validation dates côté serveur,
 *              double vérification unicité MIFP
 */

define('DB_HOST',    'localhost');
define('DB_NAME',    'identite_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5 Mo

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

// ─── Connexion PDO ───────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ─── Créer table si nécessaire ───────────────────────────────────────────────
function ensureTable(): void {
    getDB()->exec("
        CREATE TABLE IF NOT EXISTS personne (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            izina           VARCHAR(100)  NOT NULL,
            amatazirano     VARCHAR(100)  NOT NULL,
            se              VARCHAR(150),
            nyina           VARCHAR(150),
            provensi        VARCHAR(100),
            komine          VARCHAR(100),
            yavukiye        VARCHAR(100),
            italiki         DATE,
            genre           CHAR(1),
            arubatse        VARCHAR(50),
            ntarubaka       VARCHAR(150),
            akazi_akora     VARCHAR(150),
            num_mifp        VARCHAR(50) UNIQUE,
            itangiwe_i      VARCHAR(100),
            date_delivrance DATE,
            uwuyitanze      VARCHAR(150),
            telephone       VARCHAR(30),
            photo           VARCHAR(255),
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    // Ajouter la colonne telephone si elle n'existe pas (migration)
    try {
        getDB()->exec("ALTER TABLE personne ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) AFTER uwuyitanze");
    } catch (Exception $e) { /* ignore si déjà présente */ }
}

function clean(?string $v): string {
    return trim(htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'));
}

function redirect(string $page, array $params): void {
    $qs = http_build_query($params);
    header("Location: $page?$qs");
    exit;
}

// ─── Traitement POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: identite.html");
    exit;
}

ensureTable();
$errors = [];

// ── 1. Champs obligatoires ──────────────────────────────────────────────────
$required = ['izina', 'amatazirano', 'se', 'nyina', 'italiki', 'num_mifp',
             'provensi', 'komine', 'yavukiye', 'genre'];
foreach ($required as $f) {
    if (empty(trim($_POST[$f] ?? ''))) {
        $errors[] = "Le champ « $f » est obligatoire.";
    }
}

// ── 2. Validation date de naissance ────────────────────────────────────────
if (!empty($_POST['italiki'])) {
    $dob = DateTime::createFromFormat('Y-m-d', $_POST['italiki']);
    if (!$dob) {
        $errors[] = "Format de date de naissance invalide.";
    } else {
        $minDOB = new DateTime('1920-01-01');
        $today  = new DateTime('today');
        if ($dob < $minDOB) {
            $errors[] = "La date de naissance ne peut pas être avant le 01/01/1920.";
        } elseif ($dob > $today) {
            $errors[] = "La date de naissance ne peut pas être dans le futur.";
        }
    }
}

// ── 3. Validation date de délivrance — pas dans le futur ────────────────────
if (!empty($_POST['date_delivrance'])) {
    $dDel = DateTime::createFromFormat('Y-m-d', $_POST['date_delivrance']);
    if (!$dDel) {
        $errors[] = "Format de date de délivrance invalide.";
    } elseif ($dDel > new DateTime('today')) {
        $errors[] = "La date de délivrance ne peut pas être dans le futur.";
    }
}

// ── 4. Validation téléphone (optionnel) ─────────────────────────────────────
$telephone = '';
if (!empty(trim($_POST['telephone'] ?? ''))) {
    $telRaw = trim($_POST['telephone']);
    $digits = preg_replace('/\D/', '', $telRaw); // garder seulement les chiffres
    if (strlen($digits) < 7 || strlen($digits) > 9) {
        $errors[] = "Le numéro de téléphone doit contenir 7 à 9 chiffres (hors indicatif +257).";
    } else {
        // Formatter : XX XXX XXX
        $telephone = '+257 ' . chunk_split($digits, 2, ' ');
        $telephone = rtrim($telephone);
    }
}

// ── 5. Vérification unicité N° MIFP (double sécurité côté serveur) ──────────
if (!empty($_POST['num_mifp'])) {
    $pdo = getDB();
    $chk = $pdo->prepare("SELECT COUNT(*) FROM personne WHERE num_mifp = ?");
    $chk->execute([clean($_POST['num_mifp'])]);
    if ((int)$chk->fetchColumn() > 0) {
        $errors[] = "Ce N° MIFP est déjà enregistré dans le système.";
    }
}

// ── 6. Gestion de la photo ───────────────────────────────────────────────────
$photoPath = '';
if (!empty($_FILES['photo']['name'])) {
    $file    = $_FILES['photo'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $mime    = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];

    if (!in_array($mime, $allowed)) {
        $errors[] = "Format de photo invalide (JPG, PNG, WEBP uniquement).";
    } elseif ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = "La photo ne doit pas dépasser 5 Mo.";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Erreur lors de l'upload de la photo (code: {$file['error']}).";
    } else {
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid('photo_', true) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
            $errors[] = "Impossible d'enregistrer la photo sur le serveur.";
        } else {
            $photoPath = 'uploads/' . $filename;
        }
    }
}

// ── 7. Si erreurs → retour ───────────────────────────────────────────────────
if (!empty($errors)) {
    redirect('identite.html', ['error' => implode(' | ', $errors)]);
}

// ── 8. Insertion en base de données ─────────────────────────────────────────
try {
    $pdo  = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO personne
            (izina, amatazirano, se, nyina, provensi, komine, yavukiye,
             italiki, genre, arubatse, ntarubaka, akazi_akora,
             num_mifp, itangiwe_i, date_delivrance, uwuyitanze,
             telephone, photo)
        VALUES
            (:izina, :amatazirano, :se, :nyina, :provensi, :komine, :yavukiye,
             :italiki, :genre, :arubatse, :ntarubaka, :akazi_akora,
             :num_mifp, :itangiwe_i, :date_delivrance, :uwuyitanze,
             :telephone, :photo)
    ");

    $stmt->execute([
        ':izina'           => clean($_POST['izina']),
        ':amatazirano'     => clean($_POST['amatazirano']),
        ':se'              => clean($_POST['se']),
        ':nyina'           => clean($_POST['nyina']),
        ':provensi'        => clean($_POST['provensi']),
        ':komine'          => clean($_POST['komine']),
        ':yavukiye'        => clean($_POST['yavukiye']),
        ':italiki'         => clean($_POST['italiki']),
        ':genre'           => clean($_POST['genre']),
        ':arubatse'        => clean($_POST['arubatse'] ?? '-'),
        ':ntarubaka'       => clean($_POST['ntarubaka'] ?? ''),
        ':akazi_akora'     => clean($_POST['akazi_akora'] ?? ''),
        ':num_mifp'        => clean($_POST['num_mifp']),
        ':itangiwe_i'      => clean($_POST['itangiwe_i'] ?? ''),
        ':date_delivrance' => !empty($_POST['date_delivrance']) ? clean($_POST['date_delivrance']) : null,
        ':uwuyitanze'      => clean($_POST['uwuyitanze'] ?? ''),
        ':telephone'       => $telephone,
        ':photo'           => $photoPath,
    ]);

    redirect('identite.html', ['success' => 1]);

} catch (PDOException $e) {
    $msg = ($e->getCode() == 23000)
        ? "Ce N° MIFP est déjà enregistré en base de données."
        : "Erreur BD : " . $e->getMessage();
    redirect('identite.html', ['error' => $msg]);
}
