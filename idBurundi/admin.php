<?php
/**
 * admin.php — Panneau d'administration Ikarata Karangamuntu
 * Opérations : Lister, Ajouter, Modifier, Supprimer, Afficher
 */

session_start();

// ─── CONFIGURATION ────────────────────────────────────────────────────────────
define('UPLOAD_DIR',    __DIR__ . '/uploads/photos/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024);
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
define('ADMIN_USER',    'Fulgence');
define('ADMIN_PASS',    'fun123@'); // À changer en production

// ─── CONNEXION PDO ────────────────────────────────────────────────────────────
try {
    $db = new PDO('mysql:host=localhost;dbname=ikarata_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("<div style='font-family:monospace;padding:30px;background:#1a1a2e;color:#e74c3c'>
         <h2>Erreur de connexion MySQL</h2><p>" . htmlspecialchars($e->getMessage()) . "</p></div>");
}

// ─── CRÉATION TABLE ───────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS cartes_identite (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero_mifp VARCHAR(30) NOT NULL UNIQUE,
    nom VARCHAR(80) NOT NULL,
    prenom VARCHAR(80) NOT NULL,
    sexe ENUM('M','F') NOT NULL,
    date_naissance DATE NOT NULL,
    nom_mere VARCHAR(80) DEFAULT NULL,
    nom_pere VARCHAR(80) DEFAULT NULL,
    etat_civil VARCHAR(20) DEFAULT NULL,
    profession VARCHAR(100) DEFAULT NULL,
    ntarubaka VARCHAR(100) DEFAULT NULL,
    province VARCHAR(80) NOT NULL,
    commune VARCHAR(80) NOT NULL,
    colline VARCHAR(80) DEFAULT NULL,
    date_emission DATE NOT NULL,
    commune_emission VARCHAR(80) NOT NULL,
    officier_etat_civil VARCHAR(120) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ─── AUTHENTIFICATION ─────────────────────────────────────────────────────────
if (isset($_POST['login'])) {
    if ($_POST['username'] === ADMIN_USER && $_POST['password'] === ADMIN_PASS) {
        $_SESSION['admin'] = true;
    } else {
        $loginError = "Identifiants incorrects.";
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ─── FONCTIONS ────────────────────────────────────────────────────────────────
function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
}
function valDate(string $d): bool {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}
function uploadPhoto(array $f, ?string $old = null): ?string {
    if ($f['error'] === UPLOAD_ERR_NO_FILE) return $old;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("Erreur upload photo.");
    if ($f['size'] > MAX_FILE_SIZE) throw new RuntimeException("Photo trop grande (max 2 Mo).");
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, ALLOWED_TYPES, true)) throw new RuntimeException("Format non autorisé.");
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $name = uniqid('photo_', true) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], UPLOAD_DIR . $name);
    if ($old && file_exists(__DIR__ . '/' . $old)) @unlink(__DIR__ . '/' . $old);
    return 'uploads/photos/' . $name;
}

// ─── ACTIONS CRUD ─────────────────────────────────────────────────────────────
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';
$msgType = '';
$record  = null;
$errors  = [];

