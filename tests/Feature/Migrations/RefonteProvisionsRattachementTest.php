<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedMigrationDatabase;

/*
 * La migration réécrit une table financière. Elle suppose la table vide, et
 * refuse de s'exécuter si elle ne l'est pas, plutôt que de purger : un delete
 * contournerait les SoftDeletes et laisserait orphelines les écritures OD de
 * l'ancien schéma (transactions.provision_id est en nullOnDelete).
 */

/** Les deux requêtes que le message d'erreur donne à l'opérateur : elles doivent être exécutables telles quelles. */
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

it('réécrit la table vide : colonne d’adossement ajoutée, type et date retirés, libellé facultatif', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => null, function ($migration): void {
        $migration->up();

        expect(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'type'))->toBeFalse()
            ->and(Schema::hasColumn('provisions', 'date'))->toBeFalse();

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
            ->toContain(REFONTE_PROVISIONS_PURGE_SQL.';');

        // L'opérateur suit le message à la lettre : la vérification vaut 0 ...
        expect((int) array_values((array) DB::selectOne(REFONTE_PROVISIONS_VERIFICATION_SQL))[0])->toBe(0);

        // ... il purge ...
        DB::statement(REFONTE_PROVISIONS_PURGE_SQL);

        // ... et la migration passe.
        $migration->up();

        expect(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'type'))->toBeFalse();
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
            ->and(Schema::hasColumn('provisions', 'date'))->toBeTrue();
    });
});

it('refuse de revenir à l’ancien schéma tant que la table contient des rattachements', function (): void {
    refonteProvisionsAvecAncienSchema(fn () => null, function ($migration): void {
        $migration->up();

        DB::table('provisions')->insert([
            'exercice' => 2025,
            'libelle' => 'Rattachement',
            'montant' => 175,
            'transaction_ligne_id' => null,
        ]);

        expect(fn () => $migration->down())
            ->toThrow(RuntimeException::class, 'au retour arrière : la table provisions n\'est pas vide');

        // Le schéma réécrit est resté en place.
        expect(Schema::hasColumn('provisions', 'transaction_ligne_id'))->toBeTrue()
            ->and(Schema::hasColumn('provisions', 'type'))->toBeFalse();
    });
});
