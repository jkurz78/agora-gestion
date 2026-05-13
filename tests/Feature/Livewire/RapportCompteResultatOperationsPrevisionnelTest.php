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

it('a parSeances et parTiers à true par défaut, previsionnel à false', function (): void {
    $comp = Livewire::test(RapportCompteResultatOperations::class);

    expect($comp->get('parSeances'))->toBeTrue()
        ->and($comp->get('parTiers'))->toBeTrue()
        ->and($comp->get('previsionnel'))->toBeFalse();
});

it('lit le param URL prev=1 dans previsionnel', function (): void {
    $comp = Livewire::withQueryParams(['prev' => '1'])
        ->test(RapportCompteResultatOperations::class);

    expect($comp->get('previsionnel'))->toBeTrue();
});

it('passe previsionnel au service et expose previsionsCharges/Produits à la vue', function (): void {
    $op = Operation::factory()->create();

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('previsionnel', true)
        ->assertViewHas('previsionsCharges')
        ->assertViewHas('previsionsProduits');
});

it('exportUrl contient prev=1 quand previsionnel activé', function (): void {
    $op = Operation::factory()->create();

    $comp = Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('previsionnel', true);

    $url = $comp->instance()->exportUrl('pdf');

    expect($url)->toContain('prev=1');
});

it('affiche les 3 lignes Prévu/Réalisé/Écart quand toggle previsionnel ON', function (): void {
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
        ->set('previsionnel', true)
        ->assertSee('300,00'); // prévu visible quelque part dans le rendu
});
