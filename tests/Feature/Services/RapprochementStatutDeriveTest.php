<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\RapprochementBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Services\RapprochementBancaireService;
use App\Services\RemiseBancaireService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesPartieDoubleContext;

uses(RefreshDatabase::class, CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

it('pointage d\'une recette virement → statut dérivé Pointe ; dépointage → Recu', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Virement à pointer',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $service = app(RapprochementBancaireService::class);
    $rappro = $service->create($this->compteBancaire, '2025-10-31', 1100.00);

    $service->toggleTransaction($rappro, 'recette', (int) $t1->id);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Pointe);

    $service->toggleTransaction($rappro->fresh(), 'recette', (int) $t1->id);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('comptabilisation d\'une remise chèque → statut miroir passe EnMain à Recu', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Chèque à remettre',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnMain);

    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 3001, 'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise statut test', 'saisi_par' => $this->user->id,
    ]);
    app(RemiseBancaireService::class)->comptabiliser($remise, [(int) $t1->id]);

    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('supprimer un rapprochement → le statut dérivé de la source repasse Recu (pas Pointe)', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Virement pointé puis rappro supprimé',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $rappro = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'date_fin' => '2025-10-31',
    ]);
    $service = app(RapprochementBancaireService::class);
    $service->toggleTransaction($rappro, 'recette', (int) $t1->id);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Pointe);

    $service->supprimer($rappro);

    // 512X présent mais plus rapproché → dénoué (Recu), surtout PAS Pointe.
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});

it('pointer une remise chèque → sources dérivées Pointe ; dépointer → Recu', function () {
    $t1 = app(TransactionService::class)->create([
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Chèque remis puis remise pointée',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'tiers_id' => $this->tiers->id,
        'compte_id' => $this->compteBancaire->id,
    ], [[
        'sous_categorie_id' => $this->sc706->id,
        'montant' => '100.00',
        'operation_id' => null, 'seance' => null, 'notes' => null,
    ]]);

    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 4001, 'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise toggle test', 'saisi_par' => $this->user->id,
    ]);
    app(RemiseBancaireService::class)->comptabiliser($remise, [(int) $t1->id]);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);

    $rappro = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'date_fin' => '2025-10-31',
    ]);
    $service = app(RapprochementBancaireService::class);

    $service->toggleTransaction($rappro, 'remise', (int) $remise->id);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Pointe);

    $service->toggleTransaction($rappro, 'remise', (int) $remise->id);
    expect($t1->fresh()->statut_reglement)->toBe(StatutReglement::Recu);
});
