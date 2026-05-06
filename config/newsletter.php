<?php

declare(strict_types=1);

return [
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
        'decay_hours' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Durée de validité du token de confirmation
    |--------------------------------------------------------------------------
    */
    'confirmation_ttl_days' => 7,
];
