<?php
require_once __DIR__ . '/../includes/header.php';

$db = get_db();

$ex = current_exercice();
$ex_label = exercice_label($ex);

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // Save member (add or edit)
    if ($action === 'save_membre') {
        $statut = in_array($_POST['statut'], ['actif', 'inactif']) ? $_POST['statut'] : 'actif';
        $data = [
            trim($_POST['nom']),
            trim($_POST['prenom']),
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['telephone'] ?? '') ?: null,
            trim($_POST['adresse'] ?? '') ?: null,
            $_POST['date_adhesion'] ?: null,
            $statut,
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if (empty($_POST['membre_id'])) {
            $db->prepare('INSERT INTO membres (nom,prenom,email,telephone,adresse,date_adhesion,statut,notes) VALUES (?,?,?,?,?,?,?,?)')->execute($data);
            $new_id = (int)$db->lastInsertId();
            flash('success', 'Membre ajouté.');
            header('Location: /accounting/pages/membres.php?id=' . $new_id); exit;
        } else {
            $data[] = (int)$_POST['membre_id'];
            $db->prepare('UPDATE membres SET nom=?,prenom=?,email=?,telephone=?,adresse=?,date_adhesion=?,statut=?,notes=? WHERE id=?')->execute($data);
            flash('success', 'Membre mis à jour.');
            header('Location: /accounting/pages/membres.php?id=' . (int)$_POST['membre_id']); exit;
        }
    }

    // Add cotisation
    if ($action === 'save_cotisation') {
        $stmt = $db->prepare('INSERT INTO cotisations (membre_id,exercice,montant,date_paiement,mode_paiement,compte_id) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            (int)$_POST['membre_id'],
            (int)$_POST['exercice'],
            (float)$_POST['montant'],
            $_POST['date_paiement'],
            $_POST['mode_paiement'],
            $_POST['compte_id'] ?: null,
        ]);
        flash('success', 'Cotisation enregistrée.');
        header('Location: /accounting/pages/membres.php?id=' . (int)$_POST['membre_id']); exit;
    }

    // Delete cotisation
    if ($action === 'delete_cotisation') {
        $cot_stmt = $db->prepare('SELECT membre_id FROM cotisations WHERE id=?');
        $cot_stmt->execute([(int)$_POST['cotisation_id']]);
        $cot = $cot_stmt->fetch();
        if ($cot) {
            $db->prepare('DELETE FROM cotisations WHERE id=?')->execute([(int)$_POST['cotisation_id']]);
            flash('success', 'Cotisation supprimée.');
            header('Location: /accounting/pages/membres.php?id=' . (int)$cot['membre_id']); exit;
        }
        flash('error', 'Cotisation introuvable.');
        header('Location: /accounting/pages/membres.php'); exit;
    }

    // Delete membre
    if ($action === 'delete_membre') {
        $db->prepare('DELETE FROM membres WHERE id=?')->execute([(int)$_POST['membre_id']]);
        flash('success', 'Membre supprimé.');
        header('Location: /accounting/pages/membres.php'); exit;
    }

    header('Location: /accounting/pages/membres.php'); exit;
}

// Load comptes bancaires for cotisation form
$comptes = $db->query('SELECT id, nom FROM comptes_bancaires ORDER BY nom')->fetchAll();

