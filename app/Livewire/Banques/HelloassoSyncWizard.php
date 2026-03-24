<?php

declare(strict_types=1);

namespace App\Livewire\Banques;

use App\Enums\StatutOperation;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Services\ExerciceService;
use App\Services\HelloAssoApiClient;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class HelloassoSyncWizard extends Component
{
    public int $step = 1;

    public bool $configBloquante = false;

    /** @var list<string> */
    public array $configErrors = [];

    /** @var list<string> */
    public array $configWarnings = [];

    public ?string $stepOneSummary = null;

    public ?string $stepTwoSummary = null;

    public ?string $stepThreeSummary = null;

    // Étape 1 — Formulaires
    public bool $formsLoading = false;

    public bool $formsLoaded = false;

    /** @var array<int, ?int> mapping_id → operation_id */
    public array $formOperations = [];

    public ?string $formErreur = null;

    // Création opération inline
    public ?int $creatingOperationForMapping = null;

    public string $newOperationNom = '';

    public ?string $newOperationDateDebut = null;

    public ?string $newOperationDateFin = null;

    public function mount(): void
    {
        $this->checkConfig();
    }

    public function goToStep(int $step): void
    {
        if ($step >= $this->step) {
            return;
        }

        $this->step = $step;
    }

    public function loadFormulaires(): void
    {
        $this->formsLoading = true;
        $this->formErreur = null;

        $p = HelloAssoParametres::where('association_id', 1)->first();
        if ($p === null || $p->client_id === null) {
            $this->formErreur = 'Paramètres HelloAsso non configurés.';
            $this->formsLoading = false;

            return;
        }

        try {
            $client = new HelloAssoApiClient($p);
            $forms = $client->fetchForms();
        } catch (\RuntimeException $e) {
            $this->formErreur = $e->getMessage();
            $this->formsLoading = false;

            return;
        }

        foreach ($forms as $form) {
            HelloAssoFormMapping::updateOrCreate(
                [
                    'helloasso_parametres_id' => $p->id,
                    'form_slug' => $form['formSlug'],
                ],
                [
                    'form_type' => $form['formType'] ?? '',
                    'form_title' => $form['title'] ?? $form['formSlug'],
                    'start_date' => isset($form['startDate']) ? Carbon::parse($form['startDate'])->toDateString() : null,
                    'end_date' => isset($form['endDate']) ? Carbon::parse($form['endDate'])->toDateString() : null,
                    'state' => $form['state'] ?? null,
                ],
            );
        }

        $this->formOperations = [];
        foreach ($p->formMappings()->get() as $m) {
            $this->formOperations[$m->id] = $m->operation_id;
        }

        $this->formsLoaded = true;
        $this->formsLoading = false;
    }

    public function openCreateOperation(int $mappingId): void
    {
        $mapping = HelloAssoFormMapping::find($mappingId);
        $this->creatingOperationForMapping = $mappingId;
        $this->newOperationNom = $mapping?->form_title ?? '';
        $this->newOperationDateDebut = $mapping?->start_date?->format('Y-m-d');
        $this->newOperationDateFin = $mapping?->end_date?->format('Y-m-d');
    }

    public function cancelCreateOperation(): void
    {
        $this->creatingOperationForMapping = null;
        $this->reset('newOperationNom', 'newOperationDateDebut', 'newOperationDateFin');
    }

    public function storeOperation(): void
    {
        $this->validate([
            'newOperationNom' => 'required|string|max:255',
            'newOperationDateDebut' => 'required|date',
            'newOperationDateFin' => 'nullable|date|after_or_equal:newOperationDateDebut',
        ]);

        $operation = Operation::create([
            'nom' => $this->newOperationNom,
            'date_debut' => $this->newOperationDateDebut,
            'date_fin' => $this->newOperationDateFin,
            'statut' => StatutOperation::EnCours,
        ]);

        // Auto-select in the dropdown
        if ($this->creatingOperationForMapping !== null) {
            $this->formOperations[$this->creatingOperationForMapping] = $operation->id;
        }

        $this->cancelCreateOperation();
    }

    public function sauvegarderEtSuite(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();
        $validIds = $p?->formMappings()->pluck('id')->all() ?? [];

        foreach ($this->formOperations as $mappingId => $operationId) {
            if (! in_array((int) $mappingId, $validIds, true)) {
                continue;
            }
            HelloAssoFormMapping::where('id', $mappingId)->update([
                'operation_id' => $operationId ?: null,
            ]);
        }

        $this->updateStepOneSummary();
        $this->step = 2;
    }

    private function updateStepOneSummary(): void
    {
        $exercice = app(ExerciceService::class)->current();
        $range = app(ExerciceService::class)->dateRange($exercice);
        $exerciceStart = $range['start']->toDateString();

        $p = HelloAssoParametres::where('association_id', 1)->first();
        $filtered = $p?->formMappings()
            ->where(function ($q) use ($exerciceStart) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $exerciceStart);
            })->get() ?? collect();

        $total = $filtered->count();
        $mapped = $filtered->whereNotNull('operation_id')->count();
        $this->stepOneSummary = "{$total} formulaires, {$mapped} mappés";
    }

    private function checkConfig(): void
    {
        $p = HelloAssoParametres::where('association_id', 1)->first();

        if ($p === null || $p->client_id === null) {
            $this->configErrors[] = 'Les credentials HelloAsso ne sont pas encore configurés.';
        }

        if ($p !== null && $p->compte_helloasso_id === null) {
            $this->configErrors[] = 'Le compte bancaire HelloAsso n\'est pas configuré.';
        }

        if (count($this->configErrors) > 0) {
            $this->configBloquante = true;

            return;
        }

        if ($p->compte_versement_id === null) {
            $this->configWarnings[] = 'Le compte de versement n\'est pas configuré — les versements (cashouts) ne seront pas traités.';
        }
        if ($p->sous_categorie_don_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Dons n\'est pas configurée — les dons ne seront pas importés.';
        }
        if ($p->sous_categorie_cotisation_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Cotisations n\'est pas configurée — les cotisations ne seront pas importées.';
        }
        if ($p->sous_categorie_inscription_id === null) {
            $this->configWarnings[] = 'La sous-catégorie Inscriptions n\'est pas configurée — les inscriptions ne seront pas importées.';
        }
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();
        $range = app(ExerciceService::class)->dateRange($exercice);
        $exerciceStart = $range['start']->toDateString();

        $p = HelloAssoParametres::where('association_id', 1)->first();

        $formMappings = $p?->formMappings()
            ->where(function ($q) use ($exerciceStart) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $exerciceStart);
            })
            ->orderBy('form_title')
            ->get() ?? collect();

        return view('livewire.banques.helloasso-sync-wizard', [
            'formMappings' => $formMappings,
            'operations' => Operation::orderBy('nom')->get(),
        ]);
    }
}
