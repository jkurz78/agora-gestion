<?php

declare(strict_types=1);

use App\Livewire\Setup\SetupForm;
use App\Models\Association;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::forget('app.installed');
});

it('walks through the full fresh-install flow : root → /setup → submit → /dashboard → /onboarding', function () {
    // Phase 1 : visiting any URL on a fresh install redirects to /setup
    $this->get('/dashboard')->assertRedirect('/setup');
    $this->get('/login')->assertRedirect('/setup');

    // Phase 2 : /setup is reachable
    $this->get('/setup')->assertOk()->assertSee('Bienvenue sur AgoraGestion');

    // Phase 3 : submitting the form creates everything
    Livewire::test(SetupForm::class)
        ->set('nom', 'Marie Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('password_confirmation', 'azerty1234')
        ->set('nomAsso', 'Mon Association')
        ->call('submit')
        ->assertRedirect('/dashboard');

    expect(User::where('email', 'marie@asso.fr')->exists())->toBeTrue();
    expect(Association::where('nom', 'Mon Association')->exists())->toBeTrue();

    // Phase 4 : after submit, /setup is no longer accessible
    Cache::forget('app.installed');
    $this->get('/setup')->assertRedirect('/login');

    // Phase 5 : visiting /dashboard while wizard is incomplete redirects to /onboarding.
    // The super-admin user created by SetupForm is admin of the new asso
    // (form attached them with role=admin) AND wizard_completed_at is null →
    // ForceWizardIfNotCompleted must redirect to /onboarding even though
    // role_systeme=SuperAdmin.
    $asso = Association::where('nom', 'Mon Association')->first();
    $superAdmin = User::where('email', 'marie@asso.fr')->first();

    $response = $this->actingAs($superAdmin)
        ->withSession(['current_association_id' => $asso->id])
        ->get('/dashboard');

    $response->assertRedirect(route('onboarding.index'));
});
