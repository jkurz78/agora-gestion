<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Famille;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\BudgetExportService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $tenantId = (int) TenantContext::currentId();

    // Familles nommées AVANT la matérialisation des comptes (sinon fallback nom = code)
    Famille::create(['association_id' => $tenantId, 'code' => '61', 'nom' => 'Charges']);
    Famille::create(['association_id' => $tenantId, 'code' => '75', 'nom' => 'Produits']);

    // Comptes classe 6/7 → CompteObserver matérialise la famille + la sous-catégorie miroir
    $this->scLoyers = Compte::factory()->numero('613')->create(['intitule' => 'Loyers']);
    $this->scElec = Compte::factory()->numero('616')->create(['intitule' => 'Électricité']);
    $this->scCotis = Compte::factory()->numero('756')->create(['intitule' => 'Cotisations']);

    $this->compteLoyers = $this->scLoyers;
    $this->compteCotis = $this->scCotis;

    // Réalisé 2025 : Loyers=1200, Électricité=0 (pas de transaction), Cotisations=850
    $compte = CompteBancaire::factory()->create();

    $txLoyers = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'date' => '2025-10-15',
        'montant_total' => 1200.00,
        'compte_id' => $compte->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $txLoyers->id,
        'montant' => 1200.00,
        'compte_id' => $this->compteLoyers->id,
        'debit' => 1200.00,
        'credit' => 0.0,
    ]);

    $txCotis = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'date' => '2025-10-15',
        'montant_total' => 850.00,
        'compte_id' => $compte->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $txCotis->id,
        'montant' => 850.00,
        'compte_id' => $this->compteCotis->id,
        'debit' => 0.0,
        'credit' => 850.00,
    ]);
});

it('retourne les lignes dans l\'ordre dépenses puis recettes', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 'zero', 2026);

    $noms = array_column($rows, 2); // col 2 = sous_categorie
    $posLoyers = array_search('Loyers', $noms);
    $posCotis = array_search('Cotisations', $noms);
    expect($posLoyers)->toBeLessThan($posCotis);
});

it('met l\'exercice cible dans la première colonne au format label', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 'zero', 2026);

    foreach ($rows as $row) {
        expect($row[0])->toBe('2026-2027');
    }
});

it('source zero produit des montants vides', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 'zero', 2026);

    foreach ($rows as $row) {
        expect($row[3])->toBe(''); // col 3 = montant_prevu
    }
});

it('source realise remplit les montants non nuls, laisse vide les zéros', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 'realise', 2025);

    $byName = array_column($rows, null, 2); // col 2 = sous_categorie
    expect($byName['Loyers'][3])->toBe('1200.00');
    expect($byName['Électricité'][3])->toBe('');   // pas de transaction → vide
    expect($byName['Cotisations'][3])->toBe('850.00');
});

it('source budget exporte les montants_prevu de la table budget_lines', function () {
    BudgetLine::factory()->create(['compte_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 900.00]);
    BudgetLine::factory()->create(['compte_id' => $this->scCotis->id, 'exercice' => 2025, 'montant_prevu' => 700.00]);
    // scElec intentionnellement absent → cellule vide

    $rows = app(BudgetExportService::class)->rows(2026, 'budget', 2025);

    $byName = array_column($rows, null, 2); // col 2 = sous_categorie
    expect($byName['Loyers'][3])->toBe('900.00');
    expect($byName['Électricité'][3])->toBe('');
    expect($byName['Cotisations'][3])->toBe('700.00');
});

it('inclut le libellé de la famille en deuxième colonne', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 'zero', 2026);

    $byName = array_column($rows, null, 2); // col 2 = compte (intitulé)
    expect($byName['Loyers'][1])->toBe('61 — Charges');
    expect($byName['Cotisations'][1])->toBe('75 — Produits');
});

it('toCsv génère un CSV valide avec en-tête', function () {
    $rows = [
        ['2026-2027', 'Charges', 'Loyers', '1200.00'],
        ['2026-2027', 'Charges', 'Électricité', ''],
    ];

    $csv = app(BudgetExportService::class)->toCsv($rows);

    expect($csv)
        ->toContain('exercice;famille;compte;montant_prevu')
        ->toContain('2026-2027;Charges;Loyers;1200.00')
        ->toContain('2026-2027;Charges;Électricité;');
});
