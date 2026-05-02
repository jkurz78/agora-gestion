<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Origines HTTP autorisées
    |--------------------------------------------------------------------------
    |
    | Mapping origine → slug d'asso. L'asso est résolue à partir du header
    | `Origin` envoyé par le navigateur. Toute origine non listée est rejetée
    | (403) avant validation et avant rate-limiting.
    |
    | Pour ajouter une asso au flux newsletter : ajouter sa ligne ici.
    |
    */
    'origins' => [
        'https://soigner-vivre-sourire.fr'     => 'soigner-vivre-sourire',
        'https://dev.soigner-vivre-sourire.fr' => 'soigner-vivre-sourire',
        'http://localhost:4321'                => 'soigner-vivre-sourire',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Limite globale par IP, indépendamment de l'asso. Cohérent avec le pattern
    | RateLimiter::for('newsletter', ...) défini dans bootstrap/app.php.
    |
    */
    'rate_limit' => [
        'max_attempts' => 5,
        'decay_hours'  => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Durée de validité du token de confirmation
    |--------------------------------------------------------------------------
    */
    'confirmation_ttl_days' => 7,
];
