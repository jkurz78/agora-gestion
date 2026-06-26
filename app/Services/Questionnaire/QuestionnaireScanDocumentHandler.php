<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Services\IncomingDocuments\Contracts\DocumentHandler;
use App\Services\IncomingDocuments\HandlerAttempt;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use App\Services\Questionnaire\Contracts\QrDecoderContract;

final class QuestionnaireScanDocumentHandler implements DocumentHandler
{
    public function __construct(
        private readonly QrDecoderContract $decoder,
        private readonly QuestionnaireScanService $scans,
    ) {}

    public function name(): string
    {
        return 'questionnaire_scan';
    }

    public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
    {
        // Only handle images and PDFs
        if (! $this->isImageOrPdf($file)) {
            return HandlerAttempt::pass();
        }

        // Try to decode a questionnaire QR from the file
        $mime = $this->detectMime($file);
        $token = $this->decoder->decodeFromPath($file->tempPath, $mime);

        if ($token === null) {
            // No questionnaire QR found — let next handler try
            return HandlerAttempt::pass();
        }

        // We found a questionnaire QR — ingest the scan
        $scan = $this->scans->ingererDepuisFichier(
            path: $file->tempPath,
            mime: $mime,
            source: 'email',
            token: $token,
        );

        if ($scan->invitation_id === null) {
            return HandlerAttempt::failed('questionnaire_qr_unresolved', 'Token QR questionnaire non résolu');
        }

        return HandlerAttempt::handled(['scan_id' => $scan->id, 'campaign_id' => $scan->campaign_id]);
    }

    private function isImageOrPdf(IncomingDocumentFile $file): bool
    {
        $ext = strtolower(pathinfo($file->originalFilename, PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg'], true)) {
            return true;
        }

        if (file_exists($file->tempPath)) {
            $mime = @mime_content_type($file->tempPath);

            return $mime !== false && (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
        }

        return false;
    }

    private function detectMime(IncomingDocumentFile $file): string
    {
        if (file_exists($file->tempPath)) {
            $mime = @mime_content_type($file->tempPath);
            if ($mime !== false && $mime !== '') {
                return $mime;
            }
        }

        $ext = strtolower(pathinfo($file->originalFilename, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };
    }
}
