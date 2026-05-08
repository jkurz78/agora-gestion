<?php

declare(strict_types=1);

namespace App\Enums;

enum RegimeFiscalDon: string
{
    case InteretGeneral = 'interet_general';
    case ReconnueUtilitePublique = 'reconnue_utilite_publique';
    case Cultuelle = 'cultuelle';
    case EnseignementSuperieurRecherche = 'enseignement_superieur_recherche';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::InteretGeneral => 'Reconnue d\'intérêt général',
            self::ReconnueUtilitePublique => 'Reconnue d\'utilité publique (RUP)',
            self::Cultuelle => 'Association cultuelle (loi 1905)',
            self::EnseignementSuperieurRecherche => 'Établissement d\'enseignement supérieur ou de recherche',
            self::Autre => 'Autre régime favorable (rescrit fiscal)',
        };
    }

    /** @return array<value-of<self>, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case): array => $carry + [$case->value => $case->label()],
            []
        );
    }
}
