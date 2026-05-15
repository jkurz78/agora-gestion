<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Portail\PortailSectionsResolver;
use App\Services\Portail\Providers\FacturesPartenairesProvider;
use App\Services\Portail\Providers\HistoriqueDepensesProvider;
use App\Services\Portail\Providers\MesActivitesProvider;
use App\Services\Portail\Providers\MesAdhesionsProvider;
use App\Services\Portail\Providers\MesDonsProvider;
use App\Services\Portail\Providers\MonProfilProvider;
use App\Services\Portail\Providers\NotesDeFraisProvider;
use App\Services\Portail\Providers\TableauDeBordProvider;
use Illuminate\Support\ServiceProvider;

final class PortailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PortailSectionsResolver::class);
    }

    public function boot(): void
    {
        $resolver = $this->app->make(PortailSectionsResolver::class);

        $resolver->register(new TableauDeBordProvider);
        $resolver->register(new MonProfilProvider);
        $resolver->register(new NotesDeFraisProvider);
        $resolver->register(new FacturesPartenairesProvider);
        $resolver->register(new HistoriqueDepensesProvider);
        $resolver->register(new MesAdhesionsProvider);
        $resolver->register(new MesDonsProvider);
        $resolver->register(new MesActivitesProvider);
    }
}
