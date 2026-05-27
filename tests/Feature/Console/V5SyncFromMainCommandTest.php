<?php

declare(strict_types=1);

/**
 * Step 44 — Tests v5:sync-from-main
 *
 * Test [A] : --dry-run → exit 0, output liste les 4 étapes avec « would run », aucune commande git réelle
 * Test [B] : mode normal mocké via Process::fake → vérifier l'ordre d'invocation des 4 commandes
 * Test [C] : échec étape 3 (test --filter=Backfill failed) → exit 1, message d'erreur clair
 */

use Illuminate\Support\Facades\Process;

// ---------------------------------------------------------------------------
// Test [A] — --dry-run : exit 0, affiche les 4 étapes sans les exécuter
// ---------------------------------------------------------------------------

test('[A] v5:sync-from-main --dry-run : exit 0 et liste les 4 étapes avec "would run"', function (): void {
    Process::fake(); // Capturer sans exécuter

    $this->artisan('v5:sync-from-main', ['--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('would run')
        ->expectsOutputToContain('git fetch')
        ->expectsOutputToContain('git merge')
        ->expectsOutputToContain('php artisan test')
        ->expectsOutputToContain('compta:backfill-partie-double');

    // En mode dry-run, aucune commande réelle ne doit être invoquée
    Process::assertNothingRan();
})->group('v5_sync');

// ---------------------------------------------------------------------------
// Test [B] — mode normal mocké : vérifier l'invocation des 4 commandes
// ---------------------------------------------------------------------------

test('[B] v5:sync-from-main (mode normal) : les 4 commandes sont invoquées via Process::fake', function (): void {
    Process::fake([
        'git fetch *' => Process::result(output: 'Fetching origin', exitCode: 0),
        'git merge *' => Process::result(output: 'Already up to date.', exitCode: 0),
        'php artisan test *' => Process::result(output: 'Tests: 100 passed', exitCode: 0),
        'php artisan compta:backfill*' => Process::result(output: 'Dry-run: 0 transactions à convertir.', exitCode: 0),
    ]);

    $this->artisan('v5:sync-from-main')
        ->assertExitCode(0);

    // Vérifier que chacune des 4 commandes a été exécutée
    Process::assertRan(fn ($process) => str_contains($process->command, 'git fetch'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'git merge'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'php artisan test'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'compta:backfill'));
})->group('v5_sync');

// ---------------------------------------------------------------------------
// Test [C] — échec étape 3 → exit 1, message d'erreur
// ---------------------------------------------------------------------------

test('[C] v5:sync-from-main : échec étape 3 (tests) → exit 1 avec message d\'erreur', function (): void {
    Process::fake([
        'git fetch *' => Process::result(output: 'Fetching origin', exitCode: 0),
        'git merge *' => Process::result(output: 'Already up to date.', exitCode: 0),
        'php artisan test *' => Process::result(output: 'Tests: 5 failed', exitCode: 1),
        'php artisan compta:backfill*' => Process::result(output: '', exitCode: 0),
    ]);

    $this->artisan('v5:sync-from-main')
        ->assertExitCode(1)
        ->expectsOutputToContain('ÉCHOUÉ');
})->group('v5_sync');
