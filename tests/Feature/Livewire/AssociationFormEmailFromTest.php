<?php

declare(strict_types=1);

use App\Livewire\Parametres\AssociationForm;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('displays email_from fields', function () {
    Livewire::test(AssociationForm::class)
        ->assertSeeHtml('Adresse d\'expédition')
        ->assertSeeHtml('placeholder="Nom expéditeur"')
        ->assertSeeHtml('placeholder="noreply@monasso.fr"');
});

it('saves email_from and email_from_name', function () {
    Livewire::test(AssociationForm::class)
        ->set('nom', 'Test Association')
        ->set('email_from', 'noreply@asso.fr')
        ->set('email_from_name', 'Mon Association')
        ->call('save');

    $assoc = $this->association->fresh();
    expect($assoc->email_from)->toBe('noreply@asso.fr')
        ->and($assoc->email_from_name)->toBe('Mon Association');
});

it('validates email_from is a valid email', function () {
    Livewire::test(AssociationForm::class)
        ->set('nom', 'Test')
        ->set('email_from', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['email_from']);
});

it('loads existing email_from on mount', function () {
    $this->association->update(['email_from' => 'existing@asso.fr', 'email_from_name' => 'Existing']);

    Livewire::test(AssociationForm::class)
        ->assertSet('email_from', 'existing@asso.fr')
        ->assertSet('email_from_name', 'Existing');
});
