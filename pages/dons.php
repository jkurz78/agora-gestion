<?php
require_once __DIR__ . '/../includes/header.php';

$db = get_db();

$ex = current_exercice();
$ex_label = exercice_label($ex);

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Save don (add or edit)
    if ($action === 'save_don') {
        $mode_paiement = in_array($_POST['mode_paiement'] ?? '', ['virement', 'cheque', 'especes', 'cb', 'prelevement'])
            ? $_POST['mode_paiement'] : 'especes';

        // Donateur selection
        $donateur_id = null;
        $type_donateur = $_POST['type_donateur'] ?? 'anonyme';
        if ($type_donateur === 'nouveau') {
            $nom   = trim($_POST['d_nom']   ?? '');
            $prenom = trim($_POST['d_prenom'] ?? '');
            if (!$nom || !$prenom) {
                flash('error', 'Nom et prénom du donateur sont obligatoires.');
                $redirect = isset($_POST['don_id']) && $_POST['don_id'] ? '/accounting/pages/dons.php?edit=' . (int)$_POST['don_id'] : '/accounting/pages/dons.php?action=add';
                header('Location: ' . $redirect); exit;
            }
            $stmt = $db->prepare('INSERT INTO donateurs (nom, prenom, email, adresse) VALUES (?,?,?,?)');
            $stmt->execute([
                $nom,
                $prenom,
                trim($_POST['d_email']   ?? '') ?: null,
                trim($_POST['d_adresse'] ?? '') ?: null,
            ]);
            $donateur_id = (int)$db->lastInsertId();
        } elseif ($type_donateur === 'existant' && !empty($_POST['donateur_id'])) {
            $donateur_id = (int)$_POST['donateur_id'];
        }

        // Opération & séance
        $op_id  = !empty($_POST['operation_id']) ? (int)$_POST['operation_id'] : null;
        $seance = null;
        if ($op_id && !empty($_POST['seance'])) {
            $seance = (int)$_POST['seance'];
        }

        $montant   = (float)$_POST['montant'];
        $date = $_POST['date'] ?? '';
        if (!$date || !strtotime($date)) {
            flash('error', 'Date invalide.');
            header('Location: /accounting/pages/dons.php?action=add'); exit;
        }
        $objet     = trim($_POST['objet'] ?? '') ?: null;
        $compte_id = !empty($_POST['compte_id']) ? (int)$_POST['compte_id'] : null;

        if (empty($_POST['don_id'])) {
            $db->prepare('INSERT INTO dons (donateur_id,date,montant,mode_paiement,objet,operation_id,seance,compte_id,saisi_par) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$donateur_id, $date, $montant, $mode_paiement, $objet, $op_id, $seance, $compte_id, $_SESSION['user_id']]);
            flash('success', 'Don enregistré.');
        } else {
            $don_id = (int)$_POST['don_id'];
            $db->prepare('UPDATE dons SET donateur_id=?,date=?,montant=?,mode_paiement=?,objet=?,operation_id=?,seance=?,compte_id=? WHERE id=?')
               ->execute([$donateur_id, $date, $montant, $mode_paiement, $objet, $op_id, $seance, $compte_id, $don_id]);
            flash('success', 'Don mis à jour.');
        }
        header('Location: /accounting/pages/dons.php'); exit;
    }

    // Delete don
    if ($action === 'delete_don') {
        $don_id = (int)$_POST['don_id'];
        // Retrieve donateur_id for redirect if we came from a donateur view
        $s = $db->prepare('SELECT donateur_id FROM dons WHERE id=?');
        $s->execute([$don_id]);
        $don_row = $s->fetch();
        $db->prepare('DELETE FROM dons WHERE id=?')->execute([$don_id]);
        flash('success', 'Don supprimé.');
        if ($don_row && $don_row['donateur_id']) {
            header('Location: /accounting/pages/dons.php?donateur=' . $don_row['donateur_id']); exit;
        }
        header('Location: /accounting/pages/dons.php'); exit;
    }

    // Save donateur info (edit)
    if ($action === 'save_donateur') {
        $donateur_id = (int)$_POST['donateur_id'];
        $db->prepare('UPDATE donateurs SET nom=?,prenom=?,email=?,adresse=? WHERE id=?')
           ->execute([
               trim($_POST['nom']),
               trim($_POST['prenom']),
               trim($_POST['email'] ?? '') ?: null,
               trim($_POST['adresse'] ?? '') ?: null,
               $donateur_id,
           ]);
        flash('success', 'Donateur mis à jour.');
        header('Location: /accounting/pages/dons.php?donateur=' . $donateur_id); exit;
    }

    // Delete donateur (their dons become anonymous via FK ON DELETE SET NULL)
    if ($action === 'delete_donateur') {
        $donateur_id = (int)$_POST['donateur_id'];
        $db->prepare('DELETE FROM donateurs WHERE id=?')->execute([$donateur_id]);
        flash('success', 'Donateur supprimé. Ses dons sont désormais anonymes.');
        header('Location: /accounting/pages/dons.php'); exit;
    }

    header('Location: /accounting/pages/dons.php'); exit;
}

