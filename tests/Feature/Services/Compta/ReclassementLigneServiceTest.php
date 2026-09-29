<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\SensVentilation;
use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\PartieDoubleGuard;
use App\Services\Compta\ReclassementLigneService;
use Tests\Support\CreatesPartieDoubleContext;

/*
 * Reclasser = corriger l'imputation d'une ligne déjà réglée, sans toucher au
 * montant, à la contrepartie 411/401, à son lettrage ni au rapprochement.
 * Toutes les gardes vivent ici : classe du compte, reçu fiscal actif, exercice
 * clôturé, motif obligatoire.
 *
 * Attention aux montants : les lignes produites par EcritureGenerator portent
 * leur montant dans `debit` / `credit` et ont `montant = 0`. Le service ne doit
 * donc JAMAIS recalculer le débit ou le crédit depuis `montant` (cela mettrait
 * l'écriture à zéro), ni depuis le type de la pièce (cela retournerait une
 * contre-écriture, comme une gratuité 709A au débit).
 */

uses(CreatesPartieDoubleContext::class);

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->service = app(ReclassementLigneService::class);

    $this->compte754 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '754'],
        ['intitule' => 'Dons manuels', 'classe' => 7, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );
    $this->compte706B = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706B'],
        ['intitule' => 'Parcours thérapeutiques', 'classe' => 7, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );
});

/**
 * Recette réglée à l'encaissement : 411 D / 411 C (lettrées entre elles),
 * 754 C 175 et 512X D 175. Retourne la ligne 754.
 */
function ligneDonPourReclassementTest(object $ctx, string $date = '2025-11-15'): TransactionLigne
{
    $tx = app(EcritureGenerator::class)->pourRecetteComptant(
        tiers: Tiers::factory()->create(['association_id' => $ctx->association->id]),
        ventilations: [['compte' => $ctx->compte754, 'montant' => 175.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $ctx->compte512X,
        date: new DateTimeImmutable($date),
        libelle: 'HelloAsso — don additionnel',
    );

    return $tx->lignes()->where('compte_id', $ctx->compte754->id)->sole();
}

/**
 * Photo des lignes d'une transaction autres que `$sauf` : tout ce que le
 * reclassement n'a pas le droit de toucher.
 *
 * @return array<int, array<string, mixed>>
 */
function photoAutresLignesPourReclassementTest(Transaction $tx, int $sauf): array
{
    return $tx->lignes()->where('id', '!=', $sauf)->orderBy('id')->get()
        ->mapWithKeys(fn (TransactionLigne $l): array => [(int) $l->id => [
            'compte_id' => (int) $l->compte_id,
            'debit' => (float) $l->debit,
            'credit' => (float) $l->credit,
            'montant' => (float) $l->montant,
            'lettrage_code' => $l->lettrage_code,
            'tiers_id' => $l->tiers_id,
            'reclassee_at' => $l->reclassee_at,
        ]])
        ->all();
}

it('reclasse le compte, garde le crédit et pose la marque', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $avant = ['debit' => (float) $ligne->debit, 'credit' => (float) $ligne->credit, 'montant' => (float) $ligne->montant];
    expect($avant['credit'])->toBe(175.00);

    $this->service->reclasser(
        ligne: $ligne,
        compteId: (int) $this->compte706B->id,
        operationId: null,
        seance: null,
        motif: 'Règlement de parcours saisi en don par le payeur',
        auteur: $this->user,
    );

    $ligne->refresh();
    expect((int) $ligne->compte_id)->toBe((int) $this->compte706B->id)
        ->and((float) $ligne->credit)->toBe(175.00)
        ->and((float) $ligne->debit)->toBe(0.0)
        ->and((float) $ligne->montant)->toBe($avant['montant'])
        ->and($ligne->estReclassee())->toBeTrue()
        ->and((int) $ligne->reclassement_compte_origine_id)->toBe((int) $this->compte754->id)
        ->and($ligne->reclassement_motif)->toBe('Règlement de parcours saisi en don par le payeur')
        ->and((int) $ligne->reclassee_par_user_id)->toBe((int) $this->user->id);
});

it('laisse la contrepartie, son lettrage et le montant total intacts', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $tx = $ligne->transaction;

    // La fixture est bien une transaction réglée : le 411 est lettré.
    expect($tx->aUnReglementTiers())->toBeTrue();
    $photo = photoAutresLignesPourReclassementTest($tx, (int) $ligne->id);
    expect(collect($photo)->pluck('lettrage_code')->filter()->count())->toBeGreaterThanOrEqual(2);

    $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Erreur de saisie du payeur', $this->user);

    expect(photoAutresLignesPourReclassementTest($tx, (int) $ligne->id))->toBe($photo)
        ->and((float) $tx->fresh()->montant_total)->toBe(175.00)
        ->and($tx->fresh()->aUnReglementTiers())->toBeTrue();
    PartieDoubleGuard::assertComplete($tx->fresh());
});

