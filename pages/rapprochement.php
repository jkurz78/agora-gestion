<?php
require_once __DIR__ . '/../includes/header.php';

$db = get_db();

// POST: toggle pointe flag
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $type = $_POST['type'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $allowed = [
        'depense'    => 'depenses',
        'recette'    => 'recettes',
        'don'        => 'dons',
        'cotisation' => 'cotisations',
    ];
    if (isset($allowed[$type]) && $id > 0) {
        $table = $allowed[$type];
        $db->prepare("UPDATE `$table` SET pointe = 1 - pointe WHERE id = ?")->execute([$id]);
    }
    // PRG: redirect back with same GET params
    header('Location: /accounting/pages/rapprochement.php?' . http_build_query($_GET));
    exit;
}

$compte_id = (int)($_GET['compte_id'] ?? 0);
$debut     = $_GET['debut'] ?? date('Y-m-01');
$fin       = $_GET['fin']   ?? date('Y-m-d');

// Sanitize dates (basic format check; invalid values fall back to defaults)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) {
    $debut = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
    $fin = date('Y-m-d');
}

$comptes = $db->query('SELECT * FROM comptes_bancaires ORDER BY nom')->fetchAll();

$compte       = null;
$transactions = [];
$solde_pointe = null;
$total_entrants_pointes  = 0.0;
$total_sortants_pointes  = 0.0;

if ($compte_id) {
    $stmt = $db->prepare('SELECT * FROM comptes_bancaires WHERE id = ?');
    $stmt->execute([$compte_id]);
    $compte = $stmt->fetch();

    if ($compte) {
        // UNION of all 4 transaction types filtered by compte and date range
        $sql = "
            SELECT 'depense' AS type, id, date, libelle AS label, montant_total AS montant, pointe, mode_paiement
            FROM depenses
            WHERE compte_id = ? AND date BETWEEN ? AND ?

            UNION ALL

            SELECT 'recette' AS type, id, date, libelle AS label, montant_total AS montant, pointe, mode_paiement
            FROM recettes
            WHERE compte_id = ? AND date BETWEEN ? AND ?

            UNION ALL

            SELECT 'cotisation' AS type, co.id, co.date_paiement AS date,
                   CONCAT(m.prenom, ' ', m.nom, ' - cotisation') AS label,
                   co.montant, co.pointe, co.mode_paiement
            FROM cotisations co
            JOIN membres m ON m.id = co.membre_id
            WHERE co.compte_id = ? AND co.date_paiement BETWEEN ? AND ?

            UNION ALL

            SELECT 'don' AS type, d.id, d.date,
                   COALESCE(CONCAT(do.prenom, ' ', do.nom), 'Anonyme') AS label,
                   d.montant, d.pointe, d.mode_paiement
            FROM dons d
            LEFT JOIN donateurs do ON do.id = d.donateur_id
            WHERE d.compte_id = ? AND d.date BETWEEN ? AND ?

            ORDER BY date ASC, type ASC
        ";
        $ts = $db->prepare($sql);
        $ts->execute([
            $compte_id, $debut, $fin,
            $compte_id, $debut, $fin,
            $compte_id, $debut, $fin,
            $compte_id, $debut, $fin,
        ]);
        $transactions = $ts->fetchAll();

        // Compute solde pointé — all time, not filtered by date range
        $solde_sql = "
            SELECT
                COALESCE((SELECT SUM(montant_total) FROM recettes      WHERE compte_id = ? AND pointe = 1), 0)
              + COALESCE((SELECT SUM(montant)        FROM dons          WHERE compte_id = ? AND pointe = 1), 0)
              + COALESCE((SELECT SUM(montant)        FROM cotisations   WHERE compte_id = ? AND pointe = 1), 0)
              - COALESCE((SELECT SUM(montant_total)  FROM depenses      WHERE compte_id = ? AND pointe = 1), 0)
              AS delta
        ";
        $ss = $db->prepare($solde_sql);
        $ss->execute([$compte_id, $compte_id, $compte_id, $compte_id]);
        $delta        = (float)$ss->fetchColumn();
        $solde_pointe = (float)$compte['solde_initial'] + $delta;

        // Also compute totals for the summary card (all time, pointé only)
        $totals_sql = "
            SELECT
                COALESCE((SELECT SUM(montant_total) FROM recettes    WHERE compte_id = ? AND pointe = 1), 0) AS total_recettes,
                COALESCE((SELECT SUM(montant)       FROM dons        WHERE compte_id = ? AND pointe = 1), 0) AS total_dons,
                COALESCE((SELECT SUM(montant)       FROM cotisations WHERE compte_id = ? AND pointe = 1), 0) AS total_cotisations,
                COALESCE((SELECT SUM(montant_total) FROM depenses    WHERE compte_id = ? AND pointe = 1), 0) AS total_depenses
        ";
        $totals_stmt = $db->prepare($totals_sql);
        $totals_stmt->execute([$compte_id, $compte_id, $compte_id, $compte_id]);
        $totals = $totals_stmt->fetch();
        $total_entrants_pointes = (float)$totals['total_recettes'] + (float)$totals['total_dons'] + (float)$totals['total_cotisations'];
        $total_sortants_pointes = (float)$totals['total_depenses'];
    }
}

