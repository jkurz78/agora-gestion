<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\ImmobilisationIndex;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Audit — ImmobilisationIndex::render() charge bien les dotations en eager
 * loading (with(['compte', 'dotations'])), mais Immobilisation::cumulAmortiCentimes()
 * ignorait la relation déjà chargée et relançait dotations()->sum('montant') à
 * chaque appel. Ce cumul est sollicité plusieurs fois par fiche : la colonne
 * « amortissements » (valeur + data-sort), la colonne « valeur nette » qui
 * l'appelle en interne (valeur + data-sort), et les totaux de pied de tableau
 * — une avalanche de requêtes strictement proportionnelle au nombre de fiches.
 *
 * Le test ne se contente pas d'un plafond arbitraire : il rend le même livre
 * avec 2 puis 6 fiches, chacune dotée d'une dotation comptabilisée, et prouve
 * que le nombre de requêtes SQL exécutées est identique dans les deux cas.
 *
 * Toutes les fiches synthétiques partagent le même compte d'immobilisation
 * (comme le ferait une vraie association, qui a une poignée de comptes
 * classe 2 pour des dizaines de fiches) : un compte différent par fiche
 * fausserait la mesure en faisant grossir, avec le nombre de fiches, le
 * nombre de comptes que PlanComptableSelecteur::groupesPourType() doit
 * parcourir pour le sélecteur du formulaire — un axe de scalabilité distinct
 * de celui testé ici.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmoAvecDotation = function (): Immobilisation {
        $immobilisation = Immobilisation::factory()->create([
            'compte_id' => Compte::ofNumero('2188')->id,
            'compte_amortissement_id' => Compte::ofNumero('28188')->id,
        ]);

        ImmobilisationDotation::create([
            'immobilisation_id' => $immobilisation->id,
            'exercice' => 2025,
            'montant' => '100.00',
            'transaction_id' => Transaction::factory()->create()->id,
        ]);

        return $immobilisation;
    };
});

it('le nombre de requêtes du livre ne croît pas avec le nombre de fiches', function (): void {
    ($this->creerImmoAvecDotation)();
    ($this->creerImmoAvecDotation)();

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ImmobilisationIndex::class);
    $requetesAvecDeuxFiches = count(DB::getQueryLog());
    DB::disableQueryLog();

    for ($i = 0; $i < 4; $i++) {
        ($this->creerImmoAvecDotation)();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(ImmobilisationIndex::class);
    $requetesAvecSixFiches = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($requetesAvecSixFiches)->toBe(
        $requetesAvecDeuxFiches,
        "Le livre exécute {$requetesAvecDeuxFiches} requêtes avec 2 fiches mais "
        ."{$requetesAvecSixFiches} avec 6 : le cumul amorti déclenche encore une requête par fiche."
    );
});
