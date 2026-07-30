<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Étapes ordonnées du parcours comptable d'une association.
 *
 * L'étape n'est jamais stockée : elle est dérivée des données par
 * App\Services\Compta\EtatComptaResolver. Une seconde source de vérité
 * finirait par diverger — c'est la leçon de la recette du 2026-07-29.
 *
 * Chaque cas porte son libellé, et rien d'autre. Le remède — quelle commande
 * lancer, avec quel tenant, ou quel écran ouvrir — appartient à la couche qui
 * connaît le support et l'association. Faire porter une commande artisan par
 * l'énumération la faisait remonter jusque dans l'assistant de clôture, où le
 * trésorier lisait une ligne de console qu'il ne pouvait pas exécuter.
 *
 * Les libellés évitent « conversion » et « backfill » (vocabulaire de migration,
 * opération que le trésorier n'a pas déclenchée) et « réconciliation », qui en
 * français comptable désigne la même chose que « rapprochement » — mot déjà pris
 * par la garde « Rapprochements en cours » de la même checklist, qui parle de
 * banque et non de statuts.
 */
enum EtapeCompta: string
{
    case BackfillRequis = 'backfill_requis';
    case RepriseInitialeRequise = 'reprise_initiale_requise';
    case ReconciliationRequise = 'reconciliation_requise';
    case Operationnel = 'operationnel';

    public function label(): string
    {
        return match ($this) {
            self::BackfillRequis => 'Écritures comptables incomplètes',
            self::RepriseInitialeRequise => 'Soldes d’ouverture non repris',
            self::ReconciliationRequise => 'Statuts de règlement à mettre à jour',
            self::Operationnel => 'Opérationnel',
        };
    }
}
