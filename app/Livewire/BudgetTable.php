<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use Livewire\Component;

final class BudgetTable extends Component
{
    public int $exercice;

    public ?int $editingLineId = null;

    public string $editingMontant = '';

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function updatedExercice(): void
    {
        $this->cancelEdit();
    }

    public function addLine(int $sousCategorieId): void
    {
        BudgetLine::create([
            'sous_categorie_id' => $sousCategorieId,
            'exercice' => $this->exercice,
            'montant_prevu' => 0,
        ]);
    }

    public function startEdit(int $lineId): void
    {
        $line = BudgetLine::findOrFail($lineId);
        $this->editingLineId = $lineId;
        $this->editingMontant = (string) $line->montant_prevu;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editingMontant' => ['required', 'numeric', 'min:0'],
        ]);

        $line = BudgetLine::findOrFail($this->editingLineId);
        $line->update(['montant_prevu' => $this->editingMontant]);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingLineId = null;
        $this->editingMontant = '';
    }

    public function deleteLine(int $lineId): void
    {
        BudgetLine::findOrFail($lineId)->delete();
    }

    public function render()
    {
        $budgetService = app(BudgetService::class);
        $exerciceService = app(ExerciceService::class);

        $depenseCategories = Categorie::where('type', TypeCategorie::Depense)
            ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $recetteCategories = Categorie::where('type', TypeCategorie::Recette)
            ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $budgetLines = BudgetLine::forExercice($this->exercice)->get()->keyBy('sous_categorie_id');

        // Compute réalisé for each sous-catégorie that has a budget line
        $realiseData = [];
        $allSousCategories = $depenseCategories->flatMap->sousCategories
            ->merge($recetteCategories->flatMap->sousCategories);

        foreach ($allSousCategories as $sc) {
            $realiseData[$sc->id] = $budgetService->realise($sc->id, $this->exercice);
        }

        return view('livewire.budget-table', [
            'depenseCategories' => $depenseCategories,
            'recetteCategories' => $recetteCategories,
            'budgetLines' => $budgetLines,
            'realiseData' => $realiseData,
            'exercices' => $exerciceService->available(),
            'exerciceService' => $exerciceService,
        ]);
    }
}
