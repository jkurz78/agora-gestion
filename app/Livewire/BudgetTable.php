<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\BudgetLine;
use App\Services\BudgetImportService;
use App\Services\BudgetService;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class BudgetTable extends Component
{
    use RespectsExerciceCloture;
    use WithFileUploads;

    // ── Edition inline ────────────────────────────────────────────────────────
    public ?int $editingLineId = null;

    public string $editingMontant = '';

    // ── Export ────────────────────────────────────────────────────────────────
    public bool $showExportModal = false;

    public string $exportFormat = 'xlsx';

    public string $exportExercice = 'courant'; // 'courant' | 'suivant'

    public string $exportSource = 'courant'; // 'zero' | 'courant' | 'budget'

    // ── Import ────────────────────────────────────────────────────────────────
    public bool $showImportPanel = false;

    #[Validate(['file', 'mimes:csv,txt,xlsx', 'max:2048'])]
    public ?TemporaryUploadedFile $budgetFile = null;

    /** @var list<array{line: int, message: string}>|null */
    public ?array $importErrors = null;

    public ?string $importSuccess = null;

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    // ── Actions édition ───────────────────────────────────────────────────────

    public function addLine(int $compteId): void
    {
        if (! $this->canEdit) {
            return;
        }

        app(ExerciceService::class)->assertOuvert(app(ExerciceService::class)->current());

        BudgetLine::create([
            'compte_id' => $compteId,
            'exercice' => app(ExerciceService::class)->current(),
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
        if (! $this->canEdit) {
            return;
        }

        app(ExerciceService::class)->assertOuvert(app(ExerciceService::class)->current());

        $this->validate(['editingMontant' => ['required', 'numeric', 'min:0']]);

        BudgetLine::findOrFail($this->editingLineId)->update(['montant_prevu' => $this->editingMontant]);
        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingLineId = null;
        $this->editingMontant = '';
    }

    public function deleteLine(int $lineId): void
    {
        if (! $this->canEdit) {
            return;
        }

        app(ExerciceService::class)->assertOuvert(app(ExerciceService::class)->current());

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
        $this->validate([
            'exportFormat' => ['required', 'in:csv,xlsx'],
            'exportSource' => ['required', 'in:zero,courant,budget'],
        ]);

        $exerciceService = app(ExerciceService::class);
        $exerciceCible = $this->exportExercice === 'suivant'
            ? $exerciceService->current() + 1
            : $exerciceService->current();

        $url = route('comptabilite.budget.export', [
            'format' => $this->exportFormat,
            'exercice' => $exerciceCible,
            'source' => $this->exportSource,
        ]);

        $this->js("window.location.href = '{$url}'");
        $this->showExportModal = false;
    }

    // ── Actions import ────────────────────────────────────────────────────────

    public function toggleImportPanel(): void
    {
        $this->showImportPanel = ! $this->showImportPanel;

        if (! $this->showImportPanel) {
            $this->importErrors = null;
            $this->importSuccess = null;
            $this->budgetFile = null;
            $this->resetValidation();
        }
    }

    public function importBudget(): void
    {
        if (! $this->canEdit) {
            return;
        }

        $this->validate();

        $exercice = app(ExerciceService::class)->current();
        $result = app(BudgetImportService::class)->import($this->budgetFile, $exercice);

        if ($result->success) {
            $exerciceLabel = app(ExerciceService::class)->label($exercice);
            $this->importSuccess = "{$result->linesImported} lignes importées pour l'exercice {$exerciceLabel}.";
            $this->importErrors = null;
            $this->budgetFile = null;
            $this->resetValidation();
        } else {
            $this->importErrors = $result->errors;
            $this->importSuccess = null;
        }
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $budgetService = app(BudgetService::class);
        $exercice = app(ExerciceService::class)->current();

        // DC-8 : comptes de résultat groupés par famille (remplace Categorie →
        // sousCategories comme structure d'affichage).
        $depenseGroupes = PlanComptableSelecteur::groupesPourType('depense');
        $recetteGroupes = PlanComptableSelecteur::groupesPourType('recette');

        $budgetLines = BudgetLine::forExercice($exercice)->get()->keyBy('compte_id');

        $tousComptes = $depenseGroupes->flatMap(fn (array $g) => $g['comptes'])
            ->merge($recetteGroupes->flatMap(fn (array $g) => $g['comptes']));

        $realiseData = [];
        foreach ($tousComptes as $compte) {
            $realiseData[$compte->id] = $budgetService->realise((int) $compte->id, $exercice);
        }

        return view('livewire.budget-table', [
            'depenseGroupes' => $depenseGroupes,
            'recetteGroupes' => $recetteGroupes,
            'budgetLines' => $budgetLines,
            'realiseData' => $realiseData,
            'exerciceLabel' => app(ExerciceService::class)->label($exercice),
            'exportExerciceCourant' => $exercice,
            'exportExerciceSuivant' => $exercice + 1,
        ]);
    }
}
