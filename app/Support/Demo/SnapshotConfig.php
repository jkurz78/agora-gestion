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
        // Résidu non pertinent en démo (pas de super_admin dans le snapshot).
        'super_admin_access_log',
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
     *   {value}          → basename of the column value (basename mode)
     *   {path}           → full column value as-is (full-path mode, with anti-traversal guard)
     *   {association_id} → row.association_id for tenant-scoped tables;
     *                      for the 'association' table itself, resolved to row.id.
     *                      Guard only applies when this placeholder appears in the template.
     *   {id}             → row.id of the current row
     *
     * Resolution mode is selected automatically:
     *   - Template contains {path}  → full-path mode (value stored is the full relative path)
     *   - Template contains {value} → basename mode  (value stored is only the filename)
     *
     * The resolved path is relative to the project root (storage/app/ prefix
     * included) so that Storage::disk('local') can locate the file.
     *
     * V2 scope: branding, type-operation logos, NDF justificatifs,
     *           factures partenaires, and séance signed sheets.
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
        // Full-path columns: the stored value IS the full relative path on the private disk.
        // association_id is not needed in the template (it is embedded in the stored path).
        'notes_de_frais_lignes' => [
            'piece_jointe_path' => 'storage/app/private/{path}',
        ],
        'factures_partenaires_deposees' => [
            'pdf_path' => 'storage/app/private/{path}',
        ],
        // Basename column: basename stored (e.g. 'feuille-signee.pdf'), path built from row context.
        'seances' => [
            'feuille_signee_path' => 'storage/app/private/associations/{association_id}/seances/{id}/{value}',
        ],
    ];

    /**
     * Columns that must be nulled out in the snapshot.
     * Keys are exact table names; values are column names to scrub.
     *
     * NOTE: columns with an Eloquent cast of 'encrypted' (or 'encrypted:*')
     * are now auto-detected by EncryptedColumnsRegistry and handled via
     * round-trip decrypt-at-capture / re-encrypt-at-reset. They no longer
     * need to be listed here (listing them would null them out instead of
     * preserving the plaintext value in the YAML, which is what we want).
     *
     * Only list columns that are NOT encrypted but must still be scrubbed:
     *   - remember_token  (plain random string, not encrypted, must not leak)
     */
    public const SENSITIVE_COLUMNS = [
        'users' => [
            'remember_token',
        ],
    ];
}
