<?php

declare(strict_types=1);

use App\Enums\RoleSysteme;
use App\Livewire\Setup\SetupForm;
use App\Models\Association;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    Cache::forget('app.installed');
});

it('renders the setup form when the page is requested', function () {
    $this->get('/setup')
        ->assertOk()
        ->assertSeeLivewire(SetupForm::class)
        ->assertSee('Bienvenue sur AgoraGestion');
});

it('exposes 5 form fields with correct initial values', function () {
    Livewire::test(SetupForm::class)
        ->assertSet('nom', '')
        ->assertSet('email', '')
        ->assertSet('password', '')
        ->assertSet('nomAsso', '');
});

it('exposes the password_confirmation property', function () {
    Livewire::test(SetupForm::class)
        ->assertSet('password_confirmation', '');
});

it('rejects submit with empty fields', function () {
    Livewire::test(SetupForm::class)
        ->call('submit')
        ->assertHasErrors(['nom', 'email', 'password', 'nomAsso']);
});

it('rejects submit with invalid email format', function () {
    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'not-an-email')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Asso')
        ->call('submit')
        ->assertHasErrors(['email']);
});

it('rejects submit with password shorter than 8 characters', function () {
    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'abc12')
        ->set('nomAsso', 'Mon Asso')
        ->call('submit')
        ->assertHasErrors(['password']);
});

it('rejects submit when password and confirmation do not match', function () {
    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'WRONG')
        ->set('nomAsso', 'Mon Asso')
        ->call('submit')
        ->assertHasErrors(['password']);
});

it('rejects submit with email already taken', function () {
    User::factory()->create(['email' => 'marie@asso.fr']);

    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Asso')
        ->call('submit')
        ->assertHasErrors(['email']);
});

it('passes validation on a fully valid payload', function () {
    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Association')
        ->call('submit')
        ->assertHasNoErrors();
});

it('creates super-admin user, asso, binding and auto-logs in on valid submit', function () {
    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Association')
        ->call('submit')
        ->assertRedirect('/dashboard');

    $user = User::where('email', 'marie@asso.fr')->first();
    expect($user)->not->toBeNull();
    expect($user->nom)->toBe('Marie Dupont');
    expect($user->role_systeme)->toBe(RoleSysteme::SuperAdmin);
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check('azerty1234', $user->password))->toBeTrue();

    $asso = Association::where('nom', 'Mon Association')->first();
    expect($asso)->not->toBeNull();
    expect($asso->slug)->toBe('mon-association');
    expect($asso->statut)->toBe('actif');
    expect($asso->wizard_completed_at)->toBeNull();

    $binding = DB::table('association_user')
        ->where('user_id', $user->id)
        ->where('association_id', $asso->id)
        ->first();
    expect($binding)->not->toBeNull();
    expect($binding->role)->toBe('admin');

    expect(session('current_association_id'))->toBe($asso->id);
    expect(auth()->id())->toBe($user->id);
});

it('invalidates the app.installed cache after a successful submit', function () {
    Cache::put('app.installed', false, 3600);

    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Association')
        ->call('submit')
        ->assertRedirect('/dashboard');

    expect(Cache::has('app.installed'))->toBeFalse();
});

it('handles slug collision by suffixing -2, -3, ...', function () {
    Association::factory()->create([
        'nom' => 'Pré-existant',
        'slug' => 'mon-association',
    ]);

    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Association')
        ->call('submit')
        ->assertRedirect('/dashboard');

    $created = Association::where('nom', 'Mon Association')->first();
    expect($created->slug)->toBe('mon-association-2');
});

it('redirects to /login when a super-admin already exists at submit time', function () {
    User::factory()->create([
        'email' => 'other@asso.fr',
        'role_systeme' => RoleSysteme::SuperAdmin,
    ]);
    Cache::forget('app.installed');

    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Association')
        ->call('submit')
        ->assertRedirect('/login');

    expect(User::where('email', 'marie@asso.fr')->exists())->toBeFalse();
    expect(Association::where('nom', 'Mon Association')->exists())->toBeFalse();
});
