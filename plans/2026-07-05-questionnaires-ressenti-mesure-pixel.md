# Mesure pixel des barres « ressenti » — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** Remplacer l'estimation LLM (imprécise) de la position du trait sur les barres « ressenti » des questionnaires papier scannés par une mesure déterministe par analyse de pixels.

**Architecture :** Un détecteur pur GD (`RessentiMarkDetector`) mesure les barres sur une image ; un orchestrateur (`RessentiScanMeasurer`) rasterise les PDF multi-pages via `spatie/pdf-to-image` (déjà en place pour le décodage QR, validé sur O2Switch : Imagick + Ghostscript 9.27 + policy OK) ; `QuestionnaireOcrService::analyzeFromPath()` fusionne les mesures dans le payload OCR après le passage LLM. Fail-safe : toute anomalie (rasterisation impossible, nombre de barres ≠ nombre de questions ressenti) conserve les valeurs LLM ; barre sans trait ou multi-traits → `value: null, confidence: 0` → assistant de saisie manuelle.

**Tech Stack :** Laravel 11, GD (analyse pixel), spatie/pdf-to-image ^3.2 (rasterisation), Pest.

**Contexte validé en amont (session du 2026-07-05) :**
- Prototype Python validé sur scan réel : barres détectées par longueur relative (barre ressenti ≈ 59 % de la largeur page — cellule `width:72%` de `resources/views/pdf/partials/champ-papier.blade.php` ; lignes d'écriture ≈ 82-84 %), traits mesurés à 17,4 % et 21,6 %, précision ±0,2 %.
- L'algorithme est auto-calibrant : le % est relatif aux extrémités détectées de la barre elle-même → translation/échelle/rotation légère du scan absorbées.
- Environnement O2Switch vérifié par script diag : `imagick` chargé, codecs PDF/PDFA actifs, conversion PDF→PNG OK depuis PHP, `gs` 9.27 présent, `exec` dispo, `gd` chargé.
- Fixtures déjà présentes dans le worktree (à `git add` en Task 2) :
  - `tests/fixtures/questionnaire/ressenti-scan-bars.png` — crop réel (2480×500, 300 dpi) des 2 barres du scan de recette, valeurs attendues 17,4 % et 21,6 %, aucune donnée personnelle.
  - `tests/fixtures/questionnaire/ressenti-scan-bars.pdf` — même crop encapsulé en PDF 1 page (300 dpi) pour tester la rasterisation.

**Environnement d'exécution :** `./vendor/bin/sail up -d` doit être actif. Tests : `./vendor/bin/sail pest <chemin>`. Lint : `./vendor/bin/sail pint --dirty`. Note : les 2 tests PDF de la Task 3 skippent si l'extension `imagick` manque dans le conteneur Sail — c'est attendu et couvert en prod par le diag O2Switch.

---

### Task 1 : `RessentiMarkDetector` — détection et mesure sur image (TDD, fixtures synthétiques)

**Files:**
- Create: `app/Services/Questionnaire/RessentiMarkDetector.php`
- Test: `tests/Feature/Questionnaire/RessentiMarkDetectorTest.php`

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Questionnaire/RessentiMarkDetectorTest.php` :

```php
<?php

declare(strict_types=1);

use App\Services\Questionnaire\RessentiMarkDetector;

/**
 * Dessine une page de questionnaire synthétique : barres ressenti (60 % de la
 * largeur, comme le gabarit papier) et lignes d'écriture pleine largeur (84 %).
 *
 * @param  list<list<float>>  $barres  une entrée par barre = positions des traits en %
 * @param  list<int>  $lignesEcriture  ordonnées Y des lignes d'écriture
 */
function creerScanRessentiSynthetique(array $barres, array $lignesEcriture = []): string
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

    $chemin = sys_get_temp_dir().'/ressenti-'.uniqid().'.png';
    imagepng($img, $chemin);
    imagedestroy($img);

    return $chemin;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/ressenti-*.png') ?: [] as $fichier) {
        @unlink($fichier);
    }
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

