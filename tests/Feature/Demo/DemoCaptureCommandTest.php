<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\HelloassoParametres;
use App\Models\Tiers;
use App\Models\User;
use App\Support\Demo\SnapshotConfig;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Yaml\Yaml;

afterEach(function (): void {
    app()->detectEnvironment(fn (): string => 'testing');
});

// T1 : DB vierge (aucune association) → exit ≠ 0
it('exits with error when database has no association', function (): void {
    TenantContext::clear();
    Association::query()->delete();

    $this->artisan('demo:capture', ['--out' => storage_path('test-t1.yaml')])
        ->expectsOutputToContain('exige une seule association')
        ->assertFailed();
});

// T2 : DB avec 2 assos → exit ≠ 0
it('exits with error when database has 2 associations', function (): void {
    // beforeEach already created 1, create a 2nd
    Association::factory()->create();

    TenantContext::clear();

    $outFile = sys_get_temp_dir().'/demo-capture-t2-'.uniqid().'.yaml';

    $this->artisan('demo:capture', ['--out' => $outFile])
        ->expectsOutputToContain('trouvées')
        ->assertFailed();
});

// T3 : env=production → exit ≠ 0
it('exits with error in production environment', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $outFile = sys_get_temp_dir().'/demo-capture-t3-'.uniqid().'.yaml';

    $this->artisan('demo:capture', ['--out' => $outFile])
        ->expectsOutputToContain('refuse de tourner en production')
        ->assertFailed();
});