// ─── Shared data for forms ────────────────────────────────────────────────────
$operations = $db->query('SELECT id, nom, nombre_seances FROM operations ORDER BY nom')->fetchAll();
$operations_map = array_column($operations, null, 'id');
$donateurs_list = $db->query('SELECT id, nom, prenom FROM donateurs ORDER BY nom, prenom')->fetchAll();
$comptes = $db->query('SELECT id, nom FROM comptes_bancaires ORDER BY nom')->fetchAll();

$modes_labels = [
    'virement'   => 'Virement',
    'cheque'     => 'Chèque',
    'especes'    => 'Espèces',
    'cb'         => 'CB',
    'prelevement' => 'Prélèvement',
];

// ─── DONATEUR DETAIL VIEW ─────────────────────────────────────────────────────
if (isset($_GET['donateur'])) {
    $donateur_id = (int)$_GET['donateur'];
    $s = $db->prepare('SELECT * FROM donateurs WHERE id=?');
    $s->execute([$donateur_id]);
    $donateur = $s->fetch();
    if (!$donateur) {
        flash('error', 'Donateur introuvable.');
        header('Location: /accounting/pages/dons.php'); exit;
    }

    $dons_stmt = $db->prepare('
        SELECT d.*, op.nom AS operation_nom, cb.nom AS compte_nom
        FROM dons d
        LEFT JOIN operations op ON op.id = d.operation_id
        LEFT JOIN comptes_bancaires cb ON cb.id = d.compte_id
        WHERE d.donateur_id = ?
        ORDER BY d.date DESC, d.created_at DESC
    ');
    $dons_stmt->execute([$donateur_id]);
    $dons = $dons_stmt->fetchAll();

    $total_dons = array_sum(array_column($dons, 'montant'));
    $donateur_name = htmlspecialchars($donateur['prenom'] . ' ' . $donateur['nom']);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><?= $donateur_name ?></h2>
  <a href="/accounting/pages/dons.php" class="btn btn-outline-secondary">← Retour à la liste</a>
</div>

<div class="row g-4">
  <!-- Donateur info card -->
  <div class="col-md-5">
    <div class="card">
      <div class="card-header">Informations du donateur</div>
      <div class="card-body">
        <form method="post" action="/accounting/pages/dons.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_donateur">
          <input type="hidden" name="donateur_id" value="<?= (int)$donateur['id'] ?>">

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label form-label-sm">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control form-control-sm" value="<?= htmlspecialchars($donateur['nom']) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Prénom <span class="text-danger">*</span></label>
              <input type="text" name="prenom" class="form-control form-control-sm" value="<?= htmlspecialchars($donateur['prenom']) ?>" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Email</label>
            <input type="email" name="email" class="form-control form-control-sm" value="<?= htmlspecialchars($donateur['email'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Adresse</label>
            <textarea name="adresse" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($donateur['adresse'] ?? '') ?></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteDonateur">Supprimer</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Dons list -->
  <div class="col-md-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Dons de <?= $donateur_name ?></span>
        <strong class="text-success"><?= number_format($total_dons, 2, ',', ' ') ?> €</strong>
      </div>
      <div class="card-body p-0">
        <?php if ($dons): ?>
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Objet</th>
              <th>Opération</th>
              <th class="text-end">Montant</th>
              <th>Mode</th>
              <th>✓</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dons as $don): ?>
            <tr>
              <td class="small"><?= date('d/m/Y', strtotime($don['date'])) ?></td>
              <td><?= $don['objet'] ? htmlspecialchars($don['objet']) : '<span class="text-muted">—</span>' ?></td>
              <td class="small"><?= $don['operation_nom'] ? htmlspecialchars($don['operation_nom']) : '<span class="text-muted">—</span>' ?></td>
              <td class="text-end fw-medium text-success"><?= number_format((float)$don['montant'], 2, ',', ' ') ?> €</td>
              <td class="small"><?= $modes_labels[$don['mode_paiement']] ?? htmlspecialchars($don['mode_paiement']) ?></td>
              <td><?= $don['pointe'] ? '<span class="text-success">✓</span>' : '' ?></td>
              <td class="text-nowrap">
                <a href="/accounting/pages/dons.php?edit=<?= (int)$don['id'] ?>" class="btn btn-sm btn-outline-secondary">Modifier</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce don ?')">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="delete_don">
                  <input type="hidden" name="don_id" value="<?= (int)$don['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">✕</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <p class="p-3 text-muted mb-0">Aucun don enregistré pour ce donateur.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Delete donateur modal -->
<div class="modal fade" id="deleteDonateur" tabindex="-1" aria-labelledby="deleteDonateurLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteDonateurLabel">Supprimer le donateur</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Supprimer <strong><?= $donateur_name ?></strong> ? Ses dons seront conservés mais deviendront anonymes.
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <form method="post" action="/accounting/pages/dons.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="delete_donateur">
          <input type="hidden" name="donateur_id" value="<?= (int)$donateur['id'] ?>">
          <button type="submit" class="btn btn-danger">Supprimer</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ─── ADD / EDIT FORM ──────────────────────────────────────────────────────────
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_don = null;

if ($edit_id) {
    $s = $db->prepare('SELECT * FROM dons WHERE id=?');
    $s->execute([$edit_id]);
    $edit_don = $s->fetch();
    if (!$edit_don) {
        flash('error', 'Don introuvable.');
        header('Location: /accounting/pages/dons.php'); exit;
    }
}

if ($edit_id !== 0 || isset($_GET['action']) && $_GET['action'] === 'add'):

// Pre-determine type_donateur for edit form
$edit_type_donateur = 'anonyme';
if ($edit_don && $edit_don['donateur_id']) {
    $edit_type_donateur = 'existant';
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><?= $edit_don ? 'Modifier le don' : 'Nouveau don' ?></h2>
  <a href="/accounting/pages/dons.php" class="btn btn-outline-secondary">← Retour</a>
</div>

<form method="post" action="/accounting/pages/dons.php">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="action" value="save_don">
  <input type="hidden" name="don_id" value="<?= $edit_id ?: '' ?>">

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Informations du don</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-2">
          <label class="form-label">Date <span class="text-danger">*</span></label>
          <input type="date" name="date" class="form-control" required
                 value="<?= htmlspecialchars($edit_don['date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Montant <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="number" name="montant" class="form-control" step="0.01" min="0.01" required
                   value="<?= htmlspecialchars($edit_don['montant'] ?? '') ?>">
            <span class="input-group-text">€</span>
          </div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Mode de paiement <span class="text-danger">*</span></label>
          <select name="mode_paiement" class="form-select" required>
            <?php
            $cur_mode = $edit_don['mode_paiement'] ?? 'cheque';
            foreach ($modes_labels as $val => $lbl): ?>
              <option value="<?= $val ?>" <?= $cur_mode === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Objet</label>
          <input type="text" name="objet" class="form-control" maxlength="255"
                 value="<?= htmlspecialchars($edit_don['objet'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Compte bancaire</label>
          <select name="compte_id" class="form-select">
            <option value="">— Aucun —</option>
            <?php foreach ($comptes as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($edit_don['compte_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Opération</label>
          <select name="operation_id" class="form-select" id="op_select" onchange="onOpChange(this)">
            <option value="">— Aucune —</option>
            <?php foreach ($operations as $op): ?>
              <option value="<?= (int)$op['id'] ?>"
                      data-seances="<?= (int)($op['nombre_seances'] ?? 0) ?>"
                      <?= ($edit_don['operation_id'] ?? '') == $op['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($op['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <?php
          $edit_op_seances = 0;
          if (!empty($edit_don['operation_id']) && isset($operations_map[$edit_don['operation_id']])) {
              $edit_op_seances = (int)($operations_map[$edit_don['operation_id']]['nombre_seances'] ?? 0);
          }
          ?>
          <label class="form-label">Séance</label>
          <div id="seance_wrap" <?= $edit_op_seances ? '' : 'style="display:none"' ?>>
            <input type="number" name="seance" class="form-control" id="seance_input"
                   min="1" max="<?= $edit_op_seances ?: '' ?>"
                   value="<?= htmlspecialchars($edit_don['seance'] ?? '') ?>">
          </div>
          <span id="seance_placeholder" class="text-muted small" <?= $edit_op_seances ? 'style="display:none"' : '' ?>>— N/A —</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Donateur selection -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Donateur</div>
    <div class="card-body">
      <div class="mb-3">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="type_donateur" id="td_anonyme" value="anonyme"
                 <?= $edit_type_donateur === 'anonyme' ? 'checked' : '' ?> onchange="onDonateurTypeChange()">
          <label class="form-check-label" for="td_anonyme">Don anonyme</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="type_donateur" id="td_existant" value="existant"
                 <?= $edit_type_donateur === 'existant' ? 'checked' : '' ?> onchange="onDonateurTypeChange()">
          <label class="form-check-label" for="td_existant">Donateur existant</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="type_donateur" id="td_nouveau" value="nouveau"
                 onchange="onDonateurTypeChange()">
          <label class="form-check-label" for="td_nouveau">Nouveau donateur</label>
        </div>
      </div>

      <!-- Existing donateur select -->
      <div id="section_existant" <?= $edit_type_donateur === 'existant' ? '' : 'style="display:none"' ?>>
        <label class="form-label">Choisir un donateur</label>
        <select name="donateur_id" class="form-select" id="donateur_select">
          <option value="">— Choisir —</option>
          <?php foreach ($donateurs_list as $don): ?>
            <option value="<?= (int)$don['id'] ?>"
                    <?= ($edit_don['donateur_id'] ?? '') == $don['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($don['nom'] . ' ' . $don['prenom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- New donateur fields -->
      <div id="section_nouveau" style="display:none">
        <div class="row g-2">
          <div class="col-md-3">
            <label class="form-label form-label-sm">Nom <span class="text-danger">*</span></label>
            <input type="text" name="d_nom" class="form-control form-control-sm" id="d_nom">
          </div>
          <div class="col-md-3">
            <label class="form-label form-label-sm">Prénom <span class="text-danger">*</span></label>
            <input type="text" name="d_prenom" class="form-control form-control-sm" id="d_prenom">
          </div>
          <div class="col-md-3">
            <label class="form-label form-label-sm">Email</label>
            <input type="email" name="d_email" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label form-label-sm">Adresse</label>
            <input type="text" name="d_adresse" class="form-control form-control-sm">
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">Enregistrer</button>
    <a href="/accounting/pages/dons.php" class="btn btn-secondary">Annuler</a>
  </div>
</form>

<script>
const opsMap = <?= json_encode(array_map(fn($o) => ['nom' => $o['nom'], 'nombre_seances' => (int)($o['nombre_seances'] ?? 0)], $operations_map), JSON_HEX_TAG) ?>;

function onOpChange(select) {
    const wrap  = document.getElementById('seance_wrap');
    const input = document.getElementById('seance_input');
    const ph    = document.getElementById('seance_placeholder');
    const opId  = select.value;
    const op    = opsMap[opId];
    if (op && op.nombre_seances > 0) {
        input.max = op.nombre_seances;
        input.min = 1;
        wrap.style.display = '';
        ph.style.display = 'none';
    } else {
        wrap.style.display = 'none';
        ph.style.display = '';
        input.value = '';
    }
}

function onDonateurTypeChange() {
    const type = document.querySelector('input[name="type_donateur"]:checked').value;
    document.getElementById('section_existant').style.display = (type === 'existant') ? '' : 'none';
    document.getElementById('section_nouveau').style.display  = (type === 'nouveau')  ? '' : 'none';
    document.getElementById('d_nom').required   = (type === 'nouveau');
    document.getElementById('d_prenom').required = (type === 'nouveau');
    document.getElementById('donateur_select').required = (type === 'existant');
}

// Init on load
onDonateurTypeChange();
</script>

<?php else: // ═══ LIST VIEW ════════════════════════════════════════════════ ?>

<?php
// Filters
$f_date_debut   = $_GET['date_debut']   ?? '';
$f_date_fin     = $_GET['date_fin']     ?? '';
$f_operation_id = $_GET['operation_id'] ?? '';

$where  = ['1=1'];
$params = [];
if ($f_date_debut !== '')   { $where[] = 'd.date >= ?';        $params[] = $f_date_debut; }
if ($f_date_fin !== '')     { $where[] = 'd.date <= ?';        $params[] = $f_date_fin; }
if ($f_operation_id !== '') { $where[] = 'd.operation_id = ?'; $params[] = (int)$f_operation_id; }

$stmt = $db->prepare('
    SELECT d.*,
           don.nom AS donateur_nom, don.prenom AS donateur_prenom,
           op.nom AS operation_nom,
           cb.nom AS compte_nom
    FROM dons d
    LEFT JOIN donateurs don ON don.id = d.donateur_id
    LEFT JOIN operations op ON op.id = d.operation_id
    LEFT JOIN comptes_bancaires cb ON cb.id = d.compte_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY d.date DESC, d.created_at DESC
');
$stmt->execute($params);
$dons = $stmt->fetchAll();

$total_filtre = array_sum(array_column($dons, 'montant'));

// Stats: total for current exercice
$ex_start = $ex . '-09-01';
$ex_end   = ($ex + 1) . '-08-31';
$stats_stmt = $db->prepare('
    SELECT
        COALESCE(SUM(montant), 0) AS total_ex,
        COUNT(DISTINCT donateur_id) AS nb_donateurs
    FROM dons
    WHERE date BETWEEN ? AND ?
');
$stats_stmt->execute([$ex_start, $ex_end]);
$stats = $stats_stmt->fetch();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="mb-0">Dons <small class="text-muted fs-6"><?= htmlspecialchars($ex_label) ?></small></h2>
  <a href="/accounting/pages/dons.php?action=add" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouveau don</a>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card text-center text-bg-success">
      <div class="card-body">
        <div class="fs-4 fw-bold"><?= number_format((float)$stats['total_ex'], 2, ',', ' ') ?> €</div>
        <div>Total dons <?= htmlspecialchars($ex_label) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body">
        <div class="fs-2 fw-bold"><?= (int)$stats['nb_donateurs'] ?></div>
        <div class="text-muted">Donateurs identifiés</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body">
        <div class="fs-2 fw-bold"><?= count($dons) ?></div>
        <div class="text-muted">Dons affichés</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-sm-2">
    <label class="form-label small">Du</label>
    <input type="date" name="date_debut" class="form-control form-control-sm" value="<?= htmlspecialchars($f_date_debut) ?>">
  </div>
  <div class="col-sm-2">
    <label class="form-label small">Au</label>
    <input type="date" name="date_fin" class="form-control form-control-sm" value="<?= htmlspecialchars($f_date_fin) ?>">
  </div>
  <div class="col-sm-4">
    <label class="form-label small">Opération</label>
    <select name="operation_id" class="form-select form-select-sm">
      <option value="">Toutes les opérations</option>
      <?php foreach ($operations as $op): ?>
        <option value="<?= (int)$op['id'] ?>" <?= $f_operation_id == $op['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($op['nom']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-1">
    <button type="submit" class="btn btn-sm btn-secondary w-100">Filtrer</button>
  </div>
  <div class="col-sm-1">
    <a href="/accounting/pages/dons.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
  </div>
</form>

<!-- Dons table -->
<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= count($dons) ?> don(s)</span>
    <strong class="text-success"><?= number_format($total_filtre, 2, ',', ' ') ?> €</strong>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Donateur</th>
          <th>Objet</th>
          <th>Opération</th>
          <th class="text-end">Montant</th>
          <th>Mode</th>
          <th class="text-center">Pointé</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($dons as $don): ?>
        <tr>
          <td class="small"><?= date('d/m/Y', strtotime($don['date'])) ?></td>
          <td>
            <?php if ($don['donateur_id']): ?>
              <a href="/accounting/pages/dons.php?donateur=<?= (int)$don['donateur_id'] ?>">
                <?= htmlspecialchars($don['donateur_prenom'] . ' ' . $don['donateur_nom']) ?>
              </a>
            <?php else: ?>
              <span class="text-muted">Anonyme</span>
            <?php endif; ?>
          </td>
          <td><?= $don['objet'] ? htmlspecialchars($don['objet']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= $don['operation_nom'] ? htmlspecialchars($don['operation_nom']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end fw-medium text-success"><?= number_format((float)$don['montant'], 2, ',', ' ') ?> €</td>
          <td class="small"><?= $modes_labels[$don['mode_paiement']] ?? htmlspecialchars($don['mode_paiement']) ?></td>
          <td class="text-center"><?= $don['pointe'] ? '<span class="text-success fw-bold">✓</span>' : '<span class="text-danger">✗</span>' ?></td>
          <td class="text-end text-nowrap">
            <a href="/accounting/pages/dons.php?edit=<?= (int)$don['id'] ?>" class="btn btn-sm btn-outline-secondary me-1">Modifier</a>
            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer ce don ?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="delete_don">
              <input type="hidden" name="don_id" value="<?= (int)$don['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$dons): ?>
        <tr><td colspan="8" class="text-muted text-center py-3">Aucun don trouvé.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
