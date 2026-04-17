<?php

declare(strict_types=1);

use App\Http\Middleware\BootTenantConfig;
use App\Models\Association;
use App\Models\SmtpParametres;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', BootTenantConfig::class])->get(
        '/_tests/mail-host',
        fn () => response()->json(['host' => Config::get('mail.mailers.smtp.host')])
    );
});

afterEach(function () {
    TenantContext::clear();
});

it('applies per-tenant SMTP config at request time', function () {
    $asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    SmtpParametres::create([
        'association_id' => $asso->id,
        'enabled' => true,
        'smtp_host' => 'smtp.tenant-a.example',
        'smtp_port' => 587,
        'smtp_username' => 'a@a.example',
        'smtp_password' => 'secret',
        'smtp_encryption' => 'tls',
        'timeout' => 30,
    ]);

    session(['current_association_id' => $asso->id]);
    TenantContext::boot($asso);

    $response = $this->actingAs($user)->get('/_tests/mail-host');
    $response->assertOk()->assertJson(['host' => 'smtp.tenant-a.example']);
});

it('does nothing when SMTP config is disabled', function () {
    Config::set('mail.mailers.smtp.host', 'smtp.default.example');
    $asso = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()]);

    SmtpParametres::create([
        'association_id' => $asso->id,
        'enabled' => false,
        'smtp_host' => 'smtp.off.example',
        'smtp_port' => 587,
        'smtp_username' => 'off@off.example',
        'smtp_password' => 'secret',
        'smtp_encryption' => 'tls',
        'timeout' => 30,
    ]);

    session(['current_association_id' => $asso->id]);
    TenantContext::boot($asso);

    $response = $this->actingAs($user)->get('/_tests/mail-host');
    $response->assertOk()->assertJson(['host' => 'smtp.default.example']);
});
