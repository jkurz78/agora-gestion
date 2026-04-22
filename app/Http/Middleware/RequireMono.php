<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\MonoAssociation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireMono
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! MonoAssociation::isActive()) {
            abort(404);
        }

        return $next($request);
    }
}
