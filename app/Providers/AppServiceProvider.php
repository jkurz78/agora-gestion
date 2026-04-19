<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\NoteDeFrais;
use App\Observers\ImmutableSlugObserver;
use App\Policies\NoteDeFraisPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(NoteDeFrais::class, NoteDeFraisPolicy::class);

        Association::observe(ImmutableSlugObserver::class);

        if (! file_exists(config_path('version.php'))) {
            $data = VersionStampCommand::readGitVersion();
            VersionStampCommand::writeVersionFile($data);
        }

        View::composer(['layouts.app', 'layouts.app-sidebar'], function (\Illuminate\View\View $view): void {
            $view->with('incomingDocumentsCount', IncomingDocument::count());
        });
    }
}
