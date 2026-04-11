<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ParticipantDocumentController extends Controller
{
    public function __invoke(Request $request, Participant $participant, string $filename): StreamedResponse
    {
        if (! $request->user()->peut_voir_donnees_sensibles) {
            abort(403);
        }

        $path = "participants/{$participant->id}/{$filename}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        if ($request->boolean('inline')) {
            $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';

            return Storage::disk('local')->response($path, $filename, [
                'Content-Type' => $mime,
                'Content-Disposition' => "inline; filename=\"{$filename}\"",
            ]);
        }

        return Storage::disk('local')->download($path, $filename);
    }
}
