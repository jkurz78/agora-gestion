<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $catRecette = \App\Models\Categorie::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'recette',
    ]);
    $this->scRecette = SousCategorie::factory()->create([
        'categorie_id' => $catRecette->id,
        'association_id' => $this->association->id,
    ]);
});

afterEach(fn () => TenantContext::clear());

test('une recette accepte une PJ header au save', function () {
    Storage::fake('local');

    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('date', '2025-10-15')
        ->set('mode_paiement', 'cheque')
        ->set('compte_id', $this->compte->id)
        ->set('lignes.0.sous_categorie_id', (string) $this->scRecette->id)
        ->set('lignes.0.montant', '100.00')
        ->set('pieceJointe', UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf'))
        ->call('save');

    $component->assertHasNoErrors();
    $tx = Transaction::latest()->first();
    expect($tx->piece_jointe_path)->not->toBeNull();
});

test('le filtre tiers ne restreint plus par type — un tiers depenses-only est proposé en saisie recette', function () {
    $tiers = \App\Models\Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Fournisseur Unique',
        'pour_depenses' => true,
        'pour_recettes' => false,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette');

    $html = $component->html();
    expect($html)->not->toContain('filtre="recettes"');
    expect($html)->not->toContain('filtre="depenses"');
});
