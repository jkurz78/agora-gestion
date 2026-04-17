<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTenantAsset
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = (string) $request->route('path');

        if (str_contains($path, '..')) {
            abort(403, 'Path traversal interdit.');
        }

        $currentAssoId = TenantContext::currentId();
        if ($currentAssoId === null) {
            abort(403);
        }

        $prefix = 'associations/'.$currentAssoId.'/';
        if (! str_starts_with($path, $prefix)) {
            abort(403, 'Asset hors tenant courant.');
        }

        return $next($request);
    }
}
