<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Models\User;

it('promote un utilisateur existant en super-admin', function () {
    $user = User::factory()->create(['email' => 'marie@asso.fr', 'role_systeme' => RoleSysteme::User]);

    expect($user->role_systeme)->toBe(RoleSysteme::User);

    $this->artisan('app:promote-super-admin', ['email' => 'marie@asso.fr'])
        ->expectsOutputToContain('promu super-admin')
        ->assertSuccessful();

    expect($user->fresh()->role_systeme)->toBe(RoleSysteme::SuperAdmin);
});

it('échoue si l\'email n\'existe pas', function () {
    $this->artisan('app:promote-super-admin', ['email' => 'inconnu@x.fr'])
        ->expectsOutputToContain('Aucun utilisateur trouvé')
        ->assertFailed();
});

it('signale si l\'utilisateur est déjà super-admin (idempotent)', function () {
    User::factory()->create([
        'email' => 'sa@asso.fr',
        'role_systeme' => RoleSysteme::SuperAdmin,
    ]);

    $this->artisan('app:promote-super-admin', ['email' => 'sa@asso.fr'])
        ->expectsOutputToContain('déjà super-admin')
        ->assertSuccessful();
});

it('rétrograde un super-admin avec --demote', function () {
    $user = User::factory()->create([
        'email' => 'sa@asso.fr',
        'role_systeme' => RoleSysteme::SuperAdmin,
    ]);

    $this->artisan('app:promote-super-admin', ['email' => 'sa@asso.fr', '--demote' => true])
        ->expectsOutputToContain('rétrogradé')
        ->assertSuccessful();

    expect($user->fresh()->role_systeme)->toBe(RoleSysteme::User);
});

it('signale --demote sur un user qui n\'est pas super-admin (idempotent)', function () {
    User::factory()->create(['email' => 'standard@asso.fr', 'role_systeme' => RoleSysteme::User]);

    $this->artisan('app:promote-super-admin', ['email' => 'standard@asso.fr', '--demote' => true])
        ->expectsOutputToContain('rien à faire')
        ->assertSuccessful();
});
