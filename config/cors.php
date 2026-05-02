<?php

declare(strict_types=1);

return [
    /*
    | Scope strict : seules les routes /api/newsletter/* sont CORS-enabled.
    | Les origines sont dérivées de config/newsletter.php (source unique de vérité).
    */
    'paths' => ['api/newsletter/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    // Les origines doivent être listées statiquement ici car config/cors.php
    // peut être résolu avant config/newsletter.php — config() n'est pas disponible
    // à ce stade de l'initialisation du framework.
    'allowed_origins' => [
        'https://soigner-vivre-sourire.fr',
        'https://dev.soigner-vivre-sourire.fr',
        'http://localhost:4321',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,
];
