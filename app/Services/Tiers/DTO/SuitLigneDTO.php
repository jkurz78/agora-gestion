<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Models\Participant;
use Carbon\Carbon;

final class SuitLigneDTO
{
    public function __construct(
        public readonly Participant $participant,
        public readonly string $qualite, // 'medecin' | 'therapeute' — injecté par le service
    ) {}

    public function tiersSuiviId(): int
    {
        return (int) $this->participant->tiers_id;
    }

    public function tiersSuiviNomComplet(): string
    {
        // $tiers->nom est déjà mb_strtoupper via l'accesseur Tiers::getNomAttribute
        return trim((string) $this->participant->tiers->prenom).' '.$this->participant->tiers->nom;
    }

    public function qualite(): string
    {
        return $this->qualite;
    }

    public function qualiteLabel(): string
    {
        return $this->qualite === 'medecin' ? 'Médecin' : 'Thérapeute';
    }

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

    public function estHelloasso(): bool
    {
        return (bool) $this->participant->est_helloasso;
    }

    public function operationArchivee(): bool
    {
        return $this->participant->operation->deleted_at !== null;
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

    public function dateInscription(): Carbon
    {
        return $this->participant->date_inscription;
    }
}
