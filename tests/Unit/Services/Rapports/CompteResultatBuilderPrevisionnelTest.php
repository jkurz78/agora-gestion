<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Categorie;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\ProjectionMatrix;
use App\Tenant\TenantContext;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->categorieDep = Categorie::factory()->depense()->create();
    $this->scDep = SousCategorie::factory()->create(['categorie_id' => $this->categorieDep->id, 'nom' => 'Encadrement']);

    $this->categorieRec = Categorie::factory()->recette()->create();
    $this->scRec = SousCategorie::factory()->create(['categorie_id' => $this->categorieRec->id, 'nom' => 'Cotisations']);

    $this->typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $this->scRec->id]);
    $this->operation = Operation::factory()->create([
        'type_operation_id' => $this->typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 5),
        'date_fin' => Carbon::create(2026, 9, 20),
    ]);

    $this->seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);

    $this->tiersEnc = Tiers::factory()->pourDepenses()->create();
    $this->tiersPart = Tiers::factory()->create();
    $this->participant = Participant::factory()->create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersPart->id,
    ]);
});

it('retourne previsions_charges quand previsionnel=true', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersEnc->id,
        'sous_categorie_id' => $this->scDep->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 200,
    ]);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        exercice: 2026,
        operationIds: [$this->operation->id],
        parSeances: true,
        parTiers: true,
        previsionnel: true,
    );

    expect($data)->toHaveKey('previsions_charges')
        ->and($data['previsions_charges'])->not->toBeEmpty();

    $total = collect($data['previsions_charges'])->sum(function ($cat) {
        return collect($cat['sous_categories'])->sum(fn ($sc) => $sc['total'] ?? $sc['montant'] ?? 0);
    });
    expect($total)->toBe(200.0);
});

it('retourne previsions_produits depuis les reglements', function (): void {
    Reglement::create([
        'participant_id' => $this->participant->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 80,
    ]);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        exercice: 2026,
        operationIds: [$this->operation->id],
        parSeances: true,
        parTiers: true,
        previsionnel: true,
    );

    expect($data)->toHaveKey('previsions_produits')
        ->and($data['previsions_produits'])->not->toBeEmpty();

    $total = collect($data['previsions_produits'])->sum(function ($cat) {
        return collect($cat['sous_categories'])->sum(fn ($sc) => $sc['total'] ?? $sc['montant'] ?? 0);
    });
    expect($total)->toBe(80.0);
});

it("n'expose pas previsions quand previsionnel=false (rétrocompat)", function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersEnc->id,
        'sous_categorie_id' => $this->scDep->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 200,
    ]);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        exercice: 2026,
        operationIds: [$this->operation->id],
        parSeances: true,
        parTiers: true,
        previsionnel: false,
    );

    expect($data)->not->toHaveKey('previsions_charges')
        ->and($data)->not->toHaveKey('previsions_produits');
});

it("filtre fail-closed les prévisions d'autres associations", function (): void {
    $autre = Association::factory()->create();
    TenantContext::boot($autre);
    $opAutre = Operation::factory()->create();
    $sAutre = Seance::create(['operation_id' => $opAutre->id, 'numero' => 1, 'date' => now()]);
    EncadrementPrevision::create([
        'operation_id' => $opAutre->id,
        'tiers_id' => Tiers::factory()->create()->id,
        'sous_categorie_id' => SousCategorie::factory()->create([
            'categorie_id' => Categorie::factory()->depense()->create()->id,
        ])->id,
        'seance_id' => $sAutre->id,
        'montant_prevu' => 9999,
    ]);

    TenantContext::boot($this->association);

    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersEnc->id,
        'sous_categorie_id' => $this->scDep->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 50,
    ]);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        exercice: 2026,
        operationIds: [$this->operation->id],
        parSeances: false,
        parTiers: false,
        previsionnel: true,
    );

    $total = collect($data['previsions_charges'])->sum(fn ($cat) => collect($cat['sous_categories'])->sum('montant'));
    expect($total)->toBe(50.0);
});

it("filtre fail-closed les previsions produits d'autres associations", function (): void {
    // Réglement valide dans notre asso
    Reglement::create([
        'participant_id' => $this->participant->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 60,
    ]);

    // Réglement dans une autre asso (ne devrait jamais remonter)
    $autre = Association::factory()->create();
    TenantContext::boot($autre);
    $typeOpAutre = TypeOperation::factory()->create([
        'sous_categorie_id' => SousCategorie::factory()->create([
            'categorie_id' => Categorie::factory()->recette()->create()->id,
        ])->id,
    ]);
    $opAutre = Operation::factory()->create([
        'type_operation_id' => $typeOpAutre->id,
        'date_debut' => Carbon::create(2026, 9, 5),
    ]);
    $sAutre = Seance::create(['operation_id' => $opAutre->id, 'numero' => 1, 'date' => now()]);
    $tAutre = Tiers::factory()->create();
    $partAutre = Participant::factory()->create([
        'operation_id' => $opAutre->id,
        'tiers_id' => $tAutre->id,
    ]);
    Reglement::create([
        'participant_id' => $partAutre->id,
        'seance_id' => $sAutre->id,
        'montant_prevu' => 9999,
    ]);

    TenantContext::boot($this->association);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        exercice: 2026,
        operationIds: [$this->operation->id],
        parSeances: false,
        parTiers: false,
        previsionnel: true,
    );

    $total = collect($data['previsions_produits'])->sum(fn ($cat) => collect($cat['sous_categories'])->sum('montant'));
    expect($total)->toBe(60.0); // pas 9999 + 60
});

it('retourne ProjectionMatrix dans proj_charges/proj_produits', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersEnc->id,
        'sous_categorie_id' => $this->scDep->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 200,
    ]);
    Reglement::create([
        'participant_id' => $this->participant->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 80,
    ]);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        exercice: 2026,
        operationIds: [$this->operation->id],
        parSeances: true,
        parTiers: true,
        previsionnel: true,
    );

    expect($data)->toHaveKey('proj_charges')
        ->and($data)->toHaveKey('proj_produits')
        ->and($data['proj_charges'])->toBeInstanceOf(ProjectionMatrix::class)
        ->and($data['proj_produits'])->toBeInstanceOf(ProjectionMatrix::class);

    expect($data['proj_charges']->total())->toBe(200.0);
    expect($data['proj_produits']->total())->toBe(80.0);

    expect($data)->not->toHaveKey('projections');
});

it('ProjectionMatrix contient les valeurs projetées au grain tiers', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersEnc->id,
        'sous_categorie_id' => $this->scDep->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 350,
    ]);

    $data = app(CompteResultatBuilder::class)->compteDeResultatOperations(
        exercice: 2026,
        operationIds: [$this->operation->id],
        parSeances: true,
        parTiers: true,
        previsionnel: true,
    );

    /** @var ProjectionMatrix $projCharges */
    $projCharges = $data['proj_charges'];

    $scId = (int) $this->scDep->id;
    $tiersId = (int) $this->tiersEnc->id;

    $tiersTotal = $projCharges->byScTiers($scId)[$tiersId] ?? 0;
    expect($tiersTotal)->toBe(350.0);

    $tiersSeance = $projCharges->byScTiersSeance($scId)[$tiersId] ?? [];
    expect(array_sum($tiersSeance))->toBe(350.0);

    $tiersOp = $projCharges->byScTiersOp($scId)[$tiersId] ?? [];
    expect(array_sum($tiersOp))->toBe(350.0);
});
