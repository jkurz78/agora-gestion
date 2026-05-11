<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Enums\StatutReglement;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use App\Services\Tiers\DTO\ParticipationLigneDTO;
use App\Services\Tiers\DTO\ParticipationsTimelineDTO;
use App\Services\Tiers\TiersOperationsTimelineService;
use App\Tenant\TenantContext;

// ── Phase 1.2 : identité opération ──────────────────────────────────────────

it('wrappe un Participant et expose son identité opération', function (): void {
    $type = TypeOperation::factory()->create(['nom' => 'Yoga']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id, 'nom' => 'Yoga saison 2025']);
    $tiers = Tiers::factory()->create();
    $participant = Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    $dto = new ParticipationLigneDTO($participant->fresh(['operation.typeOperation']));

    expect($dto->operationId())->toBe((int) $op->id)
        ->and($dto->operationNom())->toBe('Yoga saison 2025')
        ->and($dto->typeOperationNom())->toBe('Yoga')
        ->and($dto->estHelloasso())->toBeFalse()
        ->and($dto->operationArchivee())->toBeFalse()
        ->and($dto->refereParTiers())->toBeNull()
        ->and($dto->refereParNomComplet())->toBeNull();
});

// ── Phase 2.1 : DTO vide ────────────────────────────────────────────────────

it('retourne un DTO vide si le tiers n\'a aucune participation', function (): void {
    $tiers = Tiers::factory()->create();

    $service = app(TiersOperationsTimelineService::class);
    $dto = $service->forTiers($tiers);

    expect($dto)->toBeInstanceOf(ParticipationsTimelineDTO::class)
        ->and($dto->totalCount)->toBe(0)
        ->and($dto->lignes)->toBe([]);
});

// ── Phase 3.1 : tri chronologique inverse ───────────────────────────────────

it('trie les participations par date_inscription desc', function (): void {
    $tiers = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create();

    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id, 'date_inscription' => '2024-09-01']);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op2->id, 'date_inscription' => '2026-01-15']);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op3->id, 'date_inscription' => '2025-03-10']);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->totalCount)->toBe(3);
    expect($dto->lignes[0]->operationId())->toBe((int) $op2->id);
    expect($dto->lignes[1]->operationId())->toBe((int) $op3->id);
    expect($dto->lignes[2]->operationId())->toBe((int) $op1->id);
});

// ── Phase 3.2 : isolation tiers ─────────────────────────────────────────────

it('ne retourne pas les participations d\'un autre tiers', function (): void {
    $tiersA = Tiers::factory()->create();
    $tiersB = Tiers::factory()->create();
    $op = Operation::factory()->create();

    Participant::factory()->create(['tiers_id' => $tiersA->id, 'operation_id' => $op->id]);
    $op2 = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersB->id, 'operation_id' => $op2->id]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiersA);

    expect($dto->totalCount)->toBe(1);
    expect((int) $dto->lignes[0]->participant->tiers_id)->toBe((int) $tiersA->id);
});

// ── Phase 3.3 : intrusion multi-tenant ──────────────────────────────────────

it('ne fuite pas les participations d\'une autre association', function (): void {
    $assoB = Association::factory()->create();

    // Créer un Participant côté assoB
    TenantContext::boot($assoB);
    $tiersIntrus = Tiers::factory()->create();
    $opIntrus = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersIntrus->id, 'operation_id' => $opIntrus->id]);

    // Revenir sur le tenant par défaut (celui du bootstrap)
    TenantContext::boot(Association::first()->fresh());

    // Un Tiers du tenant courant
    $tiersCourant = Tiers::factory()->create();
    $opCourant = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersCourant->id, 'operation_id' => $opCourant->id]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiersCourant);

    // 1 ligne (la sienne), pas celle de l'autre asso
    expect($dto->totalCount)->toBe(1);
    expect((int) $dto->lignes[0]->participant->tiers_id)->toBe((int) $tiersCourant->id);
});

// ── Phase 4.1 : tarif ───────────────────────────────────────────────────────

it('expose le libellé et le montant du tarif appliqué', function (): void {
    $type = TypeOperation::factory()->create();
    $tarif = TypeOperationTarif::factory()->create([
        'type_operation_id' => $type->id,
        'libelle' => 'Plein',
        'montant' => 150.00,
    ]);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);
    $tiers = Tiers::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
        'type_operation_tarif_id' => $tarif->id,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->tarifLibelle())->toBe('Plein');
    expect($dto->lignes[0]->tarifMontant())->toBe(150.00);
});

