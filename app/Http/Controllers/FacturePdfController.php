<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Facture;
use App\Services\FactureService;
use Illuminate\Http\Response;

final class FacturePdfController extends Controller
{
    public function __invoke(Facture $facture, FactureService $service): Response
    {
        $facture->load('tiers');

        $pdfContent = $service->genererPdf($facture);
        $filename = "Facture {$facture->numero} - {$facture->tiers->displayName()}.pdf";

        $inline = request()->query('mode') === 'inline';

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename=\"{$filename}\"",
        ]);
    }
}
