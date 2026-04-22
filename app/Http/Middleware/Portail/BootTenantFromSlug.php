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

        // Livewire peut rejouer ce middleware sur /livewire/update où aucun
        // route param n'est présent. Le composant Livewire boote lui-même
        // le TenantContext via le trait WithPortailTenant depuis sa propriété
        // $association rehydratée. On passe donc silencieusement.
        if ($param === null) {
            return $next($request);
        }

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

        return $next($request);
    }
}
