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
$providedSecret = $_POST['token'] ?? '';

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

// ─── Déploiement en arrière-plan ─────────────────────────────────────────────
file_put_contents($logFile, "\n" . str_repeat('=', 60) . "\n" . '[' . date('Y-m-d H:i:s') . "] Déploiement démarré\n", FILE_APPEND);

$script = implode(' && ', [
    "cd {$appDir} && {$php} artisan optimize:clear",
    "{$composer} install --no-dev --optimize-autoloader --no-interaction",
    "git pull origin main",
    "{$php} artisan migrate --force",
    "{$php} artisan config:cache",
    "{$php} artisan route:cache",
    "{$php} artisan view:cache",
    "echo '[' \$(date '+%Y-%m-%d %H:%M:%S') '] Déploiement terminé avec succès'",
]);

exec('cd ' . escapeshellarg($appDir) . ' && nohup bash -c ' . escapeshellarg($script) . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
exit;
