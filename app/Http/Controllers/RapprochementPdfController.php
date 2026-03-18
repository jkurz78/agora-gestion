<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class RapprochementPdfController extends Controller
{
    public function __invoke(RapprochementBancaire $rapprochement): Response
    {
        $rapprochement->load(['compte', 'saisiPar']);
        $compte = $rapprochement->compte;

        $rid = $rapprochement->id;

        $transactions = $this->collectTransactions($compte->id, $rid);

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

    private function collectTransactions(int $compteId, int $rid): Collection
    {
        $transactions = collect();

        Transaction::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->get()
            ->each(function (Transaction $tx) use (&$transactions) {
                $transactions->push([
                    'date' => $tx->date,
                    'type' => $tx->type->label(),
                    'label' => $tx->libelle,
                    'reference' => $tx->reference ?? null,
                    'montant_signe' => $tx->montantSigne(),
                ]);
            });

        Don::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->with('tiers')
            ->get()
            ->each(function (Don $d) use (&$transactions) {
                $transactions->push([
                    'date' => $d->date,
                    'type' => 'Don',
                    'label' => $d->tiers
                        ? $d->tiers->displayName()
                        : ($d->objet ?? 'Don anonyme'),
                    'reference' => null,
                    'montant_signe' => (float) $d->montant,
                ]);
            });

        Cotisation::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->with('membre')
            ->get()
            ->each(function (Cotisation $c) use (&$transactions) {
                $transactions->push([
                    'date' => $c->date_paiement,
                    'type' => 'Cotisation',
                    'label' => $c->membre ? $c->membre->nom.' '.$c->membre->prenom : 'Cotisation',
                    'reference' => null,
                    'montant_signe' => (float) $c->montant,
                ]);
            });

        VirementInterne::where('compte_source_id', $compteId)
            ->where('rapprochement_source_id', $rid)
            ->with('compteDestination')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions) {
                $transactions->push([
                    'date' => $v->date,
                    'type' => 'Virement sortant',
                    'label' => 'Virement vers '.$v->compteDestination->nom,
                    'reference' => $v->reference ?? null,
                    'montant_signe' => -(float) $v->montant,
                ]);
            });

        VirementInterne::where('compte_destination_id', $compteId)
            ->where('rapprochement_destination_id', $rid)
            ->with('compteSource')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions) {
                $transactions->push([
                    'date' => $v->date,
                    'type' => 'Virement entrant',
                    'label' => 'Virement depuis '.$v->compteSource->nom,
                    'reference' => $v->reference ?? null,
                    'montant_signe' => (float) $v->montant,
                ]);
            });

        return $transactions->sortBy('date')->values();
    }
}
