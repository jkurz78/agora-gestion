<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Services\ExerciceService;

trait RespectsExerciceCloture
{
    public bool $exerciceCloture = false;

    public function bootRespectsExerciceCloture(): void
    {
        $exercice = app(ExerciceService::class)->exerciceAffiche();
        $this->exerciceCloture = $exercice?->isCloture() ?? false;
    }
}
