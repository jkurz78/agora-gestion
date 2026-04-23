<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Association;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MonoAssociationResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! MonoAssociation::isActive()) {
            return $next($request);
        }

        $association = Association::first();

        if ($association !== null) {
            // Boot (idempotent) and always bind the association route parameter.
            // Why: a user already authenticated via web has TenantContext booted by
            // ResolveTenant before this middleware runs. Skipping here left the route
            // parameter "association" unset, causing Livewire mount(Association) to
            // fall back to an empty model resolved from the container, which then
            // clobbered TenantContext through WithPortailTenant and made the portail
            // layout crash on route('portail.logo') with a null slug.
            TenantContext::boot($association);

            if ($request->route() !== null) {
                $request->route()->setParameter('association', $association);
            }
        }

        return $next($request);
    }
}
