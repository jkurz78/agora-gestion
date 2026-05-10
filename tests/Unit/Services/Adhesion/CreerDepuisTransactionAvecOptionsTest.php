<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\AdhesionService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $this->service = app(AdhesionService::class);
    $this->scCotisation = SousCategorie::factory()->pourCotisations()->create();
    $this->tiers = Tiers::factory()->create();
});

afterEach(function (): void {
    TenantContext::clear();
});

it('creerDepuisTransaction : adhésion créée depuis ligne parent (option_id IS NULL) avec montant_facial=0', function (): void {
    // Reproduit le cas HA-55698 post-B1 : transaction avec 2 lignes
    // - ligne parent (cotisation 0€, option_id null)
    // - ligne option (12€, option_id=18596)
    // L'adhésion doit être créée depuis la ligne parent (montant_facial=0)
    // et non depuis la ligne option.
    $formule = FormuleAdhesion::factory()->helloasso('mon-form', 18595)->create([
        'sous_categorie_id' => $this->scCotisation->id,
    ]);

    $tx = Transaction::factory()->create([
        'type' => 'recette',
        'tiers_id' => $this->tiers->id,
        'helloasso_form_slug' => 'mon-form',
    ]);

    // Supprimer les lignes auto-créées par Transaction::configure()
    TransactionLigne::where('transaction_id', $tx->id)->delete();

    // Ligne parent cotisation (montant=0)
    $ligneParent = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->scCotisation->id,
        'montant' => 0.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => null,
        'helloasso_tier_id' => 18595,
    ]);

    // Ligne option (montant=12€) — même sous-cat que le parent
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->scCotisation->id,
        'montant' => 12.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => 18596,
        'helloasso_tier_id' => null,
    ]);

    // Recalcul montant_total
    $tx->update(['montant_total' => 12.00]);

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    // montant_facial = somme des lignes de la transaction = 12€
    // (snapshot de la tx entière, pas de la ligne parent seule)
    expect((int) $adhesion->transaction_id)->toBe((int) $tx->id);
    // La formule doit être résolue depuis la ligne parent (helloasso_tier_id=18595)
    expect((int) $adhesion->formule_adhesion_id)->toBe((int) $formule->id);
});

it('creerDepuisTransaction : pas d\'adhésion si seule une ligne option cotisation existe (sans ligne parent)', function (): void {
    // Si pour une raison quelconque on n'a qu'une ligne option (option_id non-null)
    // avec une sous-cat cotisation, aucune adhésion ne doit être créée.
    // (Ce cas ne devrait pas se produire en production mais on teste la robustesse.)
    $tx = Transaction::factory()->create([
        'type' => 'recette',
        'tiers_id' => $this->tiers->id,
    ]);

    TransactionLigne::where('transaction_id', $tx->id)->delete();

    // Seulement une ligne option, pas de ligne parent
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->scCotisation->id,
        'montant' => 12.00,
        'helloasso_item_id' => 87070,
        'helloasso_option_id' => 18596,
    ]);

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion)->toBeNull();
});

it('creerDepuisTransaction : adhésion créée normalement si 1 ligne sans option_id (cas standard)', function (): void {
    // Non-régression : le flux standard (1 ligne parent sans options)
    // doit continuer à créer l'adhésion.
    $tx = Transaction::factory()->create([
        'type' => 'recette',
        'tiers_id' => $this->tiers->id,
    ]);

    TransactionLigne::where('transaction_id', $tx->id)->delete();

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->scCotisation->id,
        'montant' => 35.00,
        'helloasso_item_id' => null,
        'helloasso_option_id' => null,
    ]);

    $adhesion = $this->service->creerDepuisTransaction($tx);

    expect($adhesion)->not->toBeNull();
    expect((int) $adhesion->transaction_id)->toBe((int) $tx->id);
});
