<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
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

        // Derive exercice label: 1 sept N → 31 août N+1  ⇒  "N/N+1"
        $year = $operation->date_debut?->year ?? now()->year;
        $month = $operation->date_debut?->month ?? now()->month;
        $exerciceLabel = $month >= 9
            ? $year.' / '.($year + 1)
            : ($year - 1).' / '.$year;

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
        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $assoBase64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $assoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        $typeLogo = $operation->typeOperation?->logo_path;
        if ($typeLogo && Storage::disk('public')->exists($typeLogo)) {
            $typeBase64 = base64_encode(Storage::disk('public')->get($typeLogo));
            $ext = strtolower(pathinfo($typeLogo, PATHINFO_EXTENSION));
            $typeMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';

            return [$typeBase64, $typeMime, $assoBase64, $assoMime];
        }

        return [$assoBase64, $assoMime, null, 'image/png'];
    }
}
