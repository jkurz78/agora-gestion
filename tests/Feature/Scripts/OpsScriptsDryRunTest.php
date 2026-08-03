<?php

declare(strict_types=1);

/**
 * Step 43 — Tests dry-run des scripts ops bash
 *
 * Test [D] : clone-prod-to-preprod.sh --dry-run → exit 0, stdout contient « would run »
 * Test [E] : deploy-preprod-v5.sh --dry-run → exit 0, propagation --dry-run visible
 */

use Illuminate\Support\Facades\Process;

// ---------------------------------------------------------------------------
// Test [D] — clone-prod-to-preprod.sh --dry-run
// ---------------------------------------------------------------------------

test('[D] clone-prod-to-preprod.sh --dry-run : exit 0 et stdout contient "would run"', function (): void {
    $scriptPath = base_path('scripts/clone-prod-to-preprod.sh');

    expect(file_exists($scriptPath))->toBeTrue("Script non trouvé : {$scriptPath}");

    $result = Process::run(['bash', $scriptPath, '--dry-run']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('would run');
})->group('ops_scripts');

// ---------------------------------------------------------------------------
// Test [E] — deploy-preprod-v5.sh --dry-run
// ---------------------------------------------------------------------------

test('[E] deploy-preprod-v5.sh --dry-run : exit 0 et propagation --dry-run visible dans output', function (): void {
    $scriptPath = base_path('scripts/deploy-preprod-v5.sh');

    expect(file_exists($scriptPath))->toBeTrue("Script non trouvé : {$scriptPath}");

    $result = Process::run(['bash', $scriptPath, '--dry-run']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('would run');
})->group('ops_scripts');
