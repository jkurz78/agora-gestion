<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersMergeModal extends Component
{
    public bool $showModal = false;

    public ?int $tiersId = null;

    public string $sourceLabel = '';

    public string $targetLabel = '';

    public string $confirmLabel = '';

    public string $context = '';

    /** @var array<string, mixed> */
    public array $contextData = [];

    /** @var array<string, ?string> */
    public array $sourceData = [];

    /** @var array<string, ?string> */
    public array $targetData = [];

    /** @var array<string, ?string> */
    public array $resultData = [];

    /** @var array<string, bool> */
    public array $sourceBooleans = [];

    public bool $helloassoIdConflict = false;

    /** @var list<string> */
    public const MERGE_FIELDS = [
        'type', 'nom', 'prenom', 'entreprise', 'email',
        'telephone', 'adresse_ligne1', 'code_postal', 'ville', 'pays',
    ];

    /** @var list<string> */
    private const BOOLEAN_FIELDS = ['pour_depenses', 'pour_recettes', 'est_helloasso'];

    #[On('open-tiers-merge')]
    public function openMerge(
        array $sourceData,
        int $tiersId,
        string $sourceLabel,
        string $targetLabel,
        string $confirmLabel,
        string $context,
        array $contextData = [],
    ): void {
        $tiers = Tiers::findOrFail($tiersId);

        $this->tiersId = $tiersId;
        $this->sourceLabel = $sourceLabel;
        $this->targetLabel = $targetLabel;
        $this->confirmLabel = $confirmLabel;
        $this->context = $context;
        $this->contextData = $contextData;

        // Normalize source and target to merge fields
        $this->sourceData = [];
        $this->targetData = [];
        foreach (self::MERGE_FIELDS as $field) {
            $this->sourceData[$field] = $this->normalizeValue($sourceData[$field] ?? null);
            $this->targetData[$field] = $this->normalizeValue($tiers->$field);
        }

        // Capture boolean flags from source for OR logic at save
        $this->sourceBooleans = [];
        foreach (self::BOOLEAN_FIELDS as $field) {
            $this->sourceBooleans[$field] = (bool) ($sourceData[$field] ?? false);
        }

        // Check HelloAsso identity conflict (both are HelloAsso with different nom/prenom)
        $sourceIsHelloasso = (bool) ($sourceData['est_helloasso'] ?? false);
        $sourceHaNom = $sourceData['helloasso_nom'] ?? null;
        $sourceHaPrenom = $sourceData['helloasso_prenom'] ?? null;
        $this->helloassoIdConflict = $sourceIsHelloasso
            && $tiers->est_helloasso
            && ($sourceHaNom !== $tiers->helloasso_nom || $sourceHaPrenom !== $tiers->helloasso_prenom);

        // Pre-fill result: target values, completed by source where target is empty
        $this->resultData = [];
        foreach (self::MERGE_FIELDS as $field) {
            if ($field === 'type') {
                // Type always takes target priority
                $this->resultData[$field] = $this->targetData[$field] !== null && $this->targetData[$field] !== ''
                    ? $this->targetData[$field]
                    : ($this->sourceData[$field] ?? 'particulier');
            } else {
                $this->resultData[$field] = ($this->targetData[$field] !== null && $this->targetData[$field] !== '')
                    ? $this->targetData[$field]
                    : $this->sourceData[$field];
            }
        }

        $this->showModal = true;
    }

    public function confirmMerge(): void
    {
        if ($this->tiersId === null || $this->helloassoIdConflict) {
            return;
        }

        // CSV import context: do NOT write to DB, just dispatch the merge decisions
        if ($this->context === 'csv_import') {
            $this->dispatch('tiers-merge-confirmed',
                tiersId: $this->tiersId,
                context: $this->context,
                contextData: array_merge($this->contextData, [
                    'merge_data' => $this->resultData,
                    'boolean_data' => $this->sourceBooleans,
                ]),
            );
            $this->closeModal();

            return;
        }

        $tiers = Tiers::findOrFail($this->tiersId);

        // Build update data from result fields
        $updateData = [];
        foreach (self::MERGE_FIELDS as $field) {
            $updateData[$field] = $this->resultData[$field] ?? null;
        }

        // OR logic for boolean flags
        foreach (self::BOOLEAN_FIELDS as $field) {
            $updateData[$field] = $tiers->$field || $this->sourceBooleans[$field];
        }

        app(TiersService::class)->update($tiers, $updateData);

        $this->dispatch('tiers-merge-confirmed',
            tiersId: $this->tiersId,
            context: $this->context,
            contextData: $this->contextData,
        );

        $this->closeModal();
    }

    public function createNewTiers(): void
    {
        $this->dispatch('tiers-merge-create-new',
            sourceData: $this->sourceData,
            context: $this->context,
            contextData: $this->contextData,
        );

        $this->closeModal();
    }

    public function cancelMerge(): void
    {
        $this->dispatch('tiers-merge-cancelled', context: $this->context);
        $this->closeModal();
    }

    public function render(): View
    {
        return view('livewire.tiers-merge-modal');
    }

    private function closeModal(): void
    {
        $this->showModal = false;
        $this->tiersId = null;
        $this->sourceData = [];
        $this->targetData = [];
        $this->resultData = [];
        $this->sourceBooleans = [];
        $this->contextData = [];
        $this->helloassoIdConflict = false;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
