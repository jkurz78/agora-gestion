<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Devis;
use App\Services\DevisService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class DevisManuelPdfController extends Controller
{
    public function __invoke(Devis $devis, DevisService $service): Response
    {
        $devis->load('tiers');

        $pdfPath = $service->genererPdf($devis);

        $pdfContent = Storage::disk('local')->get($pdfPath);

        $label = $devis->numero ?? 'Brouillon';
        $tiernom = $devis->tiers?->displayName() ?? '';
        $filename = "Devis {$label} - {$tiernom}.pdf";

        return response((string) $pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
