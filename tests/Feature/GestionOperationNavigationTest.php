<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\OperationList;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use Livewire\Livewire;

test('operations list page loads', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/operations')
        ->assertOk()
        ->assertSeeLivewire('operation-list');
});

test('operation detail page loads', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Art-thérapie navigation']);
    $this->actingAs($user)
        ->get("/operations/{$operation->id}")
        ->assertOk()
        ->assertSeeLivewire('operation-detail')
        ->assertSee('Art-thérapie navigation');
});

test('operation detail returns 404 for non-existent operation', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/operations/99999')
        ->assertNotFound();
});

test('participant page loads within operation context', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Sophrologie nav']);
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->get("/operations/{$operation->id}/participants/{$participant->id}")
        ->assertOk()
        ->assertSeeLivewire('participant-show');
});

test('participant page returns 404 when participant does not belong to operation', function (): void {
    $user = User::factory()->create();
    $operation1 = Operation::factory()->create();
    $operation2 = Operation::factory()->create();
    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'operation_id' => $operation2->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->get("/operations/{$operation1->id}/participants/{$participant->id}")
        ->assertNotFound();
});

test('unauthenticated user is redirected from operations list', function (): void {
    $this->get('/operations')
        ->assertRedirect('/login');
});

test('unauthenticated user is redirected from operation detail', function (): void {
    $operation = Operation::factory()->create();
    $this->get("/operations/{$operation->id}")
        ->assertRedirect('/login');
});

test('niveau 1: opérations listées dans le tableau', function (): void {
    $user = User::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Equithérapie', 'actif' => true]);
    $op = Operation::factory()->create([
        'nom' => 'Parcours Cheval Bleu',
        'type_operation_id' => $type->id,
        'date_debut' => now()->addDays(14),
        'date_fin' => now()->addMonths(9),
    ]);
    $tiers1 = Tiers::factory()->create();
    $tiers2 = Tiers::factory()->create();
    $tiers3 = Tiers::factory()->create();
    Participant::create(['tiers_id' => $tiers1->id, 'operation_id' => $op->id, 'date_inscription' => now()->toDateString()]);
    Participant::create(['tiers_id' => $tiers2->id, 'operation_id' => $op->id, 'date_inscription' => now()->toDateString()]);
    Participant::create(['tiers_id' => $tiers3->id, 'operation_id' => $op->id, 'date_inscription' => now()->toDateString()]);

    $this->actingAs($user)
        ->get('/operations')
        ->assertSee('Parcours Cheval Bleu')
        ->assertSee('Equithérapie')
        ->assertSee('3');
});

test('niveau 1: filtre par type fonctionne', function (): void {
    $user = User::factory()->create();
    $type1 = TypeOperation::factory()->create(['nom' => 'Type A', 'actif' => true]);
    $type2 = TypeOperation::factory()->create(['nom' => 'Type B', 'actif' => true]);
    Operation::factory()->create(['nom' => 'Op A', 'type_operation_id' => $type1->id]);
    Operation::factory()->create(['nom' => 'Op B', 'type_operation_id' => $type2->id]);

    Livewire::actingAs($user)
        ->test(OperationList::class)
        ->set('filterTypeId', $type1->id)
        ->assertSee('Op A')
        ->assertDontSee('Op B');
});

test('niveau 1: opérations clôturées affichées en opacité réduite', function (): void {
    $user = User::factory()->create();
    $op = Operation::factory()->create([
        'nom' => 'Op Clôturée',
        'statut' => StatutOperation::Cloturee,
    ]);

    $this->actingAs($user)
        ->get('/operations')
        ->assertSee('Op Clôturée')
        ->assertSee('opacity');
});
