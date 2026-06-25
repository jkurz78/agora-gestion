<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Support\CurrentAssociation;
use App\Support\QuestionnaireQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class QuestionnaireImpressionService
{
    public function __construct(
        private readonly QuestionnaireInvitationService $invitations,
        private readonly QuestionnaireEcranResolver $resolver,
    ) {}

    /**
     * Assemble toutes les données nécessaires au gabarit PDF papier.
     *
     * @param  array<int>  $participantIds
     * @return array{campagne: QuestionnaireCampaign, nomAsso: string, logoDataUri: string|null, groupes: array, pages: array}
     */
    public function construireDonnees(QuestionnaireCampaign $campagne, array $participantIds): array
    {
        // 1. Générer les invitations manquantes (idempotent).
        $this->invitations->genererPour($campagne, $participantIds);

        // 2. Récupérer les invitations pour les participants sélectionnés, triées par nom du tiers.
        $invitations = $campagne
            ->invitations()
            ->whereIn('participant_id', $participantIds)
            ->with('participant.tiers')
            ->join('participants', 'participants.id', '=', 'questionnaire_invitations.participant_id')
            ->join('tiers', 'tiers.id', '=', 'participants.tiers_id')
            ->orderBy('tiers.nom')
            ->orderBy('tiers.prenom')
            ->select('questionnaire_invitations.*')
            ->get();

        // 3. Découper les questions en écrans (groupes).
        $groupes = $this->resolver->decouper(
            $campagne->questions()->orderBy('ordre')->get()
        );

        // 4. Construire les pages (une par invitation) avec le QR code.
        $pages = $invitations
            ->map(fn ($inv) => [
                'invitation' => $inv,
                'qr' => QuestionnaireQrCode::dataUri($inv->lienReponse()),
            ])
            ->all();

        // 5. Récupérer les infos de l'association courante.
        $asso = CurrentAssociation::tryGet();
        $nomAsso = $asso?->nom ?? '';
        $logoDataUri = $asso?->brandingLogoDataUri();

        return compact('campagne', 'nomAsso', 'logoDataUri', 'groupes', 'pages');
    }

    /**
     * Génère et retourne le PDF à télécharger.
     *
     * Retourne un StreamedResponse pour que Livewire SupportFileDownloads
     * puisse l'intercepter et déclencher le téléchargement côté navigateur.
     *
     * @param  array<int>  $participantIds
     */
    public function telecharger(QuestionnaireCampaign $campagne, array $participantIds): StreamedResponse
    {
        $filename = "questionnaire-{$campagne->id}.pdf";
        $pdf = Pdf::loadView(
            'pdf.questionnaire-papier',
            $this->construireDonnees($campagne, $participantIds)
        )->setPaper('a4');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
