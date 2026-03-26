<?php

declare(strict_types=1);

namespace App\Enums;

enum ModePaiement: string
{
    case Virement = 'virement';
    case Cheque = 'cheque';
    case Especes = 'especes';
    case Cb = 'cb';
    case Prelevement = 'prelevement';

    public function label(): string
    {
        return match ($this) {
            self::Virement => 'Virement',
            self::Cheque => 'Chèque',
            self::Especes => 'Espèces',
            self::Cb => 'Carte bancaire',
            self::Prelevement => 'Prélèvement',
        };
    }

    public function trigramme(): string
    {
        return match ($this) {
            self::Virement => 'VMT',
            self::Cheque => 'CHQ',
            self::Especes => 'ESP',
            self::Cb => 'CB',
            self::Prelevement => 'PRL',
        };
    }

    /** @return list<self> */
    public static function reglementCases(): array
    {
        return [self::Cheque, self::Virement, self::Especes];
    }

    public static function nextReglementMode(?self $current): ?self
    {
        $cycle = self::reglementCases();

        if ($current === null) {
            return $cycle[0];
        }

        $index = array_search($current, $cycle, true);

        if ($index === false || $index === count($cycle) - 1) {
            return null;
        }

        return $cycle[$index + 1];
    }
}
