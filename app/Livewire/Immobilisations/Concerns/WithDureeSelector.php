<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations\Concerns;

/**
 * Sélecteur de durée d'amortissement, partagé entre le formulaire de création
 * (ImmobilisationIndex) et le formulaire d'édition (ImmobilisationShow).
 *
 * Le <select> ne proposait que les durées usuelles (3/5/7/10/15 ans) : aucun
 * parcours ne pouvait donc produire une durée qui n'est pas un multiple de 12,
 * alors que le stockage (duree_mois, 1 à 600) et l'accesseur duree_label le
 * permettaient déjà. Cas réels visés : un bail restant à courir sur 42 mois,
 * une convention de subvention sur 30 mois.
 *
 * L'option « Autre durée… » révèle une saisie libre en mois, qui écrit
 * directement dans duree_mois — la classe utilisatrice doit déclarer cette
 * propriété (`public int $duree_mois`).
 */
trait WithDureeSelector
{
    /** Durées proposées, en mois. La saisie libre reste possible via 'autre'. */
    public const DUREES_USUELLES = [36 => '3 ans', 60 => '5 ans', 84 => '7 ans', 120 => '10 ans', 180 => '15 ans'];

    /** Valeur du <select> : une clé (en chaîne) de DUREES_USUELLES, ou 'autre'. */
    public string $dureeChoix = '60';

    /**
     * Une durée usuelle choisie écrase directement duree_mois. 'autre' laisse
     * la main au champ libre, qui porte lui-même wire:model="duree_mois" —
     * rien à faire ici dans ce cas.
     */
    public function updatedDureeChoix(string $value): void
    {
        if ($value !== 'autre') {
            $this->duree_mois = (int) $value;
        }
    }

    /** Positionne dureeChoix (et duree_mois) à partir d'une durée existante. */
    private function initDureeChoix(int $dureeMois): void
    {
        $this->duree_mois = $dureeMois;
        $this->dureeChoix = array_key_exists($dureeMois, self::DUREES_USUELLES)
            ? (string) $dureeMois
            : 'autre';
    }
}
