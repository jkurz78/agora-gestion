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

            // Trace the opt-out action
            EmailLog::create([
                'tiers_id' => $log->tiers_id,
                'categorie' => 'communication',
                'destinataire_email' => $log->destinataire_email,
                'destinataire_nom' => $log->destinataire_nom,
                'objet' => 'Désinscription RGPD',
                'statut' => 'envoye',
            ]);
        }

        return view('email.optout');
    }
}
