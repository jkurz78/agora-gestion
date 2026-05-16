<?php

declare(strict_types=1);

use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Services\Portail\Providers\NotesDeFraisProvider;

it('returns DTO when pour_depenses=true and no NDF', function (): void {
    $tiers = Tiers::factory()->create(['pour_depenses' => true]);
    $provider = new NotesDeFraisProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull();
});

it('returns DTO when pour_depenses=false but has at least one NDF', function (): void {
    $tiers = Tiers::factory()->create(['pour_depenses' => false]);
    NoteDeFrais::factory()->create(['tiers_id' => $tiers->id]);
    $provider = new NotesDeFraisProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull();
});

it('returns null when pour_depenses=false and no NDF', function (): void {
    $tiers = Tiers::factory()->create(['pour_depenses' => false]);
    $provider = new NotesDeFraisProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->toBeNull();
});

it('returns correct DTO values', function (): void {
    $tiers = Tiers::factory()->create(['pour_depenses' => true]);
    $provider = new NotesDeFraisProvider;

    $dto = $provider->resolve($tiers);

    expect($dto)->not->toBeNull()
        ->and($dto->id)->toBe('notes-de-frais')
        ->and($dto->label)->toBe('Notes de frais')
        ->and($dto->routeName)->toBe('portail.ndf.index')
        ->and($dto->icon)->toBe('bi-receipt')
        ->and($dto->ordre)->toBe(90)
        ->and($dto->groupe)->toBe('Mes frais & factures')
        ->and($dto->visible)->toBeTrue()
        ->and($dto->badge)->toBeNull();
});
