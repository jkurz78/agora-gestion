<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portail;

use App\Models\Tiers;
use App\Support\PortailRoute;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePourDepenses
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();

        if ($tiers === null || $tiers->pour_depenses !== true) {
            session()->flash('portail.info', "Cet espace n'est pas activé pour votre compte.");

            return redirect(PortailRoute::to('home', $request->route('association')));
        }

        return $next($request);
    }
}
