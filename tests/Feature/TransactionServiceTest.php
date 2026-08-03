<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

function compteVentilationServiceTest(int $associationId, int $classe, string $numeroPcg): Compte
{
    return Compte::create([
        'association_id' => $associationId,
        'numero_pcg' => $numeroPcg,
        'intitule' => 'Compte test '.$numeroPcg,
        'classe' => $classe,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

it('crée une dépense avec ses lignes', function () {
    $compte606 = compteVentilationServiceTest($this->association->id, 6, '606');
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test dépense',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-001',
        'compte_id' => $this->compte->id,
    ];
    $lignes = [['compte_id' => $compte606->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]];

    $transaction = $this->service->create($data, $lignes);

    expect($transaction->type)->toBe(TypeTransaction::Depense)
        ->and($transaction->lignes()->count())->toBe(1);
});

it('crée une recette avec ses lignes', function () {
    $compte706 = compteVentilationServiceTest($this->association->id, 7, '706');
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-01',
        'libelle' => 'Test recette',
        'montant_total' => '200.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-002',
        'compte_id' => $this->compte->id,
    ];
    $lignes = [['compte_id' => $compte706->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]];

    $transaction = $this->service->create($data, $lignes);

    expect($transaction->type)->toBe(TypeTransaction::Recette)
        ->and($transaction->montantSigne())->toBe(200.0);
});

it('montantSigne est négatif pour une dépense', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'montant_total' => '150.00',
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);
    expect($transaction->montantSigne())->toBe(-150.0);
});

it('montantSigne est positif pour une recette', function () {
    $transaction = Transaction::factory()->asRecette()->create([
        'montant_total' => '150.00',
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);
    expect($transaction->montantSigne())->toBe(150.0);
});

it('create assigne un numero_piece non null', function () {
    $compte606 = compteVentilationServiceTest($this->association->id, 6, '606');
    $transaction = $this->service->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-003',
        'compte_id' => $this->compte->id,
    ], [['compte_id' => $compte606->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($transaction->numero_piece)->not->toBeNull()
        ->and($transaction->numero_piece)->toStartWith('2025-2026:');
});

it('supprime une transaction non rapprochée', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);
    $this->service->delete($transaction);
    expect(Transaction::find($transaction->id))->toBeNull();
});

it('rejette la suppression d\'une transaction rapprochée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);
    $transaction = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
        'rapprochement_id' => $rapprochement->id,
    ]);
    expect(fn () => $this->service->delete($transaction))
        ->toThrow(RuntimeException::class);
});
