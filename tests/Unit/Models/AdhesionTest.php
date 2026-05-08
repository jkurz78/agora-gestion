<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Tenant\TenantContext;

it('persiste une adhésion payée avec sa transaction', function (): void {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->create();

    $adhesion = Adhesion::create([
        'association_id' => TenantContext::currentId(),
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'transaction_id' => $tx->id,
        'gratuite' => false,
    ]);

    expect($adhesion->fresh()->exercice)->toBe(2025);
    expect($adhesion->fresh()->gratuite)->toBeFalse();
    expect($adhesion->tiers->id)->toBe($tiers->id);
    expect($adhesion->transaction->id)->toBe($tx->id);
});

it('persiste une adhésion gratuite avec motif', function (): void {
    $tiers = Tiers::factory()->create();

    $adhesion = Adhesion::create([
        'association_id' => TenantContext::currentId(),
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'transaction_id' => null,
        'gratuite' => true,
        'motif_gratuite' => 'Membre d\'honneur',
    ]);

    expect($adhesion->fresh()->gratuite)->toBeTrue();
    expect($adhesion->fresh()->motif_gratuite)->toBe('Membre d\'honneur');
    expect($adhesion->transaction)->toBeNull();
});

it('respecte le scope tenant fail-closed', function (): void {
    $tiers = Tiers::factory()->create();
    Adhesion::create([
        'association_id' => TenantContext::currentId(),
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'gratuite' => true,
        'motif_gratuite' => 'Test',
    ]);

    TenantContext::clear();
    $autreAsso = Association::factory()->create();
    TenantContext::boot($autreAsso);

    expect(Adhesion::count())->toBe(0);
});

it('scope forExercice filtre correctement', function (): void {
    $tiers = Tiers::factory()->create();
    Adhesion::create([
        'association_id' => TenantContext::currentId(),
        'tiers_id' => $tiers->id,
        'exercice' => 2024,
        'gratuite' => true,
        'motif_gratuite' => '2024',
    ]);
    Adhesion::create([
        'association_id' => TenantContext::currentId(),
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
        'gratuite' => true,
        'motif_gratuite' => '2025',
    ]);

    expect(Adhesion::forExercice(2025)->count())->toBe(1);
    expect(Adhesion::forExercice(2024)->first()->motif_gratuite)->toBe('2024');
});
