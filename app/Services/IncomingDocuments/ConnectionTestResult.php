<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments;

final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $success,
        public int $folderCount = 0,
        public ?int $inboxUnseen = null,
        public ?string $erreur = null,
        public bool $processedFolderCreated = false,
        public bool $errorsFolderCreated = false,
    ) {}
}
