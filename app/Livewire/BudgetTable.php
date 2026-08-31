<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\BudgetLine;
use App\Models\Operation;
use App\Services\Budget\BudgetGelService;
use App\Services\BudgetImportService;
use App\Services\BudgetService;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use Illuminate\Database\Eloquent\Collection;
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

    // ── Gel du budget ─────────────────────────────────────────────────────────
    public bool $showDeverrouillageModal = false;

    #[Validate(['required', 'string', 'min:5'])]
    public string $commentaireDeverrouillage = '';

    // ── Computed ──────────────────────────────────────────────────────────────

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function getBudgetValideProperty(): bool
    {
        return app(BudgetGelService::class)->estValide(app(ExerciceService::class)->current());
    }

    public function getIsAdminProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '') === RoleAssociation::Admin;
    }

    // ── Actions édition ───────────────────────────────────────────────────────

    public function addLine(int $compteId): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->budgetValide) {
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

        $line = BudgetLine::findOrFail($this->editingLineId);

        if (app(BudgetGelService::class)->ligneEstVerrouillee($line)) {
            return;
        }

        $this->validate(['editingMontant' => ['required', 'numeric', 'min:0']]);

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
        if (! $this->canEdit) {
            return;
        }

        app(ExerciceService::class)->assertOuvert(app(ExerciceService::class)->current());

        $line = BudgetLine::findOrFail($lineId);

        if (app(BudgetGelService::class)->ligneEstVerrouillee($line)) {
            return;
        }

        $line->delete();
    }

    public function validerBudget(): void
    {
        if (! $this->isAdmin) {
            return;
        }

        $exercice = app(ExerciceService::class)->exerciceAffiche();

        if ($exercice === null || $exercice->budgetEstValide()) {
            return;
        }

        app(BudgetGelService::class)->valider($exercice, Auth::user());
    }

    public function deverrouillerBudget(): void
    {
        if (! $this->isAdmin) {
            return;
        }

        $this->validate(['commentaireDeverrouillage' => ['required', 'string', 'min:5']]);

        $exercice = app(ExerciceService::class)->exerciceAffiche();

        if ($exercice === null || ! $exercice->budgetEstValide()) {
            return;
        }

        app(BudgetGelService::class)->deverrouiller($exercice, Auth::user(), $this->commentaireDeverrouillage);
        $this->commentaireDeverrouillage = '';
        $this->showDeverrouillageModal = false;
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

        // Comptes de résultat groupés par famille.
        $depenseGroupes = PlanComptableSelecteur::groupesPourType('depense');
        $recetteGroupes = PlanComptableSelecteur::groupesPourType('recette');

        // Enveloppes et ventilations sont lues SÉPARÉMENT : les mêler dans une
        // seule collection ferait écraser l'enveloppe par sa ventilation au
        // keyBy, et doubler tout total.
        $budgetLines = BudgetLine::forExercice($exercice)->enveloppes()->get()->keyBy('compte_id');
        $ventilations = BudgetLine::forExercice($exercice)
            ->ventilations()
            ->with('operation')
            ->get()
            ->groupBy('compte_id');

        // Deux requêtes groupées, au lieu d'un appel par compte.
        $realiseData = $budgetService->realiseParCompte($exercice);
        $realiseParOperation = $budgetService->realiseParCompteEtOperation($exercice);

        return view('livewire.budget-table', [
            'depenseGroupes' => $depenseGroupes,
            'recetteGroupes' => $recetteGroupes,
            'budgetLines' => $budgetLines,
            'ventilations' => $ventilations,
            'realiseData' => $realiseData,
            'realiseParOperation' => $realiseParOperation,
            'operationsSansBudget' => $this->operationsSansBudget($exercice),
            'exerciceLabel' => app(ExerciceService::class)->label($exercice),
            'exerciceModele' => app(ExerciceService::class)->exerciceAffiche(),
            'exportExerciceCourant' => $exercice,
            'exportExerciceSuivant' => $exercice + 1,
        ]);
    }

    /**
     * Opérations ouvertes chevauchant l'exercice et n'ayant aucune ligne de budget.
     *
     * Périmètre volontairement plus étroit que le sélecteur de la modale : celui-ci
     * ignore les dates (une opération pluriannuelle reste imputable), alors qu'ici
     * signaler une opération hors période comme « sans budget » serait un faux
     * positif permanent. Le sélecteur est permissif, la relance est prudente.
     *
     * @return Collection<int, Operation>
     */
    private function operationsSansBudget(int $exercice): mixed
    {
        $budgetees = BudgetLine::forExercice($exercice)
            ->ventilations()
            ->pluck('operation_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return Operation::query()
            ->proposableALaSaisie()
            ->forExercice($exercice)
            ->when($budgetees !== [], fn ($q) => $q->whereNotIn('id', $budgetees))
            ->orderBy('nom')
            ->get();
    }
}
