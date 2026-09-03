<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Services\ExerciceService;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class DroitImagePdfController extends Controller
{
    public function __invoke(Request $request, Operation $operation, Participant $participant): Response
    {
        $operation->loadMissing('typeOperation');
        $typeOp = $operation->typeOperation;

        // Only available if droit_image flag is active and participant has made a choice
        if (! $typeOp?->formulaire_droit_image || $participant->droit_image === null) {
            abort(404);
        }

        $participant->load(['tiers', 'formulaireToken']);

        $association = CurrentAssociation::get();

        [$headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime] = $this->resolveLogos($association, $operation);

        $qualificatif = $typeOp->formulaire_qualificatif_atelier ?? 'thérapeutique';

        // Libellé d'exercice de la date de l'opération. Le mois de début est un
        // réglage de l'association : septembre était codé en dur ici, ce qui
        // donnait un libellé faux pour une association en exercice civil.
        // ExerciceService::label() rend « 2025-2026 » en exercice décalé et
        // « 2026 » tout court en exercice civil ; le séparateur espacé est la
        // présentation propre à ce PDF.
        $exerciceService = app(ExerciceService::class);
        $exerciceLabel = str_replace(
            '-',
            ' / ',
            $exerciceService->label($exerciceService->anneeForDate($operation->date_debut ?? now())),
        );

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $data = [
            'participant' => $participant,
            'operation' => $operation,
            'typeOperation' => $typeOp,
            'qualificatif' => $qualificatif,
            'qualificatifPluriel' => $qualificatif.'s',
            'exerciceLabel' => $exerciceLabel,
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
            'appLogoBase64' => $appLogoBase64,
        ];

        $nom = $participant->tiers?->nom ?? 'participant';
        $filename = 'autorisation-image-'.$nom.'.pdf';

        $pdf = Pdf::loadView('pdf.participant-droit-image', $data)->setPaper('a4', 'portrait');

        PdfFooterRenderer::render($pdf);

        return $pdf->stream($filename);
    }

    /**
     * Resolve header and footer logos.
     * Header: type logo if defined, else association logo.
     * Footer: association logo only when header uses the type logo.
     *
     * @return array{0: ?string, 1: string, 2: ?string, 3: string}
     */
    private function resolveLogos(?Association $association, Operation $operation): array
    {
        $assoBase64 = null;
        $assoMime = 'image/png';
        $logoFullPath = $association?->brandingLogoFullPath();
        if ($logoFullPath && Storage::disk('local')->exists($logoFullPath)) {
            $assoBase64 = base64_encode(Storage::disk('local')->get($logoFullPath));
            $ext = strtolower(pathinfo($logoFullPath, PATHINFO_EXTENSION));
            $assoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        $typeFullPath = $operation->typeOperation?->typeOpLogoFullPath();
        if ($typeFullPath && Storage::disk('local')->exists($typeFullPath)) {
            $typeBase64 = base64_encode(Storage::disk('local')->get($typeFullPath));
            $ext = strtolower(pathinfo($typeFullPath, PATHINFO_EXTENSION));
            $typeMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';

            return [$typeBase64, $typeMime, $assoBase64, $assoMime];
        }

        return [$assoBase64, $assoMime, null, 'image/png'];
    }
}
