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
}
