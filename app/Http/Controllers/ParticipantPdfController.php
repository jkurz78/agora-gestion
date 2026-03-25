<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ParticipantPdfController extends Controller
{
    public function __invoke(Request $request, Operation $operation): Response
    {
        $format = $request->query('format', 'liste'); // 'liste' or 'annuaire'
        $confidentiel = $request->boolean('confidentiel')
            && ($request->user()->peut_voir_donnees_sensibles ?? false);

        $participants = Participant::where('operation_id', $operation->id)
            ->with(['tiers', 'referePar'])
            ->when($confidentiel, fn ($q) => $q->with('donneesMedicales'))
            ->orderBy('id')
            ->get();

        $association = Association::find(1);

        $logoBase64 = null;
        $logoMime = 'image/png';
        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $logoBase64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $logoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        $data = [
            'operation' => $operation,
            'participants' => $participants,
            'confidentiel' => $confidentiel,
            'association' => $association,
            'logoBase64' => $logoBase64,
            'logoMime' => $logoMime,
        ];

        $view = $format === 'annuaire' ? 'pdf.participants-annuaire' : 'pdf.participants-liste';

        $assoPrefix = $association?->nom
            ? str_replace('/', '-', Str::ascii($association->nom)).' - '
            : '';
        $filename = $assoPrefix.'Participants '.Str::ascii($operation->nom).' - '.($format === 'annuaire' ? 'Annuaire' : 'Liste').'.pdf';

        $pdf = Pdf::loadView($view, $data)->setPaper('a4', $format === 'annuaire' ? 'portrait' : 'landscape');

        return $pdf->download($filename);
    }
}
