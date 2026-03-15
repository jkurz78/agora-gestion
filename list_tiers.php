<?php
/**
 * Liste les tiers tels qu'ils apparaissent dans les selects recettes/dépenses.
 * Usage : ./vendor/bin/sail artisan tinker --execute="require 'list_tiers.php';"
 *    ou : php list_tiers.php  (depuis la racine, avec la DB accessible)
 */

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tiers;

$sections = [
    'RECETTES' => Tiers::where('pour_recettes', true)->orderBy('nom')->get(),
    'DÉPENSES' => Tiers::where('pour_depenses', true)->orderBy('nom')->get(),
];

$lines = [];
$lines[] = str_repeat('=', 50);
$lines[] = 'LISTE DES TIERS — ' . now()->format('d/m/Y H:i');
$lines[] = str_repeat('=', 50);

foreach ($sections as $label => $tiers) {
    $lines[] = '';
    $lines[] = "── $label (" . $tiers->count() . ") ──────────────────────────";
    foreach ($tiers as $t) {
        $type = $t->type === 'entreprise' ? '[société]' : '[personne]';
        $lines[] = sprintf('  %-35s %s', $t->displayName(), $type);
    }
}

$lines[] = '';

$output = implode(PHP_EOL, $lines);

// Écriture fichier
$file = __DIR__ . '/tiers_export.txt';
file_put_contents($file, $output);

echo $output;
echo PHP_EOL . "→ Fichier écrit : tiers_export.txt" . PHP_EOL;
