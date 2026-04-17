<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Observers\ImmutableSlugObserver;
use App\Tenant\TenantContext;
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

        // TODO(S3-BootTenantConfig) : move to per-request middleware once Slice 3 lands.
        $this->overrideMailConfig();
    }

    private function overrideMailConfig(): void
    {
        $associationId = TenantContext::currentId();
        if ($associationId === null) {
            return;
        }

        try {
            $smtp = \App\Models\SmtpParametres::where('association_id', $associationId)->first();
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
