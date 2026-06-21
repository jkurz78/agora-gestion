<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Rapports\VentilationFinanciereService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->sousCategorie = SousCategorie::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Helper : crée une transaction + 1 ligne dans l'association courante, retourne la ligne.
 * Nom préfixé pour éviter toute collision de fonction globale Pest entre fichiers.
 */
function ventilationTestLigne(array $txAttrs = [], array $ligneAttrs = []): TransactionLigne
{
    $assoId = test()->association->id;
    $tx = Transaction::create(array_merge([
        'association_id' => $assoId,
        'tiers_id' => Tiers::factory()->create(['association_id' => $assoId])->id,
        'compte_id' => test()->compte->id,
        'type' => 'recette',
        'date' => '2026-01-15',
        'libelle' => 'Test',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'saisi_par' => test()->user->id,
    ], $txAttrs));

    return TransactionLigne::create(array_merge([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => test()->sousCategorie->id,
        'montant' => 100.00,
    ], $ligneAttrs));
}

it('signe les montants : recette +, dépense −', function () {
    ventilationTestLigne(['type' => 'recette'], ['montant' => 80.00]);
    ventilationTestLigne(['type' => 'depense'], ['montant' => 30.00]);

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);

    $montants = collect($rows)->pluck('Montant')->sort()->values()->all();
    expect($montants)->toBe([-30.0, 80.0]);
});

it('éclate une ligne ventilée en une ligne par affectation et exclut la ligne parente', function () {
    $opA = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Op A']);
    $opB = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Op B']);
    $ligne = ventilationTestLigne(['type' => 'recette'], ['montant' => 100.00]);
    $ligne->affectations()->create(['operation_id' => $opA->id, 'montant' => 60.00]);
    $ligne->affectations()->create(['operation_id' => $opB->id, 'montant' => 40.00]);

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);

    expect($rows)->toHaveCount(2);
    expect(collect($rows)->sum('Montant'))->toBe(100.0);

    $byOp = collect($rows)->keyBy('Opération');
    expect($byOp->keys()->sort()->values()->all())->toBe(['Op A', 'Op B']);
    expect($byOp['Op A']['Montant'])->toBe(60.0);
    expect($byOp['Op B']['Montant'])->toBe(40.0);
});

it('rend une ligne non ventilée au grain ligne, sans changement', function () {
    $op = Operation::factory()->create(['association_id' => $this->association->id, 'nom' => 'Atelier']);
    ventilationTestLigne(['type' => 'recette'], ['montant' => 50.00, 'operation_id' => $op->id, 'seance' => 3]);

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['Opération'])->toBe('Atelier');
    expect((int) $rows[0]['Séance n°'])->toBe(3);
    expect($rows[0]['Montant'])->toBe(50.0);
});

it('ajoute les dimensions temporelles et les colonnes de détail', function () {
    ventilationTestLigne(['type' => 'recette', 'date' => '2026-01-15', 'numero_piece' => 'P-42', 'reference' => 'REF-1'], []);

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);

    expect($rows[0])->toHaveKeys([
        'Date', 'N° pièce', 'Référence', 'Mode paiement', 'Libellé',
        'Tiers', 'Type tiers', 'Sous-catégorie', 'Catégorie', 'Type', 'Compte',
        'Opération', 'Type opération', 'Séance n°', 'Montant',
        'Mois', 'Trimestre', 'Semestre',
    ]);
    expect($rows[0]['Date'])->toBe('15/01/2026');
    expect($rows[0]['N° pièce'])->toBe('P-42');
    expect($rows[0]['Référence'])->toBe('REF-1');
    expect($rows[0]['Mois'])->toBe('Janvier 2026');
    expect($rows[0]['Trimestre'])->toBe('T2 2025-2026');
    expect($rows[0]['Semestre'])->toBe('S1 2025-2026');
});

it('exclut les transactions hors de la fenêtre de l\'exercice', function () {
    ventilationTestLigne(['type' => 'recette', 'date' => '2026-01-15'], []); // exercice 2025
    ventilationTestLigne(['type' => 'recette', 'date' => '2025-06-15'], []); // exercice 2024 → hors

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['Date'])->toBe('15/01/2026');
});

it('exclut les lignes supprimées (soft delete)', function () {
    $ligne = ventilationTestLigne(['type' => 'recette'], ['montant' => 70.00]);
    $ligne->delete();

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);

    expect($rows)->toBeEmpty();
});

it('ne retourne pas les lignes d\'une autre association', function () {
    // Données dans une autre association
    $autre = Association::factory()->create();
    TenantContext::boot($autre);
    $compteB = CompteBancaire::factory()->create(['association_id' => $autre->id]);
    $scB = SousCategorie::factory()->create(['association_id' => $autre->id]);
    $tiersB = Tiers::factory()->create(['association_id' => $autre->id]);
    $txB = Transaction::create([
        'association_id' => $autre->id, 'tiers_id' => $tiersB->id, 'compte_id' => $compteB->id,
        'type' => 'recette', 'date' => '2026-01-15', 'libelle' => 'B', 'montant_total' => 99.00,
        'mode_paiement' => 'virement', 'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create(['transaction_id' => $txB->id, 'sous_categorie_id' => $scB->id, 'montant' => 99.00]);

    // Retour au tenant courant + une ligne à nous
    TenantContext::boot($this->association);
    ventilationTestLigne(['type' => 'recette'], ['montant' => 11.00]);

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['Montant'])->toBe(11.0);
});

it('compose le libellé Tiers en gérant un nom de famille nul', function () {
    // Particulier avec prénom + nom
    $tiersComplet = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'particulier',
        'prenom' => 'Jean',
        'nom' => 'Dupont',
    ]);
    $txComplet = Transaction::create([
        'association_id' => $this->association->id, 'tiers_id' => $tiersComplet->id,
        'compte_id' => $this->compte->id, 'type' => 'recette', 'date' => '2026-01-15',
        'libelle' => 'A', 'montant_total' => 10.00, 'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create(['transaction_id' => $txComplet->id, 'sous_categorie_id' => $this->sousCategorie->id, 'montant' => 10.00]);

    // Particulier avec nom seul (prénom null) — le cas qui révèle le bug CONCAT/NULL
    $tiersNomSeul = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'particulier',
        'prenom' => null,
        'nom' => 'Martin',
    ]);
    $txNomSeul = Transaction::create([
        'association_id' => $this->association->id, 'tiers_id' => $tiersNomSeul->id,
        'compte_id' => $this->compte->id, 'type' => 'recette', 'date' => '2026-01-16',
        'libelle' => 'B', 'montant_total' => 20.00, 'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create(['transaction_id' => $txNomSeul->id, 'sous_categorie_id' => $this->sousCategorie->id, 'montant' => 20.00]);

    $rows = app(VentilationFinanciereService::class)->pourExercice(2025);
    $tiersValues = collect($rows)->pluck('Tiers')->all();

    expect($tiersValues)->toContain('Jean Dupont');
    expect($tiersValues)->toContain('Martin'); // pas de NULL, pas d'espace de tête
});
