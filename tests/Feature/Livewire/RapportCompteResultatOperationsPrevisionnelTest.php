<?php

declare(strict_types=1);

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

it('a parSeances et parTiers à true par défaut, mode realise', function (): void {
    $comp = Livewire::test(RapportCompteResultatOperations::class);

    expect($comp->get('parSeances'))->toBeTrue()
        ->and($comp->get('parTiers'))->toBeTrue()
        ->and($comp->get('mode'))->toBe('realise');
});

it('lit le param URL mode=projection', function (): void {
    $comp = Livewire::withQueryParams(['mode' => 'projection'])
        ->test(RapportCompteResultatOperations::class);

    expect($comp->get('mode'))->toBe('projection');
});

it('passe previsionnel au service et expose previsionsCharges/Produits à la vue', function (): void {
    $op = Operation::factory()->create();

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('mode', 'projection')
        ->assertViewHas('previsionsCharges')
        ->assertViewHas('previsionsProduits');
});

it('exportUrl contient mode=projection quand mode projection activé', function (): void {
    $op = Operation::factory()->create();

    $comp = Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('mode', 'projection');

    $url = $comp->instance()->exportUrl('pdf');

    expect($url)->toContain('mode=projection');
});

it('affiche les montants projetés quand mode projection ON', function (): void {
    $categorieDep = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorieDep->id, 'nom' => 'Encadrement']);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $op = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 5),
    ]);
    $seance = Seance::create(['operation_id' => $op->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);
    $tiers = Tiers::factory()->pourDepenses()->create();

    EncadrementPrevision::create([
        'operation_id' => $op->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 300,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('mode', 'projection')
        ->assertSee('300,00');
});

it('affiche un tiers prévision-only avec son montant projeté (mode simple)', function (): void {
    $categorieDep = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorieDep->id, 'nom' => 'Encadrement']);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $op = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 5),
    ]);
    $seance = Seance::create(['operation_id' => $op->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);
    $tiers = Tiers::factory()->pourDepenses()->create(['nom' => 'FORMATEUR INVISIBLE']);

    EncadrementPrevision::create([
        'operation_id' => $op->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 450,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('mode', 'projection')
        ->set('parSeances', false)
        ->set('parTiers', true)
        ->set('parOperations', false)
        ->assertSee('FORMATEUR INVISIBLE')
        ->assertSee('450,00');
});

it('affiche un tiers prévision-only avec ses opérations projetées', function (): void {
    $categorieDep = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorieDep->id, 'nom' => 'Encadrement']);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $op1 = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 5),
    ]);
    $op2 = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 12),
    ]);
    $seance1 = Seance::create(['operation_id' => $op1->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);
    $seance2 = Seance::create(['operation_id' => $op2->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 15)]);
    $tiers = Tiers::factory()->pourDepenses()->create(['nom' => 'FORMATEUR OPS']);

    EncadrementPrevision::create([
        'operation_id' => $op1->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance1->id,
        'montant_prevu' => 200,
    ]);
    EncadrementPrevision::create([
        'operation_id' => $op2->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance2->id,
        'montant_prevu' => 300,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op1->id, $op2->id])
        ->set('mode', 'projection')
        ->set('parSeances', false)
        ->set('parTiers', true)
        ->set('parOperations', true)
        ->assertSee('FORMATEUR OPS')
        ->assertSee('500,00');
});

it('affiche un tiers prévision-only avec ses séances projetées', function (): void {
    $categorieDep = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorieDep->id, 'nom' => 'Encadrement']);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $op = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 5),
    ]);
    $seance = Seance::create(['operation_id' => $op->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);
    $tiers = Tiers::factory()->pourDepenses()->create(['nom' => 'FORMATEUR SEANCES']);

    EncadrementPrevision::create([
        'operation_id' => $op->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 175,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('mode', 'projection')
        ->set('parSeances', true)
        ->set('parTiers', true)
        ->set('parOperations', false)
        ->assertSee('FORMATEUR SEANCES')
        ->assertSee('175,00');
});

it('exporte le PDF avec tiers prévision-only sans erreur', function (): void {
    $categorieDep = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorieDep->id]);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $op = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 5),
    ]);
    $seance = Seance::create(['operation_id' => $op->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);
    $tiers = Tiers::factory()->pourDepenses()->create();

    EncadrementPrevision::create([
        'operation_id' => $op->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 500,
    ]);

    $exercice = app(ExerciceService::class)->current();

    foreach (['pdf', 'xlsx'] as $format) {
        foreach ([false, true] as $parOps) {
            $params = [
                'rapport' => 'operations',
                'format' => $format,
                'exercice' => $exercice,
                'ops' => [$op->id],
                'tiers' => '1',
                'mode' => 'projection',
            ];
            if ($parOps) {
                $params['parops'] = '1';
            } else {
                $params['seances'] = '1';
            }
            $this->get(route('rapports.export', $params))->assertOk();
        }
    }
});

