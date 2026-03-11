<?php
require_once __DIR__ . '/../config/db.php';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false, // set to true when served over HTTPS
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /accounting/pages/dashboard.php'); exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        die('Requête invalide.');
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = get_db()->prepare('SELECT id, nom, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_nom'] = $user['nom'];
        header('Location: /accounting/pages/dashboard.php'); exit;
    } else {
        $error = 'Email ou mot de passe incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SVS Comptabilité — Connexion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:420px;margin-top:80px">
  <div class="text-center mb-4">
    <h1 class="h4 fw-bold text-primary">SVS Comptabilité</h1>
    <p class="text-muted small">Association Soigner Vivre Sourire</p>
  </div>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required autofocus
                 value="<?= htmlspecialchars($email ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Mot de passe</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Se connecter</button>
      </form>
      <div class="mt-3 text-center">
        <a href="/accounting/pages/reset-password.php" class="text-muted small">Mot de passe oublié ?</a>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