if (!empty($_SESSION['admin'])) {

    // SUPPRIMER
    if ($action === 'delete' && $id > 0) {
        $row = $db->query("SELECT photo_path FROM cartes_identite WHERE id=$id")->fetch();
        if ($row) {
            if ($row['photo_path'] && file_exists(__DIR__.'/'.$row['photo_path']))
                @unlink(__DIR__.'/'.$row['photo_path']);
            $db->exec("DELETE FROM cartes_identite WHERE id=$id");
            $message = "Carte supprimée avec succès.";
            $msgType = 'success';
        }
        $action = 'list';
    }

    // CHARGER POUR MODIFIER
    if ($action === 'edit' && $id > 0) {
        $record = $db->query("SELECT * FROM cartes_identite WHERE id=$id")->fetch();
        if (!$record) { $action = 'list'; }
    }

    // SAUVEGARDER (ajout ou modification)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
        $d = [
            'numero_mifp'         => clean($_POST['numero_mifp'] ?? ''),
            'nom'                 => clean($_POST['nom'] ?? ''),
            'prenom'              => clean($_POST['prenom'] ?? ''),
            'sexe'                => clean($_POST['sexe'] ?? ''),
            'date_naissance'      => clean($_POST['date_naissance'] ?? ''),
            'nom_mere'            => clean($_POST['nom_mere'] ?? ''),
            'nom_pere'            => clean($_POST['nom_pere'] ?? ''),
            'etat_civil'          => clean($_POST['etat_civil'] ?? ''),
            'profession'          => clean($_POST['profession'] ?? ''),
            'ntarubaka'           => clean($_POST['ntarubaka'] ?? ''),
            'province'            => clean($_POST['province'] ?? ''),
            'commune'             => clean($_POST['commune'] ?? ''),
            'colline'             => clean($_POST['colline'] ?? ''),
            'date_emission'       => clean($_POST['date_emission'] ?? ''),
            'commune_emission'    => clean($_POST['commune_emission'] ?? ''),
            'officier_etat_civil' => clean($_POST['officier_etat_civil'] ?? ''),
        ];
        $editId = (int)($_POST['edit_id'] ?? 0);

        if (empty($d['numero_mifp'])) $errors[] = "N° MIFP obligatoire.";
        if (empty($d['nom']))         $errors[] = "Nom obligatoire.";
        if (empty($d['prenom']))      $errors[] = "Prénom obligatoire.";
        if (!in_array($d['sexe'], ['M','F'], true)) $errors[] = "Sexe invalide.";
        if (!valDate($d['date_naissance'])) $errors[] = "Date de naissance invalide.";
        if (!valDate($d['date_emission']))  $errors[] = "Date d'émission invalide.";
        if (empty($d['province']))          $errors[] = "Province obligatoire.";
        if (empty($d['commune']))           $errors[] = "Commune obligatoire.";
        if (empty($d['commune_emission']))  $errors[] = "Commune d'émission obligatoire.";

        $photoPath = null;
        if (empty($errors)) {
            try {
                $oldPhoto = $editId > 0
                    ? ($db->query("SELECT photo_path FROM cartes_identite WHERE id=$editId")->fetchColumn() ?: null)
                    : null;
                $photoPath = uploadPhoto($_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE], $oldPhoto);
            } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
        }

        if (empty($errors)) {
            try {
                if ($editId > 0) {
                    $sql = "UPDATE cartes_identite SET
                        numero_mifp=:numero_mifp, nom=:nom, prenom=:prenom, sexe=:sexe,
                        date_naissance=:date_naissance, nom_mere=:nom_mere, nom_pere=:nom_pere,
                        etat_civil=:etat_civil, profession=:profession, ntarubaka=:ntarubaka,
                        province=:province, commune=:commune, colline=:colline,
                        date_emission=:date_emission, commune_emission=:commune_emission,
                        officier_etat_civil=:officier_etat_civil
                        " . ($photoPath !== null ? ", photo_path=:photo_path" : "") . "
                        WHERE id=:id";
                    $p = $d;
                    $p[':id'] = $editId;
                    if ($photoPath !== null) $p[':photo_path'] = $photoPath;
                    $stmt = $db->prepare($sql);
                    $params = [];
                    foreach ($d as $k => $v) $params[':'.$k] = $v ?: null;
                    $params[':id'] = $editId;
                    if ($photoPath !== null) $params[':photo_path'] = $photoPath;
                    $stmt->execute($params);
                    $message = "Carte modifiée avec succès.";
                } else {
                    $stmt = $db->prepare("INSERT INTO cartes_identite
                        (numero_mifp,nom,prenom,sexe,date_naissance,nom_mere,nom_pere,etat_civil,
                         profession,ntarubaka,province,commune,colline,date_emission,commune_emission,
                         officier_etat_civil,photo_path)
                        VALUES (:numero_mifp,:nom,:prenom,:sexe,:date_naissance,:nom_mere,:nom_pere,
                                :etat_civil,:profession,:ntarubaka,:province,:commune,:colline,
                                :date_emission,:commune_emission,:officier_etat_civil,:photo_path)");
                    $params = [];
                    foreach ($d as $k => $v) $params[':'.$k] = $v ?: null;
                    $params[':photo_path'] = $photoPath;
                    $stmt->execute($params);
                    $message = "Carte ajoutée avec succès.";
                }
                $msgType = 'success';
                $action  = 'list';
            } catch (PDOException $e) {
                $errors[] = $e->getCode() === '23000'
                    ? "Ce N° MIFP existe déjà."
                    : "Erreur BD : " . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $action = $editId > 0 ? 'edit' : 'add';
            if ($editId > 0) $record = $db->query("SELECT * FROM cartes_identite WHERE id=$editId")->fetch();
            $message = implode(' | ', $errors);
            $msgType = 'error';
        }
    }

    // LISTE avec recherche
    $search = clean($_GET['q'] ?? '');
    $where  = $search
        ? "WHERE nom LIKE :q OR prenom LIKE :q OR numero_mifp LIKE :q OR province LIKE :q"
        : "";
    $countStmt = $db->prepare("SELECT COUNT(*) FROM cartes_identite $where");
    if ($search) $countStmt->execute([':q' => "%$search%"]);
    else $countStmt->execute();
    $total = (int)$countStmt->fetchColumn();

    $perPage = 10;
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;
    $pages   = (int)ceil($total / $perPage);

    $listStmt = $db->prepare("SELECT * FROM cartes_identite $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    if ($search) $listStmt->execute([':q' => "%$search%"]);
    else $listStmt->execute();
    $rows = $listStmt->fetchAll();

    // AFFICHER UN ENREGISTREMENT
    if ($action === 'view' && $id > 0) {
        $record = $db->query("SELECT * FROM cartes_identite WHERE id=$id")->fetch();
        if (!$record) $action = 'list';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administration — Ikarata Karangamuntu</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg:        #0b1220;
  --surface:   #111827;
  --surface2:  #1a2438;
  --border:    #1e2d45;
  --accent:    #3b82f6;
  --accent2:   #60a5fa;
  --gold:      #f59e0b;
  --red:       #ef4444;
  --green:     #10b981;
  --text:      #e2e8f0;
  --muted:     #64748b;
  --white:     #ffffff;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

body{
  font-family:'DM Sans',sans-serif;
  background:var(--bg);
  color:var(--text);
  min-height:100vh;
}

/* ── LOGIN ── */
.login-wrap{
  min-height:100vh;
  display:flex;align-items:center;justify-content:center;
  background:radial-gradient(ellipse at 30% 50%, #0d2251 0%, var(--bg) 60%);
}
.login-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:20px;
  padding:52px 44px;
  width:100%;max-width:400px;
  box-shadow:0 32px 80px rgba(0,0,0,0.6);
}
.login-logo{text-align:center;margin-bottom:36px}
.login-logo h1{font-family:'DM Serif Display',serif;font-size:1.6rem;color:var(--white);margin-bottom:4px}
.login-logo p{font-size:0.78rem;color:var(--muted);letter-spacing:0.15em;text-transform:uppercase}
.login-logo .dot{
  width:56px;height:56px;border-radius:50%;
  background:linear-gradient(135deg,#1d4ed8,#3b82f6);
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 16px;
  font-size:1.5rem;
}
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:0.75rem;font-weight:600;color:var(--muted);letter-spacing:0.1em;text-transform:uppercase;margin-bottom:7px}
.form-group input{
  width:100%;height:46px;padding:0 14px;
  background:var(--surface2);border:1.5px solid var(--border);border-radius:10px;
  color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.95rem;outline:none;
  transition:border-color 0.2s,box-shadow 0.2s;
}
.form-group input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,0.15)}
.btn-login{
  width:100%;height:48px;margin-top:8px;
  background:linear-gradient(135deg,#1d4ed8,#3b82f6);
  border:none;border-radius:10px;color:#fff;
  font-family:'DM Sans',sans-serif;font-size:0.9rem;font-weight:600;
  cursor:pointer;letter-spacing:0.06em;
  box-shadow:0 4px 20px rgba(59,130,246,0.35);
  transition:transform 0.15s,box-shadow 0.2s;
}
.btn-login:hover{transform:translateY(-1px);box-shadow:0 6px 28px rgba(59,130,246,0.5)}
.login-err{background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;
  padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;text-align:center}

/* ── LAYOUT ADMIN ── */
.admin-wrap{display:flex;min-height:100vh}

/* Sidebar */
.sidebar{
  width:240px;min-height:100vh;
  background:var(--surface);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  position:sticky;top:0;height:100vh;overflow-y:auto;
  flex-shrink:0;
}
.sidebar-logo{
  padding:28px 20px 20px;
  border-bottom:1px solid var(--border);
}
.sidebar-logo h1{font-family:'DM Serif Display',serif;font-size:1.1rem;color:var(--white);line-height:1.3}
.sidebar-logo p{font-size:0.68rem;color:var(--muted);letter-spacing:0.12em;margin-top:2px;text-transform:uppercase}
.sidebar-logo .badge{
  display:inline-block;background:rgba(59,130,246,0.15);color:var(--accent2);
  border:1px solid rgba(59,130,246,0.3);border-radius:20px;
  font-size:0.62rem;padding:2px 8px;margin-top:8px;letter-spacing:0.1em;
}

.sidebar-nav{padding:16px 12px;flex:1}
.nav-label{font-size:0.62rem;color:var(--muted);letter-spacing:0.18em;text-transform:uppercase;
  padding:0 8px;margin-bottom:8px;margin-top:16px}
.nav-item{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;border-radius:10px;
  color:var(--muted);font-size:0.88rem;font-weight:500;
  text-decoration:none;cursor:pointer;
  transition:background 0.15s,color 0.15s;
  border:none;background:none;width:100%;text-align:left;
}
.nav-item:hover{background:rgba(255,255,255,0.05);color:var(--text)}
.nav-item.active{background:rgba(59,130,246,0.15);color:var(--accent2)}
.nav-item svg{width:16px;height:16px;flex-shrink:0}

.sidebar-footer{padding:16px 20px;border-top:1px solid var(--border)}
.sidebar-footer a{
  display:flex;align-items:center;gap:8px;
  color:var(--muted);font-size:0.82rem;text-decoration:none;
  transition:color 0.2s;
}
.sidebar-footer a:hover{color:var(--red)}

/* Main */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}

.topbar{
  height:64px;padding:0 32px;
  background:var(--surface);border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:10;
}
.topbar-title{font-family:'DM Serif Display',serif;font-size:1.25rem;color:var(--white)}
.topbar-right{display:flex;align-items:center;gap:16px}
.admin-badge{
  background:rgba(245,158,11,0.12);color:var(--gold);
  border:1px solid rgba(245,158,11,0.25);border-radius:20px;
  font-size:0.72rem;font-weight:600;padding:4px 12px;letter-spacing:0.08em;
}

.content{padding:32px;flex:1;overflow-y:auto}

/* Stats */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:32px}
.stat-card{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:20px 22px;
  transition:border-color 0.2s,transform 0.2s;
}
.stat-card:hover{border-color:var(--accent);transform:translateY(-2px)}
.stat-card .val{font-family:'DM Serif Display',serif;font-size:2rem;color:var(--white);line-height:1}
.stat-card .lbl{font-size:0.75rem;color:var(--muted);margin-top:6px;letter-spacing:0.08em;text-transform:uppercase}
.stat-card .ico{font-size:1.4rem;margin-bottom:10px}

