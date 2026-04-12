<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\Tiers;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EmailOptoutController extends Controller
{
    public function __invoke(Request $request, string $token): View
    {
        $log = EmailLog::where('tracking_token', $token)->firstOrFail();

        if ($log->tiers_id) {
            Tiers::where('id', $log->tiers_id)->update(['email_optout' => true]);
        }

        return view('email.optout');
    }
}
