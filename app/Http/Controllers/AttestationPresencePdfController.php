<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Services\AttestationPresencePdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AttestationPresencePdfController extends Controller
{
    public function __construct(private readonly AttestationPresencePdfService $service) {}

    public function seance(Request $request, Operation $operation, Seance $seance): Response
    {
        abort_unless((int) $seance->operation_id === (int) $operation->id, 404);

        $participantIds = array_filter(array_map('intval', explode(',', $request->query('participants', ''))));

        if (empty($participantIds)) {
            abort(400, 'Aucun participant sélectionné.');
        }

        $contents = $this->service->seance($operation, $seance, array_values($participantIds));
        $filename = "Attestation présence - {$operation->nom} - S{$seance->numero}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }

    public function recap(Operation $operation, Participant $participant): Response
    {
        if ((int) $participant->operation_id !== (int) $operation->id) {
            abort(404);
        }

        $contents = $this->service->recap($operation, $participant);

        $participant->load(['tiers']);
        $prenom = $participant->tiers->prenom ?? '';
        $nom = $participant->tiers->nom ?? '';
        $filename = "Attestation présence - {$operation->nom} - {$prenom} {$nom}.pdf";

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
