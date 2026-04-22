<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Livewire\Concerns\WithPerPage;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\TransactionCompteService;
use App\Services\TransactionService;
use App\Services\VirementInterneService;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionCompteList extends Component
{
    use RespectsExerciceCloture;
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public ?int $compteId = null;

    public string $dateDebut = '';

    public string $dateFin = '';

    public string $searchTiers = '';

    public string $sortColumn = 'date';

    public string $sortDirection = 'asc';

    public function mount(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();
        $this->dateDebut = "{$exercice}-09-01";
        $this->dateFin = ($exercice + 1).'-08-31';
    }

    public function updatedCompteId(): void
    {
        $this->resetPage();
    }

    public function updatedSearchTiers(): void
    {
        $this->resetPage();
    }

    public function updatedDateDebut(): void
    {
        $this->resetPage();
    }

    public function updatedDateFin(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortColumn = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    public function deleteTransaction(string $sourceType, int $id): void
    {
        match ($sourceType) {
            'depense', 'recette' => $this->deleteTransactionGeneric($id),
            'virement_sortant', 'virement_entrant' => $this->deleteVirement($id),
            default => null,
        };
    }

    private function deleteTransactionGeneric(int $id): void
    {
        $transaction = Transaction::find($id);
        if (! $transaction) {
            return;
        }
        try {
            app(TransactionService::class)->delete($transaction);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function deleteVirement(int $id): void
    {
        $virement = VirementInterne::find($id);
        if (! $virement || $virement->isLockedByRapprochement()) {
            return;
        }
        app(VirementInterneService::class)->delete($virement);
    }

    public function redirectToEdit(string $sourceType, int $id): mixed
    {
        $url = match ($sourceType) {
            'depense', 'recette' => route('comptabilite.transactions').'?edit='.$id,
            'virement_sortant', 'virement_entrant' => route('banques.virements.index').'?edit='.$id,
            default => route('dashboard'),
        };

        return redirect()->to($url);
    }

    public function render(): mixed
    {
        $comptes = CompteBancaire::orderBy('nom')->get();

        if ($this->compteId === null) {
            return view('livewire.transaction-compte-list', [
                'comptes' => $comptes,
                'paginator' => null,
                'soldeAvantPage' => null,
                'showSolde' => false,
                'transactions' => collect(),
            ]);
        }

        $compte = CompteBancaire::findOrFail($this->compteId);

        $result = app(TransactionCompteService::class)->paginate(
            compte: $compte,
            dateDebut: $this->dateDebut ?: null,
            dateFin: $this->dateFin ?: null,
            searchTiers: $this->searchTiers ?: null,
            sortColumn: $this->sortColumn,
            sortDirection: $this->sortDirection,
            perPage: $this->effectivePerPage(),
            page: $this->getPage(),
        );

        $transactions = collect($result['paginator']->items());

        if ($result['showSolde'] && $result['soldeAvantPage'] !== null) {
            $solde = $result['soldeAvantPage'];
            foreach ($transactions as $tx) {
                $solde += (float) $tx->montant;
                $tx->solde_courant = $solde;
            }
        }

        return view('livewire.transaction-compte-list', [
            'comptes' => $comptes,
            'paginator' => $result['paginator'],
            'soldeAvantPage' => $result['soldeAvantPage'],
            'showSolde' => $result['showSolde'],
            'transactions' => $transactions,
        ]);
    }
}
