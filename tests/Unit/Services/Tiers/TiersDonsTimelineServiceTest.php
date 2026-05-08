<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\DonsTimelineDTO;
use App\Services\Tiers\TiersDonsTimelineService;
use App\Tenant\TenantContext;

it('groupe les dons par année civile, ordre desc', function (): void {
    $tiers = Tiers::factory()->create();
    $sousCat = SousCategorie::factory()->create();
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    // Don 2024 : 100€
    $tx2024 = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2024-06-15',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx2024->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 100,
    ]);

    // 2 dons 2025 : 50€ + 30€
    $tx2025a = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-03-10',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx2025a->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50,
    ]);

    $tx2025b = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-09-20',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx2025b->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 30,
    ]);

    $service = app(TiersDonsTimelineService::class);
    $dto = $service->forTiers($tiers);

    expect($dto)->toBeInstanceOf(DonsTimelineDTO::class);
    expect(array_keys($dto->annees))->toBe([2025, 2024]);
    expect($dto->annees[2025]->count)->toBe(2);
    expect((float) $dto->annees[2025]->total)->toBe(80.0);
    expect($dto->annees[2024]->count)->toBe(1);
    expect((float) $dto->annees[2024]->total)->toBe(100.0);
    expect($dto->totalCount)->toBe(3);
    expect((float) $dto->totalMontant)->toBe(180.0);
});

it('filtre les dons par année civile', function (): void {
    $tiers = Tiers::factory()->create();
    $sousCat = SousCategorie::factory()->create();
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx2024 = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2024-06-15',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx2024->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 100,
    ]);

    $tx2025 = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-03-10',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx2025->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50,
    ]);

    $dto = app(TiersDonsTimelineService::class)->forTiers($tiers, 2025);

    expect(array_keys($dto->annees))->toBe([2025]);
    expect($dto->totalCount)->toBe(1);
});

it('détecte l\'alerte helloasso sur un don importé', function (): void {
    $tiers = Tiers::factory()->create();
    $sousCat = SousCategorie::factory()->create();
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-03-10',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
        'helloasso_payment_id' => 123456,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50,
    ]);

    $dto = app(TiersDonsTimelineService::class)->forTiers($tiers);
    $ligne = $dto->annees[2025]->lignes[0];

    expect($ligne->alertes)->toContain('helloasso');
});

it('bloque le téléchargement si l\'adresse du tiers est incomplète', function (): void {
    // Association must be eligible with signataire set to reach the address check
    $asso = Association::find(TenantContext::currentId());
    $asso->update([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Dupont',
        'signataire_qualite' => 'Président',
    ]);

    $tiers = Tiers::factory()->create([
        'adresse_ligne1' => null,
        'code_postal' => '69000',
        'ville' => 'Lyon',
    ]);
    $sousCat = SousCategorie::factory()->create();
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-03-10',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50,
    ]);

    $dto = app(TiersDonsTimelineService::class)->forTiers($tiers);
    $ligne = $dto->annees[2025]->lignes[0];

    expect($ligne->peutTelecharger)->toBeFalse();
    expect($ligne->raisonBlocage)->toBe('Adresse du donateur incomplète');
});

it('expose un raisonBlocageGlobal si signataire de l\'asso est absent', function (): void {
    $asso = Association::find(TenantContext::currentId());
    $asso->update([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => null,
        'signataire_qualite' => null,
    ]);

    $tiers = Tiers::factory()->create();

    $dto = app(TiersDonsTimelineService::class)->forTiers($tiers);

    expect($dto->raisonBlocageGlobal)->toContain('signataire');
});

it('ne remonte pas les dons d\'un tiers d\'une autre association', function (): void {
    $tiers = Tiers::factory()->create();
    $sousCat = SousCategorie::factory()->create();
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2025-03-10',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50,
    ]);

    // Switch tenant context to another association
    TenantContext::clear();
    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    $dto = app(TiersDonsTimelineService::class)->forTiers($tiers);

    // The Tiers global scope is fail-closed across tenants, so calling
    // forTiers with a "foreign" Tiers instance must not leak its dons.
    expect($dto->totalCount)->toBe(0);
});
