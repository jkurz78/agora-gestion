<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Tenant\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class OnboardingBrandingController extends Controller
{
    public function show(string $kind): Response
    {
        $association = TenantContext::current();

        if ($association === null) {
            abort(404);
        }

        $fullPath = match ($kind) {
            'logo' => $association->brandingLogoFullPath(),
            'cachet' => $association->brandingCachetFullPath(),
            default => null,
        };

        if ($fullPath === null || ! Storage::disk('local')->exists($fullPath)) {
            abort(404);
        }

        $mime = Storage::disk('local')->mimeType($fullPath) ?? 'application/octet-stream';
        $contents = Storage::disk('local')->get($fullPath);

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
