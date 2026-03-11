<?php
// Database configuration
// For production: set these as environment variables or use a .env loader
define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'svs_accounting');
define('DB_USER',    $_ENV['DB_USER']    ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
define('DB_CHARSET', 'utf8mb4');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Log the real error internally, show a generic message to the user
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Erreur de connexion à la base de données. Veuillez contacter l\'administrateur.');
        }
    }
    return $pdo;
}
