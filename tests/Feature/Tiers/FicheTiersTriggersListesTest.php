<?php

declare(strict_types=1);

use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

// ── tiers.index ──────────────────────────────────────────────────────────────

it('affiche un bouton Voir sur tiers.index', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Durand',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertSee(route('tiers.show', $tiers->id));
});

it('rend la ligne cliquable sur tiers.index (data-tiers-href)', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Durand',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertSee('data-tiers-href', false);
});

// ── tiers.adherents ──────────────────────────────────────────────────────────

it('affiche un bouton Voir sur la liste des adhérents', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Lebrun',
    ]);

    Adhesion::factory()->create([
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.adherents'))
        ->assertOk()
        ->assertSee(route('tiers.show', $tiers->id));
});

// ── tiers.dons ───────────────────────────────────────────────────────────────

it('affiche un bouton Voir sur la liste des dons', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Donateur',
    ]);

    $donSousCategorie = SousCategorie::factory()->pourDons()->create([
        'association_id' => $this->association->id,
        'code_cerfa' => '754',
    ]);

    $compte = Compte::where('numero_pcg', '754')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $transaction = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'date' => now()->format('Y-m-d'),
    ]);
    $transaction->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte->id,
        'montant' => 100,
        'debit' => 0,
        'credit' => 100,
    ]);

    $this->actingAs($this->user)
        ->get(route('comptabilite.dons'))
        ->assertOk()
        ->assertSee(route('tiers.show', $tiers->id));
});

// ── tiers.cotisations ────────────────────────────────────────────────────────

it('affiche un bouton Voir sur la liste des cotisations', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Cotisant',
    ]);

    $cotSousCategorie = SousCategorie::factory()->pourCotisations()->create([
        'association_id' => $this->association->id,
        'code_cerfa' => '751',
    ]);

    $compte = Compte::where('numero_pcg', '751')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $transaction = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'tiers_id' => $tiers->id,
        'date' => now()->format('Y-m-d'),
    ]);
    $transaction->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte->id,
        'montant' => 50,
        'debit' => 0,
        'credit' => 50,
    ]);

    $this->actingAs($this->user)
        ->get(route('comptabilite.cotisations'))
        ->assertOk()
        ->assertSee(route('tiers.show', $tiers->id));
});

// ── tiers.communication ──────────────────────────────────────────────────────

it('affiche un bouton Voir sur la liste communication tiers', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Communicant',
        'email' => 'communicant@example.org',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.communication'))
        ->assertOk()
        ->assertSee(route('tiers.show', $tiers->id));
});
