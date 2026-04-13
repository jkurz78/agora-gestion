<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\TransactionService;
use App\Services\VirementInterneService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create();
});

test('TransactionService::delete lève une exception si la dépense est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'statut_reglement' => 'pointe',
    ]);

    expect(fn () => app(TransactionService::class)->delete($depense))
        ->toThrow(RuntimeException::class, 'pointée');
});

test('TransactionService::delete réussit si la dépense n\'est pas pointée', function () {
    $depense = Transaction::factory()->asDepense()->create(['compte_id' => $this->compte->id]);

    app(TransactionService::class)->delete($depense);

    expect(Transaction::find($depense->id))->toBeNull();
});

test('TransactionService::delete lève une exception si la recette est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'statut_reglement' => 'pointe',
    ]);

    expect(fn () => app(TransactionService::class)->delete($recette))
        ->toThrow(RuntimeException::class, 'pointée');
});

test('VirementInterneService::delete lève une exception si le virement est pointé côté source', function () {
    $compteDestination = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => $compteDestination->id,
        'rapprochement_source_id' => $rapprochement->id,
        'saisi_par' => $this->user->id,
    ]);

    expect(fn () => app(VirementInterneService::class)->delete($virement))
        ->toThrow(RuntimeException::class, 'pointé');
});

test('VirementInterneService::delete lève une exception si le virement est pointé côté destination', function () {
    $compteSource = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $compteSource->id,
        'compte_destination_id' => $this->compte->id,
        'rapprochement_destination_id' => $rapprochement->id,
        'saisi_par' => $this->user->id,
    ]);

    expect(fn () => app(VirementInterneService::class)->delete($virement))
        ->toThrow(RuntimeException::class, 'pointé');
});

test('TransactionService::delete réussit si la recette n\'est pas pointée', function () {
    $recette = Transaction::factory()->asRecette()->create(['compte_id' => $this->compte->id]);

    app(TransactionService::class)->delete($recette);

    expect(Transaction::withTrashed()->find($recette->id)->deleted_at)->not->toBeNull();
});

test('VirementInterneService::delete réussit si le virement n\'est pas pointé', function () {
    $compteDestination = CompteBancaire::factory()->create();
    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => $compteDestination->id,
        'saisi_par' => $this->user->id,
    ]);

    app(VirementInterneService::class)->delete($virement);

    expect(VirementInterne::withTrashed()->find($virement->id)->deleted_at)->not->toBeNull();
});
