<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Models\User;
use App\Tenant\TenantContext;

afterEach(function () {
    TenantContext::clear();
});

beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
    $this->asso = Association::factory()->create();
});

it('enters support mode and logs the action', function () {
    $this->actingAs($this->superAdmin)
        ->post("/super-admin/associations/{$this->asso->slug}/support/enter")
        ->assertRedirect('/dashboard');

    expect(session('support_mode'))->toBeTrue();
    expect((int) session('support_association_id'))->toBe($this->asso->id);

    $log = SuperAdminAccessLog::where('association_id', $this->asso->id)->where('action', 'enter_support_mode')->first();
    expect($log)->not->toBeNull();
    expect((int) $log->user_id)->toBe($this->superAdmin->id);
});

it('blocks POST requests in support mode except the exit endpoint', function () {
    $this->actingAs($this->superAdmin)
        ->post("/super-admin/associations/{$this->asso->slug}/support/enter");

    $this->post('/logout')
        ->assertForbidden();

    $this->post('/super-admin/support/exit')
        ->assertRedirect();

    expect(session('support_mode'))->toBeFalsy();
});

it('resolves the support association_id as current tenant in support mode', function () {
    $this->actingAs($this->superAdmin)
        ->post("/super-admin/associations/{$this->asso->slug}/support/enter");

    $this->actingAs($this->superAdmin)->get('/dashboard');
    expect(SuperAdminAccessLog::count())->toBeGreaterThan(0);
});

it('does not require pivot association_user for super-admin in support mode', function () {
    expect($this->superAdmin->associations()->wherePivot('association_id', $this->asso->id)->exists())->toBeFalse();

    $response = $this->actingAs($this->superAdmin)
        ->post("/super-admin/associations/{$this->asso->slug}/support/enter");

    $response->assertRedirect('/dashboard');
});
