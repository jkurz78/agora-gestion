<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Models\Association;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('Tiers email_optout defaults to false', function () {
    $tiers = Tiers::factory()->create();
    expect($tiers->email_optout)->toBeFalse();
});

it('Tiers email_optout can be set to true', function () {
    $tiers = Tiers::factory()->create(['email_optout' => true]);
    expect($tiers->fresh()->email_optout)->toBeTrue();
});

it('Association email_from is fillable', function () {
    // Réutilise l'association déjà bootée par le bootstrap global des tests
    // (tests/Pest.php) plutôt que de supposer un id=1 : sous MySQL/MariaDB,
    // l'auto-increment ne redémarre pas à 1 entre les tests (contrairement à
    // SQLite en mémoire), donc l'id=1 ne correspond à aucune ligne réelle.
    $assoc = TenantContext::current();
    $assoc->fill([
        'nom' => 'Test',
        'email_from' => 'contact@asso.fr',
        'email_from_name' => 'Mon Asso',
    ])->save();

    $fresh = Association::find($assoc->id);
    expect($fresh->email_from)->toBe('contact@asso.fr')
        ->and($fresh->email_from_name)->toBe('Mon Asso');
});

it('CategorieEmail::Communication exists with tiers variables', function () {
    $vars = CategorieEmail::Communication->variables();
    expect($vars)->toHaveKeys(['{prenom}', '{nom}', '{email}', '{association}', '{lien_optout}', '{lien_desinscription}']);
});
