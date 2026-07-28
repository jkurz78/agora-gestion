<?php

declare(strict_types=1);

use App\Models\Association;
use Illuminate\Support\Facades\DB;

/*
 * Le 530 (Caisse — espèces) n'était seedé que pour les tenants ayant déjà une
 * transaction en espèces. Les tenants migrés sous cet ancien contrat n'ont donc
 * pas de 530 : cette migration le leur ajoute.
 */

it('crée le compte 530 pour un tenant existant qui n’en a pas', function (): void {
    $association = Association::factory()->create();

    // Simule un tenant migré sous l'ancien contrat conditionnel : pas de 530.
    DB::table('comptes')
        ->where('association_id', (int) $association->id)
        ->where('numero_pcg', '530')
        ->delete();

    $migration = require base_path('database/migrations/2026_07_28_000001_add_compte_530_system.php');
    $migration->up();

    $compte = DB::table('comptes')
        ->where('association_id', (int) $association->id)
        ->where('numero_pcg', '530')
        ->first();

    expect($compte)->not->toBeNull()
        ->and((int) $compte->classe)->toBe(5)
        ->and((bool) $compte->est_systeme)->toBeTrue()
        ->and((bool) $compte->lettrable)->toBeTrue()
        ->and($compte->intitule)->toBe('Caisse (espèces)');
});

it('est idempotente : rejouer la migration ne duplique pas le 530', function (): void {
    $association = Association::factory()->create();

    $migration = require base_path('database/migrations/2026_07_28_000001_add_compte_530_system.php');
    $migration->up();
    $migration->up();

    expect(DB::table('comptes')
        ->where('association_id', (int) $association->id)
        ->where('numero_pcg', '530')
        ->count())->toBe(1);
});
