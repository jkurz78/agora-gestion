<?php

declare(strict_types=1);

use App\Enums\Civilite;
use App\Livewire\TiersForm;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('permet de saisir une civilité à la création', function (): void {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', 'Kurz')
        ->set('prenom', 'Anne')
        ->set('pour_recettes', true)
        ->set('civilite', 'Mme')
        ->call('save');

    $tiers = Tiers::where('nom', 'Kurz')->first();
    expect($tiers)->not->toBeNull()
        ->and($tiers->civilite)->toBe(Civilite::Mme);
});

it('persiste null si civilite vide', function (): void {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'particulier')
        ->set('nom', 'TestSansCivilite')
        ->set('prenom', 'Sans')
        ->set('pour_recettes', true)
        ->set('civilite', '')
        ->call('save');

    $tiers = Tiers::where('nom', 'TestSansCivilite')->first();
    expect($tiers)->not->toBeNull()
        ->and($tiers->civilite)->toBeNull();
});

it('pré-remplit la civilité en édition', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'civilite' => 'M.',
        'pour_recettes' => true,
    ]);

    Livewire::test(TiersForm::class)
        ->call('edit', $tiers->id)
        ->assertSet('civilite', 'M.');
});

it('ne propose pas le champ civilité pour un tiers entreprise dans la vue', function (): void {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('type', 'entreprise')
        ->assertDontSeeHtml('id="civilite"');
});

it('réinitialise la civilité après resetForm', function (): void {
    Livewire::test(TiersForm::class)
        ->call('showNewForm')
        ->set('civilite', 'Mme')
        ->call('resetForm')
        ->assertSet('civilite', null);
});

it('réinitialise la civilité après showNewForm', function (): void {
    Livewire::test(TiersForm::class)
        ->set('civilite', 'Mme')
        ->call('showNewForm')
        ->assertSet('civilite', null);
});
