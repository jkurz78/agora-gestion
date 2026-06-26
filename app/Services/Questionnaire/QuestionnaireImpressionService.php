<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use App\Support\QuestionnaireQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class QuestionnaireImpressionService
{
    public function __construct(
        private readonly QuestionnaireInvitationService $invitations,
        private readonly QuestionnaireEcranResolver $resolver,
        private readonly QuestionnaireVariableResolver $variables,
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

        // 4. Construire les pages (une par invitation) avec le QR code,
        //    l'intro et le remerciement résolus par invitation.
        $introSource = (string) ($campagne->intro ?? '');
        $remerciementSource = (string) ($campagne->remerciement ?? '');

        $pages = $invitations
            ->map(function ($inv) use ($introSource, $remerciementSource): array {
                $vars = $this->variables->pour($inv);

                return [
                    'invitation' => $inv,
                    'qr' => QuestionnaireQrCode::dataUri($inv->lienReponse()),
                    'introHtml' => $this->variables->remplacer($introSource, $vars),
                    'remerciementHtml' => $this->variables->remplacer($remerciementSource, $vars),
                ];
            })
            ->all();

        // 5. Récupérer les infos de l'association courante.
        $asso = CurrentAssociation::tryGet();
        $nomAsso = $asso?->nom ?? '';
        $logoDataUri = $asso?->brandingLogoDataUri();

        return compact('campagne', 'nomAsso', 'logoDataUri', 'groupes', 'pages');
    }

    /**
     * Retourne le PDF prêt à être affiché en ligne dans le navigateur.
     *
     * Content-Disposition: inline — le navigateur l'ouvre dans l'onglet.
     *
     * @param  array<int>  $participantIds
     */
    public function afficher(QuestionnaireCampaign $campagne, array $participantIds): Response
    {
        $pdf = $this->construirePdf($campagne, $participantIds);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"questionnaire-{$campagne->id}.pdf\"",
        ]);
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
        $pdf = $this->construirePdf($campagne, $participantIds);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Construit l'objet PDF DomPDF avec le footer injecté.
     * Partagé entre afficher() et telecharger().
     *
     * @param  array<int>  $participantIds
     */
    private function construirePdf(QuestionnaireCampaign $campagne, array $participantIds): PdfInstance
    {
        $pdf = Pdf::loadView(
            'pdf.questionnaire-papier',
            $this->construireDonnees($campagne, $participantIds)
        )->setPaper('a4');

        // Pied de page : « Imprimé le … — opération — titre questionnaire » (gauche) + page X/N (droite).
        $leftText = implode(' — ', array_filter([
            'Imprimé le '.now()->format('d/m/Y'),
            $campagne->operation?->nom,
            $campagne->titre_affiche,
        ]));
        PdfFooterRenderer::renderQuestionnaire($pdf, $leftText);

        return $pdf;
    }
}
