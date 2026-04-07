<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\TiersCsvImportService;
use App\Services\TiersCsvMatcherService;
use App\Services\TiersCsvParserService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class ImportCsvTiers extends Component
{
    use WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $importFile = null;

    public string $phase = 'upload';

    /** @var list<array{line: int, message: string}> */
    public array $parseErrors = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public string $originalFilename = '';

    public ?int $resolvingIndex = null;

    /** @var array<string, mixed>|null */
    public ?array $reportData = null;

    public ?string $reportText = null;

    public bool $showPanel = false;

    public function togglePanel(): void
    {
        $this->showPanel = ! $this->showPanel;

        if (! $this->showPanel) {
            $this->resetState();
        }
    }

    public function analyzeFile(): void
    {
        $this->parseErrors = [];

        $this->validate([
            'importFile' => ['required', 'file', 'max:2048'],
        ]);

        /** @var TemporaryUploadedFile $file */
        $file = $this->importFile;
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            $this->addError('importFile', 'Format non supporté. Utilisez .csv ou .xlsx');

            return;
        }

        $this->originalFilename = $file->getClientOriginalName();

        try {
            $parser = app(TiersCsvParserService::class);
            $result = $parser->parse($file);
        } catch (\Throwable $e) {
            $this->parseErrors = [['line' => 0, 'message' => 'Erreur de lecture du fichier : '.$e->getMessage()]];

            return;
        }

        if (! $result->success) {
            $this->parseErrors = $result->errors;
            $this->phase = 'upload';

            return;
        }

        try {
            $matcher = app(TiersCsvMatcherService::class);
            $matched = $matcher->match($result->rows);
        } catch (\Throwable $e) {
            $this->parseErrors = [['line' => 0, 'message' => 'Erreur lors du matching : '.$e->getMessage()]];

            return;
        }

        // Add line numbers to each row
        $this->rows = [];
        foreach ($matched as $index => $row) {
            $row['line'] = $index + 2; // header = line 1
            $this->rows[] = $row;
        }

        $this->parseErrors = [];
        $this->phase = 'preview';
    }

    public function resolveConflict(int $index): void
    {
        if (! isset($this->rows[$index]) || $this->rows[$index]['status'] !== 'conflict') {
            return;
        }

        $row = $this->rows[$index];

        // Single match → open merge modal directly
        if (! empty($row['matched_tiers_id'])) {
            $this->resolvingIndex = $index;
            $this->dispatch('open-tiers-merge',
                sourceData: $this->buildSourceData($row),
                tiersId: $row['matched_tiers_id'],
                sourceLabel: 'Fichier CSV',
                targetLabel: 'Tiers existant',
                confirmLabel: 'Fusionner',
                context: 'csv_import',
                contextData: ['index' => $index],
            );

            return;
        }

        // Homonymes → candidates need selection first, handled by selectCandidate
        // The view shows a dropdown for candidate selection
    }

    public function selectCandidate(int $index, int $tiersId): void
    {
        if (! isset($this->rows[$index]) || $this->rows[$index]['status'] !== 'conflict') {
            return;
        }

        $row = $this->rows[$index];

        // Verify tiersId is among candidates
        $candidates = $row['matched_candidates'] ?? [];
        if (! in_array($tiersId, $candidates, true)) {
            return;
        }

        $this->rows[$index]['selected_candidate_id'] = $tiersId;
        $this->rows[$index]['matched_tiers_id'] = $tiersId;
        $this->resolvingIndex = $index;

        $this->dispatch('open-tiers-merge',
            sourceData: $this->buildSourceData($row),
            tiersId: $tiersId,
            sourceLabel: 'Fichier CSV',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Fusionner',
            context: 'csv_import',
            contextData: ['index' => $index],
        );
    }

    #[On('tiers-merge-confirmed')]
    public function onTiersMergeConfirmed(int $tiersId, string $context, array $contextData = []): void
    {
        if ($context !== 'csv_import') {
            return;
        }

        $index = $contextData['index'] ?? null;

        if ($index === null || ! isset($this->rows[$index])) {
            return;
        }

        $this->rows[$index]['status'] = 'conflict_resolved_merge';
        $this->rows[$index]['matched_tiers_id'] = $tiersId;
        $this->rows[$index]['merge_data'] = $contextData['merge_data'] ?? [];
        $this->rows[$index]['boolean_data'] = $contextData['boolean_data'] ?? [];
        $this->rows[$index]['decision_log'] = 'Fusion manuelle';
        $this->resolvingIndex = null;
    }

    #[On('tiers-merge-create-new')]
    public function onTiersMergeCreateNew(array $sourceData, string $context, array $contextData = []): void
    {
        if ($context !== 'csv_import') {
            return;
        }

        $index = $contextData['index'] ?? null;

        if ($index === null || ! isset($this->rows[$index])) {
            return;
        }

        $this->rows[$index]['status'] = 'conflict_resolved_new';
        $this->rows[$index]['decision_log'] = 'Création forcée (homonyme)';
        $this->resolvingIndex = null;
    }

    #[On('tiers-merge-cancelled')]
    public function onTiersMergeCancelled(): void
    {
        $this->resolvingIndex = null;
    }

    public function confirmImport(): void
    {
        if ($this->hasUnresolvedConflicts()) {
            return;
        }

        $this->phase = 'importing';

        try {
            $importService = app(TiersCsvImportService::class);
            $report = $importService->import($this->rows, $this->originalFilename);

            $this->reportData = [
                'created' => $report->created,
                'enriched' => $report->enriched,
                'resolvedMerge' => $report->resolvedMerge,
                'resolvedNew' => $report->resolvedNew,
                'total' => $report->total(),
                'lines' => $report->lines,
            ];
            $this->reportText = $report->toText($this->originalFilename);
            $this->phase = 'done';

            $this->dispatch('tiers-saved');
        } catch (\Throwable $e) {
            $this->parseErrors = [['line' => 0, 'message' => 'Erreur lors de l\'import : '.$e->getMessage()]];
            $this->phase = 'preview';
        }
    }

    public function downloadReport(): mixed
    {
        if ($this->reportText === null) {
            return null;
        }

        $filename = 'rapport-import-tiers-'.now()->format('Y-m-d-His').'.txt';

        return response()->streamDownload(function () {
            echo $this->reportText;
        }, $filename, ['Content-Type' => 'text/plain']);
    }

    public function cancel(): void
    {
        $this->resetState();
    }

    public function hasUnresolvedConflicts(): bool
    {
        foreach ($this->rows as $row) {
            if ($row['status'] === 'conflict') {
                return true;
            }
        }

        return false;
    }

    public function render(): View
    {
        return view('livewire.import-csv-tiers');
    }

    private function resetState(): void
    {
        $this->importFile = null;
        $this->phase = 'upload';
        $this->parseErrors = [];
        $this->rows = [];
        $this->originalFilename = '';
        $this->resolvingIndex = null;
        $this->reportData = null;
        $this->reportText = null;
        $this->resetValidation();
    }

    /**
     * Build sourceData array for the merge modal from a CSV row.
     *
     * @return array<string, mixed>
     */
    private function buildSourceData(array $row): array
    {
        $data = [];
        foreach (TiersMergeModal::MERGE_FIELDS as $field) {
            $data[$field] = $row[$field] ?? null;
        }

        $data['pour_depenses'] = (bool) ($row['pour_depenses'] ?? false);
        $data['pour_recettes'] = (bool) ($row['pour_recettes'] ?? false);
        $data['est_helloasso'] = false;

        return $data;
    }
}
