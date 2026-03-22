<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Enums\TypeTransaction;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $cat = Categorie::factory()->create(['nom' => 'Charges', 'type' => TypeCategorie::Depense]);
    $this->sc = SousCategorie::factory()->create(['nom' => 'Loyers', 'categorie_id' => $cat->id]);

    // Réalisé exercice 2025 (Sept 2025–Aug 2026) : Loyers=1200
    $compte = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'date' => '2025-10-15',
        'montant_total' => 1200.00,
        'compte_id' => $compte->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->sc->id,
        'montant' => 1200.00,
    ]);
});

it('télécharge un CSV budget', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertDownload('budget-2026-2027.csv');

    expect($response->getContent())
        ->toContain('exercice;categorie;sous_categorie;montant_prevu')
        ->toContain('2026-2027;Charges;Loyers;1200.00');
});

it('source zero produit des montants vides dans le CSV', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertOk();
    expect($response->getContent())->toContain('2026-2027;Charges;Loyers;');
    expect($response->getContent())->not->toContain('1200');
});

it('télécharge un Excel budget', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'xlsx', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertDownload('budget-2026-2027.xlsx');
});

it('source budget exporte les montants_prevu', function () {
    BudgetLine::factory()->create(['sous_categorie_id' => $this->sc->id, 'exercice' => 2025, 'montant_prevu' => 900.00]);

    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'budget']));

    $response->assertOk();
    expect($response->getContent())->toContain('2026-2027;Charges;Loyers;900.00');
});

it('redirige les invités vers login', function () {
    $response = $this->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));
    $response->assertRedirect(route('login'));
});

it('rejette un format invalide', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('budget.export', ['format' => 'pdf', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertStatus(422);
});
