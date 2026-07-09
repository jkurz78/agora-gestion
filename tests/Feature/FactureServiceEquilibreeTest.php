<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\FactureService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    // sc706 et compte706 exposés par setupPartieDoubleContext()
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->service = app(FactureService::class);
});

// ---------------------------------------------------------------------------
// Scénario : après valider() avec lignes MontantManuel + PD active,
// la Transaction générée doit avoir equilibree = true
// ---------------------------------------------------------------------------

it('valider() avec lignes MontantManuel et PD active → Transaction.equilibree = true', function () {
    // Facture brouillon avec 1 ligne MontantManuel sur sc706 (mappée à compte706)
    $facture = Facture::create([
        'association_id' => $this->association->id,
        'date' => '2025-11-15',
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $this->tiers->id,
        'saisi_par' => $this->user->id,
        'exercice' => 2025,
        'montant_total' => 0,
        'mode_paiement_prevu' => ModePaiement::Virement->value,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel->value,
        'compte_id' => $this->compte706->id,
        'libelle' => 'Cotisation annuelle',
        'montant' => 150.00,
        'ordre' => 1,
    ]);

    $this->service->valider($facture);

    $tx = Transaction::latest('id')->first();
    expect($tx)->not->toBeNull();
    expect((bool) $tx->equilibree)->toBeTrue();
});

it('valider() sans lignes MontantManuel → aucune Transaction → equilibree non concerné', function () {
    // Facture sans ligne MontantManuel — genererTransactionDepuisLignesManuelles retourne null
    $txExistante = Transaction::factory()->asRecette()->create([
        'tiers_id' => $this->tiers->id,
        'montant_total' => 300.0,
    ]);

    $facture = $this->service->creer($this->tiers->id);
    $this->service->ajouterTransactions($facture, [$txExistante->id]);
    $facture->refresh();

    $countAvant = Transaction::count();
    $this->service->valider($facture);

    // Aucune nouvelle Transaction générée
    expect(Transaction::count())->toBe($countAvant);
});
