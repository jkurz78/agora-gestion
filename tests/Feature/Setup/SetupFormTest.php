<?php

declare(strict_types=1);

use App\Livewire\Setup\SetupForm;
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
