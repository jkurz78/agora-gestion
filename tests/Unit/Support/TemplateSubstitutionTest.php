<?php

declare(strict_types=1);

use App\Support\TemplateSubstitution;

it('substitue les variables non-vides classiquement', function (): void {
    $out = TemplateSubstitution::apply(
        'Bonjour {prenom} {nom}, ravi de vous écrire.',
        ['{prenom}' => 'Anne', '{nom}' => 'Kurz']
    );
    expect($out)->toBe('Bonjour Anne Kurz, ravi de vous écrire.');
});

it('absorbe l\'espace de droite quand la variable est vide', function (): void {
    $out = TemplateSubstitution::apply(
        'Bonjour {politesse} Kurz, ravi de vous écrire.',
        ['{politesse}' => '', '{nom}' => 'Kurz']
    );
    expect($out)->toBe('Bonjour Kurz, ravi de vous écrire.');
});

it('absorbe l\'espace de gauche en fallback', function (): void {
    $out = TemplateSubstitution::apply(
        'Bonjour {politesse}.',
        ['{politesse}' => '']
    );
    expect($out)->toBe('Bonjour.');
});

it('supprime juste la variable si pas d\'espace adjacent', function (): void {
    $out = TemplateSubstitution::apply(
        '{politesse}Kurz',
        ['{politesse}' => '']
    );
    expect($out)->toBe('Kurz');
});

it('gère null comme vide', function (): void {
    $out = TemplateSubstitution::apply(
        'Bonjour {politesse} Kurz',
        ['{politesse}' => null]
    );
    expect($out)->toBe('Bonjour Kurz');
});

it('n\'absorbe qu\'un seul espace par variable vide', function (): void {
    // Cas pathologique : utilisateur a tapé deux espaces autour de la variable
    $out = TemplateSubstitution::apply(
        'Bonjour  {politesse}  Kurz',
        ['{politesse}' => '']
    );
    expect($out)->toBe('Bonjour   Kurz'); // 3 espaces (2 + 1 préservé)
    // Note : le HTML email collapse les espaces, donc cosmétiquement OK.
});
