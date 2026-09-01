<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Famille;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    // Famille nommée AVANT la matérialisation du compte (sinon fallback nom = code)
    Famille::create(['association_id' => $this->association->id, 'code' => '61', 'nom' => 'Charges']);

    $this->sc = Compte::factory()->numero('613')->create(['intitule' => 'Loyers']);
    $this->compteLoyers = $this->sc;

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
        'montant' => 1200.00,
        'compte_id' => $this->compteLoyers->id,
        'debit' => 1200.00,
        'credit' => 0.0,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('télécharge un CSV budget', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('comptabilite.budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertDownload('budget-2026-2027.csv');

    // Correctif audit point 6 : l'en-tête CSV doit porter le libellé de
    // l'exercice de référence, identique à celui du XLSX (enTetes()), et non
    // plus un "realise_reference" figé.
    expect($response->getContent())
        ->toContain('exercice;famille;compte;montant_prevu;realise_2025-2026')
        ->toContain('2026-2027;61 — Charges;Loyers;1200.00');
});

it('source zero produit des montants vides dans le CSV', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('comptabilite.budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertOk();
    // Colonne montant_prevu (4e) vide : les deux ';' se suivent. La colonne
    // informative (5e) reste renseignée, indépendamment de la source choisie.
    expect($response->getContent())->toContain('2026-2027;61 — Charges;Loyers;;1200.00');
});

it('renseigne la colonne informative avec l\'exercice de reference choisi, quelle que soit la source', function () {
    // Réalisé exercice 2024 (Sept 2024–Aug 2025), distinct de celui du beforeEach.
    $compteBancaire = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'date' => '2024-10-15',
        'montant_total' => 555.00,
        'compte_id' => $compteBancaire->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'montant' => 555.00,
        'compte_id' => $this->compteLoyers->id,
        'debit' => 555.00,
        'credit' => 0.0,
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('comptabilite.budget.export', [
            'format' => 'csv', 'exercice' => 2026, 'source' => 'zero', 'source_exercice' => 2024,
        ]));

    $response->assertOk();
    expect($response->getContent())
        ->toContain('exercice;famille;compte;montant_prevu;realise_2024-2025')
        ->toContain('2026-2027;61 — Charges;Loyers;;555.00');
});

it('retombe sur l\'exercice courant si l\'exercice de reference est hors plage', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('comptabilite.budget.export', [
            'format' => 'csv', 'exercice' => 2026, 'source' => 'zero', 'source_exercice' => 1900,
        ]));

    $response->assertOk();
    expect($response->getContent())->toContain('2026-2027;61 — Charges;Loyers;;1200.00');
});

it('télécharge un Excel budget', function () {
    $response = $this->actingAs($this->user)
        ->get(route('comptabilite.budget.export', ['format' => 'xlsx', 'exercice' => 2026, 'source' => 'courant']));

    $response->assertOk();
    $response->assertDownload('budget-2026-2027.xlsx');
});

it('l\'en-tete xlsx porte le libelle de l\'exercice de reference', function () {
    $response = $this->actingAs($this->user)
        ->get(route('comptabilite.budget.export', [
            'format' => 'xlsx', 'exercice' => 2026, 'source' => 'zero', 'source_exercice' => 2025,
        ]));

    $response->assertOk();

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_test_');
    file_put_contents($tmp, $response->streamedContent());
    $sheet = IOFactory::load($tmp)->getActiveSheet();
    $header = $sheet->toArray(null, true, true, false)[0];
    unlink($tmp);

    expect($header)->toBe(['exercice', 'famille', 'compte', 'montant_prevu', 'realise_2025-2026']);
});

it('source budget exporte les montants_prevu', function () {
    BudgetLine::factory()->create(['compte_id' => $this->sc->id, 'exercice' => 2025, 'montant_prevu' => 900.00]);

    $response = $this->actingAs($this->user)
        ->withSession(['exercice_actif' => 2025])
        ->get(route('comptabilite.budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'budget']));

    $response->assertOk();
    expect($response->getContent())->toContain('2026-2027;61 — Charges;Loyers;900.00');
});

it('redirige les invités vers login', function () {
    $response = $this->get(route('comptabilite.budget.export', ['format' => 'csv', 'exercice' => 2026, 'source' => 'zero']));
    $response->assertRedirect(route('login'));
});

it('rejette un format invalide', function () {
    $response = $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->get(route('comptabilite.budget.export', ['format' => 'pdf', 'exercice' => 2026, 'source' => 'zero']));

    $response->assertStatus(422);
});
