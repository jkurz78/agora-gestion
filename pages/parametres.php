<?php
require_once __DIR__ . '/../includes/header.php';

$db  = get_db();
$tab = $_GET['tab'] ?? 'categories';

// ─── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ── Categories ──
    if ($action === 'add_categorie') {
        $db->prepare('INSERT INTO categories (nom, type) VALUES (?, ?)')
           ->execute([trim($_POST['nom']), $_POST['type']]);
        flash('success', 'Catégorie ajoutée.');
        header('Location: /accounting/pages/parametres.php?tab=categories'); exit;
    }
    if ($action === 'delete_categorie') {
        $id = (int)$_POST['id'];
        // Check if any sous_categories exist
        $count = $db->prepare('SELECT COUNT(*) FROM sous_categories WHERE categorie_id = ?');
        $count->execute([$id]);
        if ((int)$count->fetchColumn() > 0) {
            flash('error', 'Impossible de supprimer : des sous-catégories existent.');
        } else {
            $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            flash('success', 'Catégorie supprimée.');
        }
        header('Location: /accounting/pages/parametres.php?tab=categories'); exit;
    }

    // ── Sous-catégories ──
    if ($action === 'add_sous_categorie') {
        $code = trim($_POST['code_cerfa']) ?: null;
        $db->prepare('INSERT INTO sous_categories (categorie_id, nom, code_cerfa) VALUES (?, ?, ?)')
           ->execute([(int)$_POST['categorie_id'], trim($_POST['nom']), $code]);
        flash('success', 'Sous-catégorie ajoutée.');
        header('Location: /accounting/pages/parametres.php?tab=categories'); exit;
    }
    if ($action === 'delete_sous_categorie') {
        $db->prepare('DELETE FROM sous_categories WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Sous-catégorie supprimée.');
        header('Location: /accounting/pages/parametres.php?tab=categories'); exit;
    }

    // ── Comptes bancaires ──
    if ($action === 'add_compte') {
        $db->prepare('INSERT INTO comptes_bancaires (nom, iban, solde_initial, date_solde_initial) VALUES (?, ?, ?, ?)')
           ->execute([
               trim($_POST['nom']),
               trim($_POST['iban']) ?: null,
               (float)$_POST['solde_initial'],
               $_POST['date_solde_initial'],
           ]);
        flash('success', 'Compte bancaire ajouté.');
        header('Location: /accounting/pages/parametres.php?tab=comptes'); exit;
    }
    if ($action === 'delete_compte') {
        $db->prepare('DELETE FROM comptes_bancaires WHERE id = ?')->execute([(int)$_POST['id']]);
        flash('success', 'Compte supprimé.');
        header('Location: /accounting/pages/parametres.php?tab=comptes'); exit;
    }

    // ── Utilisateurs ──
    if ($action === 'add_user') {
        $email = trim($_POST['email']);
        // Check duplicate
        $check = $db->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            flash('error', 'Un utilisateur avec cet email existe déjà.');
        } else {
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $db->prepare('INSERT INTO users (nom, email, password_hash) VALUES (?, ?, ?)')
               ->execute([trim($_POST['nom']), $email, $hash]);
            flash('success', 'Utilisateur ajouté.');
        }
        header('Location: /accounting/pages/parametres.php?tab=utilisateurs'); exit;
    }
    if ($action === 'delete_user') {
        $id = (int)$_POST['id'];
        if ($id === (int)$_SESSION['user_id']) {
            flash('error', 'Impossible de supprimer votre propre compte.');
        } else {
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('success', 'Utilisateur supprimé.');
        }
        header('Location: /accounting/pages/parametres.php?tab=utilisateurs'); exit;
    }
}

// ─── Load data ────────────────────────────────────────────────────────────────

// Categories with their sous_categories
$cats_raw = $db->query('SELECT * FROM categories ORDER BY type, nom')->fetchAll();
$categories = [];
foreach ($cats_raw as $cat) {
    $stmt = $db->prepare('SELECT * FROM sous_categories WHERE categorie_id = ? ORDER BY nom');
    $stmt->execute([$cat['id']]);
    $cat['sous'] = $stmt->fetchAll();
    $categories[] = $cat;
}

