<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Support\Demo\DateDelta;
use App\Support\Demo\SnapshotConfig;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Yaml\Yaml;

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
    Carbon::setTestNow(null);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a minimal valid snapshot YAML and write it to a temp file.
 * Returns the path to the temp file.
 *
 * @param  array<string, mixed>  $overrides  top-level YAML overrides
 */
function buildMinimalSnapshot(Carbon $ref, array $overrides = []): string
{
    $capturedAt = $ref->toIso8601String();

    // -120d relative to ref
    $minus120 = DateDelta::toDelta($ref->copy()->subDays(120), $ref);
    // -2d relative to ref
    $minus2 = DateDelta::toDelta($ref->copy()->subDays(2), $ref);

    $snapshot = array_merge([
        'captured_at' => $capturedAt,
        'schema_version' => 1,
        'tables' => [
            'association' => [
                [
                    'id' => 1,
                    'nom' => 'Démo AgoraGestion',
                    'slug' => 'demo',
                    'adresse' => '1 rue de la Démo',
                    'code_postal' => '69001',
                    'ville' => 'Lyon',
                    'statut' => 'actif',
                    'exercice_mois_debut' => 9,
                    'devis_validite_jours' => 30,
                    'wizard_completed_at' => $minus120,
                    'created_at' => $minus120,
                    'updated_at' => $minus2,
                ],
            ],
            'users' => [
                [
                    'id' => 1,
                    'derniere_association_id' => 1,
                    'email' => 'admin@demo.fr',
                    'password' => SnapshotConfig::DEMO_USER_PASSWORD_HASH,
                    'nom' => 'ADMIN Demo',
                    'role_systeme' => 'user',
                    'peut_voir_donnees_sensibles' => 1,
                    'email_verified_at' => $minus120,
                    'created_at' => $minus120,
                    'updated_at' => $minus2,
                ],
            ],
        ],
        'files' => [],
    ], $overrides);

    $yaml = Yaml::dump($snapshot, 8, 2);
    $path = sys_get_temp_dir().'/demo-reset-test-'.uniqid().'.yaml';
    file_put_contents($path, $yaml);

    return $path;
}

// ---------------------------------------------------------------------------
// T1 — env != demo → exit ≠ 0
// ---------------------------------------------------------------------------
it('refuses to run outside demo environment', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->artisan('demo:reset')
        ->expectsOutputToContain('hors environnement démo')
        ->assertFailed();
});

// ---------------------------------------------------------------------------
// T2 — snapshot OK + env demo → DB peuplée, dates réhydratées, password OK
// ---------------------------------------------------------------------------
it('resets DB from valid snapshot with correct date rehydration and password', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $ref = Carbon::parse('2026-04-15T10:00:00+00:00');
    $snapshotPath = buildMinimalSnapshot($ref);

    // Fix now() to 2026-05-15 so that -120d = 2026-01-15 and -2d = 2026-05-13
    Carbon::setTestNow('2026-05-15 12:00:00');

    // Clear existing rows inserted by the global beforeEach (association + user).
    // --skip-migrate avoids calling migrate:fresh inside a RefreshDatabase transaction.
    TenantContext::clear();
    DB::statement('PRAGMA foreign_keys = OFF');
    DB::table('association_user')->delete();
    DB::table('users')->delete();
    DB::table('association')->delete();
    DB::statement('PRAGMA foreign_keys = ON');

    $exitCode = $this->artisan('demo:reset', [
        '--snapshot' => $snapshotPath,
        '--skip-migrate' => true,
    ])->execute();

    expect($exitCode)->toBe(0);

    // Check association was inserted
    $assoCount = DB::table('association')->count();
    expect($assoCount)->toBe(1);

    $asso = DB::table('association')->first();
    expect($asso->nom)->toBe('Démo AgoraGestion');

    // -120d from 2026-05-15 = 2026-01-15
    $expectedCreatedAt = Carbon::parse('2026-05-15 12:00:00')->subDays(120)->startOfDay();
    $actualCreatedAt = Carbon::parse($asso->created_at)->startOfDay();
    expect($actualCreatedAt->equalTo($expectedCreatedAt))->toBeTrue(
        "created_at rehydration failed: expected {$expectedCreatedAt->toDateString()}, got {$actualCreatedAt->toDateString()}"
    );

    // Check user was inserted
    $user = DB::table('users')->where('email', 'admin@demo.fr')->first();
    expect($user)->not->toBeNull();

    // DEMO_USER_PASSWORD_HASH must verify against 'demo'
    expect(Hash::check('demo', $user->password))->toBeTrue();

    @unlink($snapshotPath);
});

