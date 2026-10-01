<?php

declare(strict_types=1);

use App\Enums\CasProvision;
use App\Enums\JournalComptable;
use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ProvisionService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    Exercice::create(['association_id' => $this->association->id, 'annee' => 2026, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Fabrique une ligne de produit (classe 7, au crédit) ou de charge (classe 6, au débit),
 * datée dans l'exercice 2025, éventuellement ventilée sur une opération.
 */
function ligneOrigine(int $classe, float $montant = 175.0, ?int $operationId = null, ?int $seance = null): TransactionLigne
{
    $assocId = (int) TenantContext::currentId();
    $compte = Compte::factory()->create([
        'association_id' => $assocId,
        'numero_pcg' => $classe === 7 ? '706Z' : '606Z',
        'classe' => $classe,
        'actif' => true,
    ]);

    $tx = Transaction::create([
        'association_id' => $assocId,
        'type' => $classe === 7 ? TypeTransaction::Recette : TypeTransaction::Depense,
        'date' => '2026-04-08',
        'libelle' => 'Pièce d’origine',
        'montant_total' => $montant,
        'saisi_par' => auth()->id(),
        'equilibree' => true,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Vente,
    ]);

    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => $classe === 6 ? $montant : 0,
        'credit' => $classe === 7 ? $montant : 0,
        'montant' => $montant,
        'operation_id' => $operationId,
        'seance' => $seance,
        'libelle' => 'Ligne d’origine',
    ]);
}

test('le cas est déduit de la classe et de l’adossement', function () {
    $ligne7 = ligneOrigine(7);
    $ligne6 = ligneOrigine(6);

    $pca = Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne7->id,
        'montant' => 175.0,
        'saisi_par' => auth()->id(),
    ]);
    $cca = Provision::create([
        'association_id' => TenantContext::currentId(),
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne6->id,
        'montant' => 175.0,
        'saisi_par' => auth()->id(),
    ]);

    expect($pca->cas())->toBe(CasProvision::ProduitConstateDavance);
    expect($cca->cas())->toBe(CasProvision::ChargeConstateeDavance);
    expect($pca->estAdossee())->toBeTrue();
});

test('AC-4 — l’invariant des deux colonnes est refusé dans les deux sens', function () {
    $assocId = (int) TenantContext::currentId();
    $ligne = ligneOrigine(7);
    $compte = Compte::factory()->create(['association_id' => $assocId, 'numero_pcg' => '706X', 'classe' => 7]);

    // Aucune des deux renseignées.
    expect(fn () => Provision::create([
        'association_id' => $assocId, 'exercice' => 2025,
        'montant' => 10.0, 'saisi_par' => auth()->id(),
    ]))->toThrow(InvalidArgumentException::class);

    // Les deux renseignées.
    expect(fn () => Provision::create([
        'association_id' => $assocId, 'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id, 'compte_id' => $compte->id,
        'montant' => 10.0, 'saisi_par' => auth()->id(),
    ]))->toThrow(InvalidArgumentException::class);
});

test('un montant nul ou négatif est refusé (D12)', function () {
    $ligne = ligneOrigine(7);

    foreach ([0.0, -175.0] as $montant) {
        expect(fn () => Provision::create([
            'association_id' => TenantContext::currentId(), 'exercice' => 2025,
            'transaction_ligne_id' => $ligne->id, 'montant' => $montant, 'saisi_par' => auth()->id(),
        ]))->toThrow(InvalidArgumentException::class);
    }
});

test('le cas libre est déduit du compte saisi', function () {
    $assocId = (int) TenantContext::currentId();
    $compte6 = Compte::factory()->create(['association_id' => $assocId, 'numero_pcg' => '606Y', 'classe' => 6]);
    $compte7 = Compte::factory()->create(['association_id' => $assocId, 'numero_pcg' => '706Y', 'classe' => 7]);

    $fnp = Provision::create([
        'association_id' => $assocId, 'exercice' => 2025, 'compte_id' => $compte6->id,
        'libelle' => 'Facture attendue', 'montant' => 50.0, 'saisi_par' => auth()->id(),
    ]);
    $par = Provision::create([
        'association_id' => $assocId, 'exercice' => 2025, 'compte_id' => $compte7->id,
        'libelle' => 'Produit acquis', 'montant' => 50.0, 'saisi_par' => auth()->id(),
    ]);

    expect($fnp->cas())->toBe(CasProvision::FactureNonParvenue);
    expect($par->cas())->toBe(CasProvision::ProduitARecevoir);
    expect($fnp->estAdossee())->toBeFalse();
});

test('Provision reste cloisonnée par association (le booted() du modèle appelle celui de TenantModel)', function () {
    $ligne = ligneOrigine(7);

    // association_id volontairement omis : il doit être posé par le hook `creating`
    // de TenantModel, que le booted() de Provision ne doit pas court-circuiter.
    $provision = Provision::create([
        'exercice' => 2025,
        'transaction_ligne_id' => $ligne->id,
        'montant' => 175.0,
        'saisi_par' => auth()->id(),
    ]);

    expect((int) $provision->association_id)->toBe((int) $this->association->id);
    expect(Provision::count())->toBe(1);

    // Une autre association ne voit pas ce rattachement.
    $autre = Association::factory()->create();
    TenantContext::boot($autre);

    expect(Provision::count())->toBe(0);
    expect(Provision::withoutGlobalScopes()->count())->toBe(1);
});

test('ProvisionService lit le compte via l’origine et signe l’impact sur le résultat', function () {
    // PCA de 175 € (le produit de N baisse) et CCA de 100 € (la charge de N baisse).
    $lignePca = ligneOrigine(7, 175.0);
    $ligneCca = ligneOrigine(6, 100.0);

    foreach ([$lignePca, $ligneCca] as $ligne) {
        Provision::create([
            'exercice' => 2025,
            'transaction_ligne_id' => $ligne->id,
            'montant' => $ligne->montant,
            'saisi_par' => auth()->id(),
        ]);
    }

    $service = app(ProvisionService::class);
    $parSigle = $service->provisionsExercice(2025)->keyBy('type');

    expect($parSigle)->toHaveCount(2);
    expect($parSigle['pca']['montant_signe'])->toBe(-175.0);
    expect($parSigle['pca']['compte_nom'])->toBe($lignePca->compte->intitule);
    expect($parSigle['cca']['montant_signe'])->toBe(100.0);
    expect($parSigle['cca']['compte_nom'])->toBe($ligneCca->compte->intitule);

    expect($service->totalProvisions(2025))->toBe(-75.0);

    // L'extourne de N+1 est l'image inverse.
    expect($service->extournesExercice(2026)->keyBy('type')['pca']['montant_signe'])->toBe(175.0);
    expect($service->totalExtournes(2026))->toBe(75.0);
});
