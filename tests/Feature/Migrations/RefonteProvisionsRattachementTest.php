<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedMigrationDatabase;

/*
 * La migration réécrit une table financière. Elle suppose la table vide, et
 * refuse de s'exécuter si elle ne l'est pas, plutôt que de purger : un delete
 * laisserait orphelines les écritures OD de l'ancien schéma
 * (transactions.provision_id est en nullOnDelete). Elle retire aussi la colonne
 * deleted_at (le retrait d'un rattachement est physique, spec § 6.4) et la
 * rétablit au retour arrière ; la garde de table vide vaut dans les deux états
 * de cette colonne.
 *
 * La fin du message de refus diffère selon le sens : à la montée, des provisions de
 * l'ancien écran (l'écran du lot n'existe pas encore) ; au retour arrière, des
 * rattachements du nouveau schéma, chacun avec ses deux écritures, que l'écran
 * « Exercices > Écritures de rattachement » retire avec elles.
 */

/** Les requêtes que le message d'erreur donne à l'opérateur : elles doivent être exécutables telles quelles. */
const REFONTE_PROVISIONS_VERIFICATION_SQL = 'SELECT COUNT(*) FROM transactions WHERE provision_id IS NOT NULL';
const REFONTE_PROVISIONS_PURGE_SQL = 'DELETE FROM provisions WHERE deleted_at IS NOT NULL';

/**
 * Monte la table provisions telle qu'elle est AVANT la migration (schéma de l'ancien écran).
 *
 * @param  Closure(): void  $fixtures
 * @param  Closure(object): mixed  $test  reçoit l'instance de la migration
 */
function refonteProvisionsAvecAncienSchema(Closure $fixtures, Closure $test): mixed
{
    return IsolatedMigrationDatabase::run(function () use ($fixtures): void {
        Schema::create('transaction_lignes', fn (Blueprint $table) => $table->id());
        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('provision_id')->nullable();
        });
        Schema::create('provisions', function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('exercice');
            $table->string('type', 10);
            $table->string('libelle', 255);
            $table->decimal('montant', 10, 2);
            $table->date('date');
            $table->timestamps();
            $table->softDeletes();
        });

        $fixtures();
    }, function () use ($test): mixed {
        $migration = require base_path('database/migrations/2026_10_01_100001_refonte_provisions_rattachement.php');

        return $test($migration);
    });
}

/** Une ligne de l'ancien schéma, éventuellement supprimée logiquement. */
function refonteProvisionsInsererAncienneLigne(?string $supprimeeLe = null): void
{
    DB::table('provisions')->insert([
        'exercice' => 2025,
        'type' => 'depense',
        'libelle' => 'Ancienne provision',
        'montant' => 100,
        'date' => '2026-08-31',
        'deleted_at' => $supprimeeLe,
    ]);
}

/**
 * Un rattachement du NOUVEAU schéma, tel que la production le portera : la ligne de
 * provisions et ses deux écritures (dotation et extourne) dans transactions.
 *
 * @return int l'id du rattachement
 */
function refonteProvisionsInsererRattachement(): int
{
    $id = (int) DB::table('provisions')->insertGetId([
        'exercice' => 2025,
        'libelle' => 'Rattachement',
        'montant' => 175,
        'transaction_ligne_id' => null,
    ]);

    DB::table('transactions')->insert([
        ['provision_id' => $id], // la dotation
        ['provision_id' => $id], // l'extourne
    ]);

    return $id;
}

/**
 * Le retrait par l'écran « Exercices > Écritures de rattachement » : il supprime le
 * rattachement ET ses deux écritures. Simulé ici, ce schéma minimal n'ayant pas d'écran.
 */
function refonteProvisionsRetirerRattachementParEcran(int $id): void
{
    DB::table('transactions')->where('provision_id', $id)->delete();
    DB::table('provisions')->where('id', $id)->delete();
}

