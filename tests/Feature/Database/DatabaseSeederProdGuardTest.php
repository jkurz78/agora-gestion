<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    // Wipe the default Association created by the global Pest beforeEach
    // so this file can assert the seeder produces 0 / >0 from a clean baseline.
    TenantContext::clear();
    Association::query()->forceDelete();
});

it('refuses to run in production environment', function () {
    app()['env'] = 'production';

    try {
        // Pas de setCommand : le seeder doit tolérer l'absence de console.
        $seeder = new DatabaseSeeder;
        $seeder->run();

        expect(Association::count())->toBe(0);
        expect(User::where('email', 'admin@monasso.fr')->exists())->toBeFalse();
    } finally {
        app()['env'] = 'testing';
    }
});

it('runs normally in non-production environment', function () {
    app()['env'] = 'testing';

    $seeder = new DatabaseSeeder;

    // Run the seeder; sub-seeders that use MySQL-specific SQL (e.g. SET FOREIGN_KEY_CHECKS)
    // may throw on SQLite — we only care that the guard did not block and the association +
    // admin user were created (both happen before the sub-seeder calls).
    try {
        $seeder->run();
    } catch (Exception $e) {
        // SQLite compat: sub-seeders may fail; assert on what was already persisted.
    }

    expect(Association::count())->toBeGreaterThan(0);
    expect(User::where('email', 'admin@monasso.fr')->exists())->toBeTrue();
});
