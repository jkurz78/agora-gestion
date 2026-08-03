<?php

declare(strict_types=1);

namespace App\Livewire\Compta;

use App\Models\Exercice;
use App\Models\Tiers;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class PostesTiersOuverts extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public int $exercice;

    public string $filtreCompte = '';

    public ?int $filtreTiersId = null;

    public ?int $filtreExerciceOrigine = null;

    public string $recherche = '';

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function updatedFiltreCompte(): void
    {
        $this->resetPage();
    }

    public function updatedFiltreTiersId(): void
    {
        $this->resetPage();
    }

    public function updatedFiltreExerciceOrigine(): void
    {
        $this->resetPage();
    }

    public function updatedRecherche(): void
    {
        $this->resetPage();
    }

    public function regler(int $ligneId): void
    {
        $this->dispatch('poste-tiers-reglement:ouvrir', ligneId: $ligneId, exercice: $this->exercice);
    }

    #[On('poste-tiers-reglement:enregistre')]
    #[On('poste-tiers-reglement:annule')]
    public function rafraichir(): void {}

    public function render(): View
    {
        $postes = app(PostesTiersOuvertsService::class)->paginer(
            exercice: $this->exercice,
            compte: $this->filtreCompte !== '' ? $this->filtreCompte : null,
            tiersId: $this->filtreTiersId,
            exerciceOrigine: $this->filtreExerciceOrigine,
            recherche: $this->recherche !== '' ? $this->recherche : null,
            parPage: 20,
            page: $this->getPage(),
        );

        return view('livewire.compta.postes-tiers-ouverts', [
            'postes' => $postes,
            'tiers' => Tiers::query()->orderBy('nom')->orderBy('prenom')->get(),
            'exercices' => Exercice::query()->orderByDesc('annee')->get(),
        ])->layout('layouts.app-sidebar', ['title' => 'Postes tiers ouverts']);
    }
}
