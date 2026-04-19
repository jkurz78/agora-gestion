<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portail;

use App\Models\Association;
use App\Services\Portail\AuthSessionService;
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
            $param = $request->route('association');

            $slug = $param instanceof Association ? $param->slug : (string) $param;

            return redirect()->route('portail.choisir', ['association' => $slug]);
        }

        return $next($request);
    }
}
