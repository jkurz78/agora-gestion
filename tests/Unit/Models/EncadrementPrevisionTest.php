<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Categorie;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('persiste un montant prévu par (operation, tiers, sous-catégorie, séance)', function (): void {
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();
    $categorie = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorie->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => now()]);

    $prevision = EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 150.50,
    ]);

    expect($prevision->fresh()->montant_prevu)->toEqual('150.50')
        ->and($prevision->association_id)->toBe($this->association->id);
});

it('exclut les prévisions des autres associations via le scope global', function (): void {
    $autre = Association::factory()->create();
    $operation = Operation::factory()->for($autre, 'association')->create();
    $tiers = Tiers::factory()->for($autre, 'association')->create();
    $categorie = Categorie::factory()->depense()->for($autre, 'association')->create();
    $sc = SousCategorie::factory()->for($autre, 'association')->create(['categorie_id' => $categorie->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => now()]);

    TenantContext::boot($autre);
    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 99,
    ]);

    TenantContext::boot($this->association);
    expect(EncadrementPrevision::count())->toBe(0);
});

it('rejette deux prévisions pour le même (operation, tiers, sous-cat, séance)', function (): void {
    $operation = Operation::factory()->create();
    $tiers = Tiers::factory()->create();
    $categorie = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorie->id]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => now()]);

    EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 100,
    ]);

    expect(fn () => EncadrementPrevision::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 200,
    ]))->toThrow(QueryException::class);
});
