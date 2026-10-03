<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\FormuleAdhesion;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\TransactionService;
use App\Tenant\TenantContext;

/*
 * Spec 2026-10-02, E1 / E2 — la règle « une adhésion par tiers et par saison » ne doit
 * JAMAIS empêcher d'enregistrer un encaissement.
 *
 * Une ligne de cotisation saisie sur un tiers déjà adhérent de la saison, sur une formule
 * dont la période ne coïncide pas avec l'adhésion existante, heurte la clé unique
 * (tiers, exercice, date_debut). L'observer — l'une des deux portes de saisie d'une
 * cotisation, via l'écran Comptabilité / Cotisations — ne doit pas faire tomber l'écran.
 */

beforeEach(function (): void {
    $this->compte = Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => '756OC',
        'intitule' => 'Cotisations',
        'classe' => 7,
        'actif' => true,
    ]);
    $this->compte->usages()->create(['usage' => UsageComptable::Cotisation->value]);
    // Formule de 3 mois : du 1er septembre au 30 novembre.
    FormuleAdhesion::factory()->modeDuree(3)->create(['compte_id' => $this->compte->id]);

    $this->tiers = Tiers::factory()->create();
    // Adhérent de la saison 2026, pour 6 mois à partir du même 1er septembre.
    $this->existante = Adhesion::factory()->create([
        'tiers_id' => $this->tiers->id,
        'exercice' => 2026,
        'date_debut' => '2026-09-01',
        'date_fin' => '2027-02-28',
        'mode' => 'duree',
    ]);
});

it('E3 · l\'observer enregistre une ligne de cotisation sur un tiers déjà adhérent de la saison, sans exception', function (): void {
    $tx = Transaction::factory()->asRecette()->create(['tiers_id' => $this->tiers->id, 'date' => '2026-09-01']);
    TransactionLigne::where('transaction_id', $tx->id)->forceDelete();

    // L'observer tourne sur la création de la ligne : il ne doit pas propager d'exception.
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte->id,
        'montant' => 50.00,
        'debit' => 0,
        'credit' => 50.00,
    ]);

    expect($ligne->exists)->toBeTrue()
        ->and(Transaction::find($tx->id))->not->toBeNull()
        ->and(TransactionLigne::where('transaction_id', $tx->id)->count())->toBe(1)
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(1)
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->first()->id)->toBe($this->existante->id);
});

it('E3 · l\'enregistrement d\'une cotisation par le service de transaction réussit sur un tiers déjà adhérent', function (): void {
    // Le chemin des écrans de saisie : TransactionService::create, observer actif.
    SystemeSeeder::seed();
    $banque = CompteBancaire::factory()->create();

    $tx = app(TransactionService::class)->create(
        data: [
            'type' => TypeTransaction::Recette->value,
            'date' => '2026-09-01',
            'libelle' => 'Cotisation',
            'montant_total' => 25.00,
            'mode_paiement' => ModePaiement::Cheque->value,
            'tiers_id' => $this->tiers->id,
            'compte_id' => $banque->id,
            'reference' => null,
        ],
        lignes: [['compte_id' => $this->compte->id, 'montant' => 25.00]],
    );

    expect(Transaction::find($tx->id))->not->toBeNull()
        ->and(Adhesion::where('tiers_id', $this->tiers->id)->count())->toBe(1);
});
