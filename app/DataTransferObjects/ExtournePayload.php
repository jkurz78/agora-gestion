<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\ModePaiement;
use App\Models\Transaction;
use Carbon\Carbon;

final class ExtournePayload
{
    public function __construct(
        public readonly Carbon $date,
        public readonly string $libelle,
        public readonly ModePaiement $modePaiement,
        public readonly ?string $notes = null,
    ) {}

    /**
     * Build a payload from the origin Transaction with optional overrides.
     *
     * @param  array{date?: Carbon|string, libelle?: string, mode_paiement?: ModePaiement|string, notes?: ?string}  $overrides
     */
    public static function fromOrigine(Transaction $origine, array $overrides = []): self
    {
        $date = $overrides['date'] ?? Carbon::now();
        if (! $date instanceof Carbon) {
            $date = Carbon::parse($date);
        }

        $libelle = $overrides['libelle'] ?? 'Annulation - '.$origine->libelle;

        $modePaiement = $overrides['mode_paiement'] ?? $origine->mode_paiement;
        if (! $modePaiement instanceof ModePaiement) {
            $modePaiement = ModePaiement::from((string) $modePaiement);
        }

        $notes = $overrides['notes'] ?? null;

        return new self(
            date: $date,
            libelle: $libelle,
            modePaiement: $modePaiement,
            notes: $notes,
        );
    }
}
