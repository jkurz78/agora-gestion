<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Le tri des colonnes de l'écran « Écritures de rattachement »
|--------------------------------------------------------------------------
|
| Le tri est du JavaScript, dans le script de la page (resources/views/exercices/provisions.blade.php) : il
| lit `data-sort` sur les cellules (dates en ISO, nombres avec un point), à défaut leur texte, et ne trie que
| les `table[data-triable]` par leurs `th[data-tri]`. Aucun test PHP ne peut le faire tourner : celui-ci en
| extrait le script, et l'exécute sous Node dans un DOM minimal (tests/fixtures/tri-colonnes/harness.js) pour
| prouver ce qu'il trie — « 1000 » après « 175 », les dates, les accents, l'égalité dans l'ordre d'origine,
| l'indicateur de sens — et qu'il s'attache bien par délégation sur le document.
|
| Sans Node sur la machine, le test est ignoré : ce n'est pas une dépendance du projet. Ce n'est pas un test
| de navigateur : la mise en page, elle, se vérifie à l'œil.
*/

test('le script de tri de la page trie nombres, dates et textes, et s’attache par délégation sur le document', function () {
    preg_match('#<script>(.*?)</script>#s', (string) file_get_contents(resource_path('views/exercices/provisions.blade.php')), $script);
    expect($script)->not->toBeEmpty('Le script de tri est introuvable dans la page.');

    $fichier = tempnam(sys_get_temp_dir(), 'tri-colonnes-');
    file_put_contents($fichier, $script[1]);

    try {
        $resultat = Process::run(['node', base_path('tests/fixtures/tri-colonnes/harness.js'), $fichier]);
    } finally {
        unlink($fichier);
    }

    expect($resultat->output())->not->toContain('KO');
    expect($resultat->output())->toContain('ok  montant croissant');
    expect($resultat->exitCode())->toBe(0, $resultat->output().$resultat->errorOutput());
})->skip(
    fn (): bool => Process::run(['node', '--version'])->failed(),
    'Node n’est pas disponible : le script de tri n’est pas exécutable ici.',
);
