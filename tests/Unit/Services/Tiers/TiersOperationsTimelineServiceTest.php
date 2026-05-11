<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
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
use App\Services\Tiers\DTO\AReferreTimelineDTO;
use App\Services\Tiers\DTO\EncadrementTimelineDTO;
use App\Services\Tiers\DTO\ParticipationLigneDTO;
use App\Services\Tiers\DTO\ParticipationsTimelineDTO;
use App\Services\Tiers\DTO\SuitLigneDTO;
use App\Services\Tiers\DTO\SuitTimelineDTO;
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

// ── Phase 7b : section "A référé" ───────────────────────────────────────────

it('aReferreForTiers retourne DTO vide si tiers n\'a référé personne', function (): void {
    $tiers = Tiers::factory()->create();

    $dto = app(TiersOperationsTimelineService::class)->aReferreForTiers($tiers);

    expect($dto)->toBeInstanceOf(AReferreTimelineDTO::class)
        ->and($dto->totalCount)->toBe(0)
        ->and($dto->lignes)->toBe([]);
});

it('aReferreForTiers totalCount = nb de tiers DISTINCTS (pas lignes)', function (): void {
    $referent = Tiers::factory()->create();
    $marie = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Martin']);
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create();

    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op1->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op2->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op3->id, 'refere_par_id' => $referent->id]);

    $dto = app(TiersOperationsTimelineService::class)->aReferreForTiers($referent);

    // totalCount = 1 (Marie est 1 tiers distinct), lignes = 3 (1 par lien)
    expect($dto->totalCount)->toBe(1)
        ->and(count($dto->lignes))->toBe(3);
});

it('aReferreForTiers trie par tiers.nom ASC', function (): void {
    $referent = Tiers::factory()->create();
    $tiersC = Tiers::factory()->create(['prenom' => 'Charles', 'nom' => 'Zulu']);
    $tiersA = Tiers::factory()->create(['prenom' => 'Alice', 'nom' => 'Alpha']);
    $tiersB = Tiers::factory()->create(['prenom' => 'Bob', 'nom' => 'Martin']);
    $op = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create();

    Participant::factory()->create(['tiers_id' => $tiersC->id, 'operation_id' => $op->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $tiersA->id, 'operation_id' => $op2->id, 'refere_par_id' => $referent->id]);
    Participant::factory()->create(['tiers_id' => $tiersB->id, 'operation_id' => $op3->id, 'refere_par_id' => $referent->id]);

    $dto = app(TiersOperationsTimelineService::class)->aReferreForTiers($referent);

    expect($dto->lignes[0]->tiersReferreId())->toBe((int) $tiersA->id)
        ->and($dto->lignes[1]->tiersReferreId())->toBe((int) $tiersB->id)
        ->and($dto->lignes[2]->tiersReferreId())->toBe((int) $tiersC->id);
});

it('aReferreForTiers trie par dateDebut opération DESC en secondaire (même nom)', function (): void {
    $referent = Tiers::factory()->create();
    $patient = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);

    // Même patient sur 2 opérations
    $opRecente = Operation::factory()->create();
    Seance::factory()->create(['operation_id' => $opRecente->id, 'date' => '2026-03-15']);
    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $opRecente->id,
        'refere_par_id' => $referent->id,
    ]);

    $opAncienne = Operation::factory()->create();
    Seance::factory()->create(['operation_id' => $opAncienne->id, 'date' => '2024-09-10']);
    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $opAncienne->id,
        'refere_par_id' => $referent->id,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->aReferreForTiers($referent);

    expect($dto->totalCount)->toBe(1)
        ->and(count($dto->lignes))->toBe(2);
    // L'opération qui a démarré le plus récemment en premier (dateDebut DESC)
    expect($dto->lignes[0]->operationId())->toBe((int) $opRecente->id)
        ->and($dto->lignes[1]->operationId())->toBe((int) $opAncienne->id);
});

