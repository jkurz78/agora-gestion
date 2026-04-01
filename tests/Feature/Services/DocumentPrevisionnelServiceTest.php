<?php

declare(strict_types=1);

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
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

function createOperationWithReglements(int $nbSeances = 3, float $montant = 50.00): array
{
    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
        'date_debut' => '2025-10-01',
        'date_fin' => '2026-01-15',
    ]);

    $tiers = Tiers::factory()->create();
    $participant = Participant::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => '2025-09-15',
    ]);

    $seances = [];
    for ($i = 1; $i <= $nbSeances; $i++) {
        $seance = Seance::create([
            'operation_id' => $operation->id,
            'numero' => $i,
            'date' => '2025-10-' . str_pad((string) ($i * 7), 2, '0', STR_PAD_LEFT),
            'titre' => "Séance $i",
        ]);
        $seances[] = $seance;

        Reglement::create([
            'participant_id' => $participant->id,
            'seance_id' => $seance->id,
            'montant_prevu' => $montant,
        ]);
    }

    return [$operation, $participant, $seances];
}

describe('emettre()', function () {
    it('creates a devis with one aggregated line', function () {
        [$operation, $participant] = createOperationWithReglements(3, 50.00);

        $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        expect($doc)->toBeInstanceOf(DocumentPrevisionnel::class)
            ->and($doc->type)->toBe(TypeDocumentPrevisionnel::Devis)
            ->and($doc->version)->toBe(1)
            ->and((float) $doc->montant_total)->toBe(150.00)
            ->and($doc->numero)->toStartWith('D-2025-')
            ->and($doc->exercice)->toBe(2025)
            ->and($doc->saisi_par)->toBe($this->user->id);

        $lignes = $doc->lignes_json;
        expect($lignes)->toHaveCount(2); // 1 header texte + 1 montant
        expect($lignes[0]['type'])->toBe('texte');
        expect($lignes[1]['type'])->toBe('montant');
        expect((float) $lignes[1]['montant'])->toBe(150.00);
    });

    it('creates a proforma with one line per seance', function () {
        [$operation, $participant] = createOperationWithReglements(3, 50.00);

        $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Proforma);

        expect($doc->type)->toBe(TypeDocumentPrevisionnel::Proforma)
            ->and($doc->numero)->toStartWith('PF-2025-')
            ->and((float) $doc->montant_total)->toBe(150.00);

        $lignes = $doc->lignes_json;
        expect($lignes)->toHaveCount(4); // 1 header texte + 3 montant lines
        expect($lignes[0]['type'])->toBe('texte');
        expect($lignes[1]['type'])->toBe('montant');
        expect($lignes[2]['type'])->toBe('montant');
        expect($lignes[3]['type'])->toBe('montant');
    });

    it('increments version when amounts change', function () {
        [$operation, $participant, $seances] = createOperationWithReglements(2, 30.00);

        $doc1 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        // Change a reglement amount to trigger a new version
        Reglement::where('participant_id', $participant->id)
            ->where('seance_id', $seances[0]->id)
            ->update(['montant_prevu' => 50.00]);

        $doc2 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        expect($doc1->version)->toBe(1);
        expect($doc2->version)->toBe(2);
    });

    it('maintains separate numbering for devis and proforma', function () {
        [$operation, $participant] = createOperationWithReglements(2, 30.00);

        $devis = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
        $proforma = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Proforma);

        expect($devis->numero)->toStartWith('D-2025-');
        expect($proforma->numero)->toStartWith('PF-2025-');
    });

    it('returns existing document if amounts unchanged', function () {
        [$operation, $participant] = createOperationWithReglements(2, 30.00);

        $doc1 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
        $doc2 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        // Same amounts → should return existing, not create new version
        expect($doc2->id)->toBe($doc1->id);
        expect(DocumentPrevisionnel::count())->toBe(1);
    });

    it('creates new version when amounts change', function () {
        [$operation, $participant, $seances] = createOperationWithReglements(2, 30.00);

        $doc1 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        // Change a reglement amount
        Reglement::where('participant_id', $participant->id)
            ->where('seance_id', $seances[0]->id)
            ->update(['montant_prevu' => 50.00]);

        $doc2 = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        expect($doc2->id)->not->toBe($doc1->id);
        expect($doc2->version)->toBe(2);
        expect((float) $doc2->montant_total)->toBe(80.00);
    });

    it('uses singular seance when only one', function () {
        [$operation, $participant] = createOperationWithReglements(1, 50.00);

        $doc = $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);

        $headerLine = $doc->lignes_json[0];
        expect($headerLine['libelle'])->toContain('1 séance :');
        expect($headerLine['libelle'])->not->toContain('séances');
    });

    it('throws when exercice is closed', function () {
        Exercice::first()->update(['statut' => 'cloture']);

        [$operation, $participant] = createOperationWithReglements(2, 30.00);

        $this->service->emettre($operation, $participant, TypeDocumentPrevisionnel::Devis);
    })->throws(\App\Exceptions\ExerciceCloturedException::class);
});
