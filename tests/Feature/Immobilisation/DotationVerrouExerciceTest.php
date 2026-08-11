<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Anomalie 3 (audit) — assertExerciceGenerable() consultait le statut de
 * l'exercice AVANT d'ouvrir la transaction d'écriture, et sans verrou. Une
 * clôture concurrente pouvait s'intercaler entre le contrôle et l'insertion.
 *
 * Limite assumée (cf. rapport) : la suite tourne sur SQLite :memory:
 * (une connexion, verrous no-op — Illuminate\Database\Query\Grammars\
 * SQLiteGrammar::compileLock() retourne ''). On ne peut donc pas ici
 * démontrer une exclusion mutuelle réelle entre deux transactions
 * concurrentes (ça se joue sous MySQL, comme ExerciceService::cloturer()).
 * Ce que le test ci-dessous prouve réellement : la requête de contrôle sur
 * `exercices` et la requête d'écriture dans `immobilisation_dotations`
 * s'exécutent toutes les deux à l'intérieur d'une seule et même transaction
 * ouverte (aucun commit ne les sépare) — la condition structurelle sans
 * laquelle un vrai verrou ne protégerait rien.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->creerImmo = function (): Immobilisation {
        return app(ImmobilisationService::class)->acquerir(
            tiers: Tiers::factory()->create(),
            libelle: '20 tenues d’escrime',
            quantite: 20,
            compte: Compte::ofNumero('2188'),
            compteAmortissement: Compte::ofNumero('28188'),
            montant: '3000.00',
            dateAchat: Carbon::parse('2026-09-12'),
            dateMiseEnService: Carbon::parse('2026-09-12'),
            dureeMois: 60,
            modePaiement: null,
            compteTresorerie: null,
        );
    };
});

it('contrôle l’ouverture de l’exercice dans la même transaction que l’écriture de la dotation', function (): void {
    ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');

    $requetes = [];

    DB::listen(function ($query) use (&$requetes): void {
        $requetes[] = [
            'sql' => $query->sql,
            'niveau' => $query->connection->transactionLevel(),
        ];
    });

    app(DotationService::class)->generer(2026);

    $indexEcriture = null;

    foreach ($requetes as $i => $r) {
        if ($indexEcriture === null
            && str_contains($r['sql'], 'immobilisation_dotations')
            && str_starts_with(strtolower($r['sql']), 'insert')) {
            $indexEcriture = $i;
        }
    }

    // Le contrôle pertinent est celui qui précède IMMÉDIATEMENT (dans le même
    // passage) l'écriture — pas le premier contrôle rencontré dans le test :
    // generer() garde un appel de filet de sécurité en tête, hors verrou, qui
    // ne prouve rien à lui seul (cf. docblock du fichier).
    $indexControle = null;

    for ($i = $indexEcriture; $i >= 0; $i--) {
        if (str_contains($requetes[$i]['sql'], 'exercices')) {
            $indexControle = $i;
            break;
        }
    }

    expect($indexControle)->not->toBeNull()
        ->and($indexEcriture)->not->toBeNull()
        ->and($indexControle)->toBeLessThan($indexEcriture);

    // RefreshDatabase enveloppe déjà chaque test dans sa propre transaction
    // (niveau ≥ 1 en permanence) : un simple « niveau ≥ 1 » ne prouverait
    // donc rien. Ce qui distingue « même transaction » de « deux transactions
    // successives » ici, c'est le NIVEAU exact : si le contrôle tourne au
    // niveau N (le DB::transaction() de generer()/comptabiliser() a été
    // ouvert) et que l'écriture tourne aussi au niveau N — sans jamais
    // redescendre en dessous entre les deux — alors les deux appartiennent au
    // même DB::transaction(). Si l'écriture se fait à un niveau différent
    // (typiquement N+1, ouvert APRÈS un retour au niveau N — donc après un
    // commit de savepoint intermédiaire), c'est que le contrôle a eu lieu
    // dans une transaction distincte de celle de l'écriture : exactement le
    // bug de l'anomalie 3.
    $niveauPartage = $requetes[$indexControle]['niveau'];

    expect($niveauPartage)->toBeGreaterThan(1, 'le contrôle doit se faire à l’intérieur d’un DB::transaction() applicatif, pas seulement celui de RefreshDatabase')
        ->and($requetes[$indexEcriture]['niveau'])->toBe($niveauPartage);

    for ($i = $indexControle; $i <= $indexEcriture; $i++) {
        expect($requetes[$i]['niveau'])->toBeGreaterThanOrEqual($niveauPartage);
    }

    Carbon::setTestNow();
});

it('refuse de recalculer une dotation sur un exercice clôturé', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    expect(fn () => app(DotationService::class)->recalculer($immo->fresh(), 2026))
        ->toThrow(DotationInterditeException::class);

    Carbon::setTestNow();
});

it('refuse d’annuler une dotation sur un exercice clôturé', function (): void {
    $immo = ($this->creerImmo)();
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    expect(fn () => app(DotationService::class)->annuler($immo->fresh(), 2026))
        ->toThrow(DotationInterditeException::class);

    Carbon::setTestNow();
});
