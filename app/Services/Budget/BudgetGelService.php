<?php

declare(strict_types=1);

namespace App\Services\Budget;

use App\Enums\TypeActionExercice;
use App\Exceptions\ExerciceCloturedException;
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
            // Même protocole que ExerciceService::cloturer() : le verrou
            // d'abord, le contrôle d'état APRÈS l'avoir acquis. Sans lui, un
            // import en cours (validation faite AVANT le gel, écriture APRÈS)
            // ou deux validations concurrentes pourraient toutes deux se
            // croire premières — soit deux écritures d'audit pour un seul
            // geste, soit un budget « revalidé » qui écrase sa date d'origine.
            $exerciceVerrouille = Exercice::query()
                ->whereKey((int) $exercice->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Défense en profondeur : les gardes de l'assistant (BudgetTable)
            // sont consultatives, ce service peut être appelé directement.
            // Refuser ici rend la garde réelle — même motif que
            // ExerciceService::cloturer(). Contrôlée sur l'état verrouillé,
            // pas sur $exercice (potentiellement périmé).
            if ($exerciceVerrouille->isCloture()) {
                throw new ExerciceCloturedException((int) $exerciceVerrouille->annee);
            }

            if ($exerciceVerrouille->budgetEstValide()) {
                return;
            }

            $exerciceVerrouille->update([
                'budget_valide_le' => now(),
                'budget_valide_par_id' => $user->id,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exerciceVerrouille->id,
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
            $exerciceVerrouille = Exercice::query()
                ->whereKey((int) $exercice->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Voir le commentaire équivalent dans valider().
            if ($exerciceVerrouille->isCloture()) {
                throw new ExerciceCloturedException((int) $exerciceVerrouille->annee);
            }

            if (! $exerciceVerrouille->budgetEstValide()) {
                return;
            }

            $exerciceVerrouille->update([
                'budget_valide_le' => null,
                'budget_valide_par_id' => null,
            ]);

            ExerciceAction::create([
                'exercice_id' => $exerciceVerrouille->id,
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
