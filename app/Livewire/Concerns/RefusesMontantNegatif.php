<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Impose qu'un champ montant soit strictement positif.
 *
 * Fournit le message standardisé et des helpers pour construire les règles et
 * messages de validation de façon uniforme sur tous les composants Livewire
 * qui acceptent une saisie de montant.
 *
 * Note : les constantes de trait ne sont pas directement accessibles en PHP
 * (ex: `RefusesMontantNegatif::MONTANT_NEGATIF_MESSAGE` lève une erreur fatale).
 * Utiliser `MontantValidation::MESSAGE` pour référencer le message dans les tests
 * ou en dehors d'une classe qui use ce trait.
 *
 * @see App\Livewire\Concerns\MontantValidation
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */
trait RefusesMontantNegatif
{
    /**
     * Retourne la règle de validation standard pour les champs montant.
     */
    public static function montantPositifRule(): string
    {
        return 'gt:0';
    }

    /**
     * Retourne les messages de validation standardisés pour les champs montant.
     *
     * @param  list<string>  $fields  Noms des champs à couvrir (ex: ['montant', 'prix_unitaire'])
     * @return array<string, string>
     */
    public static function montantNegatifMessages(array $fields): array
    {
        $messages = [];
        foreach ($fields as $field) {
            $messages["{$field}.gt"] = MontantValidation::MESSAGE;
        }

        return $messages;
    }
}
