<?php

declare(strict_types=1);

use App\Models\Association;

dataset('urlRenouvellementCases', [
    'specific URL set' => ['https://asso.fr/renouveler', 'https://asso.fr', 'https://asso.fr/renouveler'],
    'specific null falls back to site' => [null, 'https://asso.fr', 'https://asso.fr'],
    'specific empty falls back to site' => ['', 'https://asso.fr', 'https://asso.fr'],
    'both null → null' => [null, null, null],
    'both empty → null' => ['', '', null],
]);

dataset('urlNouveauDonCases', [
    'specific URL set' => ['https://asso.fr/don', 'https://asso.fr', 'https://asso.fr/don'],
    'specific null falls back to site' => [null, 'https://asso.fr', 'https://asso.fr'],
    'specific empty falls back to site' => ['', 'https://asso.fr', 'https://asso.fr'],
    'both null → null' => [null, null, null],
    'both empty → null' => ['', '', null],
]);

it('urlRenouvellementAdhesion retourne la bonne URL', function (
    ?string $specific,
    ?string $siteweb,
    ?string $expected,
): void {
    $asso = new Association([
        'url_renouvellement_adhesion' => $specific,
        'url_site_web' => $siteweb,
    ]);

    expect($asso->urlRenouvellementAdhesion())->toBe($expected);
})->with('urlRenouvellementCases');

it('urlNouveauDon retourne la bonne URL', function (
    ?string $specific,
    ?string $siteweb,
    ?string $expected,
): void {
    $asso = new Association([
        'url_nouveau_don' => $specific,
        'url_site_web' => $siteweb,
    ]);

    expect($asso->urlNouveauDon())->toBe($expected);
})->with('urlNouveauDonCases');
