<?php
require_once __DIR__ . '/../includes/header.php';

$db = get_db();

// ─── Exercice selector ───────────────────────────────────────────────────────
$cur_ex = current_exercice();
$ex     = isset($_GET['exercice']) ? (int)$_GET['exercice'] : $cur_ex;
$label  = exercice_label($ex);
$debut  = $ex . '-09-01';
$fin    = ($ex + 1) . '-08-31';

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_line') {
        $sc_id   = (int)$_POST['sous_categorie_id'];
        $montant = (float)$_POST['montant_prevu'];
        $notes   = trim($_POST['notes']) ?: null;
        $ex_post = (int)$_POST['exercice'];

        if (empty($_POST['line_id'])) {
            $db->prepare('INSERT INTO budget_lines (sous_categorie_id, exercice, montant_prevu, notes) VALUES (?,?,?,?)')
               ->execute([$sc_id, $ex_post, $montant, $notes]);
            flash('success', 'Ligne budgétaire ajoutée.');
        } else {
            $db->prepare('UPDATE budget_lines SET sous_categorie_id=?, montant_prevu=?, notes=? WHERE id=?')
               ->execute([$sc_id, $montant, $notes, (int)$_POST['line_id']]);
            flash('success', 'Ligne budgétaire mise à jour.');
        }
        header('Location: /accounting/pages/budget.php?exercice=' . $ex_post); exit;
    }

    if ($action === 'delete_line') {
        $db->prepare('DELETE FROM budget_lines WHERE id=?')->execute([(int)$_POST['line_id']]);
        flash('success', 'Ligne supprimée.');
        header('Location: /accounting/pages/budget.php?exercice=' . $ex); exit;
    }
}

