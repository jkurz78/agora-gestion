<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Models\Tiers;
use App\Support\Demo;
use App\Support\PortailRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Bypass OTP portail réservé à l'environnement démo.
 *
 * Route : GET /portail/demo/login-as/{tierId}
 *         GET /{slug}/portail/demo/login-as/{tierId}
 *
 * Guard : Demo::isActive() est vérifié EN TOUTE PREMIÈRE INSTRUCTION.
 *         Si l'env n'est pas "demo", la requête est rejetée avec 403 sans
 *         aucun side-effect.
 */
final class DemoLoginAsTierController extends Controller
{
    public function __invoke(int $tierId, Association $association): RedirectResponse
    {
        // ── GARDE STRICTE — doit rester en première position ──────────────────
        if (! Demo::isActive()) {
            abort(403, 'Bypass OTP portail interdit hors environnement démo.');
        }
        // ──────────────────────────────────────────────────────────────────────

        /** @var Tiers $tiers */
        $tiers = Tiers::findOrFail($tierId);

        Log::info('demo.portail.login_as_tier', [
            'tier_id' => (int) $tiers->id,
            'email' => $tiers->email,
        ]);

        // Reproduit fidèlement les effets de bord d'un login OTP réussi
        // (voir AuthSessionService::loginSingleTiers) :
        Auth::guard('tiers-portail')->login($tiers);

        return redirect(PortailRoute::to('home', $association));
    }
}
