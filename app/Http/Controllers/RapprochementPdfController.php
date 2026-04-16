<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // Association resolved from TenantContext (booted by ResolveTenant middleware)
        $association = CurrentAssociation::get();

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

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $data = [
            'rapprochement' => $rapprochement,
            'compte' => $compte,
            'transactions' => $transactions,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'association' => $association,
            'logoBase64' => $logoBase64,
            'logoMime' => $logoMime,
            'appLogoBase64' => $appLogoBase64,
            // No footer association logo: the header already shows it.
            'footerLogoBase64' => null,
            'footerLogoMime' => null,
        ];

        $dateFin = $rapprochement->date_fin->format('Y-m-d');
        $comptePart = str_replace('/', '-', Str::ascii($compte->nom));
        $prefix = $association?->nom
            ? str_replace('/', '-', Str::ascii($association->nom)).' - '
            : '';
        $filename = $prefix.'Rapprochement '.$comptePart.' au '.$dateFin.'.pdf';

        $pdf = Pdf::loadView('pdf.rapprochement', $data);
        PdfFooterRenderer::render($pdf);

        $inline = request()->query('mode') === 'inline';

        return $inline ? $pdf->stream($filename) : $pdf->download($filename);
    }

    private function collectTransactions(int $compteId, int $rid): Collection
    {
        $transactions = collect();

        // Récupérer toutes les transactions pointées pour ce rapprochement
        $txRows = Transaction::where('compte_id', $compteId)
            ->where('rapprochement_id', $rid)
            ->with('tiers', 'remise')
            ->get();

        // Grouper les transactions appartenant à une remise
        $remiseGroups = $txRows->whereNotNull('remise_id')->groupBy('remise_id');
        $standalone = $txRows->whereNull('remise_id');

        // Lignes remises — une ligne par remise avec sous-transactions
        foreach ($remiseGroups as $remiseId => $remiseTxs) {
            $remise = $remiseTxs->first()->remise;
            $montantTotal = $remiseTxs->sum(fn (Transaction $tx) => $tx->montantSigne());
            $transactions->push([
                'id' => (int) $remiseId,
                'type' => 'Remise',
                'date' => $remise?->date ?? $remiseTxs->first()->date,
                'label' => $remise?->libelle ?? "Remise n°{$remiseId}",
                'tiers' => "Remise {$remiseTxs->first()->mode_paiement?->label()} ({$remiseTxs->count()} transactions)",
                'reference' => $remise?->numero ? "n°{$remise->numero}" : null,
                'mode_paiement' => $remiseTxs->first()->mode_paiement?->trigramme(),
                'montant_signe' => $montantTotal,
                'sub_transactions' => $remiseTxs->map(fn (Transaction $tx) => [
                    'id' => $tx->id,
                    'date' => $tx->date,
                    'label' => $tx->libelle,
                    'tiers' => $tx->tiers?->displayName() ?? $tx->libelle,
                    'reference' => $tx->reference,
                    'montant_signe' => $tx->montantSigne(),
                ])->values()->all(),
            ]);
        }

        // Transactions standalone
        $standalone->each(function (Transaction $tx) use (&$transactions) {
            $transactions->push([
                'id' => $tx->id,
                'type' => $tx->type->label(),
                'date' => $tx->date,
                'label' => $tx->libelle,
                'tiers' => $tx->tiers?->displayName() ?? $tx->libelle,
                'reference' => $tx->reference ?? null,
                'mode_paiement' => $tx->mode_paiement?->trigramme(),
                'montant_signe' => $tx->montantSigne(),
                'sub_transactions' => [],
            ]);
        });

        VirementInterne::where('compte_source_id', $compteId)
            ->where('rapprochement_source_id', $rid)
            ->with('compteDestination')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'Virement sortant',
                    'date' => $v->date,
                    'label' => 'Virement vers '.$v->compteDestination->nom,
                    'tiers' => $v->compteDestination->nom,
                    'reference' => $v->reference ?? null,
                    'mode_paiement' => 'VMT',
                    'montant_signe' => -(float) $v->montant,
                    'sub_transactions' => [],
                ]);
            });

        VirementInterne::where('compte_destination_id', $compteId)
            ->where('rapprochement_destination_id', $rid)
            ->with('compteSource')
            ->get()
            ->each(function (VirementInterne $v) use (&$transactions) {
                $transactions->push([
                    'id' => $v->id,
                    'type' => 'Virement entrant',
                    'date' => $v->date,
                    'label' => 'Virement depuis '.$v->compteSource->nom,
                    'tiers' => $v->compteSource->nom,
                    'reference' => $v->reference ?? null,
                    'mode_paiement' => 'VMT',
                    'montant_signe' => (float) $v->montant,
                    'sub_transactions' => [],
                ]);
            });

        return $transactions->sortBy('date')->values();
    }
}
