<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\EmailLog;
use App\Models\Tiers;
use App\Support\TenantAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class EmailOptoutController extends Controller
{
    public function showOptout(string $token): View
    {
        EmailLog::where('tracking_token', $token)->firstOrFail();

        return view('email.optout', [
            'token' => $token,
            'confirmed' => false,
            'resubscribed' => false,
        ] + $this->associationData());
    }

    public function optout(string $token): View
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
            'confirmed' => true,
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
            'confirmed' => true,
            'resubscribed' => true,
        ] + $this->associationData());
    }

    /** @return array{nomAsso: string, logoUrl: string|null} */
    private function associationData(): array
    {
        // TODO(S7): public route — no TenantContext booted here.
        // Replace with CurrentAssociation::get() once public routes resolve tenant from URL/subdomain.
        $association = Association::find(1);
        $logoUrl = null;
        $logoFullPath = $association?->brandingLogoFullPath();
        if ($logoFullPath && Storage::disk('local')->exists($logoFullPath)) {
            $logoUrl = TenantAsset::url($logoFullPath);
        }

        return [
            'nomAsso' => $association?->nom ?? 'Notre association',
            'logoUrl' => $logoUrl,
        ];
    }
}
