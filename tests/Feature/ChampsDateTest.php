<?php

declare(strict_types=1);

/*
 * Champs date : jj/mm/aaaa quelle que soit la langue du navigateur.
 *
 * Un <input type="date"> natif suit la langue de l'INTERFACE du navigateur, pas
 * le lang="fr" de la page : sous un Firefox (Zen) en anglais, il affiche
 * MM/DD/YYYY. Le back-office passe donc par x-date-input (flatpickr fr).
 *
 * Exception assumée : les pages publiques et le portail gardent le sélecteur
 * natif — sur téléphone il suit la langue du téléphone et offre la roue de
 * défilement, plus pratique qu'un calendrier pour une date de naissance.
 */

use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

/**
 * Rend le composant par le vrai chemin Blade : `@props` ne s'applique qu'à une
 * invocation de composant, un rendu direct de la vue ne testerait rien.
 */
function rendreChampDate(string $attributs): string
{
    return Blade::render("<x-date-input {$attributs} />");
}

it('n emploie aucun champ date natif hors des pages publiques et du portail', function (): void {
    $exceptions = [
        'formulaire/',                 // formulaire d'inscription public
        'questionnaire/repondant/',    // questionnaires de satisfaction (répondants)
        'livewire/portail/',           // portail des tiers
    ];

    $fautifs = [];
    foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $fichier) {
        $chemin = str_replace('\\', '/', $fichier->getRelativePathname());
        if (collect($exceptions)->contains(fn (string $prefixe): bool => str_starts_with($chemin, $prefixe))) {
            continue;
        }

        if (preg_match('/type\s*=\s*["\']date["\']/', $fichier->getContents()) === 1) {
            $fautifs[] = $chemin;
        }
    }

    expect($fautifs)->toBe([]);
});

it('suit la propriété Livewire liée, pour refléter les dates fixées par le serveur', function (): void {
    // wire:ignore protège flatpickr des re-rendus, mais l'empêcherait aussi de
    // voir une date posée par le serveur (ouverture de modale, bouton
    // « Aujourd'hui », remise à zéro) : le composant surveille la propriété.
    expect(rendreChampDate('name="d" wire:model.live="comptabiliserDate"'))
        ->toContain("propriete: 'comptabiliserDate'")
        ->toContain('$wire.$watch(this.propriete')
        ->and(rendreChampDate('name="d" wire:model="valeurs.12"'))
        ->toContain("propriete: 'valeurs.12'");
});

it('ne surveille rien hors de Livewire', function (): void {
    // Formulaire classique (Paramètres > Comptes bancaires) : aucune propriété.
    expect(rendreChampDate('name="date_solde_initial" id="edit_date"'))
        ->toContain('propriete: null');
});

it('se décline en petite taille, actif comme désactivé', function (): void {
    expect(rendreChampDate('name="d" wire:model="d" size="sm"'))
        ->toContain('class="input-group input-group-sm" wire:ignore')
        ->and(rendreChampDate('name="d" value="2026-01-15" size="sm" :disabled="true"'))
        ->toContain('value="15/01/2026"')
        ->toContain('class="form-control form-control-sm bg-light" disabled')
        ->and(rendreChampDate('name="d" wire:model="d"'))
        ->toContain('class="input-group" wire:ignore');
});