it('garde le compte d\'origine après un second reclassement', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $autre = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '706C'],
        ['intitule' => 'Autres prestations', 'classe' => 7, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );

    $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Premier reclassement de test', $this->user);
    $this->service->reclasser($ligne->fresh(), (int) $autre->id, null, null, 'Second reclassement de test', $this->user);

    $ligne->refresh();
    expect((int) $ligne->reclassement_compte_origine_id)->toBe((int) $this->compte754->id)
        ->and((int) $ligne->compte_id)->toBe((int) $autre->id)
        ->and($ligne->reclassement_motif)->toBe('Second reclassement de test');
});

it('range la ligne sous l\'opération et la séance choisies', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => TypeOperation::factory()->create(['association_id' => $this->association->id])->id,
    ]);

    $this->service->reclasser($ligne, (int) $this->compte706B->id, (int) $operation->id, 2, 'Rattachement au parcours concerné', $this->user);

    $ligne->refresh();
    expect((int) $ligne->operation_id)->toBe((int) $operation->id)
        ->and((int) $ligne->seance)->toBe(2);
});

it('refuse une opération d\'une autre association', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $autreAsso = Association::factory()->create();
    $operationEtrangere = Operation::factory()->create([
        'association_id' => $autreAsso->id,
        'type_operation_id' => TypeOperation::factory()->create(['association_id' => $autreAsso->id])->id,
    ]);

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, (int) $operationEtrangere->id, null, 'Opération d\'une autre association', $this->user))
        ->toThrow(RuntimeException::class);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id)
        ->and($ligne->fresh()->operation_id)->toBeNull();
});

it('conserve le sens d\'une contre-écriture au débit (gratuité 709A)', function () {
    $compte709A = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '709A'],
        ['intitule' => 'Gratuités accordées', 'classe' => 7, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );
    $tx = app(EcritureGenerator::class)->pourRecetteACredit(
        tiers: Tiers::factory()->create(['association_id' => $this->association->id]),
        ventilations: [
            ['sens' => SensVentilation::Credit, 'compte' => $this->compte706B, 'montant' => 200.00],
            ['sens' => SensVentilation::Debit, 'compte' => $compte709A, 'montant' => 25.00],
        ],
        dateConstatation: new DateTimeImmutable('2025-11-15'),
        libelle: 'Séance avec place offerte',
    );
    $contra = $tx->lignes()->where('compte_id', $compte709A->id)->sole();
    expect((float) $contra->debit)->toBe(25.00);

    $this->service->reclasser($contra, (int) $this->compte754->id, null, null, 'Gratuité rangée sur le mauvais compte', $this->user);

    $contra->refresh();
    expect((int) $contra->compte_id)->toBe((int) $this->compte754->id)
        ->and((float) $contra->debit)->toBe(25.00)
        ->and((float) $contra->credit)->toBe(0.0);
    PartieDoubleGuard::assertComplete($tx->fresh());
});

