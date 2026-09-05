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
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use App\Services\Rapports\BudgetEcranBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
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

    public string $exportSourceExercice = '';

    /**
     * Volontairement SANS #[Validate] — voir le commentaire équivalent sur
     * $commentaireDeverrouillage ci-dessous : un validate() nu ailleurs dans
     * ce composant (importBudget() en a un, explicite, mais un futur ajout
     * pourrait ne pas l'être) embarquerait ces deux propriétés. C'est
     * exactement l'incident de juillet : l'import ne partait jamais, l'erreur
     * s'affichant dans une modale d'import fermée.
     */
    public bool $exportAvecRealise = false;

    public bool $exportAvecVentilations = false;

    // ── Import ────────────────────────────────────────────────────────────────
    public bool $showImportPanel = false;

    #[Validate(['file', 'mimes:csv,txt,xlsx', 'max:2048'])]
    public ?TemporaryUploadedFile $budgetFile = null;

    /** @var list<array{line: int, message: string}>|null */
    public ?array $importErrors = null;

    public ?string $importSuccess = null;

    /** @var array{enveloppes: int, ventilations: int, montant_ventile: float, operations: int}|null */
    public ?array $compteRenduImport = null;

    // ── Gel du budget ─────────────────────────────────────────────────────────
    public bool $showDeverrouillageModal = false;

    /**
     * Volontairement SANS attribut #[Validate] : ce champ n'est obligatoire que
     * lors d'un déverrouillage, et deverrouillerBudget() le valide explicitement.
     * Un #[Validate] ici serait pris en compte par tout appel à validate() sans
     * argument — importBudget() échouait ainsi en silence sur un commentaire
     * vide, l'erreur n'étant rendue que dans la modale de déverrouillage fermée.
     */
    public string $commentaireDeverrouillage = '';

    public function mount(): void
    {
        // Défaut N-1 : l'AG se tient en octobre ou novembre, le réalisé de
        // l'exercice courant n'aurait que deux mois quand on amorce le budget N.
        $this->exportSourceExercice = (string) (app(ExerciceService::class)->current() - 1);
    }

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

        // $compteId est entièrement piloté par le navigateur : un appel forgé
        // pourrait sinon créer une enveloppe sur un compte d'une autre
        // association, inactif, ou hors classe 6-7 — aucun de ces contrôles
        // n'existait ici. Même liste blanche que celle utilisée par
        // BudgetAffectationModal::enregistrer().
        if (! in_array($compteId, PlanComptableSelecteur::comptesAutorisesPourTypes(['depense', 'recette']), true)) {
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

        $exercice = app(ExerciceService::class)->current();
        app(ExerciceService::class)->assertOuvert($exercice);

        $line = BudgetLine::findOrFail($this->editingLineId);

        // Le scope tenant filtre l'association, mais pas l'exercice de la ligne
        // elle-même : assertOuvert() ci-dessus ne contrôle QUE l'exercice
        // affiché. Un appel forgé (editingLineId poussé sans passer par
        // startEdit()) pourrait sinon viser une ligne d'un exercice clôturé —
        // ligneEstVerrouillee() ne teste que le gel du budget, jamais la
        // clôture. Sortie silencieuse, cohérente avec les autres gardes de
        // cette méthode.
        if ((int) $line->exercice !== (int) $exercice) {
            return;
        }

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

        $exercice = app(ExerciceService::class)->current();
        app(ExerciceService::class)->assertOuvert($exercice);

        $line = BudgetLine::findOrFail($lineId);

        // Voir le commentaire équivalent dans saveEdit() : le scope tenant ne
        // filtre pas l'exercice de la ligne.
        if ((int) $line->exercice !== (int) $exercice) {
            return;
        }

        if (app(BudgetGelService::class)->ligneEstVerrouillee($line)) {
            return;
        }

        $line->delete();
    }

    public function validerBudget(): void
    {
        // $exerciceCloture évite d'atteindre BudgetGelService::valider() dont
        // la garde de clôture lève ExerciceCloturedException, non rattrapée
        // ici — elle produirait une 500 au lieu d'un no-op silencieux.
        if (! $this->isAdmin || $this->exerciceCloture) {
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
        // Voir le commentaire équivalent dans validerBudget().
        if (! $this->isAdmin || $this->exerciceCloture) {
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
            'exportSourceExercice' => ['required', 'integer'],
        ]);

        $exerciceService = app(ExerciceService::class);
        $exerciceCible = $this->exportExercice === 'suivant'
            ? $exerciceService->current() + 1
            : $exerciceService->current();

        $url = route('comptabilite.budget.export', [
            'format' => $this->exportFormat,
            'exercice' => $exerciceCible,
            'source' => $this->exportSource,
            'source_exercice' => $this->exportSourceExercice,
        ]);

        $this->js("window.location.href = '{$url}'");
        $this->showExportModal = false;
    }

    /**
     * PDF imprimable — passe par le registre de rapports (RapportExportController),
     * jamais par le gabarit d'aller-retour CSV/XLSX ci-dessus : ce sont deux
     * usages distincts (document à voter en AG vs fichier réimportable).
     *
     * Toujours l'exercice COURANT affiché à l'écran (pas exportExercice, qui
     * ne sert qu'au pré-remplissage du gabarit réimportable vers l'exercice
     * suivant) : le PDF est un instantané du budget affiché, jamais d'un
     * exercice qui n'existe pas encore.
     */
    public function exportPdf(): void
    {
        $url = route('rapports.export', [
            'rapport' => 'budget',
            'format' => 'pdf',
            'exercice' => app(ExerciceService::class)->current(),
            'realise' => $this->exportAvecRealise ? 1 : 0,
            'ventilations' => $this->exportAvecVentilations ? 1 : 0,
        ]);

        $this->js("window.location.href = '{$url}'");
        $this->showExportModal = false;
    }

    // ── Actions import ────────────────────────────────────────────────────────

    public function toggleImportPanel(): void
    {
        $this->showImportPanel = ! $this->showImportPanel;

        if ($this->showImportPanel) {
            $this->compteRenduImport = app(BudgetImportService::class)->compteRendu(app(ExerciceService::class)->current());
        } else {
            $this->importErrors = null;
            $this->importSuccess = null;
            $this->budgetFile = null;
            $this->compteRenduImport = null;
            $this->resetValidation();
        }
    }

    public function importBudget(): void
    {
        if (! $this->canEdit) {
            return;
        }

        // Validation explicite du seul champ concerné : un validate() nu
        // embarquerait toute autre règle #[Validate] du composant.
        $this->validate([
            'budgetFile' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:2048'],
        ]);

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

    #[On('budget-affecte')]
    public function rafraichir(): void
    {
        // Le render() suivant relit tout : rien à faire ici.
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        // Les six requêtes (comptes groupés, enveloppes, ventilations,
        // réalisé) sont partagées avec le PDF imprimable de cet écran — voir
        // App\Services\Rapports\BudgetEcranBuilder.
        $donnees = app(BudgetEcranBuilder::class)->pourExercice($exercice);

        return view('livewire.budget-table', array_merge($donnees, [
            'operationsSansBudget' => $this->operationsSansBudget($exercice),
            'exerciceLabel' => app(ExerciceService::class)->label($exercice),
            'exerciceModele' => app(ExerciceService::class)->exerciceAffiche(),
            'exportExerciceCourant' => $exercice,
            'exportExerciceSuivant' => $exercice + 1,
            'anneesDisponibles' => app(ExerciceService::class)->availableYears(),
        ]));
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
