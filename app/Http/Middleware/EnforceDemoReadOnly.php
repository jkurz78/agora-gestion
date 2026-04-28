<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class EnforceDemoReadOnly
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (Demo::isActive() && in_array($request->method(), self::WRITE_METHODS, true)) {
            Log::info('demo.write_blocked', [
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            abort(403, 'Modification désactivée en démo.');
        }

        return $next($request);
    }
}
