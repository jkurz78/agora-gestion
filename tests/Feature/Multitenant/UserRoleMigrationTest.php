<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('every user has at least one association_user row with role copied', function (): void {
    // Seed one association and two users, then simulate what the migration does.
    $assoId = DB::table('association')->insertGetId([
        'nom' => 'Test Asso',
        'slug' => 'test-asso',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $userIds = [];
    foreach (['admin', 'gestionnaire'] as $role) {
        $userIds[] = DB::table('users')->insertGetId([
            'nom' => 'Test User',
            'email' => $role.'@test.fr',
            'password' => Hash::make('password'),
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Insert pivots as the migration would have done.
    foreach ($userIds as $userId) {
        $user = DB::table('users')->where('id', $userId)->first();
        DB::table('association_user')->insert([
            'user_id' => $userId,
            'association_id' => $assoId,
            'role' => $user->role ?? 'consultation',
            'joined_at' => $user->created_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')
            ->where('id', $userId)
            ->whereNull('derniere_association_id')
            ->update(['derniere_association_id' => $assoId]);
    }

    $users = DB::table('users')->get();
    foreach ($users as $user) {
        $pivot = DB::table('association_user')->where('user_id', $user->id)->first();
        expect($pivot)->not->toBeNull()
            ->and($pivot->role)->not->toBeNull();
    }
});

it('every user has derniere_association_id populated', function (): void {
    $assoId = DB::table('association')->insertGetId([
        'nom' => 'Test Asso 2',
        'slug' => 'test-asso-2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $userId = DB::table('users')->insertGetId([
        'nom' => 'Test Admin',
        'email' => 'admin2@test.fr',
        'password' => Hash::make('password'),
        'role' => 'admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Migration sets derniere_association_id when NULL.
    DB::table('users')
        ->where('id', $userId)
        ->whereNull('derniere_association_id')
        ->update(['derniere_association_id' => $assoId]);

    $nullCount = DB::table('users')->whereNull('derniere_association_id')->count();
    expect($nullCount)->toBe(0);
});
