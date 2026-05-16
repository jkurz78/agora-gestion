<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Enums\TypeDocumentPrevisionnel;
use App\Http\Controllers\Controller;
use App\Models\DocumentPrevisionnel;
use App\Models\Facture;
use App\Models\Participant;
use App\Models\Tiers;
use App\Services\DocumentPrevisionnelService;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class DocumentPortailController extends Controller
{
    public function __construct(
        private readonly DocumentPrevisionnelService $documentService,
        private readonly FactureService $factureService,
    ) {}

    public function devis(Request $request): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $docId = (int) $request->route('document');

        // TenantModel scope fail-closed : DocumentPrevisionnel::find() retourne null si cross-tenant
        $document = DocumentPrevisionnel::find($docId);
        abort_unless($document !== null, 404);

        // Ownership : le DocumentPrevisionnel doit être rattaché à un Participant du Tiers connecté
        $participant = Participant::find($document->participant_id);
        abort_unless($participant !== null && (int) $participant->tiers_id === (int) $tiers->id, 403);

        $contents = $this->documentService->genererPdf($document);

        Log::info('portail.devis.telecharge', [
            'document_id' => $document->id,
            'tiers_id' => $tiers->id,
        ]);

        $slug = TenantContext::current()?->slug ?? 'asso';
        $type = $document->type === TypeDocumentPrevisionnel::Devis ? 'devis' : 'proforma';
        $ref = $document->numero ?? (string) $document->id;
        $filename = "{$slug}-{$type}-{$ref}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function facture(Request $request): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $factureId = (int) $request->route('facture');

        // TenantModel scope fail-closed : Facture::find() retourne null si cross-tenant
        $facture = Facture::find($factureId);
        abort_unless($facture !== null, 404);

        // Ownership : la Facture doit être liée à un Participant du Tiers connecté
        // via : Facture → FactureLigne → TransactionLigne → Transaction → Reglement → Participant.tiers_id
        $appartient = Facture::query()
            ->where('id', (int) $facture->id)
            ->whereHas(
                'lignes.transactionLigne.transaction.reglement.participant',
                fn ($q) => $q->where('tiers_id', (int) $tiers->id)
            )
            ->exists();
        abort_unless($appartient, 403);

        $contents = $this->factureService->genererPdf($facture);

        Log::info('portail.facture.telecharge', [
            'facture_id' => $facture->id,
            'tiers_id' => $tiers->id,
        ]);

        $slug = TenantContext::current()?->slug ?? 'asso';
        $ref = $facture->numero ?? (string) $facture->id;
        $filename = "{$slug}-facture-{$ref}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
