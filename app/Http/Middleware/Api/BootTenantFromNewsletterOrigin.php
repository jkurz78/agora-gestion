<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\Association;
use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BootTenantFromNewsletterOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = (string) $request->headers->get('Origin', '');

        $origins = (array) config('newsletter.origins', []);
        $slug = $origins[$origin] ?? null;

        if ($slug === null) {
            abort(403, 'Origin not allowed.');
        }

        $association = Association::where('slug', $slug)->first();

        if ($association === null) {
            abort(403, 'Association not found.');
        }

        TenantContext::boot($association);

        return $next($request);
    }
}
