<?php

declare(strict_types=1);

namespace App\Services\Budget;

use App\Enums\TypeActionExercice;
use App\Models\BudgetLine;
use App\Models\Exercice;
use App\Models\ExerciceAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gel du budget voté.
 *
 * Le gel ne verrouille QUE les enveloppes (`operation_id IS NULL`). La ventilation
 * par opération reste modifiable toute l'année : ce que l'AG a voté ne bouge plus,
 * ce qui se décide en cours d'année reste ouvert.
 *
 * Le gel et le dégel atterrissent dans `exercice_actions`, le journal qui trace
 * déjà création, clôture et réouverture — aucun mécanisme d'audit nouveau.
 */
final class BudgetGelService
{
    public function valider(Exercice $exercice, User $user): void
    {
        DB::transaction(function () use ($exercice, $user): void {
            $exercice->update([
                'budget_valide_le' => now(),
                'budget_valide_par_id' => $user->id,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::BudgetValide,
                'user_id' => $user->id,
            ]);
        });
    }

    public function deverrouiller(Exercice $exercice, User $user, string $commentaire): void
    {
        $commentaire = trim($commentaire);

        if ($commentaire === '') {
            throw new InvalidArgumentException('Le déverrouillage du budget exige un commentaire.');
        }

        DB::transaction(function () use ($exercice, $user, $commentaire): void {
            $exercice->update([
                'budget_valide_le' => null,
                'budget_valide_par_id' => null,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exercice->id,
                'action' => TypeActionExercice::BudgetDeverrouille,
                'user_id' => $user->id,
                'commentaire' => $commentaire,
            ]);
        });
    }

    /** Le budget de l'exercice affiché est-il figé ? */
    public function estValide(int $annee): bool
    {
        return Exercice::query()
            ->where('annee', $annee)
            ->first()
            ?->budgetEstValide() ?? false;
    }

    /**
     * Une ligne est-elle verrouillée par le gel ?
     *
     * Seules les enveloppes le sont. Une ventilation reste libre même budget figé.
     */
    public function ligneEstVerrouillee(BudgetLine $ligne): bool
    {
        if ($ligne->operation_id !== null) {
            return false;
        }

        return $this->estValide((int) $ligne->exercice);
    }
}
