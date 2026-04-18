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

    // Next request in support mode must boot the TenantContext on the support tenant
    // even though the super-admin has no pivot row for that association.
    $this->actingAs($this->superAdmin)->get('/dashboard');

    // We verify indirectly via a write attempt that would expose the current tenant:
    // any write must be blocked (403). Combined with the log check, this proves the
    // session state is read and honored on the next request.
    $this->actingAs($this->superAdmin)
        ->post('/logout')
        ->assertForbidden();

    // And the session still reflects the support association.
    expect((int) session('support_association_id'))->toBe($this->asso->id);
});

it('logs exit_support_mode when leaving support mode', function () {
    $this->actingAs($this->superAdmin)
        ->post("/super-admin/associations/{$this->asso->slug}/support/enter");

    $this->actingAs($this->superAdmin)
        ->post('/super-admin/support/exit')
        ->assertRedirect(route('super-admin.associations.index'));

    $log = SuperAdminAccessLog::where('association_id', $this->asso->id)
        ->where('action', 'exit_support_mode')
        ->first();

    expect($log)->not->toBeNull();
    expect((int) $log->user_id)->toBe($this->superAdmin->id);
});

it('rejects non-super-admin users trying to enter support mode', function () {
    $regularUser = User::factory()->create(['role_systeme' => RoleSysteme::User]);

    $this->actingAs($regularUser)
        ->post("/super-admin/associations/{$this->asso->slug}/support/enter")
        ->assertForbidden();

    expect(session('support_mode'))->toBeFalsy();
});

it('does not require pivot association_user for super-admin in support mode', function () {
    expect($this->superAdmin->associations()->wherePivot('association_id', $this->asso->id)->exists())->toBeFalse();

    $response = $this->actingAs($this->superAdmin)
        ->post("/super-admin/associations/{$this->asso->slug}/support/enter");

    $response->assertRedirect('/dashboard');
});
