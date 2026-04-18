<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\OperationList;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

test('operations list page loads', function (): void {
    $this->get('/operations')
        ->assertOk()
        ->assertSeeLivewire('operation-list');
});

test('operation detail page loads', function (): void {
    $operation = Operation::factory()->create([
        'nom' => 'Art-thérapie navigation',
        'association_id' => $this->association->id,
    ]);
    $this->get("/operations/{$operation->id}")
        ->assertOk()
        ->assertSeeLivewire('operation-detail')
        ->assertSee('Art-thérapie navigation');
});

test('operation detail returns 404 for non-existent operation', function (): void {
    $this->get('/operations/99999')
        ->assertNotFound();
});

test('participant page loads within operation context', function (): void {
    $operation = Operation::factory()->create([
        'nom' => 'Sophrologie nav',
        'association_id' => $this->association->id,
    ]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now()->toDateString(),
    ]);

    $this->get("/operations/{$operation->id}/participants/{$participant->id}")
        ->assertOk()
        ->assertSeeLivewire('participant-show');
});

test('participant page returns 404 when participant does not belong to operation', function (): void {
    $operation1 = Operation::factory()->create(['association_id' => $this->association->id]);
    $operation2 = Operation::factory()->create(['association_id' => $this->association->id]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $participant = Participant::create([
        'operation_id' => $operation2->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => now()->toDateString(),
    ]);

    $this->get("/operations/{$operation1->id}/participants/{$participant->id}")
        ->assertNotFound();
});

test('unauthenticated user is redirected from operations list', function (): void {
    TenantContext::clear();
    auth()->logout();
    $this->get('/operations')
        ->assertRedirect('/login');
});

test('unauthenticated user is redirected from operation detail', function (): void {
    TenantContext::clear();
    auth()->logout();
    $operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $this->get("/operations/{$operation->id}")
        ->assertRedirect('/login');
});

test('niveau 1: opérations listées dans le tableau', function (): void {
    $type = TypeOperation::factory()->create([
        'nom' => 'Equithérapie',
        'actif' => true,
        'association_id' => $this->association->id,
    ]);
    $op = Operation::factory()->create([
        'nom' => 'Parcours Cheval Bleu',
        'type_operation_id' => $type->id,
        'date_debut' => now()->addDays(14),
        'date_fin' => now()->addMonths(9),
        'association_id' => $this->association->id,
    ]);
    $tiers1 = Tiers::factory()->create(['association_id' => $this->association->id]);
    $tiers2 = Tiers::factory()->create(['association_id' => $this->association->id]);
    $tiers3 = Tiers::factory()->create(['association_id' => $this->association->id]);
    Participant::create(['tiers_id' => $tiers1->id, 'operation_id' => $op->id, 'date_inscription' => now()->toDateString()]);
    Participant::create(['tiers_id' => $tiers2->id, 'operation_id' => $op->id, 'date_inscription' => now()->toDateString()]);
    Participant::create(['tiers_id' => $tiers3->id, 'operation_id' => $op->id, 'date_inscription' => now()->toDateString()]);

    $this->get('/operations')
        ->assertSee('Parcours Cheval Bleu')
        ->assertSee('Equithérapie')
        ->assertSee('3');
});

test('niveau 1: filtre par type fonctionne', function (): void {
    $type1 = TypeOperation::factory()->create([
        'nom' => 'Type A',
        'actif' => true,
        'association_id' => $this->association->id,
    ]);
    $type2 = TypeOperation::factory()->create([
        'nom' => 'Type B',
        'actif' => true,
        'association_id' => $this->association->id,
    ]);
    Operation::factory()->create([
        'nom' => 'Op A',
        'type_operation_id' => $type1->id,
        'association_id' => $this->association->id,
    ]);
    Operation::factory()->create([
        'nom' => 'Op B',
        'type_operation_id' => $type2->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(OperationList::class)
        ->set('filterTypeId', $type1->id)
        ->assertSee('Op A')
        ->assertDontSee('Op B');
});

test('niveau 1: opérations clôturées affichées en opacité réduite', function (): void {
    $op = Operation::factory()->create([
        'nom' => 'Op Clôturée',
        'statut' => StatutOperation::Cloturee,
        'association_id' => $this->association->id,
    ]);

    $this->get('/operations')
        ->assertSee('Op Clôturée')
        ->assertSee('opacity');
});
