<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments;

use App\Models\IncomingDocument;
use App\Services\IncomingDocuments\Contracts\DocumentHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class IncomingDocumentIngester
{
    /** @param  iterable<DocumentHandler>  $handlers */
    public function __construct(
        private readonly iterable $handlers,
        private readonly ?IncomingDocumentThumbnailGenerator $thumbnailGenerator = null,
    ) {}

    public function ingest(IncomingDocumentFile $file): IngestionResult
    {
        try {
            foreach ($this->handlers as $handler) {
                $attempt = $handler->tryHandle($file);

                if ($attempt->outcome === 'handled') {
                    Log::info('Document traité par handler', [
                        'handler' => $handler->name(),
                        'context' => $attempt->context,
                        'source' => $file->source,
                        'original_filename' => $file->originalFilename,
                    ]);

                    return IngestionResult::handled($handler->name(), $attempt->context);
                }

                if ($attempt->outcome === 'failed') {
                    $doc = $this->parkInInbox(
                        $file,
                        $handler->name(),
                        $attempt->failureReason ?? 'unclassified',
                        $attempt->failureDetail,
                    );

                    return IngestionResult::pending($doc);
                }
                // outcome === 'pass' → next handler
            }

            $doc = $this->parkInInbox(
                $file,
                null,
                'unclassified',
                'Aucun handler n\'a reconnu ce document.',
            );

            return IngestionResult::pending($doc);

        } finally {
            if (file_exists($file->tempPath)) {
                @unlink($file->tempPath);
            }
        }
    }

    private function parkInInbox(
        IncomingDocumentFile $file,
        ?string $handlerName,
        string $reason,
        ?string $detail,
    ): IncomingDocument {
        if ($file->sourceMessageId !== null) {
            $existing = IncomingDocument::where('source_message_id', $file->sourceMessageId)->first();
            if ($existing !== null) {
                Log::info('Document déjà en inbox (dédup par Message-ID)', [
                    'message_id' => $file->sourceMessageId,
                    'existing_id' => $existing->id,
                ]);

                return $existing;
            }
        }

        $uuid = Str::uuid()->toString();
        $storagePath = "incoming-documents/{$uuid}.pdf";

        Storage::disk('local')->put($storagePath, file_get_contents($file->tempPath));

        // Génération de la vignette (échec non bloquant)
        if ($this->thumbnailGenerator !== null) {
            $thumbPath = IncomingDocument::thumbnailPath($storagePath);
            $this->thumbnailGenerator->generate(
                Storage::disk('local')->path($storagePath),
                Storage::disk('local')->path($thumbPath),
            );
        }

        return IncomingDocument::create([
            'association_id' => 1,
            'storage_path' => $storagePath,
            'original_filename' => $file->originalFilename,
            'sender_email' => $file->senderEmail ?? 'upload-manuel',
            'recipient_email' => $file->recipientEmail,
            'subject' => $file->subject,
            'received_at' => $file->receivedAt,
            'source_message_id' => $file->sourceMessageId,
            'handler_attempted' => $handlerName,
            'reason' => $reason,
            'reason_detail' => $detail,
        ]);
    }
}
