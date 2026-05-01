<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Constantes de validation pour les champs montant.
 *
 * Cette classe compagnon du trait RefusesMontantNegatif expose la constante
 * de message standardisée. Séparer la constante du trait permet de la
 * référencer directement (ex: dans les tests) sans passer par une classe
 * qui use le trait — les constantes de trait ne sont pas accessibles
 * directement en PHP.
 *
 * @see App\Livewire\Concerns\RefusesMontantNegatif
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */
final class MontantValidation
{
    public const MESSAGE = "Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante.";
}
