<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\AssociationCreateForm;
use App\Models\Association;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
});

it('rejects a reserved slug on creation', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationCreateForm::class)
        ->set('nom', 'Test Asso')
        ->set('slug', 'dashboard')
        ->set('email_admin', 'admin@test.example')
        ->set('nom_admin', 'Admin Test')
        ->call('submit')
        ->assertHasErrors(['slug']);

    expect(Association::where('slug', 'dashboard')->exists())->toBeFalse();
});

it('accepts a non-reserved slug on creation', function () {
    Mail::fake();

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationCreateForm::class)
        ->set('nom', 'Mon Association')
        ->set('slug', 'monasso')
        ->set('email_admin', 'admin@monasso.example')
        ->set('nom_admin', 'Admin Monasso')
        ->call('submit')
        ->assertHasNoErrors(['slug']);

    expect(Association::where('slug', 'monasso')->exists())->toBeTrue();
});
