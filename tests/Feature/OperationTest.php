<?php

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->typeOperation = TypeOperation::factory()->create();
});

it('requires authentication to access operations index', function () {
    $this->get(route('compta.operations.index'))
        ->assertRedirect(route('login'));
});

it('can list operations', function () {
    $operation = Operation::factory()->create();

    $this->actingAs($this->user)
        ->get(route('compta.operations.index'))
        ->assertOk()
        ->assertSee($operation->nom);
});

it('can store an operation with valid data', function () {
    $this->actingAs($this->user)
        ->post(route('compta.operations.store'), [
            'nom' => 'Fête annuelle',
            'description' => 'Organisation de la fête',
            'date_debut' => '2025-06-01',
            'date_fin' => '2025-06-30',
            'nombre_seances' => 5,
            'type_operation_id' => $this->typeOperation->id,
        ])
        ->assertRedirect(route('compta.operations.index'));

    $this->assertDatabaseHas('operations', [
        'nom' => 'Fête annuelle',
        'nombre_seances' => 5,
        'statut' => 'en_cours',
        'type_operation_id' => $this->typeOperation->id,
    ]);
});

it('validates required fields when storing an operation', function () {
    $this->actingAs($this->user)
        ->post(route('compta.operations.store'), [])
        ->assertSessionHasErrors(['nom', 'type_operation_id']);
});

it('validates date_fin must be after or equal to date_debut', function () {
    $this->actingAs($this->user)
        ->post(route('compta.operations.store'), [
            'nom' => 'Test',
            'date_debut' => '2025-06-30',
            'date_fin' => '2025-06-01',
            'type_operation_id' => $this->typeOperation->id,
        ])
        ->assertSessionHasErrors(['date_fin']);
});

it('can view show page with financial summary', function () {
    $operation = Operation::factory()->create();

    // Create linked transaction lignes
    TransactionLigne::factory()->create([
        'operation_id' => $operation->id,
        'montant' => 100.00,
    ]);
    TransactionLigne::factory()->create([
        'operation_id' => $operation->id,
        'montant' => 50.00,
    ]);

    // Create linked transaction lignes (recette)
    TransactionLigne::factory()->create([
        'operation_id' => $operation->id,
        'montant' => 200.00,
    ]);

    $this->actingAs($this->user)
        ->get(route('compta.operations.show', $operation))
        ->assertOk()
        ->assertSee($operation->nom);
});

it('can update an operation', function () {
    $operation = Operation::factory()->create(['nom' => 'Ancien nom']);

    $this->actingAs($this->user)
        ->put(route('compta.operations.update', $operation), [
            'nom' => 'Nouveau nom',
            'statut' => 'cloturee',
            'date_debut' => $operation->date_debut->toDateString(),
            'date_fin' => $operation->date_fin->toDateString(),
            'type_operation_id' => $operation->type_operation_id,
        ])
        ->assertRedirect(route('compta.operations.show', $operation));

    $this->assertDatabaseHas('operations', [
        'id' => $operation->id,
        'nom' => 'Nouveau nom',
        'statut' => 'cloturee',
    ]);
});

it('locks type when participants exist', function () {
    $operation = Operation::factory()->create();
    $otherType = TypeOperation::factory()->create();

    // Create a participant for this operation
    Tiers::factory()->create();
    $tiers = Tiers::factory()->create();
    Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    $this->actingAs($this->user)
        ->put(route('compta.operations.update', $operation), [
            'nom' => $operation->nom,
            'statut' => $operation->statut->value,
            'date_debut' => $operation->date_debut->toDateString(),
            'date_fin' => $operation->date_fin->toDateString(),
            'type_operation_id' => $otherType->id,
        ])
        ->assertSessionHasErrors(['type_operation_id']);
});
