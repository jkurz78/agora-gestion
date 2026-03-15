# SVS Accounting — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a standalone PHP/MySQL accounting application for Association Soigner Vivre Sourire (SVS), a French loi 1901 non-profit.

**Architecture:** Pure PHP 8.x procédural, Bootstrap 5, PDO/MySQL, Apache. One PHP file per page. Shared includes for auth, header, footer. No external dependencies, no Composer.

**Tech Stack:** PHP 8.x · MySQL 8.x · Bootstrap 5 (CDN) · Apache · French UI

---

## Conventions

- All pages begin with `require_once '../includes/auth.php'` (except login/logout/reset-password)
- All DB queries use PDO prepared statements — never string interpolation in SQL
- CSRF token: generated once per session in `auth.php`, verified on every POST
- Exercice: INT (start year). `exercice=2025` = 01/09/2025→31/08/2026. Displayed as "2025-2026".
- Monetary values: `DECIMAL(10,2)`, displayed with `number_format($v, 2, ',', ' ') . ' €'`
- Dates: stored as `DATE` (Y-m-d), displayed as `d/m/Y` in UI
- Bootstrap alerts for success/error feedback (flash via `$_SESSION['flash']`)

---

## Task 1: Project scaffolding & database schema

**Files:**
- Create: `accounting/config/db.php`
- Create: `accounting/includes/auth.php`
- Create: `accounting/includes/header.php`
- Create: `accounting/includes/footer.php`
- Create: `accounting/index.php`
- Create: `accounting/sql/schema.sql`

**Step 1: Create directory structure**

```bash
mkdir -p accounting/{config,includes,pages,sql}
```

**Step 2: Write `accounting/sql/schema.sql`**

```sql
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  reset_token VARCHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comptes_bancaires (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(150) NOT NULL,
  iban VARCHAR(34) NULL,
  solde_initial DECIMAL(10,2) NOT NULL DEFAULT 0,
  date_solde_initial DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  type ENUM('depense','recette') NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sous_categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  categorie_id INT NOT NULL,
  nom VARCHAR(100) NOT NULL,
  code_cerfa VARCHAR(10) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE operations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(150) NOT NULL,
  description TEXT NULL,
  date_debut DATE NULL,
  date_fin DATE NULL,
  nombre_seances INT NULL,
  statut ENUM('en_cours','cloturee') NOT NULL DEFAULT 'en_cours',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE depenses (
  id INT PRIMARY KEY AUTO_INCREMENT,
  date DATE NOT NULL,
  libelle VARCHAR(255) NOT NULL,
  montant_total DECIMAL(10,2) NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  beneficiaire VARCHAR(150) NULL,
  reference VARCHAR(100) NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  saisi_par INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL,
  FOREIGN KEY (saisi_par) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE depense_lignes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  depense_id INT NOT NULL,
  sous_categorie_id INT NOT NULL,
  operation_id INT NULL,
  seance INT NULL,
  montant DECIMAL(10,2) NOT NULL,
  notes TEXT NULL,
  FOREIGN KEY (depense_id) REFERENCES depenses(id) ON DELETE CASCADE,
  FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id),
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recettes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  date DATE NOT NULL,
  libelle VARCHAR(255) NOT NULL,
  montant_total DECIMAL(10,2) NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  payeur VARCHAR(150) NULL,
  reference VARCHAR(100) NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  saisi_par INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL,
  FOREIGN KEY (saisi_par) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recette_lignes (
  id INT PRIMARY KEY AUTO_INCREMENT,
  recette_id INT NOT NULL,
  sous_categorie_id INT NOT NULL,
  operation_id INT NULL,
  seance INT NULL,
  montant DECIMAL(10,2) NOT NULL,
  notes TEXT NULL,
  FOREIGN KEY (recette_id) REFERENCES recettes(id) ON DELETE CASCADE,
  FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id),
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_lines (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sous_categorie_id INT NOT NULL,
  exercice INT NOT NULL,
  montant_prevu DECIMAL(10,2) NOT NULL,
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sous_categorie_id) REFERENCES sous_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE membres (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  email VARCHAR(150) NULL,
  telephone VARCHAR(20) NULL,
  adresse TEXT NULL,
  date_adhesion DATE NULL,
  statut ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cotisations (
  id INT PRIMARY KEY AUTO_INCREMENT,
  membre_id INT NOT NULL,
  exercice INT NOT NULL,
  montant DECIMAL(10,2) NOT NULL,
  date_paiement DATE NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (membre_id) REFERENCES membres(id) ON DELETE CASCADE,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE donateurs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  email VARCHAR(150) NULL,
  adresse TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dons (
  id INT PRIMARY KEY AUTO_INCREMENT,
  donateur_id INT NULL,
  date DATE NOT NULL,
  montant DECIMAL(10,2) NOT NULL,
  mode_paiement ENUM('virement','cheque','especes','cb','prelevement') NOT NULL,
  objet VARCHAR(255) NULL,
  operation_id INT NULL,
  seance INT NULL,
  compte_id INT NULL,
  pointe TINYINT(1) NOT NULL DEFAULT 0,
  recu_emis TINYINT(1) NOT NULL DEFAULT 0,
  saisi_par INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (donateur_id) REFERENCES donateurs(id) ON DELETE SET NULL,
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL,
  FOREIGN KEY (compte_id) REFERENCES comptes_bancaires(id) ON DELETE SET NULL,
  FOREIGN KEY (saisi_par) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Step 3: Write `accounting/config/db.php`**

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'svs_accounting');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
```

