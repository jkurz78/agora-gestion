<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

/**
 * Mesure déterministe des barres « ressenti » (échelles visuelles analogiques)
 * sur une image de questionnaire papier scanné — analyse de pixels pure GD.
 *
 * Auto-calibrant : le pourcentage est calculé relativement aux extrémités
 * détectées de la barre elle-même, ce qui absorbe translation, échelle et
 * rotation légère du scan. Les lignes d'écriture (pleine largeur) sont
 * distinguées des barres ressenti (~59 % de la largeur) par leur longueur.
 */
final class RessentiMarkDetector
{
    private const LARGEUR_ANALYSE = 1700; // px — toute image est ramenée à cette largeur

    private const SEUIL_SOMBRE = 160; // luminance 0-255 sous laquelle un pixel compte comme encre

    private const FRACTION_MIN = 0.30; // longueur mini d'une barre (fraction de la largeur page)

    private const FRACTION_MAX = 0.75; // au-delà : ligne d'écriture, pas une barre ressenti

    private const TOLERANCE_TROUS = 3; // px blancs tolérés au sein d'une ligne (bruit de scan)

    private const DEMI_BANDE = 19; // demi-hauteur de la bande d'analyse autour de la ligne

    private const SURPLUS_TRAIT = 8; // encre au-delà de l'épaisseur de ligne => trait du répondant

    private const ECART_CLUSTER = 4; // colonnes distantes d'au plus N px = même trait

    private const MARGE_EXTREMITES = 7; // px exclus aux extrémités pour le calcul d'épaisseur

    /**
     * @return list<array{pct: float|null, nbTraits: int}> une entrée par barre, de haut en bas
     */
    public function mesurer(string $imagePath): array
    {
        $sombre = $this->carteBinaire($imagePath);
        if ($sombre === null) {
            return [];
        }

        $h = count($sombre);
        $barres = $this->detecterBarres($sombre, self::LARGEUR_ANALYSE);

        return array_map(
            fn (array $barre): array => $this->mesurerTrait($sombre, $barre, $h),
            $barres,
        );
    }

    /**
     * Charge l'image, la ramène à LARGEUR_ANALYSE px de large et binarise :
     * une chaîne '1'/'0' par rangée (les chaînes restent légères en mémoire).
     *
     * @return list<string>|null
     */
    private function carteBinaire(string $imagePath): ?array
    {
        $contenu = @file_get_contents($imagePath);
        if ($contenu === false) {
            return null;
        }

        $source = @imagecreatefromstring($contenu);
        if ($source === false) {
            return null;
        }

        $w = self::LARGEUR_ANALYSE;
        $h = (int) round(imagesy($source) * $w / imagesx($source));
        $img = imagescale($source, $w, $h);
        imagedestroy($source);
        if ($img === false) {
            return null;
        }

        $sombre = [];
        for ($y = 0; $y < $h; $y++) {
            $rangee = '';
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $lum = ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3;
                $rangee .= $lum < self::SEUIL_SOMBRE ? '1' : '0';
            }
            $sombre[] = $rangee;
        }
        imagedestroy($img);

