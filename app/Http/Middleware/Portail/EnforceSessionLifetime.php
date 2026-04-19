<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portail;

use App\Models\Association;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        $lifetimeMinutes = (int) config('portail.session_lifetime_minutes');
        $lastActivity = session('portail.last_activity_at');
        $now = now()->timestamp;

        if (Auth::guard('tiers-portail')->check()) {
            if ($lastActivity !== null && ($now - (int) $lastActivity) > $lifetimeMinutes * 60) {
                Auth::guard('tiers-portail')->logout();
                session()->forget('portail.last_activity_at');

                /** @var Association|string $association */
                $association = $request->route('association');
                $slug = $association instanceof Association ? $association->slug : (string) $association;

                return redirect()->route('portail.login', ['association' => $slug]);
            }

            session(['portail.last_activity_at' => $now]);
        }

        return $next($request);
    }
}
