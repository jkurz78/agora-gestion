<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portail;

use App\Services\Portail\AuthSessionService;
use App\Support\PortailRoute;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTiersChosen
{
    public function __construct(private readonly AuthSessionService $authSession) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('tiers-portail')->check() && $this->authSession->hasPendingChoice()) {
            $association = $request->route('association');

            return redirect(PortailRoute::to('choisir', $association));
        }

        return $next($request);
    }
}
