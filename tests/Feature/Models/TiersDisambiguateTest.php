<?php

declare(strict_types=1);

use App\Models\Tiers;

it('ne modifie pas le label quand il n\'y a pas d\'homonyme', function () {
    $t1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $t2 = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Marie']);

    $labels = Tiers::disambiguate(collect([$t1, $t2]));

    expect($labels[$t1->id])->toBe($t1->displayName());
    expect($labels[$t2->id])->toBe($t2->displayName());
});

it('ajoute l\'email pour disambiguer des homonymes', function () {
    $t1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean1@mail.com']);
    $t2 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean2@mail.com']);

    $labels = Tiers::disambiguate(collect([$t1, $t2]));

    expect($labels[$t1->id])->toContain('jean1@mail.com');
    expect($labels[$t2->id])->toContain('jean2@mail.com');
});

it('utilise la ville quand il n\'y a pas d\'email', function () {
    $t1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => null, 'ville' => 'Paris']);
    $t2 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => null, 'ville' => 'Lyon']);

    $labels = Tiers::disambiguate(collect([$t1, $t2]));

    expect($labels[$t1->id])->toContain('Paris');
    expect($labels[$t2->id])->toContain('Lyon');
});

it('utilise le code postal quand il n\'y a ni email ni ville', function () {
    $t1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => null, 'ville' => null, 'code_postal' => '75001']);
    $t2 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => null, 'ville' => null, 'code_postal' => '69001']);

    $labels = Tiers::disambiguate(collect([$t1, $t2]));

    expect($labels[$t1->id])->toContain('75001');
    expect($labels[$t2->id])->toContain('69001');
});

it('utilise l\'ID en dernier recours', function () {
    $t1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => null, 'ville' => null, 'code_postal' => null]);
    $t2 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => null, 'ville' => null, 'code_postal' => null]);

    $labels = Tiers::disambiguate(collect([$t1, $t2]));

    expect($labels[$t1->id])->toContain('#'.$t1->id);
    expect($labels[$t2->id])->toContain('#'.$t2->id);
});

it('gère un mix d\'homonymes et de tiers uniques', function () {
    $t1 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean1@mail.com']);
    $t2 = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'email' => 'jean2@mail.com']);
    $t3 = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Marie']);

    $labels = Tiers::disambiguate(collect([$t1, $t2, $t3]));

    expect($labels[$t1->id])->toContain('jean1@mail.com');
    expect($labels[$t2->id])->toContain('jean2@mail.com');
    expect($labels[$t3->id])->toBe($t3->displayName());
    expect($labels[$t3->id])->not->toContain('(');
});
