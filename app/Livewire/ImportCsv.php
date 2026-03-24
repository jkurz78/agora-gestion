<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\CsvImportService;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class ImportCsv extends Component
{
    use WithFileUploads;
    use \App\Livewire\Concerns\RespectsExerciceCloture;

    // #[Locked] prevents client-side tampering via wire:model
    #[Locked]
    public string $type = '';

    public bool $showPanel = false;

    #[Validate(['file', 'mimes:csv,txt', 'max:2048'])]
    public ?TemporaryUploadedFile $csvFile = null;

    /** @var list<array{line: int, message: string}>|null */
    public ?array $importErrors = null;

    public ?string $successMessage = null;

    public function mount(string $type): void
    {
        $this->type = $type;
    }

    public function togglePanel(): void
    {
        $this->showPanel = ! $this->showPanel;

        if (! $this->showPanel) {
            $this->importErrors = null;
            $this->successMessage = null;
            $this->csvFile = null;
            $this->resetValidation();
        }
    }

    public function import(): void
    {
        $this->validate();

        $result = app(CsvImportService::class)->import($this->csvFile, $this->type);

        if ($result->success) {
            $this->successMessage = "Import réussi : {$result->transactionsCreated} transactions créées ({$result->lignesCreated} lignes comptables)";
            $this->importErrors = null;
            $this->dispatch('csv-imported');
        } else {
            $this->importErrors = $result->errors;
            $this->successMessage = null;
        }
    }

    public function render(): View
    {
        return view('livewire.import-csv');
    }
}