it('aReferreForTiers isole les autres tiers', function (): void {
    $referentX = Tiers::factory()->create();
    $referentZ = Tiers::factory()->create();
    $tiersReferreParX = Tiers::factory()->create();
    $tiersReferreParZ = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();

    Participant::factory()->create(['tiers_id' => $tiersReferreParX->id, 'operation_id' => $op1->id, 'refere_par_id' => $referentX->id]);
    Participant::factory()->create(['tiers_id' => $tiersReferreParZ->id, 'operation_id' => $op2->id, 'refere_par_id' => $referentZ->id]);

    $dto = app(TiersOperationsTimelineService::class)->aReferreForTiers($referentX);

    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->tiersReferreId())->toBe((int) $tiersReferreParX->id);
});

it('aReferreForTiers isole multi-tenant', function (): void {
    $assoB = Association::factory()->create();

    // Créer des participants côté assoB
    TenantContext::boot($assoB);
    $referentB = Tiers::factory()->create();
    $tiersReferreB = Tiers::factory()->create();
    $opB = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersReferreB->id, 'operation_id' => $opB->id, 'refere_par_id' => $referentB->id]);

    // Revenir sur le tenant par défaut
    TenantContext::boot(Association::first()->fresh());

    $referentA = Tiers::factory()->create();
    $tiersReferreA = Tiers::factory()->create();
    $opA = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $tiersReferreA->id, 'operation_id' => $opA->id, 'refere_par_id' => $referentA->id]);

    $dto = app(TiersOperationsTimelineService::class)->aReferreForTiers($referentA);

    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->tiersReferreId())->toBe((int) $tiersReferreA->id);
});

it('AReferreLigneDTO expose tiersReferreNomComplet en "Prenom NOM"', function (): void {
    $referent = Tiers::factory()->create();
    $marie = Tiers::factory()->create(['prenom' => 'Marie', 'nom' => 'Dupont']);
    $op = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $marie->id, 'operation_id' => $op->id, 'refere_par_id' => $referent->id]);

    $dto = app(TiersOperationsTimelineService::class)->aReferreForTiers($referent);

    // accesseur Tiers::getNomAttribute applique mb_strtoupper
    expect($dto->lignes[0]->tiersReferreNomComplet())->toBe('Marie DUPONT');
});

// ── Phase 7b : section "Suit" ────────────────────────────────────────────────

it('suitForTiers retourne DTO vide si tiers ne suit personne', function (): void {
    $tiers = Tiers::factory()->create();

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($tiers);

    expect($dto)->toBeInstanceOf(SuitTimelineDTO::class)
        ->and($dto->totalCount)->toBe(0)
        ->and($dto->lignes)->toBe([]);
});

it('suitForTiers totalCount = tiers distincts (un tiers suivi en médecin sur 2 opérations)', function (): void {
    $medecin = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();

    Participant::factory()->create(['tiers_id' => $patient->id, 'operation_id' => $op1->id, 'medecin_tiers_id' => $medecin->id]);
    Participant::factory()->create(['tiers_id' => $patient->id, 'operation_id' => $op2->id, 'medecin_tiers_id' => $medecin->id]);

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($medecin);

    expect($dto->totalCount)->toBe(1)
        ->and(count($dto->lignes))->toBe(2);
});

it('suitForTiers cas double rôle : 1 tiers en médecin ET en thérapeute sur même opération → 2 lignes, totalCount=1', function (): void {
    $suivi = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();

    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $op->id,
        'medecin_tiers_id' => $suivi->id,
        'therapeute_tiers_id' => $suivi->id,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($suivi);

    expect($dto->totalCount)->toBe(1)
        ->and(count($dto->lignes))->toBe(2);
});

