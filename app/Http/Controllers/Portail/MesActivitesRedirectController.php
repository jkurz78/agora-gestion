<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Models\TypeOperation;
use App\Support\PortailRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

final class MesActivitesRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $premierType = TypeOperation::query()
            ->whereHas('operations.participants', fn ($q) => $q->where('tiers_id', (int) $tiers->id))
            ->orderBy('nom')
            ->first();

        abort_unless($premierType !== null, 404);

        return redirect()->to(PortailRoute::to(
            'mes-activites.show',
            $request->route('association'),
            ['typeOperation' => (int) $premierType->id],
        ));
    }
}
