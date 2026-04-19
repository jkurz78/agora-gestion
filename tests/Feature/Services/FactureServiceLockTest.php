<?php

declare(strict_types=1);

use App\Enums\StatutFacture;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();
});

afterEach(function () {
    TenantContext::clear();
});

function makeFactureLockedTransaction(CompteBancaire $compte): Transaction
{
    static $factureSeq = 0;
    $factureSeq++;

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create([
        'type' => 'recette',
        'compte_id' => $compte->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-10-01',
        'montant_total' => 200.00,
    ]);
    $transaction->lignes()->forceDelete();
    $sc = SousCategorie::factory()->create();
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 200.00,
    ]);

    // Create validated facture linked to this transaction
    $facture = Facture::create([
        'date' => '2025-10-01',
        'statut' => StatutFacture::Validee,
        'numero' => 'FV-2025-'.str_pad((string) $factureSeq, 4, '0', STR_PAD_LEFT),
        'tiers_id' => $tiers->id,
        'montant_total' => 200.00,
        'saisi_par' => auth()->id(),
        'exercice' => 2025,
    ]);
    $facture->transactions()->attach($transaction->id);

    return $transaction->fresh(['lignes', 'rapprochement']);
}

function makeBrouillonFactureTransaction(CompteBancaire $compte): Transaction
{
    static $brouillonSeq = 0;
    $brouillonSeq++;

    $tiers = Tiers::factory()->create();
    $transaction = Transaction::factory()->create([
        'type' => 'recette',
        'compte_id' => $compte->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-10-01',
        'montant_total' => 200.00,
    ]);
    $transaction->lignes()->forceDelete();
    $sc = SousCategorie::factory()->create();
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sc->id,
        'montant' => 200.00,
    ]);

    // Create brouillon facture linked to this transaction
    $facture = Facture::create([
        'date' => '2025-10-01',
        'statut' => StatutFacture::Brouillon,
        'numero' => 'FB-2025-'.str_pad((string) $brouillonSeq, 4, '0', STR_PAD_LEFT),
        'tiers_id' => $tiers->id,
        'montant_total' => 200.00,
        'saisi_par' => auth()->id(),
        'exercice' => 2025,
    ]);
    $facture->transactions()->attach($transaction->id);

    return $transaction->fresh(['lignes', 'rapprochement']);
}

// --- ALLOWED changes on facture-locked transaction ---

it('update autorise la modification de date sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => '2025-10-15',
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $ligne->sous_categorie_id,
        'montant' => '200.00',
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => $ligne->notes,
    ]]);

    expect($transaction->fresh()->date->format('Y-m-d'))->toBe('2025-10-15');
});

it('update autorise la modification de libelle sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => 'Nouveau libellé facturé',
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $ligne->sous_categorie_id,
        'montant' => '200.00',
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => $ligne->notes,
    ]]);

    expect($transaction->fresh()->libelle)->toBe('Nouveau libellé facturé');
});

it('update autorise la modification de notes sur une ligne de transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $ligne->sous_categorie_id,
        'montant' => '200.00',
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => 'Note modifiée sur ligne facturée',
    ]]);

    expect($ligne->fresh()->notes)->toBe('Note modifiée sur ligne facturée');
});

// --- BLOCKED changes on facture-locked transaction ---

it('update rejette la modification de montant_total sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => '999.00',
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $ligne->sous_categorie_id,
        'montant' => '200.00',
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => $ligne->notes,
    ]]))->toThrow(RuntimeException::class, 'montant total');
});

it('update rejette la modification du nombre de lignes sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [
        ['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => $ligne->operation_id, 'seance' => $ligne->seance, 'notes' => null],
        ['sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]))->toThrow(RuntimeException::class, 'nombre de lignes');
});

it('update rejette la modification du montant d\'une ligne sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $ligne->sous_categorie_id,
        'montant' => '150.00',
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => $ligne->notes,
    ]]))->toThrow(RuntimeException::class, 'montant d\'une ligne');
});

it('update rejette la modification de sous_categorie_id sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();
    $autreSousCategorie = SousCategorie::factory()->create();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $autreSousCategorie->id,
        'montant' => '200.00',
        'operation_id' => $ligne->operation_id,
        'seance' => $ligne->seance,
        'notes' => $ligne->notes,
    ]]))->toThrow(RuntimeException::class, 'sous-catégorie');
});

it('update rejette la modification de operation_id sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();
    $operation = Operation::factory()->create();

    expect(fn () => $this->service->update($transaction, [
        'date' => $transaction->date->format('Y-m-d'),
        'libelle' => $transaction->libelle,
        'montant_total' => $transaction->montant_total,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $ligne->sous_categorie_id,
        'montant' => '200.00',
        'operation_id' => $operation->id,
        'seance' => $ligne->seance,
        'notes' => $ligne->notes,
    ]]))->toThrow(RuntimeException::class, 'opération');
});

// --- delete / affecterLigne / supprimerAffectations ---

it('delete rejette la suppression d\'une transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);

    expect(fn () => $this->service->delete($transaction))
        ->toThrow(RuntimeException::class, 'facture validée');
});

it('affecterLigne rejette la ventilation sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();
    $operation = Operation::factory()->create();

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $operation->id, 'seance' => null, 'montant' => '200.00', 'notes' => null],
    ]))->toThrow(RuntimeException::class, 'facture validée');
});

it('supprimerAffectations rejette sur transaction facturée', function () {
    $transaction = makeFactureLockedTransaction($this->compte);
    $ligne = $transaction->lignes->first();

    expect(fn () => $this->service->supprimerAffectations($ligne))
        ->toThrow(RuntimeException::class, 'facture validée');
});

// --- Brouillon facture does NOT lock ---

it('toutes les opérations sont autorisées sur transaction liée à une facture brouillon', function () {
    $transaction = makeBrouillonFactureTransaction($this->compte);
    $ligne = $transaction->lignes->first();
    $autreSousCategorie = SousCategorie::factory()->create();
    $operation = Operation::factory()->create();

    // Update with changed sous_categorie, operation, montant_total should all work
    $result = $this->service->update($transaction, [
        'date' => '2025-10-20',
        'libelle' => 'Libellé brouillon modifié',
        'montant_total' => 300.00,
        'mode_paiement' => $transaction->mode_paiement->value,
        'compte_id' => $transaction->compte_id,
        'reference' => $transaction->reference,
    ], [[
        'id' => $ligne->id,
        'sous_categorie_id' => $autreSousCategorie->id,
        'montant' => '300.00',
        'operation_id' => $operation->id,
        'seance' => null,
        'notes' => 'Note brouillon',
    ]]);

    expect($result->montant_total)->toBe('300.00');
    expect($result->date->format('Y-m-d'))->toBe('2025-10-20');

    // Delete should also work
    $transaction2 = makeBrouillonFactureTransaction($this->compte);
    $this->service->delete($transaction2);
    expect(Transaction::find($transaction2->id))->toBeNull();
});
