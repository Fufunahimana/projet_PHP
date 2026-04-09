<?php
/**
 * admin.php — v2
 * Administration des cartes d'identité burundaises
 * Ajouts : champ téléphone, validations dates, vérification MIFP en édition
 */

define('DB_HOST',    'localhost');
define('DB_NAME',    'identite_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS personne (
                id INT AUTO_INCREMENT PRIMARY KEY,
                izina VARCHAR(100) NOT NULL, amatazirano VARCHAR(100) NOT NULL,
                se VARCHAR(150), nyina VARCHAR(150),
                provensi VARCHAR(100), komine VARCHAR(100), yavukiye VARCHAR(100),
                italiki DATE, genre CHAR(1), arubatse VARCHAR(50),
                ntarubaka VARCHAR(150), akazi_akora VARCHAR(150),
                num_mifp VARCHAR(50) UNIQUE, itangiwe_i VARCHAR(100),
                date_delivrance DATE, uwuyitanze VARCHAR(150),
                telephone VARCHAR(30),
                photo VARCHAR(255),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        // Migration : ajouter telephone si absente
        try { $pdo->exec("ALTER TABLE personne ADD COLUMN IF NOT EXISTS telephone VARCHAR(30) AFTER uwuyitanze"); }
        catch (Exception $e) {}
    }
    return $pdo;
}

function clean(?string $v): string { return trim(htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8')); }

$action  = $_GET['action'] ?? ($_POST['action'] ?? '');
$message = '';
$msgType = 'success';

// ─── DELETE ──────────────────────────────────────────────────────────────────
if ($action === 'delete' && isset($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $pdo = getDB();
    $row = $pdo->prepare("SELECT photo FROM personne WHERE id=?");
    $row->execute([$id]);
    $person = $row->fetch();
    if ($person && $person['photo'] && file_exists(__DIR__.'/'.$person['photo'])) {
        unlink(__DIR__.'/'.$person['photo']);
    }
    $pdo->prepare("DELETE FROM personne WHERE id=?")->execute([$id]);
    header("Location: admin.php?msg=deleted"); exit;
}

// ─── UPDATE ──────────────────────────────────────────────────────────────────
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)$_POST['id'];
    $pdo    = getDB();
    $errors = [];

    // Validation date de naissance
    if (!empty($_POST['italiki'])) {
        $dob = DateTime::createFromFormat('Y-m-d', $_POST['italiki']);
        if (!$dob) { $errors[] = "Format date de naissance invalide."; }
        else {
            if ($dob < new DateTime('1920-01-01')) $errors[] = "Date de naissance avant 1920 non autorisée.";
            if ($dob > new DateTime('today'))       $errors[] = "Date de naissance dans le futur non autorisée.";
        }
    }

    // Validation date de délivrance
    if (!empty($_POST['date_delivrance'])) {
        $dDel = DateTime::createFromFormat('Y-m-d', $_POST['date_delivrance']);
        if (!$dDel) { $errors[] = "Format date de délivrance invalide."; }
        elseif ($dDel > new DateTime('today')) $errors[] = "La date de délivrance ne peut pas être dans le futur.";
    }

    // Vérification unicité MIFP (exclure l'ID courant)
    if (!empty($_POST['num_mifp'])) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM personne WHERE num_mifp=? AND id!=?");
        $chk->execute([clean($_POST['num_mifp']), $id]);
        if ((int)$chk->fetchColumn() > 0) $errors[] = "Ce N° MIFP est déjà utilisé par un autre enregistrement.";
    }

    // Validation téléphone
    $telephone = '';
    if (!empty(trim($_POST['telephone'] ?? ''))) {
        $digits = preg_replace('/\D/', '', $_POST['telephone']);
        if (strlen($digits) < 7 || strlen($digits) > 9) {
            $errors[] = "Numéro de téléphone invalide (7 à 9 chiffres).";
        } else {
            $telephone = '+257 ' . rtrim(chunk_split($digits, 2, ' '));
        }
    }

    if (!empty($errors)) {
        $message = implode(' | ', $errors);
        $msgType = 'error';
        goto SHOW_PAGE;
    }

    // Gestion photo
    $photoSQL = '';
    $extraParams = [];
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file  = $_FILES['photo'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg','image/png','image/jpg','image/webp'];
        if (in_array($mime, $allowed) && $file['size'] <= MAX_FILE_SIZE) {
            $old = $pdo->prepare("SELECT photo FROM personne WHERE id=?");
            $old->execute([$id]);
            $oldRow = $old->fetch();
            if ($oldRow['photo'] && file_exists(__DIR__.'/'.$oldRow['photo'])) {
                unlink(__DIR__.'/'.$oldRow['photo']);
            }
            $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fname = uniqid('photo_', true) . '.' . $ext;
            move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $fname);
            $photoSQL            = ', photo=:photo';
            $extraParams[':photo'] = 'uploads/' . $fname;
        }
    }

    $sql = "UPDATE personne SET
        izina=:izina, amatazirano=:amatazirano, se=:se, nyina=:nyina,
        provensi=:provensi, komine=:komine, yavukiye=:yavukiye,
        italiki=:italiki, genre=:genre, arubatse=:arubatse,
        ntarubaka=:ntarubaka, akazi_akora=:akazi_akora,
        num_mifp=:num_mifp, itangiwe_i=:itangiwe_i,
        date_delivrance=:date_delivrance, uwuyitanze=:uwuyitanze,
        telephone=:telephone
        $photoSQL WHERE id=:id";

    $params = array_merge($extraParams, [
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
        ':id'              => $id,
    ]);

    try {
        $pdo->prepare($sql)->execute($params);
        header("Location: admin.php?msg=updated"); exit;
    } catch (PDOException $e) {
        $message = "Erreur BD : " . $e->getMessage();
        $msgType = 'error';
    }
}

