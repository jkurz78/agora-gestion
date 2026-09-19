<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

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

it('reprend les types de toutes les associations quand artisan migrate tourne sans TenantContext', function () {
    // En prod, `artisan migrate` s'exécute hors requête HTTP : TenantContext
    // n'est jamais booté. La migration doit donc traiter toutes les
    // associations via une requête brute, jamais via le modèle Eloquent
    // (scope tenant fail-closed : `WHERE 1 = 0` sans contexte).
    $autreAssociation = Association::factory()->create();

    $parcoursAssociationCourante = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'formulaire_parcours_therapeutique' => true,
    ]);

    TenantContext::boot($autreAssociation);
    $parcoursAutreAssociation = TypeOperation::factory()->create([
        'association_id' => $autreAssociation->id,
        'formulaire_parcours_therapeutique' => true,
    ]);

    TenantContext::clear();

    repriseParticipationSeanceMigration()->up();

    $ligneCourante = DB::table('type_operations')->where('id', $parcoursAssociationCourante->id)->first();
    $ligneAutre = DB::table('type_operations')->where('id', $parcoursAutreAssociation->id)->first();

    expect((bool) $ligneCourante->participation_seance_active)->toBeTrue()
        ->and($ligneCourante->participation_seance_libelle)->toBe('Kiné')
        ->and((bool) $ligneAutre->participation_seance_active)->toBeTrue()
        ->and($ligneAutre->participation_seance_libelle)->toBe('Kiné');
});