// Detail view
if (isset($_GET['id'])) {
    $membre_id = (int)$_GET['id'];
    $membre_stmt = $db->prepare('SELECT * FROM membres WHERE id=?');
    $membre_stmt->execute([$membre_id]);
    $membre = $membre_stmt->fetch();
    if (!$membre) {
        flash('error', 'Membre introuvable.');
        header('Location: /accounting/pages/membres.php'); exit;
    }

    // Cotisation history
    $cotisations_stmt = $db->prepare('
        SELECT c.*, cb.nom as compte_nom
        FROM cotisations c
        LEFT JOIN comptes_bancaires cb ON cb.id=c.compte_id
        WHERE c.membre_id=?
        ORDER BY c.exercice DESC, c.date_paiement DESC
    ');
    $cotisations_stmt->execute([$membre_id]);
    $cotisations = $cotisations_stmt->fetchAll();

    $membre_name = htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><?= $membre_name ?></h2>
  <a href="/accounting/pages/membres.php" class="btn btn-outline-secondary">← Retour à la liste</a>
</div>

<div class="row g-4">
  <!-- Member info card -->
  <div class="col-md-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Informations</span>
        <span class="badge bg-<?= $membre['statut'] === 'actif' ? 'success' : 'secondary' ?>"><?= $membre['statut'] === 'actif' ? 'Actif' : 'Inactif' ?></span>
      </div>
      <div class="card-body">
        <form method="post" action="/accounting/pages/membres.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_membre">
          <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label form-label-sm">Nom</label>
              <input type="text" name="nom" class="form-control form-control-sm" value="<?= htmlspecialchars($membre['nom']) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Prénom</label>
              <input type="text" name="prenom" class="form-control form-control-sm" value="<?= htmlspecialchars($membre['prenom']) ?>" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Email</label>
            <input type="email" name="email" class="form-control form-control-sm" value="<?= htmlspecialchars($membre['email'] ?? '') ?>">
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label form-label-sm">Téléphone</label>
              <input type="tel" name="telephone" class="form-control form-control-sm" value="<?= htmlspecialchars($membre['telephone'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Date d'adhésion</label>
              <input type="date" name="date_adhesion" class="form-control form-control-sm" value="<?= htmlspecialchars($membre['date_adhesion'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Adresse</label>
            <textarea name="adresse" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($membre['adresse'] ?? '') ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label form-label-sm">Statut</label>
            <select name="statut" class="form-select form-select-sm">
              <option value="actif" <?= $membre['statut'] === 'actif' ? 'selected' : '' ?>>Actif</option>
              <option value="inactif" <?= $membre['statut'] === 'inactif' ? 'selected' : '' ?>>Inactif</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Notes</label>
            <textarea name="notes" class="form-control form-control-sm" rows="2"><?= htmlspecialchars($membre['notes'] ?? '') ?></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
            <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">Supprimer</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Cotisations panel -->
  <div class="col-md-6">
    <!-- Add cotisation -->
    <div class="card mb-3">
      <div class="card-header">Enregistrer une cotisation</div>
      <div class="card-body">
        <form method="post" action="/accounting/pages/membres.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_cotisation">
          <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label form-label-sm">Exercice</label>
              <select name="exercice" class="form-select form-select-sm">
                <?php for ($y = $ex; $y >= $ex - 4; $y--): ?>
                  <option value="<?= (int)$y ?>" <?= $y === $ex ? 'selected' : '' ?>><?= htmlspecialchars(exercice_label($y)) ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Montant</label>
              <div class="input-group input-group-sm">
                <input type="number" name="montant" class="form-control" step="0.01" min="0.01" required>
                <span class="input-group-text">€</span>
              </div>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label form-label-sm">Date de paiement</label>
              <input type="date" name="date_paiement" class="form-control form-control-sm" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label form-label-sm">Mode de paiement</label>
              <select name="mode_paiement" class="form-select form-select-sm">
                <option value="cheque">Chèque</option>
                <option value="especes">Espèces</option>
                <option value="virement">Virement</option>
                <option value="cb">CB</option>
                <option value="prelevement">Prélèvement</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label form-label-sm">Compte bancaire</label>
            <select name="compte_id" class="form-select form-select-sm">
              <option value="">— Aucun —</option>
              <?php foreach ($comptes as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-success btn-sm">Enregistrer la cotisation</button>
        </form>
      </div>
    </div>

    <!-- Cotisation history -->
    <div class="card">
      <div class="card-header">Historique des cotisations</div>
      <div class="card-body p-0">
        <?php if ($cotisations): ?>
        <table class="table table-sm table-hover mb-0">
          <thead><tr><th>Exercice</th><th>Date</th><th>Montant</th><th>Mode</th><th>Compte</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($cotisations as $c): ?>
            <tr>
              <td><?= htmlspecialchars(exercice_label((int)$c['exercice'])) ?></td>
              <td><?= date('d/m/Y', strtotime($c['date_paiement'])) ?></td>
              <td class="text-end"><?= number_format((float)$c['montant'], 2, ',', ' ') ?> €</td>
              <td><?= htmlspecialchars($c['mode_paiement']) ?></td>
              <td><?= $c['compte_nom'] ? htmlspecialchars($c['compte_nom']) : '—' ?></td>
              <td>
                <form method="post" action="/accounting/pages/membres.php" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="delete_cotisation">
                  <input type="hidden" name="cotisation_id" value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="btn btn-link btn-sm text-danger p-0" onclick="return confirm('Supprimer cette cotisation ?')">✕</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <p class="p-3 text-muted mb-0">Aucune cotisation enregistrée.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Delete modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="deleteModalLabel">Supprimer le membre</h5></div>
      <div class="modal-body">Supprimer <strong><?= $membre_name ?></strong> ? Ses cotisations seront également supprimées.</div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <form method="post" action="/accounting/pages/membres.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="delete_membre">
          <input type="hidden" name="membre_id" value="<?= (int)$membre['id'] ?>">
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

// LIST VIEW
// Filters
$statut_filter = $_GET['statut'] ?? '';

$where = ['1=1'];
$params = [];
if ($statut_filter !== '') {
    $where[] = 'm.statut = ?';
    $params[] = $statut_filter;
}

$membres_stmt = $db->prepare('
    SELECT m.*,
           (SELECT COUNT(*) FROM cotisations c WHERE c.membre_id=m.id AND c.exercice=?) as cotise
    FROM membres m
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY m.statut DESC, m.nom, m.prenom
');
$membres_stmt->execute(array_merge([$ex], $params));
$membres = $membres_stmt->fetchAll();

// Stats
$total = count($membres);
$sans_cotisation = count(array_filter($membres, fn($m) => !$m['cotise']));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h2>Membres <small class="text-muted fs-6"><?= htmlspecialchars($ex_label) ?></small></h2>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
    <i class="bi bi-plus-lg"></i> Nouveau membre
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card text-center">
      <div class="card-body">
        <div class="fs-2 fw-bold"><?= $total ?></div>
        <div class="text-muted">Membres</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center text-bg-success">
      <div class="card-body">
        <div class="fs-2 fw-bold"><?= $total - $sans_cotisation ?></div>
        <div>Cotisations reçues</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center <?= $sans_cotisation > 0 ? 'text-bg-warning' : 'text-bg-light' ?>">
      <div class="card-body">
        <div class="fs-2 fw-bold"><?= $sans_cotisation ?></div>
        <div>En attente</div>
      </div>
    </div>
  </div>
</div>

<!-- Filter -->
<form class="row g-2 mb-3" method="get">
  <div class="col-auto">
    <select name="statut" class="form-select form-select-sm">
      <option value="">Tous les statuts</option>
      <option value="actif" <?= $statut_filter === 'actif' ? 'selected' : '' ?>>Actifs seulement</option>
      <option value="inactif" <?= $statut_filter === 'inactif' ? 'selected' : '' ?>>Inactifs seulement</option>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary" type="submit">Filtrer</button>
  </div>
</form>

<!-- Members table -->
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Nom</th>
          <th>Prénom</th>
          <th>Email</th>
          <th>Téléphone</th>
          <th>Statut</th>
          <th class="text-center">Cotisation <?= htmlspecialchars($ex_label) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($membres): ?>
        <?php foreach ($membres as $m): ?>
          <tr class="cursor-pointer" onclick="window.location='/accounting/pages/membres.php?id=<?= (int)$m['id'] ?>'">
            <td><?= htmlspecialchars($m['nom']) ?></td>
            <td><?= htmlspecialchars($m['prenom']) ?></td>
            <td><?= $m['email'] ? htmlspecialchars($m['email']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $m['telephone'] ? htmlspecialchars($m['telephone']) : '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge bg-<?= $m['statut'] === 'actif' ? 'success' : 'secondary' ?>"><?= $m['statut'] === 'actif' ? 'Actif' : 'Inactif' ?></span></td>
            <td class="text-center"><?= $m['cotise'] ? '<span class="text-success fw-bold">✓</span>' : '<span class="text-danger">✗</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Aucun membre trouvé.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add member modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1" aria-labelledby="addMemberModalLabel">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addMemberModalLabel">Nouveau membre</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="/accounting/pages/membres.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save_membre">
        <div class="modal-body">
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Prénom <span class="text-danger">*</span></label>
              <input type="text" name="prenom" class="form-control" required>
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control">
            </div>
            <div class="col-6">
              <label class="form-label">Téléphone</label>
              <input type="tel" name="telephone" class="form-control">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Date d'adhésion</label>
              <input type="date" name="date_adhesion" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
            </div>
            <div class="col-6">
              <label class="form-label">Statut</label>
              <select name="statut" class="form-select">
                <option value="actif">Actif</option>
                <option value="inactif">Inactif</option>
              </select>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Adresse</label>
            <textarea name="adresse" class="form-control" rows="2"></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-primary">Ajouter</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.cursor-pointer { cursor: pointer; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
