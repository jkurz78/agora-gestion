<?php
require_once __DIR__ . '/../includes/header.php';

$db = get_db();

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $montants = array_map('floatval', $_POST['montant'] ?? []);
        $montant_total = array_sum($montants);

        $db->beginTransaction();
        try {
            if (empty($_POST['depense_id'])) {
                $stmt = $db->prepare('INSERT INTO depenses (date,libelle,montant_total,mode_paiement,beneficiaire,reference,compte_id,notes,saisi_par) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([
                    $_POST['date'],
                    trim($_POST['libelle']),
                    $montant_total,
                    $_POST['mode_paiement'],
                    trim($_POST['beneficiaire']) ?: null,
                    trim($_POST['reference'])    ?: null,
                    $_POST['compte_id']          ?: null,
                    trim($_POST['notes'])         ?: null,
                    $_SESSION['user_id'],
                ]);
                $depense_id = (int)$db->lastInsertId();
                $msg = 'Dépense créée.';
            } else {
                $depense_id = (int)$_POST['depense_id'];
                $db->prepare('UPDATE depenses SET date=?,libelle=?,montant_total=?,mode_paiement=?,beneficiaire=?,reference=?,compte_id=?,notes=? WHERE id=?')
                   ->execute([
                       $_POST['date'],
                       trim($_POST['libelle']),
                       $montant_total,
                       $_POST['mode_paiement'],
                       trim($_POST['beneficiaire']) ?: null,
                       trim($_POST['reference'])    ?: null,
                       $_POST['compte_id']          ?: null,
                       trim($_POST['notes'])         ?: null,
                       $depense_id,
                   ]);
                $db->prepare('DELETE FROM depense_lignes WHERE depense_id=?')->execute([$depense_id]);
                $msg = 'Dépense mise à jour.';
            }

            $sc_ids    = $_POST['sous_categorie_id'] ?? [];
            $op_ids    = $_POST['operation_id']      ?? [];
            $seances   = $_POST['seance']            ?? [];
            $montants_arr = $_POST['montant']        ?? [];
            $ligne_notes  = $_POST['ligne_notes']    ?? [];

            $ins = $db->prepare('INSERT INTO depense_lignes (depense_id,sous_categorie_id,operation_id,seance,montant,notes) VALUES (?,?,?,?,?,?)');
            foreach ($sc_ids as $i => $sc_id) {
                $sc_id = (int)$sc_id;
                if (!$sc_id || !isset($montants_arr[$i]) || (float)$montants_arr[$i] <= 0) continue;
                $op_id  = !empty($op_ids[$i])  ? (int)$op_ids[$i]  : null;
                $seance = ($op_id && !empty($seances[$i])) ? (int)$seances[$i] : null;
                $ins->execute([
                    $depense_id,
                    $sc_id,
                    $op_id,
                    $seance,
                    (float)$montants_arr[$i],
                    trim($ligne_notes[$i] ?? '') ?: null,
                ]);
            }

            $db->commit();
            flash('success', $msg);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Depense save error: ' . $e->getMessage());
            flash('error', 'Erreur lors de l\'enregistrement.');
        }
        header('Location: /accounting/pages/depenses.php'); exit;
    }

    if ($action === 'delete') {
        $db->prepare('DELETE FROM depenses WHERE id=?')->execute([(int)$_POST['id']]);
        flash('success', 'Dépense supprimée.');
        header('Location: /accounting/pages/depenses.php'); exit;
    }
}

// ─── Load form data (for add/edit) ───────────────────────────────────────────
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_dep = null;
$edit_lignes = [];

if ($edit_id) {
    $s = $db->prepare('SELECT * FROM depenses WHERE id=?');
    $s->execute([$edit_id]);
    $edit_dep = $s->fetch();
    if ($edit_dep) {
        $s2 = $db->prepare('SELECT * FROM depense_lignes WHERE depense_id=? ORDER BY id');
        $s2->execute([$edit_id]);
        $edit_lignes = $s2->fetchAll();
    }
}

