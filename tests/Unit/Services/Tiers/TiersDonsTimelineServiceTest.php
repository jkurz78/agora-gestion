<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\DonsTimelineDTO;
use App\Services\Tiers\TiersDonsTimelineService;

it('groupe les dons par année civile, ordre desc', function (): void {
    $tiers = Tiers::factory()->create();
    $sousCat = SousCategorie::factory()->create();
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    // Don 2024 : 100€
    $tx2024 = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'date' => '2024-06-15',
        'statut_reglement' => StatutReglement::Recu->value,
        'type' => \App\Enums\TypeTransaction::Recette->value,
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
        'type' => \App\Enums\TypeTransaction::Recette->value,
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
        'type' => \App\Enums\TypeTransaction::Recette->value,
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