it('réécrit la table vide : colonne d’adossement ajoutée, type, date et deleted_at retirés, libellé facultatif', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => null, function ($migration): void {
        // Garde anti-test-vide : l'ancien schéma porte bien la suppression logique.
        expect(Schema::hasColumn('provisions', 'deleted_at'))->toBeTrue();

        $migration->up();

        expect(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'type'))->toBeFalse()
            ->and(Schema::hasColumn('provisions', 'date'))->toBeFalse()
            ->and(Schema::hasColumn('provisions', 'deleted_at'))->toBeFalse();

        $libelle = collect(Schema::getColumns('provisions'))->firstWhere('name', 'libelle');
        expect($libelle['nullable'])->toBeTrue();
    });
});

it('refuse de réécrire une table qui contient des rattachements actifs, et ne propose pas de les purger', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => refonteProvisionsInsererAncienneLigne(), function ($migration): void {
        $erreur = null;
        try {
            $migration->up();
        } catch (RuntimeException $e) {
            $erreur = $e->getMessage();
        }

        expect($erreur)->not->toBeNull()
            ->toContain('1 ligne(s) active(s), 0 ligne(s) supprimée(s) logiquement')
            ->toContain(REFONTE_PROVISIONS_VERIFICATION_SQL)
            ->toContain('Des provisions de l\'ancien écran existent : ne les supprimez pas à la main')
            ->toContain('n\'allez pas plus loin et examinez-les avec leurs écritures')
            // La sortie du retour arrière n'a pas de sens ici : l'écran du lot n'existe pas encore.
            ->not->toContain('Écritures de rattachement')
            ->not->toContain('arrêtez la migration')
            ->not->toContain('DELETE FROM');

        // Rien n'a été touché : ni la ligne, ni le schéma.
        expect(DB::table('provisions')->count())->toBe(1)
            ->and(Schema::hasColumn('provisions', 'type'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeFalse();
    });
});

it('compte à part les rattachements supprimés logiquement et donne la sortie : vérifier, purger, relancer', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => refonteProvisionsInsererAncienneLigne('2026-01-01 00:00:00'), function ($migration): void {
        $erreur = null;
        try {
            $migration->up();
        } catch (RuntimeException $e) {
            $erreur = $e->getMessage();
        }

        expect($erreur)->not->toBeNull()
            ->toContain('0 ligne(s) active(s), 1 ligne(s) supprimée(s) logiquement')
            ->toContain(REFONTE_PROVISIONS_VERIFICATION_SQL.';')
            ->toContain(REFONTE_PROVISIONS_PURGE_SQL.';')
            // Deux sorties qui se contredisent : purger, ou ne rien supprimer à la main.
            ->not->toContain('ne les supprimez pas à la main');

        // L'opérateur suit le message à la lettre : la vérification vaut 0 ...
        expect((int) array_values((array) DB::selectOne(REFONTE_PROVISIONS_VERIFICATION_SQL))[0])->toBe(0);

        // ... il purge ...
        DB::statement(REFONTE_PROVISIONS_PURGE_SQL);

        // ... et la migration passe.
        $migration->up();

        expect(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'type'))->toBeFalse()
            ->and(Schema::hasColumn('provisions', 'deleted_at'))->toBeFalse();
    });
});

it('refuse de réécrire une table qui contient à la fois des lignes actives et supprimées, et les compte chacune', function (): void {
    refonteProvisionsAvecAncienSchema(function (): void {
        refonteProvisionsInsererAncienneLigne();
        refonteProvisionsInsererAncienneLigne('2026-01-01 00:00:00');
        refonteProvisionsInsererAncienneLigne('2026-02-01 00:00:00');
    }, function ($migration): void {
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, '1 ligne(s) active(s), 2 ligne(s) supprimée(s) logiquement');
    });
});

it('rétablit l’ancien schéma sur une table vide', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => null, function ($migration): void {
        $migration->up();
        $migration->down();

        expect(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeFalse()
            ->and(Schema::hasColumn('provisions', 'type'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'date'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'deleted_at'))->toBeTrue();
    });
});

