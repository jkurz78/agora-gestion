<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Enums\StatutFacture;
use App\Models\Facture;
use App\Services\ExerciceService;
use App\Services\FactureService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

final class FactureList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $filterStatut = '';

    public string $filterTiers = '';

    public ?int $newFactureTiersId = null;

    public bool $showCreerModal = false;

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function updatedFilterStatut(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTiers(): void
    {
        $this->resetPage();
    }

    public function creer(?int $tiersId = null): mixed
    {
        if (! $this->canEdit) {
            return null;
        }

        if ($tiersId === null) {
            $this->showCreerModal = true;
            $this->resetValidation('newFactureTiersId');

            return null;
        }

        $this->newFactureTiersId = $tiersId;

        $this->validate([
            'newFactureTiersId' => ['required', 'exists:tiers,id'],
        ]);

        $facture = app(FactureService::class)->creer($this->newFactureTiersId);

        return $this->redirect(route('facturation.factures.edit', $facture));
    }

    public function supprimer(int $id): void
    {
        if (! $this->canEdit) {
            return;
        }

        try {
            $facture = Facture::findOrFail($id);
            app(FactureService::class)->supprimerBrouillon($facture);
            session()->flash('success', 'Brouillon supprime.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Facture::with('tiers')
            ->where('exercice', $exercice);

        // Pre-filter by statut in SQL
        if ($this->filterStatut !== '' && in_array($this->filterStatut, ['brouillon', 'annulee'])) {
            $query->where('statut', $this->filterStatut);
        } elseif (in_array($this->filterStatut, ['validee', 'acquittee', 'non_reglee'])) {
            // All three require statut = validee in SQL; PHP post-filter distinguishes them
            $query->where('statut', StatutFacture::Validee);
        }

        if ($this->filterTiers !== '') {
            $search = $this->filterTiers;
            $query->whereHas('tiers', function ($q) use ($search): void {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('entreprise', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('date')->orderByDesc('id');

        // For acquittee/non_reglee filters we need PHP post-filtering
        if (in_array($this->filterStatut, ['acquittee', 'non_reglee'])) {
            $allFactures = $query->get();

            $filtered = $allFactures->filter(function (Facture $f) {
                $acquittee = $f->isAcquittee();

                return $this->filterStatut === 'acquittee' ? $acquittee : ! $acquittee;
            });

            return view('livewire.facture-list', [
                'factures' => new LengthAwarePaginator(
                    $filtered->forPage($this->getPage(), 20),
                    $filtered->count(),
                    20,
                    $this->getPage(),
                    ['path' => request()->url()],
                ),
            ]);
        }

        $factures = $query->paginate(20);

        return view('livewire.facture-list', [
            'factures' => $factures,
        ]);
    }
}
