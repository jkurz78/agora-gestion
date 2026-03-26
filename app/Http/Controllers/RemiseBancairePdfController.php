<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\RemiseBancaire;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RemiseBancairePdfController extends Controller
{
    public function __invoke(RemiseBancaire $remise): Response
    {
        $remise->load(['compteCible', 'reglements.participant.tiers', 'reglements.seance.operation']);

        // Association (may be null)
        $association = Association::find(1);

        // Logo base64 (null-safe)
        $logoBase64 = null;
        $logoMime = 'image/png';
        if ($association !== null && $association->logo_path !== null) {
            $path = $association->logo_path;
            if (Storage::disk('public')->exists($path)) {
                $logoBase64 = base64_encode(Storage::disk('public')->get($path));
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $logoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
            }
        }

        $typeLabel = $remise->mode_paiement->value === 'cheque' ? 'chèques' : 'espèces';
        $montantTotal = $remise->montantTotal();

        $data = [
            'remise' => $remise,
            'compteCible' => $remise->compteCible,
            'reglements' => $remise->reglements,
            'typeLabel' => $typeLabel,
            'montantTotal' => $montantTotal,
            'association' => $association,
            'logoBase64' => $logoBase64,
            'logoMime' => $logoMime,
        ];

        $dateFormatted = $remise->date->format('Y-m-d');
        $prefix = $association?->nom
            ? str_replace('/', '-', Str::ascii($association->nom)).' - '
            : '';
        $filename = $prefix.'Bordereau remise '.$typeLabel.' n°'.$remise->numero.' du '.$dateFormatted.'.pdf';

        $pdf = Pdf::loadView('pdf.remise-bancaire', $data);
        $inline = request()->query('mode') === 'inline';

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }
}
