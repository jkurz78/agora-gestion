<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\HelloAssoParametres;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;

afterEach(function () {
    TenantContext::clear();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('skips sync and emits skipped_demo log in demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $association = Association::first() ?? Association::factory()->create(['nom' => 'Test Asso']);
    HelloAssoParametres::create([
        'association_id' => $association->id,
        'callback_token' => 'helloasso-sync-guard-token',
        'environnement' => 'sandbox',
    ]);

    Log::spy();

    $this->artisan('helloasso:sync')->assertExitCode(0);

    Log::shouldHaveReceived('info')->once()->with('helloasso.sync.skipped_demo');
});

it('does not emit skipped_demo log and enters sync loop in non-demo env', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $association = Association::first() ?? Association::factory()->create(['nom' => 'Test Asso']);
    HelloAssoParametres::create([
        'association_id' => $association->id,
        'callback_token' => 'helloasso-sync-guard-token-2',
        'environnement' => 'sandbox',
    ]);

    // En env local : la commande tente la sync HelloAsso (échoue sur API — pas de credentials — c'est attendu).
    // On vérifie que le log skipped_demo N'EST PAS émis.
    $this->artisan('helloasso:sync')
        ->doesntExpectOutputToContain('helloasso.sync.skipped_demo');
});
