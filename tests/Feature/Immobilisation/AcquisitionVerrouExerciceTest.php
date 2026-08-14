<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contre-audit P1-01 — acquerir() consultait le statut de l'exercice AVANT
 * d'ouvrir sa transaction, et sans verrou ; modifier() n'ouvrait aucune
 * transaction du tout. Une clôture concurrente pouvait donc s'intercaler
 * entre le contrôle et l'écriture, et l'application se retrouvait avec une
 * acquisition (ou une modification de fiche) postérieure à la clôture de
 * l'exercice qui la porte.
 *
 * DotationService::assertExerciceGenerable() avait déjà reçu ce traitement
 * (Anomalie 3) ; les deux mutations de la fiche elle-même étaient restées en
 * dehors. Le protocole est désormais le même partout, et partagé :
 * ExerciceService::verrouillerPourEcriture() — association puis exercice,
 * dans l'ordre canonique de ExerciceService::cloturer(), sans quoi clôture et
 * acquisition s'inter-bloqueraient au lieu de s'exclure.
 *
 * Même limite assumée que DotationVerrouExerciceTest : sur SQLite les verrous
 * sont compilés en no-op (SQLiteGrammar::compileLock() retourne ''), on ne
 * peut y prouver que la condition structurelle — contrôle et écriture dans une
 * seule et même transaction ouverte. La présence effective du `for update` est
 * vérifiée plus bas, sur MySQL uniquement.
 */
beforeEach(function (): void {
    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->acquerir = function (): Immobilisation {
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

    /**
     * Repère, dans le journal des requêtes, l'index de la première écriture
     * ciblant $table et celui du dernier contrôle sur `exercices` qui la
     * précède, avec le niveau de transaction de chacun.
     *
     * @return array{controle: ?int, ecriture: ?int, requetes: list<array{sql: string, niveau: int}>}
     */
    $this->reperer = function (array $requetes, string $table, string $verbe): array {
        $indexEcriture = null;

        foreach ($requetes as $i => $r) {
            if ($indexEcriture === null
                && str_contains($r['sql'], $table)
                && str_starts_with(strtolower($r['sql']), $verbe)) {
                $indexEcriture = $i;
            }
        }

        $indexControle = null;

        for ($i = $indexEcriture ?? -1; $i >= 0; $i--) {
            if (str_contains($requetes[$i]['sql'], 'exercices')) {
                $indexControle = $i;
                break;
            }
        }

        return ['controle' => $indexControle, 'ecriture' => $indexEcriture, 'requetes' => $requetes];
    };

    $this->journaliser = function (callable $action): array {
        $requetes = [];

        DB::listen(function ($query) use (&$requetes): void {
            $requetes[] = [
                'sql' => $query->sql,
                'niveau' => $query->connection->transactionLevel(),
            ];
        });

        $action();

        return $requetes;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Voir le docblock du fichier pour le raisonnement sur les niveaux de
 * transaction : RefreshDatabase maintient le niveau ≥ 1 en permanence, seul
 * un niveau PARTAGÉ et jamais redescendu entre contrôle et écriture prouve
 * qu'ils appartiennent au même DB::transaction() applicatif.
 */
function attendreMemeTransaction(array $repere, string $quoi): void
{
    expect($repere['controle'])->not->toBeNull("aucun contrôle sur `exercices` avant l’écriture ({$quoi})")
        ->and($repere['ecriture'])->not->toBeNull("aucune écriture repérée ({$quoi})")
        ->and($repere['controle'])->toBeLessThan($repere['ecriture']);

    $niveauPartage = $repere['requetes'][$repere['controle']]['niveau'];

    expect($niveauPartage)->toBeGreaterThan(
        1,
        "le contrôle doit se faire dans un DB::transaction() applicatif, pas seulement celui de RefreshDatabase ({$quoi})"
    )->and($repere['requetes'][$repere['ecriture']]['niveau'])->toBe($niveauPartage);

    for ($i = $repere['controle']; $i <= $repere['ecriture']; $i++) {
        expect($repere['requetes'][$i]['niveau'])->toBeGreaterThanOrEqual($niveauPartage);
    }
}

it('contrôle l’ouverture de l’exercice dans la même transaction que la création de la fiche', function (): void {
    $requetes = ($this->journaliser)(fn () => ($this->acquerir)());

    attendreMemeTransaction(
        ($this->reperer)($requetes, 'immobilisations', 'insert'),
        'acquisition'
    );
});

it('contrôle l’ouverture de l’exercice dans la même transaction que la modification de la fiche', function (): void {
    $immo = ($this->acquerir)();

    $requetes = ($this->journaliser)(fn () => app(ImmobilisationService::class)->modifier(
        immobilisation: $immo,
        libelle: 'Tenues d’escrime (lot complet)',
        quantite: 22,
        dureeMois: 72,
        dateMiseEnService: Carbon::parse('2026-10-01'),
        notes: null,
    ));

    attendreMemeTransaction(
        ($this->reperer)($requetes, 'immobilisations', 'update'),
        'modification'
    );
});

it('verrouille réellement l’exercice pendant l’acquisition', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite compile les verrous en no-op : contrôle non observable.');
    }

    $requetes = ($this->journaliser)(fn () => ($this->acquerir)());

    $verrouExercice = collect($requetes)->first(
        fn (array $r): bool => str_contains($r['sql'], 'from `exercices`')
            && str_contains($r['sql'], 'for update')
    );

    expect($verrouExercice)->not->toBeNull(
        'Aucun `select ... from `exercices` ... for update` : l’exercice est lu sans verrou.'
    );
});

it('verrouille réellement l’exercice pendant la modification', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite compile les verrous en no-op : contrôle non observable.');
    }

    $immo = ($this->acquerir)();

    $requetes = ($this->journaliser)(fn () => app(ImmobilisationService::class)->modifier(
        immobilisation: $immo,
        libelle: 'Tenues d’escrime (lot complet)',
        quantite: 22,
        dureeMois: 72,
        dateMiseEnService: Carbon::parse('2026-10-01'),
        notes: null,
    ));

    $verrouExercice = collect($requetes)->first(
        fn (array $r): bool => str_contains($r['sql'], 'from `exercices`')
            && str_contains($r['sql'], 'for update')
    );

    expect($verrouExercice)->not->toBeNull(
        'Aucun `select ... from `exercices` ... for update` : l’exercice est lu sans verrou.'
    );
});

it('refuse toujours une acquisition sur un exercice clôturé', function (): void {
    Exercice::create(['annee' => 2026, 'statut' => StatutExercice::Cloture]);

    expect(fn (): Immobilisation => ($this->acquerir)())
        ->toThrow(ExerciceCloturedException::class);

    expect(Immobilisation::count())->toBe(0);
});
