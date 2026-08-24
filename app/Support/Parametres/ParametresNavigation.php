<?php

declare(strict_types=1);

namespace App\Support\Parametres;

use App\Enums\RoleAssociation;

/**
 * Source unique de l'arbre de la section Paramètres.
 *
 * Quatre surfaces la lisent — sidebar, page d'accueil, fil d'Ariane et garde
 * serveur. Aucune ne redéclare un libellé ni un droit : c'est ce qui les empêche
 * structurellement de diverger.
 */
final class ParametresNavigation
{
    /** @return list<SectionParametres> */
    public static function sections(): array
    {
        $admin = [RoleAssociation::Admin];
        $adminComptable = [RoleAssociation::Admin, RoleAssociation::Comptable];
        $adminGestionnaire = [RoleAssociation::Admin, RoleAssociation::Gestionnaire];

        return [
            new SectionParametres(
                cle: 'association-acces',
                libelle: 'Association et accès',
                description: 'Qui vous êtes, et qui accède à l’application.',
                icone: 'bi-building',
                ecrans: [
                    new EcranParametre('informations', 'Informations de l’association', 'parametres.association', 'bi-info-circle', $admin),
                    new EcranParametre('utilisateurs', 'Utilisateurs et droits', 'parametres.utilisateurs.index', 'bi-people', $admin),
                    new EcranParametre('liens-publics', 'Liens publics', 'parametres.liens-publics', 'bi-link-45deg', $adminGestionnaire),
                ],
            ),
            new SectionParametres(
                cle: 'adhesions-dons',
                libelle: 'Adhésions et dons',
                description: 'Ce que les adhérents et donateurs voient et reçoivent.',
                icone: 'bi-heart',
                ecrans: [
                    new EcranParametre('formules-adhesion', 'Formules d’adhésion', 'parametres.adhesions.formules', 'bi-card-checklist', $adminGestionnaire),
                    new EcranParametre('recus-fiscaux', 'Reçus fiscaux', 'parametres.recus-fiscaux', 'bi-receipt', $adminComptable),
                ],
            ),
            new SectionParametres(
                cle: 'comptabilite',
                libelle: 'Comptabilité',
                description: 'Comment les écritures sont ventilées et facturées.',
                icone: 'bi-calculator',
                ecrans: [
                    new EcranParametre('plan-comptable', 'Plan comptable', 'parametres.plan-comptable', 'bi-list-columns', $adminComptable),
                    new EcranParametre('affectations-comptables', 'Affectations comptables', 'parametres.comptabilite.usages', 'bi-diagram-3', $adminComptable),
                    new EcranParametre('facturation', 'Facturation', 'parametres.facturation', 'bi-file-earmark-text', $adminComptable),
                ],
            ),
            new SectionParametres(
                cle: 'services-connectes',
                libelle: 'Services connectés',
                description: 'Ce à quoi l’application est branchée.',
                icone: 'bi-plug',
                ecrans: [
                    new EcranParametre('helloasso', 'HelloAsso', 'parametres.helloasso', 'bi-box-arrow-in-down', $admin),
                    new EcranParametre('reception-documents', 'Réception de documents par e-mail', 'parametres.reception-documents', 'bi-envelope-open', $admin),
                    new EcranParametre('envoi-emails', 'Envoi d’e-mails', 'parametres.smtp', 'bi-send', $admin),
                    new EcranParametre('ocr-ia', 'OCR / IA', 'parametres.ocr-ia', 'bi-robot', $admin),
                ],
            ),
        ];
    }
}
