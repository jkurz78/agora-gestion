<?php

/**
 * One-time script to create the initial admin user.
 * Run from CLI: php accounting/sql/create_admin.php admin@monasso.fr "YourPassword123"
 * Delete this file after use.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès refusé.');
}

if ($argc < 3) {
    echo "Usage: php create_admin.php <email> <password>\n";
    exit(1);
}

require_once __DIR__.'/../config/db.php';

$nom = 'Admin';
$email = trim($argv[1]);
$password = $argv[2];

if (strlen($password) < 8) {
    echo "Erreur : le mot de passe doit contenir au moins 8 caractères.\n";
    exit(1);
}

// Check if email already exists
$stmt = get_db()->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo "Erreur : un utilisateur avec cet email existe déjà.\n";
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$stmt = get_db()->prepare('INSERT INTO users (nom, email, password_hash) VALUES (?, ?, ?)');
$stmt->execute([$nom, $email, $hash]);
echo "Utilisateur créé : $email\n";
echo "IMPORTANT: Supprimez ce fichier après utilisation.\n";
