<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();

    $cat = Categorie::factory()->create(['nom' => 'Charges', 'type' => TypeCategorie::Depense]);
    $sc  = SousCategorie::factory()->create(['nom' => 'Loyers', 'categorie_id' => $cat->id]);
    BudgetLine::factory()->create(['sous_categorie_id' => $sc->id, 'exercice' => 2025, 'montant_prevu' => 1200.00]);
});

it('télécharge un CSV budget', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertDownload('budget-2026.csv');

    expect($response->getContent())
        ->toContain('exercice;sous_categorie;montant_prevu')
        ->toContain('2026;Loyers;1200.00');
});

it('source zero produit des montants vides dans le CSV', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertOk();
    expect($response->getContent())->toContain('2026;Loyers;');
    expect($response->getContent())->not->toContain('1200');
});

it('télécharge un Excel budget', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'xlsx', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertDownload('budget-2026.xlsx');
});

it('redirige les invités vers login', function () {
    $response = $this->get(route('budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));
    $response->assertRedirect(route('login'));
});

it('rejette un format invalide', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budget.export', ['format' => 'pdf', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertStatus(422);
});
