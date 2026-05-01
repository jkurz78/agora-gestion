<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Helpers de validation pour les champs montant.
 *
 * Classe statique pure — toute règle et tout message standardisé pour les
 * champs montant passent par ici. Utiliser `MontantValidation::RULE` dans les
 * tableaux de règles et `MontantValidation::messages([...])` dans les tableaux
 * de messages. Ce pattern est la convention pour les Steps 6-8 de l'audit.
 *
 * @see docs/audit/2026-04-30-signe-negatif.md §2.4
 */
final class MontantValidation
{
    public const MESSAGE = "Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante.";

    public const RULE = 'gt:0';

    /**
     * Constructeur privé : classe statique pure, ne pas instancier.
     */
    private function __construct() {}

    /**
     * Messages de validation pour les champs montant.
     *
     * @param  list<string>  $fields  Liste des champs à matcher (ex: ['montant', 'affectations.*.montant'])
     * @return array<string, string>
     */
    public static function messages(array $fields): array
    {
        $messages = [];
        foreach ($fields as $field) {
            $messages["{$field}.gt"] = self::MESSAGE;
        }

        return $messages;
    }
}
