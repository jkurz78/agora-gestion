<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ParticipantXlsxImportService;
use App\Services\ParticipantXlsxMatcherService;
use App\Services\ParticipantXlsxParserService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class ImportParticipants extends Component
{
    use WithFileUploads;

    public Operation $operation;

    public bool $showPanel = false;

    public string $phase = 'upload';

    /** @var TemporaryUploadedFile|null */
    public $importFile = null;

    /** @var list<array{line: int, message: string}> */
    public array $parseErrors = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public string $originalFilename = '';

    /** @var array<string, mixed>|null */
    public ?array $reportData = null;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function togglePanel(): void
    {
        $this->showPanel = ! $this->showPanel;

        if (! $this->showPanel) {
            $this->resetState();
        }
    }

    public function updatedImportFile(): void
    {
        $this->analyzeFile();
    }

    public function analyzeFile(): void
    {
        $this->parseErrors = [];

        $this->validate([
            'importFile' => ['required', 'file', 'max:4096'],
        ]);

        /** @var TemporaryUploadedFile $file */
        $file = $this->importFile;
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['csv', 'xlsx'], true)) {
            $this->addError('importFile', 'Format non supporté. Utilisez .csv ou .xlsx');

            return;
        }

        $this->originalFilename = $file->getClientOriginalName();

        try {
            $parser = app(ParticipantXlsxParserService::class);
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
            $matcher = app(ParticipantXlsxMatcherService::class);
            $matched = $matcher->match($result->rows, $this->operation->id);
        } catch (\Throwable $e) {
            $this->parseErrors = [['line' => 0, 'message' => 'Erreur lors de l\'analyse : '.$e->getMessage()]];

            return;
        }

        $this->rows = [];
        foreach ($matched as $index => $row) {
            if (! isset($row['line'])) {
                $row['line'] = $index + 2; // header = line 1
            }
            $this->rows[] = $row;
        }

        $this->parseErrors = [];
        $this->phase = 'preview';
    }

    public function confirmImport(): void
    {
        if ($this->hasConflicts()) {
            return;
        }

        $this->phase = 'importing';

        try {
            $importService = app(ParticipantXlsxImportService::class);
            $report = $importService->import($this->rows, $this->operation->id, $this->originalFilename);

            $this->reportData = [
                'created' => $report->created,
                'linked' => $report->linked,
                'skipped' => $report->skipped,
                'total' => $report->total(),
                'lines' => $report->lines,
            ];

            $this->phase = 'done';
            $this->dispatch('participants-saved');
        } catch (\Throwable $e) {
            $this->parseErrors = [['line' => 0, 'message' => 'Erreur lors de l\'import : '.$e->getMessage()]];
            $this->phase = 'preview';
        }
    }

    public function cancel(): void
    {
        $this->resetState();
    }

    public function hasConflicts(): bool
    {
        foreach ($this->rows as $row) {
            if (($row['status'] ?? '') === 'conflict') {
                return true;
            }
        }

        return false;
    }

    public function render(): View
    {
        return view('livewire.import-participants');
    }

    private function resetState(): void
    {
        $this->importFile = null;
        $this->phase = 'upload';
        $this->parseErrors = [];
        $this->rows = [];
        $this->originalFilename = '';
        $this->reportData = null;
        $this->resetValidation();
    }
}
