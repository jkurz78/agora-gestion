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
        '/_tests/mail-host-isolation',
        fn () => response()->json(['host' => Config::get('mail.mailers.smtp.host')])
    );
});

afterEach(function () {
    TenantContext::clear();
});

it('applies different SMTP host per tenant in consecutive requests', function () {
    $tenantA = Association::factory()->create();
    $tenantB = Association::factory()->create();

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $userA->associations()->attach($tenantA->id, ['role' => 'admin', 'joined_at' => now()]);
    $userB->associations()->attach($tenantB->id, ['role' => 'admin', 'joined_at' => now()]);

    SmtpParametres::create(['association_id' => $tenantA->id, 'enabled' => true, 'smtp_host' => 'smtp.A.example', 'smtp_port' => 587, 'smtp_username' => 'a', 'smtp_password' => 'x', 'smtp_encryption' => 'tls', 'timeout' => 30]);
    SmtpParametres::create(['association_id' => $tenantB->id, 'enabled' => true, 'smtp_host' => 'smtp.B.example', 'smtp_port' => 587, 'smtp_username' => 'b', 'smtp_password' => 'x', 'smtp_encryption' => 'tls', 'timeout' => 30]);

    // Requête A
    session()->flush();
    TenantContext::clear();
    session(['current_association_id' => $tenantA->id]);
    TenantContext::boot($tenantA);
    $this->actingAs($userA)
        ->get('/_tests/mail-host-isolation')
        ->assertJson(['host' => 'smtp.A.example']);

    // Requête B — le host DOIT changer
    session()->flush();
    TenantContext::clear();
    session(['current_association_id' => $tenantB->id]);
    TenantContext::boot($tenantB);
    $this->actingAs($userB)
        ->get('/_tests/mail-host-isolation')
        ->assertJson(['host' => 'smtp.B.example']);
});
