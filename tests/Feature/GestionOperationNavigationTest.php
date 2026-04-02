<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;

test('operations list page loads', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/operations')
        ->assertOk()
        ->assertSeeLivewire('operation-list');
});

test('operation detail page loads', function (): void {
    $user = User::factory()->create();
    $operation = Operation::factory()->create(['nom' => 'Art-thérapie navigation']);
    $this->actingAs($user)
        ->get("/gestion/operations/{$operation->id}")
        ->assertOk()
        ->assertSeeLivewire('operation-detail')
        ->assertSee('Art-thérapie navigation');
});

test('operation detail returns 404 for non-existent operation', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/operations/99999')
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
        ->get("/gestion/operations/{$operation->id}/participants/{$participant->id}")
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
        ->get("/gestion/operations/{$operation1->id}/participants/{$participant->id}")
        ->assertNotFound();
});

test('unauthenticated user is redirected from operations list', function (): void {
    $this->get('/gestion/operations')
        ->assertRedirect('/login');
});

test('unauthenticated user is redirected from operation detail', function (): void {
    $operation = Operation::factory()->create();
    $this->get("/gestion/operations/{$operation->id}")
        ->assertRedirect('/login');
});