/* Alert */
.alert{
  padding:12px 18px;border-radius:10px;margin-bottom:24px;
  font-size:0.88rem;display:flex;align-items:center;gap:10px;
}
.alert-success{background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:#6ee7b7}
.alert-error  {background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#fca5a5}

/* Search & actions bar */
.bar{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.search-box{
  flex:1;min-width:200px;position:relative;
}
.search-box input{
  width:100%;height:40px;padding:0 14px 0 38px;
  background:var(--surface);border:1.5px solid var(--border);border-radius:10px;
  color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;
  transition:border-color 0.2s;
}
.search-box input:focus{border-color:var(--accent)}
.search-box svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--muted)}

/* Buttons */
.btn{
  display:inline-flex;align-items:center;gap:7px;
  height:40px;padding:0 18px;border-radius:10px;
  font-family:'DM Sans',sans-serif;font-size:0.84rem;font-weight:600;
  cursor:pointer;text-decoration:none;border:none;
  transition:all 0.2s;white-space:nowrap;
}
.btn-primary{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;box-shadow:0 4px 14px rgba(59,130,246,0.3)}
.btn-primary:hover{box-shadow:0 6px 20px rgba(59,130,246,0.5);transform:translateY(-1px)}
.btn-warning{background:rgba(245,158,11,0.15);color:var(--gold);border:1px solid rgba(245,158,11,0.3)}
.btn-warning:hover{background:rgba(245,158,11,0.25)}
.btn-danger{background:rgba(239,68,68,0.12);color:#f87171;border:1px solid rgba(239,68,68,0.25)}
.btn-danger:hover{background:rgba(239,68,68,0.22)}
.btn-info{background:rgba(59,130,246,0.12);color:var(--accent2);border:1px solid rgba(59,130,246,0.25)}
.btn-info:hover{background:rgba(59,130,246,0.22)}
.btn-ghost{background:transparent;color:var(--muted);border:1px solid var(--border)}
.btn-ghost:hover{color:var(--text);border-color:var(--muted)}
.btn-sm{height:32px;padding:0 12px;font-size:0.78rem;border-radius:8px}

/* Table */
.table-wrap{
  background:var(--surface);border:1px solid var(--border);border-radius:16px;
  overflow:hidden;
}
table{width:100%;border-collapse:collapse}
thead tr{border-bottom:1px solid var(--border)}
thead th{
  padding:14px 16px;text-align:left;
  font-size:0.7rem;font-weight:600;color:var(--muted);
  letter-spacing:0.12em;text-transform:uppercase;
  background:var(--surface2);
}
tbody tr{border-bottom:1px solid rgba(255,255,255,0.04);transition:background 0.15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:rgba(255,255,255,0.03)}
tbody td{padding:13px 16px;font-size:0.88rem;vertical-align:middle}
.td-photo{width:48px}
.photo-thumb{
  width:38px;height:48px;border-radius:6px;
  object-fit:cover;background:var(--surface2);
  border:1px solid var(--border);display:block;
}
.photo-placeholder-sm{
  width:38px;height:48px;border-radius:6px;
  background:var(--surface2);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:1.1rem;
}
.td-name{font-weight:600;color:var(--white)}
.td-mifp{font-family:monospace;font-size:0.82rem;color:var(--accent2)}
.badge-m{background:rgba(59,130,246,0.15);color:#93c5fd;border:1px solid rgba(59,130,246,0.25);border-radius:20px;font-size:0.7rem;padding:2px 9px}
.badge-f{background:rgba(236,72,153,0.15);color:#f9a8d4;border:1px solid rgba(236,72,153,0.25);border-radius:20px;font-size:0.7rem;padding:2px 9px}
.actions-td{display:flex;gap:6px;align-items:center}

/* Pagination */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-top:1px solid var(--border)}
.pagination-info{font-size:0.8rem;color:var(--muted)}
.pagination-btns{display:flex;gap:6px}
.page-btn{
  width:34px;height:34px;border-radius:8px;border:1px solid var(--border);
  background:transparent;color:var(--muted);font-size:0.82rem;cursor:pointer;
  display:flex;align-items:center;justify-content:center;text-decoration:none;
  transition:all 0.15s;
}
.page-btn:hover{border-color:var(--accent);color:var(--accent2)}
.page-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}

/* Panel (formulaire add/edit + vue détail) */
.panel{
  background:var(--surface);border:1px solid var(--border);border-radius:16px;
  overflow:hidden;
}
.panel-head{
  padding:20px 28px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  background:var(--surface2);
}
.panel-head h2{font-family:'DM Serif Display',serif;font-size:1.2rem;color:var(--white)}
.panel-body{padding:28px}

/* Formulaire add/edit */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px}
.form-full{grid-column:1/-1}
.f-group{display:flex;flex-direction:column;gap:6px}
.f-group label{font-size:0.7rem;font-weight:600;color:var(--muted);letter-spacing:0.1em;text-transform:uppercase}
.f-group input,
.f-group select{
  height:42px;padding:0 12px;
  background:var(--bg);border:1.5px solid var(--border);border-radius:9px;
  color:var(--text);font-family:'DM Sans',sans-serif;font-size:0.88rem;outline:none;
  transition:border-color 0.2s,box-shadow 0.2s;
}
.f-group input:focus,.f-group select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,0.12)}
.f-group select{
  appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2360a5fa' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;
}
.section-sep{
  grid-column:1/-1;
  display:flex;align-items:center;gap:12px;margin:4px 0;
}
.section-sep::before,.section-sep::after{content:'';flex:1;height:1px;background:var(--border)}
.section-sep span{font-size:0.65rem;font-weight:600;color:var(--muted);letter-spacing:0.18em;text-transform:uppercase;white-space:nowrap}
.divider-panel{grid-column:1/-1;border:none;border-top:1px solid var(--border);margin:4px 0}

