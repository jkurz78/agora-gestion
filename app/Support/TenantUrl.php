<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Helper façade unifié pour les URLs applicatives tenant-aware.
 *
 * Aujourd'hui : wrappers transparents sur route()/url()/URL::signedRoute().
 * Demain (S7) : retournera https://{slug}.agoragestion.fr/... sans toucher aux appelants.
 *
 * Règle d'adoption : TOUT email, PDF, webhook, réponse publique doit passer par ici.
 */
final class TenantUrl
{
    /**
     * @param  array<string,mixed>  $params
     */
    public static function route(string $name, array $params = [], bool $absolute = true): string
    {
        return route($name, $params, $absolute);
    }

    public static function to(string $path, bool $secure = false): string
    {
        return url($path, [], $secure ?: null);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    public static function signed(string $name, array $params = [], bool $absolute = true): string
    {
        return URL::signedRoute($name, $params, null, $absolute);
    }
}
