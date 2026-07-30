<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Étapes ordonnées du parcours comptable d'une association.
 *
 * L'étape n'est jamais stockée : elle est dérivée des données par
 * App\Services\Compta\EtatComptaResolver. Une seconde source de vérité
 * finirait par diverger — c'est la leçon de la recette du 2026-07-29.
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
            self::BackfillRequis => 'Conversion en partie double requise',
            self::RepriseInitialeRequise => 'Reprise initiale des soldes requise',
            self::ReconciliationRequise => 'Réconciliation des statuts requise',
            self::Operationnel => 'Opérationnel',
        };
    }

    /** Geste qui débloque l'étape, ou null si rien n'est requis. */
    public function geste(): ?string
    {
        return match ($this) {
            self::BackfillRequis => 'php artisan compta:backfill-partie-double --all',
            self::RepriseInitialeRequise => 'php artisan compta:bootstrap-an --dry-run puis --confirmer',
            self::ReconciliationRequise => 'php artisan compta:reconcilier-statuts',
            self::Operationnel => null,
        };
    }
}