it('suitForTiers distinct sur 2 FKs : Marie est médecin de X, Pierre est thérapeute de X → totalCount=2', function (): void {
    $suivi = Tiers::factory()->create(); // le tiers consulté (le "praticien")
    $patientX = Tiers::factory()->create(['prenom' => 'Patient', 'nom' => 'X']);
    $patientY = Tiers::factory()->create(['prenom' => 'Patient', 'nom' => 'Y']);
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();

    // $suivi est médecin de patientX
    Participant::factory()->create(['tiers_id' => $patientX->id, 'operation_id' => $op1->id, 'medecin_tiers_id' => $suivi->id]);
    // $suivi est thérapeute de patientY
    Participant::factory()->create(['tiers_id' => $patientY->id, 'operation_id' => $op2->id, 'therapeute_tiers_id' => $suivi->id]);

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($suivi);

    expect($dto->totalCount)->toBe(2)
        ->and(count($dto->lignes))->toBe(2);
});

it('SuitLigneDTO qualiteLabel retourne Médecin ou Thérapeute', function (): void {
    $suivi = Tiers::factory()->create();
    $patient = Tiers::factory()->create();
    $op = Operation::factory()->create();

    Participant::factory()->create([
        'tiers_id' => $patient->id,
        'operation_id' => $op->id,
        'medecin_tiers_id' => $suivi->id,
        'therapeute_tiers_id' => $suivi->id,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($suivi);

    $labels = array_map(fn (SuitLigneDTO $l) => $l->qualiteLabel(), $dto->lignes);
    expect($labels)->toContain('Médecin')
        ->and($labels)->toContain('Thérapeute');
});

it('suitForTiers trie par tiers.nom ASC', function (): void {
    $suivi = Tiers::factory()->create();
    $tiersC = Tiers::factory()->create(['prenom' => 'Charles', 'nom' => 'Zulu']);
    $tiersA = Tiers::factory()->create(['prenom' => 'Alice', 'nom' => 'Alpha']);
    $tiersB = Tiers::factory()->create(['prenom' => 'Bob', 'nom' => 'Martin']);
    $op1 = Operation::factory()->create();
    $op2 = Operation::factory()->create();
    $op3 = Operation::factory()->create();

    Participant::factory()->create(['tiers_id' => $tiersC->id, 'operation_id' => $op1->id, 'medecin_tiers_id' => $suivi->id]);
    Participant::factory()->create(['tiers_id' => $tiersA->id, 'operation_id' => $op2->id, 'therapeute_tiers_id' => $suivi->id]);
    Participant::factory()->create(['tiers_id' => $tiersB->id, 'operation_id' => $op3->id, 'medecin_tiers_id' => $suivi->id]);

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($suivi);

    expect($dto->lignes[0]->tiersSuiviId())->toBe((int) $tiersA->id)
        ->and($dto->lignes[1]->tiersSuiviId())->toBe((int) $tiersB->id)
        ->and($dto->lignes[2]->tiersSuiviId())->toBe((int) $tiersC->id);
});

it('suitForTiers isole les autres tiers (même tenant)', function (): void {
    $medecinA = Tiers::factory()->create();
    $medecinB = Tiers::factory()->create();
    $patientA = Tiers::factory()->create();
    $patientB = Tiers::factory()->create();
    $op = Operation::factory()->create();

    Participant::factory()->create([
        'tiers_id' => $patientA->id,
        'operation_id' => $op->id,
        'medecin_tiers_id' => $medecinA->id,
    ]);

    $op2 = Operation::factory()->create();
    Participant::factory()->create([
        'tiers_id' => $patientB->id,
        'operation_id' => $op2->id,
        'medecin_tiers_id' => $medecinB->id,
    ]);

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($medecinA);

    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->tiersSuiviId())->toBe((int) $patientA->id);
});

it('suitForTiers isole multi-tenant', function (): void {
    $assoB = Association::factory()->create();

    // Créer des participants côté assoB
    TenantContext::boot($assoB);
    $suiviB = Tiers::factory()->create();
    $patientB = Tiers::factory()->create();
    $opB = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $patientB->id, 'operation_id' => $opB->id, 'medecin_tiers_id' => $suiviB->id]);

    // Revenir sur le tenant par défaut
    TenantContext::boot(Association::first()->fresh());

    $suiviA = Tiers::factory()->create();
    $patientA = Tiers::factory()->create();
    $opA = Operation::factory()->create();
    Participant::factory()->create(['tiers_id' => $patientA->id, 'operation_id' => $opA->id, 'medecin_tiers_id' => $suiviA->id]);

    $dto = app(TiersOperationsTimelineService::class)->suitForTiers($suiviA);

    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->tiersSuiviId())->toBe((int) $patientA->id);
});

