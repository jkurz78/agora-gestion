<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use App\Support\QuestionnaireQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
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
        $content = $this->construirePdfFusionne($campagne, $participantIds);

        return response($content, 200, [
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
        $content = $this->construirePdfFusionne($campagne, $participantIds);

        return response()->streamDownload(
            fn () => print ($content),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Rend un PDF par invitation (avec nom du répondant en pied de page
     * et pagination propre à chaque répondant), puis fusionne via FPDI.
     *
     * Si une invitation produit un nombre impair de pages, une page blanche
     * est ajoutée pour que l'impression recto-verso ne mélange pas les
     * formulaires de deux répondants différents.
     *
     * @param  array<int>  $participantIds
     */
    private function construirePdfFusionne(QuestionnaireCampaign $campagne, array $participantIds): string
    {
        $donnees = $this->construireDonnees($campagne, $participantIds);

        $leftText = $campagne->operation?->nom ?? '';

        $merger = new Fpdi();

        foreach ($donnees['pages'] as $page) {
            $nomParticipant = $page['invitation']->participant?->tiers?->displayName() ?? '';

            $singlePdf = Pdf::loadView('pdf.questionnaire-papier', [
                'campagne' => $donnees['campagne'],
                'nomAsso' => $donnees['nomAsso'],
                'logoDataUri' => $donnees['logoDataUri'],
                'groupes' => $donnees['groupes'],
                'pages' => [$page],
            ])->setPaper('a4');

            PdfFooterRenderer::renderQuestionnaire($singlePdf, $leftText, $nomParticipant);

            $pdfContent = $singlePdf->output();

            $pageCount = $merger->setSourceFile(StreamReader::createByString($pdfContent));
            for ($p = 1; $p <= $pageCount; $p++) {
                $tpl = $merger->importPage($p);
                $size = $merger->getTemplateSize($tpl);
                $merger->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $merger->useTemplate($tpl);
            }

            if ($pageCount % 2 !== 0) {
                $merger->AddPage('P', [210, 297]);
            }
        }

        return $merger->Output('S');
    }
}
