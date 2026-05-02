<?php

declare(strict_types=1);

return [
    /*
    | Scope strict : seules les routes /api/newsletter/* sont CORS-enabled.
    | Les origines sont dérivées de config/newsletter.php (source unique de vérité).
    */
    'paths' => ['api/newsletter/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => array_keys((array) config('newsletter.origins', [])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
