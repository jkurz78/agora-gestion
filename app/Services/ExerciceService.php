<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutExercice;
use App\Enums\TypeActionExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ExerciceService
{
    private function moisDebut(): int
    {
        return TenantContext::current()?->exercice_mois_debut ?? 9;
    }

    /**
     * Return the current exercice year.
     * Financial year start month is read from the current tenant's exercice_mois_debut.
     * Falls back to month 9 (September) when no tenant context is booted.
     */
    public function current(): int
    {
        if (session()->has('exercice_actif')) {
            return (int) session('exercice_actif');
        }

        $now = CarbonImmutable::now();
        $moisDebut = $this->moisDebut();

        return $now->month >= $moisDebut ? $now->year : $now->year - 1;
    }

    /**
     * Return the start and end dates for a given exercice.
     * Dates are computed from the current tenant's exercice_mois_debut.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function dateRange(int $exercice): array
    {
        $moisDebut = $this->moisDebut();
        $start = CarbonImmutable::create($exercice, $moisDebut, 1)->startOfDay();

        if ($moisDebut === 1) {
            // Calendrier : exercice jan–déc de la même année
            $end = CarbonImmutable::create($exercice, 12, 31)->startOfDay();
        } else {
            // Décalé : fin le dernier jour du mois précédant moisDebut, année suivante
            $endMonth = $moisDebut - 1;
            $end = CarbonImmutable::create($exercice + 1, $endMonth, 1)->endOfMonth()->startOfDay();
        }

        return compact('start', 'end');
    }

    /**
     * Return a display label for the given exercice.
     * Returns e.g. "2026" for a calendar exercice, "2025-2026" for a shifted one.
     */
    public function label(int $exercice): string
    {
        return $this->moisDebut() === 1
            ? (string) $exercice
            : $exercice.'-'.($exercice + 1);
    }

    /**
     * Return the best default date for a new entry in the active exercice.
     * Returns today if in range, dateFin if past, dateDebut if future.
     */
    public function defaultDate(): string
    {
        $range = $this->dateRange($this->current());
        $today = CarbonImmutable::today();

        if ($today->lt($range['start'])) {
            return $range['start']->toDateString();
        }

        if ($today->gt($range['end'])) {
            return $range['end']->toDateString();
        }

        return $today->toDateString();
    }

    /**
     * Return the Exercice model for the currently displayed exercice.
     */
    public function exerciceAffiche(): ?Exercice
    {
        return Exercice::where('annee', $this->current())->first();
    }

    /**
     * Calculate which exercice a given date belongs to.
     * Month >= moisDebut → that year, otherwise → previous year.
     */
    public function anneeForDate(CarbonImmutable|Carbon $date): int
    {
        $moisDebut = $this->moisDebut();

        return $date->month >= $moisDebut ? $date->year : $date->year - 1;
    }

    /**
     * Assert that the exercice for the given year is open.
     * Throws ExerciceCloturedException if closed.
     * Does nothing if the exercice does not exist in database (graceful for fresh installs).
     */
    public function assertOuvert(int $annee): void
    {
        $exercice = Exercice::where('annee', $annee)->first();

        if ($exercice !== null && $exercice->isCloture()) {
            throw new ExerciceCloturedException($annee);
        }
    }

    /**
     * Close an exercice: update status, record action.
     */
    public function cloturer(Exercice $exercice, User $user): void
    {
        DB::transaction(function () use ($exercice, $user): void {
            $exercice->update([
                'statut' => StatutExercice::Cloture,
                'date_cloture' => now(),
                'cloture_par_id' => $user->id,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Cloture,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Reopen a closed exercice with a mandatory comment.
     */
    public function reouvrir(Exercice $exercice, User $user, string $commentaire): void
    {
        DB::transaction(function () use ($exercice, $user, $commentaire): void {
            $exercice->update([
                'statut' => StatutExercice::Ouvert,
                'date_cloture' => null,
                'cloture_par_id' => null,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Reouverture,
                'user_id' => $user->id,
                'commentaire' => $commentaire,
            ]);
        });
    }

    /**
     * Create a new exercice year.
     * association_id is auto-filled by TenantModel's creating observer from TenantContext.
     */
    public function creerExercice(int $annee, User $user): Exercice
    {
        return DB::transaction(function () use ($annee, $user): Exercice {
            $exercice = Exercice::create([
                'annee' => $annee,
                'statut' => StatutExercice::Ouvert,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::Creation,
                'user_id' => $user->id,
            ]);

            return $exercice;
        });
    }

    /**
     * Return available exercice years for dropdowns.
     * From current year + 1 down to current year - 3.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        $currentYear = (int) now()->format('Y');

        return range($currentYear + 1, $currentYear - 3);
    }

    /**
     * Switch the displayed exercice in session.
     */
    public function changerExerciceAffiche(Exercice $exercice): void
    {
        session(['exercice_actif' => $exercice->annee]);
    }
}
