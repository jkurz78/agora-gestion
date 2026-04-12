<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Models\Exercice;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;

final class ChangerExercice extends Component
{
    public bool $showCreateModal = false;

    public ?int $nouvelleAnnee = null;

    public bool $showEditModal = false;

    public ?int $editingExerciceId = null;

    public string $editHelloassoUrl = '';

    public function changer(int $annee): void
    {
        $exercice = Exercice::where('annee', $annee)->firstOrFail();
        app(ExerciceService::class)->changerExerciceAffiche($exercice);

        $this->redirect(route('exercices.changer'));
    }

    public function creer(): void
    {
        $this->validate([
            'nouvelleAnnee' => ['required', 'integer', 'min:2000', 'max:2099', 'unique:exercices,annee'],
        ], [
            'nouvelleAnnee.unique' => 'Cet exercice existe déjà.',
        ]);

        app(ExerciceService::class)->creerExercice($this->nouvelleAnnee, auth()->user());

        $this->showCreateModal = false;
        $this->nouvelleAnnee = null;
        session()->flash('success', 'Exercice créé avec succès.');
    }

    public function ouvrirEdition(int $id): void
    {
        $exercice = Exercice::findOrFail($id);
        $this->editingExerciceId = $id;
        $this->editHelloassoUrl = $exercice->helloasso_url ?? '';
        $this->showEditModal = true;
    }

    public function sauvegarderUrl(): void
    {
        $this->validate([
            'editHelloassoUrl' => ['nullable', 'url', 'max:500'],
        ], [
            'editHelloassoUrl.url' => 'L\'URL saisie n\'est pas valide.',
            'editHelloassoUrl.max' => 'L\'URL ne peut pas dépasser 500 caractères.',
        ]);

        $exercice = Exercice::findOrFail($this->editingExerciceId);
        $exercice->update([
            'helloasso_url' => $this->editHelloassoUrl !== '' ? $this->editHelloassoUrl : null,
        ]);

        $this->showEditModal = false;
        $this->editingExerciceId = null;
        $this->editHelloassoUrl = '';
        session()->flash('success', 'URL HelloAsso mise à jour.');
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);

        return view('livewire.exercices.changer-exercice', [
            'exercices' => Exercice::orderByDesc('annee')->with('cloturePar')->get(),
            'exerciceActif' => $exerciceService->current(),
        ]);
    }
}
