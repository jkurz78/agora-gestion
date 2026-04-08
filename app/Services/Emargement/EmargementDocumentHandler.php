<?php

declare(strict_types=1);

namespace App\Services\Emargement;

use App\Models\Seance;
use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\IncomingDocuments\Contracts\DocumentHandler;
use App\Services\IncomingDocuments\HandlerAttempt;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class EmargementDocumentHandler implements DocumentHandler
{
    public function __construct(
        private readonly QrCodeExtractor $extractor,
    ) {}

    public function name(): string
    {
        return 'emargement';
    }

    public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
    {
        if (! $this->isPdf($file)) {
            return HandlerAttempt::pass();
        }

        $qr = $this->extractor->extractSeanceIdFromPdf($file->tempPath);

        // No QR on the page or decoder choked on an unknown QR → not ours, let next handler try
        if ($qr->reason === 'qr_not_found' || $qr->reason === 'qr_unreadable') {
            return HandlerAttempt::pass();
        }

        // Rasterization failed → no handler can process this; claim it with a clear reason
        if ($qr->reason === 'pdf_unreadable') {
            return HandlerAttempt::failed('pdf_unreadable', $qr->detail);
        }

        // Wrong env → emargement QR but from another environment, claim + reject
        if ($qr->reason === 'qr_wrong_environment') {
            return HandlerAttempt::failed('qr_wrong_environment', $qr->detail);
        }

        // $qr->reason === 'ok' at this point, seanceId is set
        $seance = Seance::find($qr->seanceId);
        if ($seance === null) {
            return HandlerAttempt::failed(
                'qr_no_matching_seance',
                "seance_id {$qr->seanceId} introuvable",
            );
        }

        $this->attachToSeance($file, $seance);

        return HandlerAttempt::handled(['seance_id' => $seance->id]);
    }

    private function isPdf(IncomingDocumentFile $file): bool
    {
        $ext = strtolower(pathinfo($file->originalFilename, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            return true;
        }

        if (file_exists($file->tempPath)) {
            $mime = @mime_content_type($file->tempPath);

            return $mime === 'application/pdf';
        }

        return false;
    }

    private function attachToSeance(IncomingDocumentFile $file, Seance $seance): void
    {
        $finalPath = "emargement/seance-{$seance->id}.pdf";

        if ($seance->feuille_signee_path !== null) {
            Log::info('Feuille signée écrasée lors d\'un rescan', [
                'seance_id' => $seance->id,
                'previous_source' => $seance->feuille_signee_source,
                'previous_attached_at' => $seance->feuille_signee_at?->toIso8601String(),
                'new_source' => $file->source,
            ]);
        }

        Storage::disk('local')->put($finalPath, file_get_contents($file->tempPath));

        $seance->update([
            'feuille_signee_path' => $finalPath,
            'feuille_signee_at' => $file->receivedAt,
            'feuille_signee_source' => $file->source === 'email' ? 'email' : 'manual',
            'feuille_signee_sender_email' => $file->senderEmail,
        ]);
    }
}