it('reclasse une charge vers un autre compte de classe 6', function () {
    $compte616 = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '616'],
        ['intitule' => 'Assurances', 'classe' => 6, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );
    $tx = app(EcritureGenerator::class)->pourDepenseComptant(
        tiers: Tiers::factory()->create(['association_id' => $this->association->id]),
        ventilations: [['compte' => $this->compte606, 'montant' => 80.00]],
        mode: ModePaiement::Virement,
        compteTresorerie: $this->compte512X,
        date: new DateTimeImmutable('2025-11-15'),
        libelle: 'Facture mal classée',
    );
    $charge = $tx->lignes()->where('compte_id', $this->compte606->id)->sole();

    $this->service->reclasser($charge, (int) $compte616->id, null, null, 'Assurance rangée en fournitures', $this->user);

    $charge->refresh();
    expect((int) $charge->compte_id)->toBe((int) $compte616->id)
        ->and((float) $charge->debit)->toBe(80.00)
        ->and((float) $charge->credit)->toBe(0.0);

    // Et une charge ne se reclasse pas vers un compte de produit.
    expect(fn () => $this->service->reclasser($charge, (int) $this->compte754->id, null, null, 'Vers un compte de produit', $this->user))
        ->toThrow(RuntimeException::class);
});

it('refuse une ligne dont la transaction a été supprimée', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $ligne->transaction->delete();

    expect(fn () => $this->service->reclasser($ligne->fresh(), (int) $this->compte706B->id, null, null, 'Transaction supprimée entre-temps', $this->user))
        ->toThrow(RuntimeException::class, 'aucune transaction');

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('refuse une pièce qui n\'est ni une recette ni une dépense', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $ligne->transaction->update(['type' => TypeTransaction::Virement]);

    expect(fn () => $this->service->reclasser($ligne->fresh(), (int) $this->compte706B->id, null, null, 'Type de pièce non reclassable', $this->user))
        ->toThrow(RuntimeException::class, 'recette ou une dépense');

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('refuse un compte de la mauvaise classe', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $charge = Compte::firstOrCreate(
        ['association_id' => $this->association->id, 'numero_pcg' => '606'],
        ['intitule' => 'Achats', 'classe' => 6, 'actif' => true, 'lettrable' => false, 'est_systeme' => false, 'pour_inscriptions' => false],
    );

    expect(fn () => $this->service->reclasser($ligne, (int) $charge->id, null, null, 'Tentative de classe 6', $this->user))
        ->toThrow(RuntimeException::class);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id)
        ->and($ligne->fresh()->estReclassee())->toBeFalse();
});

