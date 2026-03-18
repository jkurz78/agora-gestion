<?php
declare(strict_types=1);
use App\Enums\StatutRapprochement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();
});

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
    ]);
    $transaction->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'montant' => 200.00,
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
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
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
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
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
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '999.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update autorise la modification de sous_categorie_id de ligne sur pièce verrouillée', function () {
    $transaction = makeLockedTransaction($this->compte);
    $autreSousCategorie = SousCategorie::factory()->create();
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $autreSousCategorie->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($ligne->fresh()->sous_categorie_id)->toBe($autreSousCategorie->id);
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
        ['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
        ['sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
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
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

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
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

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
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => $operation->id, 'seance' => null, 'notes' => null]]);

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
