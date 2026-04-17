<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\SuperAdmin\AssociationCreateForm;
use App\Mail\SuperAdminInvitationMail;
use App\Models\Association;
use App\Models\SuperAdminAccessLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->superAdmin = User::factory()->create(['role_systeme' => RoleSysteme::SuperAdmin]);
});

it('creates association + admin user + pivot + logs + sends invitation mail', function () {
    Mail::fake();

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationCreateForm::class)
        ->set('nom', 'Nouvelle Asso')
        ->set('slug', 'nouvelle-asso')
        ->set('email_admin', 'admin@nouvelle.example')
        ->set('nom_admin', 'Jean NOUVELADMIN')
        ->call('submit')
        ->assertRedirect(route('super-admin.associations.index'));

    $asso = Association::where('slug', 'nouvelle-asso')->first();
    expect($asso)->not->toBeNull();
    expect($asso->statut)->toBe('actif');
    expect($asso->wizard_completed_at)->toBeNull();

    $admin = User::where('email', 'admin@nouvelle.example')->first();
    expect($admin)->not->toBeNull();
    expect($admin->role_systeme)->toBe(RoleSysteme::User);
    expect($admin->associations()->wherePivot('association_id', $asso->id)->wherePivot('role', 'admin')->exists())->toBeTrue();

    expect(SuperAdminAccessLog::where('action', 'create_association')->where('association_id', $asso->id)->exists())->toBeTrue();

    Mail::assertSent(SuperAdminInvitationMail::class, fn ($m) => $m->hasTo('admin@nouvelle.example'));
});

it('rejects a duplicate slug', function () {
    Association::factory()->create(['slug' => 'doublon']);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationCreateForm::class)
        ->set('nom', 'Test')
        ->set('slug', 'doublon')
        ->set('email_admin', 'x@x.example')
        ->set('nom_admin', 'X Y')
        ->call('submit')
        ->assertHasErrors('slug');
});

it('rejects an invalid slug format', function () {
    Livewire::actingAs($this->superAdmin)
        ->test(AssociationCreateForm::class)
        ->set('nom', 'Test')
        ->set('slug', 'Has Spaces!')
        ->set('email_admin', 'x@x.example')
        ->set('nom_admin', 'X Y')
        ->call('submit')
        ->assertHasErrors('slug');
});

it('rejects an already-taken admin email', function () {
    User::factory()->create(['email' => 'deja@pris.example']);

    Livewire::actingAs($this->superAdmin)
        ->test(AssociationCreateForm::class)
        ->set('nom', 'Test')
        ->set('slug', 'valide-slug')
        ->set('email_admin', 'deja@pris.example')
        ->set('nom_admin', 'X Y')
        ->call('submit')
        ->assertHasErrors('email_admin');
});
