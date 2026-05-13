<?php

declare(strict_types=1);

use App\Livewire\Tiers\Onglets\Coordonnees;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('affiche identité, adresse, contact et métadonnées', function (): void {
    $tiers = Tiers::factory()->create([
        'nom' => 'Martin',
        'prenom' => 'Jeanne',
        'email' => 'jeanne@example.org',
        'telephone' => '0123456789',
        'adresse_ligne1' => '12 rue des Lilas',
        'code_postal' => '69002',
        'ville' => 'Lyon',
    ]);

    Livewire::test(Coordonnees::class, ['tiers' => $tiers])
        ->assertSee('MARTIN')
        ->assertSee('Jeanne')
        ->assertSee('jeanne@example.org')
        ->assertSee('0123456789')
        ->assertSee('12 rue des Lilas')
        ->assertSee('69002')
        ->assertSee('Lyon');
});

it('affiche un badge optout si le tiers est désinscrit', function (): void {
    $tiers = Tiers::factory()->create(['email_optout' => true]);

    Livewire::test(Coordonnees::class, ['tiers' => $tiers])
        ->assertSee('Désinscrit');
});

it('affiche la civilité quand elle est renseignée', function (): void {
    $tiers = Tiers::factory()->create([
        'civilite' => 'Mme',
        'nom' => 'Kurz',
        'prenom' => 'Anne',
    ]);

    Livewire::test(Coordonnees::class, ['tiers' => $tiers])
        ->assertSee('Civilité')
        ->assertSee('Madame');
});

it('n\'affiche pas la ligne Civilité quand elle est null', function (): void {
    $tiers = Tiers::factory()->create([
        'civilite' => null,
        'nom' => 'Sans',
        'prenom' => 'Civil',
    ]);

    Livewire::test(Coordonnees::class, ['tiers' => $tiers])
        ->assertDontSee('Civilité');
});
