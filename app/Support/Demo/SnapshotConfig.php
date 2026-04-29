<?php

declare(strict_types=1);

namespace App\Support\Demo;

final class SnapshotConfig
{
    /**
     * Tables excluded from snapshot capture.
     * These contain runtime/session/queue data that should not be replayed.
     */
    public const EXCLUDED_TABLES = [
        'sessions',
        'cache',
        'cache_locks',
        'failed_jobs',
        'jobs',
        'password_reset_tokens',
        'personal_access_tokens',
        'migrations',
        'email_logs',
        'incoming_mail_logs',
    ];

    /**
     * Pre-computed bcrypt hash for the 'demo' password (cost=12).
     * Used to replace real user passwords in the snapshot so that
     * all demo accounts share the same known password.
     *
     * Verified: password_verify('demo', DEMO_USER_PASSWORD_HASH) === true
     */
    public const DEMO_USER_PASSWORD_HASH = '$2y$12$70r/NnnHK5IpvNqor/8crO/xnNsascPzsKdorcqAASnP3MQkD6tTC';

    public const SCHEMA_VERSION = 1;

    /**
     * Map of "file path" columns to capture during demo:capture.
     *
     * Format: ['table_name' => ['column' => '<path_template>']]
     *
     * Template placeholders:
     *   {value}          → contents of the column (basename of the file)
     *   {association_id} → row.association_id for tenant-scoped tables;
     *                      for the 'association' table itself, resolved to row.id
     *   {id}             → row.id of the current row
     *
     * The resolved path is relative to the project root (storage/app/ prefix
     * included) so that Storage::disk('local') can locate the file.
     *
     * V1 scope: branding files and type-operation logos.
     * Ephemeral uploads (transactions, NDF, factures partenaires, etc.)
     * are intentionally excluded — they are user-generated and not seeded.
     */
    public const FILE_PATH_COLUMNS = [
        'association' => [
            'logo_path' => 'storage/app/private/associations/{association_id}/branding/{value}',
            'cachet_signature_path' => 'storage/app/private/associations/{association_id}/branding/{value}',
        ],
        'type_operations' => [
            'logo_path' => 'storage/app/private/associations/{association_id}/type-operations/{id}/{value}',
            'attestation_medicale_path' => 'storage/app/private/associations/{association_id}/type-operations/{id}/{value}',
        ],
    ];

    /**
     * Columns that must be nulled out in the snapshot.
     * Keys are exact table names; values are column names to scrub.
     * These columns contain secrets (encrypted or plain) that must never
     * appear in a committed YAML file.
     */
    public const SENSITIVE_COLUMNS = [
        'users' => [
            'two_factor_secret',
            'two_factor_recovery_codes',
            'remember_token',
        ],
        'association' => [
            'anthropic_api_key',
        ],
        'helloasso_parametres' => [
            'client_secret',
            'callback_token',
        ],
        'incoming_mail_parametres' => [
            'imap_password',
        ],
        'smtp_parametres' => [
            'smtp_password',
        ],
    ];
}