// ─── Charger les données ──────────────────────────────────────────────────────
SHOW_PAGE:
$pdo    = getDB();
$search = clean($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where   = $search ? "WHERE izina LIKE :q OR amatazirano LIKE :q OR num_mifp LIKE :q OR provensi LIKE :q OR telephone LIKE :q" : "";
$qParam  = "%$search%";

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM personne $where");
$search ? $totalStmt->execute([':q' => $qParam]) : $totalStmt->execute();
$total     = (int)$totalStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

$stmt = $pdo->prepare("SELECT * FROM personne $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
if ($search) $stmt->bindValue(':q', $qParam);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$persons = $stmt->fetchAll();

$provinces = ['Bubanza','Bujumbura','Bururi','Cankuzo','Cibitoke','Gitega','Karuzi',
              'Kayanza','Kirundo','Makamba','Muramvya','Muyinga','Mwaro','Ngozi',
              'Rumonge','Rutana','Ruyigi','Bujumbura Mairie'];

// Messages de retour
if (isset($_GET['msg'])) {
    $msgs = [
        'deleted' => ['Enregistrement supprimé avec succès.', 'success'],
        'updated' => ['Informations mises à jour avec succès.', 'success'],
    ];
    if (isset($msgs[$_GET['msg']])) [$message, $msgType] = $msgs[$_GET['msg']];
}

$todayStr = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Administration — Cartes d'Identité Burundaises</title>
  <link rel="stylesheet" href="style.css">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
</head>
<body>
  <div class="bg-pattern"></div>

  <header class="site-header">
    <div class="header-inner">
      <div class="logo-block">
        <div class="flag-bar"><span class="f1"></span><span class="f2"></span><span class="f3"></span></div>
        <div class="header-text">
          <span class="republic">REPUBLIKA Y'UBURUNDI</span>
          <span class="ministry">Ministère de l'Intérieur — Administration</span>
        </div>
      </div>
      <nav class="header-nav">
        <a href="identite.html" class="nav-link">+ Nouvel Enregistrement</a>
        <a href="admin.php" class="nav-link active admin-link">⚙ Administration</a>
      </nav>
    </div>
  </header>

  <main class="admin-wrapper">
    <h1 class="admin-title">Gestion des Identités</h1>
    <p class="admin-sub">Base de données nationale des cartes d'identité — Ikarata Karangamuntu</p>

    <?php if ($message): ?>
    <div class="alert <?= $msgType ?>" id="admin-alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-bar">
      <div class="stat-card">
        <div class="stat-number"><?= $total ?></div>
        <div class="stat-label">Total Enregistrés</div>
      </div>
      <?php
        $s2 = $pdo->query("SELECT COUNT(*) FROM personne WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $s3 = $pdo->query("SELECT COUNT(*) FROM personne WHERE photo != '' AND photo IS NOT NULL")->fetchColumn();
        $s4 = $pdo->query("SELECT COUNT(DISTINCT provensi) FROM personne")->fetchColumn();
        $s5 = $pdo->query("SELECT COUNT(*) FROM personne WHERE telephone IS NOT NULL AND telephone != ''")->fetchColumn();
      ?>
      <div class="stat-card"><div class="stat-number"><?= $s2 ?></div><div class="stat-label">Aujourd'hui</div></div>
      <div class="stat-card"><div class="stat-number"><?= $s3 ?></div><div class="stat-label">Avec Photo</div></div>
      <div class="stat-card"><div class="stat-number"><?= $s5 ?></div><div class="stat-label">Avec Tél.</div></div>
      <div class="stat-card"><div class="stat-number"><?= $s4 ?></div><div class="stat-label">Provinces</div></div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <form method="GET" style="display:flex;gap:8px;flex:1;">
        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>"
               placeholder="🔍  Nom, prénom, N° MIFP, province, téléphone...">
        <button class="btn-primary" type="submit" style="white-space:nowrap;">Rechercher</button>
        <?php if ($search): ?>
          <a href="admin.php" class="btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;padding:10px 16px;border-radius:10px;">✕ Effacer</a>
        <?php endif; ?>
      </form>
      <a href="identite.html" class="btn-primary" style="text-decoration:none;white-space:nowrap;">+ Ajouter</a>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Photo</th>
            <th>Nom & Prénom</th>
            <th>N° MIFP</th>
            <th>Téléphone</th>
            <th>Province / Commune</th>
            <th>Date Naiss.</th>
            <th>Genre</th>
            <th>Enregistré le</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($persons)): ?>
          <tr><td colspan="10" style="text-align:center;padding:40px;color:#7a7a99;">
            <?= $search ? "Aucun résultat pour « ".htmlspecialchars($search)." »" : "Aucun enregistrement trouvé." ?>
          </td></tr>
          <?php else: ?>
          <?php foreach ($persons as $p): ?>
          <tr>
            <td><span class="id-badge"><?= $p['id'] ?></span></td>
            <td>
              <?php if ($p['photo'] && file_exists(__DIR__.'/'.$p['photo'])): ?>
                <img src="<?= htmlspecialchars($p['photo']) ?>" alt="Photo" class="photo-thumb"
                     onclick="openLightbox('<?= htmlspecialchars($p['photo']) ?>','<?= htmlspecialchars($p['izina'].' '.$p['amatazirano']) ?>')">
              <?php else: ?>
                <div class="no-photo">Pas de<br>photo</div>
              <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($p['izina']) ?></strong> <?= htmlspecialchars($p['amatazirano']) ?></td>
            <td><code style="font-size:.8rem;color:#1a7a3c;"><?= htmlspecialchars($p['num_mifp'] ?? '—') ?></code></td>
            <td style="font-size:.84rem;">
              <?= htmlspecialchars($p['telephone'] ?? '—') ?>
            </td>
            <td><?= htmlspecialchars($p['provensi'] ?? '—') ?> / <?= htmlspecialchars($p['komine'] ?? '—') ?></td>
            <td><?= $p['italiki'] ? date('d/m/Y', strtotime($p['italiki'])) : '—' ?></td>
            <td><?= htmlspecialchars($p['genre'] ?? '—') ?></td>
            <td style="font-size:.78rem;color:#7a7a99;"><?= date('d/m/Y', strtotime($p['created_at'])) ?></td>
            <td>
              <div class="action-btns">
                <button class="btn-view" onclick="openView(<?= htmlspecialchars(json_encode($p)) ?>)">👁 Voir</button>
                <button class="btn-edit" onclick="openEdit(<?= htmlspecialchars(json_encode($p)) ?>)">✏ Éditer</button>
                <button class="btn-del"  onclick="confirmDelete(<?= $p['id'] ?>,'<?= htmlspecialchars($p['izina'].' '.$p['amatazirano']) ?>')">🗑 Suppr.</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?q=<?= urlencode($search) ?>&page=<?= $i ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <p>© 2025 Republika y'Uburundi — Système National d'Identification | Administration</p>
  </footer>

  <!-- ═══════════════ VIEW MODAL ═══════════════ -->
  <div class="modal-overlay" id="modal-view">
    <div class="modal">
      <div class="modal-header">
        <h2>Détails de la Carte d'Identité</h2>
        <button class="modal-close" onclick="closeModal('modal-view')">✕</button>
      </div>
      <div class="modal-body">
        <div class="modal-photo" id="view-photo-wrap"></div>
        <div class="info-grid" id="view-info"></div>
      </div>
    </div>
  </div>

  <!-- ═══════════════ EDIT MODAL ═══════════════ -->
  <div class="modal-overlay" id="modal-edit">
    <div class="modal">
      <div class="modal-header">
        <h2>Modifier l'Enregistrement</h2>
        <button class="modal-close" onclick="closeModal('modal-edit')">✕</button>
      </div>
      <div class="modal-body">
        <div id="edit-photo-preview-wrap"></div>
        <div id="edit-alert" class="alert hidden" style="margin:0 0 16px;"></div>
        <form method="POST" enctype="multipart/form-data" action="admin.php?action=update" id="editForm" novalidate>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="edit-id">
          <div class="grid-2">
            <div class="field-group">
              <label>IZINA (Nom) *</label>
              <input type="text" name="izina" id="edit-izina" required>
            </div>
            <div class="field-group">
              <label>AMATAZIRANO (Prénom) *</label>
              <input type="text" name="amatazirano" id="edit-amatazirano" required>
            </div>
            <div class="field-group">
              <label>SE (Père)</label>
              <input type="text" name="se" id="edit-se">
            </div>
            <div class="field-group">
              <label>NYINA (Mère)</label>
              <input type="text" name="nyina" id="edit-nyina">
            </div>
            <!-- Téléphone dans le modal edit -->
            <div class="field-group">
              <label>Téléphone</label>
              <div class="phone-input-wrap">
                <span class="phone-prefix">+257</span>
                <input type="tel" name="telephone" id="edit-telephone" placeholder="79 123 456" maxlength="11">
              </div>
              <span class="field-error" id="edit-err-telephone"></span>
            </div>
            <div class="field-group">
              <label>Province</label>
              <select name="provensi" id="edit-provensi">
                <?php foreach ($provinces as $pv): ?>
                  <option value="<?= $pv ?>"><?= $pv ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-group">
              <label>Commune</label>
              <input type="text" name="komine" id="edit-komine">
            </div>
            <div class="field-group">
              <label>Né(e) à</label>
              <input type="text" name="yavukiye" id="edit-yavukiye">
            </div>
            <div class="field-group">
              <label>Date de naissance</label>
              <input type="date" name="italiki" id="edit-italiki"
                     min="1920-01-01" max="<?= $todayStr ?>">
              <span class="field-error" id="edit-err-italiki"></span>
              <span class="field-hint">Entre 01/01/1920 et aujourd'hui</span>
            </div>
            <div class="field-group">
              <label>Genre</label>
              <select name="genre" id="edit-genre">
                <option value="M">Masculin</option>
                <option value="F">Féminin</option>
              </select>
            </div>
            <div class="field-group">
              <label>État civil</label>
              <select name="arubatse" id="edit-arubatse">
                <option value="-">-</option>
                <option value="célibataire">Célibataire</option>
                <option value="marié(e)">Marié(e)</option>
                <option value="divorcé(e)">Divorcé(e)</option>
                <option value="veuf/veuve">Veuf/Veuve</option>
              </select>
            </div>
            <div class="field-group">
              <label>Profession</label>
              <input type="text" name="ntarubaka" id="edit-ntarubaka">
            </div>
            <div class="field-group">
              <label>Employeur</label>
              <input type="text" name="akazi_akora" id="edit-akazi_akora">
            </div>
            <div class="field-group">
              <label>N° MIFP *</label>
              <div class="mifp-wrap">
                <input type="text" name="num_mifp" id="edit-num_mifp" required autocomplete="off">
                <span class="mifp-status" id="edit-mifp-status"></span>
              </div>
              <span class="field-error" id="edit-err-num_mifp"></span>
            </div>
            <div class="field-group">
              <label>Délivrée à</label>
              <input type="text" name="itangiwe_i" id="edit-itangiwe_i">
            </div>
            <div class="field-group">
              <label>Date délivrance</label>
              <input type="date" name="date_delivrance" id="edit-date_delivrance"
                     max="<?= $todayStr ?>">
              <span class="field-error" id="edit-err-date_delivrance"></span>
              <span class="field-hint">Ne peut pas être dans le futur</span>
            </div>
            <div class="field-group">
              <label>Signataire</label>
              <input type="text" name="uwuyitanze" id="edit-uwuyitanze">
            </div>
          </div>
          <!-- Photo -->
          <div class="field-group" style="margin-top:16px;">
            <label>Nouvelle Photo (laisser vide = conserver l'actuelle)</label>
            <label class="upload-btn" for="edit-photo" style="width:fit-content;margin-top:6px;">
              📷 Choisir une photo
            </label>
            <input type="file" id="edit-photo" name="photo" accept="image/*" class="hidden-input">
            <p class="upload-hint" style="margin-top:4px;">JPG, PNG, WEBP — Max 5 Mo</p>
          </div>
          <div class="form-actions">
            <button type="button" class="btn-secondary" onclick="closeModal('modal-edit')">Annuler</button>
            <button type="submit" class="btn-primary" id="edit-submit-btn">💾 Enregistrer</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ═══════════════ DELETE CONFIRM ═══════════════ -->
  <div class="modal-overlay" id="modal-delete">
    <div class="modal" style="max-width:420px;">
      <div class="modal-header" style="background:#c0392b;">
        <h2>Confirmer la suppression</h2>
        <button class="modal-close" onclick="closeModal('modal-delete')">✕</button>
      </div>
      <div class="modal-body" style="text-align:center;padding:32px;">
        <p style="font-size:1.1rem;margin-bottom:8px;">⚠️ Supprimer cet enregistrement ?</p>
        <p id="delete-name" style="font-weight:700;font-size:1.2rem;color:#c0392b;margin-bottom:20px;"></p>
        <p style="color:#7a7a99;font-size:.88rem;margin-bottom:28px;">Cette action est irréversible et supprimera aussi la photo associée.</p>
        <div style="display:flex;gap:12px;justify-content:center;">
          <button class="btn-secondary" onclick="closeModal('modal-delete')">Annuler</button>
          <a id="delete-link" href="#" class="btn-primary" style="text-decoration:none;background:#c0392b;box-shadow:0 4px 15px rgba(192,57,43,.3);">🗑 Supprimer définitivement</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Lightbox -->
  <div class="lightbox" id="lightbox">
    <button class="lightbox-close" onclick="closeLightbox()">✕</button>
    <img id="lightbox-img" src="" alt="">
  </div>

  <script>
  const todayStr = '<?= $todayStr ?>';

  // ── Modal helpers ──────────────────────────────────────────────────────────
  function openModal(id)  { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('.modal-overlay').forEach(el =>
    el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); })
  );

  // ── VIEW ───────────────────────────────────────────────────────────────────
  function openView(p) {
    const photo = document.getElementById('view-photo-wrap');
    photo.innerHTML = p.photo
      ? `<img src="${p.photo}" style="max-width:120px;max-height:150px;border-radius:8px;border:3px solid #1a7a3c;cursor:pointer"
              onclick="openLightbox('${p.photo}','${p.izina} ${p.amatazirano}')">`
      : `<div class="no-photo" style="width:80px;height:100px;margin:0 auto;">Pas de photo</div>`;

    const fields = [
      ['Nom (IZINA)', p.izina],['Prénom (AMATAZIRANO)', p.amatazirano],
      ['Père (SE)', p.se],['Mère (NYINA)', p.nyina],
      ['Province', p.provensi],['Commune', p.komine],
      ['Né(e) à', p.yavukiye],['Date naissance', fmtDate(p.italiki)],
      ['Genre', p.genre==='M'?'Masculin':p.genre==='F'?'Féminin':'—'],
      ['État civil', p.arubatse],['Profession', p.ntarubaka],['Employeur', p.akazi_akora],
      ['N° MIFP', p.num_mifp],['Délivrée à', p.itangiwe_i],
      ['Date délivrance', fmtDate(p.date_delivrance)],['Signataire', p.uwuyitanze],
      ['Téléphone', p.telephone || '—'],
      ['Enregistré le', fmtDate(p.created_at)],
    ];
    document.getElementById('view-info').innerHTML = fields
      .map(([l,v]) => `<div class="info-item"><label>${l}</label><span>${v||'—'}</span></div>`)
      .join('');
    openModal('modal-view');
  }

  // ── EDIT ───────────────────────────────────────────────────────────────────
  let editCurrentId = null;
  let editMifpValid = true;
  let editMifpTimer = null;

  function openEdit(p) {
    editCurrentId = p.id;
    editMifpValid = true;

    const simpleFields = ['id','izina','amatazirano','se','nyina','provensi','komine',
                          'yavukiye','genre','arubatse','ntarubaka','akazi_akora',
                          'num_mifp','itangiwe_i','uwuyitanze'];
    simpleFields.forEach(f => {
      const el = document.getElementById('edit-' + f);
      if (el) el.value = p[f] || '';
    });
    // Dates
    document.getElementById('edit-italiki').value         = p.italiki         ? p.italiki.split(' ')[0]         : '';
    document.getElementById('edit-date_delivrance').value = p.date_delivrance ? p.date_delivrance.split(' ')[0] : '';
    // Téléphone — extraire seulement les chiffres locaux
    const telEl = document.getElementById('edit-telephone');
    if (telEl) {
      const raw = (p.telephone || '').replace('+257', '').replace(/\s/g,'');
      telEl.value = raw;
    }
    // Reset MIFP status
    document.getElementById('edit-mifp-status').textContent = '';
    document.getElementById('edit-mifp-status').className   = 'mifp-status';
    // Clear errors
    document.querySelectorAll('#editForm .field-error').forEach(el => el.textContent = '');
    document.querySelectorAll('#editForm .input-error').forEach(el => el.classList.remove('input-error'));
    document.getElementById('edit-alert').classList.add('hidden');

    // Photo preview
    const wrap = document.getElementById('edit-photo-preview-wrap');
    wrap.innerHTML = p.photo
      ? `<div style="text-align:center;margin-bottom:16px;">
           <p style="font-size:.75rem;color:#7a7a99;margin-bottom:6px;">Photo actuelle (cliquer pour agrandir)</p>
           <img id="edit-current-photo" src="${p.photo}"
                style="max-width:80px;max-height:100px;border-radius:6px;border:2px solid #1a7a3c;cursor:pointer"
                onclick="openLightbox('${p.photo}','Photo actuelle')">
         </div>`
      : '';

    openModal('modal-edit');
  }

  // MIFP unicité dans l'édition
  document.getElementById('edit-num_mifp').addEventListener('input', function () {
    const errEl  = document.getElementById('edit-err-num_mifp');
    const status = document.getElementById('edit-mifp-status');
    const val    = this.value.trim();
    errEl.textContent = '';
    this.classList.remove('input-error');
    if (!val) { status.textContent = ''; return; }

    status.textContent = '⏳';
    status.className   = 'mifp-status checking';
    clearTimeout(editMifpTimer);
    editMifpTimer = setTimeout(() => {
      const excludeParam = editCurrentId ? `&exclude_id=${editCurrentId}` : '';
      fetch(`check_mifp.php?num_mifp=${encodeURIComponent(val)}${excludeParam}`)
        .then(r => r.json())
        .then(data => {
          if (data.exists) {
            errEl.textContent = '❌ Ce N° MIFP est déjà utilisé.';
            this.classList.add('input-error');
            status.textContent = '✗'; status.className = 'mifp-status taken';
            editMifpValid = false;
          } else {
            status.textContent = '✓'; status.className = 'mifp-status available';
            editMifpValid = true;
          }
        })
        .catch(() => { status.textContent = ''; editMifpValid = true; });
    }, 500);
  });

  // Validation date naissance dans édition
  document.getElementById('edit-italiki').addEventListener('change', function () {
    const err = document.getElementById('edit-err-italiki');
    err.textContent = ''; this.classList.remove('input-error');
    if (!this.value) return;
    const d = new Date(this.value + 'T00:00:00');
    if (d < new Date('1920-01-01')) {
      err.textContent = '❌ Date avant 01/01/1920 non autorisée.';
      this.classList.add('input-error'); this.value = '';
    } else if (d > new Date()) {
      err.textContent = '❌ Date dans le futur non autorisée.';
      this.classList.add('input-error'); this.value = '';
    }
  });

  // Validation date délivrance dans édition
  document.getElementById('edit-date_delivrance').addEventListener('change', function () {
    const err = document.getElementById('edit-err-date_delivrance');
    err.textContent = ''; this.classList.remove('input-error');
    if (!this.value) return;
    if (new Date(this.value + 'T00:00:00') > new Date()) {
      err.textContent = '❌ Date de délivrance dans le futur non autorisée.';
      this.classList.add('input-error'); this.value = '';
    }
  });

  // Validation téléphone dans édition
  document.getElementById('edit-telephone').addEventListener('input', function () {
    const err = document.getElementById('edit-err-telephone');
    this.value = this.value.replace(/[^\d\s]/g,'');
    const digits = this.value.replace(/\s/g,'');
    err.textContent = digits.length > 0 && (digits.length < 7 || digits.length > 9)
      ? '⚠ 7 à 9 chiffres attendus.' : '';
  });

  // Photo preview dans édition
  document.getElementById('edit-photo').addEventListener('change', function () {
    const file = this.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
      const el = document.getElementById('edit-current-photo');
      if (el) { el.src = e.target.result; el.title = 'Nouvelle photo (aperçu)'; }
      else {
        document.getElementById('edit-photo-preview-wrap').innerHTML =
          `<div style="text-align:center;margin-bottom:16px;">
            <p style="font-size:.75rem;color:#7a7a99;margin-bottom:6px;">Nouvelle photo (aperçu)</p>
            <img src="${e.target.result}" style="max-width:80px;max-height:100px;border-radius:6px;border:2px dashed #1a7a3c;">
          </div>`;
      }
    };
    reader.readAsDataURL(file);
  });

  // Soumission formulaire édition
  document.getElementById('editForm').addEventListener('submit', function (e) {
    if (!editMifpValid) {
      e.preventDefault();
      const alert = document.getElementById('edit-alert');
      alert.textContent = '❌ Le N° MIFP saisi est déjà utilisé — corrigez-le avant de soumettre.';
      alert.className = 'alert error';
      alert.classList.remove('hidden');
      return;
    }
    document.getElementById('edit-submit-btn').disabled = true;
    document.getElementById('edit-submit-btn').textContent = '⏳ Enregistrement...';
  });

  // ── DELETE ─────────────────────────────────────────────────────────────────
  function confirmDelete(id, name) {
    document.getElementById('delete-name').textContent = name;
    document.getElementById('delete-link').href = `admin.php?action=delete&id=${id}`;
    openModal('modal-delete');
  }

  // ── LIGHTBOX ───────────────────────────────────────────────────────────────
  function openLightbox(src, name) {
    document.getElementById('lightbox-img').src = src;
    document.getElementById('lightbox-img').alt = name;
    document.getElementById('lightbox').classList.add('open');
  }
  function closeLightbox() { document.getElementById('lightbox').classList.remove('open'); }
  document.getElementById('lightbox').addEventListener('click', e => { if (e.target === e.currentTarget) closeLightbox(); });

  // ── DATE FORMAT ────────────────────────────────────────────────────────────
  function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d);
    return isNaN(dt) ? d : dt.toLocaleDateString('fr-FR');
  }

  // ── ESC KEY ────────────────────────────────────────────────────────────────
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      ['modal-view','modal-edit','modal-delete'].forEach(closeModal);
      closeLightbox();
    }
  });

  // Auto-dismiss alert
  <?php if ($message): ?>
  setTimeout(() => {
    const a = document.getElementById('admin-alert');
    if (a) { a.style.opacity='0'; a.style.transition='.4s'; setTimeout(()=>a.remove(),400); }
  }, 5000);
  <?php endif; ?>
  </script>
</body>
</html>
