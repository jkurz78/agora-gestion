<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Models\Association;
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
});

afterEach(function () {
    TenantContext::clear();
});

// ── tiers.index ──────────────────────────────────────────────────────────────

it('affiche un bouton Voir sur tiers.index', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom'            => 'Durand',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertSee(route('tiers.show', $tiers->id));
});

it('rend la ligne cliquable sur tiers.index (data-tiers-href)', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom'            => 'Durand',
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
        'nom'            => 'Lebrun',
    ]);

    // Créer une cotisation pour que ce tiers apparaisse dans AdherentList
    $cotSousCategorie = SousCategorie::forUsage(UsageComptable::Cotisation)->first();
    if (! $cotSousCategorie) {
        $cotSousCategorie = SousCategorie::factory()->create([
            'association_id' => $this->association->id,
        ]);
        $cotSousCategorie->usages()->attach(UsageComptable::Cotisation);
    }

    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'tiers_id'       => $tiers->id,
        'type'           => 'recette',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id'   => $transaction->id,
        'sous_categorie_id' => $cotSousCategorie->id,
        'montant'          => 50,
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
        'nom'            => 'Donateur',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.dons'))
        ->assertOk()
        ->assertSee('bi-eye', false);
});

// ── tiers.cotisations ────────────────────────────────────────────────────────

it('affiche un bouton Voir sur la liste des cotisations', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom'            => 'Cotisant',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.cotisations'))
        ->assertOk()
        ->assertSee('bi-eye', false);
});

// ── tiers.communication ──────────────────────────────────────────────────────

it('affiche un bouton Voir sur la liste communication tiers', function (): void {
    $tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom'            => 'Communicant',
        'email'          => 'communicant@example.org',
    ]);

    $this->actingAs($this->user)
        ->get(route('tiers.communication'))
        ->assertOk()
        ->assertSee(route('tiers.show', $tiers->id));
});
