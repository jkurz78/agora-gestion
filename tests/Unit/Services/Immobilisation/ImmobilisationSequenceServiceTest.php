<?php

declare(strict_types=1);

use App\Models\Association;
use App\Services\Immobilisation\ImmobilisationSequenceService;
use App\Tenant\TenantContext;

it('produit des numéros consécutifs formatés sur 5 chiffres', function (): void {
    $service = app(ImmobilisationSequenceService::class);

    expect($service->prochain())->toBe('IM00001')
        ->and($service->prochain())->toBe('IM00002')
        ->and($service->prochain())->toBe('IM00003');
});

it('cloisonne la séquence par tenant', function (): void {
    $service = app(ImmobilisationSequenceService::class);

    expect($service->prochain())->toBe('IM00001')
        ->and($service->prochain())->toBe('IM00002');

    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    expect($service->prochain())->toBe('IM00001');
});
