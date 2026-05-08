<?php

/**
 * AgoraGestion — Newsletter subscription shim (PHP).
 *
 * Proxy serveur léger à déposer sur le site vitrine d'une asso. Reçoit
 * la soumission d'un formulaire newsletter (HTML ou JS), signe le payload
 * en HMAC-SHA256, et relaie l'appel authentifié vers l'endpoint REST de
 * l'instance AgoraGestion de l'asso.
 *
 * Le secret HMAC ne quitte JAMAIS le serveur — il n'est ni en JavaScript,
 * ni dans les headers visibles par le navigateur. Toute compromission
 * reste contenue à ce serveur.
 *
 * Configuration : voir `.env.example` dans le même dossier.
 * Documentation complète : `README.md`.
 *
 * Source : https://github.com/jkurz78/agora-gestion/tree/main/clients/newsletter-php
 *
 * @license   MIT
 */

declare(strict_types=1);

// ─── 1. Méthode autorisée ─────────────────────────────────────────────────

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// ─── 2. Chargement de la configuration ────────────────────────────────────

// Source 1 : fichier .env dans le même dossier que ce shim
$envFile = __DIR__.'/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach ((array) parse_ini_file($envFile, false, INI_SCANNER_RAW) as $key => $value) {
        if (getenv((string) $key) === false) {
            putenv("$key=$value");
        }
    }
}
// Source 2 : variables d'environnement Apache (SetEnv dans .htaccess) ou
// variables système — utilisées en fallback si .env absent.

$endpoint = (string) (getenv('NEWSLETTER_ENDPOINT') ?: '');
$keyId = (string) (getenv('NEWSLETTER_KEY_ID') ?: '');
$secret = (string) (getenv('NEWSLETTER_HMAC_SECRET') ?: '');

if ($endpoint === '' || $keyId === '' || $secret === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'shim_misconfigured']);
    error_log('[newsletter-shim] Missing env vars: NEWSLETTER_ENDPOINT, NEWSLETTER_KEY_ID, or NEWSLETTER_HMAC_SECRET.');
    exit;
}

// ─── 3. Lecture du payload entrant ────────────────────────────────────────

$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
$rawInput = (string) (file_get_contents('php://input') ?: '');

if (str_contains($contentType, 'application/json')) {
    $input = json_decode($rawInput, true) ?: [];
} else {
    // form-encoded (default HTML form submission)
    $input = $_POST;
}

// On normalise vers le contrat exact attendu par AgoraGestion.
// Tout champ supplémentaire est ignoré (anti-pollution du payload).
$payload = [
    'email' => isset($input['email']) ? trim((string) $input['email']) : '',
    'prenom' => isset($input['prenom']) && $input['prenom'] !== '' ? (string) $input['prenom'] : null,
    'nom' => isset($input['nom']) && $input['nom'] !== '' ? (string) $input['nom'] : null,
    'consent' => filter_var($input['consent'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'bot_trap' => isset($input['bot_trap']) ? (string) $input['bot_trap'] : '',
];

$payloadJson = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($payloadJson === false) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'invalid_payload']);
    exit;
}

// ─── 4. Signature HMAC ────────────────────────────────────────────────────

$timestamp = time();
$stringToSign = $timestamp.'.'.$payloadJson;
$signature = 'v1='.hash_hmac('sha256', $stringToSign, $secret);

// ─── 5. Relai HTTP vers AgoraGestion ──────────────────────────────────────

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payloadJson,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: AgoraGestion-Newsletter-Shim/1.0 (PHP)',
        'X-Key-Id: '.$keyId,
        'X-Timestamp: '.$timestamp,
        'X-Signature: '.$signature,
    ],
]);

$body = curl_exec($ch);
$status = (int) (curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
$curlError = (string) curl_error($ch);
// Note : pas de curl_close($ch) — depuis PHP 8.0 c'est un no-op silencieux,
// depuis PHP 8.5+ ça lève un Deprecated warning. Le handle se ferme
// automatiquement à la fin du scope.
unset($ch);

// ─── 6. Renvoi au navigateur ──────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');

if ($body === false || $status === 0) {
    http_response_code(502);
    error_log('[newsletter-shim] Upstream unreachable: '.$curlError);
    echo json_encode(['error' => 'upstream_unavailable']);
    exit;
}

http_response_code($status);
echo $body;
