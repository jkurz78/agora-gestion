<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\Operation;
use App\Models\Reglement;
use App\Models\Seance;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

final class ReglementTable extends Component
{
    public Operation $operation;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function cycleModePaiement(int $participantId, int $seanceId): void
    {
        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        $reglement = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seance->id)
            ->first();

        if ($reglement?->remise_id !== null) {
            return;
        }

        $current = $reglement?->mode_paiement;
        $next = ModePaiement::nextReglementMode($current);

        Reglement::updateOrCreate(
            ['participant_id' => $participantId, 'seance_id' => $seance->id],
            ['mode_paiement' => $next]
        );
    }

    public function updateMontant(int $participantId, int $seanceId, string $montant): void
    {
        $seance = Seance::where('operation_id', $this->operation->id)->findOrFail($seanceId);

        $existing = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seance->id)
            ->first();

        if ($existing?->remise_id !== null) {
            return;
        }

        $parsed = (float) str_replace(',', '.', $montant);

        Reglement::updateOrCreate(
            ['participant_id' => $participantId, 'seance_id' => $seance->id],
            ['montant_prevu' => $parsed]
        );
    }

    public function copierLigne(int $participantId): void
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        if ($seances->isEmpty()) {
            return;
        }

        $source = Reglement::where('participant_id', $participantId)
            ->where('seance_id', $seances->first()->id)
            ->first();

        if (! $source) {
            return;
        }

        foreach ($seances->skip(1) as $seance) {
            $existing = Reglement::where('participant_id', $participantId)
                ->where('seance_id', $seance->id)
                ->first();

            if ($existing?->remise_id !== null) {
                continue;
            }

            Reglement::updateOrCreate(
                ['participant_id' => $participantId, 'seance_id' => $seance->id],
                [
                    'mode_paiement' => $source->mode_paiement,
                    'montant_prevu' => $source->montant_prevu,
                ]
            );
        }
    }

    public function render(): View
    {
        $seances = Seance::where('operation_id', $this->operation->id)
            ->orderBy('numero')
            ->get();

        $participants = $this->operation->participants()
            ->with('tiers')
            ->get()
            ->sortBy(fn ($p) => mb_strtolower(($p->tiers->nom ?? '').' '.($p->tiers->prenom ?? '')))
            ->values();

        // Load all reglements in one query, indexed by "participantId-seanceId"
        $seanceIds = $seances->pluck('id');
        $reglements = Reglement::whereIn('seance_id', $seanceIds)->get();

        $reglementMap = [];
        foreach ($reglements as $r) {
            $reglementMap[$r->participant_id.'-'.$r->seance_id] = $r;
        }

        // Compute realized amounts from transaction_lignes
        $realiseMap = $this->computeRealise($seances, $participants);

        return view('livewire.reglement-table', [
            'seances' => $seances,
            'participants' => $participants,
            'reglementMap' => $reglementMap,
            'realiseMap' => $realiseMap,
        ]);
    }

    /**
     * @return array<string, float> keyed by "participantId-seanceId"
     */
    private function computeRealise(\Illuminate\Database\Eloquent\Collection $seances, Collection $participants): array
    {
        if ($seances->isEmpty() || $participants->isEmpty()) {
            return [];
        }

        $tiersIds = $participants->pluck('tiers_id')->unique()->values();
        $seanceNumeros = $seances->pluck('numero', 'id'); // id => numero

        $rows = DB::table('transaction_lignes')
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->where('transactions.type', 'recette')
            ->whereIn('transactions.tiers_id', $tiersIds)
            ->where('transaction_lignes.operation_id', $this->operation->id)
            ->whereNotNull('transaction_lignes.seance')
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->select(
                'transactions.tiers_id',
                'transaction_lignes.seance as seance_numero',
                DB::raw('SUM(transaction_lignes.montant) as total')
            )
            ->groupBy('transactions.tiers_id', 'transaction_lignes.seance')
            ->get();

        // Build tiers_id => participant_id mapping
        $tiersToParticipant = $participants->pluck('id', 'tiers_id');
        // Build seance numero => seance id mapping
        $numeroToSeanceId = $seanceNumeros->flip(); // numero => id

        $map = [];
        foreach ($rows as $row) {
            $participantId = $tiersToParticipant[$row->tiers_id] ?? null;
            $seanceId = $numeroToSeanceId[$row->seance_numero] ?? null;

            if ($participantId !== null && $seanceId !== null) {
                $map[$participantId.'-'.$seanceId] = (float) $row->total;
            }
        }

        return $map;
    }
}
