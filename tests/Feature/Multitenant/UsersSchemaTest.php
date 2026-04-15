<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('users table has multi-tenant columns', function (): void {
    expect(Schema::hasColumn('users', 'role_systeme'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'derniere_association_id'))->toBeTrue();
});
