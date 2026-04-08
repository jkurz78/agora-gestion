<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments;

use DateTimeImmutable;

final readonly class IncomingDocumentFile
{
    public function __construct(
        public string $tempPath,
        public string $originalFilename,
        public string $source,
        public ?string $senderEmail,
        public ?string $recipientEmail,
        public ?string $subject,
        public DateTimeImmutable $receivedAt,
        public ?string $sourceMessageId,
    ) {}
}
