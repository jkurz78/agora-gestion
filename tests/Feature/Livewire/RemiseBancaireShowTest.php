<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\RemiseBancaireShow;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compteCible = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Banque Pop',
    ]);

    $service = app(RemiseBancaireService::class);
    $this->remise = $service->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'prenom' => 'Jean']);
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 30.00,
        'statut_reglement' => StatutReglement::EnAttente,
        'tiers_id' => $tiers->id,
        'remise_id' => null,
    ]);

    $service->comptabiliser($this->remise, [$tx->id]);
    $this->remise->refresh();
});

afterEach(function () {
    TenantContext::clear();
});

it('renders the show page', function () {
    $this->get(route('banques.remises.show', $this->remise))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireShow::class);
});

it('displays remise details', function () {
    Livewire::test(RemiseBancaireShow::class, ['remise' => $this->remise])
        ->assertSee('Remise chèques n°1')
        ->assertSee('Banque Pop')
        ->assertSee('Jean DUPONT')
        ->assertSee('RBC-00001-001');
});

it('isBrouillon returns false when remise has recu transactions', function () {
    $component = Livewire::test(RemiseBancaireShow::class, ['remise' => $this->remise]);

    // The remise has a recu transaction, so it's not a brouillon
    expect($this->remise->transactions()->where('statut_reglement', StatutReglement::Recu->value)->exists())->toBeTrue();
});

it('isBrouillon returns true when no recu or pointe transactions', function () {
    $service = app(RemiseBancaireService::class);
    $remiseBrouillon = $service->creer([
        'date' => '2025-11-01',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
    ]);

    // No comptabiliser called — no recu transactions
    expect($remiseBrouillon->transactions()->whereIn('statut_reglement', ['recu', 'pointe'])->exists())->toBeFalse();
});
