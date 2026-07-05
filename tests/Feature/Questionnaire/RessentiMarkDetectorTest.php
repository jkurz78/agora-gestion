<?php

declare(strict_types=1);

use App\Services\Questionnaire\RessentiMarkDetector;

/**
 * Registre des fixtures temporaires créées par ce fichier de tests : le
 * nettoyage ne supprime que ces chemins (sûr en exécution --parallel).
 *
 * @return ArrayObject<int, string>
 */
function registreFichiersRessenti(): ArrayObject
{
    static $registre = null;

    return $registre ??= new ArrayObject;
}

function cheminFixtureRessenti(): string
{
    // uniqid(more_entropy) évite les collisions inter-processus ; le point
    // qu'il introduit est retiré pour garder un nom de fichier propre.
    $chemin = sys_get_temp_dir().'/ressenti-'.str_replace('.', '', uniqid('', true)).'.png';
    registreFichiersRessenti()->append($chemin);

    return $chemin;
}

/**
 * Dessine une page de questionnaire synthétique : barres ressenti (60 % de la
 * largeur, comme le gabarit papier), lignes d'écriture pleine largeur (84 %)
 * et courtes lignes décoratives (20 %).
 *
 * @param  list<list<float>>  $barres  une entrée par barre = positions des traits en %
 * @param  list<int>  $lignesEcriture  ordonnées Y des lignes d'écriture
 * @param  list<int>  $lignesCourtes  ordonnées Y des lignes courtes décoratives
 */
function creerScanRessentiSynthetique(array $barres, array $lignesEcriture = [], array $lignesCourtes = []): string
{
    $w = 1654;
    $h = 1200;
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));
    $noir = (int) imagecolorallocate($img, 30, 30, 30);

    $x0 = (int) round($w * 0.20);
    $x1 = (int) round($w * 0.80);
    $y = 150;
    foreach ($barres as $traits) {
        imagefilledrectangle($img, $x0, $y, $x1, $y + 3, $noir);
        foreach ($traits as $pct) {
            $xt = (int) round($x0 + ($x1 - $x0) * $pct / 100.0);
            imagefilledrectangle($img, $xt - 2, $y - 18, $xt + 2, $y + 21, $noir);
        }
        $y += 180;
    }
    foreach ($lignesEcriture as $yLigne) {
        imagefilledrectangle($img, (int) round($w * 0.08), $yLigne, (int) round($w * 0.92), $yLigne + 3, $noir);
    }
    foreach ($lignesCourtes as $yLigne) {
        imagefilledrectangle($img, (int) round($w * 0.10), $yLigne, (int) round($w * 0.30), $yLigne + 3, $noir);
    }

    $chemin = cheminFixtureRessenti();
    imagepng($img, $chemin);
    imagedestroy($img);

    return $chemin;
}

afterEach(function (): void {
    $registre = registreFichiersRessenti();
    foreach ($registre as $fichier) {
        @unlink($fichier);
    }
    $registre->exchangeArray([]);
});

it('mesure un trait unique à la position attendue', function (): void {
    $chemin = creerScanRessentiSynthetique([[25.0]]);
    $mesures = (new RessentiMarkDetector)->mesurer($chemin);

    expect($mesures)->toHaveCount(1);
    expect($mesures[0]['pct'])->toEqualWithDelta(25.0, 1.0);
    expect($mesures[0]['nbTraits'])->toBe(1);
});

it('mesure plusieurs barres indépendamment, de haut en bas', function (): void {
    $chemin = creerScanRessentiSynthetique([[17.0], [62.0]]);
    $mesures = (new RessentiMarkDetector)->mesurer($chemin);

    expect($mesures)->toHaveCount(2);
    expect($mesures[0]['pct'])->toEqualWithDelta(17.0, 1.0);
    expect($mesures[1]['pct'])->toEqualWithDelta(62.0, 1.0);
});

it('mesure les traits proches des extrémités', function (): void {
    $chemin = creerScanRessentiSynthetique([[2.0], [98.0]]);
    $mesures = (new RessentiMarkDetector)->mesurer($chemin);

    expect($mesures[0]['pct'])->toEqualWithDelta(2.0, 1.5);
    expect($mesures[1]['pct'])->toEqualWithDelta(98.0, 1.5);
});

it('signale une barre sans trait avec pct null', function (): void {
    $chemin = creerScanRessentiSynthetique([[], [40.0]]);
    $mesures = (new RessentiMarkDetector)->mesurer($chemin);

    expect($mesures)->toHaveCount(2);
    expect($mesures[0]['pct'])->toBeNull();
    expect($mesures[0]['nbTraits'])->toBe(0);
    expect($mesures[1]['pct'])->toEqualWithDelta(40.0, 1.0);
});

it('signale plusieurs traits sur une même barre', function (): void {
    $chemin = creerScanRessentiSynthetique([[30.0, 70.0]]);
    $mesures = (new RessentiMarkDetector)->mesurer($chemin);

    expect($mesures)->toHaveCount(1);
    expect($mesures[0]['nbTraits'])->toBe(2);
});

it('ignore les lignes d ecriture pleine largeur', function (): void {
    $chemin = creerScanRessentiSynthetique([[55.0]], [600, 700, 800]);
    $mesures = (new RessentiMarkDetector)->mesurer($chemin);

    expect($mesures)->toHaveCount(1);
    expect($mesures[0]['pct'])->toEqualWithDelta(55.0, 1.0);
});

it('ignore les lignes courtes décoratives sous la longueur minimale', function (): void {
    $chemin = creerScanRessentiSynthetique([[55.0]], [], [600, 700, 800]);
    $mesures = (new RessentiMarkDetector)->mesurer($chemin);

    expect($mesures)->toHaveCount(1);
    expect($mesures[0]['pct'])->toEqualWithDelta(55.0, 1.0);
});

it('retourne un tableau vide pour un fichier illisible', function (): void {
    $chemin = cheminFixtureRessenti();
    file_put_contents($chemin, 'pas une image');

    expect((new RessentiMarkDetector)->mesurer($chemin))->toBe([]);
});
