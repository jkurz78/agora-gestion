<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DocumentPrevisionnel;
use App\Services\DocumentPrevisionnelService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class DocumentPrevisionnelPdfController extends Controller
{
    public function __invoke(
        DocumentPrevisionnel $document,
        DocumentPrevisionnelService $service,
    ): Response {
        // Serve stored PDF if available, otherwise generate
        if ($document->pdf_path && Storage::disk('local')->exists($document->pdf_path)) {
            $pdfContent = Storage::disk('local')->get($document->pdf_path);
        } else {
            $pdfContent = $service->genererPdf($document);
        }

        $document->load('participant.tiers');
        $label = $document->type->label();
        $filename = "{$label} {$document->numero} - {$document->participant->tiers->displayName()}.pdf";

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}
