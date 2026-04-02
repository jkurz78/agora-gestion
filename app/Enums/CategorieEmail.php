<?php

declare(strict_types=1);

namespace App\Enums;

enum CategorieEmail: string
{
    case Formulaire = 'formulaire';
    case Attestation = 'attestation';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Formulaire => 'Formulaire',
            self::Attestation => 'Attestation de présence',
            self::Document => 'Document (facture / devis / pro forma)',
        };
    }

    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        $logos = [
            '{logo}' => 'Logo de l\'association',
            '{logo_operation}' => 'Logo de l\'opération (ou logo association par défaut)',
        ];

        $common = [
            '{prenom}' => 'Prénom du participant',
            '{nom}' => 'Nom du participant',
            '{operation}' => 'Nom de l\'opération',
            '{type_operation}' => 'Nom du type d\'opération',
            '{date_debut}' => 'Date début opération',
            '{date_fin}' => 'Date fin opération',
            '{nb_seances}' => 'Nombre de séances',
        ] + $logos;

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
            self::Document => [
                '{prenom}' => 'Prénom du destinataire',
                '{nom}' => 'Nom du destinataire',
                '{type_document}' => 'Type (facture, devis, pro forma)',
                '{type_document_uc}' => 'Type avec majuscule (Facture, Devis, Pro forma)',
                '{type_document_article}' => 'Type avec article (la facture, le devis, la pro forma)',
                '{type_document_article_de}' => 'Type avec de (de la facture, du devis, de la pro forma)',
                '{numero_document}' => 'Numéro du document',
                '{date_document}' => 'Date du document',
                '{montant_total}' => 'Montant total',
            ] + $logos,
        };
    }
}
