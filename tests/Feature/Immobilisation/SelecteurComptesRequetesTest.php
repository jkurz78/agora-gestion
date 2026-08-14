<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Contre-audit P2-04 — PlanComptableSelecteur::groupesPourType('immobilisation')
 * chargeait les comptes de classe 2 puis filtrait chaque compte via
 * ImmobilisationComptesSeeder::compteAmortissementPour(), qui exécute un
 * Compte::ofNumero() non caché. Soit une requête supplémentaire par compte de
 * classe 2, à chaque rendu du registre — modale d'acquisition fermée comprise,
 * puisque ImmobilisationIndex::render() construit le sélecteur sans condition.
 *
 * LivreRequetesTest mesure un autre axe (le nombre de fiches) et neutralise
 * délibérément celui-ci en donnant le même compte à toutes ses fiches. C'est
 * donc ici qu'on mesure la scalabilité du plan comptable lui-même.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    // Un couple = un compte d'immobilisation 21XX et son compte
    // d'amortissement dérivé 281XX, comme les pose le seeder. La plage 219XX
    // est libre : le seeder n'occupe que 2154/2183/2184/2188.
    $this->creerCouples = function (int $nombre, int $depart = 0): void {
        for ($i = $depart; $i < $depart + $nombre; $i++) {
            $numero = '219'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);

            Compte::factory()->create([
                'numero_pcg' => $numero,
                'classe' => 2,
                'actif' => true,
            ]);
            Compte::factory()->create([
                'numero_pcg' => '2'.'8'.substr($numero, 1),
                'classe' => 2,
                'actif' => true,
            ]);
        }
    };
});

it('le nombre de requêtes du sélecteur ne croît pas avec le nombre de comptes de classe 2', function (): void {
    ($this->creerCouples)(2);

    DB::flushQueryLog();
    DB::enableQueryLog();
    PlanComptableSelecteur::groupesPourType('immobilisation');
    $requetesAvecDeuxCouples = count(DB::getQueryLog());
    DB::disableQueryLog();

    ($this->creerCouples)(20, depart: 2);

    DB::flushQueryLog();
    DB::enableQueryLog();
    PlanComptableSelecteur::groupesPourType('immobilisation');
    $requetesAvecVingtDeuxCouples = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($requetesAvecVingtDeuxCouples)->toBe(
        $requetesAvecDeuxCouples,
        "Le sélecteur exécute {$requetesAvecDeuxCouples} requêtes avec 2 couples mais "
        ."{$requetesAvecVingtDeuxCouples} avec 22 : le compte d'amortissement dérivé "
        .'est encore résolu compte par compte.'
    );
});

it('ne propose que les comptes dont le compte d’amortissement dérivé existe et est actif', function (): void {
    // Couple complet → proposé.
    Compte::factory()->create(['numero_pcg' => '21901', 'classe' => 2, 'actif' => true]);
    Compte::factory()->create(['numero_pcg' => '281901', 'classe' => 2, 'actif' => true]);

    // Amortissement inactif → le compte doit disparaître du sélecteur.
    Compte::factory()->create(['numero_pcg' => '21902', 'classe' => 2, 'actif' => true]);
    Compte::factory()->create(['numero_pcg' => '281902', 'classe' => 2, 'actif' => false]);

    // Amortissement absent → idem.
    Compte::factory()->create(['numero_pcg' => '21903', 'classe' => 2, 'actif' => true]);

    $numerosProposes = PlanComptableSelecteur::groupesPourType('immobilisation')
        ->flatMap(fn (array $groupe) => $groupe['comptes']->pluck('numero_pcg'))
        ->all();

    expect($numerosProposes)->toContain('21901')
        ->and($numerosProposes)->not->toContain('21902')
        ->and($numerosProposes)->not->toContain('21903')
        ->and($numerosProposes)->not->toContain('281901');
});