// ── Phase 7c : section "Encadrement" ────────────────────────────────────────

it('encadrementForTiers retourne DTO vide si pas d\'opération encadrée', function (): void {
    $tiers = Tiers::factory()->create();

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    expect($dto)->toBeInstanceOf(EncadrementTimelineDTO::class)
        ->and($dto->totalCount)->toBe(0)
        ->and($dto->lignes)->toBe([]);
});

it('encadrementForTiers totalCount = nb opérations distinctes encadrées', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Formation']);
    $op1 = Operation::factory()->create(['type_operation_id' => $type->id]);
    $op2 = Operation::factory()->create(['type_operation_id' => $type->id]);

    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr2 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);

    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $op1->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr2->id, 'operation_id' => $op2->id, 'montant' => 200.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    expect($dto->totalCount)->toBe(2)
        ->and(count($dto->lignes))->toBe(2);
});

it('encadrementForTiers AGRÈGE le montant : 3 paiements sur même opération → 1 ligne avec montantTotal = somme', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Yoga']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);

    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr2 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr3 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);

    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $op->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr2->id, 'operation_id' => $op->id, 'montant' => 150.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr3->id, 'operation_id' => $op->id, 'montant' => 50.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->montantTotal())->toBe(300.00);
});

it('encadrementForTiers nbSeances = count distinct séances numérotées', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Kiné']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);

    // 3 paiements sur séances 1, 2, 1 → nbSeances=2
    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr2 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr3 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);

    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 50.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr2->id, 'operation_id' => $op->id, 'seance' => 2, 'montant' => 50.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr3->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 50.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    expect($dto->lignes[0]->nbSeances())->toBe(2);
});

it('encadrementForTiers nbSeances = 0 si paiement forfaitaire (seance null)', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Forfait']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);

    $tr = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    TransactionLigne::factory()->create(['transaction_id' => $tr->id, 'operation_id' => $op->id, 'seance' => null, 'montant' => 300.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    expect($dto->lignes[0]->nbSeances())->toBe(0)
        ->and($dto->lignes[0]->montantTotal())->toBe(300.00);
});

it('encadrementForTiers inclut tous statuts de règlement', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Sport']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);

    // EnAttente
    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense, 'statut_reglement' => StatutReglement::EnAttente]);
    // Recu
    $tr2 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense, 'statut_reglement' => StatutReglement::Recu]);
    // Pointe
    $tr3 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense, 'statut_reglement' => StatutReglement::Pointe]);

    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $op->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr2->id, 'operation_id' => $op->id, 'montant' => 200.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr3->id, 'operation_id' => $op->id, 'montant' => 50.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    // Tous statuts comptés → montantTotal = 100+200+50 = 350
    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->montantTotal())->toBe(350.00);
});

it('encadrementForTiers EXCLUT les transactions de type Recette', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Cours']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);

    // Transaction Recette avec operation_id → NE DOIT PAS apparaître dans Encadrement
    $trRecette = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Recette]);
    TransactionLigne::factory()->create(['transaction_id' => $trRecette->id, 'operation_id' => $op->id, 'montant' => 50.00]);

    // Pas de transaction Depense
    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    expect($dto->totalCount)->toBe(0)
        ->and($dto->lignes)->toBe([]);
});

