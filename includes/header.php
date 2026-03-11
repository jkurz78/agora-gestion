<?php
require_once __DIR__ . '/auth.php';
require_auth();
$flash = get_flash();
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
    <a class="navbar-brand fw-bold" href="/accounting/pages/dashboard.php">SVS Comptabilité</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/dashboard.php">Tableau de bord</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/budget.php">Budget</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/depenses.php">Dépenses</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/recettes.php">Recettes</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/membres.php">Membres</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/dons.php">Dons</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/operations.php">Opérations</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/rapprochement.php">Rapprochement</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/rapports.php">Rapports</a></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/parametres.php">Paramètres</a></li>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item"><span class="nav-link text-white-50 small"><?= htmlspecialchars($_SESSION['user_nom'] ?? '') ?></span></li>
        <li class="nav-item"><a class="nav-link" href="/accounting/pages/logout.php"><i class="bi bi-box-arrow-right"></i> Déconnexion</a></li>
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
