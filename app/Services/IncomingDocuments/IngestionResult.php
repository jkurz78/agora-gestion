<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments;

use App\Models\IncomingDocument;

final readonly class IngestionResult
{
    public function __construct(
        public string $outcome,
        public ?string $handlerName,
        public array $context,
        public ?IncomingDocument $incomingDocument,
    ) {}

    public static function handled(string $handlerName, array $context): self
    {
        return new self('handled', $handlerName, $context, null);
    }

    public static function pending(IncomingDocument $doc): self
    {
        return new self('pending', null, [], $doc);
    }
}