it('retourne null si pas de tarif rattaché', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
        'type_operation_tarif_id' => null,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->tarifLibelle())->toBeNull();
    expect($dto->lignes[0]->tarifMontant())->toBe(0.0);
});

// ── Phase 5.1 : séances suivies ─────────────────────────────────────────────

it('compte le nombre total de séances de l\'opération', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Seance::factory()->count(15)->create(['operation_id' => $op->id]);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->seancesTotal())->toBe(15);
});

it('retourne null pour seancesTotal si aucune séance créée', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->seancesTotal())->toBeNull();
});

it('compte les présences positives uniquement (statut present)', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seances = Seance::factory()->count(5)->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    Presence::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[0]->id, 'statut' => StatutPresence::Present->value]);
    Presence::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[1]->id, 'statut' => StatutPresence::Present->value]);
    Presence::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[2]->id, 'statut' => StatutPresence::Excuse->value]);
    Presence::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[3]->id, 'statut' => StatutPresence::AbsenceNonJustifiee->value]);
    // 5e séance : pas de Presence créée → ni présent ni absent

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->seancesSuivies())->toBe(2);
    expect($dto->lignes[0]->seancesTotal())->toBe(5);
});

// ── Phase 6.1 : montant prévu / payé ────────────────────────────────────────

it('calcule montantPrevu comme somme des montant_prevu des reglements', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seances = Seance::factory()->count(3)->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[0]->id, 'montant_prevu' => 30.00]);
    Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[1]->id, 'montant_prevu' => 30.00]);
    Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[2]->id, 'montant_prevu' => 30.00]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->montantPrevu())->toBe(90.00);
});

it('calcule montantPaye en excluant transactions en attente', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seances = Seance::factory()->count(3)->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $regl1 = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[0]->id, 'montant_prevu' => 30.00]);
    $regl2 = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[1]->id, 'montant_prevu' => 30.00]);
    $regl3 = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[2]->id, 'montant_prevu' => 30.00]);

    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $regl1->id, 'statut_reglement' => StatutReglement::Recu]);
    $tr2 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $regl2->id, 'statut_reglement' => StatutReglement::Pointe]);
    $tr3 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $regl3->id, 'statut_reglement' => StatutReglement::EnAttente]);

    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $op->id, 'montant' => 30.00, 'seance' => $seances[0]->numero ?? null]);
    TransactionLigne::factory()->create(['transaction_id' => $tr2->id, 'operation_id' => $op->id, 'montant' => 30.00, 'seance' => $seances[1]->numero ?? null]);
    TransactionLigne::factory()->create(['transaction_id' => $tr3->id, 'operation_id' => $op->id, 'montant' => 30.00, 'seance' => $seances[2]->numero ?? null]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->montantPaye())->toBe(60.00); // 30 + 30 (Recu + Pointe), exclut EnAttente
    expect($dto->lignes[0]->montantPrevu())->toBe(90.00);
});

it('retourne 0 pour montantPaye si pas de transaction encaissée', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seance = Seance::factory()->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    $regl = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seance->id, 'montant_prevu' => 50.00]);
    Transaction::factory()->create(['reglement_id' => $regl->id, 'statut_reglement' => StatutReglement::EnAttente]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->montantPaye())->toBe(0.0);
});

// ── Phase 7.1 : 4 statuts ───────────────────────────────────────────────────

it('retourne statut=gratuit si montantPrevu=0', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    // Aucun Reglement créé → W = 0

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->statut())->toBe('gratuit');
});

it('retourne statut=non_paye si montantPrevu>0 et montantPaye=0', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seance = Seance::factory()->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    $regl = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seance->id, 'montant_prevu' => 50.00]);
    Transaction::factory()->create(['reglement_id' => $regl->id, 'statut_reglement' => StatutReglement::EnAttente]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->statut())->toBe('non_paye');
});

it('retourne statut=partiel si 0 < montantPaye < montantPrevu', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seances = Seance::factory()->count(2)->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    $regl1 = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[0]->id, 'montant_prevu' => 30.00]);
    $regl2 = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seances[1]->id, 'montant_prevu' => 30.00]);
    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $regl1->id, 'statut_reglement' => StatutReglement::Recu]);
    Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $regl2->id, 'statut_reglement' => StatutReglement::EnAttente]);
    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $op->id, 'montant' => 30.00]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->statut())->toBe('partiel');
});

