<?php

declare(strict_types=1);

use App\Livewire\OperationDetail;
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
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $catRec = Categorie::factory()->recette()->create();
    $scRec = SousCategorie::factory()->create(['categorie_id' => $catRec->id]);
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $scRec->id]);
    $this->operation = Operation::factory()->create(['type_operation_id' => $typeOp->id]);
    $this->seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);

    $catDep = Categorie::factory()->depense()->create();
    $this->scDep = SousCategorie::factory()->create(['categorie_id' => $catDep->id]);
    $this->tiersEnc = Tiers::factory()->pourDepenses()->create();
    $this->tiersPart = Tiers::factory()->create();
    $this->participant = Participant::factory()->create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersPart->id,
    ]);
});

it('affiche le total planifié dépenses + recettes dans le bilan', function (): void {
    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $this->tiersEnc->id,
        'sous_categorie_id' => $this->scDep->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 300,
    ]);
    Reglement::create([
        'participant_id' => $this->participant->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 75,
    ]);

    Livewire::test(OperationDetail::class, ['operation' => $this->operation])
        ->set('activeTab', 'details')
        ->assertSee('Planifié')
        ->assertSee('Écart')
        ->assertSeeText('300,00')
        ->assertSeeText('75,00');
});

it('expose totaux planifiés et écarts dans les viewData', function (): void {
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
        'montant_prevu' => 50,
    ]);

    $comp = Livewire::test(OperationDetail::class, ['operation' => $this->operation])
        ->set('activeTab', 'details');

    $comp->assertViewHas('totalDepensesPrev', 200.0)
        ->assertViewHas('totalRecettesPrev', 50.0)
        ->assertViewHas('soldePrev', -150.0);
});
