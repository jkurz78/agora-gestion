<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\SmtpParametres;
use App\Observers\ImmutableSlugObserver;
use Illuminate\Support\Facades\Config;
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

        $this->overrideMailConfig();
    }

    private function overrideMailConfig(): void
    {
        try {
            $smtp = SmtpParametres::where('association_id', 1)->first();
        } catch (\Throwable) {
            // DB non disponible (migrations, CI sans DB, artisan sans connexion)
            return;
        }

        if ($smtp === null || ! $smtp->enabled) {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $smtp->smtp_host);
        Config::set('mail.mailers.smtp.port', $smtp->smtp_port);
        Config::set('mail.mailers.smtp.username', $smtp->smtp_username);
        Config::set('mail.mailers.smtp.password', $smtp->smtp_password);
        Config::set('mail.mailers.smtp.timeout', $smtp->timeout);
        Config::set('mail.mailers.smtp.scheme', match ($smtp->smtp_encryption) {
            'ssl' => 'smtps',
            default => null,
        });
    }
}
