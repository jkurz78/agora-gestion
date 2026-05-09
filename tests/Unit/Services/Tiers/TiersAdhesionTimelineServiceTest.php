<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\Tiers\DTO\AdhesionLigneDTO;
use App\Services\Tiers\DTO\AdhesionTimelineDTO;
use App\Services\Tiers\TiersAdhesionTimelineService;
use App\Tenant\TenantContext;

it('groupe les adhésions par ordre exercice desc puis id desc', function (): void {
    $tiers = Tiers::factory()->create();

    $oldest = Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2023]);
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2024]);
    $latest = Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2025]);

    $dto = app(TiersAdhesionTimelineService::class)->forTiers($tiers);

    expect($dto)->toBeInstanceOf(AdhesionTimelineDTO::class);
    expect($dto->lignes[0])->toBeInstanceOf(AdhesionLigneDTO::class);
    // Premier exercice doit être 2025
    expect($dto->lignes[0]->adhesion->exercice)->toBe(2025);
    // L'id le plus récent (exercice 2025) passe en premier
    expect($dto->lignes[0]->adhesion->id)->toBe((int) $latest->id);
    // Dernier est le plus ancien exercice
    expect($dto->lignes[2]->adhesion->id)->toBe((int) $oldest->id);
});

it('compte le total correct', function (): void {
    $tiers = Tiers::factory()->create();

    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2023]);
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2024]);
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2025]);

    $dto = app(TiersAdhesionTimelineService::class)->forTiers($tiers);

    expect($dto->totalCount)->toBe(3);
    expect($dto->lignes)->toHaveCount(3);
});

it('ne remonte pas les adhésions d\'un tiers d\'une autre association', function (): void {
    $tiers = Tiers::factory()->create();

    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2024]);
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'exercice' => 2025]);

    // Switch tenant to another association
    TenantContext::clear();
    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    $dto = app(TiersAdhesionTimelineService::class)->forTiers($tiers);

    expect($dto->totalCount)->toBe(0);
});

it('eager-load la transaction et son compte sur les adhésions payées', function (): void {
    $tiers = Tiers::factory()->create();

    // Adhésion payée (avec transaction)
    $adhesion = Adhesion::factory()->payee()->create(['tiers_id' => $tiers->id]);

    $dto = app(TiersAdhesionTimelineService::class)->forTiers($tiers);

    expect($dto->totalCount)->toBe(1);
    $ligne = $dto->lignes[0];
    // La transaction doit être chargée (pas de requête supplémentaire)
    expect($ligne->adhesion->relationLoaded('transaction'))->toBeTrue();
    expect($ligne->adhesion->transaction)->not->toBeNull();
    // Le compte de la transaction doit aussi être chargé
    expect($ligne->adhesion->transaction->relationLoaded('compte'))->toBeTrue();
});