/* Upload photo dans formulaire */
.photo-upload-zone{
  width:100px;height:130px;
  border:2px dashed var(--border);border-radius:10px;
  background:var(--bg);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  cursor:pointer;position:relative;overflow:hidden;
  transition:border-color 0.2s;
}
.photo-upload-zone:hover{border-color:var(--accent)}
.photo-upload-zone input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.photo-upload-zone svg{width:28px;height:28px;color:var(--muted);margin-bottom:6px}
.photo-upload-zone p{font-size:0.62rem;color:var(--muted);text-align:center;line-height:1.4;padding:0 6px}
#edit-preview{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:none;border-radius:8px}
.photo-hint{font-size:0.7rem;color:var(--muted);margin-top:6px}

/* Vue détail */
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:0}
.detail-item{padding:16px 20px;border-bottom:1px solid var(--border);border-right:1px solid var(--border)}
.detail-item:nth-child(3n){border-right:none}
.detail-item:nth-last-child(-n+3){border-bottom:none}
.detail-lbl{font-size:0.65rem;color:var(--muted);letter-spacing:0.12em;text-transform:uppercase;margin-bottom:5px}
.detail-val{font-size:0.92rem;color:var(--white);font-weight:500}
.detail-photo-wrap{padding:24px 28px;border-bottom:1px solid var(--border);display:flex;gap:24px;align-items:flex-start}
.detail-photo{width:90px;height:115px;object-fit:cover;border-radius:8px;border:2px solid var(--border)}
.detail-photo-placeholder{width:90px;height:115px;border-radius:8px;border:2px solid var(--border);
  background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:2rem}
