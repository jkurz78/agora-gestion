<?php

declare(strict_types=1);

namespace App\Enums;

enum TypeQuestion: string
{
    case TexteCourt = 'texte_court';
    case TexteLong = 'texte_long';
    case Satisfaction = 'satisfaction';
    case SatisfactionTexteLong = 'satisfaction_texte_long';
    case Ressenti = 'ressenti';
    case CaseACocher = 'case_a_cocher';
    case ChoixUnique = 'choix_unique';
    case Information = 'information';

    public function label(): string
    {
        return match ($this) {
            self::TexteCourt => 'Texte court',
            self::TexteLong => 'Texte long',
            self::Satisfaction => 'Satisfaction (5 niveaux)',
            self::SatisfactionTexteLong => 'Satisfaction + texte long',
            self::Ressenti => 'Ressenti (curseur 0-100)',
            self::CaseACocher => 'Case à cocher (oui/non)',
            self::ChoixUnique => 'Choix unique',
            self::Information => 'Information / intertitre',
        };
    }

    /** Colonne de questionnaire_answers où la valeur est stockée (D8). */
    public function valueColumn(): string
    {
        if ($this === self::Information) {
            throw new \LogicException('TypeQuestion::Information n\'a pas de colonne de réponse');
        }

        return match ($this) {
            self::TexteCourt, self::TexteLong => 'value_text',
            self::Satisfaction, self::SatisfactionTexteLong, self::Ressenti => 'value_integer',
            self::CaseACocher => 'value_boolean',
            self::ChoixUnique => 'value_option',
        };
    }

    public function aDesOptions(): bool
    {
        return $this === self::ChoixUnique;
    }

    /** Vrai pour tous les types qui stockent une réponse (faux pour Information). */
    public function estReponse(): bool
    {
        return $this !== self::Information;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function pourSelect(): array
    {
        return array_map(
            fn (self $t): array => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }
}
