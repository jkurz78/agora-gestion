<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\AssociationDetail;
use App\Models\Association;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->asso = Association::factory()->create(['slug' => 'mon-asso']);
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
});

it('rejects a reserved slug on edit', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'admin')
        ->call('saveSlug')
        ->assertHasErrors(['newSlug']);

    expect($this->asso->fresh()->slug)->toBe('mon-asso');
});

it('accepts a non-reserved slug on edit', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $this->asso])
        ->call('openSlugEditor')
        ->set('newSlug', 'nouvelle')
        ->call('saveSlug')
        ->assertHasNoErrors(['newSlug']);

    expect($this->asso->fresh()->slug)->toBe('nouvelle');
});
