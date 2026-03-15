<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\CotisationService;
use App\Services\DepenseService;
use App\Services\DonService;
use App\Services\RecetteService;
use App\Services\VirementInterneService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create();
});

test('DepenseService::delete lève une exception si la dépense est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(DepenseService::class)->delete($depense))
        ->toThrow(RuntimeException::class, 'pointée');
});

test('DepenseService::delete réussit si la dépense n\'est pas pointée', function () {
    $depense = Depense::factory()->create(['compte_id' => $this->compte->id]);

    app(DepenseService::class)->delete($depense);

    expect(Depense::find($depense->id))->toBeNull();
});

test('RecetteService::delete lève une exception si la recette est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(RecetteService::class)->delete($recette))
        ->toThrow(RuntimeException::class, 'pointée');
});

test('DonService::delete lève une exception si le don est pointé', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $don = Don::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(DonService::class)->delete($don))
        ->toThrow(RuntimeException::class, 'pointé');
});

test('CotisationService::delete lève une exception si la cotisation est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $tiers = Tiers::factory()->membre()->create();
    $cotisation = Cotisation::factory()->create([
        'tiers_id' => $tiers->id,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(CotisationService::class)->delete($cotisation))
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

test('RecetteService::delete réussit si la recette n\'est pas pointée', function () {
    $recette = Recette::factory()->create(['compte_id' => $this->compte->id]);

    app(RecetteService::class)->delete($recette);

    expect(Recette::withTrashed()->find($recette->id)->deleted_at)->not->toBeNull();
});

test('DonService::delete réussit si le don n\'est pas pointé', function () {
    $don = Don::factory()->create(['compte_id' => $this->compte->id]);

    app(DonService::class)->delete($don);

    expect(Don::withTrashed()->find($don->id)->deleted_at)->not->toBeNull();
});

test('CotisationService::delete réussit si la cotisation n\'est pas pointée', function () {
    $tiers = Tiers::factory()->membre()->create();
    $cotisation = Cotisation::factory()->create([
        'tiers_id' => $tiers->id,
        'compte_id' => $this->compte->id,
    ]);

    app(CotisationService::class)->delete($cotisation);

    expect(Cotisation::withTrashed()->find($cotisation->id)->deleted_at)->not->toBeNull();
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
