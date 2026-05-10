<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\RecuFiscalException;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

final class RecuFiscalController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RecuFiscalService $service,
    ) {}

    public function download(Request $request, Tiers $tiers, TransactionLigne $ligne): mixed
    {
        abort_unless((int) $ligne->transaction->tiers_id === (int) $tiers->id, 404);

        try {
            $recu = $this->service->obtenirOuGenerer($ligne, $request->user());
        } catch (RecuFiscalException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $this->authorize('download', $recu);

        if ($recu->isAnnule() && $recu->remplace_par_id === null) {
            $message = "Reçu annulé le {$recu->annule_at->format('d/m/Y')} — motif : {$recu->annule_motif}";

            return redirect()->back()->with('error', $message);
        }

        return $this->service->streamPdf($recu);
    }

    public function downloadByRecu(Request $request, RecuFiscalEmis $recu): mixed
    {
        $this->authorize('download', $recu);

        if ($recu->isAnnule() && $recu->remplace_par_id === null) {
            $message = "Reçu annulé le {$recu->annule_at->format('d/m/Y')} — motif : {$recu->annule_motif}";

            return redirect()->back()->with('error', $message);
        }

        return $this->service->streamPdf($recu);
    }
}
