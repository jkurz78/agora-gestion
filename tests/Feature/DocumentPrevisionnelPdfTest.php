<?php

declare(strict_types=1);

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\Association;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\DocumentPrevisionnelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DocumentPrevisionnelService::class);

    Association::create(['nom' => 'Test Asso', 'siret' => '123 456 789 00012']);
    Exercice::create([
        'annee' => 2025,
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'statut' => 'ouvert',
    ]);
});

function createDocPrevSetup(int $nbSeances = 2, float $montant = 50.00): array
{
    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => '2025-10-01',
        'date_fin' => '2025-12-15',
    ]);

    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => '2025-09-15',
    ]);

    for ($i = 1; $i <= $nbSeances; $i++) {
        $seance = Seance::create([
            'operation_id' => $operation->id,
            'numero' => $i,
            'date' => '2025-10-'.str_pad((string) ($i * 7), 2, '0', STR_PAD_LEFT),
            'titre' => "Séance $i",
        ]);

        Reglement::create([
            'participant_id' => $participant->id,
            'seance_id' => $seance->id,
            'montant_prevu' => $montant,
        ]);
    }

    return [$operation, $participant];
}

it('generates a PDF for a devis', function () {
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
    $pdfContent = $this->service->genererPdf($doc);

    expect($pdfContent)->toBeString()
        ->and(strlen($pdfContent))->toBeGreaterThan(100)
        ->and(str_starts_with($pdfContent, '%PDF'))->toBeTrue();
});

it('generates a PDF for a proforma', function () {
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Proforma);
    $pdfContent = $this->service->genererPdf($doc);

    expect($pdfContent)->toBeString()
        ->and(str_starts_with($pdfContent, '%PDF'))->toBeTrue();
});

it('stores the PDF on disk and updates pdf_path', function () {
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
    $this->service->genererPdf($doc);

    $doc->refresh();
    expect($doc->pdf_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($doc->pdf_path))->toBeTrue();
});
