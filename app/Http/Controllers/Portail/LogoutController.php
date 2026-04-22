<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Http\Controllers\Controller;
use App\Models\Association;
use App\Support\PortailRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class LogoutController extends Controller
{
    public function __invoke(Association $association): RedirectResponse
    {
        Auth::guard('tiers-portail')->logout();
        session()->forget('portail.last_activity_at');

        return redirect(PortailRoute::to('login', $association));
    }
}
