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
        /**
         * Vrai quand la date n'a pas été choisie : c'est celle du jour, que l'appelant impose parce que
         * personne ne la saisit (annulation d'une facture). Un refus qui tient à la date ne doit alors pas
         * proposer de « dater l'annulation » autrement (GelRattachement::verrouillerPieceExtournable()).
         * Faux pour toute date donnée, même égale à celle du jour : la fenêtre d'annulation la pré-remplit
         * et l'utilisateur peut la changer.
         */
        public readonly bool $dateImposee = false,
    ) {}

    /**
     * Build a payload from the origin Transaction with optional overrides.
     *
     * @param  array{date?: Carbon|string, libelle?: string, mode_paiement?: ModePaiement|string, notes?: ?string}  $overrides
     */
    public static function fromOrigine(Transaction $origine, array $overrides = []): self
    {
        // Sans date donnée, c'est « aujourd'hui », imposé par l'appelant : voir $dateImposee.
        $dateImposee = ($overrides['date'] ?? null) === null;
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
            dateImposee: $dateImposee,
        );
    }
}
