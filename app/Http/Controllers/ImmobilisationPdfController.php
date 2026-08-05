<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
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
            'plan' => $this->plan($immobilisation),
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

    /**
     * Même construction que ImmobilisationShow — le PDF est un rendu figé de la
     * fiche, il ne doit pas diverger de l'écran.
     *
     * @return list<array{exercice: int, moisEcoules: int, dotationCentimes: int, cumulCentimes: int, valeurNetteCentimes: int, comptabilisee: bool}>
     */
    private function plan(Immobilisation $immobilisation): array
    {
        $calculator = app(PlanAmortissementCalculator::class);
        $exerciceService = app(ExerciceService::class);

        $exercice = $exerciceService->anneeForDate(
            CarbonImmutable::parse($immobilisation->date_mise_en_service->toDateString())
        );

        $montantCentimes = $immobilisation->montantAcquisitionCentimes();
        $plan = [];
        $cumul = 0;
        $borne = intdiv((int) $immobilisation->duree_mois, 12) + 2;

        for ($i = 0; $i < $borne && $cumul < $montantCentimes; $i++) {
            $enregistree = $immobilisation->dotations->firstWhere('exercice', $exercice);
            $comptabilisee = $enregistree !== null;

            $dotation = $comptabilisee
                ? (int) round(((float) $enregistree->montant) * 100)
                : $calculator->dotationCentimes($immobilisation, $exercice, $cumul);

            $cumul += $dotation;

            $plan[] = [
                'exercice' => $exercice,
                'moisEcoules' => $calculator->moisEcoules($immobilisation, $exercice),
                'dotationCentimes' => $dotation,
                'cumulCentimes' => $cumul,
                'valeurNetteCentimes' => $montantCentimes - $cumul,
                'comptabilisee' => $comptabilisee,
            ];

            $exercice++;
        }

        return $plan;
    }
}
