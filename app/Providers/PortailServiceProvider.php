<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Portail\PortailSectionsResolver;
use Illuminate\Support\ServiceProvider;

final class PortailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PortailSectionsResolver::class);
    }
}
