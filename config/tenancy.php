<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reserved slugs
    |--------------------------------------------------------------------------
    |
    | Ces slugs ne peuvent pas être utilisés comme identifiant d'association
    | (slug). Ils correspondent aux routes internes de l'application, aux
    | préfixes système et aux mots réservés de l'infrastructure.
    |
    | La comparaison est faite en minuscules (mb_strtolower + trim).
    |
    */

    'reserved_slugs' => [
        // Auth
        'login',
        'logout',

        // App routes
        'dashboard',
        'membres',
        'operations',
        'depenses',
        'recettes',
        'dons',
        'budget',
        'rapprochement',
        'rapports',
        'exercices',
        'parametres',
        'facturation',
        'banques',
        'tiers',
        'comptabilite',
        'profile',

        // Admin / super-admin
        'admin',
        'super-admin',

        // Portail tiers
        'portail',
        'formulaire',
        't',

        // Communication
        'email',

        // Multi-tenancy
        'tenant-assets',
        'association-selector',
        'switch-association',
        'onboarding',

        // API / webhooks
        'api',
        'webhook',
        'webhooks',

        // Infrastructure / framework
        'storage',
        'livewire',
        'vendor',
        'sanctum',
        'oauth',
        'inbound-mail',
        'horizon',
        'telescope',

        // DNS / hosting reservations
        'app',
        'www',
        'public',
        'assets',

        // Support
        'help',
        'support',

        // Auth sub-routes (cannot be association slugs)
        'password',
        'verify-email',
        'confirm-password',
        'forgot-password',
        'reset-password',
        'two-factor',

        // Health / infra
        'up',
    ],

];
