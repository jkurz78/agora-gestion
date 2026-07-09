<?php

declare(strict_types=1);
use App\Enums\StatutRapprochement;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();
});

function compteVentilationLockTest(int $classe = 6, string $numeroPcg = '606'): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numeroPcg.'-'.uniqid(),
        'intitule' => 'Compte test '.$numeroPcg,
        'classe' => $classe,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

function makeLockedTransaction(CompteBancaire $compte, string $type = 'depense'): Transaction
{
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);
    $transaction = Transaction::factory()->create([
        'type' => $type,
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
        'statut_reglement' => 'pointe',
    ]);
    $transaction->lignes()->forceDelete();
    $compteVentilation = compteVentilationLockTest($type === 'depense' ? 6 : 7);
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compteVentilation->id,
        'montant' => 200.00,
        'debit' => $type === 'depense' ? 200.00 : 0,
        'credit' => $type === 'depense' ? 0 : 200.00,
    ]);

    return $transaction->fresh(['lignes', 'rapprochement']);
}

it('update rejette la modification de date sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => '2025-12-01',
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update rejette la modification de compte_id sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $autreCompte = CompteBancaire::factory()->create();
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $autreCompte->id,
        'reference' => $transaction->reference,
    ], [['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update rejette la modification de montant de ligne sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => '999.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update rejette la modification de montant_total sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => '999.00',
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update autorise la modification du compte de ventilation de ligne sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $autreCompteVentilation = compteVentilationLockTest(6, '607');
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [['id' => $ligne->id, 'compte_id' => $autreCompteVentilation->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $autreCompteVentilation->id);
});

it('update rejette l\'ajout d\'une ligne sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [
        ['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
        ['compte_id' => $ligne->compte_id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ])
    )->toThrow(RuntimeException::class);
});

it('update accepte la modification de tiers_id sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $tiers = Tiers::factory()->create();
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
        'tiers_id' => $tiers->id,
    ], [['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($transaction->fresh()->tiers_id)->toBe($tiers->id);
});

it('update accepte la modification de libelle et notes sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => 'Libellé modifié',
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
        'notes' => 'Nouvelle note',
    ], [['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($transaction->fresh()->libelle)->toBe('Libellé modifié');
    expect($transaction->fresh()->notes)->toBe('Nouvelle note');
});

it('update accepte la modification d\'operation_id de ligne sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $operation = Operation::factory()->create();
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [['id' => $ligne->id, 'compte_id' => $ligne->compte_id, 'montant' => (string) $ligne->montant, 'operation_id' => $operation->id, 'seance' => null, 'notes' => null]]);

    expect($transaction->fresh(['lignes'])->lignes->first()->operation_id)->toBe($operation->id);
});

it('update rejette la suppression d\'une ligne sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    // La transaction a 1 ligne, on soumet 0 lignes
    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [])
    )->toThrow(RuntimeException::class);
});
