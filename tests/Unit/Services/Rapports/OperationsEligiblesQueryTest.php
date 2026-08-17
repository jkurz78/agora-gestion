<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Models\Association;
use App\Models\Compte;
use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
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

/**
 * Crée une prévision de charge (encadrement_previsions) sur une séance de
 * l'opération donnée, avec ou sans date de séance.
 */
function eligPrevisionCharge(int $operationId, int $compteId, ?string $seanceDate): EncadrementPrevision
{
    $seance = Seance::create([
        'operation_id' => $operationId,
        'numero' => 1,
        'date' => $seanceDate,
    ]);

    return EncadrementPrevision::create([
        'operation_id' => $operationId,
        'tiers_id' => Tiers::factory()->create()->id,
        'compte_id' => $compteId,
        'seance_id' => $seance->id,
        'montant_prevu' => 100.0,
    ]);
}

/**
 * Crée une prévision de produit (reglements.montant_prevu) sur une séance de
 * l'opération donnée, avec ou sans date de séance. Le compte de la ventilation
 * vient du type d'opération de l'opération elle-même (comme dans
 * CompteResultatBuilder::buildPrevisionsProduits — via type_operations.compte_id).
 */
function eligPrevisionProduit(int $operationId, ?string $seanceDate): Reglement
{
    $seance = Seance::create([
        'operation_id' => $operationId,
        'numero' => 1,
        'date' => $seanceDate,
    ]);

    $participant = Participant::factory()->create(['operation_id' => $operationId]);

    return Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'montant_prevu' => 100.0,
    ]);
}

it('ignore une opération n\'ayant qu\'une prévision de charge sans le drapeau, et la retient avec', function () {
    $op = operationTest('Prévision charge seule');
    eligPrevisionCharge((int) $op->id, (int) $this->compte606->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([])
        ->and($this->query->pourExercice(2025, true))->toBe([(int) $op->id]);
});

it('ignore une opération n\'ayant qu\'une prévision de produit sans le drapeau, et la retient avec', function () {
    // L'opération créée par operationTest() porte un type_operation dont le
    // compte de ventilation par défaut est de classe 7 (TypeOperationFactory) —
    // exactement la chaîne lue par la branche produits (to_.compte_id).
    $op = operationTest('Prévision produit seule');
    eligPrevisionProduit((int) $op->id, '2025-10-01');

    expect($this->query->pourExercice(2025))->toBe([])
        ->and($this->query->pourExercice(2025, true))->toBe([(int) $op->id]);
});

it('ignore une prévision de charge datée hors exercice, avec ou sans le drapeau', function () {
    $op = operationTest('Prévision charge hors exercice');
    eligPrevisionCharge((int) $op->id, (int) $this->compte606->id, '2024-10-01');

    expect($this->query->pourExercice(2025))->toBe([])
        ->and($this->query->pourExercice(2025, true))->toBe([]);
});

it('retient avec le drapeau une opération dont la séance de prévision n\'a pas de date', function () {
    $op = operationTest('Prévision séance non datée');
    eligPrevisionCharge((int) $op->id, (int) $this->compte606->id, null);

    expect($this->query->pourExercice(2025))->toBe([])
        ->and($this->query->pourExercice(2025, true))->toBe([(int) $op->id]);
});

it('n\'expose pas, même avec le drapeau, une opération d\'un autre tenant portant une prévision', function () {
    $op = operationTest('Prévision autre tenant');
    eligPrevisionCharge((int) $op->id, (int) $this->compte606->id, '2025-10-01');

    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    expect($this->query->pourExercice(2025, true))->toBe([]);
});

it('ignore, même avec le drapeau, une opération supprimée logiquement portant une prévision', function () {
    $op = operationTest('Prévision opération supprimée');
    eligPrevisionCharge((int) $op->id, (int) $this->compte606->id, '2025-10-01');

    expect($this->query->pourExercice(2025, true))->toBe([(int) $op->id]);

    $op->delete();

    expect($this->query->pourExercice(2025, true))->toBe([]);
});

it('ne retourne pas de doublon quand une opération a un mouvement réel et une prévision', function () {
    $op = operationTest('Réel et prévision');
    ligneDirecte((int) $this->compte606->id, (int) $op->id, '2025-10-01');
    eligPrevisionCharge((int) $op->id, (int) $this->compte606->id, '2025-11-01');

    expect($this->query->pourExercice(2025, true))->toBe([(int) $op->id]);
});

it('normaliser propage le drapeau prévisions à pourExercice', function () {
    $op = operationTest('Prévision normaliser');
    eligPrevisionCharge((int) $op->id, (int) $this->compte606->id, '2025-10-01');

    expect($this->query->normaliser([(string) $op->id], 2025))->toBe([])
        ->and($this->query->normaliser([(string) $op->id], 2025, true))->toBe([(int) $op->id]);
});
