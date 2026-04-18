<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\RemiseBancaireList;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
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

it('renders the list page', function () {
    $this->get(route('banques.remises.index'))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireList::class);
});

it('displays existing remises', function () {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id, 'nom' => 'Banque Populaire']);
    RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $compte->id,
        'libelle' => 'Remise chèques n°1',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RemiseBancaireList::class)
        ->assertSee('Remise chèques n°1')
        ->assertSee('Banque Populaire')
        ->assertSee('Brouillon');
});

it('creates a new remise and redirects to selection', function () {
    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);

    Livewire::test(RemiseBancaireList::class)
        ->set('date', '2025-10-15')
        ->set('compte_cible_id', $compte->id)
        ->set('mode_paiement', 'cheque')
        ->call('create')
        ->assertRedirect();

    expect(RemiseBancaire::count())->toBe(1);
});
