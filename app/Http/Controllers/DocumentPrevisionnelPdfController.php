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
        $fullPath = $document->pdfFullPath();
        if ($fullPath && Storage::disk('local')->exists($fullPath)) {
            $pdfContent = Storage::disk('local')->get($fullPath);
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
