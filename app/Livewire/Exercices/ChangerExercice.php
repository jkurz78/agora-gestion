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

    public function changer(int $annee): void
    {
        $exercice = Exercice::where('annee', $annee)->firstOrFail();
        app(ExerciceService::class)->changerExerciceAffiche($exercice);

        $this->redirect(route('compta.exercices.changer'));
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

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);

        return view('livewire.exercices.changer-exercice', [
            'exercices' => Exercice::orderByDesc('annee')->with('cloturePar')->get(),
            'exerciceActif' => $exerciceService->current(),
        ]);
    }
}
