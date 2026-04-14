<?php

declare(strict_types=1);

use App\Livewire\Parametres\AssociationForm;
use App\Models\Association;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('displays email_from fields', function () {
    Livewire::test(AssociationForm::class)
        ->assertSee('Adresse d\'expédition')
        ->assertSeeHtml('placeholder="Nom expéditeur"')
        ->assertSeeHtml('placeholder="noreply@monasso.fr"');
});

it('saves email_from and email_from_name', function () {
    Livewire::test(AssociationForm::class)
        ->set('nom', 'Test Association')
        ->set('email_from', 'noreply@asso.fr')
        ->set('email_from_name', 'Mon Association')
        ->call('save');

    $assoc = Association::find(1);
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
    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Test', 'email_from' => 'existing@asso.fr', 'email_from_name' => 'Existing'])->save();

    Livewire::test(AssociationForm::class)
        ->assertSet('email_from', 'existing@asso.fr')
        ->assertSet('email_from_name', 'Existing');
});
