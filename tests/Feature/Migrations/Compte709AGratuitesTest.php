<?php

declare(strict_types=1);

/**
 * Migration 2026_08_20_100000 — création du compte 709A « Gratuités accordées ».
 *
 * L'index `comptes_asso_numero_pcg_unique` porte sur (association_id,
 * numero_pcg) SEULS : il inclut donc les lignes soft-deleted. Un 709A supprimé
 * occupe la place. Le `WHERE NOT EXISTS (… deleted_at IS NULL)` de la migration
 * ne le voyait pas, l'INSERT partait, l'index le refusait — et `INSERT IGNORE`
 * (MySQL) / `INSERT OR IGNORE` (SQLite) avalait le refus sans un mot.
 *
 * Résultat : aucun 709A actif, aucun usage Gratuite, et la synchro HelloAsso
 * sans compte de contrepartie pour ses remises. En silence.
 */

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

function rejouerMigration709A(): void
{
    $migration = require base_path('database/migrations/2026_08_20_100000_add_compte_709a_gratuites.php');
    $migration->up();
}

/**
 * Repart d'une association sans aucune ligne 709A : la migration du bootstrap
 * de tests en a déjà créé un pour toute association existant à ce moment-là.
 */
function associationSansCompte709A(): Association
{
    $association = Association::factory()->create();
    TenantContext::boot($association);
    DB::table('comptes')->where('association_id', $association->id)->where('numero_pcg', '709A')->delete();

    return $association;
}

it('crée le 709A et lui rattache l\'usage Gratuité', function (): void {
    $association = associationSansCompte709A();

    rejouerMigration709A();

    $compte = Compte::forUsage(UsageComptable::Gratuite)->first();

    expect($compte)->not->toBeNull();
    expect($compte->numero_pcg)->toBe('709A');
    expect((int) $compte->association_id)->toBe((int) $association->id);
});

it('restaure un 709A soft-deleted au lieu de laisser l\'association sans compte de gratuité', function (): void {
    $association = associationSansCompte709A();

    $id = DB::table('comptes')->insertGetId([
        'association_id' => $association->id,
        'numero_pcg' => '709A',
        'intitule' => 'Gratuités accordées',
        'classe' => 7,
        'actif' => 0,
        'est_systeme' => 0,
        'pour_inscriptions' => 0,
        'lettrable' => 0,
        'deleted_at' => '2026-01-01 00:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    rejouerMigration709A();

    $compte = Compte::forUsage(UsageComptable::Gratuite)->first();

    expect($compte)->not->toBeNull('Sans restauration, l\'INSERT IGNORE échoue en silence et aucun compte ne porte l\'usage.');
    expect((int) $compte->id)->toBe((int) $id, 'L\'index unique interdit un second 709A : c\'est forcément celui-là qui revient.');
    expect((bool) $compte->actif)->toBeTrue('Un compte restauré doit être utilisable, comme celui que l\'INSERT aurait créé.');
});

it('est idempotente — un second passage ne duplique ni compte ni usage', function (): void {
    $association = associationSansCompte709A();

    rejouerMigration709A();
    rejouerMigration709A();

    expect(Compte::where('numero_pcg', '709A')->count())->toBe(1);
    expect(DB::table('usages_comptes')
        ->where('association_id', $association->id)
        ->where('usage', UsageComptable::Gratuite->value)
        ->count())->toBe(1);
});