        return $sombre;
    }

    /**
     * Détecte les longues lignes horizontales et ne garde que celles dont la
     * longueur correspond à une barre ressenti.
     *
     * @param  list<string>  $sombre
     * @return list<array{y: int, x0: int, x1: int}>
     */
    private function detecterBarres(array $sombre, int $w): array
    {
        $longueurMin = (int) round(self::FRACTION_MIN * $w);

        // Rangées contenant une longue plage sombre
        $rangees = [];
        foreach ($sombre as $y => $rangee) {
            $plage = $this->plusLonguePlage($rangee, $w);
            if ($plage !== null && $longueurMin <= $plage[1] - $plage[0] + 1) {
                $rangees[$y] = $plage;
            }
        }

        // Regroupement des rangées adjacentes (épaisseur de ligne, léger skew)
        $lignes = [];
        foreach ($rangees as $y => [$debut, $fin]) {
            $indexDerniere = $lignes === [] ? null : array_key_last($lignes);
            if ($indexDerniere !== null
                && $y - $lignes[$indexDerniere]['yFin'] <= 2
                && $debut <= $lignes[$indexDerniere]['x1']
                && $fin >= $lignes[$indexDerniere]['x0']) {
                $lignes[$indexDerniere]['yFin'] = $y;
                $lignes[$indexDerniere]['x0'] = min($lignes[$indexDerniere]['x0'], $debut);
                $lignes[$indexDerniere]['x1'] = max($lignes[$indexDerniere]['x1'], $fin);
            } else {
                $lignes[] = ['yDebut' => $y, 'yFin' => $y, 'x0' => $debut, 'x1' => $fin];
            }
        }

        $barres = [];
        foreach ($lignes as $ligne) {
            $fraction = ($ligne['x1'] - $ligne['x0']) / $w;
            if ($fraction >= self::FRACTION_MIN && $fraction <= self::FRACTION_MAX) {
                $barres[] = [
                    'y' => intdiv($ligne['yDebut'] + $ligne['yFin'], 2),
                    'x0' => $ligne['x0'],
                    'x1' => $ligne['x1'],
                ];
            }
        }

        return $barres;
    }

    /**
     * Plus longue plage de pixels sombres d'une rangée, trous tolérés.
     *
     * @return array{0: int, 1: int}|null [début, fin] ou null si rangée vide
     */
    private function plusLonguePlage(string $rangee, int $w): ?array
    {
        $meilleur = null;
        $meilleureLongueur = 0;
        $debut = null;
        $dernierSombre = 0;
        $trous = 0;

        for ($x = 0; $x < $w; $x++) {
            if ($rangee[$x] === '1') {
                $debut ??= $x;
                $dernierSombre = $x;
                $trous = 0;
            } elseif ($debut !== null && ++$trous > self::TOLERANCE_TROUS) {
                if ($dernierSombre - $debut + 1 > $meilleureLongueur) {
                    $meilleur = [$debut, $dernierSombre];
                    $meilleureLongueur = $dernierSombre - $debut + 1;
                }
                $debut = null;
            }
        }
        if ($debut !== null && $dernierSombre - $debut + 1 > $meilleureLongueur) {
            $meilleur = [$debut, $dernierSombre];
        }

        return $meilleur;
    }

    /**
     * Mesure la position du trait vertical du répondant sur une barre :
     * comptage d'encre par colonne dans une bande autour de la ligne, la
     * médiane donnant l'épaisseur de la ligne imprimée (baseline).
     *
     * @param  list<string>  $sombre
     * @param  array{y: int, x0: int, x1: int}  $barre
     * @return array{pct: float|null, nbTraits: int}
     */
    private function mesurerTrait(array $sombre, array $barre, int $h): array
    {
        ['y' => $yc, 'x0' => $x0, 'x1' => $x1] = $barre;
        $yLo = max(0, $yc - self::DEMI_BANDE);
        $yHi = min($h - 1, $yc + self::DEMI_BANDE);

        $comptes = [];
        for ($x = $x0; $x <= $x1; $x++) {
            $n = 0;
            for ($y = $yLo; $y <= $yHi; $y++) {
                if ($sombre[$y][$x] === '1') {
                    $n++;
                }
            }
            $comptes[] = $n;
        }

        $interieur = array_slice(
            $comptes,
            self::MARGE_EXTREMITES,
            count($comptes) - 2 * self::MARGE_EXTREMITES,
        );
        sort($interieur);
        $baseline = $interieur[intdiv(count($interieur), 2)];

        // Colonnes nettement plus encrées que la ligne => trait(s) du répondant
        $seuil = $baseline + self::SURPLUS_TRAIT;
        $clusters = [];
        foreach ($comptes as $i => $compte) {
            if ($compte < $seuil) {
                continue;
            }
            $indexDernier = $clusters === [] ? null : array_key_last($clusters);
            if ($indexDernier !== null && $i - end($clusters[$indexDernier]) <= self::ECART_CLUSTER) {
                $clusters[$indexDernier][] = $i;
            } else {
                $clusters[] = [$i];
            }
        }

        if ($clusters === []) {
            return ['pct' => null, 'nbTraits' => 0];
        }

        $encres = [];
        foreach ($clusters as $cluster) {
            $total = 0;
            foreach ($cluster as $i) {
                $total += $comptes[$i] - $baseline;
            }
            $encres[] = $total;
        }

        $encreMax = max($encres);
        $meilleur = $clusters[array_search($encreMax, $encres, true)];

        // Centroïde pondéré par l'encre du cluster dominant
        $cx = 0.0;
        foreach ($meilleur as $i) {
            $cx += $i * ($comptes[$i] - $baseline);
        }
        $cx /= $encreMax;

        $pct = max(0.0, min(100.0, $cx / ($x1 - $x0) * 100));
        $nbTraits = count(array_filter($encres, fn (int $encre): bool => $encre > $encreMax * 0.4));

        return ['pct' => $pct, 'nbTraits' => $nbTraits];
    }
}
