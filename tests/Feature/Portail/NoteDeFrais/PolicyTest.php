<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Gate;

// Policy tests use Gate::forUser($tiers) to check policy responses directly.
// The global Pest.php bootstrap boots a default association + TenantContext.

// ---------------------------------------------------------------------------
// 1. view — propriétaire
// ---------------------------------------------------------------------------

it('policy view: tiers propriétaire peut consulter sa NDF', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($tiers)->allows('view', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 2. view — tiers différent
// ---------------------------------------------------------------------------

it('policy view: tiers différent ne peut pas consulter la NDF', function () {
    $tiers = Tiers::factory()->create();
    $autreTiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($autreTiers)->denies('view', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 3. update — propriétaire
// ---------------------------------------------------------------------------

it('policy update: tiers propriétaire peut modifier sa NDF', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($tiers)->allows('update', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 4. update — tiers différent
// ---------------------------------------------------------------------------

it('policy update: tiers différent ne peut pas modifier la NDF', function () {
    $tiers = Tiers::factory()->create();
    $autreTiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($autreTiers)->denies('update', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 5. delete — propriétaire brouillon
// ---------------------------------------------------------------------------

it('policy delete: tiers propriétaire peut supprimer un brouillon', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($tiers)->allows('delete', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 6. delete — propriétaire NDF soumise (refus car statut ≠ Brouillon)
// ---------------------------------------------------------------------------

it('policy delete: propriétaire ne peut pas supprimer une NDF soumise', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->soumise()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($tiers)->denies('delete', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 7. delete — propriétaire NDF validée (refus)
// ---------------------------------------------------------------------------

it('policy delete: propriétaire ne peut pas supprimer une NDF validée', function () {
    $tiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->validee()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($tiers)->denies('delete', $ndf))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 8. delete — tiers différent (refus même si brouillon)
// ---------------------------------------------------------------------------

it('policy delete: tiers différent ne peut pas supprimer un brouillon appartenant à autrui', function () {
    $tiers = Tiers::factory()->create();
    $autreTiers = Tiers::factory()->create();
    $ndf = NoteDeFrais::factory()->brouillon()->create(['tiers_id' => $tiers->id]);

    expect(Gate::forUser($autreTiers)->denies('delete', $ndf))->toBeTrue();
});
