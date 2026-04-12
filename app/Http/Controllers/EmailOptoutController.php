<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class EmailOptoutController extends Controller
{
    public function optout(Request $request, string $token): View
    {
        $log = EmailLog::where('tracking_token', $token)->firstOrFail();

        if ($log->tiers_id) {
            Tiers::where('id', $log->tiers_id)->update(['email_optout' => true]);

            EmailLog::create([
                'tiers_id' => $log->tiers_id,
                'categorie' => 'communication',
                'destinataire_email' => $log->destinataire_email,
                'destinataire_nom' => $log->destinataire_nom,
                'objet' => 'Désinscription RGPD',
                'statut' => 'envoye',
            ]);
        }

        return view('email.optout', [
            'token' => $token,
            'resubscribed' => false,
        ] + $this->associationData());
    }

    public function resubscribe(Request $request, string $token): View
    {
        $log = EmailLog::where('tracking_token', $token)->firstOrFail();

        if ($log->tiers_id) {
            Tiers::where('id', $log->tiers_id)->update(['email_optout' => false]);

            EmailLog::create([
                'tiers_id' => $log->tiers_id,
                'categorie' => 'communication',
                'destinataire_email' => $log->destinataire_email,
                'destinataire_nom' => $log->destinataire_nom,
                'objet' => 'Réinscription communications',
                'statut' => 'envoye',
            ]);
        }

        return view('email.optout', [
            'token' => $token,
            'resubscribed' => true,
        ] + $this->associationData());
    }

    /** @return array{nomAsso: string, logoUrl: string|null} */
    private function associationData(): array
    {
        $association = Association::find(1);
        $logoUrl = ($association?->logo_path && Storage::disk('public')->exists($association->logo_path))
            ? Storage::disk('public')->url($association->logo_path)
            : null;

        return [
            'nomAsso' => $association?->nom ?? 'Notre association',
            'logoUrl' => $logoUrl,
        ];
    }
}
