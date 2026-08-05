<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\TransactionService;
use Carbon\Carbon;

/**
 * Bug 2 — la transaction d'acquisition d'une immobilisation ne doit jamais
 * pouvoir être supprimée, annulée ou extournée : la fiche resterait au
 * registre avec un transaction_id pointant sur du vide, le débit
 * disparaîtrait du grand livre, et les dotations continueraient à être
 * générées sur un bien qui n'est plus à l'actif.
 */
beforeEach(function (): void {
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
