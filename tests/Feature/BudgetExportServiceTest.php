<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Enums\TypeCategorie;
use App\Services\BudgetExportService;

beforeEach(function () {
    // Catégories dépenses
    $catCharge = Categorie::factory()->create(['nom' => 'Charges', 'type' => TypeCategorie::Depense]);
    $this->scLoyers = SousCategorie::factory()->create(['nom' => 'Loyers', 'categorie_id' => $catCharge->id]);
    $this->scElec   = SousCategorie::factory()->create(['nom' => 'Électricité', 'categorie_id' => $catCharge->id]);

    // Catégories recettes
    $catProduit = Categorie::factory()->create(['nom' => 'Produits', 'type' => TypeCategorie::Recette]);
    $this->scCotis = SousCategorie::factory()->create(['nom' => 'Cotisations', 'categorie_id' => $catProduit->id]);

    // Réalisé 2025 : Loyers=1200, Électricité=0 (pas de transaction), Cotisations=850
    $compte = CompteBancaire::factory()->create();

    $txLoyers = Transaction::factory()->create([
        'type'          => TypeTransaction::Depense,
        'date'          => '2025-10-15',
        'montant_total' => 1200.00,
        'compte_id'     => $compte->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id'    => $txLoyers->id,
        'sous_categorie_id' => $this->scLoyers->id,
        'montant'           => 1200.00,
    ]);

    $txCotis = Transaction::factory()->create([
        'type'          => TypeTransaction::Recette,
        'date'          => '2025-10-15',
        'montant_total' => 850.00,
        'compte_id'     => $compte->id,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id'    => $txCotis->id,
        'sous_categorie_id' => $this->scCotis->id,
        'montant'           => 850.00,
    ]);
});

it('retourne les lignes dans l\'ordre dépenses puis recettes', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 'zero', 2026);

    $noms = array_column($rows, 1);
    $posLoyers = array_search('Loyers', $noms);
    $posCotis  = array_search('Cotisations', $noms);
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
        expect($row[2])->toBe('');
    }
});

it('source realise remplit les montants non nuls, laisse vide les zéros', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 'realise', 2025);

    $byName = array_column($rows, null, 1);
    expect($byName['Loyers'][2])->toBe('1200.00');
    expect($byName['Électricité'][2])->toBe('');   // pas de transaction → vide
    expect($byName['Cotisations'][2])->toBe('850.00');
});

it('source budget exporte les montants_prevu de la table budget_lines', function () {
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scLoyers->id, 'exercice' => 2025, 'montant_prevu' => 900.00]);
    BudgetLine::factory()->create(['sous_categorie_id' => $this->scCotis->id, 'exercice' => 2025, 'montant_prevu' => 700.00]);
    // scElec intentionnellement absent → cellule vide

    $rows = app(BudgetExportService::class)->rows(2026, 'budget', 2025);

    $byName = array_column($rows, null, 1);
    expect($byName['Loyers'][2])->toBe('900.00');
    expect($byName['Électricité'][2])->toBe('');
    expect($byName['Cotisations'][2])->toBe('700.00');
});

it('toCsv génère un CSV valide avec en-tête', function () {
    $rows = [
        ['2026-2027', 'Loyers', '1200.00'],
        ['2026-2027', 'Électricité', ''],
    ];

    $csv = app(BudgetExportService::class)->toCsv($rows);

    expect($csv)
        ->toContain('exercice;sous_categorie;montant_prevu')
        ->toContain('2026-2027;Loyers;1200.00')
        ->toContain('2026-2027;Électricité;');
});
