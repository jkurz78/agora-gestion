<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTwoFactor
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($this->twoFactorService->isTrustedBrowser($request, $user)) {
            return $next($request);
        }

        if ($request->session()->get('two_factor_verified') === true) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }
}