it('refuse un compte inactif', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $this->compte706B->update(['actif' => false]);

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Compte désactivé entre-temps', $this->user))
        ->toThrow(RuntimeException::class);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('refuse un compte d\'une autre association', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $autreAsso = Association::factory()->create();
    $compteEtranger = Compte::withoutGlobalScopes()->create([
        'association_id' => $autreAsso->id,
        'numero_pcg' => '706Z',
        'intitule' => 'Compte d\'une autre association',
        'classe' => 7,
        'actif' => true,
        'lettrable' => false,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    expect(fn () => $this->service->reclasser($ligne, (int) $compteEtranger->id, null, null, 'Compte d\'une autre association', $this->user))
        ->toThrow(RuntimeException::class);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('refuse de reclasser une ligne de contrepartie 411', function () {
    $ligne = ligneDonPourReclassementTest($this);
    $ligne411 = $ligne->transaction->lignes()->whereHas('compte', fn ($q) => $q->where('numero_pcg', '411'))->orderBy('id')->firstOrFail();

    expect(fn () => $this->service->reclasser($ligne411, (int) $this->compte706B->id, null, null, 'Tentative sur la contrepartie', $this->user))
        ->toThrow(RuntimeException::class);

    expect($ligne411->fresh()->compte->numero_pcg)->toBe('411')
        ->and($ligne411->fresh()->estReclassee())->toBeFalse();
});

it('refuse quand un reçu fiscal actif porte sur la ligne', function () {
    $ligne = ligneDonPourReclassementTest($this);
    // Le tiers est porté par les lignes 411 (l'en-tête de la pièce n'en a pas).
    $tiersId = (int) $ligne->transaction->lignes()->whereNotNull('tiers_id')->value('tiers_id');
    $recu = RecuFiscalEmis::create([
        'association_id' => $this->association->id,
        'numero' => '2025-0001',
        'annee_civile' => 2025,
        'tiers_id' => $tiersId,
        'transaction_ligne_id' => (int) $ligne->id,
        'montant_centimes' => 17500,
        'date_versement' => '2025-11-15',
        'mode_versement' => 'virement',
        'forme_don' => 'numeraire',
        'article_cgi' => 'art_200',
        'pdf_path' => 'recus_fiscaux/2025/2025-0001.pdf',
        'pdf_hash' => str_repeat('a', 64),
        'emitted_at' => now(),
    ]);

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Reclassement refusé attendu', $this->user))
        ->toThrow(RuntimeException::class);
    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);

    // Reçu annulé → le reclassement redevient possible
    $recu->update(['annule_at' => now(), 'annule_motif' => 'Erreur']);
    $this->service->reclasser($ligne->fresh(), (int) $this->compte706B->id, null, null, 'Reclassement après annulation', $this->user);
    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte706B->id);
});

it('refuse un motif vide ou trop court', function () {
    $ligne = ligneDonPourReclassementTest($this);

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, '   ', $this->user))
        ->toThrow(RuntimeException::class);
    // 9 caractères : refusé. Le seuil est de 10.
    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Trop bref', $this->user))
        ->toThrow(RuntimeException::class);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);

    // 10 caractères (les espaces autour ne comptent pas) : accepté.
    $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, '  Dix signes  ', $this->user);
    expect($ligne->fresh()->reclassement_motif)->toBe('Dix signes');
});

it('refuse un motif de plus de 255 caractères', function () {
    $ligne = ligneDonPourReclassementTest($this);

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, str_repeat('a', 256), $this->user))
        ->toThrow(RuntimeException::class);

    // 255 caractères : accepté (la colonne est un varchar(255))
    $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, str_repeat('a', 255), $this->user);
    expect(mb_strlen((string) $ligne->fresh()->reclassement_motif))->toBe(255);
});

it('annule tout quand le grand livre de la pièce est déséquilibré', function () {
    $ligne = ligneDonPourReclassementTest($this);
    // Pièce déjà déséquilibrée : la garde de partie double doit annuler l'écriture.
    $ligne->transaction->lignes()->whereNotNull('tiers_id')->orderBy('id')->firstOrFail()->update(['debit' => 999.00]);

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Pièce déséquilibrée attendue', $this->user))
        ->toThrow(RuntimeException::class);

    // L'écriture avait eu lieu avant la garde : compte inchangé = transaction annulée.
    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id)
        ->and($ligne->fresh()->estReclassee())->toBeFalse();
});

it('refuse quand l\'exercice de la transaction est clôturé', function () {
    $ligne = ligneDonPourReclassementTest($this);
    Exercice::updateOrCreate(
        ['association_id' => $this->association->id, 'annee' => 2025],
        ['statut' => 'cloture'],
    );

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Exercice clôturé attendu', $this->user))
        ->toThrow(ExerciceCloturedException::class);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});

it('contrôle l\'exercice de la pièce, pas l\'exercice courant', function () {
    // « Aujourd'hui » (2026-01-15) tombe dans l'exercice 2025, ouvert ; la pièce
    // date de l'exercice 2024, clôturé.
    $ligne = ligneDonPourReclassementTest($this, '2024-11-15');
    Exercice::updateOrCreate(
        ['association_id' => $this->association->id, 'annee' => 2024],
        ['statut' => 'cloture'],
    );

    expect(fn () => $this->service->reclasser($ligne, (int) $this->compte706B->id, null, null, 'Pièce d\'un exercice clôturé', $this->user))
        ->toThrow(ExerciceCloturedException::class);

    expect((int) $ligne->fresh()->compte_id)->toBe((int) $this->compte754->id);
});
