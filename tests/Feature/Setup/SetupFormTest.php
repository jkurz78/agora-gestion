<?php

declare(strict_types=1);

use App\Livewire\Setup\SetupForm;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
        ->assertSet('prenom', '')
        ->assertSet('nom', '')
        ->assertSet('email', '')
        ->assertSet('password', '')
        ->assertSet('nomAsso', '');
});

it('rejects submit with empty fields', function () {
    Livewire::test(SetupForm::class)
        ->call('submit')
        ->assertHasErrors(['prenom', 'nom', 'email', 'password', 'nomAsso']);
});

it('rejects submit with invalid email format', function () {
    Livewire::test(SetupForm::class)
        ->set('prenom', 'Marie')
        ->set('nom', 'Dupont')
        ->set('email', 'not-an-email')
        ->set('password', 'azerty1234')
        ->set('nomAsso', 'Mon Asso')
        ->call('submit')
        ->assertHasErrors(['email']);
});

it('rejects submit with password shorter than 8 characters', function () {
    Livewire::test(SetupForm::class)
        ->set('prenom', 'Marie')
        ->set('nom', 'Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'abc12')
        ->set('nomAsso', 'Mon Asso')
        ->call('submit')
        ->assertHasErrors(['password']);
});

it('rejects submit with email already taken', function () {
    User::factory()->create(['email' => 'marie@asso.fr']);

    Livewire::test(SetupForm::class)
        ->set('prenom', 'Marie')
        ->set('nom', 'Dupont')
        ->set('email', 'marie@asso.fr')
        ->set('password', 'azerty1234')
        ->set('nomAsso', 'Mon Asso')
        ->call('submit')
        ->assertHasErrors(['email']);
});
