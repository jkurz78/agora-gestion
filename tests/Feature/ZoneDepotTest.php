<?php

declare(strict_types=1);

/*
 * Zone de glisser-déposer.
 *
 * Le composant ENVELOPPE un champ fichier existant, il ne le remplace pas :
 * c'est ce qui garantit qu'un navigateur sans glisser-déposer, ou un
 * utilisateur au clavier, garde exactement la voie d'avant — le clic sur le
 * label. Ces tests verrouillent cette propriété, qu'une réécriture du
 * composant en zone autonome ferait disparaître sans bruit.
 */

use Illuminate\Support\Facades\Blade;

/**
 * Rend le composant par le VRAI chemin Blade, et non par view() : `@props` ne
 * s'applique qu'à une invocation de composant, si bien qu'un rendu direct de
 * la vue appliquerait toujours les valeurs par défaut et ne testerait rien.
 */
function rendreZoneDepot(string $attributs, string $contenu): string
{
    return Blade::render("<x-zone-depot {$attributs}>{$contenu}</x-zone-depot>");
}

it('laisse passer le champ fichier qu elle enveloppe', function (): void {
    $html = rendreZoneDepot('', '<input type="file" wire:model="pieceJointe" class="d-none">');

    // Le champ d'origine est intact, avec son wire:model : c'est lui qui
    // televerse, au clic comme au depot.
    expect($html)->toContain('<input type="file" wire:model="pieceJointe" class="d-none">');
});

it('depose le fichier en emettant un evenement change sur le champ', function (): void {
    $html = rendreZoneDepot('', '<input type="file" wire:model="pj">');

    // Livewire n'ecoute QUE l'evenement `change` du champ : sans lui, un
    // fichier depose serait affecte a l'input sans jamais partir.
    expect($html)->toContain("new Event('change', { bubbles: true })")
        // input.files n'accepte qu'un FileList, qu'on ne peut construire
        // qu'a travers un DataTransfer.
        ->and($html)->toContain('new DataTransfer()')
        ->and($html)->toContain('@drop.prevent="deposer($event)"');
});

it('ignore un champ desactive', function (): void {
    $html = rendreZoneDepot('', '<input type="file" wire:model="pj" disabled>');

    // Un formulaire en cours d'envoi desactive ses champs : la zone ne doit
    // pas contourner ce verrou.
    expect($html)->toContain('champ.disabled');
});

it('affiche une aide personnalisable et sait la taire', function (): void {
    expect(rendreZoneDepot('aide="ou déposez la facture ici"', '<input type="file">'))
        ->toContain('ou déposez la facture ici');

    // La chaîne vide tait l'aide, et non `:aide="null"` : @props traite un
    // null explicite comme un attribut absent et réapplique son défaut. C'est
    // du comportement Laravel, pas du composant — d'où ce test, qui fixe la
    // façon de faire pour qui voudra une zone muette.
    //
    // On vise la DIV, pas la chaîne : le sélecteur `.zone-depot-aide` reste
    // présent dans la feuille de style même quand l'aide n'est pas rendue.
    expect(rendreZoneDepot('aide=""', '<input type="file">'))
        ->not->toContain('<div class="zone-depot-aide');
});

it('l ecran d analyse de facture propose le glisser-deposer', function (): void {
    $vue = file_get_contents(resource_path('views/livewire/transaction-form.blade.php'));

    // Premiere surface equipee, choisie parce que c'est le depot le plus
    // frequent. Les vingt autres suivront si l'ergonomie convient.
    expect($vue)->toContain('<x-zone-depot')
        ->and($vue)->toContain('ou glissez-déposez la facture ici');
});
