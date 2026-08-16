<?php

declare(strict_types=1);

/**
 * Garde contre la réapparition d'une période comptable codée en dur.
 *
 * Le mois de début d'exercice est un réglage de l'association
 * (`exercice_mois_debut`). Écrire « septembre-août » dans le code produit des
 * chiffres faux, en silence, pour toute association qui ne suit pas ce
 * calendrier — et le défaut est invisible sur un jeu de données de test qui
 * utilise le mois par défaut.
 *
 * Ce motif avait proliféré dans six fichiers avant d'être corrigé le
 * 2026-08-16. Ce test échoue si quelqu'un le réintroduit ailleurs : c'est le
 * seul moyen qu'une règle transverse tienne dans la durée, un commentaire
 * n'étant lu que par qui touche déjà le bon fichier.
 *
 * `ExerciceService::dateRange()` est la seule source autorisée. Ce service est
 * lui-même exempté : c'est lui qui porte la connaissance du calendrier.
 */
it('n’écrit nulle part une borne d’exercice en dur', function (): void {
    $exemptes = [
        // Porte la définition du calendrier comptable, donc les seuls
        // littéraux légitimes.
        'app/Services/ExerciceService.php',
    ];

    $fautifs = [];

    $fichiers = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($fichiers as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }

        $chemin = str_replace(base_path().'/', '', $fichier->getPathname());

        if (in_array($chemin, $exemptes, true)) {
            continue;
        }

        $contenu = (string) file_get_contents($fichier->getPathname());

        // On cible la SIGNATURE du défaut : un fragment de date collé à une
        // année calculée. Deux formes seulement, et aucune date en commentaire
        // ou en littéral complet ne peut les déclencher :
        //
        //   "{$exercice}-09-01"        → interpolation
        //   ($exercice + 1).'-08-31'   → concaténation
        $motifs = [
            '/\{\$\w+\}-\d{2}-\d{2}/',
            '/\.\s*[\'"]-\d{2}-\d{2}[\'"]/',
        ];

        foreach ($motifs as $motif) {
            if (preg_match($motif, $contenu, $m)) {
                $fautifs[] = $chemin.' → '.trim($m[0]);
            }
        }
    }

    expect($fautifs)->toBe(
        [],
        "Bornes d'exercice codées en dur — utiliser ExerciceService::dateRange() :\n".implode("\n", $fautifs)
    );
});
