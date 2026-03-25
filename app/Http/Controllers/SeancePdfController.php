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
        abort_unless($seance->operation_id === $operation->id, 404);

        $participants = Participant::where('operation_id', $operation->id)
            ->with('tiers')
            ->orderBy('id')
            ->get();

        [$association, $logoBase64, $logoMime] = $this->getAssociationData();

        $filename = Str::ascii($operation->nom).' - Emargement S'.$seance->numero.'.pdf';

        $pdf = Pdf::loadView('pdf.seance-emargement', [
            'operation' => $operation,
            'seance' => $seance,
            'participants' => $participants,
            'association' => $association,
            'logoBase64' => $logoBase64,
            'logoMime' => $logoMime,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream($filename);
    }

    public function matrice(Request $request, Operation $operation): Response
    {
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

        [$association, $logoBase64, $logoMime] = $this->getAssociationData();

        $filename = Str::ascii($operation->nom).' - Matrice presences.pdf';

        $pdf = Pdf::loadView('pdf.seances-matrice', [
            'operation' => $operation,
            'seances' => $seances,
            'participants' => $participants,
            'presenceMap' => $presenceMap,
            'association' => $association,
            'logoBase64' => $logoBase64,
            'logoMime' => $logoMime,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream($filename);
    }

    /**
     * @return array{0: ?Association, 1: ?string, 2: string}
     */
    private function getAssociationData(): array
    {
        $association = Association::find(1);
        $logoBase64 = null;
        $logoMime = 'image/png';

        if ($association?->logo_path && Storage::disk('public')->exists($association->logo_path)) {
            $logoBase64 = base64_encode(Storage::disk('public')->get($association->logo_path));
            $ext = strtolower(pathinfo($association->logo_path, PATHINFO_EXTENSION));
            $logoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        return [$association, $logoBase64, $logoMime];
    }
}
