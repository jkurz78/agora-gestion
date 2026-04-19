<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portail;

use App\Models\Association;
use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BootTenantFromSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        $param = $request->route('association');

        // If SubstituteBindings has already resolved the model, use it directly.
        // Otherwise, resolve from the raw slug string.
        if ($param instanceof Association) {
            $association = $param;
        } else {
            $slug = is_string($param) ? $param : null;

            if ($slug === null) {
                abort(404);
            }

            $association = Association::where('slug', $slug)->first();

            if ($association === null) {
                abort(404);
            }
        }

        TenantContext::boot($association);
        view()->share('portailAssociation', $association);

        return $next($request);
    }
}
