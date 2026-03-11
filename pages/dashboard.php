<?php
require_once __DIR__ . '/../includes/header.php';

$db    = get_db();
$ex    = current_exercice();
$label = exercice_label($ex);
$debut = $ex . '-09-01';
$fin   = ($ex + 1) . '-08-31';

// Total dépenses
$stmt = $db->prepare('SELECT COALESCE(SUM(montant_total), 0) FROM depenses WHERE date BETWEEN ? AND ?');
$stmt->execute([$debut, $fin]);
$total_depenses = (float) $stmt->fetchColumn();

// Total recettes (header amounts)
$stmt = $db->prepare('SELECT COALESCE(SUM(montant_total), 0) FROM recettes WHERE date BETWEEN ? AND ?');
$stmt->execute([$debut, $fin]);
$total_recettes = (float) $stmt->fetchColumn();

// Total dons
$stmt = $db->prepare('SELECT COALESCE(SUM(montant), 0) FROM dons WHERE date BETWEEN ? AND ?');
$stmt->execute([$debut, $fin]);
$total_dons = (float) $stmt->fetchColumn();

// Total cotisations for exercice
$stmt = $db->prepare('SELECT COALESCE(SUM(montant), 0) FROM cotisations WHERE exercice = ?');
$stmt->execute([$ex]);
$total_cotisations = (float) $stmt->fetchColumn();

$total_entrees = $total_recettes + $total_dons + $total_cotisations;
$solde         = $total_entrees - $total_depenses;

// Last 5 dépenses
$stmt = $db->prepare('
    SELECT d.date, d.libelle, d.montant_total, u.nom AS saisi_nom
    FROM depenses d
    JOIN users u ON u.id = d.saisi_par
    ORDER BY d.date DESC, d.created_at DESC
    LIMIT 5
');
$stmt->execute();
$dernieres_depenses = $stmt->fetchAll();

// Last 5 recettes
$stmt = $db->prepare('
    SELECT r.date, r.libelle, r.montant_total, u.nom AS saisi_nom
    FROM recettes r
    JOIN users u ON u.id = r.saisi_par
    ORDER BY r.date DESC, r.created_at DESC
    LIMIT 5
');
$stmt->execute();
$dernieres_recettes = $stmt->fetchAll();

// Active members without cotisation for current exercice
$stmt = $db->prepare('
    SELECT m.id, m.nom, m.prenom
    FROM membres m
    WHERE m.statut = "actif"
      AND m.id NOT IN (
          SELECT c.membre_id FROM cotisations c WHERE c.exercice = ?
      )
    ORDER BY m.nom, m.prenom
    LIMIT 10
');
$stmt->execute([$ex]);
$membres_en_attente = $stmt->fetchAll();

// Helper to format money
function fmt(float $v): string {
    return number_format($v, 2, ',', ' ') . ' €';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="mb-0">Tableau de bord</h2>
  <span class="badge bg-secondary fs-6">Exercice <?= htmlspecialchars($label) ?></span>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Solde général</div>
        <div class="fs-4 fw-bold <?= $solde >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= fmt($solde) ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Total entrées</div>
        <div class="fs-4 fw-bold text-success"><?= fmt($total_entrees) ?></div>
        <div class="text-muted small">dont <?= fmt($total_dons) ?> de dons · <?= fmt($total_cotisations) ?> de cotisations</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Total dépenses</div>
        <div class="fs-4 fw-bold text-danger"><?= fmt($total_depenses) ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small mb-1">Dons reçus</div>
        <div class="fs-4 fw-bold text-primary"><?= fmt($total_dons) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Recent activity + members -->
<div class="row g-4">
  <!-- Last depenses -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Dernières dépenses</span>
        <a href="/accounting/pages/depenses.php" class="btn btn-sm btn-outline-secondary">Voir tout</a>
      </div>
      <div class="card-body p-0">
        <?php if ($dernieres_depenses): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($dernieres_depenses as $d): ?>
          <li class="list-group-item d-flex justify-content-between align-items-start py-2">
            <div>
              <div class="small fw-medium"><?= htmlspecialchars($d['libelle']) ?></div>
              <div class="text-muted" style="font-size:.75rem"><?= date('d/m/Y', strtotime($d['date'])) ?></div>
            </div>
            <span class="badge bg-danger-subtle text-danger rounded-pill ms-2"><?= fmt((float)$d['montant_total']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
          <p class="text-muted small p-3 mb-0">Aucune dépense enregistrée.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Last recettes -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Dernières recettes</span>
        <a href="/accounting/pages/recettes.php" class="btn btn-sm btn-outline-secondary">Voir tout</a>
      </div>
      <div class="card-body p-0">
        <?php if ($dernieres_recettes): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($dernieres_recettes as $r): ?>
          <li class="list-group-item d-flex justify-content-between align-items-start py-2">
            <div>
              <div class="small fw-medium"><?= htmlspecialchars($r['libelle']) ?></div>
              <div class="text-muted" style="font-size:.75rem"><?= date('d/m/Y', strtotime($r['date'])) ?></div>
            </div>
            <span class="badge bg-success-subtle text-success rounded-pill ms-2"><?= fmt((float)$r['montant_total']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
          <p class="text-muted small p-3 mb-0">Aucune recette enregistrée.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Members without cotisation -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Cotisations en attente</span>
        <span class="badge bg-warning text-dark"><?= count($membres_en_attente) ?></span>
      </div>
      <div class="card-body p-0">
        <?php if ($membres_en_attente): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($membres_en_attente as $m): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span class="small"><?= htmlspecialchars($m['prenom'] . ' ' . $m['nom']) ?></span>
            <a href="/accounting/pages/membres.php?id=<?= (int)$m['id'] ?>" class="btn btn-sm btn-outline-primary">Saisir</a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
          <p class="text-success small p-3 mb-0">✓ Tous les membres actifs ont cotisé.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
