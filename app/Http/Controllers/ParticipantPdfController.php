<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
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

        $association = Association::find(1);

        [$headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime] = $this->resolveLogos($association, $operation);

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
        ];

        $view = $format === 'annuaire' ? 'pdf.participants-annuaire' : 'pdf.participants-liste';

        $assoPrefix = $association?->nom
            ? str_replace('/', '-', Str::ascii($association->nom)).' - '
            : '';
        $filename = $assoPrefix.'Participants '.Str::ascii($operation->nom).' - '.($format === 'annuaire' ? 'Annuaire' : 'Liste').'.pdf';

        $pdf = Pdf::loadView($view, $data)->setPaper('a4', $format === 'annuaire' ? 'portrait' : 'landscape');

        return $pdf->stream($filename);
    }
}
