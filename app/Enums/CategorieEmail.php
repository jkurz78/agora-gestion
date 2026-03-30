<?php

declare(strict_types=1);

namespace App\Enums;

enum CategorieEmail: string
{
    case Formulaire = 'formulaire';
    case Attestation = 'attestation';
    case Facture = 'facture';

    public function label(): string
    {
        return match ($this) {
            self::Formulaire => 'Formulaire',
            self::Attestation => 'Attestation de présence',
            self::Facture => 'Facture',
        };
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        $common = [
            '{prenom}' => 'Prénom du participant',
            '{nom}' => 'Nom du participant',
            '{operation}' => 'Nom de l\'opération',
            '{type_operation}' => 'Nom du type d\'opération',
            '{date_debut}' => 'Date début opération',
            '{date_fin}' => 'Date fin opération',
            '{nb_seances}' => 'Nombre de séances',
        ];

        return match ($this) {
            self::Formulaire => $common + [
                '{bloc_liens}' => 'Bloc complet (bouton + code + expiration)',
                '{url}' => 'URL du formulaire',
                '{code}' => 'Code du formulaire',
                '{date_expiration}' => 'Date d\'expiration du lien',
            ],
            self::Attestation => $common + [
                '{numero_seance}' => 'Numéro de la séance',
                '{date_seance}' => 'Date de la séance',
                '{bloc_seances}' => 'Bloc séance(s) : détail unitaire ou tableau récapitulatif',
            ],
            self::Facture => $common + [
                '{numero_seance}' => 'Numéro de la séance',
                '{date_seance}' => 'Date de la séance',
                '{date_facture}' => 'Date de la facture',
                '{numero_facture}' => 'Numéro de la facture',
            ],
        };
    }
}
