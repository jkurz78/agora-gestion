<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AdhesionService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function creerDepuisTransaction(Transaction $tx): ?Adhesion
    {
        if ($tx->type !== TypeTransaction::Recette) {
            return null;
        }

        if (empty($tx->tiers_id)) {
            return null;
        }

        $aUneLigneCotisation = $tx->lignes()
            ->whereHas('sousCategorie.usages', function ($q): void {
                $q->where('usage', UsageComptable::Cotisation->value);
            })
            ->exists();

        if (! $aUneLigneCotisation) {
            return null;
        }

        $exercice = $this->exerciceFromDate($tx->date);

        return DB::transaction(function () use ($tx, $exercice): Adhesion {
            $adhesion = Adhesion::withTrashed()
                ->where('tiers_id', (int) $tx->tiers_id)
                ->where('exercice', $exercice)
                ->where('transaction_id', (int) $tx->id)
                ->first();

            if ($adhesion?->trashed()) {
                $adhesion->restore();

                return $adhesion;
            }

            if ($adhesion !== null) {
                return $adhesion; // idempotence
            }

            return Adhesion::create([
                'association_id' => TenantContext::currentId(),
                'tiers_id' => (int) $tx->tiers_id,
                'exercice' => $exercice,
                'transaction_id' => (int) $tx->id,
                'gratuite' => false,
            ]);
        });
    }

    public function creerGratuite(Tiers $tiers, int $exercice, string $motif, User $createur): Adhesion
    {
        return DB::transaction(function () use ($tiers, $exercice, $motif): Adhesion {
            $existante = Adhesion::withTrashed()
                ->where('tiers_id', (int) $tiers->id)
                ->where('exercice', $exercice)
                ->first();

            if ($existante !== null && ! $existante->trashed()) {
                throw new DomainException(
                    "Ce tiers a déjà une adhésion sur l'exercice {$existante->exercice}-".($existante->exercice + 1).'.'
                );
            }

            if ($existante !== null && $existante->trashed()) {
                $existante->restore();
                $existante->update([
                    'gratuite' => true,
                    'motif_gratuite' => $motif,
                    'transaction_id' => null,
                ]);

                return $existante;
            }

            return Adhesion::create([
                'association_id' => TenantContext::currentId(),
                'tiers_id' => (int) $tiers->id,
                'exercice' => $exercice,
                'transaction_id' => null,
                'gratuite' => true,
                'motif_gratuite' => $motif,
            ]);
        });
    }

    private function exerciceFromDate(\DateTimeInterface $date): int
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $exerciceMoisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;

        return $month >= $exerciceMoisDebut ? $year : $year - 1;
    }
}
