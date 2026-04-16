<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Participant;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ParticipantPdfController extends Controller
{
    use Concerns\ResolvesLogos;

    public function __invoke(Request $request, Operation $operation): Response
    {
        $format = $request->query('format', 'liste'); // 'liste' or 'annuaire'

        $operation->loadMissing('typeOperation');

        $typeOp = $operation->typeOperation;
        $canSee = $request->boolean('confidentiel')
            && ($request->user()->peut_voir_donnees_sensibles ?? false);

        $confidentiel = $canSee && ($typeOp?->formulaire_parcours_therapeutique ?? false);
        $showPrescripteur = $canSee && ($typeOp?->formulaire_prescripteur ?? false);
        $showDroitImage = $canSee && ($typeOp?->formulaire_droit_image ?? false);

        $participants = Participant::where('operation_id', $operation->id)
            ->with(['tiers', 'referePar', 'medecinTiers', 'therapeuteTiers', 'typeOperationTarif'])
            ->when($confidentiel, fn ($q) => $q->with('donneesMedicales'))
            ->orderBy('id')
            ->get();

        $association = CurrentAssociation::get();

        [$headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime] = $this->resolveLogos($association, $operation);

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $data = [
            'operation' => $operation,
            'participants' => $participants,
            'confidentiel' => $confidentiel,
            'showPrescripteur' => $showPrescripteur,
            'showDroitImage' => $showDroitImage,
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
            'appLogoBase64' => $appLogoBase64,
        ];

        $view = $format === 'annuaire' ? 'pdf.participants-annuaire' : 'pdf.participants-liste';

        $assoPrefix = $association?->nom
            ? str_replace('/', '-', Str::ascii($association->nom)).' - '
            : '';
        $filename = $assoPrefix.'Participants '.Str::ascii($operation->nom).' - '.($format === 'annuaire' ? 'Annuaire' : 'Liste').'.pdf';

        $pdf = Pdf::loadView($view, $data)->setPaper('a4', $format === 'annuaire' ? 'portrait' : 'landscape');

        PdfFooterRenderer::render($pdf);

        return $pdf->stream($filename);
    }
}
