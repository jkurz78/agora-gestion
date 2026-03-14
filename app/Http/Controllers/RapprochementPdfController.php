<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\VirementInterne;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class RapprochementPdfController extends Controller
{
    public function __invoke(Request $request, RapprochementBancaire $rapprochement): Response
    {
        $rapprochement->load(['compte', 'saisiPar']);
        $compte = $rapprochement->compte;

        $rid = $rapprochement->id;

        // Collect all pointed transactions for this rapprochement
        $transactions = collect();

        // Dépenses (debit)
        Depense::where('rapprochement_id', $rid)->get()
            ->each(function (Depense $d) use ($transactions): void {
                $transactions->push([
                    'date' => $d->date,
                    'type' => 'Dépense',
                    'label' => $d->libelle,
                    'reference' => $d->reference ?? '',
                    'montant_signe' => -(float) $d->montant_total,
                ]);
            });

        // Recettes (credit)
        Recette::where('rapprochement_id', $rid)->get()
            ->each(function (Recette $r) use ($transactions): void {
                $transactions->push([
                    'date' => $r->date,
                    'type' => 'Recette',
                    'label' => $r->libelle,
                    'reference' => $r->reference ?? '',
                    'montant_signe' => (float) $r->montant_total,
                ]);
            });

        // Dons (credit)
        Don::where('rapprochement_id', $rid)->get()
            ->each(function (Don $d) use ($transactions): void {
                $transactions->push([
                    'date' => $d->date,
                    'type' => 'Don',
                    'label' => $d->objet ?? 'Don',
                    'reference' => '',
                    'montant_signe' => (float) $d->montant,
                ]);
            });

        // Cotisations (credit)
        Cotisation::where('rapprochement_id', $rid)->get()
            ->each(function (Cotisation $c) use ($transactions): void {
                $transactions->push([
                    'date' => $c->date_paiement,
                    'type' => 'Cotisation',
                    'label' => 'Cotisation exercice '.$c->exercice,
                    'reference' => '',
                    'montant_signe' => (float) $c->montant,
                ]);
            });

        // Virements source (debit — money leaving this account)
        VirementInterne::where('rapprochement_source_id', $rid)->get()
            ->each(function (VirementInterne $v) use ($transactions): void {
                $transactions->push([
                    'date' => $v->date,
                    'type' => 'Virement sortant',
                    'label' => 'Virement interne',
                    'reference' => $v->reference ?? '',
                    'montant_signe' => -(float) $v->montant,
                ]);
            });

        // Virements destination (credit — money arriving on this account)
        VirementInterne::where('rapprochement_destination_id', $rid)->get()
            ->each(function (VirementInterne $v) use ($transactions): void {
                $transactions->push([
                    'date' => $v->date,
                    'type' => 'Virement entrant',
                    'label' => 'Virement interne',
                    'reference' => $v->reference ?? '',
                    'montant_signe' => (float) $v->montant,
                ]);
            });

        // Sort by date ascending
        $transactions = $transactions->sortBy('date')->values();

        $totalDebit = abs($transactions->where('montant_signe', '<', 0)->sum('montant_signe'));
        $totalCredit = $transactions->where('montant_signe', '>', 0)->sum('montant_signe');

        // Association (may be null)
        $association = Association::find(1);

        // Logo base64 (null-safe)
        $logoBase64 = null;
        $logoMime = 'image/png';
        if ($association !== null && $association->logo_path !== null) {
            $path = $association->logo_path;
            if (Storage::disk('public')->exists($path)) {
                $logoBase64 = base64_encode(Storage::disk('public')->get($path));
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $logoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
            }
        }

        $data = [
            'rapprochement' => $rapprochement,
            'compte' => $compte,
            'transactions' => $transactions,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'association' => $association,
            'logoBase64' => $logoBase64,
            'logoMime' => $logoMime,
        ];

        $filename = 'rapprochement-'.$rapprochement->id.'.pdf';

        return Pdf::loadView('pdf.rapprochement', $data)->download($filename);
    }
}
