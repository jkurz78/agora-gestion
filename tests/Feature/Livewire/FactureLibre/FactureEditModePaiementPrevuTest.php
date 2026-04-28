<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutDevis;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Livewire\FactureEdit;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
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

    $this->categorie = Categorie::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'recette',
    ]);

    $this->sousCategorie = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $this->categorie->id,
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
});

afterEach(function (): void {
    TenantContext::clear();
});

// ── Test 1 : Facture sans MontantLibre → champ mode_paiement_prevu absent du DOM ──

it('le champ mode_paiement_prevu est absent du DOM si aucune ligne MontantLibre', function (): void {
    // Pas de ligne du tout
    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertDontSeeHtml('name="modePaiementPrevu"');

    // Ligne Texte uniquement → pas de MontantLibre
    FactureLigne::create([
        'facture_id' => $this->facture->id,
        'type' => TypeLigneFacture::Texte,
        'libelle' => 'Commentaire',
        'montant' => null,
        'transaction_ligne_id' => null,
        'ordre' => 1,
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertDontSeeHtml('name="modePaiementPrevu"');
});

// ── Test 2 : Facture avec ≥ 1 MontantLibre → champ mode_paiement_prevu présent ──

it('le champ mode_paiement_prevu est présent dans le DOM si au moins une ligne MontantLibre', function (): void {
    FactureLigne::create([
        'facture_id' => $this->facture->id,
        'type' => TypeLigneFacture::MontantLibre,
        'libelle' => 'Prestation',
        'prix_unitaire' => 100.00,
        'quantite' => 1,
        'montant' => 100.00,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $this->sousCategorie->id,
        'ordre' => 1,
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertSeeHtml('name="modePaiementPrevu"');
});

// ── Test 3 : Sélection d'un mode → persistance sur la facture ────────────────

it('updatedModePaiementPrevu persiste le mode de paiement sur la facture', function (): void {
    FactureLigne::create([
        'facture_id' => $this->facture->id,
        'type' => TypeLigneFacture::MontantLibre,
        'libelle' => 'Prestation',
        'prix_unitaire' => 200.00,
        'quantite' => 1,
        'montant' => 200.00,
        'transaction_ligne_id' => null,
        'sous_categorie_id' => $this->sousCategorie->id,
        'ordre' => 1,
    ]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->set('modePaiementPrevu', ModePaiement::Virement->value)
        ->assertHasNoErrors();

    expect($this->facture->fresh()->mode_paiement_prevu)->toBe(ModePaiement::Virement);
});

// ── Test 4 : Mention "Issue du devis" présente ssi devis_id non-null ─────────

it('affiche la mention Issue du devis si devis_id est renseigné', function (): void {
    // Sans devis → mention absente
    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertDontSee('Issue du devis');

    // Créer un devis puis lier la facture
    $devis = Devis::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'statut' => StatutDevis::Accepte,
        'numero' => 'D-'.$this->exercice.'-0001',
    ]);

    $this->facture->update(['devis_id' => $devis->id]);

    Livewire::test(FactureEdit::class, ['facture' => $this->facture])
        ->assertSee('Issue du devis');
});