// T4 : DB avec 1 asso + 1 user + 1 tiers (créé il y a 13 jours) → YAML correct
it('produces correct YAML snapshot with one association user and tiers', function (): void {
    // Delete any extra associations to ensure exactly 1 (isolation safety).
    // The global beforeEach creates 1 association; previous tests may leave artefacts
    // depending on RefreshDatabase isolation mode.
    $current = TenantContext::current();
    Association::withoutGlobalScopes()->where('id', '!=', $current->id)->delete();

    $current->update(['nom' => 'Démo AgoraGestion Test']);

    // Create a user (no association created here)
    $user = User::factory()->create([
        'email' => 'admin@demo.fr',
        'derniere_association_id' => $current->id,
    ]);
    $user->associations()->attach($current->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    // Create a tiers created exactly 13 days ago
    $tiers = Tiers::factory()->create([
        'association_id' => $current->id,
        'created_at' => now()->subDays(13),
        'updated_at' => now()->subDays(13),
    ]);

    // Use storage_path to ensure the file is in the app's storage (same process context)
    $outFile = storage_path('testing-demo-capture-'.uniqid().'.yaml');

    // run() must be called explicitly to execute the command before reading the output file.
    // assertSuccessful() only sets expectedExitCode; __destruct triggers run() — but that's
    // too late for file I/O checks within the same test. Calling execute() forces immediate run.
    $exitCode = $this->artisan('demo:capture', ['--out' => $outFile])->execute();
    expect($exitCode)->toBe(0, 'demo:capture should exit 0');

    $yaml = @file_get_contents($outFile);
    expect($yaml)->not->toBeFalse(message: "File not readable at: {$outFile}");
    $data = Yaml::parse($yaml);

    // Contains associations with the correct nom
    expect($data['tables'])->toHaveKey('association');
    $assoRows = $data['tables']['association'];
    expect($assoRows)->not->toBeEmpty();
    expect($assoRows[0]['nom'])->toBe('Démo AgoraGestion Test');

    // users password replaced with SnapshotConfig::DEMO_USER_PASSWORD_HASH
    expect($data['tables'])->toHaveKey('users');
    $userRow = collect($data['tables']['users'])->firstWhere('email', 'admin@demo.fr');
    expect($userRow)->not->toBeNull();
    expect($userRow['password'])->toBe(SnapshotConfig::DEMO_USER_PASSWORD_HASH);

    // tiers created_at is a delta of approximately -13d
    expect($data['tables'])->toHaveKey('tiers');
    $tiersRow = collect($data['tables']['tiers'])->first(fn ($r) => (int) $r['id'] === (int) $tiers->id);
    expect($tiersRow)->not->toBeNull();
    $delta = $tiersRow['created_at'];
    // Should be -13d (allow ±1d tolerance)
    expect($delta)->toMatch('/^-1[234]d$|^-12d$|^-13d$|^-14d$/');

    // Excluded tables must NOT appear
    expect($data['tables'])->not->toHaveKey('sessions');
    expect($data['tables'])->not->toHaveKey('cache');
    expect($data['tables'])->not->toHaveKey('cache_locks');
    expect($data['tables'])->not->toHaveKey('failed_jobs');
    expect($data['tables'])->not->toHaveKey('email_logs');

    // files key exists and is empty array
    expect($data['files'])->toBe([]);

    // schema_version is 1
    expect($data['schema_version'])->toBe(1);

    // Tables keys are sorted alphabetically
    $tableKeys = array_keys($data['tables']);
    $sorted = $tableKeys;
    sort($sorted);
    expect($tableKeys)->toBe($sorted);

    // captured_at is present
    expect($data)->toHaveKey('captured_at');

    // Cleanup
    @unlink($outFile);
});

// T5 : Sanity check — Hash::check('demo', SnapshotConfig::DEMO_USER_PASSWORD_HASH) returns true
it('SnapshotConfig DEMO_USER_PASSWORD_HASH is valid bcrypt hash for demo', function (): void {
    expect(Hash::check('demo', SnapshotConfig::DEMO_USER_PASSWORD_HASH))->toBeTrue();
});

// T6 : Sensitive columns + super-admin role are scrubbed in the snapshot
it('scrubs sensitive columns and forces role_systeme to user in snapshot', function (): void {
    $current = TenantContext::current();
    Association::withoutGlobalScopes()->where('id', '!=', $current->id)->delete();

    // Set anthropic_api_key on the association
    $current->update(['anthropic_api_key' => 'sk-ant-super-secret-key-12345']);

    // Create a super-admin user with two_factor_secret and remember_token
    $superAdmin = User::factory()->create([
        'email' => 'superadmin@demo.fr',
        'role_systeme' => 'super_admin',
        'two_factor_secret' => 'TOTP_SECRET_ABC',
        'two_factor_recovery_codes' => json_encode(['code1', 'code2']),
        'remember_token' => 'remember-tok-xyz',
        'derniere_association_id' => $current->id,
    ]);
    $superAdmin->associations()->attach($current->id, [
        'role' => 'admin',
        'joined_at' => now(),
    ]);

    // Seed a HelloAsso config with secrets
    HelloassoParametres::updateOrCreate(
        ['association_id' => $current->id],
        [
            'client_id' => 'my-client-id',
            'client_secret' => 'helloasso-secret-xyz',
            'callback_token' => 'callback-secret-abc',
            'organisation_slug' => 'mon-asso',
        ]
    );

    $outFile = storage_path('testing-demo-scrub-'.uniqid().'.yaml');

    $exitCode = $this->artisan('demo:capture', ['--out' => $outFile])->execute();
    expect($exitCode)->toBe(0, 'demo:capture should succeed');

    $yaml = @file_get_contents($outFile);
    expect($yaml)->not->toBeFalse();
    $data = Yaml::parse($yaml);

    // role_systeme must be 'user' — never super_admin
    $userRow = collect($data['tables']['users'])->firstWhere('email', 'superadmin@demo.fr');
    expect($userRow)->not->toBeNull();
    expect($userRow['role_systeme'])->toBe('user', 'role_systeme must be downgraded to user');

    // two_factor_secret must be null
    expect($userRow['two_factor_secret'])->toBeNull('two_factor_secret must be scrubbed');

    // two_factor_recovery_codes must be null
    expect($userRow['two_factor_recovery_codes'])->toBeNull('two_factor_recovery_codes must be scrubbed');

    // remember_token must be null
    expect($userRow['remember_token'])->toBeNull('remember_token must be scrubbed');

    // anthropic_api_key must be null
    $assoRow = collect($data['tables']['association'])->firstWhere('id', $current->id);
    expect($assoRow)->not->toBeNull();
    expect($assoRow['anthropic_api_key'])->toBeNull('anthropic_api_key must be scrubbed');

    // helloasso client_secret and callback_token must be null
    expect($data['tables'])->toHaveKey('helloasso_parametres');
    $helloRow = collect($data['tables']['helloasso_parametres'])->firstWhere('association_id', $current->id);
    expect($helloRow)->not->toBeNull();
    expect($helloRow['client_secret'])->toBeNull('helloasso client_secret must be scrubbed');
    expect($helloRow['callback_token'])->toBeNull('helloasso callback_token must be scrubbed');

    @unlink($outFile);
});
