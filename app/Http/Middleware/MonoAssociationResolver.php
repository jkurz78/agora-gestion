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
        if (TenantContext::currentId() !== null) {
            return $next($request);
        }

        if (! MonoAssociation::isActive()) {
            return $next($request);
        }

        $association = Association::first();

        if ($association !== null) {
            TenantContext::boot($association);

            // Bind the association as a route parameter so that:
            // - Livewire mount(Association $association) resolves via route model binding
            // - Controller __invoke(Association $association) resolves correctly
            // - portail.layouts.app uses $portailAssociation (shared by BootTenantFromSlug)
            if ($request->route() !== null) {
                $request->route()->setParameter('association', $association);
            }

            view()->share('portailAssociation', $association);
        }

        return $next($request);
    }
}
