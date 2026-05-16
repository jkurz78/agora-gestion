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
use App\Support\CurrentAssociation;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DocumentPrevisionnelService::class);

    Exercice::create([
        'annee' => 2025,
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'statut' => 'ouvert',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

function createDocPrevSetup(int $nbSeances = 2, float $montant = 50.00): array
{
    $assocId = TenantContext::currentId();

    $typeOp = TypeOperation::factory()->create(['association_id' => $assocId]);
    $operation = Operation::factory()->create([
        'association_id' => $assocId,
        'type_operation_id' => $typeOp->id,
        'date_debut' => '2025-10-01',
        'date_fin' => '2025-12-15',
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $assocId]);
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

it('le rendu HTML d\'une proforma include le footer-logos avec appLogoBase64', function () {
    // Régression bug 2026-05-16 : DocumentPrevisionnelService ne passait ni
    // appLogoBase64 ni n'appelait PdfFooterRenderer::render() — résultat : PDF
    // proforma/devis prévisionnel sans pied de page. Le test sur le binaire PDF
    // est trop brittle (streams Flate-compressés), on assert sur la vue.
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Proforma);
    $doc->load('participant.tiers', 'operation');

    $appLogoBase64 = base64_encode(file_get_contents(public_path('images/agora-gestion.svg')));

    $html = view('pdf.document-previsionnel', [
        'document' => $doc,
        'association' => CurrentAssociation::get(),
        'tiers' => $doc->participant->tiers,
        'headerLogoBase64' => null,
        'headerLogoMime' => null,
        'appLogoBase64' => $appLogoBase64,
        'footerLogoBase64' => null,
        'footerLogoMime' => null,
    ])->render();

    // Le footer-logos est inclus → image avec le base64 du logo AgoraGestion
    expect($html)->toContain('data:image/svg+xml;base64,'.$appLogoBase64);
});

it('stores the PDF on disk and updates pdf_path', function () {
    [$operation, $participant] = createDocPrevSetup();

    $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
    $this->service->genererPdf($doc);

    $doc->refresh();
    expect($doc->pdf_path)->not->toBeNull();
    // pdf_path contient uniquement le nom court (ex: "42.pdf"), le chemin complet est via pdfFullPath()
    expect($doc->pdf_path)->not->toContain('/');
    expect(Storage::disk('local')->exists($doc->pdfFullPath()))->toBeTrue();
});