it('retourne un tableau vide pour un fichier illisible', function (): void {
    $chemin = sys_get_temp_dir().'/ressenti-'.uniqid().'.png';
    file_put_contents($chemin, 'pas une image');

    expect((new RessentiMarkDetector)->mesurer($chemin))->toBe([]);
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/RessentiMarkDetectorTest.php`
Attendu : ÉCHEC — `Class "App\Services\Questionnaire\RessentiMarkDetector" not found`.

- [ ] **Step 3 : Implémenter le détecteur**

Créer `app/Services/Questionnaire/RessentiMarkDetector.php` :

```php
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
            if ($plage !== null && $plage[1] - $plage[0] + 1 >= $longueurMin) {
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
```

- [ ] **Step 4 : Vérifier que les tests passent**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/RessentiMarkDetectorTest.php`
Attendu : PASS (7 tests). Si un test de position échoue de peu (delta), vérifier l'arithmétique de mise à l'échelle avant de toucher aux constantes.

- [ ] **Step 5 : Commit**

```bash
git add app/Services/Questionnaire/RessentiMarkDetector.php tests/Feature/Questionnaire/RessentiMarkDetectorTest.php
git commit -m "feat(questionnaires): détecteur pixel des barres ressenti (RessentiMarkDetector)"
```

---

### Task 2 : Validation sur le scan réel de recette

**Files:**
- Modify: `tests/Feature/Questionnaire/RessentiMarkDetectorTest.php` (ajout d'un test)
- Add: `tests/fixtures/questionnaire/ressenti-scan-bars.png` (déjà présent dans le worktree, non tracké)
- Add: `tests/fixtures/questionnaire/ressenti-scan-bars.pdf` (déjà présent dans le worktree, non tracké)

- [ ] **Step 1 : Ajouter le test fixture réelle**

À la fin de `tests/Feature/Questionnaire/RessentiMarkDetectorTest.php` :

```php
it('mesure les deux barres du scan réel de recette', function (): void {
    $mesures = (new RessentiMarkDetector)->mesurer(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
    );

    expect($mesures)->toHaveCount(2);
    expect($mesures[0]['pct'])->toEqualWithDelta(17.4, 1.5);
    expect($mesures[0]['nbTraits'])->toBe(1);
    expect($mesures[1]['pct'])->toEqualWithDelta(21.6, 1.5);
    expect($mesures[1]['nbTraits'])->toBe(1);
});
```

- [ ] **Step 2 : Vérifier que le test passe**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/RessentiMarkDetectorTest.php`
Attendu : PASS (8 tests). Les valeurs de référence 17,4 / 21,6 viennent du prototype validé visuellement (crops annotés) le 2026-07-05. En cas d'échec : diagnostiquer (dump des barres détectées), ne pas élargir le delta au-delà de 1,5.

- [ ] **Step 3 : Commit (fixtures incluses)**

```bash
git add tests/fixtures/questionnaire/ tests/Feature/Questionnaire/RessentiMarkDetectorTest.php
git commit -m "test(questionnaires): fixture scan réel pour la mesure ressenti"
```

---

### Task 3 : `RessentiScanMeasurer` — rasterisation PDF multi-pages

**Files:**
- Create: `app/Services/Questionnaire/RessentiScanMeasurer.php`
- Test: `tests/Feature/Questionnaire/RessentiScanMeasurerTest.php`

- [ ] **Step 1 : Écrire les tests qui échouent**

Créer `tests/Feature/Questionnaire/RessentiScanMeasurerTest.php` :

```php
<?php

declare(strict_types=1);

use App\Services\Questionnaire\RessentiScanMeasurer;

it('analyse directement une image sans rasterisation', function (): void {
    $mesures = app(RessentiScanMeasurer::class)->mesurerDocument(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
    );

    expect($mesures)->toHaveCount(2);
    expect($mesures[0]['pct'])->toEqualWithDelta(17.4, 1.5);
    expect($mesures[1]['pct'])->toEqualWithDelta(21.6, 1.5);
});

it('rasterise un PDF et concatène les mesures de toutes les pages', function (): void {
    $mesures = app(RessentiScanMeasurer::class)->mesurerDocument(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.pdf'),
        'application/pdf',
    );

    expect($mesures)->toHaveCount(2);
    expect($mesures[0]['pct'])->toEqualWithDelta(17.4, 2.0);
    expect($mesures[1]['pct'])->toEqualWithDelta(21.6, 2.0);
})->skip(fn (): bool => ! extension_loaded('imagick'), 'Imagick requis pour la rasterisation PDF');

it('retourne null quand le PDF est illisible', function (): void {
    $chemin = sys_get_temp_dir().'/corrompu-'.uniqid().'.pdf';
    file_put_contents($chemin, 'pas un pdf');

    expect(app(RessentiScanMeasurer::class)->mesurerDocument($chemin, 'application/pdf'))->toBeNull();
    @unlink($chemin);
})->skip(fn (): bool => ! extension_loaded('imagick'), 'Imagick requis pour la rasterisation PDF');
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/RessentiScanMeasurerTest.php`
Attendu : ÉCHEC — `Class "App\Services\Questionnaire\RessentiScanMeasurer" not found`.

- [ ] **Step 3 : Implémenter le measurer**

Créer `app/Services/Questionnaire/RessentiScanMeasurer.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\PdfToImage\Enums\OutputFormat;
use Spatie\PdfToImage\Pdf as PdfToImage;
use Throwable;

/**
 * Rasterise un document scanné (PDF multi-pages ou image) puis mesure les
 * barres « ressenti » page par page, dans l'ordre de lecture du document.
 */
final class RessentiScanMeasurer
{
    public function __construct(private readonly RessentiMarkDetector $detecteur) {}

    /**
     * @return list<array{pct: float|null, nbTraits: int}>|null null si le document n'a pas pu être rasterisé
     */
    public function mesurerDocument(string $path, string $mime): ?array
    {
        if ($mime !== 'application/pdf') {
            return $this->detecteur->mesurer($path);
        }

        $dossierTemp = storage_path('app/private/temp/questionnaire-scan');
        File::ensureDirectoryExists($dossierTemp);
        $prefixe = $dossierTemp.'/'.Str::uuid()->toString();
        $pages = [];

        try {
            $pdf = (new PdfToImage($path))->resolution(200)->format(OutputFormat::Png);
            $mesures = [];
            for ($page = 1; $page <= $pdf->pageCount(); $page++) {
                $png = $prefixe.'-p'.$page.'.png';
                $pdf->selectPage($page)->save($png);
                $pages[] = $png;
                foreach ($this->detecteur->mesurer($png) as $mesure) {
                    $mesures[] = $mesure;
                }
            }

            return $mesures;
        } catch (Throwable) {
            return null;
        } finally {
            foreach ($pages as $png) {
                @unlink($png);
            }
        }
    }
}
```

- [ ] **Step 4 : Vérifier que les tests passent**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/RessentiScanMeasurerTest.php`
Attendu : PASS — 1 test vert minimum (image) ; les 2 tests PDF passent si `imagick` est présent dans le conteneur, sinon SKIP (acceptable, validé en prod par le diag O2Switch).

- [ ] **Step 5 : Commit**

```bash
git add app/Services/Questionnaire/RessentiScanMeasurer.php tests/Feature/Questionnaire/RessentiScanMeasurerTest.php
git commit -m "feat(questionnaires): rasterisation multi-pages pour la mesure ressenti"
```

---

### Task 4 : Fusion dans `QuestionnaireOcrService`

**Files:**
- Modify: `app/Services/Questionnaire/QuestionnaireOcrService.php`
- Test: `tests/Feature/Questionnaire/QuestionnaireOcrServiceTest.php` (ajout de 3 tests)

- [ ] **Step 1 : Écrire les tests qui échouent**

À la fin de `tests/Feature/Questionnaire/QuestionnaireOcrServiceTest.php`, ajouter (compléter les `use` en tête de fichier : `App\Support\CurrentAssociation`, `Illuminate\Support\Facades\Http`) :

```php
it('remplace les valeurs ressenti du LLM par la mesure pixel', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Premier ressenti', 'type' => 'ressenti', 'ordre' => 1,
    ]);
    $q2 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Second ressenti', 'type' => 'ressenti', 'ordre' => 2,
    ]);
    CurrentAssociation::tryGet()->update(['anthropic_api_key' => 'test-key-for-ocr']);

    // Le LLM renvoie 50 partout — la mesure pixel doit primer
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [[
        'type' => 'text',
        'text' => json_encode([
            (string) $q1->id => ['value' => 50, 'confidence' => 0.6],
            (string) $q2->id => ['value' => 50, 'confidence' => 0.6],
        ]),
    ]]])]);

    $resultat = app(QuestionnaireOcrService::class)->analyzeFromPath(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
        $campagne->fresh(),
    );

    // Scan réel : barres à 17,4 % et 21,6 %
    expect($resultat[(string) $q1->id]['value'])->toBeGreaterThanOrEqual(16)->toBeLessThanOrEqual(19);
    expect($resultat[(string) $q1->id]['confidence'])->toBe(0.98);
    expect($resultat[(string) $q2->id]['value'])->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(23);
    expect($resultat[(string) $q2->id]['confidence'])->toBe(0.98);
});

it('conserve les valeurs LLM quand le nombre de barres ne correspond pas', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $questions = collect([1, 2, 3])->map(fn (int $ordre) => QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create(['libelle' => "Ressenti {$ordre}", 'type' => 'ressenti', 'ordre' => $ordre]));
    CurrentAssociation::tryGet()->update(['anthropic_api_key' => 'test-key-for-ocr']);

    // 3 questions ressenti mais le scan ne contient que 2 barres => fail-safe
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [[
        'type' => 'text',
        'text' => json_encode($questions->mapWithKeys(fn ($q, $i) => [
            (string) $q->id => ['value' => 35 + 10 * $i, 'confidence' => 0.6],
        ])->all()),
    ]]])]);

    $resultat = app(QuestionnaireOcrService::class)->analyzeFromPath(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
        $campagne->fresh(),
    );

    expect($resultat[(string) $questions[0]->id]['value'])->toBe(35);
    expect($resultat[(string) $questions[0]->id]['confidence'])->toBe(0.6);
    expect($resultat[(string) $questions[2]->id]['value'])->toBe(55);
});

it('ne modifie pas le payload quand la campagne n a pas de question ressenti', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => 'satisfaction', 'ordre' => 1,
    ]);
    CurrentAssociation::tryGet()->update(['anthropic_api_key' => 'test-key-for-ocr']);

    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [[
        'type' => 'text',
        'text' => json_encode([(string) $q1->id => ['value' => 4, 'confidence' => 0.9]]),
    ]]])]);

    $resultat = app(QuestionnaireOcrService::class)->analyzeFromPath(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
        $campagne->fresh(),
    );

    expect($resultat[(string) $q1->id]['value'])->toBe(4);
    expect($resultat[(string) $q1->id]['confidence'])->toBe(0.9);
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/QuestionnaireOcrServiceTest.php`
Attendu : les 3 nouveaux tests ÉCHOUENT (les valeurs LLM 50 ne sont pas remplacées) ; les 4 tests existants restent verts.

- [ ] **Step 3 : Implémenter la fusion**

Dans `app/Services/Questionnaire/QuestionnaireOcrService.php` :

Ajouter les imports :

```php
use App\Enums\TypeQuestion;
use Illuminate\Support\Facades\Log;
```

Ajouter le constructeur (la classe n'en a pas — le conteneur Laravel résout l'injection partout, y compris `app(QuestionnaireOcrService::class)`) :

```php
    public function __construct(private readonly RessentiScanMeasurer $mesureur) {}
```

Dans `analyzeFromPath()`, remplacer la dernière ligne :

```php
        return $this->parse($response->json('content.0.text', ''));
```

par :

```php
        $reponses = $this->parse($response->json('content.0.text', ''));

        return $this->fusionnerMesuresRessenti($reponses, $campagne, $path, $mime);
```

Ajouter la méthode privée (après `parse()`) :

```php
    /**
     * Remplace les valeurs « ressenti » estimées par le LLM par la mesure
     * déterministe (analyse de pixels). Fail-safe : si le document n'a pas pu
     * être analysé ou si le nombre de barres détectées ne correspond pas au
     * nombre de questions ressenti, les valeurs LLM sont conservées.
     *
     * @param  array<string, array{value: mixed, confidence: float}>  $reponses
     * @return array<string, array{value: mixed, confidence: float}>
     */
    private function fusionnerMesuresRessenti(array $reponses, QuestionnaireCampaign $campagne, string $path, string $mime): array
    {
        $questionsRessenti = $campagne->questions
            ->filter(fn ($q): bool => $q->type === TypeQuestion::Ressenti)
            ->values();

        if ($questionsRessenti->isEmpty()) {
            return $reponses;
        }

        $mesures = $this->mesureur->mesurerDocument($path, $mime);

        if ($mesures === null || count($mesures) !== $questionsRessenti->count()) {
            Log::info('Mesure pixel ressenti indisponible, valeurs LLM conservées.', [
                'campaign_id' => $campagne->id,
                'barres_detectees' => $mesures === null ? null : count($mesures),
                'questions_ressenti' => $questionsRessenti->count(),
            ]);

            return $reponses;
        }

        foreach ($questionsRessenti as $i => $question) {
            $mesure = $mesures[$i];

            // Barre sans trait ou trait ambigu => revue manuelle (assistant de saisie)
            $reponses[(string) $question->id] = ($mesure['pct'] !== null && $mesure['nbTraits'] === 1)
                ? ['value' => (int) round($mesure['pct']), 'confidence' => 0.98]
                : ['value' => null, 'confidence' => 0.0];
        }

        return $reponses;
    }
```

Note : le prompt LLM (`buildPrompt()`) reste inchangé — l'estimation LLM sert de repli quand la mesure pixel échoue.

- [ ] **Step 4 : Vérifier que les tests passent**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire/QuestionnaireOcrServiceTest.php`
Attendu : PASS (7 tests).

- [ ] **Step 5 : Commit**

```bash
git add app/Services/Questionnaire/QuestionnaireOcrService.php tests/Feature/Questionnaire/QuestionnaireOcrServiceTest.php
git commit -m "feat(questionnaires): mesure pixel des ressentis fusionnée dans l'OCR"
```

---

### Task 5 : Lint + suite complète questionnaires

- [ ] **Step 1 : Pint**

Run : `./vendor/bin/sail pint --dirty`
Attendu : aucun changement ou corrections mineures de style.

- [ ] **Step 2 : Suite questionnaires + scans**

Run : `./vendor/bin/sail pest tests/Feature/Questionnaire tests/Unit/Questionnaire`
Attendu : 0 failed (les 2 skips Imagick éventuels sont acceptables).

- [ ] **Step 3 : Commit final si Pint a modifié des fichiers**

```bash
git add -A && git diff --cached --quiet || git commit -m "style(questionnaires): pint"
```
