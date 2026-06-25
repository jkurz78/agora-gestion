<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use Illuminate\Support\Collection;

final class QuestionnaireEcranResolver
{
    /**
     * Découpe une collection ordonnée de questions en écrans.
     *
     * Un nouvel écran commence quand `grouper_avec_precedente === false`
     * (ou quand il n'y a pas encore d'écran courant — première question).
     *
     * @return array<int, Collection>
     */
    public function decouper(Collection $questionsOrdonnees): array
    {
        $ecrans = [];
        $ecranCourant = null;

        foreach ($questionsOrdonnees as $question) {
            if ($ecranCourant === null || $question->grouper_avec_precedente === false) {
                $ecranCourant = collect();
                $ecrans[] = $ecranCourant;
            }

            $ecranCourant->push($question);
        }

        return $ecrans;
    }
}
