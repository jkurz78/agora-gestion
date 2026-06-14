<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\TransactionUniverselle;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compteBancaire = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
});

afterEach(fn () => TenantContext::clear());

test('badge recette normale est REC vert (text-bg-success)', function () {
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'journal' => JournalComptable::Vente,
        'date' => '2025-10-15',
    ]);

    Livewire::test(TransactionUniverselle::class)
        ->set('filterDateDebut', '2025-09-01')
        ->set('filterDateFin', '2026-08-31')
        ->assertSeeHtml('text-bg-success')
        ->assertSee('REC');
});

test('badge miroir extourne de recette est DÉP rouge (sens=dépense)', function () {
    // A recette that was extournée — the miroir has type=recette but sens=depense
    $origine = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'journal' => JournalComptable::Vente,
        'date' => '2025-10-15',
        'extournee_at' => now(),
        'statut_reglement' => StatutReglement::Pointe,
    ]);

    $miroir = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'type_ecriture' => 'extourne',
        'journal' => JournalComptable::Vente,
        'date' => '2025-10-15',
        'montant_total' => -100,
    ]);

    Extourne::create([
        'association_id' => $this->association->id,
        'transaction_origine_id' => $origine->id,
        'transaction_extourne_id' => $miroir->id,
        'created_by' => $this->user->id,
    ]);

    // The badge for the miroir should be DÉP (danger) because sens_tresorerie = depense
    Livewire::test(TransactionUniverselle::class)
        ->set('filterDateDebut', '2025-09-01')
        ->set('filterDateFin', '2026-08-31')
        ->assertSeeHtml('text-bg-danger');  // At least one badge with danger (the miroir)
});

test('badge miroir extourne de dépense est REC vert (sens=recette)', function () {
    $origine = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'journal' => JournalComptable::Achat,
        'date' => '2025-10-15',
        'extournee_at' => now(),
        'statut_reglement' => StatutReglement::Pointe,
    ]);

    $miroir = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'type_ecriture' => 'extourne',
        'journal' => JournalComptable::Achat,
        'date' => '2025-10-15',
        'montant_total' => -200,
    ]);

    Extourne::create([
        'association_id' => $this->association->id,
        'transaction_origine_id' => $origine->id,
        'transaction_extourne_id' => $miroir->id,
        'created_by' => $this->user->id,
    ]);

    // The badge for the miroir should be REC (success) because sens_tresorerie = recette
    Livewire::test(TransactionUniverselle::class)
        ->set('filterDateDebut', '2025-09-01')
        ->set('filterDateFin', '2026-08-31')
        ->assertSeeHtml('text-bg-success');  // The miroir gets success
});