// Preload for form selects
$sous_cats = $db->query('
    SELECT sc.id, sc.nom, c.nom AS cat_nom, c.type
    FROM sous_categories sc
    JOIN categories c ON c.id = sc.categorie_id
    WHERE c.type = "depense"
    ORDER BY c.nom, sc.nom
')->fetchAll();

$operations = $db->query('SELECT id, nom, nombre_seances FROM operations ORDER BY nom')->fetchAll();
$operations_map = array_column($operations, null, 'id');

$comptes = $db->query('SELECT id, nom FROM comptes_bancaires ORDER BY nom')->fetchAll();

// ─── Show form if add/edit ────────────────────────────────────────────────────
if ($edit_id !== 0 || isset($_GET['new'])):
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><?= $edit_dep ? 'Modifier la dépense' : 'Nouvelle dépense' ?></h2>
  <a href="/accounting/pages/depenses.php" class="btn btn-outline-secondary">← Retour</a>
</div>

<form method="post" id="depenseForm">
  <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
  <input type="hidden" name="action"      value="save">
  <input type="hidden" name="depense_id"  value="<?= $edit_id ?: '' ?>">

  <!-- Header -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">En-tête de la dépense</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-2">
          <label class="form-label">Date <span class="text-danger">*</span></label>
          <input type="date" name="date" class="form-control" required
                 value="<?= htmlspecialchars($edit_dep['date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Libellé <span class="text-danger">*</span></label>
          <input type="text" name="libelle" class="form-control" required maxlength="255"
                 value="<?= htmlspecialchars($edit_dep['libelle'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Mode de paiement <span class="text-danger">*</span></label>
          <select name="mode_paiement" class="form-select" required>
            <?php
            $modes = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces', 'cb' => 'CB', 'prelevement' => 'Prélèvement'];
            $cur_mode = $edit_dep['mode_paiement'] ?? 'virement';
            foreach ($modes as $val => $lbl): ?>
              <option value="<?= $val ?>" <?= $cur_mode === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Bénéficiaire</label>
          <input type="text" name="beneficiaire" class="form-control" maxlength="150"
                 value="<?= htmlspecialchars($edit_dep['beneficiaire'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Réf. / N° pièce</label>
          <input type="text" name="reference" class="form-control" maxlength="100"
                 value="<?= htmlspecialchars($edit_dep['reference'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Compte bancaire</label>
          <select name="compte_id" class="form-select">
            <option value="">— Non affecté —</option>
            <?php foreach ($comptes as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($edit_dep['compte_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['nom']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-9">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control" maxlength="500"
                 value="<?= htmlspecialchars($edit_dep['notes'] ?? '') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Lines -->
  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
      <span>Ventilation analytique</span>
      <span class="text-muted small">Total : <strong id="totalDisplay">0,00 €</strong></span>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0" id="lignesTable">
        <thead class="table-light">
          <tr>
            <th style="width:28%">Sous-catégorie</th>
            <th style="width:22%">Opération</th>
            <th style="width:8%">Séance</th>
            <th style="width:12%">Montant (€)</th>
            <th style="width:24%">Notes</th>
            <th style="width:6%"></th>
          </tr>
        </thead>
        <tbody id="lignesBody">
          <?php
          $init_lignes = $edit_lignes ?: [['sous_categorie_id' => '', 'operation_id' => '', 'seance' => '', 'montant' => '', 'notes' => '']];
          foreach ($init_lignes as $idx => $ligne):
          ?>
          <tr class="ligne-row">
            <td>
              <select name="sous_categorie_id[]" class="form-select form-select-sm" required>
                <option value="">— Choisir —</option>
                <?php foreach ($sous_cats as $sc): ?>
                  <option value="<?= (int)$sc['id'] ?>"
                    <?= ($ligne['sous_categorie_id'] ?? '') == $sc['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sc['cat_nom'] . ' / ' . $sc['nom']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select name="operation_id[]" class="form-select form-select-sm op-select" onchange="onOpChange(this)">
                <option value="">— Aucune —</option>
                <?php foreach ($operations as $op): ?>
                  <option value="<?= (int)$op['id'] ?>"
                    data-seances="<?= (int)($op['nombre_seances'] ?? 0) ?>"
                    <?= ($ligne['operation_id'] ?? '') == $op['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($op['nom']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <?php
              $op_seances = 0;
              if (!empty($ligne['operation_id']) && isset($operations_map[$ligne['operation_id']])) {
                  $op_seances = (int)($operations_map[$ligne['operation_id']]['nombre_seances'] ?? 0);
              }
              ?>
              <div class="seance-wrap" <?= $op_seances ? '' : 'style="display:none"' ?>>
                <input type="number" name="seance[]" class="form-control form-control-sm seance-input"
                       min="1" max="<?= $op_seances ?: '' ?>"
                       value="<?= htmlspecialchars($ligne['seance'] ?? '') ?>">
              </div>
            </td>
            <td>
              <input type="number" name="montant[]" class="form-control form-control-sm montant-input"
                     step="0.01" min="0.01" required
                     value="<?= htmlspecialchars($ligne['montant'] ?? '') ?>">
            </td>
            <td>
              <input type="text" name="ligne_notes[]" class="form-control form-control-sm"
                     value="<?= htmlspecialchars($ligne['notes'] ?? '') ?>">
            </td>
            <td>
              <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLigne(this)">✕</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <button type="button" class="btn btn-sm btn-outline-success" onclick="addLigne()">+ Ajouter une ligne</button>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">Enregistrer</button>
    <a href="/accounting/pages/depenses.php" class="btn btn-secondary">Annuler</a>
  </div>
</form>

<script>
// Operations map for seance selector
const opsMap = <?= json_encode(array_map(fn($o) => ['nom' => $o['nom'], 'nombre_seances' => (int)($o['nombre_seances'] ?? 0)], $operations_map), JSON_HEX_TAG) ?>;

function onOpChange(select) {
    const row   = select.closest('tr');
    const wrap  = row.querySelector('.seance-wrap');
    const input = row.querySelector('.seance-input');
    const opId  = select.value;
    const op    = opsMap[opId];
    if (op && op.nombre_seances > 0) {
        input.max = op.nombre_seances;
        input.min = 1;
        input.required = true;
        wrap.style.display = '';
    } else {
        wrap.style.display = 'none';
        input.value   = '';
        input.required = false;
    }
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('.montant-input').forEach(i => {
        const v = parseFloat(i.value);
        if (!isNaN(v)) total += v;
    });
    document.getElementById('totalDisplay').textContent =
        total.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
}
document.addEventListener('input', e => { if (e.target.classList.contains('montant-input')) updateTotal(); });
updateTotal();

function addLigne() {
    const tbody = document.getElementById('lignesBody');
    const first = tbody.querySelector('tr.ligne-row');
    const clone = first.cloneNode(true);
    // Clear values
    clone.querySelectorAll('input').forEach(i => { i.value = ''; i.required = false; });
    clone.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
    clone.querySelector('.seance-wrap').style.display = 'none';
    clone.querySelector('select[name="sous_categorie_id[]"]').required = true;
    clone.querySelector('input[name="montant[]"]').required = true;
    tbody.appendChild(clone);
}

function removeLigne(btn) {
    const rows = document.querySelectorAll('tr.ligne-row');
    if (rows.length <= 1) return; // Keep at least one line
    btn.closest('tr').remove();
    updateTotal();
}
</script>

<?php else: // ═══ LIST VIEW ═══════════════════════════════════════════════ ?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="mb-0">Dépenses</h2>
  <a href="?new" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouvelle dépense</a>
</div>

<!-- Filters -->
<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-sm-2">
    <label class="form-label small">Du</label>
    <input type="date" name="date_debut" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['date_debut'] ?? '') ?>">
  </div>
  <div class="col-sm-2">
    <label class="form-label small">Au</label>
    <input type="date" name="date_fin" class="form-control form-control-sm" value="<?= htmlspecialchars($_GET['date_fin'] ?? '') ?>">
  </div>
  <div class="col-sm-3">
    <label class="form-label small">Compte</label>
    <select name="compte_id" class="form-select form-select-sm">
      <option value="">Tous les comptes</option>
      <?php foreach ($comptes as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($_GET['compte_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['nom']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <label class="form-label small">Pointé</label>
    <select name="pointe" class="form-select form-select-sm">
      <option value="">Tous</option>
      <option value="0" <?= isset($_GET['pointe']) && $_GET['pointe'] === '0' ? 'selected' : '' ?>>Non pointé</option>
      <option value="1" <?= ($_GET['pointe'] ?? '') === '1' ? 'selected' : '' ?>>Pointé</option>
    </select>
  </div>
  <div class="col-sm-1">
    <button type="submit" class="btn btn-sm btn-secondary w-100">Filtrer</button>
  </div>
  <div class="col-sm-1">
    <a href="/accounting/pages/depenses.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
  </div>
</form>

<?php
// Build filtered query
$where  = ['1=1'];
$params = [];
if (!empty($_GET['date_debut'])) { $where[] = 'd.date >= ?'; $params[] = $_GET['date_debut']; }
if (!empty($_GET['date_fin']))   { $where[] = 'd.date <= ?'; $params[] = $_GET['date_fin']; }
if (!empty($_GET['compte_id']))  { $where[] = 'd.compte_id = ?'; $params[] = (int)$_GET['compte_id']; }
if (isset($_GET['pointe']) && $_GET['pointe'] !== '') { $where[] = 'd.pointe = ?'; $params[] = (int)$_GET['pointe']; }

$stmt = $db->prepare('
    SELECT d.*, u.nom AS saisi_nom, cb.nom AS compte_nom
    FROM depenses d
    LEFT JOIN users u ON u.id = d.saisi_par
    LEFT JOIN comptes_bancaires cb ON cb.id = d.compte_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY d.date DESC, d.created_at DESC
');
$stmt->execute($params);
$depenses = $stmt->fetchAll();
$total_filtre = array_sum(array_column($depenses, 'montant_total'));
$modes_labels = ['virement' => 'Virement', 'cheque' => 'Chèque', 'especes' => 'Espèces', 'cb' => 'CB', 'prelevement' => 'Prélèvement'];
?>

<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><?= count($depenses) ?> dépense(s)</span>
    <strong class="text-danger"><?= number_format($total_filtre, 2, ',', ' ') ?> €</strong>
  </div>
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Date</th><th>Libellé</th><th>Bénéficiaire</th><th>Mode</th>
          <th>Compte</th><th class="text-end">Montant</th><th>✓</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($depenses as $d): ?>
        <tr>
          <td class="small"><?= date('d/m/Y', strtotime($d['date'])) ?></td>
          <td>
            <span class="fw-medium"><?= htmlspecialchars($d['libelle']) ?></span>
            <?php if ($d['reference']): ?><br><span class="text-muted small"><?= htmlspecialchars($d['reference']) ?></span><?php endif; ?>
          </td>
          <td class="small"><?= htmlspecialchars($d['beneficiaire'] ?? '—') ?></td>
          <td class="small"><?= $modes_labels[$d['mode_paiement']] ?? htmlspecialchars($d['mode_paiement']) ?></td>
          <td class="small"><?= htmlspecialchars($d['compte_nom'] ?? '—') ?></td>
          <td class="text-end fw-medium text-danger"><?= number_format((float)$d['montant_total'], 2, ',', ' ') ?> €</td>
          <td><?= $d['pointe'] ? '<span class="text-success">✓</span>' : '' ?></td>
          <td class="text-end text-nowrap">
            <a href="?edit=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-secondary me-1">Modifier</a>
            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette dépense ?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action"     value="delete">
              <input type="hidden" name="id"         value="<?= (int)$d['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$depenses): ?>
        <tr><td colspan="8" class="text-muted text-center py-3">Aucune dépense trouvée.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
