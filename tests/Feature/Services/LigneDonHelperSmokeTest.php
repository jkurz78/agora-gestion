<?php

declare(strict_types=1);

use App\Enums\UsageComptable;

it('crée une ligne don valide avec toutes les pièces', function () {
    $ligne = $this->ligneDonValide();

    expect($ligne)->not->toBeNull();
    expect($ligne->transaction)->not->toBeNull();
    expect($ligne->transaction->tiers)->not->toBeNull();
    expect($ligne->sousCategorie)->not->toBeNull();
    expect($ligne->sousCategorie->hasUsage(UsageComptable::Don))->toBeTrue();
});

it('accepte des overrides sur le tiers', function () {
    $ligne = $this->ligneDonValide(tiersOverrides: ['nom' => 'Martin', 'prenom' => 'Paul']);

    // L'accesseur Tiers::nom retourne le nom en majuscules (v2.5.3)
    expect($ligne->transaction->tiers->nom)->toBe('MARTIN');
    expect($ligne->transaction->tiers->prenom)->toBe('Paul');
});

it('accepte des overrides sur le montant de la ligne', function () {
    $ligne = $this->ligneDonValide(ligneOverrides: ['montant' => 500.00]);

    expect((float) $ligne->montant)->toBe(500.00);
});
