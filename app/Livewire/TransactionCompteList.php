<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Recette;
use App\Models\VirementInterne;
use App\Services\CotisationService;
use App\Services\DepenseService;
use App\Services\DonService;
use App\Services\ExerciceService;
use App\Services\RecetteService;
use App\Services\TransactionCompteService;
use App\Services\VirementInterneService;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionCompteList extends Component
{
    use WithPagination;

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
            'recette' => $this->deleteRecette($id),
            'depense' => $this->deleteDepense($id),
            'don' => $this->deleteDon($id),
            'cotisation' => $this->deleteCotisation($id),
            'virement_sortant', 'virement_entrant' => $this->deleteVirement($id),
            default => null,
        };
    }

    private function deleteRecette(int $id): void
    {
        $recette = Recette::find($id);
        if (! $recette || $recette->isLockedByRapprochement()) {
            return;
        }
        app(RecetteService::class)->delete($recette);
    }

    private function deleteDepense(int $id): void
    {
        $depense = Depense::find($id);
        if (! $depense || $depense->isLockedByRapprochement()) {
            return;
        }
        app(DepenseService::class)->delete($depense);
    }

    private function deleteDon(int $id): void
    {
        $don = Don::find($id);
        if (! $don || $don->isLockedByRapprochement()) {
            return;
        }
        app(DonService::class)->delete($don);
    }

    private function deleteCotisation(int $id): void
    {
        $cotisation = Cotisation::find($id);
        if (! $cotisation || $cotisation->isLockedByRapprochement()) {
            return;
        }
        app(CotisationService::class)->delete($cotisation);
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
            'recette' => route('recettes.index').'?edit='.$id,
            'depense' => route('depenses.index').'?edit='.$id,
            'don' => route('dons.index').'?edit='.$id,
            'virement_sortant', 'virement_entrant' => route('virements.index').'?edit='.$id,
            'cotisation' => $this->buildCotisationEditUrl($id),
            default => route('dashboard'),
        };

        return redirect()->to($url);
    }

    private function buildCotisationEditUrl(int $id): string
    {
        $cotisation = Cotisation::find($id);
        if (! $cotisation) {
            return route('membres.index');
        }

        return route('membres.index').'?membre='.$cotisation->tiers_id.'&edit='.$id;
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
