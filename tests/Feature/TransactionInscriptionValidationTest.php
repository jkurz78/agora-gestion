<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    SystemeSeeder::seed();
    $this->actingAs($this->user);
    $this->service = app(TransactionService::class);
    $this->compte = CompteBancaire::factory()->create();

    $categorie = Categorie::factory()->create(['type' => 'recette']);
    $this->scInscription = SousCategorie::factory()->pourInscriptions()->create([
        'categorie_id' => $categorie->id,
        'nom' => 'Inscription stage',
        'code_cerfa' => '706',
    ]);
    $this->scDon = SousCategorie::factory()->pourDons()->create([
        'categorie_id' => $categorie->id,
        'nom' => 'Don manuel',
        'code_cerfa' => '754',
    ]);

    $this->compteInscription = Compte::where('numero_pcg', '706')
        ->where('association_id', $this->association->id)
        ->firstOrFail();
    $this->compteDon = Compte::where('numero_pcg', '754')
        ->where('association_id', $this->association->id)
        ->firstOrFail();
});

afterEach(function () {
    TenantContext::clear();
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
        'compte_id' => $this->compteInscription->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    expect(fn () => $this->service->create($data, $lignes))
        ->toThrow(InvalidArgumentException::class);
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
        'compte_id' => $this->compteInscription->id,
        'montant' => '50.00',
        'operation_id' => $operation->id,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);
    expect($transaction->lignes()->ventilation()->count())->toBe(1);

    $ventilationLignes = $transaction->lignes()->ventilation()->get();
    expect($ventilationLignes)->toHaveCount(1)
        ->and($ventilationLignes->first()->operation_id)->toBe($operation->id);
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
        'compte_id' => $this->compteDon->id,
        'montant' => '50.00',
        'operation_id' => null,
        'seance' => null,
        'notes' => null,
    ]];

    $transaction = $this->service->create($data, $lignes);
    expect($transaction->lignes()->ventilation()->count())->toBe(1);
});
