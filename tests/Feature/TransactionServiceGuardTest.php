<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Exceptions\Compta\PartieDoubleIncompleteException;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\PartieDoubleGuard;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Config;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();
    Config::set('compta.use_partie_double', true);
});

// ---------------------------------------------------------------------------
// Test 1 : le skip gracieux (SousCategorie sans Compte) NE lance PAS
//          d'exception — PD simplement non applicable (guard pas déclenché)
// ---------------------------------------------------------------------------

it('create() avec PD active et SousCategorie sans mapping → skip gracieux, pas d\'exception (guard non déclenché)', function () {
    // SousCategorie sans code_cerfa → enrichirPartieDouble() skip silencieux.
    // La transaction est créée avec equilibree=false mais aucune exception n'est levée.
    $categorie = Categorie::factory()->recette()->create([
        'association_id' => $this->association->id,
        'nom' => 'Catégorie sans mapping',
    ]);
    $scSansCerfa = SousCategorie::create([
        'association_id' => $this->association->id,
        'categorie_id'   => $categorie->id,
        'nom'            => 'SC sans mapping Compte',
        'code_cerfa'     => null,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $service = app(TransactionService::class);

    $data = [
        'type'          => TypeTransaction::Recette->value,
        'date'          => '2025-10-01',
        'libelle'       => 'Recette sans mapping PD',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'compte_id'     => $this->compteBancaire->id,
        'tiers_id'      => $tiers->id,
    ];
    $lignes = [['sous_categorie_id' => $scSansCerfa->id, 'montant' => '100.00']];

    // Pas d'exception — PD silencieusement skippée (code_cerfa absent → G2 dans resolver)
    $transaction = $service->create($data, $lignes);

    expect($transaction)->toBeInstanceOf(Transaction::class);
    expect((bool) $transaction->equilibree)->toBeFalse('PD skippée → equilibree reste false');
});

// ---------------------------------------------------------------------------
// Test 2 : guard passe sur le happy path (SousCategorie correctement mappée)
// ---------------------------------------------------------------------------

it('create() avec PD active et SousCategorie correctement mappée → guard passe, equilibree=true', function () {
    // sc706 est mappée à compte706 via code_cerfa='706' → numero_pcg='706'
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $service = app(TransactionService::class);

    $data = [
        'type'          => TypeTransaction::Recette->value,
        'date'          => '2025-10-01',
        'libelle'       => 'Recette mappée PD',
        'montant_total' => '200.00',
        'mode_paiement' => 'virement',
        'compte_id'     => $this->compteBancaire->id,
        'tiers_id'      => $tiers->id,
    ];
    $lignes = [['sous_categorie_id' => $this->sc706->id, 'montant' => '200.00']];

    $transaction = $service->create($data, $lignes);

    expect((bool) $transaction->equilibree)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Test 3 : guard détecte une transaction marquée equilibree=true mais avec
//          des lignes PD déséquilibrées (test direct du guard via helper)
// ---------------------------------------------------------------------------

it('PartieDoubleGuard::assertComplete lance une exception si debit ≠ credit', function () {
    // Simuler une transaction avec equilibree=true mais des lignes déséquilibrées
    $compte = $this->compte706;

    $transaction = Transaction::create([
        'association_id' => $this->association->id,
        'type'           => TypeTransaction::Recette,
        'date'           => '2025-10-01',
        'libelle'        => 'TX déséquilibrée',
        'montant_total'  => 100.0,
        'saisi_par'      => $this->user->id,
        'equilibree'     => true,   // marquée équilibrée mais lignes tronquées
    ]);

    // Insérer une seule ligne PD (débit mais pas de crédit correspondant)
    // Utiliser DB::table pour contourner l'observer XOR qui empêche debit>0 ET credit=0 seul.
    \Illuminate\Support\Facades\DB::table('transaction_lignes')->insert([
        'transaction_id' => $transaction->id,
        'compte_id'      => $compte->id,
        'debit'          => 100.0,
        'credit'         => 0.0,
        'montant'        => 0.0,
    ]);

    expect(fn () => PartieDoubleGuard::assertComplete($transaction->fresh()))
        ->toThrow(PartieDoubleIncompleteException::class);
});
