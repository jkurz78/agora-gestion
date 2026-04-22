<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portail;

use App\Models\Association;
use App\Support\PortailRoute;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('tiers-portail')->check()) {
            /** @var Association|string $association */
            $association = $request->route('association');

            return redirect(PortailRoute::to('login', $association));
        }

        return $next($request);
    }
}
