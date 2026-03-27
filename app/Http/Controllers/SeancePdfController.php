<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class SeancePdfController extends Controller
{
    public function emargement(Request $request, Operation $operation, Seance $seance): Response
    {
        abort_unless((int) $seance->operation_id === $operation->id, 404);

        $operation->loadMissing('typeOperation');
        $isConfidentiel = $operation->typeOperation?->confidentiel ?? false;

        $participants = Participant::where('operation_id', $operation->id)
            ->with('tiers')
            ->orderBy('id')
            ->get();

        [$association, $headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime] = $this->getAssociationData($operation);

        $filename = Str::ascii($operation->nom).' - Emargement S'.$seance->numero.'.pdf';

        $pdf = Pdf::loadView('pdf.seance-emargement', [
            'operation' => $operation,
            'seance' => $seance,
            'participants' => $participants,
            'association' => $association,
            'isConfidentiel' => $isConfidentiel,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream($filename);
    }

    public function matrice(Request $request, Operation $operation): Response
    {
        $operation->loadMissing('typeOperation');
        $isConfidentiel = $operation->typeOperation?->confidentiel ?? false;

        $seances = Seance::where('operation_id', $operation->id)
            ->orderBy('numero')
            ->get();

        $participants = Participant::where('operation_id', $operation->id)
            ->with('tiers')
            ->orderBy('id')
            ->get();

        $seanceIds = $seances->pluck('id');
        $presences = Presence::whereIn('seance_id', $seanceIds)->get();
        $presenceMap = [];
        foreach ($presences as $p) {
            $presenceMap[$p->seance_id.'-'.$p->participant_id] = $p;
        }

        [$association, $headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime] = $this->getAssociationData($operation);

        $filename = Str::ascii($operation->nom).' - Matrice presences.pdf';

        $pdf = Pdf::loadView('pdf.seances-matrice', [
            'operation' => $operation,
            'seances' => $seances,
            'participants' => $participants,
            'presenceMap' => $presenceMap,
            'association' => $association,
            'isConfidentiel' => $isConfidentiel,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream($filename);
    }

    /**
     * Resolve association + header/footer logos.
     *
     * @return array{0: ?Association, 1: ?string, 2: string, 3: ?string, 4: string}
     */
    private function getAssociationData(Operation $operation): array
    {
        $association = Association::find(1);
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

            return [$association, $typeBase64, $typeMime, $assoBase64, $assoMime];
        }

        return [$association, $assoBase64, $assoMime, null, 'image/png'];
    }
}
