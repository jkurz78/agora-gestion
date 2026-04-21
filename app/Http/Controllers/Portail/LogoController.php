<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Association;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class LogoController extends Controller
{
    public function __invoke(Association $association): Response|RedirectResponse
    {
        $path = $association->brandingLogoFullPath();
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return redirect(asset('images/agora-gestion.svg'));
        }

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=3600',
        ], 'inline');
    }
}
