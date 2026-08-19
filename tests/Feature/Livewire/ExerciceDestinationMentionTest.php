<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
 * Contrepartie du retrait des bornes d'exercice affiché (voir
 * DateHorsExerciceAfficheTest) : plus rien n'attrape la faute de frappe sur
 * l'année (2025 au lieu de 2026 en janvier). Le composant
 * <x-exercice-destination> la rend de nouveau visible, sans bloquer la
 * saisie.
 *
 * ⚠️ Horloge de test gelée au 2026-01-15 (tests/Pest.php) : l'exercice
 * affiché est 2025 (2025-09-01 → 2026-08-31). L'exercice 2024 court du
 * 2024-09-01 au 2025-08-31 — les dates de ce fichier y sont franchement à
 * l'intérieur (15 novembre), jamais sur une borne.
 */

beforeEach(function () {
    $this->setupPartieDoubleContext();
});

it('affiche la mention de destination quand la date sort de l\'exercice affiché', function () {
    $html = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('date', '2024-11-15')
        ->html();

    expect($html)->toContain('sera rattachée à l\'exercice 2024-2025');
});

it('n\'affiche pas la mention de destination quand la date est dans l\'exercice affiché', function () {
    $html = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('date', '2025-11-15')
        ->html();

    expect($html)->not->toContain('sera rattachée');
});
