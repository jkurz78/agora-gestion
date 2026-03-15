<?php

declare(strict_types=1);

// Supprimer toute sortie d'erreur PHP avant l'auth check
ini_set('display_errors', '0');
error_reporting(0);
set_time_limit(0);

// ─── Lecture manuelle du .env ────────────────────────────────────────────────
// Laravel n'est pas bootstrappé ici — env() et getenv() ne lisent pas le .env.
$envFile = __DIR__ . '/../.env';
$env     = [];

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }
}

// ─── Authentification ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

$expectedSecret = $env['DEPLOY_SECRET'] ?? '';
$authHeader     = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedSecret = '';

if (str_starts_with($authHeader, 'Bearer ')) {
    $providedSecret = substr($authHeader, 7);
}

// hash_equals() est obligatoire pour éviter les timing attacks
if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    exit;
}

// ─── Configuration ───────────────────────────────────────────────────────────
$appDir  = __DIR__ . '/..';
$logFile = $appDir . '/deploy.log';
$php     = '/usr/local/bin/php';
$composer = 'HOME=/home/nqgu6487 /opt/cpanel/composer/bin/composer';

// ─── Helpers ─────────────────────────────────────────────────────────────────
function runCommand(string $cmd, string $logFile): bool
{
    $output  = [];
    $retCode = 0;

    exec($cmd . ' 2>&1', $output, $retCode);

    $log = '[' . date('Y-m-d H:i:s') . '] $ ' . $cmd . "\n";
    $log .= implode("\n", $output) . "\n";
    $log .= 'Exit code: ' . $retCode . "\n\n";

    file_put_contents($logFile, $log, FILE_APPEND);

    return $retCode === 0;
}

// ─── Déploiement ─────────────────────────────────────────────────────────────
file_put_contents($logFile, "\n" . str_repeat('=', 60) . "\n" . '[' . date('Y-m-d H:i:s') . "] Déploiement démarré\n", FILE_APPEND);

$commands = [
    "cd {$appDir} && {$php} artisan optimize:clear",
    "cd {$appDir} && git pull origin main",
    "cd {$appDir} && {$composer} install --no-dev --optimize-autoloader --no-interaction",
    "cd {$appDir} && {$php} artisan migrate --force",
    "cd {$appDir} && {$php} artisan config:cache",
    "cd {$appDir} && {$php} artisan route:cache",
    "cd {$appDir} && {$php} artisan view:cache",
];

foreach ($commands as $cmd) {
    if (!runCommand($cmd, $logFile)) {
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] ÉCHEC — déploiement interrompu\n", FILE_APPEND);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error']);
        exit;
    }
}

file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] Déploiement terminé avec succès\n", FILE_APPEND);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
