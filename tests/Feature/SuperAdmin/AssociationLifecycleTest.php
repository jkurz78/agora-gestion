<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\AssociationDetail;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $this->asso = Association::factory()->create(['statut' => 'actif']);
});

it('suspends an active association', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('suspend');

    expect($this->asso->fresh()->statut)->toBe('suspendu');
    expect(SuperAdminAccessLog::where('action', 'suspend')->where('association_id', $this->asso->id)->exists())->toBeTrue();
});

it('reactivates a suspended association', function () {
    $this->asso->update(['statut' => 'suspendu']);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('reactivate');

    expect($this->asso->fresh()->statut)->toBe('actif');
});

it('archives a suspended association', function () {
    $this->asso->update(['statut' => 'suspendu']);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('archive');

    expect($this->asso->fresh()->statut)->toBe('archive');
    expect(SuperAdminAccessLog::where('action', 'archive')->where('association_id', $this->asso->id)->exists())->toBeTrue();
});

it('refuses to archive an active (not-yet-suspended) association', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('archive')
        ->assertHasErrors(['statut']);

    expect($this->asso->fresh()->statut)->toBe('actif');
});

it('prevents a regular user from accessing a suspended association', function () {
    $user = User::factory()->create();
    $this->asso->update(['statut' => 'suspendu']);
    $user->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);

    session(['current_association_id' => $this->asso->id]);

    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});
