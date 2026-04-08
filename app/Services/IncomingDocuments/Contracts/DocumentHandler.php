<?php

declare(strict_types=1);

namespace App\Services\IncomingDocuments\Contracts;

use App\Services\IncomingDocuments\HandlerAttempt;
use App\Services\IncomingDocuments\IncomingDocumentFile;

interface DocumentHandler
{
    public function tryHandle(IncomingDocumentFile $file): HandlerAttempt;

    public function name(): string;
}
