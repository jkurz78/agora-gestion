<?php

use App\Models\Don;
use App\Models\Operation;
use App\Models\TransactionLigne;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication to access operations index', function () {
    $this->get(route('operations.index'))
        ->assertRedirect(route('login'));
});

it('can list operations', function () {
    $operation = Operation::factory()->create();

    $this->actingAs($this->user)
        ->get(route('operations.index'))
        ->assertOk()
        ->assertSee($operation->nom);
});

it('can store an operation with valid data', function () {
    $this->actingAs($this->user)
        ->post(route('operations.store'), [
            'nom' => 'Fête annuelle',
            'description' => 'Organisation de la fête',
            'date_debut' => '2025-06-01',
            'date_fin' => '2025-06-30',
            'nombre_seances' => 5,
            'statut' => 'en_cours',
        ])
        ->assertRedirect(route('operations.index'));

    $this->assertDatabaseHas('operations', [
        'nom' => 'Fête annuelle',
        'nombre_seances' => 5,
        'statut' => 'en_cours',
    ]);
});

it('validates required fields when storing an operation', function () {
    $this->actingAs($this->user)
        ->post(route('operations.store'), [])
        ->assertSessionHasErrors(['nom']);
});

it('validates date_fin must be after or equal to date_debut', function () {
    $this->actingAs($this->user)
        ->post(route('operations.store'), [
            'nom' => 'Test',
            'date_debut' => '2025-06-30',
            'date_fin' => '2025-06-01',
            'statut' => 'en_cours',
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

    // Create linked don
    Don::factory()->create([
        'operation_id' => $operation->id,
        'montant' => 75.00,
    ]);

    $this->actingAs($this->user)
        ->get(route('operations.show', $operation))
        ->assertOk()
        ->assertSee($operation->nom);
});

it('can update an operation', function () {
    $operation = Operation::factory()->create(['nom' => 'Ancien nom']);

    $this->actingAs($this->user)
        ->put(route('operations.update', $operation), [
            'nom' => 'Nouveau nom',
            'statut' => 'cloturee',
        ])
        ->assertRedirect(route('operations.show', $operation));

    $this->assertDatabaseHas('operations', [
        'id' => $operation->id,
        'nom' => 'Nouveau nom',
        'statut' => 'cloturee',
    ]);
});
