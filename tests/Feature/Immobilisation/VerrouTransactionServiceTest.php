<?php

declare(strict_types=1);

use App\Livewire\Immobilisations\DotationsExercice;
use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\TransactionAvecReglementService;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * Construit un jeu de $data/$lignes minimal mais valide pour appeler
 * TransactionService::update() sur $tx sans en modifier le contenu — sert de
 * témoin pour prouver que le verrou refuse la modification sans altérer la
 * transaction (au lieu de laisser passer un update "no-op" par accident).
 *
 * @return array{data: array<string, mixed>, lignes: array<int, array<string, mixed>>}
 */
function updatePayloadIdentique(Transaction $tx): array
{
    $tx->loadMissing('lignes');
    $ligne = $tx->lignes->first();

    return [
        'data' => [
            'type' => $tx->type->value,
            'date' => $tx->date->format('Y-m-d'),
            'libelle' => 'Libellé falsifié via update()',
            'montant_total' => (string) $tx->montant_total,
            'mode_paiement' => $tx->mode_paiement?->value,
            'tiers_id' => $tx->tiers_id,
            'compte_id' => $tx->compte_id,
            'reference' => $tx->reference,
        ],
        'lignes' => [[
            'id' => $ligne->id,
            'compte_id' => $ligne->compte_id,
            'montant' => (string) $ligne->montant,
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

/**
 * Bug 2 — la transaction d'acquisition d'une immobilisation ne doit jamais
 * pouvoir être supprimée, annulée ou extournée : la fiche resterait au
 * registre avec un transaction_id pointant sur du vide, le débit
 * disparaîtrait du grand livre, et les dotations continueraient à être
 * générées sur un bien qui n'est plus à l'actif.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    Compte::factory()->create(['numero_pcg' => '401', 'classe' => 4, 'est_systeme' => true]);
    ImmobilisationComptesSeeder::seed();

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: '20 tenues d’escrime',
        quantite: 20,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: null,
        compteTresorerie: null,
    );
});

it('refuse la suppression de la transaction d’acquisition', function (): void {
    $tx = Transaction::findOrFail((int) $this->immo->transaction_id);

    expect(fn () => app(TransactionService::class)->delete($tx))
        ->toThrow(RuntimeException::class, 'immobilisation');

    expect(Transaction::find($tx->id))->not->toBeNull();
});

it('refuse l’annulation de la transaction d’acquisition', function (): void {
    $tx = Transaction::findOrFail((int) $this->immo->transaction_id);

    expect(fn () => app(TransactionService::class)->annuler($tx, 'test'))
        ->toThrow(RuntimeException::class, 'immobilisation');

    expect($tx->fresh()->deleted_at)->toBeNull();
});

it('interdit l’extourne de la transaction d’acquisition', function (): void {
    $tx = Transaction::findOrFail((int) $this->immo->transaction_id);

    expect($tx->isExtournable())->toBeFalse();
});

/**
 * Bug audit — TransactionService::update() ne contrôlait pas
 * isLockedByImmobilisation() : seule l'IHM (propriété Livewire falsifiable)
 * protégeait l'écriture d'acquisition contre une réécriture directe du
 * service, via TransactionAvecReglementService ou un appel forgé.
 */
it('refuse la modification de la transaction d’acquisition via update()', function (): void {
    $tx = Transaction::findOrFail((int) $this->immo->transaction_id);
    $libelleOriginal = $tx->libelle;
    ['data' => $data, 'lignes' => $lignes] = updatePayloadIdentique($tx);

    expect(fn () => app(TransactionService::class)->update($tx, $data, $lignes))
        ->toThrow(RuntimeException::class, 'immobilisation');

    expect($tx->fresh()->libelle)->toBe($libelleOriginal);
});

/**
 * Chemin alternatif signalé par l'audit : TransactionAvecReglementService
 * délègue à TransactionService::update() en interne — le verrou doit donc
 * s'appliquer aussi de ce côté, sans dépendre de l'appelant.
 */
it('refuse la modification de la transaction d’acquisition via TransactionAvecReglementService', function (): void {
    $tx = Transaction::findOrFail((int) $this->immo->transaction_id);
    $libelleOriginal = $tx->libelle;
    ['data' => $data, 'lignes' => $lignes] = updatePayloadIdentique($tx);

    expect(fn () => app(TransactionAvecReglementService::class)->enregistrer(
        $tx, $data, $lignes, null, null, null, 2026
    ))->toThrow(RuntimeException::class, 'immobilisation');

    expect($tx->fresh()->libelle)->toBe($libelleOriginal);
});

it('expose isLockedByImmobilisation() sur la transaction d’acquisition', function (): void {
    $tx = Transaction::findOrFail((int) $this->immo->transaction_id);

    expect($tx->isLockedByImmobilisation())->toBeTrue();
});

it('laisse une transaction ordinaire supprimable, annulable et extournable', function (): void {
    $compte6 = Compte::factory()->create(['numero_pcg' => '606', 'classe' => 6]);
    $tx = Transaction::factory()->asDepense()->create();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte6->id,
        'debit' => 100,
    ]);

    expect($tx->isLockedByImmobilisation())->toBeFalse()
        ->and($tx->isExtournable())->toBeTrue();

    app(TransactionService::class)->delete($tx->fresh());

    expect(Transaction::find($tx->id))->toBeNull();
});

it('laisse une transaction ordinaire modifiable via update()', function (): void {
    $compte6 = Compte::factory()->create(['numero_pcg' => '607', 'classe' => 6]);
    $tx = Transaction::factory()->asDepense()->create(['montant_total' => 100]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte6->id,
        'debit' => 100,
        'montant' => 100,
    ]);
    $tx = $tx->fresh();

    ['data' => $data, 'lignes' => $lignes] = updatePayloadIdentique($tx);

    app(TransactionService::class)->update($tx, $data, $lignes);

    expect($tx->fresh()->libelle)->toBe('Libellé falsifié via update()');
});

/**
 * La dotation aux amortissements est elle aussi calculée par
 * PlanAmortissementCalculator à partir de la fiche : ses montants et ses
 * comptes ne doivent pas diverger de ce que la fiche produit. Même verrou
 * que l'acquisition, via ImmobilisationDotation.transaction_id cette fois.
 */
describe('verrou de la transaction de dotation', function (): void {
    beforeEach(function (): void {
        // Reste au-delà de la fin de l'exercice 2026 (pas de reset intermédiaire) :
        // DotationService::annuler()/recalculer() revalident assertExerciceGenerable()
        // à chaque appel, y compris ceux faits plus tard dans le corps du test.
        Carbon::setTestNow('2027-10-15');
        Livewire::test(DotationsExercice::class)->set('exercice', 2026)->call('genererTout');

        $this->dotation = ImmobilisationDotation::where('exercice', 2026)
            ->where('immobilisation_id', $this->immo->id)
            ->firstOrFail();
    });

    it('expose isLockedByImmobilisation() sur la transaction de dotation', function (): void {
        $tx = Transaction::findOrFail((int) $this->dotation->transaction_id);

        expect($tx->isLockedByImmobilisation())->toBeTrue();
    });

    it('refuse la suppression de la transaction de dotation', function (): void {
        $tx = Transaction::findOrFail((int) $this->dotation->transaction_id);

        expect(fn () => app(TransactionService::class)->delete($tx))
            ->toThrow(RuntimeException::class, 'immobilisation');

        expect(Transaction::find($tx->id))->not->toBeNull();
    });

    it('refuse l’annulation de la transaction de dotation', function (): void {
        $tx = Transaction::findOrFail((int) $this->dotation->transaction_id);

        expect(fn () => app(TransactionService::class)->annuler($tx, 'test'))
            ->toThrow(RuntimeException::class, 'immobilisation');

        expect($tx->fresh()->deleted_at)->toBeNull();
    });

    it('interdit l’extourne de la transaction de dotation', function (): void {
        $tx = Transaction::findOrFail((int) $this->dotation->transaction_id);

        expect($tx->isExtournable())->toBeFalse();
    });

    it('refuse la modification de la transaction de dotation via update()', function (): void {
        $tx = Transaction::findOrFail((int) $this->dotation->transaction_id);
        $libelleOriginal = $tx->libelle;
        ['data' => $data, 'lignes' => $lignes] = updatePayloadIdentique($tx);

        expect(fn () => app(TransactionService::class)->update($tx, $data, $lignes))
            ->toThrow(RuntimeException::class, 'immobilisation');

        expect($tx->fresh()->libelle)->toBe($libelleOriginal);
    });

    /**
     * Point important de l'anomalie 1 : le verrou d'update() ne doit jamais
     * bloquer la ventilation analytique (répartition de la dotation sur des
     * opérations) — un besoin métier réel, couvert côté écran par
     * DotationVentilerTest / VerrouTransactionChampsTest. Ce test le confirme
     * directement au niveau service, sans passer par Livewire.
     */
    it('laisse la ventilation analytique de la ligne 6811 fonctionner malgré le verrou update()', function (): void {
        $tx = Transaction::findOrFail((int) $this->dotation->transaction_id);
        $operation = Operation::factory()->create();
        $ligne = TransactionLigne::where('transaction_id', $tx->id)
            ->whereHas('compte', fn ($q) => $q->where('numero_pcg', '6811'))
            ->firstOrFail();

        app(TransactionService::class)->affecterLigne($ligne, [[
            'operation_id' => $operation->id,
            'seance' => null,
            'montant' => (string) $ligne->montant,
            'notes' => null,
        ]]);

        expect($ligne->affectations()->count())->toBe(1)
            ->and((int) $ligne->affectations()->first()->operation_id)->toBe((int) $operation->id);

        app(TransactionService::class)->supprimerAffectations($ligne);

        expect($ligne->affectations()->count())->toBe(0);
    });

    /**
     * Le piège : DotationService::annuler() supprime la transaction via le
     * delete() du modèle Eloquent ($dotation->transaction?->delete()), pas via
     * TransactionService::delete() qui porte la garde. Étendre le verrou ne doit
     * donc jamais empêcher l'annulation ou le recalcul depuis l'écran des
     * dotations — sinon la fonctionnalité existante casse silencieusement.
     */
    it('laisse DotationService::annuler() supprimer la transaction malgré le verrou', function (): void {
        $transactionId = (int) $this->dotation->transaction_id;

        app(DotationService::class)->annuler($this->immo, 2026);

        expect(ImmobilisationDotation::where('exercice', 2026)->where('immobilisation_id', $this->immo->id)->count())->toBe(0)
            ->and(Transaction::withTrashed()->findOrFail($transactionId)->trashed())->toBeTrue();
    });

    it('laisse DotationService::recalculer() fonctionner malgré le verrou', function (): void {
        $ancienTransactionId = (int) $this->dotation->transaction_id;

        $this->immo->update(['duree_mois' => 30]);

        app(DotationService::class)->recalculer($this->immo, 2026);

        $nouvelleDotation = ImmobilisationDotation::where('exercice', 2026)
            ->where('immobilisation_id', $this->immo->id)
            ->firstOrFail();

        expect(Transaction::withTrashed()->findOrFail($ancienTransactionId)->trashed())->toBeTrue()
            ->and((int) $nouvelleDotation->transaction_id)->not->toBe($ancienTransactionId)
            ->and(Transaction::findOrFail((int) $nouvelleDotation->transaction_id)->isLockedByImmobilisation())->toBeTrue();
    });
});