// ─── Load sous-categories for the add form ───────────────────────────────────
$all_sc = $db->query('
    SELECT sc.id, sc.nom, sc.code_cerfa, c.nom AS cat_nom, c.type
    FROM sous_categories sc
    JOIN categories c ON c.id = sc.categorie_id
    ORDER BY c.type, c.nom, sc.nom
')->fetchAll();

// ─── Load budget lines with realised amounts ─────────────────────────────────
$lines = $db->prepare('
    SELECT
        bl.id, bl.montant_prevu, bl.notes,
        sc.id AS sc_id, sc.nom AS sc_nom, sc.code_cerfa,
        c.id  AS cat_id, c.nom AS cat_nom, c.type,
        COALESCE((
            SELECT SUM(dl.montant)
            FROM depense_lignes dl
            JOIN depenses d ON d.id = dl.depense_id
            WHERE dl.sous_categorie_id = sc.id
              AND d.date BETWEEN ? AND ?
        ), 0) AS realise_dep,
        COALESCE((
            SELECT SUM(rl.montant)
            FROM recette_lignes rl
            JOIN recettes r ON r.id = rl.recette_id
            WHERE rl.sous_categorie_id = sc.id
              AND r.date BETWEEN ? AND ?
        ), 0) AS realise_rec
    FROM budget_lines bl
    JOIN sous_categories sc ON sc.id = bl.sous_categorie_id
    JOIN categories c ON c.id = sc.categorie_id
    WHERE bl.exercice = ?
    ORDER BY c.type DESC, c.nom, sc.nom
');
$lines->execute([$debut, $fin, $debut, $fin, $ex]);
$budget_lines = $lines->fetchAll();

// Separate by type
$lines_dep = array_filter($budget_lines, fn($l) => $l['type'] === 'depense');
$lines_rec = array_filter($budget_lines, fn($l) => $l['type'] === 'recette');

$total_prevu_dep  = array_sum(array_column($lines_dep, 'montant_prevu'));
$total_reel_dep   = array_sum(array_column($lines_dep, 'realise_dep'));
$total_prevu_rec  = array_sum(array_column($lines_rec, 'montant_prevu'));
$total_reel_rec   = array_sum(array_column($lines_rec, 'realise_rec'));

function fmt(float $v): string {
    return number_format($v, 2, ',', ' ') . ' €';
}
function ecart_class(float $ecart, string $type): string {
    if ($type === 'depense') return $ecart <= 0 ? 'text-success' : 'text-danger';
    return $ecart >= 0 ? 'text-success' : 'text-danger';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="mb-0">Budget prévisionnel</h2>
  <!-- Exercice nav -->
  <div class="d-flex align-items-center gap-2">
    <a href="?exercice=<?= $ex - 1 ?>" class="btn btn-sm btn-outline-secondary">← <?= exercice_label($ex - 1) ?></a>
    <span class="badge bg-primary fs-6"><?= htmlspecialchars($label) ?></span>
    <a href="?exercice=<?= $ex + 1 ?>" class="btn btn-sm btn-outline-secondary"><?= exercice_label($ex + 1) ?> →</a>
  </div>
</div>

<!-- Add line form -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold">Ajouter une ligne budgétaire</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action"     value="save_line">
      <input type="hidden" name="exercice"   value="<?= $ex ?>">
      <div class="col-md-5">
        <label class="form-label">Sous-catégorie</label>
        <select name="sous_categorie_id" class="form-select" required>
          <option value="">— Choisir —</option>
          <?php
          $current_type = null;
          foreach ($all_sc as $sc):
              if ($sc['type'] !== $current_type) {
                  if ($current_type !== null) echo '</optgroup>';
                  echo '<optgroup label="' . htmlspecialchars(ucfirst($sc['type'] === 'depense' ? 'Dépenses' : 'Recettes')) . '">';
                  $current_type = $sc['type'];
              }
          ?>
            <option value="<?= (int)$sc['id'] ?>">
              <?= htmlspecialchars($sc['cat_nom'] . ' / ' . $sc['nom']) ?>
              <?= $sc['code_cerfa'] ? ' [' . htmlspecialchars($sc['code_cerfa']) . ']' : '' ?>
            </option>
          <?php endforeach; ?>
          <?php if ($current_type !== null) echo '</optgroup>'; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Montant prévu (€)</label>
        <input type="number" name="montant_prevu" class="form-control" step="0.01" min="0" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" class="form-control" maxlength="255">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100">Ajouter</button>
      </div>
    </form>
  </div>
</div>

<!-- Budget table -->
<?php foreach ([['depense', 'Dépenses', $lines_dep, $total_prevu_dep, $total_reel_dep], ['recette', 'Recettes', $lines_rec, $total_prevu_rec, $total_reel_rec]] as [$type, $type_label, $type_lines, $tp, $tr]): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex justify-content-between">
    <span><?= $type_label ?></span>
    <span class="text-muted small">Prévu : <?= fmt($tp) ?> · Réalisé : <?= fmt($tr) ?> · Écart : <strong class="<?= ecart_class($tr - $tp, $type) ?>"><?= fmt($tr - $tp) ?></strong></span>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr><th>Catégorie</th><th>Sous-catégorie</th><th>Code CERFA</th><th class="text-end">Prévu</th><th class="text-end">Réalisé</th><th class="text-end">Écart</th><th>Notes</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($type_lines as $l):
          $realise = $type === 'depense' ? (float)$l['realise_dep'] : (float)$l['realise_rec'];
          $ecart   = $realise - (float)$l['montant_prevu'];
      ?>
        <tr>
          <td class="small"><?= htmlspecialchars($l['cat_nom']) ?></td>
          <td><?= htmlspecialchars($l['sc_nom']) ?></td>
          <td><?php if ($l['code_cerfa']): ?><span class="badge bg-info text-dark"><?= htmlspecialchars($l['code_cerfa']) ?></span><?php endif; ?></td>
          <td class="text-end"><?= fmt((float)$l['montant_prevu']) ?></td>
          <td class="text-end"><?= fmt($realise) ?></td>
          <td class="text-end <?= ecart_class($ecart, $type) ?>"><?= fmt($ecart) ?></td>
          <td class="small text-muted"><?= htmlspecialchars($l['notes'] ?? '') ?></td>
          <td class="text-end">
            <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette ligne ?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action"    value="delete_line">
              <input type="hidden" name="line_id"   value="<?= (int)$l['id'] ?>">
              <input type="hidden" name="exercice"  value="<?= $ex ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$type_lines): ?>
        <tr><td colspan="8" class="text-muted text-center py-2 small">Aucune ligne budgétaire.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
