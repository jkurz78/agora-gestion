<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\RapportService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    Exercice::create(['annee' => 2025, 'statut' => 'ouvert']);
    $this->compte = CompteBancaire::factory()->create([
        'solde_initial' => 10000.00,
        'date_solde_initial' => '2025-09-01',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Une transaction de trésorerie telle qu'elle existe réellement en partie
 * double : un en-tête ET sa ligne sur le compte 512X du compte bancaire.
 *
 * Le flux de trésorerie se lit désormais sur le grand livre des comptes de
 * classe 5, et non plus sur `transactions.montant_total` filtré par journal —
 * ce dernier mesurait des engagements, pas des mouvements d'argent. Une
 * transaction sans ligne comptable, comme en produisaient ces fixtures, ne
 * déplace donc rien : c'est le comportement voulu, en v5 toute transaction
 * réelle porte ses lignes.
 */
function transactionTresorerie(object $ctx, string $type, string $date, float $montant, array $extra = []): Transaction
{
    $tx = Transaction::factory()->create(array_merge([
        'type' => $type,
        'date' => $date,
        'montant_total' => $montant,
        'compte_id' => $ctx->compte->id,
        'rapprochement_id' => null,
    ], $extra));

    $compte512 = Compte::firstOrCreate(
        ['association_id' => (int) $ctx->association->id, 'numero_pcg' => '512'.$ctx->compte->id],
        [
            'intitule' => 'Banque '.$ctx->compte->id,
            'classe' => 5,
            'actif' => true,
            'lettrable' => false,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'compte_bancaire_id' => $ctx->compte->id,
        ]
    );

    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte512->id,
        'debit' => $type === 'recette' ? $montant : 0,
        'credit' => $type === 'depense' ? $montant : 0,
        'montant' => $montant,
    ]);

    return $tx;
}

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
    transactionTresorerie($this, 'recette', '2025-10-15', 5000.00);
    transactionTresorerie($this, 'depense', '2025-11-20', 2000.00);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['synthese']['total_recettes'])->toBe(5000.00);
    expect($data['synthese']['total_depenses'])->toBe(2000.00);
    expect($data['synthese']['variation'])->toBe(3000.00);
    expect($data['synthese']['solde_ouverture'])->toBe(10000.00);
    expect($data['synthese']['solde_theorique'])->toBe(13000.00);
});

it('ventile les flux par mois', function () {
    transactionTresorerie($this, 'recette', '2025-10-15', 3000.00);
    transactionTresorerie($this, 'depense', '2025-10-20', 1000.00);

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
    ]);

    transactionTresorerie($this, 'recette', '2025-10-01', 2000.00);

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
    transactionTresorerie($this, 'recette', '2025-10-01', 3000.00, ['rapprochement_id' => $rapprochement->id]);
    transactionTresorerie($this, 'recette', '2025-10-15', 1500.00);
    transactionTresorerie($this, 'depense', '2025-11-01', 500.00);

    $data = app(RapportService::class)->fluxTresorerie(2025);

    expect($data['rapprochement']['recettes_non_pointees'])->toBe(1500.00);
    expect($data['rapprochement']['nb_recettes_non_pointees'])->toBe(1);
    expect($data['rapprochement']['depenses_non_pointees'])->toBe(500.00);
    expect($data['rapprochement']['nb_depenses_non_pointees'])->toBe(1);
    expect($data['rapprochement']['solde_reel'])->toBe($data['rapprochement']['solde_theorique'] - 1500.00 + 500.00);
});

it('expose la liste des écritures non pointées pour le PDF', function () {
    transactionTresorerie($this, 'recette', '2025-10-15', 1500.00, [
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
