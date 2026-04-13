<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class RemiseBancaireSelection extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedTransactionIds = [];

    public string $filterOperation = '';

    public string $filterTiers = '';

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;

        $this->selectedTransactionIds = Transaction::where('remise_id', $remise->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()?->role?->canWrite(Espace::Gestion) ?? false;
    }

    public function toggleAll(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $visibleIds = $this->buildBaseQuery()->pluck('id')->all();
        $allSelected = collect($visibleIds)->every(fn ($id) => in_array((int) $id, $this->selectedTransactionIds, true));

        $this->selectedTransactionIds = $allSelected ? [] : array_map('intval', $visibleIds);
    }

    public function toggleTransaction(int $id): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (in_array($id, $this->selectedTransactionIds, true)) {
            $this->selectedTransactionIds = array_values(array_diff($this->selectedTransactionIds, [$id]));
        } else {
            $this->selectedTransactionIds[] = $id;
        }
    }

    public function valider(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (count($this->selectedTransactionIds) === 0) {
            $this->addError('selection', 'Sélectionnez au moins une transaction.');

            return;
        }

        app(RemiseBancaireService::class)->enregistrerBrouillon($this->remise, $this->selectedTransactionIds);
        $this->redirect(route('banques.remises.show', $this->remise));
    }

    public function render(): View
    {
        // Base query — no text filters — used for filter options and toggleAll
        $allTransactions = $this->buildBaseQuery()
            ->with(['tiers', 'lignes.operation'])
            ->orderByDesc('date')
            ->get();

        // Operations for filter dropdown (from the full unfiltered set)
        $operations = $allTransactions
            ->flatMap(fn ($tx) => $tx->lignes->map->operation->filter())
            ->unique('id')
            ->sortBy('nom')
            ->values();

        // Apply text filters in-memory
        $transactions = $allTransactions;

        if ($this->filterTiers !== '') {
            $search = mb_strtolower($this->filterTiers);
            $transactions = $transactions->filter(function ($tx) use ($search): bool {
                $tiers = $tx->tiers;
                if ($tiers === null) {
                    return false;
                }

                return str_contains(mb_strtolower($tiers->nom ?? ''), $search)
                    || str_contains(mb_strtolower($tiers->prenom ?? ''), $search)
                    || str_contains(mb_strtolower($tiers->entreprise ?? ''), $search);
            });
        }

        if ($this->filterOperation !== '') {
            $transactions = $transactions->filter(
                fn ($tx) => $tx->lignes->contains(
                    fn ($ligne) => (int) $ligne->operation_id === (int) $this->filterOperation
                )
            );
        }

        $totalSelected = (float) $allTransactions
            ->whereIn('id', $this->selectedTransactionIds)
            ->sum('montant_total');

        $countSelected = count($this->selectedTransactionIds);

        return view('livewire.remise-bancaire-selection', [
            'transactions' => $transactions->values(),
            'totalSelected' => $totalSelected,
            'countSelected' => $countSelected,
            'operations' => $operations,
        ]);
    }

    /**
     * @return Builder<Transaction>
     */
    private function buildBaseQuery(): Builder
    {
        return Transaction::where('type', TypeTransaction::Recette->value)
            ->where('mode_paiement', $this->remise->mode_paiement->value)
            ->whereIn('statut_reglement', [
                StatutReglement::EnAttente->value,
                StatutReglement::Recu->value,
            ])
            ->where(function ($q): void {
                $q->whereNull('remise_id')
                    ->orWhere('remise_id', $this->remise->id);
            });
    }
}
