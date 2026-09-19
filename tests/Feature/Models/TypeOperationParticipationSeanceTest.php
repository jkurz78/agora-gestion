<?php

declare(strict_types=1);

use App\Models\TypeOperation;

/*
 * libelleParticipationSeance() est la SEULE condition d'affichage de la
 * colonne de participation (grille Séances, émargement, matrice, export) :
 * null = pas de colonne.
 */

it('ne renvoie aucun libellé quand l\'option est inactive', function () {
    $type = new TypeOperation([
        'participation_seance_active' => false,
        'participation_seance_libelle' => 'Repas',
    ]);

    expect($type->libelleParticipationSeance())->toBeNull();
});

it('renvoie le libellé paramétré quand l\'option est active', function () {
    $type = new TypeOperation([
        'participation_seance_active' => true,
        'participation_seance_libelle' => 'Repas',
    ]);

    expect($type->libelleParticipationSeance())->toBe('Repas');
});

it('se replie sur « Kiné » quand l\'option est active sans libellé', function () {
    $type = new TypeOperation([
        'participation_seance_active' => true,
        'participation_seance_libelle' => '  ',
    ]);

    expect($type->libelleParticipationSeance())->toBe('Kiné');
});

it('ne dépend plus du parcours thérapeutique', function () {
    $type = new TypeOperation([
        'formulaire_parcours_therapeutique' => true,
        'participation_seance_active' => false,
    ]);

    expect($type->libelleParticipationSeance())->toBeNull();
});
