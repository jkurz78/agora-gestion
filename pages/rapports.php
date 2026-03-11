<?php
require_once __DIR__ . '/../includes/auth.php';
require_auth();

$db = get_db();

$ex     = isset($_GET['exercice']) ? (int)$_GET['exercice'] : current_exercice();
$op_ids = array_filter(array_map('intval', (array)($_GET['operations'] ?? [])));

$date_debut = $ex . '-09-01';
$date_fin   = ($ex + 1) . '-08-31';

// ─── Operation filter SQL fragments ──────────────────────────────────────────
$op_filter_dep = '';
$op_filter_rec = '';
$op_params_dep = [];
$op_params_rec = [];
if ($op_ids) {
    $placeholders  = implode(',', array_fill(0, count($op_ids), '?'));
    $op_filter_dep = " AND dl.operation_id IN ($placeholders)";
    $op_filter_rec = " AND rl.operation_id IN ($placeholders)";
    $op_params_dep = array_values($op_ids);
    $op_params_rec = array_values($op_ids);
}

// ─── Charges query ────────────────────────────────────────────────────────────
$charges_stmt = $db->prepare("
    SELECT sc.code_cerfa, sc.nom as sous_cat, c.nom as cat, SUM(dl.montant) as total
    FROM depense_lignes dl
    JOIN depenses d ON d.id = dl.depense_id
    JOIN sous_categories sc ON sc.id = dl.sous_categorie_id
    JOIN categories c ON c.id = sc.categorie_id
    WHERE d.date BETWEEN ? AND ?
    $op_filter_dep
    GROUP BY sc.id, sc.code_cerfa, sc.nom, c.nom
    ORDER BY sc.code_cerfa, c.nom, sc.nom
");
$charges_stmt->execute(array_merge([$date_debut, $date_fin], $op_params_dep));
$charges = $charges_stmt->fetchAll();

// ─── Produits query ───────────────────────────────────────────────────────────
$produits_stmt = $db->prepare("
    SELECT sc.code_cerfa, sc.nom as sous_cat, c.nom as cat, SUM(rl.montant) as total
    FROM recette_lignes rl
    JOIN recettes r ON r.id = rl.recette_id
    JOIN sous_categories sc ON sc.id = rl.sous_categorie_id
    JOIN categories c ON c.id = sc.categorie_id
    WHERE r.date BETWEEN ? AND ?
    $op_filter_rec
    GROUP BY sc.id, sc.code_cerfa, sc.nom, c.nom
    ORDER BY sc.code_cerfa, c.nom, sc.nom
");
$produits_stmt->execute(array_merge([$date_debut, $date_fin], $op_params_rec));
$produits = $produits_stmt->fetchAll();

$total_charges = array_sum(array_column($charges, 'total'));
$total_produits = array_sum(array_column($produits, 'total'));
$resultat = $total_produits - $total_charges;

// ─── CSV export ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compte-resultat-' . exercice_label($ex) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Type', 'Code CERFA', 'Catégorie', 'Sous-catégorie', 'Montant'], ';');
    foreach ($charges as $row) {
        fputcsv($out, ['Charge', $row['code_cerfa'] ?? '', $row['cat'], $row['sous_cat'], number_format((float)$row['total'], 2, '.', '')], ';');
    }
    foreach ($produits as $row) {
        fputcsv($out, ['Produit', $row['code_cerfa'] ?? '', $row['cat'], $row['sous_cat'], number_format((float)$row['total'], 2, '.', '')], ';');
    }
    fclose($out);
    exit;
}

// ─── Load operations list for filter ─────────────────────────────────────────
$all_operations = $db->query('SELECT id, nom FROM operations ORDER BY nom')->fetchAll();

// ─── Pivot: Rapport par séances ───────────────────────────────────────────────
$ops_avec_seances = $db->query(
    'SELECT id, nom, nombre_seances FROM operations WHERE nombre_seances IS NOT NULL ORDER BY nom'
)->fetchAll();

$op_seances_id = (int)($_GET['op_seances'] ?? 0);
$op_seances    = null;
$pivot_charges = [];
$pivot_produits = [];

if ($op_seances_id) {
    $stmt = $db->prepare('SELECT * FROM operations WHERE id = ? AND nombre_seances IS NOT NULL');
    $stmt->execute([$op_seances_id]);
    $op_seances = $stmt->fetch();

    if ($op_seances) {
        $n = (int)$op_seances['nombre_seances'];

        // Charges per sous_categorie per seance
        $stmt = $db->prepare('
            SELECT sc.nom as sous_cat, c.nom as cat, dl.seance, SUM(dl.montant) as total
            FROM depense_lignes dl
            JOIN sous_categories sc ON sc.id = dl.sous_categorie_id
            JOIN categories c ON c.id = sc.categorie_id
            WHERE dl.operation_id = ? AND dl.seance IS NOT NULL
            GROUP BY sc.id, dl.seance
            ORDER BY c.nom, sc.nom, dl.seance
        ');
        $stmt->execute([$op_seances_id]);
        foreach ($stmt->fetchAll() as $row) {
            $pivot_charges[$row['cat']][$row['sous_cat']][$row['seance']] = (float)$row['total'];
        }

        // Produits per sous_categorie per seance
        $stmt = $db->prepare('
            SELECT sc.nom as sous_cat, c.nom as cat, rl.seance, SUM(rl.montant) as total
            FROM recette_lignes rl
            JOIN sous_categories sc ON sc.id = rl.sous_categorie_id
            JOIN categories c ON c.id = sc.categorie_id
            WHERE rl.operation_id = ? AND rl.seance IS NOT NULL
            GROUP BY sc.id, rl.seance
            ORDER BY c.nom, sc.nom, rl.seance
        ');
        $stmt->execute([$op_seances_id]);
        foreach ($stmt->fetchAll() as $row) {
            $pivot_produits[$row['cat']][$row['sous_cat']][$row['seance']] = (float)$row['total'];
        }
    }
}

$label      = exercice_label($ex);
$page_title = 'Rapports';
require_once __DIR__ . '/../includes/header.php';

// ─── Helper functions ─────────────────────────────────────────────────────────
function fmt_r(float $v): string {
    return number_format($v, 2, ',', ' ') . ' €';
}

/**
 * Group rows by code_cerfa, returning an array of groups.
 * Each group: ['code_cerfa' => string|null, 'rows' => [...], 'subtotal' => float]
 */
function group_by_cerfa(array $rows): array {
    $groups = [];
    foreach ($rows as $row) {
        $key = $row['code_cerfa'] ?? '';
        if (!isset($groups[$key])) {
            $groups[$key] = ['code_cerfa' => $row['code_cerfa'] ?? null, 'rows' => [], 'subtotal' => 0.0];
        }
        $groups[$key]['rows'][]    = $row;
        $groups[$key]['subtotal'] += (float)$row['total'];
    }
    return array_values($groups);
}

$charges_groups = group_by_cerfa($charges);
$produits_groups = group_by_cerfa($produits);

// Build URL preserving current filters (for exercice navigation)
function nav_url(int $exercice, array $op_ids): string {
    $params = ['exercice' => $exercice];
    if ($op_ids) {
        $params['operations'] = $op_ids;
    }
    return '?' . http_build_query($params);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2 class="mb-0">Rapports</h2>
  <!-- Exercice nav -->
  <div class="d-flex align-items-center gap-2">
    <a href="<?= htmlspecialchars(nav_url($ex - 1, $op_ids)) ?>" class="btn btn-sm btn-outline-secondary">← <?= exercice_label($ex - 1) ?></a>
    <span class="badge bg-primary fs-6"><?= htmlspecialchars($label) ?></span>
    <a href="<?= htmlspecialchars(nav_url($ex + 1, $op_ids)) ?>" class="btn btn-sm btn-outline-secondary"><?= exercice_label($ex + 1) ?> →</a>
  </div>
</div>

<!-- Bootstrap tabs -->
<ul class="nav nav-tabs mb-4" id="rapportsTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="compte-resultat-tab" data-bs-toggle="tab" data-bs-target="#compte-resultat" type="button" role="tab" aria-controls="compte-resultat" aria-selected="true">
      Compte de résultat
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="par-seances-tab" data-bs-toggle="tab" data-bs-target="#par-seances" type="button" role="tab" aria-controls="par-seances" aria-selected="false">
      Rapport par séances
    </button>
  </li>
</ul>

<div class="tab-content" id="rapportsTabsContent">

  <!-- ═══ Tab 1 : Compte de résultat ══════════════════════════════════════════ -->
  <div class="tab-pane fade show active" id="compte-resultat" role="tabpanel" aria-labelledby="compte-resultat-tab">

    <!-- Filters form -->
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold">Filtres</div>
      <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
          <input type="hidden" name="exercice" value="<?= $ex ?>">

          <div class="col-md-5">
            <label class="form-label">Opérations (laisser vide = toutes)</label>
            <select name="operations[]" class="form-select" multiple size="5">
              <?php foreach ($all_operations as $op): ?>
                <option value="<?= (int)$op['id'] ?>" <?= in_array((int)$op['id'], $op_ids, true) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($op['nom']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4 d-flex gap-2 align-items-end">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-funnel"></i> Filtrer
            </button>
            <?php if ($op_ids): ?>
              <a href="?exercice=<?= $ex ?>" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i> Réinitialiser
              </a>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(nav_url($ex, $op_ids) . '&export=csv') ?>" class="btn btn-outline-success">
              <i class="bi bi-download"></i> Exporter CSV
            </a>
          </div>
        </form>
      </div>
    </div>

    <!-- Two-column layout: Charges (left) | Produits (right) -->
    <div class="row g-4 mb-4">

      <!-- ── Charges ───────────────────────────────────────────────────────── -->
      <div class="col-md-6">
        <div class="card shadow-sm h-100">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span>Charges</span>
            <span class="badge bg-danger fs-6"><?= fmt_r($total_charges) ?></span>
          </div>
          <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Code CERFA</th>
                  <th>Catégorie / Sous-catégorie</th>
                  <th class="text-end">Montant</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($charges_groups): ?>
                  <?php foreach ($charges_groups as $group): ?>
                    <?php foreach ($group['rows'] as $row): ?>
                      <tr>
                        <td class="text-muted small">
                          <?php if ($row['code_cerfa']): ?>
                            <span class="badge bg-info text-dark"><?= htmlspecialchars($row['code_cerfa']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="small">
                          <span class="text-muted"><?= htmlspecialchars($row['cat']) ?></span>
                          / <?= htmlspecialchars($row['sous_cat']) ?>
                        </td>
                        <td class="text-end"><?= fmt_r((float)$row['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (count($group['rows']) > 1 || $group['code_cerfa']): ?>
                      <tr class="table-secondary fw-semibold">
                        <td colspan="2" class="small">
                          Sous-total<?= $group['code_cerfa'] ? ' ' . htmlspecialchars($group['code_cerfa']) : '' ?>
                        </td>
                        <td class="text-end"><?= fmt_r($group['subtotal']) ?></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="3" class="text-muted text-center py-3 small">Aucune charge pour cet exercice.</td></tr>
                <?php endif; ?>
              </tbody>
              <tfoot class="table-danger fw-bold">
                <tr>
                  <td colspan="2">Total charges</td>
                  <td class="text-end"><?= fmt_r($total_charges) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      <!-- ── Produits ──────────────────────────────────────────────────────── -->
      <div class="col-md-6">
        <div class="card shadow-sm h-100">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span>Produits</span>
            <span class="badge bg-success fs-6"><?= fmt_r($total_produits) ?></span>
          </div>
          <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Code CERFA</th>
                  <th>Catégorie / Sous-catégorie</th>
                  <th class="text-end">Montant</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($produits_groups): ?>
                  <?php foreach ($produits_groups as $group): ?>
                    <?php foreach ($group['rows'] as $row): ?>
                      <tr>
                        <td class="text-muted small">
                          <?php if ($row['code_cerfa']): ?>
                            <span class="badge bg-info text-dark"><?= htmlspecialchars($row['code_cerfa']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="small">
                          <span class="text-muted"><?= htmlspecialchars($row['cat']) ?></span>
                          / <?= htmlspecialchars($row['sous_cat']) ?>
                        </td>
                        <td class="text-end"><?= fmt_r((float)$row['total']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (count($group['rows']) > 1 || $group['code_cerfa']): ?>
                      <tr class="table-secondary fw-semibold">
                        <td colspan="2" class="small">
                          Sous-total<?= $group['code_cerfa'] ? ' ' . htmlspecialchars($group['code_cerfa']) : '' ?>
                        </td>
                        <td class="text-end"><?= fmt_r($group['subtotal']) ?></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr><td colspan="3" class="text-muted text-center py-3 small">Aucun produit pour cet exercice.</td></tr>
                <?php endif; ?>
              </tbody>
              <tfoot class="table-success fw-bold">
                <tr>
                  <td colspan="2">Total produits</td>
                  <td class="text-end"><?= fmt_r($total_produits) ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

    </div><!-- /row -->

    <!-- Résultat net -->
    <div class="card shadow-sm">
      <div class="card-body d-flex justify-content-between align-items-center py-3">
        <span class="fw-bold fs-5">
          Résultat net (<?= $resultat >= 0 ? 'Excédent' : 'Déficit' ?>)
        </span>
        <span class="fw-bold fs-4 <?= $resultat >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= fmt_r(abs($resultat)) ?>
          <?= $resultat >= 0 ? '<i class="bi bi-arrow-up-circle"></i>' : '<i class="bi bi-arrow-down-circle"></i>' ?>
        </span>
      </div>
    </div>

  </div><!-- /tab-pane compte-resultat -->

  <!-- ═══ Tab 2 : Rapport par séances ═════════════════════════════════════════ -->
  <div class="tab-pane fade" id="par-seances" role="tabpanel" aria-labelledby="par-seances-tab">

    <!-- Filter form -->
    <div class="card shadow-sm mb-4">
      <div class="card-header fw-semibold">Filtres</div>
      <div class="card-body">
        <form method="get" id="form-seances" class="row g-3 align-items-end">
          <!-- Preserve exercice and main tab param so switching back works -->
          <input type="hidden" name="exercice" value="<?= $ex ?>">

          <div class="col-md-5">
            <label class="form-label" for="op_seances_select">Opération</label>
            <select name="op_seances" id="op_seances_select" class="form-select">
              <option value="">— Choisir une opération —</option>
              <?php foreach ($ops_avec_seances as $op): ?>
                <option value="<?= (int)$op['id'] ?>"
                  <?= $op_seances_id === (int)$op['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($op['nom']) ?>
                  (<?= (int)$op['nombre_seances'] ?> séances)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-auto d-flex gap-2 align-items-end">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-funnel"></i> Afficher
            </button>
            <?php if ($op_seances_id): ?>
              <a href="?exercice=<?= $ex ?>&op_seances=" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i> Réinitialiser
              </a>
            <?php endif; ?>
          </div>
        </form>
        <script>
          document.getElementById('op_seances_select').addEventListener('change', function () {
            document.getElementById('form-seances').submit();
          });
        </script>
      </div>
    </div>

    <?php if (!$ops_avec_seances): ?>
      <div class="alert alert-info">Aucune opération avec séances n'est définie.</div>
    <?php elseif (!$op_seances_id): ?>
      <div class="alert alert-secondary">Veuillez sélectionner une opération pour afficher le rapport.</div>
    <?php elseif (!$op_seances): ?>
      <div class="alert alert-warning">Opération introuvable ou sans séances.</div>
    <?php else: ?>
      <?php
        $n = (int)$op_seances['nombre_seances'];
        // colspan = 2 (cat + sous-cat) + N séances + 1 total
        $colspan_total = $n + 3;
      ?>

      <h5 class="mb-3">
        <?= htmlspecialchars($op_seances['nom']) ?>
        <span class="text-muted fs-6">(<?= $n ?> séances)</span>
      </h5>

      <div class="card shadow-sm">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-light">
                <tr>
                  <th>Catégorie</th>
                  <th>Sous-catégorie</th>
                  <?php for ($s = 1; $s <= $n; $s++): ?>
                    <th class="text-end">Séance <?= $s ?></th>
                  <?php endfor; ?>
                  <th class="text-end fw-bold">Total</th>
                </tr>
              </thead>
              <tbody>

                <?php
                  // Pre-compute per-séance totals for charges and produits (needed for footer)
                  $seance_total_charges = [];
                  $seance_total_produits = [];
                  for ($s = 1; $s <= $n; $s++) {
                      $seance_total_charges[$s] = 0.0;
                      $seance_total_produits[$s] = 0.0;
                  }
                  $grand_total_charges  = 0.0;
                  $grand_total_produits = 0.0;
                ?>

                <!-- ── Charges section ───────────────────────────────────── -->
                <?php if ($pivot_charges): ?>
                  <tr class="table-danger">
                    <th colspan="<?= $colspan_total ?>">Charges</th>
                  </tr>
                  <?php foreach ($pivot_charges as $cat => $sous_cats): ?>
                    <?php foreach ($sous_cats as $sous_cat => $seances): ?>
                      <?php
                        $row_total = 0.0;
                        for ($s = 1; $s <= $n; $s++) {
                            $val = $seances[$s] ?? 0.0;
                            $row_total += $val;
                            $seance_total_charges[$s] += $val;
                        }
                        $grand_total_charges += $row_total;
                      ?>
                      <tr>
                        <td><?= htmlspecialchars($cat) ?></td>
                        <td><?= htmlspecialchars($sous_cat) ?></td>
                        <?php for ($s = 1; $s <= $n; $s++): ?>
                          <td class="text-end <?= isset($seances[$s]) ? 'text-danger' : 'text-muted' ?>">
                            <?= isset($seances[$s]) ? number_format($seances[$s], 2, ',', ' ') . ' €' : '—' ?>
                          </td>
                        <?php endfor; ?>
                        <td class="text-end fw-bold text-danger">
                          <?= number_format($row_total, 2, ',', ' ') ?> €
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  <!-- Total charges row -->
                  <tr class="table-light fw-bold">
                    <td colspan="2">Total charges</td>
                    <?php for ($s = 1; $s <= $n; $s++): ?>
                      <td class="text-end text-danger">
                        <?= $seance_total_charges[$s] > 0 ? number_format($seance_total_charges[$s], 2, ',', ' ') . ' €' : '—' ?>
                      </td>
                    <?php endfor; ?>
                    <td class="text-end text-danger">
                      <?= number_format($grand_total_charges, 2, ',', ' ') ?> €
                    </td>
                  </tr>
                <?php else: ?>
                  <tr class="table-danger">
                    <th colspan="<?= $colspan_total ?>">Charges</th>
                  </tr>
                  <tr>
                    <td colspan="<?= $colspan_total ?>" class="text-muted text-center py-2 small">
                      Aucune charge par séance pour cette opération.
                    </td>
                  </tr>
                <?php endif; ?>

                <!-- ── Produits section ──────────────────────────────────── -->
                <?php if ($pivot_produits): ?>
                  <tr class="table-success">
                    <th colspan="<?= $colspan_total ?>">Produits</th>
                  </tr>
                  <?php foreach ($pivot_produits as $cat => $sous_cats): ?>
                    <?php foreach ($sous_cats as $sous_cat => $seances): ?>
                      <?php
                        $row_total = 0.0;
                        for ($s = 1; $s <= $n; $s++) {
                            $val = $seances[$s] ?? 0.0;
                            $row_total += $val;
                            $seance_total_produits[$s] += $val;
                        }
                        $grand_total_produits += $row_total;
                      ?>
                      <tr>
                        <td><?= htmlspecialchars($cat) ?></td>
                        <td><?= htmlspecialchars($sous_cat) ?></td>
                        <?php for ($s = 1; $s <= $n; $s++): ?>
                          <td class="text-end <?= isset($seances[$s]) ? 'text-success' : 'text-muted' ?>">
                            <?= isset($seances[$s]) ? number_format($seances[$s], 2, ',', ' ') . ' €' : '—' ?>
                          </td>
                        <?php endfor; ?>
                        <td class="text-end fw-bold text-success">
                          <?= number_format($row_total, 2, ',', ' ') ?> €
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  <!-- Total produits row -->
                  <tr class="table-light fw-bold">
                    <td colspan="2">Total produits</td>
                    <?php for ($s = 1; $s <= $n; $s++): ?>
                      <td class="text-end text-success">
                        <?= $seance_total_produits[$s] > 0 ? number_format($seance_total_produits[$s], 2, ',', ' ') . ' €' : '—' ?>
                      </td>
                    <?php endfor; ?>
                    <td class="text-end text-success">
                      <?= number_format($grand_total_produits, 2, ',', ' ') ?> €
                    </td>
                  </tr>
                <?php else: ?>
                  <tr class="table-success">
                    <th colspan="<?= $colspan_total ?>">Produits</th>
                  </tr>
                  <tr>
                    <td colspan="<?= $colspan_total ?>" class="text-muted text-center py-2 small">
                      Aucun produit par séance pour cette opération.
                    </td>
                  </tr>
                <?php endif; ?>

                <!-- ── Résultat par séance ───────────────────────────────── -->
                <tr class="table-primary fw-bold">
                  <td colspan="2">Résultat par séance</td>
                  <?php
                    $grand_resultat = 0.0;
                    for ($s = 1; $s <= $n; $s++):
                      $res = $seance_total_produits[$s] - $seance_total_charges[$s];
                      $grand_resultat += $res;
                  ?>
                    <td class="text-end <?= $res >= 0 ? 'text-success' : 'text-danger' ?>">
                      <?= number_format($res, 2, ',', ' ') ?> €
                    </td>
                  <?php endfor; ?>
                  <td class="text-end <?= $grand_resultat >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= number_format($grand_resultat, 2, ',', ' ') ?> €
                  </td>
                </tr>

              </tbody>
            </table>
          </div>
        </div>
      </div>

    <?php endif; ?>

  </div>

</div><!-- /tab-content -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
