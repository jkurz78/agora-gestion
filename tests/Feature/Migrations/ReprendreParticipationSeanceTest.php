<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;

/*
 * Reprise : un type qui avait le parcours thérapeutique affichait la colonne
 * « Kiné ». Il doit la garder le jour de la livraison. La migration a déjà
 * tourné (table vide) lors du migrate initial : on la rejoue sur nos données.
 */

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

function repriseParticipationSeanceMigration(): object
{
    return require database_path('migrations/2026_09_19_100001_reprendre_participation_seance_depuis_parcours.php');
}

it('active la participation « Kiné » sur les types en parcours thérapeutique', function () {
    $parcours = TypeOperation::factory()->create(['formulaire_parcours_therapeutique' => true]);
    $formation = TypeOperation::factory()->create(['formulaire_parcours_therapeutique' => false]);

    repriseParticipationSeanceMigration()->up();

    $parcours->refresh();
    $formation->refresh();
    expect($parcours->participation_seance_active)->toBeTrue()
        ->and($parcours->participation_seance_libelle)->toBe('Kiné')
        ->and($formation->participation_seance_active)->toBeFalse()
        ->and($formation->participation_seance_libelle)->toBeNull();
});

it('ne touche pas un type déjà réglé et reste rejouable', function () {
    $regle = TypeOperation::factory()->create([
        'formulaire_parcours_therapeutique' => true,
        'participation_seance_active' => false,
        'participation_seance_libelle' => 'Repas',
    ]);

    $migration = repriseParticipationSeanceMigration();
    $migration->up();
    $migration->up();

    $regle->refresh();
    expect($regle->participation_seance_active)->toBeFalse()
        ->and($regle->participation_seance_libelle)->toBe('Repas');
});
