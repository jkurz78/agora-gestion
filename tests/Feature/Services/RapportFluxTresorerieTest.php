<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Exercice::create(['annee' => 2025, 'statut' => 'ouvert']);
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 10000.00,
        'date_solde_initial' => '2025-09-01',
        'est_systeme' => false,
    ]);
});

it('retourne la structure attendue', function () {
    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data)->toHaveKeys(['exercice', 'synthese', 'rapprochement', 'mensuel', 'ecritures_non_pointees']);
    expect($data['exercice'])->toHaveKeys(['annee', 'label', 'date_debut', 'date_fin', 'is_cloture', 'date_cloture']);
    expect($data['synthese'])->toHaveKeys(['solde_ouverture', 'total_recettes', 'total_depenses', 'variation', 'solde_theorique']);
    expect($data['rapprochement'])->toHaveKeys(['solde_theorique', 'recettes_non_pointees', 'nb_recettes_non_pointees', 'depenses_non_pointees', 'nb_depenses_non_pointees', 'solde_reel']);
    expect($data['mensuel'])->toHaveCount(12);
    expect($data['mensuel'][0])->toHaveKeys(['mois', 'recettes', 'depenses', 'solde', 'cumul']);
});

it('calcule la synthèse consolidée correctement', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 5000.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);
    Transaction::factory()->create([
        'type' => 'depense',
        'date' => '2025-11-20',
        'montant_total' => 2000.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['synthese']['total_recettes'])->toBe(5000.00);
    expect($data['synthese']['total_depenses'])->toBe(2000.00);
    expect($data['synthese']['variation'])->toBe(3000.00);
    expect($data['synthese']['solde_ouverture'])->toBe(10000.00);
    expect($data['synthese']['solde_theorique'])->toBe(13000.00);
});

it('ventile les flux par mois', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 3000.00,
        'compte_id' => $this->compte->id,
    ]);
    Transaction::factory()->create([
        'type' => 'depense',
        'date' => '2025-10-20',
        'montant_total' => 1000.00,
        'compte_id' => $this->compte->id,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['mensuel'][0]['recettes'])->toBe(0.0);
    expect($data['mensuel'][0]['depenses'])->toBe(0.0);
    expect($data['mensuel'][0]['cumul'])->toBe(10000.00);

    expect($data['mensuel'][1]['recettes'])->toBe(3000.00);
    expect($data['mensuel'][1]['depenses'])->toBe(1000.00);
    expect($data['mensuel'][1]['solde'])->toBe(2000.00);
    expect($data['mensuel'][1]['cumul'])->toBe(12000.00);
});

it('consolide plusieurs comptes et annule les virements internes', function () {
    $compte2 = CompteBancaire::factory()->create([
        'solde_initial' => 5000.00,
        'date_solde_initial' => '2025-09-01',
        'est_systeme' => false,
    ]);

    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 2000.00,
        'compte_id' => $this->compte->id,
    ]);

    VirementInterne::factory()->create([
        'date' => '2025-10-15',
        'montant' => 1000.00,
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => $compte2->id,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['synthese']['solde_ouverture'])->toBe(15000.00);
    expect($data['synthese']['total_recettes'])->toBe(2000.00);
    expect($data['synthese']['total_depenses'])->toBe(0.0);
    expect($data['synthese']['solde_theorique'])->toBe(17000.00);
});

it('calcule le rapprochement avec écritures non pointées', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
    ]);
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 3000.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
    ]);
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 1500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);
    Transaction::factory()->create([
        'type' => 'depense',
        'date' => '2025-11-01',
        'montant_total' => 500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['rapprochement']['recettes_non_pointees'])->toBe(1500.00);
    expect($data['rapprochement']['nb_recettes_non_pointees'])->toBe(1);
    expect($data['rapprochement']['depenses_non_pointees'])->toBe(500.00);
    expect($data['rapprochement']['nb_depenses_non_pointees'])->toBe(1);
    expect($data['rapprochement']['solde_reel'])->toBe($data['rapprochement']['solde_theorique'] - 1500.00 + 500.00);
});

it('exclut les comptes système du rapprochement', function () {
    $compteSys = CompteBancaire::factory()->create([
        'solde_initial' => 0,
        'date_solde_initial' => '2025-09-01',
        'est_systeme' => true,
    ]);

    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 999.00,
        'compte_id' => $compteSys->id,
        'rapprochement_id' => null,
    ]);
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-01',
        'montant_total' => 500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['rapprochement']['nb_recettes_non_pointees'])->toBe(1);
    expect($data['rapprochement']['recettes_non_pointees'])->toBe(500.00);
    expect($data['synthese']['total_recettes'])->toBe(1499.00);
});

it('expose la liste des écritures non pointées pour le PDF', function () {
    Transaction::factory()->create([
        'type' => 'recette',
        'date' => '2025-10-15',
        'montant_total' => 1500.00,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => null,
        'libelle' => 'Cotisation Dupont',
        'numero_piece' => 'R-2025-042',
    ]);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['ecritures_non_pointees'])->toHaveCount(1);
    expect($data['ecritures_non_pointees'][0])->toHaveKeys(['numero_piece', 'date', 'tiers', 'libelle', 'type', 'montant']);
});

it('retourne les informations exercice avec statut clôturé', function () {
    $exercice = Exercice::where('annee', 2025)->first();
    $exercice->update(['statut' => 'cloture', 'date_cloture' => '2026-09-15 10:00:00']);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['exercice']['is_cloture'])->toBeTrue();
    expect($data['exercice']['date_cloture'])->toBe('15/09/2026');
});
