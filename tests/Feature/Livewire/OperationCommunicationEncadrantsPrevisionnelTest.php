<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\OperationCommunication;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
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

    $this->operation = Operation::factory()->create();
    $this->seance = Seance::create(['operation_id' => $this->operation->id, 'numero' => 1, 'date' => now()]);
    $this->categorie = Categorie::factory()->depense()->create();
    $this->sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);
});

it('inclut les encadrants prévisionnels (sans transaction) dans la liste', function (): void {
    $tiers = Tiers::factory()->pourDepenses()->create(['email' => 'benevole@example.com']);

    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $this->sc->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 0,
    ]);

    $comp = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);

    expect($comp->instance()->getEncadrantsTiers()->pluck('id')->map(fn ($i) => (int) $i)->toArray())
        ->toContain((int) $tiers->id);
});

it('dédoublonne un encadrant présent à la fois en prévision et en réalisé', function (): void {
    $tiers = Tiers::factory()->pourDepenses()->create(['email' => 'mixte@example.com']);

    EncadrementPrevision::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $tiers->id,
        'sous_categorie_id' => $this->sc->id,
        'seance_id' => $this->seance->id,
        'montant_prevu' => 100,
    ]);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'tiers_id' => $tiers->id,
        'compte_id' => CompteBancaire::factory()->create()->id,
        'date' => now(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'montant' => 80,
    ]);

    $comp = Livewire::test(OperationCommunication::class, ['operation' => $this->operation]);
    $ids = $comp->instance()->getEncadrantsTiers()->pluck('id')->map(fn ($i) => (int) $i)->toArray();

    expect($ids)->toContain((int) $tiers->id)
        ->and(count(array_filter($ids, fn ($i) => $i === (int) $tiers->id)))->toBe(1);
});