**Step 4: Write `accounting/includes/auth.php`**

```php
<?php
require_once __DIR__ . '/../config/db.php';

session_start();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function require_auth(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /pages/login.php');
        exit;
    }
}

function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Token CSRF invalide.');
    }
}

function current_exercice(): int {
    $month = (int)date('n');
    return $month >= 9 ? (int)date('Y') : (int)date('Y') - 1;
}

function exercice_label(int $e): string {
    return $e . '-' . ($e + 1);
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
```

**Step 5: Write `accounting/includes/header.php`**

```php
<?php
require_once __DIR__ . '/auth.php';
require_auth();
$flash = get_flash();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SVS Comptabilité</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="/pages/dashboard.php">SVS Comptabilité</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="/pages/dashboard.php">Tableau de bord</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/budget.php">Budget</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/depenses.php">Dépenses</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/recettes.php">Recettes</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/membres.php">Membres</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/dons.php">Dons</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/operations.php">Opérations</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/rapprochement.php">Rapprochement</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/rapports.php">Rapports</a></li>
        <li class="nav-item"><a class="nav-link" href="/pages/parametres.php">Paramètres</a></li>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="/pages/logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="container-fluid py-4">
<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>
```

**Step 6: Write `accounting/includes/footer.php`**

```php
</div><!-- /container-fluid -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

**Step 7: Write `accounting/index.php`**

```php
<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    header('Location: /pages/dashboard.php');
} else {
    header('Location: /pages/login.php');
}
exit;
```

**Step 8: Import schema & verify**

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS svs_accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p svs_accounting < accounting/sql/schema.sql
mysql -u root -p svs_accounting -e "SHOW TABLES;"
# Expected: 16 tables listed
```

**Step 9: Commit**

```bash
git add accounting/
git commit -m "feat(accounting): scaffold project structure and database schema"
```

---

## Task 2: Authentication — login & logout

**Files:**
- Create: `accounting/pages/login.php`
- Create: `accounting/pages/logout.php`

**Step 1: Write `accounting/pages/login.php`**

