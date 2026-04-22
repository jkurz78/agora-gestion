<?php

declare(strict_types=1);

namespace App\Providers;

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

        // Guest layout: public routes (login, password reset, etc.) have no tenant context.
        // Pass null so the layout falls back to product branding (AgoraGestion).
        View::composer('layouts.guest', function ($view): void {
            $view->with('association', CurrentAssociation::tryGet());
        });
    }
}
