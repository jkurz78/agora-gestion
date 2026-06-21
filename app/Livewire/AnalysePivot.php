<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Reglement;
use App\Services\ExerciceService;
use App\Services\Rapports\VentilationFinanciereService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class AnalysePivot extends Component
{
    #[Url(as: 'exercice')]
    public ?int $filterExercice = null;

    public string $mode = 'participants';

    public function mount(string $mode = 'participants'): void
    {
        $this->mode = $mode;

        if ($this->filterExercice === null) {
            $this->filterExercice = app(ExerciceService::class)->current();
        }
    }

    /** @return list<array<string, mixed>> */
    public function getParticipantsDataProperty(): array
    {
        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($this->filterExercice ?? $exerciceService->current());

        return Reglement::query()
            ->join('participants', 'participants.id', '=', 'reglements.participant_id')
            ->join('tiers', 'tiers.id', '=', 'participants.tiers_id')
            ->join('seances', 'seances.id', '=', 'reglements.seance_id')
            ->join('operations', 'operations.id', '=', 'participants.operation_id')
            ->join('type_operations', 'type_operations.id', '=', 'operations.type_operation_id')
            ->leftJoin('presences', function ($join) {
                $join->on('presences.participant_id', '=', 'participants.id')
                    ->on('presences.seance_id', '=', 'seances.id');
            })
            ->whereBetween('seances.date', [$range['start'], $range['end']])
            ->select([
                'operations.nom as Opération',
                'type_operations.nom as Type opération',
                DB::raw("CONCAT(seances.numero, ' - ', seances.titre) as Séance"),
                'seances.date as Date séance',
                'tiers.nom as Nom',
                'tiers.prenom as Prénom',
                'tiers.ville as Ville',
                'participants.date_inscription as Date inscription',
                'reglements.mode_paiement as Mode paiement',
                'reglements.montant_prevu as Montant prévu',
                'presences.statut as Présence',
            ])
            ->get()
            ->map(function ($row) {
                $data = (array) $row->getAttributes();
                $data['Date séance'] = $row->getAttribute('Date séance')
                    ? Carbon::parse($row->getAttribute('Date séance'))->format('d/m/Y')
                    : null;
                $data['Date inscription'] = $row->getAttribute('Date inscription')
                    ? Carbon::parse($row->getAttribute('Date inscription'))->format('d/m/Y')
                    : null;
                $data['Montant prévu'] = (float) ($data['Montant prévu'] ?? 0);

                return $data;
            })
            ->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function getFinancierDataProperty(): array
    {
        $exercice = $this->filterExercice ?? app(ExerciceService::class)->current();

        return app(VentilationFinanciereService::class)->pourExercice($exercice);
    }

    public function exportUrl(): string
    {
        $rapport = $this->mode === 'participants' ? 'analyse-participants' : 'analyse-financier';

        return route('rapports.export', [
            'rapport' => $rapport,
            'format' => 'xlsx',
            'exercice' => $this->filterExercice,
        ]);
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);

        return view('livewire.analyse-pivot', [
            'exerciceYears' => $exerciceService->availableYears(),
            'pivotData' => $this->mode === 'participants'
                ? $this->participantsData
                : $this->financierData,
        ]);
    }
}