it('encadrementForTiers trie par dateDebut DESC', function (): void {
    $tiers = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Danse']);

    $opAncienne = Operation::factory()->create(['type_operation_id' => $type->id, 'nom' => 'Op Ancienne']);
    Seance::factory()->create(['operation_id' => $opAncienne->id, 'date' => '2024-01-15']);

    $opRecente = Operation::factory()->create(['type_operation_id' => $type->id, 'nom' => 'Op Recente']);
    Seance::factory()->create(['operation_id' => $opRecente->id, 'date' => '2026-03-20']);

    $opSansSeance = Operation::factory()->create(['type_operation_id' => $type->id, 'nom' => 'Op Sans Seance']);

    $tr1 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr2 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);
    $tr3 = Transaction::factory()->create(['tiers_id' => $tiers->id, 'type' => TypeTransaction::Depense]);

    TransactionLigne::factory()->create(['transaction_id' => $tr1->id, 'operation_id' => $opAncienne->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr2->id, 'operation_id' => $opRecente->id, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $tr3->id, 'operation_id' => $opSansSeance->id, 'montant' => 100.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiers);

    expect($dto->totalCount)->toBe(3)
        // Récente en premier, ancienne ensuite, sans séance en queue
        ->and($dto->lignes[0]->operationId())->toBe((int) $opRecente->id)
        ->and($dto->lignes[1]->operationId())->toBe((int) $opAncienne->id)
        ->and($dto->lignes[2]->operationId())->toBe((int) $opSansSeance->id);
});

it('encadrementForTiers isole les autres tiers', function (): void {
    $tiersA = Tiers::factory()->create();
    $tiersB = Tiers::factory()->create();
    $type = TypeOperation::factory()->create(['nom' => 'Pilates']);
    $op = Operation::factory()->create(['type_operation_id' => $type->id]);

    // tiersA est payé sur op
    $trA = Transaction::factory()->create(['tiers_id' => $tiersA->id, 'type' => TypeTransaction::Depense]);
    TransactionLigne::factory()->create(['transaction_id' => $trA->id, 'operation_id' => $op->id, 'montant' => 200.00]);

    // tiersB est aussi payé sur la même op → ne doit pas fuiter pour tiersA
    $trB = Transaction::factory()->create(['tiers_id' => $tiersB->id, 'type' => TypeTransaction::Depense]);
    TransactionLigne::factory()->create(['transaction_id' => $trB->id, 'operation_id' => $op->id, 'montant' => 300.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiersA);

    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->montantTotal())->toBe(200.00);
});

it('encadrementForTiers isole multi-tenant', function (): void {
    $assoB = Association::factory()->create();

    // Créer des données côté assoB
    TenantContext::boot($assoB);
    $tiersIntrus = Tiers::factory()->create();
    $typeB = TypeOperation::factory()->create(['nom' => 'Type B']);
    $opIntrus = Operation::factory()->create(['type_operation_id' => $typeB->id]);
    $trIntrus = Transaction::factory()->create(['tiers_id' => $tiersIntrus->id, 'type' => TypeTransaction::Depense]);
    TransactionLigne::factory()->create(['transaction_id' => $trIntrus->id, 'operation_id' => $opIntrus->id, 'montant' => 999.00]);

    // Revenir sur le tenant par défaut
    TenantContext::boot(Association::first()->fresh());

    $tiersCourant = Tiers::factory()->create();
    $typeA = TypeOperation::factory()->create(['nom' => 'Type A']);
    $opCourant = Operation::factory()->create(['type_operation_id' => $typeA->id]);
    $trCourant = Transaction::factory()->create(['tiers_id' => $tiersCourant->id, 'type' => TypeTransaction::Depense]);
    TransactionLigne::factory()->create(['transaction_id' => $trCourant->id, 'operation_id' => $opCourant->id, 'montant' => 100.00]);

    $dto = app(TiersOperationsTimelineService::class)->encadrementForTiers($tiersCourant);

    expect($dto->totalCount)->toBe(1)
        ->and($dto->lignes[0]->montantTotal())->toBe(100.00);
});