// Comptes bancaires
$comptes = $db->query('SELECT * FROM comptes_bancaires ORDER BY nom')->fetchAll();

// Users
$users = $db->query('SELECT id, nom, email, created_at FROM users ORDER BY nom')->fetchAll();

// CERFA reference codes for dropdown hint
$cerfa_codes = ['60','61-62','63','641','645','65','66','67','68','86','70','74','754','755','75','76','77','78','87'];
?>

<h2 class="mb-4">Paramètres</h2>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'categories'   ? 'active' : '' ?>" href="?tab=categories">Catégories</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'comptes'      ? 'active' : '' ?>" href="?tab=comptes">Comptes bancaires</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'utilisateurs' ? 'active' : '' ?>" href="?tab=utilisateurs">Utilisateurs</a>
  </li>
</ul>

<?php if ($tab === 'categories'): ?>
<!-- ═══════════════════════ CATEGORIES TAB ═══════════════════════ -->

<!-- Add category form -->
<div class="card mb-4 shadow-sm">
  <div class="card-header fw-semibold">Ajouter une catégorie</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action"     value="add_categorie">
      <div class="col-sm-6">
        <label class="form-label">Nom</label>
        <input type="text" name="nom" class="form-control" required maxlength="100">
      </div>
      <div class="col-sm-4">
        <label class="form-label">Type</label>
        <select name="type" class="form-select" required>
          <option value="depense">Dépense</option>
          <option value="recette">Recette</option>
        </select>
      </div>
      <div class="col-sm-2">
        <button type="submit" class="btn btn-primary w-100">Ajouter</button>
      </div>
    </form>
  </div>
</div>

