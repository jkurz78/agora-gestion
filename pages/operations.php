<?php
require_once __DIR__ . '/../includes/header.php';

$db = get_db();

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $data = [
            trim($_POST['nom']),
            trim($_POST['description']) ?: null,
            $_POST['date_debut']     ?: null,
            $_POST['date_fin']       ?: null,
            $_POST['nombre_seances'] !== '' ? (int)$_POST['nombre_seances'] : null,
            $_POST['statut'],
        ];
        if (empty($_POST['id'])) {
            $db->prepare('INSERT INTO operations (nom,description,date_debut,date_fin,nombre_seances,statut) VALUES (?,?,?,?,?,?)')
               ->execute($data);
            flash('success', 'Opération créée.');
        } else {
            $data[] = (int)$_POST['id'];
            $db->prepare('UPDATE operations SET nom=?,description=?,date_debut=?,date_fin=?,nombre_seances=?,statut=? WHERE id=?')
               ->execute($data);
            flash('success', 'Opération mise à jour.');
        }
        header('Location: /accounting/pages/operations.php'); exit;
    }

    if ($action === 'delete') {
        $db->prepare('DELETE FROM operations WHERE id=?')->execute([(int)$_POST['id']]);
        flash('success', 'Opération supprimée.');
        header('Location: /accounting/pages/operations.php'); exit;
    }
}

// ─── Detail view ─────────────────────────────────────────────────────────────
if (isset($_GET['id'])) {
    $op = $db->prepare('SELECT * FROM operations WHERE id=?');
    $op->execute([(int)$_GET['id']]);
    $op = $op->fetch();
    if (!$op) { flash('error', 'Opération introuvable.'); header('Location: /accounting/pages/operations.php'); exit; }

    // Depense lines for this operation
    $dep = $db->prepare('
        SELECT dl.montant, dl.seance, dl.notes as ligne_notes,
               d.date, d.libelle, d.id as depense_id,
               sc.nom as sous_cat, c.nom as cat
        FROM depense_lignes dl
        JOIN depenses d ON d.id = dl.depense_id
        JOIN sous_categories sc ON sc.id = dl.sous_categorie_id
        JOIN categories c ON c.id = sc.categorie_id
        WHERE dl.operation_id = ?
        ORDER BY d.date, d.id
    ');
    $dep->execute([$op['id']]);
    $dep_lignes = $dep->fetchAll();

    // Recette lines for this operation
    $rec = $db->prepare('
        SELECT rl.montant, rl.seance, rl.notes as ligne_notes,
               r.date, r.libelle, r.id as recette_id,
               sc.nom as sous_cat, c.nom as cat
        FROM recette_lignes rl
        JOIN recettes r ON r.id = rl.recette_id
        JOIN sous_categories sc ON sc.id = rl.sous_categorie_id
        JOIN categories c ON c.id = sc.categorie_id
        WHERE rl.operation_id = ?
        ORDER BY r.date, r.id
    ');
    $rec->execute([$op['id']]);
    $rec_lignes = $rec->fetchAll();

    $total_dep = array_sum(array_column($dep_lignes, 'montant'));
    $total_rec = array_sum(array_column($rec_lignes, 'montant'));
    $solde     = $total_rec - $total_dep;

    ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h2 class="mb-0"><?= htmlspecialchars($op['nom']) ?></h2>
        <span class="badge <?= $op['statut'] === 'en_cours' ? 'bg-success' : 'bg-secondary' ?>">
          <?= $op['statut'] === 'en_cours' ? 'En cours' : 'Clôturée' ?>
        </span>
        <?php if ($op['nombre_seances']): ?>
          <span class="badge bg-info text-dark"><?= (int)$op['nombre_seances'] ?> séances</span>
        <?php endif; ?>
      </div>
      <a href="/accounting/pages/operations.php" class="btn btn-outline-secondary">← Retour</a>
    </div>

    <?php if ($op['description']): ?>
      <p class="text-muted mb-4"><?= htmlspecialchars($op['description']) ?></p>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body text-center">
            <div class="text-muted small">Total dépenses</div>
            <div class="fs-5 fw-bold text-danger"><?= number_format($total_dep, 2, ',', ' ') ?> €</div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body text-center">
            <div class="text-muted small">Total recettes</div>
            <div class="fs-5 fw-bold text-success"><?= number_format($total_rec, 2, ',', ' ') ?> €</div>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="card-body text-center">
            <div class="text-muted small">Solde</div>
            <div class="fs-5 fw-bold <?= $solde >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($solde, 2, ',', ' ') ?> €</div>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-4">
      <div class="col-lg-6">
        <h5>Dépenses rattachées</h5>
        <?php if ($dep_lignes): ?>
        <table class="table table-sm table-hover">
          <thead><tr><th>Date</th><th>Libellé</th><th>Cat.</th><th>Séance</th><th class="text-end">Montant</th></tr></thead>
          <tbody>
          <?php foreach ($dep_lignes as $l): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($l['date'])) ?></td>
              <td><?= htmlspecialchars($l['libelle']) ?></td>
              <td class="small text-muted"><?= htmlspecialchars($l['cat'] . ' / ' . $l['sous_cat']) ?></td>
              <td><?= $l['seance'] ? '#' . $l['seance'] : '—' ?></td>
              <td class="text-end text-danger"><?= number_format((float)$l['montant'], 2, ',', ' ') ?> €</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4" class="text-end fw-bold">Total</td><td class="text-end fw-bold text-danger"><?= number_format($total_dep, 2, ',', ' ') ?> €</td></tr></tfoot>
        </table>
        <?php else: ?><p class="text-muted small">Aucune dépense rattachée.</p><?php endif; ?>
      </div>
      <div class="col-lg-6">
        <h5>Recettes rattachées</h5>
        <?php if ($rec_lignes): ?>
        <table class="table table-sm table-hover">
          <thead><tr><th>Date</th><th>Libellé</th><th>Cat.</th><th>Séance</th><th class="text-end">Montant</th></tr></thead>
          <tbody>
          <?php foreach ($rec_lignes as $l): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($l['date'])) ?></td>
              <td><?= htmlspecialchars($l['libelle']) ?></td>
              <td class="small text-muted"><?= htmlspecialchars($l['cat'] . ' / ' . $l['sous_cat']) ?></td>
              <td><?= $l['seance'] ? '#' . $l['seance'] : '—' ?></td>
              <td class="text-end text-success"><?= number_format((float)$l['montant'], 2, ',', ' ') ?> €</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4" class="text-end fw-bold">Total</td><td class="text-end fw-bold text-success"><?= number_format($total_rec, 2, ',', ' ') ?> €</td></tr></tfoot>
        </table>
        <?php else: ?><p class="text-muted small">Aucune recette rattachée.</p><?php endif; ?>
      </div>
    </div>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
    <?php return; // Stop here for detail view
}

