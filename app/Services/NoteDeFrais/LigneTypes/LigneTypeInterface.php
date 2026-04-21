<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais\LigneTypes;

use App\Enums\NoteDeFraisLigneType;

interface LigneTypeInterface
{
    public function key(): NoteDeFraisLigneType;

    /**
     * Lance ValidationException si le draft est invalide pour ce type.
     *
     * @param  array<string,mixed>  $draft
     */
    public function validate(array $draft): void;

    /**
     * Calcule le montant server-side à stocker (jamais pris en confiance du client pour les types calculés).
     *
     * @param  array<string,mixed>  $draft
     */
    public function computeMontant(array $draft): float;

    /**
     * Payload JSON stocké sur la ligne (metadata).
     *
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function metadata(array $draft): array;

    /**
     * Description humaine utilisée côté back-office (transaction_lignes.notes).
     *
     * @param  array<string,mixed>  $metadata
     */
    public function renderDescription(array $metadata): string;

    /**
     * Résolution de la sous-catégorie. Peut forcer une valeur côté stratégie (km) ou conserver celle saisie (standard).
     */
    public function resolveSousCategorieId(?int $requestedId): ?int;
}
