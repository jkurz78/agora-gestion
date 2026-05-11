<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Enums\StatutPresence;
use App\Models\Participant;
use App\Models\Tiers;
use Carbon\Carbon;

final class ParticipationLigneDTO
{
    public function __construct(
        public readonly Participant $participant,
    ) {}

    public function operationId(): int
    {
        return (int) $this->participant->operation_id;
    }

    public function operationNom(): string
    {
        return (string) $this->participant->operation->nom;
    }

    public function typeOperationNom(): string
    {
        return (string) $this->participant->operation->typeOperation->nom;
    }

    public function dateInscription(): Carbon
    {
        return $this->participant->date_inscription;
    }

    public function estHelloasso(): bool
    {
        return (bool) $this->participant->est_helloasso;
    }

    public function operationArchivee(): bool
    {
        return $this->participant->operation->deleted_at !== null;
    }

    public function refereParTiers(): ?Tiers
    {
        return $this->participant->referePar;
    }

    public function refereParNomComplet(): ?string
    {
        $ref = $this->refereParTiers();
        if ($ref === null) {
            return null;
        }

        // $ref->nom est déjà mb_strtoupper via l'accesseur Tiers::getNomAttribute
        return trim((string) $ref->prenom).' '.$ref->nom;
    }

    public function tarifLibelle(): ?string
    {
        return $this->participant->typeOperationTarif?->libelle;
    }

    public function tarifMontant(): float
    {
        return (float) ($this->participant->typeOperationTarif?->montant ?? 0.0);
    }

    public function seancesTotal(): ?int
    {
        $count = $this->participant->operation->seances->count();

        return $count === 0 ? null : $count;
    }

    public function seancesSuivies(): int
    {
        return $this->participant->presences
            ->filter(fn ($p) => $p->statut === StatutPresence::Present->value)
            ->count();
    }

    public function montantPrevu(): float
    {
        return (float) $this->participant->reglements->sum('montant_prevu');
    }

    public function montantPaye(): float
    {
        return (float) $this->participant->reglements
            ->filter(fn ($r) => $r->transaction?->statut_reglement?->isEncaisse() === true)
            ->sum('montant_prevu');
    }

    public function statut(): string
    {
        $w = $this->montantPrevu();
        $z = $this->montantPaye();

        if ($w === 0.0) {
            return 'gratuit';
        }

        if ($z === 0.0) {
            return 'non_paye';
        }

        if ($z < $w) {
            return 'partiel';
        }

        return 'solde';
    }

    public function dateDebut(): ?Carbon
    {
        $seances = $this->participant->operation->seances;
        if ($seances->isEmpty()) {
            return null;
        }

        return $seances->pluck('date')->min();
    }

    public function dateFin(): ?Carbon
    {
        $seances = $this->participant->operation->seances;
        if ($seances->isEmpty()) {
            return null;
        }

        return $seances->pluck('date')->max();
    }
}
