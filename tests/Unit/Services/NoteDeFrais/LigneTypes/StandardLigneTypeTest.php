<?php

declare(strict_types=1);

use App\Enums\NoteDeFraisLigneType;
use App\Services\NoteDeFrais\LigneTypes\StandardLigneType;

beforeEach(function () {
    $this->strategy = new StandardLigneType();
});

it('retourne la clé Standard', function () {
    expect($this->strategy->key())->toBe(NoteDeFraisLigneType::Standard);
});

it('calcule le montant depuis la valeur saisie', function () {
    $montant = $this->strategy->computeMontant(['montant' => 42.5]);
    expect($montant)->toBe(42.5);
});

it('accepte montant sous forme de chaine avec virgule', function () {
    $montant = $this->strategy->computeMontant(['montant' => '42,5']);
    expect($montant)->toBe(42.5);
});

it('metadata est un tableau vide', function () {
    expect($this->strategy->metadata(['montant' => 42.5]))->toBe([]);
});

it('renderDescription renvoie chaine vide', function () {
    expect($this->strategy->renderDescription([]))->toBe('');
});

it('resolveSousCategorieId renvoie l\'id saisi inchange', function () {
    expect($this->strategy->resolveSousCategorieId(7))->toBe(7);
    expect($this->strategy->resolveSousCategorieId(null))->toBeNull();
});

it('validate ne leve pas pour un draft minimal valide', function () {
    $this->strategy->validate(['montant' => 10]);
    expect(true)->toBeTrue();
});