// ---------------------------------------------------------------------------
// T3 — try/finally : YAML corrompu → exit ≠ 0 mais app remontée
// ---------------------------------------------------------------------------
it('calls artisan up in finally even when snapshot is corrupted', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    // Write a corrupted YAML file
    $corruptPath = sys_get_temp_dir().'/demo-reset-corrupt-'.uniqid().'.yaml';
    file_put_contents($corruptPath, "tables: [invalid yaml: {unclosed bracket\n  bad: [indent");

    // We need to verify 'up' is called in finally.
    // Strategy: after the command fails, the app must NOT be in maintenance mode.
    // migrate:fresh runs before the YAML load, so the DB is wiped but app comes back up.

    $exitCode = $this->artisan('demo:reset', ['--snapshot' => $corruptPath])->execute();

    // Command must fail
    expect($exitCode)->not->toBe(0);

    // App must be back up (finally guarantee)
    expect(app()->isDownForMaintenance())->toBeFalse('artisan up must be called in finally');

    @unlink($corruptPath);
});

// ---------------------------------------------------------------------------
// T4 — snapshot inexistant → exit ≠ 0 + app remontée
// ---------------------------------------------------------------------------
it('fails with error when snapshot file does not exist and still brings app back up', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $exitCode = $this->artisan('demo:reset', ['--snapshot' => '/non/existent/snapshot.yaml'])->execute();

    expect($exitCode)->not->toBe(0);
    expect(app()->isDownForMaintenance())->toBeFalse('artisan up must be called in finally even when snapshot is missing');
});

// ---------------------------------------------------------------------------
// T5 — round-trip capture → reset (compat demo:capture / demo:reset)
// ---------------------------------------------------------------------------
it('round-trips data through demo:capture then demo:reset', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    // Arrange: exactly 1 association (created by global beforeEach)
    // Remove any extra ones
    $current = TenantContext::current();
    Association::withoutGlobalScopes()->where('id', '!=', $current->id)->delete();

    $current->update(['nom' => 'Asso Round-Trip']);

    // Create a user attached to the asso
    $user = User::factory()->create([
        'email' => 'rt@demo.fr',
        'derniere_association_id' => $current->id,
    ]);
    $user->associations()->attach($current->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    // Step 1: capture
    $snapPath = sys_get_temp_dir().'/demo-rt-'.uniqid().'.yaml';
    $captureExit = Artisan::call('demo:capture', ['--out' => $snapPath]);
    expect($captureExit)->toBe(0, 'demo:capture should succeed');

    // Step 2: wipe the tables manually to simulate pre-reset state.
    // migrate:fresh cannot run inside a transaction (SQLite VACUUM constraint),
    // so we delete rows directly. Foreign keys off to handle pivot table.
    TenantContext::clear();
    DB::statement('PRAGMA foreign_keys = OFF');
    DB::table('association_user')->delete();
    DB::table('users')->delete();
    DB::table('association')->delete();
    DB::statement('PRAGMA foreign_keys = ON');
    expect(DB::table('association')->count())->toBe(0);

    // Step 3: reset (--skip-migrate because we can't run migrate:fresh inside a transaction)
    $resetExit = Artisan::call('demo:reset', [
        '--snapshot' => $snapPath,
        '--skip-migrate' => true,
    ]);
    expect($resetExit)->toBe(0, 'demo:reset should succeed');

    // Verify: association restored
    $assoCount = DB::table('association')->count();
    expect($assoCount)->toBe(1);

    $restoredAsso = DB::table('association')->first();
    expect($restoredAsso->nom)->toBe('Asso Round-Trip');

    // Verify: user restored with 'demo' password (capture overwrites with DEMO hash)
    $restoredUser = DB::table('users')->where('email', 'rt@demo.fr')->first();
    expect($restoredUser)->not->toBeNull();
    expect(Hash::check('demo', $restoredUser->password))->toBeTrue();

    @unlink($snapPath);
});
