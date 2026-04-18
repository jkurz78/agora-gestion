<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\AssociationsList;
use App\Models\Association;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
});

it('renders all associations regardless of current user pivot', function () {
    $a = Association::factory()->create(['nom' => 'Asso A', 'slug' => 'asso-a']);
    $b = Association::factory()->create(['nom' => 'Asso B', 'slug' => 'asso-b']);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationsList::class)
        ->assertSee('Asso A')
        ->assertSee('Asso B');
});

it('counts non-revoked users per association', function () {
    $asso = Association::factory()->create();
    $active = User::factory()->create();
    $revoked = User::factory()->create();
    $active->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);
    $revoked->associations()->attach($asso->id, ['role' => 'gestionnaire', 'joined_at' => now()->subMonth(), 'revoked_at' => now()]);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationsList::class)
        ->assertSeeInOrder([$asso->nom, '1 utilisateur']);
});

it('filters by search query on name or slug', function () {
    Association::factory()->create(['nom' => 'Alpha', 'slug' => 'alpha']);
    Association::factory()->create(['nom' => 'Beta', 'slug' => 'beta']);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationsList::class)
        ->set('search', 'alp')
        ->assertSee('Alpha')
        ->assertDontSee('Beta');
});

it('the index route returns the component', function () {
    $this->actingAs($this->superAdmin)
        ->get('/super-admin/associations')
        ->assertOk()
        ->assertSeeLivewire(AssociationsList::class);
});
