<?php

declare(strict_types=1);
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();
});

it('crée une dépense avec ses lignes', function () {
    $sc = SousCategorie::factory()->create();
    $data = [
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test dépense',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-001',
        'compte_id' => $this->compte->id,
    ];
    $lignes = [['sous_categorie_id' => $sc->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]];

    $transaction = $this->service->create($data, $lignes);

    expect($transaction->type)->toBe(TypeTransaction::Depense)
        ->and($transaction->lignes()->count())->toBe(1);
});

it('crée une recette avec ses lignes', function () {
    $sc = SousCategorie::factory()->create();
    $data = [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-01',
        'libelle' => 'Test recette',
        'montant_total' => '200.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-002',
        'compte_id' => $this->compte->id,
    ];
    $lignes = [['sous_categorie_id' => $sc->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]];

    $transaction = $this->service->create($data, $lignes);

    expect($transaction->type)->toBe(TypeTransaction::Recette)
        ->and($transaction->montantSigne())->toBe(200.0);
});

it('montantSigne est négatif pour une dépense', function () {
    $transaction = Transaction::factory()->asDepense()->create(['montant_total' => '150.00', 'compte_id' => $this->compte->id]);
    expect($transaction->montantSigne())->toBe(-150.0);
});

it('montantSigne est positif pour une recette', function () {
    $transaction = Transaction::factory()->asRecette()->create(['montant_total' => '150.00', 'compte_id' => $this->compte->id]);
    expect($transaction->montantSigne())->toBe(150.0);
});

it('create assigne un numero_piece non null', function () {
    $sc = SousCategorie::factory()->create();
    $transaction = $this->service->create([
        'type' => TypeTransaction::Depense->value,
        'date' => '2025-10-01',
        'libelle' => 'Test',
        'montant_total' => '100.00',
        'mode_paiement' => 'virement',
        'reference' => 'REF-003',
        'compte_id' => $this->compte->id,
    ], [['sous_categorie_id' => $sc->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($transaction->numero_piece)->not->toBeNull()
        ->and($transaction->numero_piece)->toStartWith('2025-2026:');
});

it('supprime une transaction non rapprochée', function () {
    $transaction = Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id]);
    $this->service->delete($transaction);
    expect(Transaction::find($transaction->id))->toBeNull();
});

it('rejette la suppression d\'une transaction rapprochée', function () {
    $rapprochement = RapprochementBancaire::factory()->create(['compte_id' => $this->compte->id]);
    $transaction = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
    ]);
    expect(fn () => $this->service->delete($transaction))
        ->toThrow(RuntimeException::class);
});
