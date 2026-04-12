<?php

declare(strict_types=1);

use App\Enums\CategorieEmail;
use App\Models\Association;
use App\Models\Tiers;
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
    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill([
        'nom' => 'Test',
        'email_from' => 'contact@asso.fr',
        'email_from_name' => 'Mon Asso',
    ])->save();

    $fresh = Association::find(1);
    expect($fresh->email_from)->toBe('contact@asso.fr')
        ->and($fresh->email_from_name)->toBe('Mon Asso');
});

it('CategorieEmail::Communication exists with tiers variables', function () {
    $vars = CategorieEmail::Communication->variables();
    expect($vars)->toHaveKeys(['{prenom}', '{nom}', '{email}', '{association}', '{lien_optout}', '{lien_desinscription}']);
});
