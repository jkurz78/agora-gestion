<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
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
    $rows = app(BudgetExportService::class)->rows(2026, null);

    // Charges avant Produits
    $noms = array_column($rows, 1);
    $posLoyers = array_search('Loyers', $noms);
    $posCotis  = array_search('Cotisations', $noms);
    expect($posLoyers)->toBeLessThan($posCotis);
});

it('met l\'exercice cible dans la première colonne au format label', function () {
    $rows = app(BudgetExportService::class)->rows(2026, null);

    foreach ($rows as $row) {
        expect($row[0])->toBe('2026-2027');
    }
});

it('source null (zéro partout) produit des montants vides', function () {
    $rows = app(BudgetExportService::class)->rows(2026, null);

    foreach ($rows as $row) {
        expect($row[2])->toBe('');
    }
});

it('source 2025 remplit les montants non nuls, laisse vide les zéros', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 2025);

    $byName = array_column($rows, null, 1);
    expect($byName['Loyers'][2])->toBe('1200.00');
    expect($byName['Électricité'][2])->toBe('');   // pas de transaction → vide
    expect($byName['Cotisations'][2])->toBe('850.00');
});

it('source N-1 absente produit des montants vides', function () {
    $rows = app(BudgetExportService::class)->rows(2026, 2024); // pas de données 2024

    foreach ($rows as $row) {
        expect($row[2])->toBe('');
    }
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
