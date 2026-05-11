<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Models\Operation;
use Carbon\Carbon;

final class EncadrementLigneDTO
{
    public function __construct(
        public readonly Operation $operation,
        private readonly int $nbSeances,
        private readonly float $montantTotal,
    ) {}

    public function operationId(): int
    {
        return (int) $this->operation->id;
    }

    public function operationNom(): string
    {
        return (string) $this->operation->nom;
    }

    public function typeOperationNom(): string
    {
        return (string) $this->operation->typeOperation->nom;
    }

    public function operationArchivee(): bool
    {
        return $this->operation->deleted_at !== null;
    }

    public function dateDebut(): ?Carbon
    {
        $seances = $this->operation->seances;
        if ($seances->isEmpty()) {
            return null;
        }

        return $seances->pluck('date')->min();
    }

    public function dateFin(): ?Carbon
    {
        $seances = $this->operation->seances;
        if ($seances->isEmpty()) {
            return null;
        }

        return $seances->pluck('date')->max();
    }

    public function nbSeances(): int
    {
        return $this->nbSeances;
    }

    public function montantTotal(): float
    {
        return $this->montantTotal;
    }
}
