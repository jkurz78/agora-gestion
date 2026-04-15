<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('users table has multi-tenant columns', function (): void {
    expect(Schema::hasColumn('users', 'role_systeme'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'derniere_association_id'))->toBeTrue();
});

it('super_admin_access_log table has expected structure', function (): void {
    expect(Schema::hasTable('super_admin_access_log'))->toBeTrue()
        ->and(Schema::hasColumn('super_admin_access_log', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('super_admin_access_log', 'association_id'))->toBeTrue()
        ->and(Schema::hasColumn('super_admin_access_log', 'action'))->toBeTrue()
        ->and(Schema::hasColumn('super_admin_access_log', 'payload'))->toBeTrue()
        ->and(Schema::hasColumn('super_admin_access_log', 'created_at'))->toBeTrue();
});
