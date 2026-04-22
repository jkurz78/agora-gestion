<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Association;
use Illuminate\Support\Facades\Request;

final class PortailRoute
{
    /**
     * Génère l'URL d'une route portail en choisissant automatiquement
     * la variante mono (sans slug) ou slug-first selon la route courante.
     *
     * Why: en mode mono, un visiteur qui entre via /portail/login doit
     * rester sur /portail/* après redirects internes (auth, logout, etc.).
     * Si la request courante est une route portail.mono.*, on utilise le
     * pendant mono. Sinon, on utilise la slug-first version.
     */
    public static function to(string $name, Association|string|null $association = null, array $parameters = []): string
    {
        $isMono = self::currentRouteIsMono();

        if ($isMono) {
            return route("portail.mono.{$name}", $parameters);
        }

        $slug = $association instanceof Association ? $association->slug : (string) $association;

        return route("portail.{$name}", array_merge(['association' => $slug], $parameters));
    }

    private static function currentRouteIsMono(): bool
    {
        $current = Request::route()?->getName() ?? '';

        return str_starts_with($current, 'portail.mono.');
    }
}