<!-- Category accordion -->
<div class="accordion" id="catAccordion">
<?php foreach ($categories as $i => $cat): ?>
  <div class="accordion-item">
    <h2 class="accordion-header">
      <button class="accordion-button <?= $i > 0 ? 'collapsed' : '' ?>" type="button"
              data-bs-toggle="collapse" data-bs-target="#cat<?= $cat['id'] ?>">
        <span class="me-2"><?= htmlspecialchars($cat['nom']) ?></span>
        <span class="badge <?= $cat['type'] === 'depense' ? 'bg-danger' : 'bg-success' ?> me-auto">
          <?= $cat['type'] === 'depense' ? 'Dépense' : 'Recette' ?>
        </span>
        <span class="badge bg-secondary ms-2"><?= count($cat['sous']) ?> sous-cat.</span>
      </button>
    </h2>
    <div id="cat<?= $cat['id'] ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>">
      <div class="accordion-body">

        <!-- Sous-categories list -->
        <?php if ($cat['sous']): ?>
        <table class="table table-sm mb-3">
          <thead><tr><th>Nom</th><th>Code CERFA</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($cat['sous'] as $sc): ?>
            <tr>
              <td><?= htmlspecialchars($sc['nom']) ?></td>
              <td><?php if ($sc['code_cerfa']): ?><span class="badge bg-info text-dark"><?= htmlspecialchars($sc['code_cerfa']) ?></span><?php endif; ?></td>
              <td class="text-end">
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Supprimer cette sous-catégorie ?')">
                  <input type="hidden" name="csrf_token"         value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action"             value="delete_sous_categorie">
                  <input type="hidden" name="id"                 value="<?= (int)$sc['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <!-- Add sous-categorie form -->
        <form method="post" class="row g-2 align-items-end border-top pt-3">
          <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action"        value="add_sous_categorie">
          <input type="hidden" name="categorie_id"  value="<?= (int)$cat['id'] ?>">
          <div class="col-sm-5">
            <label class="form-label small">Nom de la sous-catégorie</label>
            <input type="text" name="nom" class="form-control form-control-sm" required maxlength="100">
          </div>
          <div class="col-sm-3">
            <label class="form-label small">Code CERFA (optionnel)</label>
            <input type="text" name="code_cerfa" class="form-control form-control-sm"
                   list="cerfa-list" maxlength="10" placeholder="ex: 641">
            <datalist id="cerfa-list">
              <?php foreach ($cerfa_codes as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>">
              <?php endforeach; ?>
            </datalist>
          </div>
          <div class="col-sm-2">
            <button type="submit" class="btn btn-sm btn-success w-100">+ Ajouter</button>
          </div>
        </form>

        <!-- Delete category -->
        <div class="mt-3 text-end">
          <form method="post" class="d-inline"
                onsubmit="return confirm('Supprimer la catégorie <?= htmlspecialchars(addslashes($cat['nom'])) ?> ?')">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action"     value="delete_categorie">
            <input type="hidden" name="id"         value="<?= (int)$cat['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer la catégorie</button>
          </form>
        </div>

      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php elseif ($tab === 'comptes'): ?>
<!-- ═══════════════════════ COMPTES BANCAIRES TAB ═══════════════════════ -->

<div class="card mb-4 shadow-sm">
  <div class="card-header fw-semibold">Ajouter un compte bancaire</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action"     value="add_compte">
      <div class="col-sm-4">
        <label class="form-label">Nom du compte</label>
        <input type="text" name="nom" class="form-control" required maxlength="150" placeholder="ex: Compte courant CIC">
      </div>
      <div class="col-sm-3">
        <label class="form-label">IBAN (optionnel)</label>
        <input type="text" name="iban" class="form-control" maxlength="34" placeholder="FR76...">
      </div>
      <div class="col-sm-2">
        <label class="form-label">Solde initial (€)</label>
        <input type="number" name="solde_initial" class="form-control" step="0.01" value="0" required>
      </div>
      <div class="col-sm-2">
        <label class="form-label">Date du solde</label>
        <input type="date" name="date_solde_initial" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-sm-1">
        <button type="submit" class="btn btn-primary w-100">Ajouter</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr><th>Nom</th><th>IBAN</th><th>Solde initial</th><th>Date</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($comptes as $c): ?>
        <tr>
          <td class="fw-medium"><?= htmlspecialchars($c['nom']) ?></td>
          <td class="text-muted small"><?= htmlspecialchars($c['iban'] ?? '—') ?></td>
          <td><?= number_format((float)$c['solde_initial'], 2, ',', ' ') ?> €</td>
          <td><?= date('d/m/Y', strtotime($c['date_solde_initial'])) ?></td>
          <td class="text-end">
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Supprimer ce compte ?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action"     value="delete_compte">
              <input type="hidden" name="id"         value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$comptes): ?>
        <tr><td colspan="5" class="text-muted text-center py-3">Aucun compte bancaire enregistré.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'utilisateurs'): ?>
<!-- ═══════════════════════ UTILISATEURS TAB ═══════════════════════ -->

<div class="card mb-4 shadow-sm">
  <div class="card-header fw-semibold">Ajouter un utilisateur</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="action"     value="add_user">
      <div class="col-sm-3">
        <label class="form-label">Nom</label>
        <input type="text" name="nom" class="form-control" required maxlength="100">
      </div>
      <div class="col-sm-4">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="col-sm-3">
        <label class="form-label">Mot de passe initial</label>
        <input type="password" name="password" class="form-control" required minlength="8">
      </div>
      <div class="col-sm-2">
        <button type="submit" class="btn btn-primary w-100">Ajouter</button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr><th>Nom</th><th>Email</th><th>Créé le</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="fw-medium">
            <?= htmlspecialchars($u['nom']) ?>
            <?php if ((int)$u['id'] === (int)$_SESSION['user_id']): ?>
              <span class="badge bg-secondary">vous</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td class="text-muted small"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
          <td class="text-end">
            <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Supprimer cet utilisateur ?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action"     value="delete_user">
              <input type="hidden" name="id"         value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
