<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Livewire\FactureEdit;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);
    $this->user->update(['derniere_association_id' => $this->association->id]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->exercice = app(ExerciceService::class)->current();

    $this->tiers = Tiers::factory()->pourRecettes()->create([
        'association_id' => $this->association->id,
    ]);

    $this->facture = Facture::create([
        'association_id' => $this->association->id,
        'numero' => null,
        'date' => now()->toDateString(),
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 0,
        'exercice' => $this->exercice,
        'saisi_par' => $this->user->id,
    ]);

    $this->categorie = Categorie::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'recette',
    ]);

    $this->sousCategorie = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorie->id,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── Test 1 : Bouton "Ajouter ligne facture" présent ssi facture brouillon ──────

it('affiche le bouton Ajouter ligne facture ssi facture brouillon', function (): void {
    // Brouillon → bouton présent
    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertSee('Ajouter ligne facture');

    // Validée → redirige, le bouton n'est pas visible
    $this->facture->update([
        'statut' => StatutFacture::Validee,
        'numero' => 'F-'.$this->exercice.'-0001',
        'montant_total' => 0.00,
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertDontSee('Ajouter ligne facture');
});

// ── Test 2 : Submit ligne libre montant → ligne MontantManuel, montant=2400, total=2400 ──

it('ajouterLigneManuelle crée une ligne MontantManuel et recalcule le total', function (): void {
    $countBefore = FactureLigne::count();

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->set('nouvelleLigneMontantLibelle', 'Mission audit')
        ->set('nouvelleLigneMontantPrixUnitaire', '800')
        ->set('nouvelleLigneMontantQuantite', '3')
        ->set('nouvelleLigneMontantSousCategorieId', $this->sousCategorie->id)
        ->call('ajouterLigneManuelle')
        ->assertHasNoErrors();

    expect(FactureLigne::count())->toBe($countBefore + 1);

    $ligne = FactureLigne::where('facture_id', $this->facture->id)->first();
    expect($ligne->type)->toBe(TypeLigneFacture::MontantManuel);
    expect((float) $ligne->montant)->toBe(2400.0);

    $this->facture->refresh();
    expect((float) $this->facture->montant_total)->toBe(2400.0);
});

// ── Test 3 : Submit ligne texte → ligne Texte, total inchangé ───────────────

it('ajouterLigneTexteManuelle crée une ligne Texte sans modifier le total', function (): void {
    // D'abord, ajouter une ligne MontantManuel pour avoir un total de référence
    $component = Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->set('nouvelleLigneMontantLibelle', 'Prestation de base')
        ->set('nouvelleLigneMontantPrixUnitaire', '500')
        ->set('nouvelleLigneMontantQuantite', '1')
        ->set('nouvelleLigneMontantSousCategorieId', $this->sousCategorie->id)
        ->call('ajouterLigneManuelle');

    $this->facture->refresh();
    $totalAvant = (float) $this->facture->montant_total;
    expect($totalAvant)->toBe(500.0);

    $countBefore = FactureLigne::count();

    // Ajouter une ligne texte
    $component
        ->set('nouvelleLigneTexteLibelle', 'Détail de la prestation')
        ->call('ajouterLigneTexteManuelle')
        ->assertHasNoErrors();

    expect(FactureLigne::count())->toBe($countBefore + 1);

    $lignTexte = FactureLigne::where('facture_id', $this->facture->id)
        ->where('type', TypeLigneFacture::Texte)
        ->first();
    expect($lignTexte)->not->toBeNull();
    expect($lignTexte->montant)->toBeNull();

    // Total inchangé (la ligne texte n'a pas de montant)
    $this->facture->refresh();
    expect((float) $this->facture->montant_total)->toBe(500.0);
});

// ── Test 4 : PU=0 → erreur Livewire visible, pas de ligne créée ─────────────

it('ajouterLigneManuelle avec PU=0 affiche une erreur et ne crée pas de ligne', function (): void {
    $countBefore = FactureLigne::count();

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->set('nouvelleLigneMontantLibelle', 'Mission')
        ->set('nouvelleLigneMontantPrixUnitaire', '0')
        ->set('nouvelleLigneMontantQuantite', '1')
        ->call('ajouterLigneManuelle')
        ->assertHasErrors(['nouvelleLigneMontantPrixUnitaire']);

    expect(FactureLigne::count())->toBe($countBefore);
});

// ── Test 5 : Régression flow Montant ref — bouton existant présent et non cassé ──

it('le bouton Ajouter ligne Montant ref est présent et toggleTransaction fonctionne', function (): void {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $transaction = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 150.00,
        'compte_id' => $compte->id,
        'date' => now(),
    ]);

    // La section "Transactions disponibles" (flow Montant ref) est présente
    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertSee('Transactions disponibles')
        ->call('toggleTransaction', $transaction->id);

    expect($this->facture->fresh()->transactions()->count())->toBe(1);
    expect(FactureLigne::where('facture_id', $this->facture->id)
        ->where('type', TypeLigneFacture::Montant)
        ->count())->toBeGreaterThan(0);
});

// ── Test 6 : Facture validée → boutons "Ajouter ligne facture" et "Ajouter ligne texte" absents ──

it('une facture validée ne montre ni le bouton Ajouter ligne facture ni Ajouter ligne texte', function (): void {
    $this->facture->update([
        'statut' => StatutFacture::Validee,
        'numero' => 'F-'.$this->exercice.'-0002',
        'montant_total' => 100.00,
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertDontSee('Ajouter ligne facture')
        ->assertDontSee('Ajouter ligne texte');
});

// ── Test 7 : Mix Montant ref + MontantManuel + Texte → lignes des 3 types, total cohérent ──

it('mix Montant ref + MontantManuel + Texte donne les 3 types et un total cohérent', function (): void {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $transaction = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'montant_total' => 200.00,
        'compte_id' => $compte->id,
        'date' => now(),
    ]);

    $component = Livewire::test(FactureEdit::class, ['facture' => $this->facture]);

    // Ajouter ligne(s) Montant ref via toggleTransaction (1-N lignes selon TransactionFactory)
    $component->call('toggleTransaction', $transaction->id);

    // Ajouter ligne MontantManuel
    $component
        ->set('nouvelleLigneMontantLibelle', 'Frais annexes')
        ->set('nouvelleLigneMontantPrixUnitaire', '100')
        ->set('nouvelleLigneMontantQuantite', '1')
        ->set('nouvelleLigneMontantSousCategorieId', $this->sousCategorie->id)
        ->call('ajouterLigneManuelle');

    // Ajouter ligne Texte
    $component
        ->set('nouvelleLigneTexteLibelle', 'Commentaire')
        ->call('ajouterLigneTexteManuelle');

    $lignes = FactureLigne::where('facture_id', $this->facture->id)->get();

    // Au moins 1 ligne de chaque type
    expect($lignes->where('type', TypeLigneFacture::Montant)->count())->toBeGreaterThanOrEqual(1);
    expect($lignes->where('type', TypeLigneFacture::MontantManuel)->count())->toBe(1);
    expect($lignes->where('type', TypeLigneFacture::Texte)->count())->toBe(1);

    // Total = somme des montants non-null (Montant ref + MontantManuel 100)
    $this->facture->refresh();
    expect((float) $this->facture->montant_total)->toBeGreaterThan(0.0);

    $totalAttendu = (float) $lignes->whereNotNull('montant')->sum('montant');
    expect((float) $this->facture->montant_total)->toBe($totalAttendu);
});
