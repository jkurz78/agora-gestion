<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutCampagne;
use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireTemplate;
use Illuminate\Support\Facades\DB;

final class QuestionnaireCampaignService
{
    public function creerDepuisModele(Operation $operation, QuestionnaireTemplate $template): QuestionnaireCampaign
    {
        return DB::transaction(function () use ($operation, $template): QuestionnaireCampaign {
            $campagne = QuestionnaireCampaign::create([
                'operation_id' => $operation->id,
                'template_id' => $template->id,
                'titre_affiche' => $template->titre_affiche,
                'intro' => $template->intro,
                'remerciement' => $template->remerciement,
                'statut' => StatutCampagne::Brouillon,
            ]);

            foreach ($template->questions()->get() as $q) {
                $campagne->questions()->create([
                    'libelle' => $q->libelle,
                    'aide' => $q->aide,
                    'type' => $q->type,
                    'ordre' => $q->ordre,
                    'obligatoire' => $q->obligatoire,
                    'config' => $q->config, // snapshot des options + rendu
                ]);
            }

            return $campagne;
        });
    }

    public function ouvrir(QuestionnaireCampaign $campagne): void
    {
        abort_unless($campagne->statut->peutOuvrir(), 422, 'Campagne non ouvrable.');
        $campagne->update(['statut' => StatutCampagne::Ouverte, 'ouverte_at' => now()]);
    }

    public function cloturer(QuestionnaireCampaign $campagne): void
    {
        abort_unless($campagne->statut->peutCloturer(), 422, 'Campagne non clôturable.');
        $campagne->update(['statut' => StatutCampagne::Cloturee, 'cloturee_at' => now()]);
    }
}
