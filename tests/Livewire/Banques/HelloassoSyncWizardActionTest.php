<?php

declare(strict_types=1);

use App\Enums\HelloAssoEnvironnement;
use App\Livewire\Banques\HelloassoSyncWizard;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $association = Association::firstOrCreate(['id' => 1], [
        'nom' => 'Asso test',
        'slug' => 'test-asso',
    ]);
    TenantContext::boot($association);

    $this->user = User::factory()->create();
    $this->user->associations()->attach(1, ['role' => 'admin', 'joined_at' => now()]);
    $this->scCotisation = SousCategorie::factory()->pourCotisations()->create();

    $compte = CompteBancaire::factory()->create();
    $this->parametres = HelloAssoParametres::factory()->create([
        'association_id' => 1,
        'environnement' => HelloAssoEnvironnement::Sandbox,
        'client_id' => 'cid',
        'client_secret' => 'csecret',
        'organisation_slug' => 'mon-asso',
        'compte_helloasso_id' => $compte->id,
        'compte_versement_id' => $compte->id,
    ]);

    $this->formMembership = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'cotisation-2025',
        'form_type' => 'Membership',
        'form_title' => 'Cotisation 2025',
    ]);
});

it('mettreAJourAction sauvegarde "ignore" sur le form mapping', function (): void {
    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->call('mettreAJourAction', $this->formMembership->id, 'ignore')
        ->assertSet("formActions.{$this->formMembership->id}", 'ignore');
});

it('sauvegarderEtSuite persiste ignore sur le form mapping', function (): void {
    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->set('formsLoaded', true) // skip loadFormulaires
        ->call('mettreAJourAction', $this->formMembership->id, 'ignore')
        ->call('sauvegarderEtSuite');

    $this->formMembership->refresh();
    expect($this->formMembership->ignore)->toBeTrue();
    expect($this->formMembership->sous_categorie_id)->toBeNull();
    expect($this->formMembership->operation_id)->toBeNull();
});

it('sauvegarderEtSuite persiste souscat: pour Membership', function (): void {
    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->set('formsLoaded', true)
        ->call('mettreAJourAction', $this->formMembership->id, 'souscat:'.$this->scCotisation->id)
        ->call('sauvegarderEtSuite');

    $this->formMembership->refresh();
    expect($this->formMembership->ignore)->toBeFalse();
    expect($this->formMembership->sous_categorie_id)->toBe($this->scCotisation->id);
});

it('sauvegarderEtSuite persiste operation: pour Registration', function (): void {
    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);

    $formEvent = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'event-2025',
        'form_type' => 'Registration',
        'form_title' => 'Event 2025',
    ]);

    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->set('formsLoaded', true)
        ->call('mettreAJourAction', $formEvent->id, 'operation:'.$operation->id)
        ->call('sauvegarderEtSuite');

    $formEvent->refresh();
    expect($formEvent->operation_id)->toBe($operation->id);
    expect($formEvent->sous_categorie_id)->toBeNull();
});

it('étape 1 : sépare les forms Membership/Donation et Registration en 2 tableaux distincts', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'don-2025',
        'form_type' => 'Donation',
        'form_title' => 'Dons 2025',
    ]);
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'event-2025',
        'form_type' => 'Registration',
        'form_title' => 'Stage Reiki octobre',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->set('formsLoaded', true);

    $html = $component->html();
    expect($html)->toContain('Adhésions et dons')
        ->and($html)->toContain('Événements (inscriptions)')
        ->and($html)->toContain('Cotisation 2025') // Membership row
        ->and($html)->toContain('Dons 2025') // Donation row
        ->and($html)->toContain('Stage Reiki octobre'); // Registration row
});

it('étape 1 : seuls les forms Registration ont le bouton "créer opération"', function (): void {
    HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'event-2025',
        'form_type' => 'Registration',
        'form_title' => 'Stage Reiki',
    ]);

    $html = Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->set('formsLoaded', true)
        ->html();

    // Le bouton "Créer opération" n'a pas de texte distinctif → on cible son
    // titre + l'action openCreateOperation. Il existe au moins une occurrence
    // (le form Registration), pas plus pour le Membership de beforeEach.
    expect(substr_count($html, 'openCreateOperation'))->toBe(1);
});

it('un form imported_at est verrouillé : son action ne peut plus être changée', function (): void {
    $this->formMembership->update([
        'imported_at' => now(),
        'sous_categorie_id' => $this->scCotisation->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(HelloassoSyncWizard::class)
        ->set('formsLoaded', true)
        ->call('mettreAJourAction', $this->formMembership->id, 'ignore')
        ->call('sauvegarderEtSuite');

    $this->formMembership->refresh();
    expect($this->formMembership->imported_at)->not->toBeNull();
    expect($this->formMembership->ignore)->toBeFalse(); // PAS modifié — verrouillé
    expect($this->formMembership->sous_categorie_id)->toBe($this->scCotisation->id); // PAS modifié
});
