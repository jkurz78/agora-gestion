<?php

declare(strict_types=1);

use App\Livewire\SeanceTable;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders seance table', function () {
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->assertOk()
        ->assertSee('séances');
});

it('can add a seance', function () {
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('addSeance');
    expect(Seance::where('operation_id', $this->operation->id)->count())->toBe(1);
    expect(Seance::first()->numero)->toBe(1);
});

it('increments seance numero', function () {
    Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('addSeance');
    expect(Seance::where('operation_id', $this->operation->id)->count())->toBe(2);
    expect(Seance::where('numero', 2)->exists())->toBeTrue();
});

it('can update presence', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    $participant = Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('updatePresence', $seance->id, $participant->id, 'statut', 'present');
    $presence = Presence::where('seance_id', $seance->id)->where('participant_id', $participant->id)->first();
    expect($presence)->not->toBeNull();
    expect($presence->statut)->toBe('present');
});

it('can remove seance', function () {
    $seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1]);
    Livewire::test(SeanceTable::class, ['operation' => $this->operation])
        ->call('removeSeance', $seance->id);
    expect(Seance::find($seance->id))->toBeNull();
});

/** Opération dont le type porte la participation optionnelle (ou pas). */
function operationParticipationSeanceTableTest(object $ctx, ?string $libelle, bool $parcours = false): Operation
{
    $type = TypeOperation::factory()->create([
        'association_id' => $ctx->association->id,
        'formulaire_parcours_therapeutique' => $parcours,
        'participation_seance_active' => $libelle !== null,
        'participation_seance_libelle' => $libelle,
    ]);
    $operation = Operation::factory()->create([
        'association_id' => $ctx->association->id,
        'type_operation_id' => $type->id,
    ]);
    Seance::create(['operation_id' => $operation->id, 'numero' => 1]);
    Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $ctx->association->id])->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    return $operation;
}

it('affiche la colonne de participation avec son libellé', function () {
    $operation = operationParticipationSeanceTableTest($this, 'Repas');

    $component = Livewire::test(SeanceTable::class, ['operation' => $operation])
        ->assertSee('Repas')
        ->assertSeeHtml('data-participation-seance')
        ->assertSeeHtml('title="Repas"');

    // Le helper crée 1 séance × 1 participant : la cellule cliquable de
    // participation ne doit apparaître qu'une seule fois.
    expect(substr_count($component->html(), 'data-participation-seance-cellule'))->toBe(1);
});

it('n\'affiche pas la colonne sans l\'option, même en parcours thérapeutique', function () {
    $operation = operationParticipationSeanceTableTest($this, null, parcours: true);

    Livewire::test(SeanceTable::class, ['operation' => $operation])
        ->assertDontSee('Kiné')
        ->assertDontSeeHtml('data-participation-seance')
        ->assertDontSeeHtml('data-participation-seance-cellule');
});

it('refuse d\'enregistrer la participation quand l\'option est inactive', function () {
    $operation = operationParticipationSeanceTableTest($this, null, parcours: true);
    $seance = Seance::where('operation_id', $operation->id)->sole();
    $participant = Participant::where('operation_id', $operation->id)->sole();

    Livewire::test(SeanceTable::class, ['operation' => $operation])
        ->call('updatePresence', $seance->id, $participant->id, 'kine', 'oui');

    expect(Presence::where('seance_id', $seance->id)->where('participant_id', $participant->id)->first()?->kine)->toBeNull();
});

it('enregistre la participation quand l\'option est active', function () {
    $operation = operationParticipationSeanceTableTest($this, 'Kiné');
    $seance = Seance::where('operation_id', $operation->id)->sole();
    $participant = Participant::where('operation_id', $operation->id)->sole();

    Livewire::test(SeanceTable::class, ['operation' => $operation])
        ->call('updatePresence', $seance->id, $participant->id, 'kine', 'oui');

    expect(Presence::where('seance_id', $seance->id)->where('participant_id', $participant->id)->sole()->kine)->toBe('oui');
});

it('refuse toute valeur de participation hors oui/non, même quand l\'option est active', function () {
    $operation = operationParticipationSeanceTableTest($this, 'Kiné');
    $seance = Seance::where('operation_id', $operation->id)->sole();
    $participant = Participant::where('operation_id', $operation->id)->sole();

    Livewire::test(SeanceTable::class, ['operation' => $operation])
        ->call('updatePresence', $seance->id, $participant->id, 'kine', "';alert(1);'");

    expect(Presence::where('seance_id', $seance->id)->where('participant_id', $participant->id)->first()?->kine)->toBeNull();
});
