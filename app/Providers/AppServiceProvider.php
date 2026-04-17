<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Observers\ImmutableSlugObserver;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
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