// ─── List view ────────────────────────────────────────────────────────────────
$filter_statut = $_GET['statut'] ?? '';
$where  = $filter_statut ? 'WHERE statut = ?' : '';
$params = $filter_statut ? [$filter_statut] : [];

$stmt = $db->prepare("SELECT * FROM operations $where ORDER BY created_at DESC");
$stmt->execute($params);
$operations = $stmt->fetchAll();

// For edit modal: load operation if ?edit=X
$edit_op = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare('SELECT * FROM operations WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit_op = $s->fetch();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="mb-0">Opérations</h2>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#opModal">
    <i class="bi bi-plus-lg"></i> Nouvelle opération
  </button>
</div>

<!-- Filters -->
<form method="get" class="row g-2 mb-3">
  <div class="col-auto">
    <select name="statut" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">Toutes les opérations</option>
      <option value="en_cours"  <?= $filter_statut === 'en_cours'  ? 'selected' : '' ?>>En cours</option>
      <option value="cloturee"  <?= $filter_statut === 'cloturee'  ? 'selected' : '' ?>>Clôturées</option>
    </select>
  </div>
</form>

<!-- Operations table -->
<div class="card shadow-sm">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr><th>Nom</th><th>Dates</th><th>Séances</th><th>Statut</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($operations as $op): ?>
        <tr>
          <td>
            <a href="?id=<?= (int)$op['id'] ?>" class="fw-medium text-decoration-none">
              <?= htmlspecialchars($op['nom']) ?>
            </a>
            <?php if ($op['description']): ?>
              <div class="text-muted small"><?= htmlspecialchars(mb_strimwidth($op['description'], 0, 60, '…')) ?></div>
            <?php endif; ?>
          </td>
          <td class="small text-muted">
            <?= $op['date_debut'] ? date('d/m/Y', strtotime($op['date_debut'])) : '—' ?>
            <?= $op['date_fin'] ? ' → ' . date('d/m/Y', strtotime($op['date_fin'])) : '' ?>
          </td>
          <td><?= $op['nombre_seances'] ? (int)$op['nombre_seances'] : '—' ?></td>
          <td>
            <span class="badge <?= $op['statut'] === 'en_cours' ? 'bg-success' : 'bg-secondary' ?>">
              <?= $op['statut'] === 'en_cours' ? 'En cours' : 'Clôturée' ?>
            </span>
          </td>
          <td class="text-end">
            <a href="?edit=<?= (int)$op['id'] ?>" class="btn btn-sm btn-outline-secondary me-1">Modifier</a>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Supprimer cette opération ?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action"     value="delete">
              <input type="hidden" name="id"         value="<?= (int)$op['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$operations): ?>
        <tr><td colspan="5" class="text-muted text-center py-3">Aucune opération.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade <?= $edit_op ? 'show' : '' ?>" id="opModal" tabindex="-1"
     <?= $edit_op ? 'style="display:block"' : '' ?>>
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action"     value="save">
        <input type="hidden" name="id"         value="<?= $edit_op ? (int)$edit_op['id'] : '' ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= $edit_op ? 'Modifier l\'opération' : 'Nouvelle opération' ?></h5>
          <a href="/accounting/pages/operations.php" class="btn-close"></a>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nom <span class="text-danger">*</span></label>
            <input type="text" name="nom" class="form-control" required maxlength="150"
                   value="<?= htmlspecialchars($edit_op['nom'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($edit_op['description'] ?? '') ?></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Date début</label>
              <input type="date" name="date_debut" class="form-control"
                     value="<?= htmlspecialchars($edit_op['date_debut'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Date fin</label>
              <input type="date" name="date_fin" class="form-control"
                     value="<?= htmlspecialchars($edit_op['date_fin'] ?? '') ?>">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Nombre de séances</label>
              <input type="number" name="nombre_seances" class="form-control" min="1"
                     value="<?= htmlspecialchars($edit_op['nombre_seances'] ?? '') ?>"
                     placeholder="Laisser vide si sans séances">
            </div>
            <div class="col-6">
              <label class="form-label">Statut</label>
              <select name="statut" class="form-select">
                <option value="en_cours"  <?= (!$edit_op || $edit_op['statut'] === 'en_cours')  ? 'selected' : '' ?>>En cours</option>
                <option value="cloturee"  <?= ($edit_op && $edit_op['statut'] === 'cloturee')   ? 'selected' : '' ?>>Clôturée</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <a href="/accounting/pages/operations.php" class="btn btn-secondary">Annuler</a>
          <button type="submit" class="btn btn-primary">Enregistrer</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if ($edit_op): ?><div class="modal-backdrop fade show"></div><?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
