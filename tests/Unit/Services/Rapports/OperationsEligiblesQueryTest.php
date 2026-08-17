<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\Rapports\OperationsEligiblesQuery;
use App\Tenant\TenantContext;

beforeEach(function () {
    $assoc = Association::factory()->create();
    TenantContext::boot($assoc);
    $this->association = $assoc;

    $this->user = User::factory()->create();
    $this->user->associations()->attach($assoc->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);

    $tenantId = (int) TenantContext::currentId();

    $this->compte606 = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '606', 'intitule' => 'Achats',
        'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $this->compte706 = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '706', 'intitule' => 'Prestations',
        'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $this->compte512 = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '512', 'intitule' => 'Banque',
        'classe' => 5, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);

    $this->query = app(OperationsEligiblesQuery::class);
});

afterEach(function () {
    TenantContext::clear();
});

/** Crée une transaction + une ligne directe. Retourne la ligne. */
function ligneDirecte(int $compteId, ?int $operationId, string $date, float $montant = 100.0): TransactionLigne
{
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => (int) TenantContext::currentId(),
        'date' => $date,
        'saisi_par' => auth()->id(),
    ]);
    $tx->lignes()->forceDelete();

    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => $montant,
        'compte_id' => $compteId,
        'operation_id' => $operationId,
        'debit' => $montant,
        'credit' => 0.0,
    ]);
}

function operationTest(string $nom, StatutOperation $statut = StatutOperation::EnCours, ?string $debut = null, ?string $fin = null): Operation
{
    return Operation::factory()->create([
        'association_id' => (int) TenantContext::currentId(),
        'nom' => $nom,
        'statut' => $statut,
        'date_debut' => $debut,
        'date_fin' => $fin,
    ]);
}

it('retient une opération avec une ligne directe de classe 6 dans l\'exercice', function () {
    $op = operationTest('Avec charge');
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);
});

it('retient une opération avec une ligne directe de classe 7 dans l\'exercice', function () {
    $op = operationTest('Avec produit');
    ligneDirecte((int) $this->compte706->id, (int) $op->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);
});

it('retient une opération portée par une affectation ventilée', function () {
    $op = operationTest('Ventilée');
    $ligne = ligneDirecte((int) $this->compte606->id, null, '2025-10-01');
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $op->id,
        'seance' => 1,
        'montant' => 100.0,
    ]);

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);
});

it('ignore une ligne de classe 5', function () {
    $op = operationTest('Trésorerie seule');
    ligneDirecte((int) $this->compte512->id, (int) $op->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([]);
});

it('ignore un mouvement hors de l\'exercice affiché', function () {
    $op = operationTest('Hors exercice');
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2024-10-01');

    expect($this->query->pourExercice(2025))->toBe([]);
});

it('ignore une transaction supprimée', function () {
    $op = operationTest('Transaction supprimée');
    $ligne = ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');
    $ligne->transaction->delete();

    expect($this->query->pourExercice(2025))->toBe([]);
});

it('ignore une ligne supprimée', function () {
    $op = operationTest('Ligne supprimée');
    $ligne = ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');
    $ligne->delete();

    expect($this->query->pourExercice(2025))->toBe([]);
});

it('retient une opération clôturée qui a un mouvement courant', function () {
    $op = operationTest('Clôturée mais active', StatutOperation::Cloturee);
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);
});

it('ignore les dates de l\'opération', function () {
    $op = operationTest('Datée ailleurs', StatutOperation::EnCours, '2019-09-01', '2020-08-31');
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);
});

it('ne retourne pas de doublon quand une opération a plusieurs mouvements', function () {
    $op = operationTest('Multi mouvements');
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');
    ligneDirecte((int) $this->compte706->id, (int) $op->id, '2025-11-01');
    $ligne = ligneDirecte((int) $this->compte606->id, null, '2025-12-01');
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $op->id,
        'seance' => 1,
        'montant' => 100.0,
    ]);

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);
});

it('n\'expose aucune opération d\'un autre tenant', function () {
    $op = operationTest('Tenant courant');
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');

    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    expect($this->query->pourExercice(2025))->toBe([]);
});

it('retourne un tableau vide si le contexte tenant n\'est pas booté', function () {
    TenantContext::clear();

    expect($this->query->pourExercice(2025))->toBe([]);
});

it('normaliser ne garde que les identifiants éligibles', function () {
    $eligible = operationTest('Éligible');
    ligneDirecte((int) $this->compte606->id, (int) $eligible->id, '2025-10-01');
    $inerte = operationTest('Sans mouvement');

    $resultat = $this->query->normaliser(
        ['0', (string) $eligible->id, (string) $inerte->id, '999999', $eligible->id],
        2025,
    );

    expect($resultat)->toBe([(int) $eligible->id]);
});

it('normaliser retourne un tableau vide quand tout est écarté', function () {
    operationTest('Sans mouvement');

    expect($this->query->normaliser(['999999'], 2025))->toBe([]);
});

it('ignore une opération supprimée logiquement, même avec un mouvement courant', function () {
    // Operation utilise SoftDeletes, et une suppression logique ne nullifie pas
    // la clé étrangère : la ligne continue de pointer dessus. Sans le filtre,
    // l'id resterait éligible alors que l'arbre du sélecteur — qui passe par
    // Eloquent — ne la propose plus.
    $op = operationTest('Supprimée');
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);

    $op->delete();

    expect($this->query->pourExercice(2025))->toBe([])
        ->and($this->query->normaliser([(int) $op->id], 2025))->toBe([]);
});

it('ignore une opération supprimée portée par une affectation ventilée', function () {
    $op = operationTest('Supprimée ventilée');
    $ligne = ligneDirecte((int) $this->compte606->id, null, '2025-10-01');
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $op->id,
        'seance' => 1,
        'montant' => 100.0,
    ]);

    expect($this->query->pourExercice(2025))->toBe([(int) $op->id]);

    $op->delete();

    expect($this->query->pourExercice(2025))->toBe([]);
});
