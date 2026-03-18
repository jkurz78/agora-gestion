<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TypeCategorie;
use App\Models\BudgetLine;
use App\Models\Categorie;
use App\Services\BudgetExportService;
use App\Services\BudgetImportService;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class BudgetTable extends Component
{
    use WithFileUploads;

    // ── Edition inline ────────────────────────────────────────────────────────
    public ?int $editingLineId = null;
    public string $editingMontant = '';

    // ── Export ────────────────────────────────────────────────────────────────
    public bool $showExportModal = false;
    public string $exportFormat   = 'csv';
    public string $exportExercice = 'courant'; // 'courant' | 'suivant'
    public string $exportSource   = 'courant'; // 'zero' | 'courant' | 'n1'

    // ── Import ────────────────────────────────────────────────────────────────
    public bool $showImportPanel = false;

    #[Validate(['file', 'mimes:csv,txt,xlsx', 'max:2048'])]
    public ?TemporaryUploadedFile $budgetFile = null;

    /** @var list<array{line: int, message: string}>|null */
    public ?array $importErrors = null;

    public ?string $importSuccess = null;

    // ── Actions édition ───────────────────────────────────────────────────────

    public function addLine(int $sousCategorieId): void
    {
        BudgetLine::create([
            'sous_categorie_id' => $sousCategorieId,
            'exercice'          => app(ExerciceService::class)->current(),
            'montant_prevu'     => 0,
        ]);
    }

    public function startEdit(int $lineId): void
    {
        $line                  = BudgetLine::findOrFail($lineId);
        $this->editingLineId   = $lineId;
        $this->editingMontant  = (string) $line->montant_prevu;
    }

    public function saveEdit(): void
    {
        $this->validate(['editingMontant' => ['required', 'numeric', 'min:0']]);

        BudgetLine::findOrFail($this->editingLineId)->update(['montant_prevu' => $this->editingMontant]);
        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingLineId  = null;
        $this->editingMontant = '';
    }

    public function deleteLine(int $lineId): void
    {
        BudgetLine::findOrFail($lineId)->delete();
    }

    // ── Actions export ────────────────────────────────────────────────────────

    public function openExportModal(): void
    {
        $this->showExportModal = true;
    }

    public function closeExportModal(): void
    {
        $this->showExportModal = false;
    }

    public function export(): void
    {
        $exerciceService = app(ExerciceService::class);
        $exerciceCible   = $this->exportExercice === 'suivant'
            ? $exerciceService->current() + 1
            : $exerciceService->current();

        $url = route('budget.export', [
            'format'   => $this->exportFormat,
            'exercice' => $exerciceCible,
            'source'   => $this->exportSource,
        ]);

        $this->js("window.location.href = '{$url}'");
        $this->showExportModal = false;
    }

    // ── Actions import ────────────────────────────────────────────────────────

    public function toggleImportPanel(): void
    {
        $this->showImportPanel = !$this->showImportPanel;

        if (!$this->showImportPanel) {
            $this->importErrors  = null;
            $this->importSuccess = null;
            $this->budgetFile    = null;
            $this->resetValidation();
        }
    }

    public function importBudget(): void
    {
        $this->validate();

        $exercice = app(ExerciceService::class)->current();
        $result   = app(BudgetImportService::class)->import($this->budgetFile, $exercice);

        if ($result->success) {
            $exerciceLabel       = app(ExerciceService::class)->label($exercice);
            $this->importSuccess = "{$result->linesImported} lignes importées pour l'exercice {$exerciceLabel}.";
            $this->importErrors  = null;
            $this->budgetFile    = null;
            $this->resetValidation();
        } else {
            $this->importErrors  = $result->errors;
            $this->importSuccess = null;
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): \Illuminate\View\View
    {
        $budgetService = app(BudgetService::class);
        $exercice      = app(ExerciceService::class)->current();

        $depenseCategories = Categorie::where('type', TypeCategorie::Depense)
            ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $recetteCategories = Categorie::where('type', TypeCategorie::Recette)
            ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $budgetLines = BudgetLine::forExercice($exercice)->get()->keyBy('sous_categorie_id');

        $realiseData    = [];
        $allSousCategories = $depenseCategories->flatMap->sousCategories
            ->merge($recetteCategories->flatMap->sousCategories);

        foreach ($allSousCategories as $sc) {
            $realiseData[$sc->id] = $budgetService->realise($sc->id, $exercice);
        }

        return view('livewire.budget-table', [
            'depenseCategories' => $depenseCategories,
            'recetteCategories' => $recetteCategories,
            'budgetLines'       => $budgetLines,
            'realiseData'       => $realiseData,
            'exerciceLabel'     => app(ExerciceService::class)->label($exercice),
            'exportExerciceCourant' => $exercice,
            'exportExerciceSuivant' => $exercice + 1,
        ]);
    }
}
