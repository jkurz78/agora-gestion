<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use App\Models\IncomingDocument;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
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
            $smtp = \App\Models\SmtpParametres::where('association_id', 1)->first();
        } catch (\Throwable) {
            // DB non disponible (migrations, CI sans DB, artisan sans connexion)
            return;
        }

        if ($smtp === null || ! $smtp->enabled) {
            return;
        }

        \Illuminate\Support\Facades\Config::set('mail.default', 'smtp');
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.host', $smtp->smtp_host);
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.port', $smtp->smtp_port);
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.username', $smtp->smtp_username);
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.password', $smtp->smtp_password);
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.timeout', $smtp->timeout);
        \Illuminate\Support\Facades\Config::set('mail.mailers.smtp.scheme', match ($smtp->smtp_encryption) {
            'ssl'   => 'smtps',
            default => null,
        });
    }
}
