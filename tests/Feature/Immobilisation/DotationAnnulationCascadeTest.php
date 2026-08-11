<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\ImmobilisationDotation;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Services\Immobilisation\DotationService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\Carbon;

/**
 * Anomalie 2 (audit) — annuler() se contentait de soft-supprimer la
 * transaction et de retirer la ligne ImmobilisationDotation. Les
 * transaction_lignes (qui ne cascadent pas depuis leur transaction parente,
 * cf. docblock de App\Tenant\TransactionLigneTenantScope) et les affectations
 * analytiques (transaction_ligne_affectations) restaient actives :
 * lignes orphelines, ventilations périmées, comptes à tort considérés
 * « utilisés », accumulation après plusieurs recalculs.
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

it('soft-supprime les lignes et détruit les affectations lors de l’annulation d’une dotation', function (): void {
    Carbon::setTestNow('2027-10-15');
    app(DotationService::class)->generer(2026);

    $dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
    $transactionId = (int) $dotation->transaction_id;

    $lignes = TransactionLigne::where('transaction_id', $transactionId)->get();
    expect($lignes)->toHaveCount(2);

    // Simule une ligne déjà ventilée (bouton « Ventiler » de l'écran des dotations).
    $ligne6811 = $lignes->firstWhere(fn (TransactionLigne $l): bool => (int) $l->compte_id === (int) Compte::ofNumero('6811')->id);
    $operation = Operation::factory()->create();
    $ligne6811->affectations()->create([
        'operation_id' => $operation->id,
        'montant' => $ligne6811->montant,
    ]);
    expect(TransactionLigneAffectation::where('transaction_ligne_id', $ligne6811->id)->count())->toBe(1);

    app(DotationService::class)->annuler($this->immo->fresh(), 2026);

    expect(TransactionLigne::where('transaction_id', $transactionId)->count())->toBe(0)
        ->and(TransactionLigne::withTrashed()->where('transaction_id', $transactionId)->count())->toBe(2)
        ->and(TransactionLigne::withTrashed()->where('transaction_id', $transactionId)->get()->every(fn (TransactionLigne $l): bool => $l->trashed()))->toBeTrue()
        ->and(TransactionLigneAffectation::where('transaction_ligne_id', $ligne6811->id)->count())->toBe(0)
        ->and(Transaction::withTrashed()->findOrFail($transactionId)->trashed())->toBeTrue();

    Carbon::setTestNow();
});

it('ne laisse aucune ligne active après plusieurs cycles génération/annulation', function (): void {
    Carbon::setTestNow('2027-10-15');

    $transactionIds = [];

    for ($i = 0; $i < 3; $i++) {
        app(DotationService::class)->generer(2026);
        $dotation = ImmobilisationDotation::where('exercice', 2026)->firstOrFail();
        $transactionIds[] = (int) $dotation->transaction_id;

        app(DotationService::class)->annuler($this->immo->fresh(), 2026);
    }

    expect(ImmobilisationDotation::where('exercice', 2026)->count())->toBe(0)
        ->and(TransactionLigne::whereIn('transaction_id', $transactionIds)->count())->toBe(0)
        ->and(TransactionLigne::withTrashed()->whereIn('transaction_id', $transactionIds)->count())->toBe(6);

    Carbon::setTestNow();
});
