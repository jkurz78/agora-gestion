<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class ImmobilisationPdfController extends Controller
{
    public function __invoke(Immobilisation $immobilisation): Response
    {
        $immobilisation->load(['compte', 'compteAmortissement', 'dotations', 'transaction.tiers']);

        // Association resolved from TenantContext (booted by ResolveTenant middleware)
        $association = CurrentAssociation::get();

        // Logo base64 (null-safe)
        $logoBase64 = null;
        $logoMime = 'image/png';
        $logoFullPath = $association?->brandingLogoFullPath();
        if ($logoFullPath && Storage::disk('local')->exists($logoFullPath)) {
            $logoBase64 = base64_encode(Storage::disk('local')->get($logoFullPath));
            $ext = strtolower(pathinfo($logoFullPath, PATHINFO_EXTENSION));
            $logoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $pdf = Pdf::loadView('pdf.immobilisation', [
            'immobilisation' => $immobilisation,
            'association' => $association,
            'plan' => app(PlanAmortissementCalculator::class)->plan($immobilisation),
            'exerciceService' => app(ExerciceService::class),
            'logoBase64' => $logoBase64,
            'logoMime' => $logoMime,
            'appLogoBase64' => $appLogoBase64,
            // No footer association logo: the header already shows it.
            'footerLogoBase64' => null,
            'footerLogoMime' => null,
        ])->setPaper('a4', 'portrait');

        PdfFooterRenderer::render($pdf);

        return $pdf->stream('immobilisation-'.$immobilisation->numero.'.pdf');
    }
}
