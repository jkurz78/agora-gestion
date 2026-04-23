<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TypeTransaction;
use App\Models\Tiers;

/**
 * Soft-guard for CSV import: prevents unchecking pour_depenses / pour_recettes
 * on a Tiers that already has linked transactions of the matching type.
 *
 * A modal cannot be shown in batch mode, so the dereferencing is silently
 * ignored and a warning is added to the import report instead.
 */
final class TiersImportDereferenceGuard
{
    /**
     * Apply the soft-guard to an import data array for an existing Tiers.
     *
     * Only rows that update an existing tiers are concerned (new tiers
     * have no prior transactions, so the guard is never needed for creates).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function apply(Tiers $tiers, array $data): array
    {
        $warnings = [];

        if ($tiers->pour_depenses === true
            && array_key_exists('pour_depenses', $data)
            && $data['pour_depenses'] === false
            && $tiers->transactions()->where('type', TypeTransaction::Depense)->exists()
        ) {
            $data['pour_depenses'] = true;
            $warnings[] = sprintf(
                'Décochage dépenses ignoré pour %s — transactions historiques liées.',
                self::labelFor($tiers),
            );
        }

        if ($tiers->pour_recettes === true
            && array_key_exists('pour_recettes', $data)
            && $data['pour_recettes'] === false
            && $tiers->transactions()->where('type', TypeTransaction::Recette)->exists()
        ) {
            $data['pour_recettes'] = true;
            $warnings[] = sprintf(
                'Décochage recettes ignoré pour %s — transactions historiques liées.',
                self::labelFor($tiers),
            );
        }

        return [$data, $warnings];
    }

    private static function labelFor(Tiers $tiers): string
    {
        // displayName() is the canonical label accessor on Tiers
        $name = $tiers->displayName();

        if ($name !== '') {
            return $name;
        }

        return '#'.$tiers->id;
    }
}
