<?php

declare(strict_types=1);

namespace App\Livewire\Banques;

use App\Enums\StatutOperation;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\HelloAssoApiClient;
use App\Services\HelloAssoSyncService;
use App\Services\HelloAssoTiersResolver;
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

    // Étape 2 — Tiers
    public bool $tiersFetched = false;

    public bool $tiersLoading = false;

    public ?string $tiersErreur = null;

    /**
     * @var list<array{firstName: string, lastName: string, email: ?string, address: ?string, city: ?string, zipCode: ?string, country: ?string, tiers_id: ?int, tiers_name: ?string}>
     */
    public array $persons = [];

    /** @var array<int, ?int> */
    public array $selectedTiers = [];

    // Étape 3 — Synchronisation
    /** @var array<string, mixed>|null */
    public ?array $syncResult = null;

    public ?string $syncErreur = null;

    public bool $syncLoading = false;

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

        if ($step === 2 && ! $this->tiersFetched) {
            $this->loadTiers();
        }
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

        if (! $this->tiersFetched) {
            $this->loadTiers();
        }
    }

    public function loadTiers(): void
    {
        $this->tiersLoading = true;
        $this->tiersErreur = null;

        $p = HelloAssoParametres::where('association_id', 1)->first();

        try {
            $client = new HelloAssoApiClient($p);
            $exercice = app(ExerciceService::class)->current();
            $range = app(ExerciceService::class)->dateRange($exercice);
            $orders = $client->fetchOrders($range['start']->toDateString(), $range['end']->toDateString());
        } catch (\RuntimeException $e) {
            $this->tiersErreur = $e->getMessage();
            $this->tiersLoading = false;

            return;
        }

        $resolver = new HelloAssoTiersResolver;
        $extractedPersons = $resolver->extractPersons($orders);
        $result = $resolver->resolve($extractedPersons);

        $personDataByKey = [];
        foreach ($extractedPersons as $personData) {
            $key = strtolower($personData['lastName']).'|'.strtolower($personData['firstName']);
            $personDataByKey[$key] = $personData;
        }

        // Only unlinked persons
        $this->persons = [];
        $this->selectedTiers = [];
        $index = 0;

        foreach ($result['unlinked'] as $person) {
            $suggestedId = count($person['suggestions']) > 0 ? $person['suggestions'][0]['tiers_id'] : null;
            $key = strtolower($person['lastName']).'|'.strtolower($person['firstName']);
            $data = $personDataByKey[$key] ?? [];

            $this->persons[] = [
                'firstName' => $person['firstName'],
                'lastName' => $person['lastName'],
                'email' => $person['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'zipCode' => $data['zipCode'] ?? null,
                'country' => $data['country'] ?? null,
                'tiers_id' => null,
                'tiers_name' => null,
            ];
            $this->selectedTiers[$index] = $suggestedId;
            $index++;
        }

        $this->tiersFetched = true;
        $this->tiersLoading = false;
        $this->updateStepTwoSummary();
    }

    public function associerTiers(int $index): void
    {
        $tiersId = $this->selectedTiers[$index] ?? null;
        $person = $this->persons[$index] ?? null;
        if ($tiersId === null || $person === null) {
            return;
        }

        $tiers = Tiers::findOrFail($tiersId);
        $tiers->update([
            'est_helloasso' => true,
            'helloasso_nom' => $person['lastName'],
            'helloasso_prenom' => $person['firstName'],
        ]);

        $this->persons[$index]['tiers_id'] = $tiers->id;
        $this->persons[$index]['tiers_name'] = $tiers->displayName();
        $this->updateStepTwoSummary();
    }

    public function creerTiers(int $index): void
    {
        $person = $this->persons[$index] ?? null;
        if ($person === null) {
            return;
        }

        $tiers = Tiers::create([
            'type' => 'particulier',
            'nom' => $person['lastName'],
            'prenom' => $person['firstName'],
            'email' => $person['email'],
            'adresse_ligne1' => $person['address'],
            'ville' => $person['city'],
            'code_postal' => $person['zipCode'],
            'pays' => $person['country'],
            'est_helloasso' => true,
            'helloasso_nom' => $person['lastName'],
            'helloasso_prenom' => $person['firstName'],
            'pour_recettes' => true,
        ]);

        $this->persons[$index]['tiers_id'] = $tiers->id;
        $this->persons[$index]['tiers_name'] = $tiers->displayName();
        $this->selectedTiers[$index] = $tiers->id;
        $this->updateStepTwoSummary();
    }

    public function lancerSynchronisation(): void
    {
        $this->updateStepTwoSummary();
        $this->step = 3;
        $this->synchroniser();
    }

    public function synchroniser(): void
    {
        $this->syncLoading = true;
        $this->syncResult = null;
        $this->syncErreur = null;

        $parametres = HelloAssoParametres::where('association_id', 1)->first();
        $exercice = app(ExerciceService::class)->current();
        $exerciceService = app(ExerciceService::class);

        try {
            $client = new HelloAssoApiClient($parametres);
            $range = $exerciceService->dateRange($exercice);
            $from = $range['start']->toDateString();
            $to = $range['end']->toDateString();

            $orders = $client->fetchOrders($from, $to);
        } catch (\RuntimeException $e) {
            $this->syncErreur = $e->getMessage();
            $this->syncLoading = false;

            return;
        }

        $syncService = new HelloAssoSyncService($parametres);
        $syncResult = $syncService->synchroniser($orders, $exercice);

        $this->syncResult = [
            'transactionsCreated' => $syncResult->transactionsCreated,
            'transactionsUpdated' => $syncResult->transactionsUpdated,
            'lignesCreated' => $syncResult->lignesCreated,
            'lignesUpdated' => $syncResult->lignesUpdated,
            'ordersSkipped' => $syncResult->ordersSkipped,
            'errors' => $syncResult->errors,
            'virementsCreated' => 0,
            'virementsUpdated' => 0,
            'rapprochementsCreated' => 0,
            'cashoutsIncomplets' => [],
            'cashoutSkipped' => false,
        ];

        if ($parametres->compte_versement_id === null) {
            $this->syncResult['cashoutSkipped'] = true;
        } else {
            try {
                $rangePrev = $exerciceService->dateRange($exercice - 1);
                $paymentsFrom = $rangePrev['start']->toDateString();

                $payments = $client->fetchPayments($paymentsFrom, $to);
                $cashOuts = HelloAssoApiClient::extractCashOutsFromPayments($payments);
                $cashoutResult = $syncService->synchroniserCashouts($cashOuts, $exercice);

                $this->syncResult['virementsCreated'] = $cashoutResult['virements_created'];
                $this->syncResult['virementsUpdated'] = $cashoutResult['virements_updated'];
                $this->syncResult['rapprochementsCreated'] = $cashoutResult['rapprochements_created'];
                $this->syncResult['cashoutsIncomplets'] = $cashoutResult['cashouts_incomplets'];

                if (! empty($cashoutResult['errors'])) {
                    $this->syncResult['errors'] = array_merge($this->syncResult['errors'], $cashoutResult['errors']);
                }
            } catch (\RuntimeException $e) {
                $this->syncResult['errors'][] = "Cashouts : {$e->getMessage()}";
            }
        }

        $this->syncLoading = false;
        $this->updateStepThreeSummary();
    }

    private function updateStepThreeSummary(): void
    {
        if ($this->syncResult === null) {
            return;
        }
        $parts = [];
        $total = $this->syncResult['transactionsCreated'] + $this->syncResult['transactionsUpdated'];
        if ($total > 0) {
            $parts[] = "{$total} transactions";
        }
        $rap = $this->syncResult['rapprochementsCreated'] ?? 0;
        if ($rap > 0) {
            $parts[] = "{$rap} rapprochement(s)";
        }
        $this->stepThreeSummary = implode(', ', $parts) ?: 'Aucun changement';
    }

    private function updateStepTwoSummary(): void
    {
        $unlinked = collect($this->persons)->whereNull('tiers_id')->count();
        $this->stepTwoSummary = $unlinked > 0 ? "{$unlinked} tiers à lier" : 'Tous les tiers liés';
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
