<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class TenantAssetController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $mime = Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ], 'inline');
    }
}
