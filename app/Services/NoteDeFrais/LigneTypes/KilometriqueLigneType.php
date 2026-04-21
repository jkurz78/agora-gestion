<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;
use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class KilometriqueLigneType implements LigneTypeInterface
{
    public function key(): NoteDeFraisLigneType
    {
        return NoteDeFraisLigneType::Kilometrique;
    }

    public function validate(array $draft): void
    {
        $normalized = [
            'cv_fiscaux' => $draft['cv_fiscaux'] ?? null,
            'distance_km' => $this->toFloat($draft['distance_km'] ?? null),
            'bareme_eur_km' => $this->toFloat($draft['bareme_eur_km'] ?? null),
        ];

        $validator = Validator::make(
            $normalized,
            [
                'cv_fiscaux' => ['required', 'integer', 'between:1,50'],
                'distance_km' => ['required', 'numeric', 'gt:0'],
                'bareme_eur_km' => ['required', 'numeric', 'gt:0'],
            ],
            [
                'cv_fiscaux.required' => 'La puissance fiscale est obligatoire.',
                'cv_fiscaux.integer' => 'La puissance fiscale doit être un entier.',
                'cv_fiscaux.between' => 'La puissance fiscale doit être comprise entre 1 et 50 CV.',
                'distance_km.required' => 'La distance est obligatoire.',
                'distance_km.numeric' => 'La distance doit être un nombre.',
                'distance_km.gt' => 'La distance doit être supérieure à zéro.',
                'bareme_eur_km.required' => 'Le barème est obligatoire.',
                'bareme_eur_km.numeric' => 'Le barème doit être un nombre.',
                'bareme_eur_km.gt' => 'Le barème doit être supérieur à zéro.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    public function computeMontant(array $draft): float
    {
        $distance = $this->toFloat($draft['distance_km'] ?? 0);
        $bareme = $this->toFloat($draft['bareme_eur_km'] ?? 0);

        return round($distance * $bareme, 2, PHP_ROUND_HALF_UP);
    }

    public function metadata(array $draft): array
    {
        return [
            'cv_fiscaux' => (int) ($draft['cv_fiscaux'] ?? 0),
            'distance_km' => $this->toFloat($draft['distance_km'] ?? 0),
            'bareme_eur_km' => $this->toFloat($draft['bareme_eur_km'] ?? 0),
        ];
    }

    public function renderDescription(array $metadata): string
    {
        if ($metadata === []) {
            return '';
        }

        $km = $this->formatNumber((float) ($metadata['distance_km'] ?? 0));
        $cv = (int) ($metadata['cv_fiscaux'] ?? 0);
        $bareme = $this->formatNumber((float) ($metadata['bareme_eur_km'] ?? 0), 3);

        return "Déplacement de {$km} km avec un véhicule {$cv} CV au barème de {$bareme} €/km";
    }

    public function resolveSousCategorieId(?int $requestedId): ?int
    {
        $flagged = SousCategorie::forUsage(UsageComptable::FraisKilometriques)->pluck('id');

        if ($flagged->count() === 1) {
            return (int) $flagged->first();
        }

        return null;
    }

    private function toFloat(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    private function formatNumber(float $value, int $maxDecimals = 2): string
    {
        $formatted = rtrim(rtrim(number_format($value, $maxDecimals, ',', ''), '0'), ',');

        return $formatted === '' ? '0' : $formatted;
    }
}
