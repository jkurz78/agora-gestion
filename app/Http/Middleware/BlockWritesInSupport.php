<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BlockWritesInSupport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('support_mode', false)) {
            return $next($request);
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if ($request->routeIs('super-admin.support.exit')) {
            return $next($request);
        }

        abort(403, 'Mode support actif : toute écriture est interdite.');
    }
}
