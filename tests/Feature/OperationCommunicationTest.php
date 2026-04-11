<?php

declare(strict_types=1);

use App\Livewire\OperationCommunication;
use App\Livewire\OperationDetail;
use App\Models\MessageTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->operation = Operation::factory()->create();
});

it('mounts with operation and selects participants with email', function () {
    $tiersWithEmail = Tiers::factory()->create(['email' => 'alice@example.com']);
    $tiersNoEmail = Tiers::factory()->create(['email' => null]);

    $p1 = Participant::create([
        'tiers_id' => $tiersWithEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    Participant::create([
        'tiers_id' => $tiersNoEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $component->assertSet('selectedParticipants', [$p1->id]);
});

it('shows communication tab in operation detail', function () {
    $component = Livewire::test(OperationDetail::class, ['operation' => $this->operation]);

    $component->assertSee('Communication');
});

it('toggles select all participants', function () {
    $tiers1 = Tiers::factory()->create(['email' => 'a@example.com']);
    $tiers2 = Tiers::factory()->create(['email' => 'b@example.com']);

    $p1 = Participant::create([
        'tiers_id' => $tiers1->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    $p2 = Participant::create([
        'tiers_id' => $tiers2->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    // Starts with all selected (2 with email)
    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);
    $component->assertSet('selectedParticipants', [$p1->id, $p2->id]);

    // Toggle → deselect all
    $component->call('toggleSelectAll');
    $component->assertSet('selectedParticipants', []);

    // Toggle again → select all
    $component->call('toggleSelectAll');
    $selectedAfter = $component->get('selectedParticipants');
    expect($selectedAfter)->toContain($p1->id)->toContain($p2->id);
});

it('excludes participants without email from selection', function () {
    $tiersWithEmail = Tiers::factory()->create(['email' => 'ok@example.com']);
    $tiersNoEmail = Tiers::factory()->create(['email' => null]);

    $pWithEmail = Participant::create([
        'tiers_id' => $tiersWithEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);
    $pNoEmail = Participant::create([
        'tiers_id' => $tiersNoEmail->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => now(),
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $selected = $component->get('selectedParticipants');
    expect($selected)->toContain($pWithEmail->id)
        ->not->toContain($pNoEmail->id);
});

it('loads template into objet and corps', function () {
    $template = MessageTemplate::create([
        'nom' => 'Rappel séance',
        'objet' => 'Rappel : votre prochaine séance',
        'corps' => 'Bonjour {prenom}, à bientôt !',
        'type_operation_id' => null,
    ]);

    Livewire::test(OperationCommunication::class, ['operation' => $this->operation])
        ->set('selectedTemplateId', $template->id)
        ->call('loadTemplate')
        ->assertSet('objet', 'Rappel : votre prochaine séance')
        ->assertSet('corps', 'Bonjour {prenom}, à bientôt !');
});

// Step 9 tests

it('computes unresolved variables when no future seance exists', function () {
    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    // Set corps containing a variable that requires a future seance
    $component->set('corps', 'Prochaine séance : {date_prochaine_seance}');

    $instance = $component->instance();
    $unresolved = $instance->getUnresolvedVariables();

    expect($unresolved)->toContain('{date_prochaine_seance}');
});

it('returns empty unresolved when all variables are resolvable', function () {
    // Create a future seance for the operation
    Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => now()->addDays(7)->toDateString(),
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);
    $component->set('corps', 'Prochaine séance : {date_prochaine_seance}');

    $instance = $component->instance();
    $unresolved = $instance->getUnresolvedVariables();

    expect($unresolved)->toBeEmpty();
});

it('groups templates by type operation', function () {
    $typeOp = TypeOperation::factory()->create(['nom' => 'Sophrologie']);

    MessageTemplate::create([
        'nom' => 'Gabarit global',
        'objet' => 'Sujet global',
        'corps' => 'Corps global',
        'type_operation_id' => null,
    ]);
    MessageTemplate::create([
        'nom' => 'Gabarit sophrologie',
        'objet' => 'Sujet sophrologie',
        'corps' => 'Corps sophrologie',
        'type_operation_id' => $typeOp->id,
    ]);

    $component = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    $component->assertSee('Gabarit global')
        ->assertSee('Gabarit sophrologie');
});
