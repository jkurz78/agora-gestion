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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function require_auth(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: /accounting/pages/login.php');
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
