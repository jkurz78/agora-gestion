<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;

final class AdhesionLigneDTO
{
    public function __construct(
        public readonly Adhesion $adhesion,
    ) {}

    public function libelleExercice(): string
    {
        if ($this->adhesion->exercice !== null) {
            return 'Ex. '.$this->adhesion->exercice.'-'.($this->adhesion->exercice + 1);
        }

        return '—'; // mode durée → la colonne Formule/Validité affichera l'intervalle
    }

    public function libelleType(): string
    {
        return $this->adhesion->estGratuite() ? 'Offerte' : 'Cotisation';
    }

    public function recuFiscalActif(): ?RecuFiscalEmis
    {
        if ($this->adhesion->transaction === null) {
            return null;
        }

        foreach ($this->adhesion->transaction->lignes as $ligne) {
            if ($ligne->recuFiscalActif !== null) {
                return $ligne->recuFiscalActif;
            }
        }

        return null;
    }

    public function peutEmettreRecu(Association $asso): bool
    {
        // Garde montant > 0 : un palier HelloAsso "offert" ou une cotisation manuelle
        // à 0€ ne peut jamais donner droit à un reçu fiscal (pas de versement).
        $montantFacial = (float) ($this->adhesion->montant_facial ?? 0);

        return $asso->eligible_recu_fiscal
            && $this->adhesion->transaction_id !== null
            && $this->adhesion->deductible_fiscal
            && $montantFacial > 0
            && $this->recuFiscalActif() === null;
    }
}
