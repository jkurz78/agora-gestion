<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SmtpParametres;
use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class BootTenantConfig
{
    public function handle(Request $request, Closure $next): Response
    {
        $associationId = TenantContext::currentId();
        if ($associationId === null) {
            return $next($request);
        }

        try {
            $smtp = SmtpParametres::where('association_id', $associationId)->first();
        } catch (Throwable) {
            return $next($request);
        }

        if ($smtp === null || ! $smtp->enabled) {
            return $next($request);
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

        return $next($request);
    }
}