// Helper: build current query string with an override
function query_with(array $override): string
{
    $params = array_merge($_GET, $override);
    return http_build_query($params);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2>Rapprochement bancaire</h2>
</div>

<!-- Account + period selector -->
<div class="card mb-4">
  <div class="card-body">
    <form method="get" action="/accounting/pages/rapprochement.php" class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label form-label-sm fw-semibold">Compte bancaire</label>
        <select name="compte_id" class="form-select form-select-sm" required>
          <option value="">— Sélectionner un compte —</option>
          <?php foreach ($comptes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $compte_id === (int)$c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label form-label-sm fw-semibold">Du</label>
        <input type="date" name="debut" class="form-control form-control-sm" value="<?= htmlspecialchars($debut) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label form-label-sm fw-semibold">Au</label>
        <input type="date" name="fin" class="form-control form-control-sm" value="<?= htmlspecialchars($fin) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">Afficher</button>
      </div>
    </form>
  </div>
</div>

<?php if ($compte_id && !$compte): ?>
  <div class="alert alert-danger">Compte introuvable.</div>
<?php elseif ($compte): ?>

<!-- Transactions table -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      Transactions — <strong><?= htmlspecialchars($compte['nom']) ?></strong>
      du <?= date('d/m/Y', strtotime($debut)) ?> au <?= date('d/m/Y', strtotime($fin)) ?>
    </span>
    <span class="text-muted small"><?= count($transactions) ?> opération(s)</span>
  </div>
  <div class="card-body p-0">
    <?php if ($transactions): ?>
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Libellé</th>
          <th>Mode</th>
          <th class="text-end">Montant</th>
          <th class="text-center">Pointé</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($transactions as $tx): ?>
        <?php
            $is_credit = in_array($tx['type'], ['recette', 'don']);
            $amount    = (float)$tx['montant'];
            $is_pointe = (bool)$tx['pointe'];

            $type_labels = [
                'depense'    => 'Dépense',
                'recette'    => 'Recette',
                'cotisation' => 'Cotisation',
                'don'        => 'Don',
            ];
            $type_label = $type_labels[$tx['type']] ?? htmlspecialchars($tx['type']);
        ?>
        <tr class="<?= $is_pointe ? 'table-light' : '' ?>">
          <td class="text-nowrap"><?= date('d/m/Y', strtotime($tx['date'])) ?></td>
          <td><span class="badge bg-<?= $is_credit ? 'success' : 'danger' ?> bg-opacity-75"><?= $type_label ?></span></td>
          <td><?= htmlspecialchars($tx['label']) ?></td>
          <td class="text-muted small"><?= htmlspecialchars($tx['mode_paiement'] ?? '') ?></td>
          <td class="text-end fw-semibold <?= $is_credit ? 'text-success' : 'text-danger' ?>">
            <?= $is_credit ? '+' : '-' ?><?= number_format($amount, 2, ',', ' ') ?> €
          </td>
          <td class="text-center">
            <?php if ($is_pointe): ?>
              <span class="text-success fw-bold" title="Pointé">&#10003;</span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <form method="post" action="/accounting/pages/rapprochement.php?<?= htmlspecialchars(query_with([])) ?>" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="type" value="<?= htmlspecialchars($tx['type']) ?>">
              <input type="hidden" name="id"   value="<?= (int)$tx['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $is_pointe ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                <?= $is_pointe ? 'Dépointer' : 'Pointer' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
      <p class="p-3 text-muted mb-0">Aucune opération sur cette période.</p>
    <?php endif; ?>
  </div>
</div>

<!-- Summary card -->
<div class="row justify-content-end">
  <div class="col-md-5 col-lg-4">
    <div class="card">
      <div class="card-header fw-semibold">Récapitulatif du rapprochement</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <tr>
              <td class="text-muted">Solde initial</td>
              <td class="text-end fw-semibold"><?= number_format((float)$compte['solde_initial'], 2, ',', ' ') ?> €</td>
            </tr>
            <tr>
              <td class="text-muted">+ Entrants pointés</td>
              <td class="text-end text-success fw-semibold">+ <?= number_format($total_entrants_pointes, 2, ',', ' ') ?> €</td>
            </tr>
            <tr>
              <td class="text-muted">- Sortants pointés</td>
              <td class="text-end text-danger fw-semibold">- <?= number_format($total_sortants_pointes, 2, ',', ' ') ?> €</td>
            </tr>
            <tr class="table-primary">
              <td class="fw-bold">Solde pointé</td>
              <td class="text-end fw-bold fs-5"><?= number_format($solde_pointe, 2, ',', ' ') ?> €</td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php if ($compte['date_solde_initial']): ?>
      <div class="card-footer text-muted small">
        Solde initial au <?= date('d/m/Y', strtotime($compte['date_solde_initial'])) ?>
        — IBAN : <?= htmlspecialchars($compte['iban'] ?? '—') ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