.detail-mifp{font-family:'DM Serif Display',serif;font-size:1.5rem;color:var(--accent2)}
.detail-name{font-size:1rem;color:var(--muted);margin-top:4px}
.detail-date{font-size:0.78rem;color:var(--muted);margin-top:8px}

/* Confirm delete modal */
.modal-bg{
  position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:100;
  display:none;align-items:center;justify-content:center;
}
.modal-bg.open{display:flex}
.modal{
  background:var(--surface);border:1px solid var(--border);border-radius:16px;
  padding:36px 40px;max-width:420px;width:100%;text-align:center;
  box-shadow:0 32px 80px rgba(0,0,0,0.6);
}
.modal h3{font-family:'DM Serif Display',serif;font-size:1.3rem;color:var(--white);margin-bottom:10px}
.modal p{color:var(--muted);font-size:0.88rem;margin-bottom:28px;line-height:1.6}
.modal-actions{display:flex;gap:12px;justify-content:center}

@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:800px){
  .sidebar{display:none}
  .content{padding:20px}
  .form-grid,.form-grid-3,.detail-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<?php if (empty($_SESSION['admin'])): ?>
<!-- ══════════════════════════════════════════════════════ LOGIN ══ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <div class="dot">🛡️</div>
      <h1>Administration</h1>
      <p>Ikarata Karangamuntu</p>
    </div>
    <?php if (!empty($loginError)): ?>
      <div class="login-err"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Identifiant</label>
        <input type="text" name="username" placeholder="admin" required autofocus>
      </div>
      <div class="form-group">
        <label>Mot de passe</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" name="login" class="btn-login">Se connecter →</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════ ADMIN ══ -->
<?php
// Stats
$totalCards  = (int)$db->query("SELECT COUNT(*) FROM cartes_identite")->fetchColumn();
$totalM      = (int)$db->query("SELECT COUNT(*) FROM cartes_identite WHERE sexe='M'")->fetchColumn();
$totalF      = (int)$db->query("SELECT COUNT(*) FROM cartes_identite WHERE sexe='F'")->fetchColumn();
$recentCount = (int)$db->query("SELECT COUNT(*) FROM cartes_identite WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
?>

<!-- Confirm delete modal -->
<div class="modal-bg" id="delModal">
  <div class="modal">
    <h3>Confirmer la suppression</h3>
    <p>Cette opération est <strong>irréversible</strong>. La carte et la photo associée seront définitivement supprimées.</p>
    <div class="modal-actions">
      <button class="btn btn-ghost" onclick="document.getElementById('delModal').classList.remove('open')">Annuler</button>
      <a id="delLink" href="#" class="btn btn-danger">Supprimer</a>
    </div>
  </div>
</div>

<div class="admin-wrap">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <h1>Ikarata<br>Karangamuntu</h1>
      <p>Republika y'Uburundi</p>
      <span class="badge">ADMIN PANEL</span>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-label">Navigation</div>
      <a href="admin.php" class="nav-item <?= $action==='list'?'active':'' ?>">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 6h18M3 14h18M3 18h18"/></svg>
        Toutes les cartes
      </a>
      <a href="admin.php?action=add" class="nav-item <?= $action==='add'?'active':'' ?>">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Nouvelle carte
      </a>
      <div class="nav-label">Liens</div>
      <a href="formulaire_identite.html" class="nav-item" target="_blank">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        Formulaire public
      </a>
    </nav>
    <div class="sidebar-footer">
      <a href="admin.php?logout=1">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1"/></svg>
        Déconnexion
      </a>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <div class="topbar">
      <span class="topbar-title">
        <?php
          if ($action==='add')  echo 'Ajouter une carte';
          elseif ($action==='edit')  echo 'Modifier la carte';
          elseif ($action==='view')  echo 'Détail de la carte';
          else echo 'Tableau de bord';
        ?>
      </span>
      <div class="topbar-right">
        <span class="admin-badge">👤 Administrateur</span>
      </div>
    </div>

    <div class="content">

      <?php if ($message): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <?php if ($action === 'list'): ?>
      <!-- ══ LISTE ══ -->
      <div class="stats-row">
        <div class="stat-card"><div class="ico">🪪</div><div class="val"><?= $totalCards ?></div><div class="lbl">Total cartes</div></div>
        <div class="stat-card"><div class="ico">👨</div><div class="val"><?= $totalM ?></div><div class="lbl">Hommes</div></div>
        <div class="stat-card"><div class="ico">👩</div><div class="val"><?= $totalF ?></div><div class="lbl">Femmes</div></div>
        <div class="stat-card"><div class="ico">🆕</div><div class="val"><?= $recentCount ?></div><div class="lbl">Ce mois-ci</div></div>
      </div>

      <div class="bar">
        <form method="GET" style="display:contents">
          <div class="search-box">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" name="q" placeholder="Rechercher par nom, prénom, N° MIFP, province…" value="<?= htmlspecialchars($search) ?>">
          </div>
          <button type="submit" class="btn btn-ghost">Rechercher</button>
          <?php if ($search): ?><a href="admin.php" class="btn btn-ghost">✕ Effacer</a><?php endif; ?>
        </form>
        <a href="admin.php?action=add" class="btn btn-primary">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
          Nouvelle carte
        </a>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Photo</th>
              <th>N° MIFP</th>
              <th>Nom complet</th>
              <th>Sexe</th>
              <th>Naissance</th>
              <th>Province</th>
              <th>Émission</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:40px">Aucun enregistrement trouvé</td></tr>
            <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="td-photo">
                <?php if ($r['photo_path'] && file_exists(__DIR__.'/'.$r['photo_path'])): ?>
                  <img src="<?= htmlspecialchars($r['photo_path']) ?>" alt="" class="photo-thumb">
                <?php else: ?>
                  <div class="photo-placeholder-sm">👤</div>
                <?php endif; ?>
              </td>
              <td><span class="td-mifp"><?= htmlspecialchars($r['numero_mifp']) ?></span></td>
              <td><span class="td-name"><?= htmlspecialchars($r['nom'].' '.$r['prenom']) ?></span></td>
              <td>
                <span class="badge-<?= strtolower($r['sexe']) ?>">
                  <?= $r['sexe']==='M' ? 'M' : 'F' ?>
                </span>
              </td>
              <td><?= htmlspecialchars($r['date_naissance']) ?></td>
              <td><?= htmlspecialchars($r['province']) ?></td>
              <td><?= htmlspecialchars($r['date_emission']) ?></td>
              <td>
                <div class="actions-td">
                  <a href="admin.php?action=view&id=<?= $r['id'] ?>" class="btn btn-info btn-sm" title="Voir">👁</a>
                  <a href="admin.php?action=edit&id=<?= $r['id'] ?>" class="btn btn-warning btn-sm" title="Modifier">✏️</a>
                  <button class="btn btn-danger btn-sm" title="Supprimer"
                    onclick="confirmDelete(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['nom'].' '.$r['prenom'])) ?>')">🗑</button>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <div class="pagination">
          <div class="pagination-info">
            <?php
              $from = $total===0 ? 0 : $offset+1;
              $to   = min($offset+$perPage, $total);
              echo "$from–$to sur $total résultat(s)";
              if ($search) echo " pour «&nbsp;".htmlspecialchars($search)."&nbsp;»";
            ?>
          </div>
          <div class="pagination-btns">
            <?php for ($p=1;$p<=$pages;$p++): ?>
              <a href="admin.php?page=<?= $p ?><?= $search?'&q='.urlencode($search):'' ?>"
                 class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <?php elseif ($action === 'view' && $record): ?>
      <!-- ══ DÉTAIL ══ -->
      <div style="margin-bottom:16px">
        <a href="admin.php" class="btn btn-ghost btn-sm">← Retour à la liste</a>
        <a href="admin.php?action=edit&id=<?= $record['id'] ?>" class="btn btn-warning btn-sm" style="margin-left:8px">✏️ Modifier</a>
      </div>
      <div class="panel">
        <div class="panel-head">
          <h2>Détail de la carte</h2>
          <span style="font-size:0.75rem;color:var(--muted)">ID #<?= $record['id'] ?></span>
        </div>
        <div class="detail-photo-wrap">
          <?php if ($record['photo_path'] && file_exists(__DIR__.'/'.$record['photo_path'])): ?>
            <img src="<?= htmlspecialchars($record['photo_path']) ?>" alt="Photo" class="detail-photo">
          <?php else: ?>
            <div class="detail-photo-placeholder">👤</div>
          <?php endif; ?>
          <div>
            <div class="detail-mifp">N° <?= htmlspecialchars($record['numero_mifp']) ?></div>
            <div class="detail-name"><?= htmlspecialchars($record['nom'].' '.$record['prenom']) ?></div>
            <div class="detail-date">Émise le <?= htmlspecialchars($record['date_emission']) ?> — <?= htmlspecialchars($record['commune_emission']) ?></div>
          </div>
        </div>
        <div class="detail-grid">
          <?php
          $fields = [
            'Nom'                 => $record['nom'],
            'Prénom'              => $record['prenom'],
            'Sexe'                => $record['sexe']==='M'?'Masculin':'Féminin',
            'Date de naissance'   => $record['date_naissance'],
            'Nom de la mère'      => $record['nom_mere'] ?: '—',
            'Nom du père'         => $record['nom_pere'] ?: '—',
            'État civil'          => $record['etat_civil'] ?: '—',
            'Profession'          => $record['profession'] ?: '—',
            'Province'            => $record['province'],
            'Commune'             => $record['commune'],
            'Colline'             => $record['colline'] ?: '—',
            'Officier État Civil' => $record['officier_etat_civil'] ?: '—',
          ];
          foreach ($fields as $lbl => $val):
          ?>
          <div class="detail-item">
            <div class="detail-lbl"><?= $lbl ?></div>
            <div class="detail-val"><?= htmlspecialchars($val) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php elseif ($action === 'add' || $action === 'edit'): ?>
      <!-- ══ FORMULAIRE ADD / EDIT ══ -->
      <?php $isEdit = $action==='edit' && $record; $editId = $isEdit ? $record['id'] : 0; ?>
      <div style="margin-bottom:16px">
        <a href="admin.php" class="btn btn-ghost btn-sm">← Retour à la liste</a>
      </div>
      <div class="panel">
        <div class="panel-head">
          <h2><?= $isEdit ? '✏️ Modifier la carte' : '➕ Nouvelle carte' ?></h2>
          <?php if ($isEdit): ?><span style="font-size:0.75rem;color:var(--muted)">ID #<?= $editId ?></span><?php endif; ?>
        </div>
        <div class="panel-body">
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="edit_id" value="<?= $editId ?>">
            <div class="form-grid">

              <div class="section-sep form-full"><span>Photo</span></div>
              <div class="f-group" style="align-items:flex-start">
                <label>Photo d'identité</label>
                <div class="photo-upload-zone" id="photoZone">
                  <img id="edit-preview"
                    src="<?= ($isEdit && $record['photo_path'] && file_exists(__DIR__.'/'.$record['photo_path'])) ? htmlspecialchars($record['photo_path']) : '' ?>"
                    alt=""
                    style="<?= ($isEdit && $record['photo_path'] && file_exists(__DIR__.'/'.$record['photo_path'])) ? 'display:block' : 'display:none' ?>">
                  <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>
                  <p>Cliquer pour choisir</p>
                  <input type="file" name="photo" id="photoFile" accept="image/*" onchange="previewPhoto(this)">
                </div>
                <span class="photo-hint">JPEG / PNG / WebP · max 2 Mo</span>
              </div>

              <div class="section-sep form-full"><span>Identification</span></div>
              <div class="f-group">
                <label>N° MIFP *</label>
                <input type="text" name="numero_mifp" placeholder="1504/111.147" required
                  value="<?= $isEdit ? htmlspecialchars($record['numero_mifp']) : '' ?>">
              </div>
              <div class="f-group">
                <label>Date d'émission *</label>
                <input type="date" name="date_emission" required
                  value="<?= $isEdit ? htmlspecialchars($record['date_emission']) : '' ?>">
              </div>
              <div class="f-group">
                <label>Commune d'émission *</label>
                <input type="text" name="commune_emission" required
                  value="<?= $isEdit ? htmlspecialchars($record['commune_emission']) : '' ?>">
              </div>
              <div class="f-group">
                <label>Officier État Civil</label>
                <input type="text" name="officier_etat_civil"
                  value="<?= $isEdit ? htmlspecialchars($record['officier_etat_civil'] ?? '') : '' ?>">
              </div>

              <div class="section-sep form-full"><span>Identité personnelle</span></div>
              <div class="f-group">
                <label>Nom de famille *</label>
                <input type="text" name="nom" required
                  value="<?= $isEdit ? htmlspecialchars($record['nom']) : '' ?>">
              </div>
              <div class="f-group">
                <label>Prénom *</label>
                <input type="text" name="prenom" required
                  value="<?= $isEdit ? htmlspecialchars($record['prenom']) : '' ?>">
              </div>
              <div class="f-group">
                <label>Sexe *</label>
                <select name="sexe" required>
                  <option value="">— Choisir —</option>
                  <option value="M" <?= ($isEdit && $record['sexe']==='M')?'selected':'' ?>>Masculin</option>
                  <option value="F" <?= ($isEdit && $record['sexe']==='F')?'selected':'' ?>>Féminin</option>
                </select>
              </div>
              <div class="f-group">
                <label>Date de naissance *</label>
                <input type="date" name="date_naissance" required
                  value="<?= $isEdit ? htmlspecialchars($record['date_naissance']) : '' ?>">
              </div>
              <div class="f-group">
                <label>Nom de la mère</label>
                <input type="text" name="nom_mere"
                  value="<?= $isEdit ? htmlspecialchars($record['nom_mere'] ?? '') : '' ?>">
              </div>
              <div class="f-group">
                <label>Nom du père</label>
                <input type="text" name="nom_pere"
                  value="<?= $isEdit ? htmlspecialchars($record['nom_pere'] ?? '') : '' ?>">
              </div>
              <div class="f-group">
                <label>État civil</label>
                <select name="etat_civil">
                  <option value="">— Choisir —</option>
                  <?php foreach(['celibataire'=>'Célibataire','marie'=>'Marié(e)','divorce'=>'Divorcé(e)','veuf'=>'Veuf/Veuve'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= ($isEdit && $record['etat_civil']===$v)?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="f-group">
                <label>Profession</label>
                <input type="text" name="profession"
                  value="<?= $isEdit ? htmlspecialchars($record['profession'] ?? '') : '' ?>">
              </div>

              <div class="section-sep form-full"><span>Adresse</span></div>
              <div class="f-group">
                <label>Province *</label>
                <select name="province" required>
                  <option value="">— Province —</option>
                  <?php foreach(['Bujumbura','Buhumuza','Burunga','Butanyerera','Gitega'] as $p): ?>
                  <option value="<?= $p ?>" <?= ($isEdit && $record['province']===$p)?'selected':'' ?>><?= $p ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="f-group">
                <label>Commune *</label>
                <input type="text" name="commune" required
                  value="<?= $isEdit ? htmlspecialchars($record['commune']) : '' ?>">
              </div>
              <div class="f-group">
                <label>Colline</label>
                <input type="text" name="colline"
                  value="<?= $isEdit ? htmlspecialchars($record['colline'] ?? '') : '' ?>">
              </div>

            </div><!-- /form-grid -->

            <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:28px">
              <a href="admin.php" class="btn btn-ghost">Annuler</a>
              <button type="submit" name="save" class="btn btn-primary">
                <?= $isEdit ? '💾 Enregistrer les modifications' : '➕ Ajouter la carte' ?>
              </button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /admin-wrap -->

<script>
function confirmDelete(id, name) {
  document.getElementById('delLink').href = 'admin.php?action=delete&id=' + id;
  document.getElementById('delModal').classList.add('open');
}
document.getElementById('delModal').addEventListener('click', function(e){
  if (e.target === this) this.classList.remove('open');
});
function previewPhoto(input) {
  var preview = document.getElementById('edit-preview');
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>

<?php endif; ?>
</body>
</html>