it('affiche le mode combiné séances × opérations avec projection', function (): void {
    $categorieDep = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorieDep->id, 'nom' => 'Encadrement']);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $op1 = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 5),
    ]);
    $op2 = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => Carbon::create(2026, 9, 12),
    ]);
    $seance1 = Seance::create(['operation_id' => $op1->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);
    $seance2 = Seance::create(['operation_id' => $op2->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 15)]);
    $tiers = Tiers::factory()->pourDepenses()->create(['nom' => 'FORMATEUR COMBINÉ']);

    EncadrementPrevision::create([
        'operation_id' => $op1->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance1->id,
        'montant_prevu' => 200,
    ]);
    EncadrementPrevision::create([
        'operation_id' => $op2->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => $seance2->id,
        'montant_prevu' => 300,
    ]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op1->id, $op2->id])
        ->set('mode', 'projection')
        ->set('parSeances', true)
        ->set('parTiers', true)
        ->set('parOperations', true)
        ->assertSee('FORMATEUR COMBINÉ')
        ->assertSee('500,00')
        ->assertSee('200,00')
        ->assertSee('300,00');
});

it('exporte PDF et Excel en mode combiné séances × opérations', function (): void {
    $categorieDep = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $categorieDep->id]);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $op1 = Operation::factory()->create(['type_operation_id' => $typeOp->id, 'date_debut' => Carbon::create(2026, 9, 5)]);
    $op2 = Operation::factory()->create(['type_operation_id' => $typeOp->id, 'date_debut' => Carbon::create(2026, 9, 12)]);
    Seance::create(['operation_id' => $op1->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 10)]);
    Seance::create(['operation_id' => $op2->id, 'numero' => 1, 'date' => Carbon::create(2026, 9, 15)]);
    $tiers = Tiers::factory()->pourDepenses()->create();

    EncadrementPrevision::create([
        'operation_id' => $op1->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $sc->id,
        'seance_id' => Seance::where('operation_id', $op1->id)->first()->id,
        'montant_prevu' => 150,
    ]);

    $exercice = app(ExerciceService::class)->current();

    foreach (['pdf', 'xlsx'] as $format) {
        foreach (['realise', 'projection'] as $mode) {
            $this->get(route('rapports.export', [
                'rapport' => 'operations',
                'format' => $format,
                'exercice' => $exercice,
                'ops' => [$op1->id, $op2->id],
                'seances' => '1',
                'tiers' => '1',
                'mode' => $mode,
                'parops' => '1',
            ]))->assertOk();
        }
    }
});

it('exporte le PDF rapport opérations en mode projection', function (): void {
    $op = Operation::factory()->create();
    $exercice = app(ExerciceService::class)->current();

    $response = $this->get(route('rapports.export', [
        'rapport' => 'operations',
        'format' => 'pdf',
        'exercice' => $exercice,
        'ops' => [$op->id],
        'seances' => '1',
        'tiers' => '1',
        'mode' => 'projection',
    ]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('exporte le Excel rapport opérations en mode projection', function (): void {
    $op = Operation::factory()->create();
    $exercice = app(ExerciceService::class)->current();

    $response = $this->get(route('rapports.export', [
        'rapport' => 'operations',
        'format' => 'xlsx',
        'exercice' => $exercice,
        'ops' => [$op->id],
        'seances' => '1',
        'tiers' => '1',
        'mode' => 'projection',
    ]));

    $response->assertOk();
});