it('refuse de revenir à l’ancien schéma tant que la table contient des rattachements, et donne la sortie : l’écran de rattachement', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => null, function ($migration): void {
        $migration->up();

        // Garde anti-test-vide : la garde s'exécute bien dans l'état « colonne retirée ».
        // Une garde qui interrogerait encore deleted_at échouerait sur une erreur SQL,
        // pas sur le refus lisible que ce test attend.
        expect(Schema::hasColumn('provisions', 'deleted_at'))->toBeFalse();

        // Un rattachement réaliste : sa ligne de provisions ET ses deux écritures.
        $id = refonteProvisionsInsererRattachement();

        $erreur = null;
        try {
            $migration->down();
        } catch (RuntimeException $e) {
            $erreur = $e->getMessage();
        }

        // Sans suppression logique, rien à distinguer ni à purger : le message ne
        // propose pas une requête visant une colonne absente. Et la sortie est celle
        // du retour arrière : l'écran, qui retire aussi les écritures.
        expect($erreur)->not->toBeNull()
            ->toContain('au retour arrière : la table provisions n\'est pas vide')
            ->toContain('1 ligne(s) (la table n\'a plus de suppression logique : toutes sont actives)')
            ->toContain(REFONTE_PROVISIONS_VERIFICATION_SQL)
            ->toContain('Des rattachements existent, chacun avec ses deux écritures')
            ->toContain('Exercices > Écritures de rattachement')
            ->toContain('qui supprime aussi leurs écritures')
            ->toContain('Ne les supprimez pas à la main')
            // Les consignes de la montée, fausses dans ce sens.
            ->not->toContain('Vérifiez d\'abord')
            ->not->toContain('arrêtez la migration')
            ->not->toContain('ancien écran')
            ->not->toContain('deleted_at')
            ->not->toContain('DELETE FROM');

        // La requête donnée à l'opérateur reste exécutable telle quelle, et compte
        // bien les deux écritures du rattachement (elle ne vaut donc PAS 0 ici).
        expect((int) array_values((array) DB::selectOne(REFONTE_PROVISIONS_VERIFICATION_SQL))[0])->toBe(2);

        // Le schéma réécrit est resté en place : la colonne n'a pas été rétablie ; et le
        // rattachement comme ses écritures n'ont pas été touchés.
        expect(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'type'))->toBeFalse()
            ->and(Schema::hasColumn('provisions', 'deleted_at'))->toBeFalse()
            ->and(DB::table('provisions')->where('id', $id)->count())->toBe(1)
            ->and(DB::table('transactions')->where('provision_id', $id)->count())->toBe(2);
    });
});

it('rétablit deleted_at au retour arrière, et le retour arrière est rejouable une fois la table vidée', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => null, function ($migration): void {
        $migration->up();

        $id = refonteProvisionsInsererRattachement();

        // Garde anti-test-vide : le rattachement porte bien ses deux écritures.
        expect(DB::table('transactions')->where('provision_id', $id)->count())->toBe(2);

        expect(fn () => $migration->down())
            ->toThrow(RuntimeException::class, 'au retour arrière : la table provisions n\'est pas vide');

        // Rattachement retiré par l'écran (avec ses écritures) — simulé ici, ce schéma
        // minimal n'a pas d'écran : le retour arrière passe.
        refonteProvisionsRetirerRattachementParEcran($id);

        expect(DB::table('transactions')->where('provision_id', $id)->count())->toBe(0);

        $migration->down();

        expect(Schema::hasColumn('provisions', 'deleted_at'))->toBeTrue();

        // Et la colonne rétablie est utilisable comme avant : une ligne supprimée logiquement
        // est comptée par la garde de la montée suivante.
        refonteProvisionsInsererAncienneLigne('2026-01-01 00:00:00');

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, '0 ligne(s) active(s), 1 ligne(s) supprimée(s) logiquement');
    });
});
