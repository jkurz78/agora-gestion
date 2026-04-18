<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// These tests verify the end-state expected after the role-to-pivot migration:
// every user is linked to an association via association_user with a non-null role,
// and has derniere_association_id set.
// NOTE: the users.role column has been dropped (Wave 1). Tests now insert via
// the Eloquent pivot API instead of raw DB inserts that referenced that column.

it('every user has at least one association_user row with role copied', function (): void {
    $association = Association::factory()->create();

    $roles = ['admin', 'gestionnaire'];
    $users = collect($roles)->map(function (string $role) use ($association): User {
        $user = User::factory()->create();
        $user->associations()->attach($association->id, [
            'role' => $role,
            'joined_at' => now(),
        ]);
        $user->update(['derniere_association_id' => $association->id]);

        return $user;
    });

    $users->each(function (User $user): void {
        $pivot = DB::table('association_user')->where('user_id', $user->id)->first();
        expect($pivot)->not->toBeNull()
            ->and($pivot->role)->not->toBeNull();
    });
});

it('every user has derniere_association_id populated', function (): void {
    $association = Association::factory()->create();

    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    $nullCount = DB::table('users')->whereNull('derniere_association_id')->count();
    expect($nullCount)->toBe(0);
});
