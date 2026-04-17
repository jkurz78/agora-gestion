<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\AssociationDetail;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
});

it('renders association name + slug on the detail view', function () {
    $asso = Association::factory()->create(['nom' => 'Asso X', 'slug' => 'asso-x']);
    $this->actingAs($this->superAdmin)
        ->get("/super-admin/associations/{$asso->slug}")
        ->assertOk()
        ->assertSee('Asso X')
        ->assertSee('asso-x');
});

it('shows users tab with role and joined_at', function () {
    $asso = Association::factory()->create();
    $alice = User::factory()->create(['email' => 'alice@example.com']);
    $alice->associations()->attach($asso->id, ['role' => 'admin', 'joined_at' => now()->subDays(10)]);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $asso])
        ->set('tab', 'users')
        ->assertSee('alice@example.com')
        ->assertSee('admin');
});

it('shows recent super-admin access logs scoped to the association', function () {
    $asso = Association::factory()->create();
    $other = Association::factory()->create();

    SuperAdminAccessLog::factory()->create([
        'user_id' => $this->superAdmin->id,
        'association_id' => $asso->id,
        'action' => 'enter_support_mode',
    ]);
    SuperAdminAccessLog::factory()->create([
        'user_id' => $this->superAdmin->id,
        'association_id' => $other->id,
        'action' => 'enter_support_mode',
    ]);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationDetail::class, ['association' => $asso])
        ->set('tab', 'logs')
        ->assertSee('enter_support_mode')
        ->assertSeeHtml('data-logs-count="1"');
});

it('returns 404 for unknown slug', function () {
    $this->actingAs($this->superAdmin)
        ->get('/super-admin/associations/nope-nope')
        ->assertNotFound();
});
