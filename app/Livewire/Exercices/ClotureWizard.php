<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Services\ClotureCheckService;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;

final class ClotureWizard extends Component
{
    public int $step = 1;

    public int $annee;

    public bool $peutCloturer = false;

    public function mount(): void
    {
        $exerciceService = app(ExerciceService::class);
        $this->annee = $exerciceService->current();

        $exercice = $exerciceService->exerciceAffiche();
        if ($exercice?->isCloture()) {
            $this->redirect(route('exercices.changer'));

            return;
        }

        $this->runChecks();
    }

    public function runChecks(): void
    {
        $result = app(ClotureCheckService::class)->executer($this->annee);
        $this->peutCloturer = $result->peutCloturer();
    }

    public function suite(): void
    {
        if ($this->step === 1) {
            $this->runChecks();
            if (! $this->peutCloturer) {
                return;
            }
            $this->step = 2;

            return;
        }

        if ($this->step === 2) {
            $this->step = 3;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
            if ($step === 1) {
                $this->runChecks();
            }
        }
    }

    public function cloturer(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->exerciceAffiche();

        if ($exercice === null || $exercice->isCloture()) {
            return;
        }

        $exerciceService->cloturer($exercice, auth()->user());

        session()->flash('success', "L'exercice {$exercice->label()} a été clôturé avec succès.");
        $this->redirect(route('exercices.changer'));
    }

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $checkResult = app(ClotureCheckService::class)->executer($this->annee);

        return view('livewire.exercices.cloture-wizard', [
            'exerciceLabel' => $exerciceService->label($this->annee),
            'checkResult' => $checkResult,
        ]);
    }
}
