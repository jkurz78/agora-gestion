<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Association;
use App\Support\CurrentAssociation;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class LayoutAssociationComposerProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Auth layouts: inject association from TenantContext (booted by ResolveTenant middleware).
        View::composer(['layouts.app', 'layouts.app-sidebar'], function ($view): void {
            $view->with('association', CurrentAssociation::tryGet());
        });

        // Guest layout: no TenantContext is booted on public routes.
        // TODO(S7): replace with CurrentAssociation::tryGet() once public routes resolve tenant from URL/subdomain.
        View::composer('layouts.guest', function ($view): void {
            $view->with('association', Association::first());
        });
    }
}
