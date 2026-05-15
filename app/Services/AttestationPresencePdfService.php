<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutPresence;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

final class AttestationPresencePdfService
{
    /**
     * Génère le PDF d'attestation de présence pour une séance donnée.
     *
     * @param  array<int>  $participantIds  IDs des participants à inclure (filtrés ensuite sur Present)
     * @return string Contenu binaire PDF
     */
    public function seance(Operation $operation, Seance $seance, array $participantIds): string
    {
        // Load participants that belong to this operation
        $participants = Participant::with(['tiers', 'donneesMedicales'])
            ->where('operation_id', $operation->id)
            ->whereIn('id', $participantIds)
            ->get();

        // Filter to only those present at this seance (statut is encrypted, must filter in PHP)
        $presences = Presence::where('seance_id', $seance->id)
            ->whereIn('participant_id', $participants->pluck('id'))
            ->get();

        $presentParticipantIds = $presences
            ->filter(fn (Presence $p) => $p->statut === StatutPresence::Present->value)
            ->pluck('participant_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $participants = $participants->filter(
            fn (Participant $p) => in_array((int) $p->id, $presentParticipantIds, true)
        )->values();

        if ($participants->isEmpty()) {
            abort(404, 'Aucun participant présent trouvé.');
        }

        [$association, $headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime, $cachetBase64, $cachetMime] = $this->getAssociationData($operation);

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode((string) file_get_contents($appLogoPath)) : null;

        $pdf = Pdf::loadView('pdf.attestation-presence', [
            'mode' => 'seance',
            'operation' => $operation,
            'seance' => $seance,
            'participants' => $participants,
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
            'cachetBase64' => $cachetBase64,
            'cachetMime' => $cachetMime,
            'appLogoBase64' => $appLogoBase64,
        ])->setPaper('a4', 'portrait');

        PdfFooterRenderer::render($pdf);

        return (string) $pdf->output();
    }

    /**
     * Génère le PDF récapitulatif de présence pour un participant sur toute une opération.
     *
     * @return string Contenu binaire PDF
     */
    public function recap(Operation $operation, Participant $participant): string
    {
        $participant->load(['tiers', 'donneesMedicales']);

        // Get all seances for this operation, ordered by numero
        $allSeances = Seance::where('operation_id', $operation->id)
            ->orderBy('numero')
            ->get();

        $totalSeances = $allSeances->count();

        // Load presences for this participant across all seances (statut is encrypted)
        $presences = Presence::where('participant_id', $participant->id)
            ->whereIn('seance_id', $allSeances->pluck('id'))
            ->get();

        $presentSeanceIds = $presences
            ->filter(fn (Presence $p) => $p->statut === StatutPresence::Present->value)
            ->pluck('seance_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $seancesPresent = $allSeances->filter(
            fn (Seance $s) => in_array((int) $s->id, $presentSeanceIds, true)
        )->values();

        [$association, $headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime, $cachetBase64, $cachetMime] = $this->getAssociationData($operation);

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode((string) file_get_contents($appLogoPath)) : null;

        $pdf = Pdf::loadView('pdf.attestation-presence', [
            'mode' => 'recap',
            'operation' => $operation,
            'participant' => $participant,
            'seancesPresent' => $seancesPresent,
            'totalSeances' => $totalSeances,
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'footerLogoBase64' => $footerLogoBase64,
            'footerLogoMime' => $footerLogoMime,
            'cachetBase64' => $cachetBase64,
            'cachetMime' => $cachetMime,
            'appLogoBase64' => $appLogoBase64,
        ])->setPaper('a4', 'portrait');

        PdfFooterRenderer::render($pdf);

        return (string) $pdf->output();
    }

    /**
     * Resolve association + header/footer logos + cachet as base64.
     *
     * @return array{0: ?Association, 1: ?string, 2: string, 3: ?string, 4: string, 5: ?string, 6: string}
     */
    private function getAssociationData(Operation $operation): array
    {
        $association = CurrentAssociation::get();
        $assoBase64 = null;
        $assoMime = 'image/png';

        $logoFullPath = $association?->brandingLogoFullPath();
        if ($logoFullPath && Storage::disk('local')->exists($logoFullPath)) {
            $assoBase64 = base64_encode((string) Storage::disk('local')->get($logoFullPath));
            $ext = strtolower(pathinfo($logoFullPath, PATHINFO_EXTENSION));
            $assoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        // Type operation logo takes priority in header
        $headerLogoBase64 = $assoBase64;
        $headerLogoMime = $assoMime;
        $footerLogoBase64 = null;
        $footerLogoMime = 'image/png';

        $typeFullPath = $operation->typeOperation?->typeOpLogoFullPath();
        if ($typeFullPath && Storage::disk('local')->exists($typeFullPath)) {
            $headerLogoBase64 = base64_encode((string) Storage::disk('local')->get($typeFullPath));
            $ext = strtolower(pathinfo($typeFullPath, PATHINFO_EXTENSION));
            $headerLogoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
            $footerLogoBase64 = $assoBase64;
            $footerLogoMime = $assoMime;
        }

        // Cachet/signature
        $cachetBase64 = null;
        $cachetMime = 'image/png';
        $cachetFullPath = $association?->brandingCachetFullPath();
        if ($cachetFullPath && Storage::disk('local')->exists($cachetFullPath)) {
            $cachetBase64 = base64_encode((string) Storage::disk('local')->get($cachetFullPath));
            $ext = strtolower(pathinfo($cachetFullPath, PATHINFO_EXTENSION));
            $cachetMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }

        return [$association, $headerLogoBase64, $headerLogoMime, $footerLogoBase64, $footerLogoMime, $cachetBase64, $cachetMime];
    }
}
