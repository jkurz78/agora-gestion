<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

/**
 * Une ligne du plan d'amortissement complet d'une fiche — celui que
 * construit PlanAmortissementCalculator::plan(), affiché tel quel par la
 * fiche (ImmobilisationShow) et par le PDF (ImmobilisationPdfController).
 *
 * Même esprit que LigneDotationPreview : un objet de valeur plutôt qu'un
 * tableau associatif nu.
 */
final class LignePlanAmortissement
{
    public function __construct(
        public readonly int $exercice,
        public readonly int $moisEcoules,
        public readonly int $dotationCentimes,
        public readonly int $cumulCentimes,
        public readonly int $valeurNetteCentimes,
        public readonly bool $comptabilisee,
        public readonly ?int $transactionId = null,
    ) {}
}
