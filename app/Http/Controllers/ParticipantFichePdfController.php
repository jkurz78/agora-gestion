<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ParticipantFichePdfController extends Controller
{
    public function __invoke(Request $request, Operation $operation, Participant $participant): Response
    {
        $operation->loadMissing('typeOperation');
        $typeOp = $operation->typeOperation;

        $canSeeSensible = $request->user()->peut_voir_donnees_sensibles ?? false;
        $showParcours = $canSeeSensible && ($typeOp?->formulaire_parcours_therapeutique ?? false);
        $showPrescripteur = $canSeeSensible && ($typeOp?->formulaire_prescripteur ?? false);
        $showDroitImage = $canSeeSensible && ($typeOp?->formulaire_droit_image ?? false);

        $participant->load(['tiers', 'referePar', 'medecinTiers', 'therapeuteTiers', 'typeOperationTarif', 'formulaireToken']);
        if ($showParcours) {
            $participant->load('donneesMedicales');
        }

        $association = Association::find(1);

        [$headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime] = $this->resolveLogos($association, $operation);

        $documents = [];
        if ($showParcours) {
            $dir = "participants/{$participant->id}";
            if (Storage::disk('local')->exists($dir)) {
                $documents = collect(Storage::disk('local')->files($dir))
                    ->map(fn (string $path) => basename($path))
                    ->values()
                    ->all();
            }
        }

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $pdf = Pdf::loadView('pdf.participant-fiche', [
            'participant' => $participant,
            'operation' => $operation,
            'typeOperation' => $typeOp,
            'association' => $association,
            'showParcours' => $showParcours,
            'showPrescripteur' => $showPrescripteur,
            'showDroitImage' => $showDroitImage,
            'documents' => $documents,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
            'appLogoBase64' => $appLogoBase64,
        ])->setPaper('a4', 'portrait');

        PdfFooterRenderer::render($pdf);

        $nom = $participant->tiers->nom ?? 'participant';
        $prenom = $participant->tiers->prenom ?? '';
        $filename = 'Fiche-'.Str::ascii($prenom.'-'.$nom).'.pdf';

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
