<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IncomingDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class IncomingDocumentsController extends Controller
{
    public function download(IncomingDocument $document): StreamedResponse
    {
        abort_if(! Storage::disk('local')->exists($document->storage_path), 404);

        return Storage::disk('local')->response(
            $document->storage_path,
            $document->original_filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
