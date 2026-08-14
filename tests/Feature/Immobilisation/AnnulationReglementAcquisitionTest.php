<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\ReglementOperationService;
use App\Tenant\TenantContext;
use Carbon\Carbon;

/**
 * Recette du 13/08/2026 — « Annuler le règlement » sur la transaction
 * d'acquisition d'une immobilisation : le statut repasse bien à « Dû », mais
 * le solde du compte bancaire reste celui d'une immobilisation payée.
 *
 * Reproduction avant diagnostic : on vérifie séparément (1) que la T2 de
 * règlement disparaît réellement, et (2) que le grand livre du compte de
 * trésorerie revient à son état d'avant paiement. Le solde affiché se dérive
 * de (2) — si (1) passe et (2) échoue, le défaut est dans les lignes laissées
 * derrière, pas dans la suppression.
 */
beforeEach(function (): void {
    $association = TenantContext::current();
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($user);

    SystemeSeeder::seed();
    ImmobilisationComptesSeeder::seed();

    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $association->id,
        'iban' => 'FR7612345000012345678901234',
    ]);
    BancairesSeeder::seed();

    $this->compte512 = Compte::query()
        ->where('compte_bancaire_id', $this->compteBancaire->id)
        ->bancaires()
        ->firstOrFail();

    $this->immo = app(ImmobilisationService::class)->acquerir(
        tiers: Tiers::factory()->create(),
        libelle: 'Vidéoprojecteur',
        quantite: 1,
        compte: Compte::ofNumero('2188'),
        compteAmortissement: Compte::ofNumero('28188'),
        montant: '3000.00',
        dateAchat: Carbon::parse('2026-09-12'),
        dateMiseEnService: Carbon::parse('2026-09-12'),
        dureeMois: 60,
        modePaiement: ModePaiement::Virement,
        compteTresorerie: $this->compte512,
    );

    /** Solde du grand livre sur le compte de trésorerie (débit - crédit). */
    $this->soldeTresorerie = function (): float {
        return round((float) TransactionLigne::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->where('transaction_lignes.compte_id', (int) $this->compte512->id)
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_lignes.deleted_at')
            ->selectRaw('COALESCE(SUM(transaction_lignes.debit),0) - COALESCE(SUM(transaction_lignes.credit),0) AS net')
            ->value('net'), 2);
    };
});

it('a bien décaissé le compte de trésorerie à l’acquisition payée', function (): void {
    expect(($this->soldeTresorerie)())->toBe(-3000.00);
});

it('supprime la T2 de règlement quand on annule le règlement', function (): void {
    $t2 = app(ReglementOperationService::class)->trouverT2($this->immo->transaction);
    expect($t2)->not->toBeNull();

    app(PosteTiersReglementService::class)->annuler((int) $t2->id);

    expect(Transaction::withTrashed()->find($t2->id))->toBeNull();
});

it('ramène le solde de trésorerie à zéro quand on annule le règlement', function (): void {
    $t2 = app(ReglementOperationService::class)->trouverT2($this->immo->transaction);

    app(PosteTiersReglementService::class)->annuler((int) $t2->id);

    expect(($this->soldeTresorerie)())->toBe(0.00);
});

it('laisse la fiche et son écriture d’acquisition intactes', function (): void {
    $t2 = app(ReglementOperationService::class)->trouverT2($this->immo->transaction);

    app(PosteTiersReglementService::class)->annuler((int) $t2->id);

    expect(Immobilisation::count())->toBe(1)
        ->and(Transaction::find($this->immo->transaction_id))->not->toBeNull();
});
