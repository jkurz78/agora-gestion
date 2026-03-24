<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;

final class ReouvrirExercice extends Component
{
    public string $commentaire = '';

    public function mount(): void
    {
        $exercice = app(ExerciceService::class)->exerciceAffiche();
        if ($exercice === null || ! $exercice->isCloture()) {
            $this->redirect(route('compta.exercices.changer'));
        }
    }

    public function reouvrir(): void
    {
        $this->validate([
            'commentaire' => ['required', 'string', 'min:5'],
        ], [
            'commentaire.required' => 'Le motif de réouverture est obligatoire.',
            'commentaire.min' => 'Le motif doit contenir au moins 5 caractères.',
        ]);

        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->exerciceAffiche();

        if ($exercice === null || ! $exercice->isCloture()) {
            return;
        }

        $exerciceService->reouvrir($exercice, auth()->user(), $this->commentaire);

        session()->flash('success', "L'exercice {$exercice->label()} a été réouvert.");
        $this->redirect(route('compta.exercices.reouvrir'));
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->exerciceAffiche();

        return view('livewire.exercices.reouvrir-exercice', [
            'exercice' => $exercice,
            'actions' => $exercice?->actions()->with('user')->latest('created_at')->get() ?? collect(),
        ]);
    }
}
