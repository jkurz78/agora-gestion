<?php

// tests/Feature/Migrations/TiersTableTest.php
declare(strict_types=1);
use Illuminate\Support\Facades\Schema;

it('tiers table has expected columns', function () {
    expect(Schema::hasColumns('tiers', [
        'id', 'type', 'nom', 'prenom', 'email', 'telephone',
        'adresse_ligne1', 'code_postal', 'ville', 'pays',
        'entreprise', 'date_naissance', 'est_helloasso',
        'pour_depenses', 'pour_recettes',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});
