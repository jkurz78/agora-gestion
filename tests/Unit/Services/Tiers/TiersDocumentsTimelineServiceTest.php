<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FacturePartenaireDeposee;
use App\Models\Participant;
use App\Models\ParticipantDocument;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Tiers\DTO\DocumentsTimelineDTO;
use App\Services\Tiers\TiersDocumentsTimelineService;
use App\Tenant\TenantContext;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->service = app(TiersDocumentsTimelineService::class);
});

it('liste un reçu fiscal don (pas d\'adhésion liée)', function (): void {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Recette->value]);
    $ligne = TransactionLigne::factory()->create(['transaction_id' => $tx->id]);
    RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'annule_at' => null,
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->recusFiscaux)->toHaveCount(1)
        ->and($result->recusFiscaux[0]->type)->toBe('don');
});

it('détecte un reçu fiscal de type cotisation via adhésion liée', function (): void {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Recette->value]);
    $ligne = TransactionLigne::factory()->create(['transaction_id' => $tx->id]);
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'transaction_id' => $tx->id]);
    RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'annule_at' => null,
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->recusFiscaux)->toHaveCount(1)
        ->and($result->recusFiscaux[0]->type)->toBe('cotisation');
});

it('exclut les reçus fiscaux annulés', function (): void {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->create(['tiers_id' => $tiers->id]);
    $ligne = TransactionLigne::factory()->create(['transaction_id' => $tx->id]);
    RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'annule_at' => now(),
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->recusFiscaux)->toHaveCount(0);
});

it('liste les factures émises tous statuts', function (): void {
    $tiers = Tiers::factory()->create();
    Facture::factory()->create(['tiers_id' => $tiers->id, 'statut' => 'brouillon']);
    Facture::factory()->create(['tiers_id' => $tiers->id, 'statut' => 'validee']);

    $result = $this->service->forTiers($tiers);

    expect($result->facturesEmises)->toHaveCount(2);
});

it('liste les factures partenaires déposées', function (): void {
    $tiers = Tiers::factory()->create();
    FacturePartenaireDeposee::factory()->create(['tiers_id' => $tiers->id]);

    $result = $this->service->forTiers($tiers);

    expect($result->facturesDeposees)->toHaveCount(1);
});

it('liste les justificatifs des participants du tiers', function (): void {
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id]);
    ParticipantDocument::factory()->create([
        'participant_id' => $participant->id,
        'label' => 'Attestation médicale',
    ]);

    $result = $this->service->forTiers($tiers);

    expect($result->justificatifsParticipants)->toHaveCount(1)
        ->and($result->justificatifsParticipants[0]->label)->toBe('Attestation médicale')
        ->and($result->justificatifsParticipants[0]->participantId)->toBe($participant->id);
});

it('exclut les justificatifs des participants d\'autres tiers', function (): void {
    $tiers = Tiers::factory()->create();
    $autre = Tiers::factory()->create();
    $autreParticipant = Participant::factory()->create(['tiers_id' => $autre->id]);
    ParticipantDocument::factory()->create(['participant_id' => $autreParticipant->id]);

    $result = $this->service->forTiers($tiers);

    expect($result->justificatifsParticipants)->toHaveCount(0);
});
