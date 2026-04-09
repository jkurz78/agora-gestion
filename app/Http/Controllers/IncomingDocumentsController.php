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

    public function thumbnail(IncomingDocument $document): StreamedResponse
    {
        $thumbPath = IncomingDocument::thumbnailPath($document->storage_path);

        abort_if(! Storage::disk('local')->exists($thumbPath), 404);

        return Storage::disk('local')->response(
            $thumbPath,
            'thumb-'.pathinfo($document->storage_path, PATHINFO_FILENAME).'.jpg',
            ['Content-Type' => 'image/jpeg'],
        );
    }
}
