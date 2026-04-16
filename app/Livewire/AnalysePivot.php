<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Reglement;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
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
        $exerciceService = app(ExerciceService::class);
        $exercice = $this->filterExercice ?? $exerciceService->current();
        $range = $exerciceService->dateRange($exercice);

        return TransactionLigne::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->join('tiers', 'tiers.id', '=', 'transactions.tiers_id')
            ->join('sous_categories', 'sous_categories.id', '=', 'transaction_lignes.sous_categorie_id')
            ->join('categories', 'categories.id', '=', 'sous_categories.categorie_id')
            ->join('comptes_bancaires', 'comptes_bancaires.id', '=', 'transactions.compte_id')
            ->leftJoin('operations', 'operations.id', '=', 'transaction_lignes.operation_id')
            ->leftJoin('type_operations', 'type_operations.id', '=', 'operations.type_operation_id')
            ->whereBetween('transactions.date', [$range['start'], $range['end']])
            ->whereNull('transaction_lignes.deleted_at')
            ->select([
                'operations.nom as Opération',
                'type_operations.nom as Type opération',
                'transaction_lignes.seance as Séance n°',
                DB::raw("CASE WHEN tiers.type = 'entreprise' THEN COALESCE(tiers.entreprise, tiers.nom) ELSE CONCAT(COALESCE(tiers.prenom, ''), ' ', tiers.nom) END as Tiers"),
                'tiers.type as Type tiers',
                'transactions.date as Date',
                'transaction_lignes.montant as Montant',
                'sous_categories.nom as Sous-catégorie',
                'categories.nom as Catégorie',
                'transactions.type as Type',
                'comptes_bancaires.nom as Compte',
            ])
            ->get()
            ->map(function ($row) use ($exercice) {
                $data = (array) $row->getAttributes();
                $date = $row->getAttribute('Date')
                    ? Carbon::parse($row->getAttribute('Date'))
                    : null;
                $data['Date'] = $date?->format('d/m/Y');
                $data['Montant'] = (float) ($data['Montant'] ?? 0);

                // Temporal dimensions
                if ($date) {
                    $data['Mois'] = ucfirst($date->translatedFormat('F Y'));
                    $data['Trimestre'] = $this->trimestreFor($date->month).' '.$exercice.'-'.($exercice + 1);
                    $data['Semestre'] = $this->semestreFor($date->month).' '.$exercice.'-'.($exercice + 1);
                } else {
                    $data['Mois'] = null;
                    $data['Trimestre'] = null;
                    $data['Semestre'] = null;
                }

                return $data;
            })
            ->toArray();
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

    /**
     * Map a month number to its trimestre label within the exercice.
     * The trimestre boundaries are relative to exercice_mois_debut from TenantContext.
     * Offset 1–3 → T1, 4–6 → T2, 7–9 → T3, 10–12 → T4.
     */
    private function trimestreFor(int $month): string
    {
        $moisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;
        $offset = (($month - $moisDebut + 12) % 12) + 1;

        return 'T'.(int) ceil($offset / 3);
    }

    /**
     * Map a month number to its semestre label within the exercice.
     * The semestre boundaries are relative to exercice_mois_debut from TenantContext.
     * Offset 1–6 → S1, 7–12 → S2.
     */
    private function semestreFor(int $month): string
    {
        $moisDebut = TenantContext::current()?->exercice_mois_debut ?? 9;
        $offset = (($month - $moisDebut + 12) % 12) + 1;

        return $offset <= 6 ? 'S1' : 'S2';
    }
}
