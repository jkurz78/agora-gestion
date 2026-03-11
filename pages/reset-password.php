<?php
require_once __DIR__ . '/../config/db.php';

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$step    = isset($_GET['token']) ? 'reset' : 'request';
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        die('Requête invalide.');
    }

    if ($step === 'request') {
        $email = trim($_POST['email'] ?? '');
        $stmt  = get_db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $reset_token = bin2hex(random_bytes(32));
            $expires     = date('Y-m-d H:i:s', strtotime('+1 hour'));
            get_db()->prepare('UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE id = ?')
                    ->execute([$reset_token, $expires, $user['id']]);

            $link    = 'http://' . $_SERVER['HTTP_HOST'] . '/accounting/pages/reset-password.php?token=' . $reset_token;
            $subject = 'Réinitialisation de votre mot de passe — SVS Comptabilité';
            $body    = "Bonjour,\n\nCliquez sur le lien ci-dessous pour réinitialiser votre mot de passe :\n\n$link\n\nCe lien expire dans 1 heure.\n\nSi vous n'avez pas fait cette demande, ignorez ce message.";
            $headers = 'From: noreply@svs.fr' . "\r\n" . 'X-Mailer: PHP/' . phpversion();
            mail($email, $subject, $body, $headers);
        }

        // Always show the same message — don't reveal whether email exists
        $message = 'Si cet email est associé à un compte, un lien de réinitialisation a été envoyé.';

    } else {
        // Reset step
        $reset_token = $_GET['token'] ?? '';
        $password    = $_POST['password'] ?? '';
        $confirm     = $_POST['confirm']  ?? '';

        if ($password !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } elseif (strlen($password) < 8) {
            $error = 'Le mot de passe doit contenir au moins 8 caractères.';
        } else {
            $stmt = get_db()->prepare(
                'SELECT id FROM users WHERE reset_token = ? AND reset_expires_at > NOW()'
            );
            $stmt->execute([$reset_token]);
            $user = $stmt->fetch();

            if ($user) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                get_db()->prepare(
                    'UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?'
                )->execute([$hash, $user['id']]);

                $message = 'Mot de passe mis à jour avec succès. <a href="/accounting/pages/login.php">Se connecter</a>';
            } else {
                $error = 'Ce lien est invalide ou a expiré. Veuillez faire une nouvelle demande.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SVS Comptabilité — Réinitialisation mot de passe</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width:420px;margin-top:80px">
  <div class="text-center mb-4">
    <h1 class="h4 fw-bold text-primary">SVS Comptabilité</h1>
    <p class="text-muted small">Réinitialisation du mot de passe</p>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (!$message): ?>
  <div class="card shadow-sm">
    <div class="card-body p-4">

      <?php if ($step === 'request'): ?>
        <p class="text-muted small mb-3">Saisissez votre email pour recevoir un lien de réinitialisation.</p>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required autofocus>
          </div>
          <button type="submit" class="btn btn-primary w-100">Envoyer le lien</button>
        </form>

      <?php else: ?>
        <p class="text-muted small mb-3">Choisissez un nouveau mot de passe (8 caractères minimum).</p>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div class="mb-3">
            <label class="form-label">Nouveau mot de passe</label>
            <input type="password" name="password" class="form-control" minlength="8" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirmer le mot de passe</label>
            <input type="password" name="confirm" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary w-100">Mettre à jour</button>
        </form>
      <?php endif; ?>

      <div class="mt-3 text-center">
        <a href="/accounting/pages/login.php" class="text-muted small">Retour à la connexion</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
