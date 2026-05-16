<?php

declare(strict_types=1);

namespace App\Http\Middleware\Portail;

use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Support\PortailRoute;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePeutVoirNotesDeFrais
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();

        $hasNdf = $tiers !== null
            && NoteDeFrais::query()->where('tiers_id', $tiers->id)->exists();

        if ($tiers === null || ($tiers->pour_depenses !== true && ! $hasNdf)) {
            session()->flash('portail.info', "Cet espace n'est pas activé pour votre compte.");

            return redirect(PortailRoute::to('home', $request->route('association')));
        }

        return $next($request);
    }
}
