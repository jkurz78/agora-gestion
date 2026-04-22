<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Livewire\TransactionUniverselle;
use App\Livewire\VirementInterneForm;
use App\Models\Association;
use App\Models\CompteBancaire;
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

    CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Compte courant test',
        'saisie_automatisee' => false,
        'actif_recettes_depenses' => true,
    ]);
    CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'HelloAsso test',
        'saisie_automatisee' => true,
        'actif_recettes_depenses' => true,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('saisieManuelle scope excludes HelloAsso and includes normal account', function () {
    $noms = CompteBancaire::saisieManuelle()->pluck('nom')->all();

    expect($noms)->toContain('Compte courant test')
        ->and($noms)->not->toContain('HelloAsso test');
});

it('TransactionUniverselle column filter lists ALL accounts including HelloAsso', function () {
    // Le sélecteur de filtre de la colonne "Compte" est un outil de
    // consultation — il doit inclure tous les comptes (dont HelloAsso)
    // pour permettre de filtrer sur les transactions HelloAsso.
    $html = Livewire::test(TransactionUniverselle::class)->html();

    expect($html)->toContain('Compte courant test')
        ->and($html)->toContain('HelloAsso test');
});

it('TransactionForm creation selector excludes HelloAsso', function () {
    // Le sélecteur de compte dans le formulaire de création/édition
    // de transaction doit exclure HelloAsso (saisie automatisée).
    $html = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->html();

    expect($html)->toContain('Compte courant test')
        ->and($html)->not->toContain('HelloAsso test');
});

it('VirementInterneForm selector does not list HelloAsso', function () {
    $html = Livewire::test(VirementInterneForm::class)
        ->dispatch('open-virement-form', id: null)
        ->html();

    expect($html)->toContain('Compte courant test')
        ->and($html)->not->toContain('HelloAsso test');
});
