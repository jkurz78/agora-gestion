<?php

declare(strict_types=1);

use App\Livewire\Tiers\FicheTiers;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('n\'affiche pas l\'onglet Opérations si le tiers n\'a aucune participation', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->assertDontSeeHtml('?onglet=operations');
});

it('affiche l\'onglet Opérations avec compteur si tiers a des participations', function (): void {
    $tiers = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id]);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op2->id]);

    Livewire::actingAs($this->user)
        ->test(FicheTiers::class, ['tiers' => $tiers])
        ->assertSee('Opérations')
        ->assertSee('(2)');
});

it('charge le composant Operations via ?onglet=operations', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $response = $this->actingAs($this->user)->get(route('tiers.show', $tiers->id).'?onglet=operations');
    $response->assertOk();
    $response->assertSee('Participation'); // depuis x-tiers.operations.section-card
});
