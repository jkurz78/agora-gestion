<?php

require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tiers;

$tiers = Tiers::orderBy('nom')->get();

foreach ($tiers as $t) {
    echo $t->displayName() . PHP_EOL;
}
