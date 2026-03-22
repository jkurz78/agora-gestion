<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();

    $categorie = Categorie::factory()->create(['type' => 'recette']);
    $this->scInscription = SousCategorie::create([
        'categorie_id' => $categorie->id,
        'nom' => 'Inscription stage',
        'code_cerfa' => '706',
        'pour_inscriptions' => true,
    ]);
    $this->scDon = SousCategorie::create([
        'categorie_id' => $categorie->id,
        'nom' => 'Don manuel',
        'code_cerfa' => '754',
        'pour_dons' => true,
    ]);
});

it('refuses a transaction ligne with inscription sous-categorie without operation_id', function () {
    $data = [
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $this->compte->id,
        'reference' => 'REF-INS',
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scInscription->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    expect(fn () => $this->service->create($data, $lignes))
        ->toThrow(\InvalidArgumentException::class);
});

it('accepts a transaction ligne with inscription sous-categorie with operation_id', function () {
    $operation = Operation::factory()->create();

    $data = [
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $this->compte->id,
        'reference' => 'REF-INS-OK',
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scInscription->id,
        'montant' => '50.00',
        'operation_id' => $operation->id,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);
    expect($transaction->lignes()->count())->toBe(1)
        ->and($transaction->lignes->first()->operation_id)->toBe($operation->id);
});

it('does not require operation_id for non-inscription sous-categorie', function () {
    $data = [
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => '50.00',
        'mode_paiement' => 'cb',
        'compte_id' => $this->compte->id,
        'reference' => 'REF-DON',
    ];
    $lignes = [[
        'sous_categorie_id' => $this->scDon->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);
    expect($transaction->lignes()->count())->toBe(1);
});
