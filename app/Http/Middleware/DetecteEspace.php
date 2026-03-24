<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Espace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DetecteEspace
{
    public function handle(Request $request, Closure $next, string $espace): Response
    {
        $espaceEnum = Espace::from($espace);

        // Share with views
        $request->attributes->set('espace', $espaceEnum);
        view()->share('espace', $espaceEnum);
        view()->share('espaceColor', $espaceEnum->color());
        view()->share('espaceLabel', $espaceEnum->label());

        // Persist last espace choice
        $user = $request->user();
        if ($user !== null && $user->dernier_espace !== $espaceEnum) {
            $user->update(['dernier_espace' => $espaceEnum]);
        }

        return $next($request);
    }
}