```php
<?php
require_once __DIR__ . '/../config/db.php';
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /pages/dashboard.php'); exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        die('Token CSRF invalide.');
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = get_db()->prepare('SELECT id, nom, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_nom'] = $user['nom'];
        header('Location: /pages/dashboard.php'); exit;
    } else {
        $error = 'Email ou mot de passe incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>SVS Comptabilité — Connexion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:420px;margin-top:80px">
  <h1 class="h4 mb-4 text-center">SVS Comptabilité</h1>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Mot de passe</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Se connecter</button>
      </form>
      <div class="mt-3 text-center">
        <a href="/pages/reset-password.php" class="text-muted small">Mot de passe oublié ?</a>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

**Step 2: Write `accounting/pages/logout.php`**

```php
<?php
session_start();
session_destroy();
header('Location: /pages/login.php');
exit;
```

**Step 3: Create a first admin user manually to test login**

```sql
INSERT INTO users (nom, email, password_hash)
VALUES ('Admin', 'admin@svs.fr', '$2y$12$REPLACE_WITH_REAL_HASH');
```

Generate hash with:
```php
<?php echo password_hash('votre_mot_de_passe', PASSWORD_BCRYPT); ?>
```

**Step 4: Open browser, navigate to `/pages/login.php`**
- Test wrong password → error message shown
- Test correct credentials → redirect to `/pages/dashboard.php` (404 for now is OK)
- Test direct access to `/pages/dashboard.php` without session → redirect to login

**Step 5: Commit**

```bash
git add accounting/pages/login.php accounting/pages/logout.php
git commit -m "feat(accounting): add login and logout"
```

---

## Task 3: Password reset via email

**Files:**
- Create: `accounting/pages/reset-password.php`

**Step 1: Write `accounting/pages/reset-password.php`**

This file handles two states: (1) request form, (2) token confirmation form.

```php
<?php
require_once __DIR__ . '/../config/db.php';
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$step = isset($_GET['token']) ? 'reset' : 'request';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) die('Token CSRF invalide.');

    if ($step === 'request') {
        $email = trim($_POST['email'] ?? '');
        $stmt = get_db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $reset_token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $upd = get_db()->prepare('UPDATE users SET reset_token=?, reset_expires_at=? WHERE id=?');
            $upd->execute([$reset_token, $expires, $user['id']]);

            $link = 'http://' . $_SERVER['HTTP_HOST'] . '/pages/reset-password.php?token=' . $reset_token;
            mail($email, 'Réinitialisation de votre mot de passe — SVS',
                "Cliquez sur ce lien pour réinitialiser votre mot de passe :\n\n$link\n\nCe lien expire dans 1 heure."
            );
        }
        // Always show same message (security: don't reveal if email exists)
        $message = 'Si cet email existe, un lien de réinitialisation a été envoyé.';

    } else {
        // Reset step
        $reset_token = $_GET['token'];
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';

        if ($password !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif (strlen($password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } else {
            $stmt = get_db()->prepare(
                'SELECT id FROM users WHERE reset_token=? AND reset_expires_at > NOW()'
            );
            $stmt->execute([$reset_token]);
            $user = $stmt->fetch();

            if ($user) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $upd = get_db()->prepare(
                    'UPDATE users SET password_hash=?, reset_token=NULL, reset_expires_at=NULL WHERE id=?'
                );
                $upd->execute([$hash, $user['id']]);
                $message = 'Mot de passe mis à jour. <a href="/pages/login.php">Se connecter</a>';
            } else {
                $error = 'Lien invalide ou expiré.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>SVS — Réinitialisation mot de passe</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:420px;margin-top:80px">
  <h1 class="h5 mb-4">Réinitialisation du mot de passe</h1>
  <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if (!$message): ?>
  <div class="card shadow-sm"><div class="card-body">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php if ($step === 'request'): ?>
      <div class="mb-3">
        <label class="form-label">Votre email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <button class="btn btn-primary w-100">Envoyer le lien</button>
    <?php else: ?>
      <div class="mb-3">
        <label class="form-label">Nouveau mot de passe</label>
        <input type="password" name="password" class="form-control" minlength="8" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Confirmer</label>
        <input type="password" name="confirm" class="form-control" required>
      </div>
      <button class="btn btn-primary w-100">Mettre à jour</button>
    <?php endif; ?>
  </form>
  </div></div>
  <?php endif; ?>
</div>
</body>
</html>
```

**Step 2: Test in browser**
- Submit form with unknown email → message générique affiché
- Submit with known email → check DB for token, visit link
- Submit mismatched passwords → error
- Submit valid reset → password updated, redirect to login

**Step 3: Commit**

```bash
git add accounting/pages/reset-password.php
git commit -m "feat(accounting): add password reset via email"
```

---

## Task 4: Dashboard

**Files:**
- Create: `accounting/pages/dashboard.php`

**Step 1: Write `accounting/pages/dashboard.php`**

```php
<?php
require_once __DIR__ . '/../includes/header.php';

$db = get_db();
$ex = current_exercice();
$ex_label = exercice_label($ex);
$date_debut = $ex . '-09-01';
$date_fin = ($ex + 1) . '-08-31';

// Solde général: total recettes - total dépenses sur l'exercice
$stmt = $db->prepare('SELECT COALESCE(SUM(montant_total),0) FROM recettes WHERE date BETWEEN ? AND ?');
$stmt->execute([$date_debut, $date_fin]);
$total_recettes = (float)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COALESCE(SUM(montant_total),0) FROM depenses WHERE date BETWEEN ? AND ?');
$stmt->execute([$date_debut, $date_fin]);
$total_depenses = (float)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COALESCE(SUM(montant),0) FROM dons WHERE date BETWEEN ? AND ?');
$stmt->execute([$date_debut, $date_fin]);
$total_dons = (float)$stmt->fetchColumn();

$solde = $total_recettes + $total_dons - $total_depenses;

// Dernières dépenses (5)
$dernieres_depenses = $db->prepare('SELECT d.*, u.nom as saisi_nom FROM depenses d JOIN users u ON u.id=d.saisi_par ORDER BY d.date DESC, d.created_at DESC LIMIT 5');
$dernieres_depenses->execute();

// Dernières recettes (5)
$dernieres_recettes = $db->prepare('SELECT r.*, u.nom as saisi_nom FROM recettes r JOIN users u ON u.id=r.saisi_par ORDER BY r.date DESC, r.created_at DESC LIMIT 5');
$dernieres_recettes->execute();

// Membres sans cotisation sur l'exercice en cours
$membres_en_attente = $db->prepare('
  SELECT m.id, m.nom, m.prenom FROM membres m
  WHERE m.statut = "actif"
  AND m.id NOT IN (SELECT membre_id FROM cotisations WHERE exercice = ?)
  ORDER BY m.nom, m.prenom
  LIMIT 10
');
$membres_en_attente->execute([$ex]);
?>

<h2 class="mb-4">Tableau de bord — Exercice <?= $ex_label ?></h2>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card text-bg-primary">
      <div class="card-body">
        <div class="small">Solde général</div>
        <div class="fs-4 fw-bold"><?= number_format($solde, 2, ',', ' ') ?> €</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-bg-success">
      <div class="card-body">
        <div class="small">Recettes</div>
        <div class="fs-4 fw-bold"><?= number_format($total_recettes + $total_dons, 2, ',', ' ') ?> €</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-bg-danger">
      <div class="card-body">
        <div class="small">Dépenses</div>
        <div class="fs-4 fw-bold"><?= number_format($total_depenses, 2, ',', ' ') ?> €</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-bg-info">
      <div class="card-body">
        <div class="small">Dons reçus</div>
        <div class="fs-4 fw-bold"><?= number_format($total_dons, 2, ',', ' ') ?> €</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-md-6">
    <h5>Dernières dépenses</h5>
    <table class="table table-sm table-hover">
      <thead><tr><th>Date</th><th>Libellé</th><th class="text-end">Montant</th></tr></thead>
      <tbody>
      <?php foreach ($dernieres_depenses as $d): ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($d['date'])) ?></td>
          <td><?= htmlspecialchars($d['libelle']) ?></td>
          <td class="text-end text-danger"><?= number_format($d['montant_total'], 2, ',', ' ') ?> €</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="col-md-6">
    <h5>Membres sans cotisation <?= $ex_label ?></h5>
    <?php $membres = $membres_en_attente->fetchAll(); ?>
    <?php if ($membres): ?>
    <ul class="list-group list-group-flush">
      <?php foreach ($membres as $m): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <?= htmlspecialchars($m['prenom'] . ' ' . $m['nom']) ?>
          <a href="/pages/membres.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">Saisir</a>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
      <p class="text-success">Tous les membres actifs ont cotisé ✓</p>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

**Step 2: Verify in browser**
- Dashboard loads, shows 4 KPI cards
- Solde = 0 with empty DB (correct)
- No members without cotisation yet (correct)

**Step 3: Commit**

```bash
git add accounting/pages/dashboard.php
git commit -m "feat(accounting): add dashboard with KPIs and recent activity"
```

---

## Task 5: Paramètres — Catégories & sous-catégories

**Files:**
- Create: `accounting/pages/parametres.php`

**Step 1: Write `accounting/pages/parametres.php`**

Single page with tabs: Catégories, Comptes bancaires, Utilisateurs.

The page handles all CRUD via POST with `?action=` parameter.

Key patterns for categories section:
```php
// Load all categories with their subcategories
$cats = $db->query('SELECT * FROM categories ORDER BY type, nom')->fetchAll();
foreach ($cats as &$cat) {
    $stmt = $db->prepare('SELECT * FROM sous_categories WHERE categorie_id=? ORDER BY nom');
    $stmt->execute([$cat['id']]);
    $cat['sous'] = $stmt->fetchAll();
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_categorie') {
        $stmt = $db->prepare('INSERT INTO categories (nom, type) VALUES (?,?)');
        $stmt->execute([trim($_POST['nom']), $_POST['type']]);
        flash('success', 'Catégorie ajoutée.');
    }
    elseif ($action === 'add_sous_categorie') {
        $stmt = $db->prepare('INSERT INTO sous_categories (categorie_id, nom, code_cerfa) VALUES (?,?,?)');
        $code = trim($_POST['code_cerfa']) ?: null;
        $stmt->execute([$_POST['categorie_id'], trim($_POST['nom']), $code]);
        flash('success', 'Sous-catégorie ajoutée.');
    }
    elseif ($action === 'delete_categorie') {
        // Only if no sous_categories (or cascade)
        $stmt = $db->prepare('DELETE FROM categories WHERE id=?');
        $stmt->execute([$_POST['id']]);
        flash('success', 'Catégorie supprimée.');
    }
    // ... similar for delete_sous_categorie, edit operations
    header('Location: /pages/parametres.php'); exit;
}
```

Display: Bootstrap accordion — one panel per category, listing subcategories with code_cerfa badge. Add forms inline with collapse.

**Step 2: Add comptes bancaires tab**

```php
// Load comptes
$comptes = $db->query('SELECT * FROM comptes_bancaires ORDER BY nom')->fetchAll();

// POST: add_compte
if ($action === 'add_compte') {
    $stmt = $db->prepare('INSERT INTO comptes_bancaires (nom, iban, solde_initial, date_solde_initial) VALUES (?,?,?,?)');
    $stmt->execute([
        trim($_POST['nom']),
        trim($_POST['iban']) ?: null,
        $_POST['solde_initial'],
        $_POST['date_solde_initial'],
    ]);
    flash('success', 'Compte bancaire ajouté.');
}
```

**Step 3: Add utilisateurs tab**

```php
// POST: add_user
if ($action === 'add_user') {
    $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (nom, email, password_hash) VALUES (?,?,?)');
    $stmt->execute([trim($_POST['nom']), trim($_POST['email']), $hash]);
    flash('success', 'Utilisateur ajouté.');
}

// POST: delete_user (cannot delete self)
if ($action === 'delete_user') {
    if ((int)$_POST['id'] === $_SESSION['user_id']) {
        flash('error', 'Impossible de supprimer votre propre compte.');
    } else {
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$_POST['id']]);
        flash('success', 'Utilisateur supprimé.');
    }
}
```

**Step 4: Test in browser**
- Add a category "Charges de personnel" (type: dépense) → appears in list
- Add subcategory "Salaires" with code_cerfa "641" under it → appears
- Add a bank account "Compte courant" with initial balance
- Add a second user

**Step 5: Commit**

```bash
git add accounting/pages/parametres.php
git commit -m "feat(accounting): add settings page (categories, bank accounts, users)"
```

---

## Task 6: Opérations CRUD

**Files:**
- Create: `accounting/pages/operations.php`

**Step 1: Write `accounting/pages/operations.php`**

List + modal add/edit + detail view. Key fields include `nombre_seances`.

```php
// POST: add/edit operation
if ($action === 'save') {
    $data = [
        trim($_POST['nom']),
        trim($_POST['description']) ?: null,
        $_POST['date_debut'] ?: null,
        $_POST['date_fin'] ?: null,
        $_POST['nombre_seances'] ?: null,
        $_POST['statut'],
    ];
    if (empty($_POST['id'])) {
        $db->prepare('INSERT INTO operations (nom,description,date_debut,date_fin,nombre_seances,statut) VALUES (?,?,?,?,?,?)')->execute($data);
    } else {
        $data[] = $_POST['id'];
        $db->prepare('UPDATE operations SET nom=?,description=?,date_debut=?,date_fin=?,nombre_seances=?,statut=? WHERE id=?')->execute($data);
    }
    flash('success', 'Opération enregistrée.');
}

// Detail view: ?id=X → show all depense_lignes + recette_lignes for this operation
if (isset($_GET['id'])) {
    $op = $db->prepare('SELECT * FROM operations WHERE id=?');
    $op->execute([$_GET['id']]);
    $op = $op->fetch();

    $lignes_dep = $db->prepare('
        SELECT dl.*, d.date, d.libelle, sc.nom as sous_cat, c.nom as cat
        FROM depense_lignes dl
        JOIN depenses d ON d.id=dl.depense_id
        JOIN sous_categories sc ON sc.id=dl.sous_categorie_id
        JOIN categories c ON c.id=sc.categorie_id
        WHERE dl.operation_id=?
        ORDER BY d.date
    ');
    $lignes_dep->execute([$op['id']]);
    // ... similar for recette_lignes
}
```

**Step 2: Test**
- Create "Atelier escrime" with 10 séances → appears in list
- Edit → nombre_seances changes
- View detail (empty for now)

**Step 3: Commit**

```bash
git add accounting/pages/operations.php
git commit -m "feat(accounting): add operations CRUD"
```

---

## Task 7: Dépenses CRUD (with line items and seance selector)

**Files:**
- Create: `accounting/pages/depenses.php`

This is the most complex page. The form uses JavaScript to dynamically add/remove lines.

**Step 1: Write the page structure**

```php
// POST: save depense (header + lines)
if ($action === 'save') {
    csrf_verify();
    $db->beginTransaction();
    try {
        $montant_total = array_sum($_POST['montant'] ?? []);

        if (empty($_POST['depense_id'])) {
            $stmt = $db->prepare('INSERT INTO depenses (date,libelle,montant_total,mode_paiement,beneficiaire,reference,compte_id,notes,saisi_par) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $_POST['date'], trim($_POST['libelle']), $montant_total,
                $_POST['mode_paiement'], trim($_POST['beneficiaire']) ?: null,
                trim($_POST['reference']) ?: null, $_POST['compte_id'] ?: null,
                trim($_POST['notes']) ?: null, $_SESSION['user_id'],
            ]);
            $depense_id = $db->lastInsertId();
        } else {
            $depense_id = (int)$_POST['depense_id'];
            $db->prepare('UPDATE depenses SET date=?,libelle=?,montant_total=?,mode_paiement=?,beneficiaire=?,reference=?,compte_id=?,notes=? WHERE id=?')
               ->execute([$_POST['date'], trim($_POST['libelle']), $montant_total,
                  $_POST['mode_paiement'], trim($_POST['beneficiaire']) ?: null,
                  trim($_POST['reference']) ?: null, $_POST['compte_id'] ?: null,
                  trim($_POST['notes']) ?: null, $depense_id]);
            $db->prepare('DELETE FROM depense_lignes WHERE depense_id=?')->execute([$depense_id]);
        }

        foreach ($_POST['sous_categorie_id'] as $i => $sc_id) {
            if (!$sc_id || !$_POST['montant'][$i]) continue;
            $stmt = $db->prepare('INSERT INTO depense_lignes (depense_id,sous_categorie_id,operation_id,seance,montant,notes) VALUES (?,?,?,?,?,?)');
            $seance = ($_POST['seance'][$i] ?? '') ?: null;
            $op_id = ($_POST['operation_id'][$i] ?? '') ?: null;
            $stmt->execute([$depense_id, $sc_id, $op_id, $seance, $_POST['montant'][$i], trim($_POST['ligne_notes'][$i] ?? '') ?: null]);
        }

        $db->commit();
        flash('success', 'Dépense enregistrée.');
    } catch (Exception $e) {
        $db->rollBack();
        flash('error', 'Erreur : ' . $e->getMessage());
    }
}
```

**Step 2: Write the JavaScript for dynamic lines**

```html
<script>
// Preload operations with their nombre_seances
const operations = <?= json_encode(
    array_column(
        $db->query('SELECT id, nom, nombre_seances FROM operations ORDER BY nom')->fetchAll(),
        null, 'id'
    )
) ?>;

function addLine() {
    const tpl = document.getElementById('ligne-template').content.cloneNode(true);
    const idx = document.querySelectorAll('.ligne-row').length;
    // Update name attributes with new index
    tpl.querySelectorAll('[name]').forEach(el => {
        el.name = el.name.replace('__IDX__', idx);
    });
    document.getElementById('lignes-body').appendChild(tpl);
}

function onOperationChange(select) {
    const row = select.closest('tr');
    const seanceWrap = row.querySelector('.seance-wrap');
    const seanceInput = row.querySelector('.seance-input');
    const op = operations[select.value];
    if (op && op.nombre_seances) {
        seanceInput.max = op.nombre_seances;
        seanceInput.min = 1;
        seanceWrap.style.display = '';
    } else {
        seanceWrap.style.display = 'none';
        seanceInput.value = '';
    }
}
</script>
```

**Step 3: Write the list with filters**

Filters: `date_debut`, `date_fin`, `categorie_id`, `operation_id`, `compte_id`, `pointe`.

```php
$where = ['1=1'];
$params = [];
if (!empty($_GET['date_debut'])) { $where[] = 'd.date >= ?'; $params[] = $_GET['date_debut']; }
if (!empty($_GET['date_fin']))   { $where[] = 'd.date <= ?'; $params[] = $_GET['date_fin']; }
if (!empty($_GET['compte_id']))  { $where[] = 'd.compte_id = ?'; $params[] = $_GET['compte_id']; }
if (isset($_GET['pointe']) && $_GET['pointe'] !== '') { $where[] = 'd.pointe = ?'; $params[] = $_GET['pointe']; }

$sql = 'SELECT d.*, u.nom as saisi_nom, cb.nom as compte_nom
        FROM depenses d
        LEFT JOIN users u ON u.id=d.saisi_par
        LEFT JOIN comptes_bancaires cb ON cb.id=d.compte_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY d.date DESC, d.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$depenses = $stmt->fetchAll();
```

**Step 4: Test in browser**
- Add a depense with 2 lines, different sous-catégories
- Add a depense line linked to "Atelier escrime" → séance selector appears (1-10)
- Edit depense → lines reload correctly
- Delete depense → lines cascade deleted

**Step 5: Commit**

```bash
git add accounting/pages/depenses.php
git commit -m "feat(accounting): add depenses CRUD with multi-line ventilation and seance selector"
```

---

## Task 8: Recettes CRUD

**Files:**
- Create: `accounting/pages/recettes.php`

**Step 1:** Copy the structure of `depenses.php`, replace:
- `depenses` → `recettes`
- `depense_lignes` → `recette_lignes`
- `beneficiaire` → `payeur`
- "Dépense" → "Recette" in all labels

The logic is identical (same header + lines pattern).

**Step 2: Test** — same as depenses

**Step 3: Commit**

```bash
git add accounting/pages/recettes.php
git commit -m "feat(accounting): add recettes CRUD (mirrors depenses)"
```

---

## Task 9: Budget prévisionnel

**Files:**
- Create: `accounting/pages/budget.php`

**Step 1: Write `accounting/pages/budget.php`**

```php
// Default to current exercice
$ex = isset($_GET['exercice']) ? (int)$_GET['exercice'] : current_exercice();

// Load budget lines with category info
$lignes = $db->prepare('
    SELECT bl.*, sc.nom as sous_cat, sc.code_cerfa, c.nom as cat, c.type,
           COALESCE((
               SELECT SUM(dl.montant) FROM depense_lignes dl
               JOIN depenses d ON d.id=dl.depense_id
               WHERE dl.sous_categorie_id=bl.sous_categorie_id
               AND d.date BETWEEN CONCAT(?,\'-09-01\') AND CONCAT(?+1,\'-08-31\')
           ),0) as realise_dep,
           COALESCE((
               SELECT SUM(rl.montant) FROM recette_lignes rl
               JOIN recettes r ON r.id=rl.recette_id
               WHERE rl.sous_categorie_id=bl.sous_categorie_id
               AND r.date BETWEEN CONCAT(?,\'-09-01\') AND CONCAT(?+1,\'-08-31\')
           ),0) as realise_rec
    FROM budget_lines bl
    JOIN sous_categories sc ON sc.id=bl.sous_categorie_id
    JOIN categories c ON c.id=sc.categorie_id
    WHERE bl.exercice=?
    ORDER BY c.type, c.nom, sc.nom
');
$lignes->execute([$ex, $ex, $ex, $ex, $ex]);
```

Display: table grouped by type (Dépenses / Recettes), columns: Catégorie, Sous-catégorie, Code CERFA, Prévu, Réalisé, Écart.

**Step 2: Test** — add a budget line for "Salaires 641", enter amount, verify réalisé updates as depenses are entered.

**Step 3: Commit**

```bash
git add accounting/pages/budget.php
git commit -m "feat(accounting): add budget previsionnel with prevue vs realise"
```

---

## Task 10: Membres & cotisations

**Files:**
- Create: `accounting/pages/membres.php`

**Step 1: Write `accounting/pages/membres.php`**

Two views: list and detail (by `?id=X`).

```php
// List: membres with cotisation status for current exercice
$ex = current_exercice();
$membres = $db->prepare('
    SELECT m.*,
           (SELECT COUNT(*) FROM cotisations c WHERE c.membre_id=m.id AND c.exercice=?) as cotise
    FROM membres m
    ORDER BY m.statut DESC, m.nom, m.prenom
');
$membres->execute([$ex]);

// Detail view actions:
// - edit membre info
// - add cotisation (with exercice, montant, date, mode_paiement, compte_id)
// - list all cotisations for this membre

// POST: save_cotisation
if ($action === 'save_cotisation') {
    $stmt = $db->prepare('INSERT INTO cotisations (membre_id,exercice,montant,date_paiement,mode_paiement,compte_id) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $_POST['membre_id'], $_POST['exercice'], $_POST['montant'],
        $_POST['date_paiement'], $_POST['mode_paiement'],
        $_POST['compte_id'] ?: null,
    ]);
    flash('success', 'Cotisation enregistrée.');
}
```

**Step 2: Test**
- Add a member → appears in list with cotisation ✗
- Add cotisation → ✓ appears
- Dashboard members-without-cotisation updates

**Step 3: Commit**

```bash
git add accounting/pages/membres.php
git commit -m "feat(accounting): add membres and cotisations management"
```

---

## Task 11: Dons & donateurs

**Files:**
- Create: `accounting/pages/dons.php`

**Step 1: Write `accounting/pages/dons.php`**

Two sub-views: dons list and donateur detail.

```php
// Don form: select or create donateur inline
// If $_POST['nouveau_donateur'] == '1': insert donateur first, use new id
// Otherwise: use $_POST['donateur_id'] (nullable for anonymous)

if ($action === 'save_don') {
    $donateur_id = null;
    if (!empty($_POST['nouveau_donateur'])) {
        $stmt = $db->prepare('INSERT INTO donateurs (nom, prenom, email, adresse) VALUES (?,?,?,?)');
        $stmt->execute([trim($_POST['d_nom']), trim($_POST['d_prenom']), trim($_POST['d_email']) ?: null, trim($_POST['d_adresse']) ?: null]);
        $donateur_id = $db->lastInsertId();
    } elseif (!empty($_POST['donateur_id'])) {
        $donateur_id = (int)$_POST['donateur_id'];
    }

    $op_id = $_POST['operation_id'] ?: null;
    $seance = ($op_id && $_POST['seance']) ? (int)$_POST['seance'] : null;

    $stmt = $db->prepare('INSERT INTO dons (donateur_id,date,montant,mode_paiement,objet,operation_id,seance,compte_id,saisi_par) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$donateur_id, $_POST['date'], $_POST['montant'], $_POST['mode_paiement'],
        trim($_POST['objet']) ?: null, $op_id, $seance, $_POST['compte_id'] ?: null, $_SESSION['user_id']]);
    flash('success', 'Don enregistré.');
}
```

**Step 2: Test** — add don anonyme, add don with new donateur, view donateur history.

**Step 3: Commit**

```bash
git add accounting/pages/dons.php
git commit -m "feat(accounting): add dons and donateurs management"
```

---

## Task 12: Rapprochement bancaire

**Files:**
- Create: `accounting/pages/rapprochement.php`

**Step 1: Write `accounting/pages/rapprochement.php`**

```php
// Select compte and period, show all transactions with pointe toggle
$compte_id = (int)($_GET['compte_id'] ?? 0);
$periode_debut = $_GET['debut'] ?? date('Y-m-01');
$periode_fin = $_GET['fin'] ?? date('Y-m-d');

// POST: toggle pointe
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $type = $_POST['type']; // depense|recette|don|cotisation
    $id = (int)$_POST['id'];
    $table = match($type) {
        'depense'   => 'depenses',
        'recette'   => 'recettes',
        'don'       => 'dons',
        'cotisation'=> 'cotisations',
        default => throw new InvalidArgumentException()
    };
    $db->prepare("UPDATE $table SET pointe = 1 - pointe WHERE id=?")->execute([$id]);
    header('Location: ' . $_SERVER['HTTP_SELF'] . '?' . http_build_query($_GET)); exit;
}

// Load compte
$compte = $db->prepare('SELECT * FROM comptes_bancaires WHERE id=?');
$compte->execute([$compte_id]);
$compte = $compte->fetch();

// Calculate solde pointe
// solde = solde_initial + sum(recettes pointées) + sum(cotisations pointées) + sum(dons pointés) - sum(dépenses pointées)
// (for transactions up to periode_fin)

// Load all transactions for this compte in period
// Union of depenses, recettes, cotisations, dons
```

Display: one table with columns: Date, Type, Libellé, Montant, Pointé. Sortable by date. Running balance at bottom.

**Step 2: Test** — point a depense, solde updates correctly.

**Step 3: Commit**

```bash
git add accounting/pages/rapprochement.php
git commit -m "feat(accounting): add bank reconciliation page"
```

---

## Task 13: Rapports — Compte de résultat CERFA

**Files:**
- Create: `accounting/pages/rapports.php`

**Step 1: Write compte de résultat query**

```php
// Aggregate by code_cerfa for selected exercice and optional operations
$ex = (int)($_GET['exercice'] ?? current_exercice());
$op_ids = array_filter(array_map('intval', (array)($_GET['operations'] ?? [])));

$date_debut = $ex . '-09-01';
$date_fin = ($ex + 1) . '-08-31';

// Build operation filter
$op_filter = '';
$op_params = [];
if ($op_ids) {
    $placeholders = implode(',', array_fill(0, count($op_ids), '?'));
    $op_filter = " AND dl.operation_id IN ($placeholders)";
    $op_params = $op_ids;
}

// Charges (depense_lignes)
$charges = $db->prepare("
    SELECT sc.code_cerfa, sc.nom as sous_cat, c.nom as cat,
           SUM(dl.montant) as total
    FROM depense_lignes dl
    JOIN depenses d ON d.id=dl.depense_id
    JOIN sous_categories sc ON sc.id=dl.sous_categorie_id
    JOIN categories c ON c.id=sc.categorie_id
    WHERE d.date BETWEEN ? AND ?
    $op_filter
    GROUP BY sc.id
    ORDER BY sc.code_cerfa, c.nom, sc.nom
");
$charges->execute(array_merge([$date_debut, $date_fin], $op_params));

// Produits (recette_lignes + dons + cotisations linked to sous_categories via code 754/755)
// Note: cotisations and dons aggregate under their respective CERFA codes if defined
$produits = $db->prepare("
    SELECT sc.code_cerfa, sc.nom as sous_cat, c.nom as cat,
           SUM(rl.montant) as total
    FROM recette_lignes rl
    JOIN recettes r ON r.id=rl.recette_id
    JOIN sous_categories sc ON sc.id=rl.sous_categorie_id
    JOIN categories c ON c.id=sc.categorie_id
    WHERE r.date BETWEEN ? AND ?
    $op_filter
    GROUP BY sc.id
    ORDER BY sc.code_cerfa, c.nom, sc.nom
");
$produits->execute(array_merge([$date_debut, $date_fin], $op_params));
```

Display: two-column Bootstrap card layout (Charges left, Produits right), subtotals per CERFA code, grand totals, result (excédent/déficit).

**Step 2: Add CSV export**

```php
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compte-resultat-' . exercice_label($ex) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['Type', 'Code CERFA', 'Catégorie', 'Sous-catégorie', 'Montant'], ';');
    foreach ($charges->fetchAll() as $row) {
        fputcsv($out, ['Charge', $row['code_cerfa'], $row['cat'], $row['sous_cat'], $row['total']], ';');
    }
    // ... produits
    exit;
}
```

**Step 3: Test** — enter some depenses/recettes with code_cerfa, verify aggregation is correct.

**Step 4: Commit**

```bash
git add accounting/pages/rapports.php
git commit -m "feat(accounting): add compte de resultat CERFA with operation filter and CSV export"
```

---

## Task 14: Rapport par séances (pivot)

**Files:**
- Modify: `accounting/pages/rapports.php` (add second tab)

**Step 1: Add operation selector (only operations with nombre_seances)**

```php
$ops_avec_seances = $db->query('SELECT id, nom, nombre_seances FROM operations WHERE nombre_seances IS NOT NULL ORDER BY nom')->fetchAll();
```

**Step 2: Build pivot query**

```php
$op_id = (int)($_GET['op_seances'] ?? 0);
if ($op_id) {
    $op = $db->prepare('SELECT * FROM operations WHERE id=?');
    $op->execute([$op_id]);
    $op = $op->fetch();
    $n = (int)$op['nombre_seances'];

    // Charges per seance per sous_categorie
    $pivot_charges = [];
    for ($s = 1; $s <= $n; $s++) {
        $stmt = $db->prepare('
            SELECT sc.id, sc.nom as sous_cat, c.nom as cat, SUM(dl.montant) as total
            FROM depense_lignes dl
            JOIN sous_categories sc ON sc.id=dl.sous_categorie_id
            JOIN categories c ON c.id=sc.categorie_id
            WHERE dl.operation_id=? AND dl.seance=?
            GROUP BY sc.id
        ');
        $stmt->execute([$op_id, $s]);
        foreach ($stmt->fetchAll() as $row) {
            $pivot_charges[$row['cat']][$row['sous_cat']][$s] = $row['total'];
        }
    }
    // Similar for produits via recette_lignes
}
```

Display: HTML table with `<th>` for each séance, rows for each sous-catégorie, footer row for totals per séance and grand total column.

**Step 2: Test** — enter depense lines with seances, verify pivot table shows correct amounts per séance.

**Step 3: Commit**

```bash
git add accounting/pages/rapports.php
git commit -m "feat(accounting): add rapport par seances pivot table"
```

---

## Task 15: Final polish & security review

**Step 1: Verify CSRF on all POST forms** — every `<form method="post">` must have `<input type="hidden" name="csrf_token" value="...">`

**Step 2: Verify all SQL uses prepared statements** — grep for any string interpolation in queries

```bash
grep -r "SELECT.*\$_" accounting/pages/
# Expected: no results
```

**Step 3: Verify all output is escaped** — every `<?= $var ?>` must be `<?= htmlspecialchars($var) ?>`

```bash
grep -r "<?=" accounting/pages/ | grep -v htmlspecialchars | grep -v number_format | grep -v json_encode
# Review each result manually
```

**Step 4: Test password reset flow end to end**

**Step 5: Verify exercice calculation**
- In September: `current_exercice()` returns current year
- In January: returns previous year

**Step 6: Add `.htaccess` to protect config directory**

```apache
# accounting/config/.htaccess
Deny from all
```

**Step 7: Final commit**

```bash
git add accounting/
git commit -m "feat(accounting): complete MVP - SVS accounting application"
```

---

## Appendix: CERFA 15059-02 Code Reference

| Code | Libellé (Charges) |
|------|-------------------|
| 60 | Achats |
| 61-62 | Services extérieurs |
| 63 | Impôts et taxes |
| 641 | Salaires et traitements |
| 645 | Charges sociales |
| 65 | Autres charges de gestion courante |
| 66 | Charges financières |
| 67 | Charges exceptionnelles |
| 68 | Dotations aux amortissements |
| 86 | Emplois des contributions volontaires en nature |

| Code | Libellé (Produits) |
|------|-------------------|
| 70 | Ventes de produits et prestations de services |
| 74 | Subventions d'exploitation |
| 754 | Cotisations reçues |
| 755 | Dons manuels |
| 75 | Autres produits de gestion courante |
| 76 | Produits financiers |
| 77 | Produits exceptionnels |
| 78 | Reprises sur amortissements |
| 87 | Contributions volontaires en nature |
