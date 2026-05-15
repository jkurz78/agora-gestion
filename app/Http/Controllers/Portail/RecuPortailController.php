<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Exceptions\RecuFiscalException;
use App\Http\Controllers\Controller;
use App\Models\Adhesion;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Services\Tiers\TiersDonsTimelineService;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class RecuPortailController extends Controller
{
    public function cotisation(Request $request): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        // Résolution manuelle — TenantScope fail-closed garantit l'isolation cross-tenant.
        $adhesion = Adhesion::find((int) $request->route('adhesion'));
        abort_unless($adhesion !== null, 404);

        // Ownership : l'adhésion doit appartenir au Tiers connecté.
        abort_unless((int) $adhesion->tiers_id === (int) $tiers->id, 403);

        try {
            $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);
        } catch (RecuFiscalException $e) {
            abort(422, $e->getMessage());
        }

        if (! $recu->verifierIntegrite()) {
            abort(500, "Intégrité du PDF reçu n°{$recu->numero} compromise");
        }

        Log::info('portail.recu.cotisation.telecharge', [
            'adhesion_id' => $adhesion->id,
            'tiers_id' => $tiers->id,
        ]);

        $contents = Storage::disk('local')->get($recu->pdfFullPath());
        $filename = "recu-cotisation-{$recu->numero}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function fiscalDon(Request $request): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        // TransactionLigne n'extends pas TenantModel — résolution manuelle + garde cross-tenant explicite.
        // On charge la transaction sans scope global (Transaction extends TenantModel) pour pouvoir
        // vérifier le tenant AVANT d'appliquer l'isolation.
        $ligne = TransactionLigne::find((int) $request->route('ligne'));
        abort_unless($ligne !== null, 404);

        $transaction = $ligne->transaction()->withoutGlobalScopes()->first();
        abort_unless($transaction !== null, 404);

        // Garde cross-tenant : la transaction doit appartenir au tenant courant.
        abort_unless((int) $transaction->association_id === (int) TenantContext::currentId(), 403);

        // Ownership : la ligne doit apparaître dans le résultat du service pour CE Tiers.
        // TiersDonsTimelineService applique : recette + sous-cat usage Don + transaction.tiers_id + TenantScope.
        $dto = app(TiersDonsTimelineService::class)->forTiers($tiers);
        $appartient = false;
        foreach ($dto->annees as $annee) {
            foreach ($annee->lignes as $donDto) {
                if ((int) $donDto->ligne->id === (int) $ligne->id) {
                    $appartient = true;
                    break 2;
                }
            }
        }
        abort_unless($appartient, 403);

        try {
            $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
        } catch (RecuFiscalException $e) {
            abort(422, $e->getMessage());
        }

        if (! $recu->verifierIntegrite()) {
            abort(500, "Intégrité du PDF reçu n°{$recu->numero} compromise");
        }

        Log::info('portail.recu.fiscal.telecharge', [
            'ligne_id' => $ligne->id,
            'tiers_id' => $tiers->id,
        ]);

        $contents = Storage::disk('local')->get($recu->pdfFullPath());
        $filename = "recu-fiscal-{$recu->numero}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
