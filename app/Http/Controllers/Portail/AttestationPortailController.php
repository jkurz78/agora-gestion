<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portail;

use App\Enums\StatutPresence;
use App\Http\Controllers\Controller;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Models\Tiers;
use App\Services\AttestationPresencePdfService;
use App\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class AttestationPortailController extends Controller
{
    public function __construct(private readonly AttestationPresencePdfService $service) {}

    public function seance(Request $request): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $opId = (int) $request->route('operation');
        $seanceId = (int) $request->route('seance');

        // Operation extends TenantModel → TenantScope assure l'isolation cross-tenant
        $operation = Operation::find($opId);
        abort_unless($operation !== null, 404);

        // Seance extends TenantModel → TenantScope assure l'isolation cross-tenant
        $seance = Seance::find($seanceId);
        abort_unless($seance !== null && (int) $seance->operation_id === (int) $operation->id, 404);

        // Ownership : le Tiers connecté doit avoir un Participant pour cette opération
        $participant = Participant::query()
            ->where('operation_id', (int) $operation->id)
            ->where('tiers_id', (int) $tiers->id)
            ->first();
        abort_unless($participant !== null, 403);

        // Et il doit avoir une Presence(Present) sur cette séance
        $presence = Presence::query()
            ->where('seance_id', (int) $seance->id)
            ->where('participant_id', (int) $participant->id)
            ->first();
        abort_unless($presence !== null && $presence->statut === StatutPresence::Present->value, 403);

        Log::info('portail.attestation.seance.telecharge', [
            'participant_id' => $participant->id,
            'tiers_id' => $tiers->id,
            'seance_id' => $seance->id,
        ]);

        $contents = $this->service->seance($operation, $seance, [(int) $participant->id]);

        $slug = TenantContext::current()?->slug ?? 'asso';
        $filename = "{$slug}-attestation-seance-{$seance->id}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function recap(Request $request): Response
    {
        /** @var Tiers|null $tiers */
        $tiers = Auth::guard('tiers-portail')->user();
        abort_unless($tiers !== null, 403);

        $opId = (int) $request->route('operation');
        $participantId = (int) $request->route('participant');

        // Operation extends TenantModel → TenantScope assure l'isolation cross-tenant
        $operation = Operation::find($opId);
        abort_unless($operation !== null, 404);

        // Participant extends TenantModel → TenantScope assure l'isolation cross-tenant
        $participant = Participant::find($participantId);
        abort_unless($participant !== null, 404);

        // Ownership : le participant doit appartenir au Tiers connecté
        abort_unless((int) $participant->tiers_id === (int) $tiers->id, 403);

        // Cohérence : le participant doit appartenir à cette opération
        abort_unless((int) $participant->operation_id === (int) $operation->id, 404);

        Log::info('portail.attestation.recap.telecharge', [
            'participant_id' => $participant->id,
            'tiers_id' => $tiers->id,
        ]);

        $contents = $this->service->recap($operation, $participant);

        $slug = TenantContext::current()?->slug ?? 'asso';
        $filename = "{$slug}-attestation-recap-{$participant->id}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