it('retourne statut=solde si montantPaye>=montantPrevu>0', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    $seance = Seance::factory()->create(['operation_id' => $op->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    $regl = Reglement::factory()->create(['participant_id' => $participant->id, 'seance_id' => $seance->id, 'montant_prevu' => 50.00]);
    $tr = Transaction::factory()->create(['tiers_id' => $tiers->id, 'reglement_id' => $regl->id, 'statut_reglement' => StatutReglement::Recu]);
    TransactionLigne::factory()->create(['transaction_id' => $tr->id, 'operation_id' => $op->id, 'montant' => 50.00]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->statut())->toBe('solde');
});

// ── Phase 8.1 : dateDebut / dateFin ─────────────────────────────────────────

it('expose dateDebut/dateFin comme min/max des séances', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2025-09-15']);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2026-06-30']);
    Seance::factory()->create(['operation_id' => $op->id, 'date' => '2025-12-20']);
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->dateDebut()->format('Y-m-d'))->toBe('2025-09-15');
    expect($dto->lignes[0]->dateFin()->format('Y-m-d'))->toBe('2026-06-30');
});

it('retourne null pour dateDebut/dateFin si aucune séance', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->dateDebut())->toBeNull();
    expect($dto->lignes[0]->dateFin())->toBeNull();
});

// ── Phase 8.2 : opération archivée ──────────────────────────────────────────

it('expose operationArchivee=true si l\'opération est soft-deleted', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    $op->delete(); // soft delete

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->totalCount)->toBe(1); // la ligne reste affichée
    expect($dto->lignes[0]->operationArchivee())->toBeTrue();
});

// ── Phase 8.3 : référent dénormalisé ────────────────────────────────────────

it('expose refereParNomComplet avec NOM en majuscules', function (): void {
    $tiers = Tiers::factory()->create();
    $referent = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Dupont']);
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
        'refere_par_id' => $referent->id,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    // accesseur Tiers::getNomAttribute applique mb_strtoupper
    expect($dto->lignes[0]->refereParNomComplet())->toBe('Marie DUPONT');
    expect((int) $dto->lignes[0]->refereParTiers()->id)->toBe((int) $referent->id);
});

it('retourne null pour refereParNomComplet si pas de référent', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
        'refere_par_id' => null,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);

    expect($dto->lignes[0]->refereParNomComplet())->toBeNull();
});

// ── Phase 7a-fix : paiements hors séance via TransactionLigne ────────────────

it('calcule montantPaye via TransactionLigne et inclut les paiements hors séance', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $tr = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'statut_reglement' => StatutReglement::Recu,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tr->id,
        'operation_id' => $op->id,
        'seance' => null,
        'montant' => 80.00,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);
    expect($dto->lignes[0]->montantPaye())->toBe(80.00);
});

it('exclut les paiements d\'un autre tiers même si operation_id correspond', function (): void {
    $tiersA = Tiers::factory()->create();
    $tiersB = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersA->id, 'operation_id' => $op->id]);

    $tr = Transaction::factory()->create([
        'tiers_id' => $tiersB->id,
        'statut_reglement' => StatutReglement::Recu,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tr->id,
        'operation_id' => $op->id,
        'montant' => 50.00,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiersA);
    expect($dto->lignes[0]->montantPaye())->toBe(0.0);
});

it('exclut les paiements d\'une autre opération', function (): void {
    $tiers = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op1->id]);

    $tr = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'statut_reglement' => StatutReglement::Recu,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tr->id,
        'operation_id' => $op2->id,
        'montant' => 50.00,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);
    expect($dto->lignes[0]->montantPaye())->toBe(0.0);
});

it('retourne statut=solde quand W=0 et Z>0 (paiement forfaitaire hors séance)', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);
    // Pas de Reglement créé → W=0

    $tr = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'statut_reglement' => StatutReglement::Recu,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tr->id,
        'operation_id' => $op->id,
        'montant' => 80.00,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);
    expect($dto->lignes[0]->montantPaye())->toBe(80.00);
    expect($dto->lignes[0]->montantPrevu())->toBe(0.0);
    expect($dto->lignes[0]->statut())->toBe('solde');
});

it('exclut les paiements EnAttente du calcul', function (): void {
    $tiers = Tiers::factory()->create();
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiers->id, 'operation_id' => $op->id]);

    $tr = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'statut_reglement' => StatutReglement::EnAttente,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tr->id,
        'operation_id' => $op->id,
        'montant' => 80.00,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->forTiers($tiers);
    expect($dto->lignes[0]->montantPaye())->toBe(0.0);
});
