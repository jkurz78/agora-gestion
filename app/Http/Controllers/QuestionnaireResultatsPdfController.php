<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StatutSubmission;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireSubmission;
use App\Services\Questionnaire\QuestionnaireResultatService;
use App\Support\CurrentAssociation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

final class QuestionnaireResultatsPdfController extends Controller
{
    public function campagne(QuestionnaireCampaign $campagne, QuestionnaireResultatService $service): Response
    {
        $resultats = $service->pourCampagne($campagne);
        $contacts = $this->contacts(collect([$campagne]));
        $titre = $campagne->titre_affiche ?: $campagne->titre;
        $sousTitre = $campagne->operation->nom;

        return $this->genererPdf($resultats, $contacts, $titre, $sousTitre);
    }

    public function consolides(Request $request, QuestionnaireResultatService $service): Response
    {
        $ids = $request->input('campagneIds', []);
        abort_if(empty($ids), 404);

        $campagnes = QuestionnaireCampaign::whereIn('id', $ids)->with('operation')->get();
        abort_if($campagnes->isEmpty(), 404);

        $resultats = $service->pourCampagnes($campagnes);
        $contacts = $this->contacts($campagnes);
        $titre = $campagnes->first()->titre_affiche ?: $campagnes->first()->titre;
        $sousTitre = 'Consolidé — '.$campagnes->map(fn ($c) => $c->operation->nom)->join(', ');

        return $this->genererPdf($resultats, $contacts, $titre, $sousTitre);
    }

    private function genererPdf(array $resultats, mixed $contacts, string $titre, string $sousTitre): Response
    {
        $association = CurrentAssociation::get();

        $pdf = Pdf::loadView('questionnaire.resultats.pdf', [
            'resultats' => $resultats,
            'contacts' => $contacts,
            'titre' => $titre,
            'sousTitre' => $sousTitre,
            'association' => $association,
            'date' => now()->format('d/m/Y'),
        ])->setPaper('a4', 'portrait');

        $filename = 'resultats-questionnaire-'.now()->format('Y-m-d').'.pdf';

        return $pdf->stream($filename);
    }

    /** @param  Collection<int, QuestionnaireCampaign>  $campagnes */
    private function contacts(mixed $campagnes): Collection
    {
        return QuestionnaireSubmission::whereIn('campaign_id', $campagnes->pluck('id'))
            ->where('statut', StatutSubmission::Soumise->value)
            ->where('accepte_contact', true)
            ->with('invitation.participant.tiers')
            ->get();
    }
}
