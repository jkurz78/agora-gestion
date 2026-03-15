<?php

declare(strict_types=1);

use App\Livewire\ImportCsv;
use App\Models\User;
use App\Services\CsvImportResult;
use App\Services\CsvImportService;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('togglePanel shows and hides the panel', function () {
    Livewire::test(ImportCsv::class, ['type' => 'depense'])
        ->assertSet('showPanel', false)
        ->call('togglePanel')
        ->assertSet('showPanel', true)
        ->call('togglePanel')
        ->assertSet('showPanel', false);
});

it('import rejects missing file', function () {
    Livewire::test(ImportCsv::class, ['type' => 'depense'])
        ->call('import')
        ->assertHasErrors(['csvFile']);
});

it('import rejects non-csv file', function () {
    $file = UploadedFile::fake()->create('test.pdf', 10, 'application/pdf');

    Livewire::test(ImportCsv::class, ['type' => 'depense'])
        ->set('csvFile', $file)
        ->call('import')
        ->assertHasErrors(['csvFile']);
});

it('import success sets successMessage and dispatches event', function () {
    $fakeResult = new CsvImportResult(true, transactionsCreated: 2, lignesCreated: 3);

    // CsvImportService is final so we bind a duck-typed fake to the container
    $fake = new class($fakeResult) {
        public function __construct(private CsvImportResult $fakeResult) {}

        public function import(\Illuminate\Http\UploadedFile $file, string $type): CsvImportResult
        {
            return $this->fakeResult;
        }
    };

    app()->instance(CsvImportService::class, $fake);

    $csvFile = UploadedFile::fake()->create('test.csv', 10, 'text/csv');

    Livewire::test(ImportCsv::class, ['type' => 'depense'])
        ->set('csvFile', $csvFile)
        ->call('import')
        ->assertSet('successMessage', fn ($msg) => str_contains($msg, '2 transactions'))
        ->assertDispatched('csv-imported');
});

it('import error sets importErrors array', function () {
    $fakeResult = new CsvImportResult(false, errors: [['line' => 4, 'message' => 'Erreur test']]);

    $fake = new class($fakeResult) {
        public function __construct(private CsvImportResult $fakeResult) {}

        public function import(\Illuminate\Http\UploadedFile $file, string $type): CsvImportResult
        {
            return $this->fakeResult;
        }
    };

    app()->instance(CsvImportService::class, $fake);

    $csvFile = UploadedFile::fake()->create('test.csv', 10, 'text/csv');

    Livewire::test(ImportCsv::class, ['type' => 'depense'])
        ->set('csvFile', $csvFile)
        ->call('import')
        ->assertSet('importErrors', [['line' => 4, 'message' => 'Erreur test']]);
});
