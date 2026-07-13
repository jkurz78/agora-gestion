<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\Migrations\BancairesSeeder;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\Compta\TransactionConverter;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    SystemeSeeder::seed();

    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'iban' => 'FR7612345000012345678901234',
        'actif_recettes_depenses' => true,
    ]);
    BancairesSeeder::seed();

    $this->compte706 = Compte::factory()->numero('706')->create(['intitule' => 'Cotisations membres']);
    $this->compte758 = Compte::factory()->numero('758')->create(['intitule' => 'Produits divers']);
    $this->compte606 = Compte::factory()->depense()->numero('606')->create(['intitule' => 'Fournitures']);
    $this->compte616 = Compte::factory()->depense()->numero('616')->create(['intitule' => 'Assurances']);
    $this->tiers = Tiers::factory()->create();

    $this->typeOperation = TypeOperation::factory()->create(['compte_id' => $this->compte706->id]);
    $this->operation = Operation::factory()->create([
        'type_operation_id' => $this->typeOperation->id,
        'nom' => 'Formation automne',
    ]);
    $this->autreOperation = Operation::factory()->create([
        'type_operation_id' => $this->typeOperation->id,
        'nom' => 'Opération hors filtre',
    ]);
    $this->seance1 = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2025-10-15',
    ]);
    $this->seance2 = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 2,
        'date' => '2025-11-15',
    ]);

    $this->builder = app(CompteResultatBuilder::class);
    $this->transactionService = app(TransactionService::class);
});

afterEach(function (): void {
    TenantContext::clear();
});

function creerLigneCompteFirst(
    object $ctx,
    string $type,
    Compte $compte,
    float $montant,
    ?int $operationId = null,
    ?int $seance = null,
): TransactionLigne {
    $factory = Transaction::factory();
    $transaction = $type === 'recette'
        ? $factory->asRecette()->create([
            'date' => '2025-10-01',
            'montant_total' => $montant,
            'saisi_par' => $ctx->user->id,
        ])
        : $factory->asDepense()->create([
            'date' => '2025-10-01',
            'montant_total' => $montant,
            'saisi_par' => $ctx->user->id,
        ]);

    $transaction->lignes()->forceDelete();

    return TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'compte_id' => $compte->id,
        'montant' => $montant,
        'debit' => $type === 'depense' ? $montant : 0.0,
        'credit' => $type === 'recette' ? $montant : 0.0,
        'operation_id' => $operationId,
        'seance' => $seance,
    ]);
}

it('filtre le compte de résultat par opération avec des montants exacts', function (): void {
    creerLigneCompteFirst($this, 'recette', $this->compte706, 120.0, (int) $this->operation->id);
    creerLigneCompteFirst($this, 'depense', $this->compte606, 45.0, (int) $this->operation->id);
    creerLigneCompteFirst($this, 'recette', $this->compte758, 999.0, (int) $this->autreOperation->id);
    creerLigneCompteFirst($this, 'depense', $this->compte616, 888.0, (int) $this->autreOperation->id);

    $resultat = $this->builder->compteDeResultatOperations(2025, [(int) $this->operation->id]);
    $produits = collect($resultat['produits'])->flatMap(fn (array $famille): array => $famille['comptes']);
    $charges = collect($resultat['charges'])->flatMap(fn (array $famille): array => $famille['comptes']);

    expect($produits->firstWhere('compte_id', (int) $this->compte706->id)['montant'])->toBe(120.0)
        ->and($charges->firstWhere('compte_id', (int) $this->compte606->id)['montant'])->toBe(45.0)
        ->and($produits->firstWhere('compte_id', (int) $this->compte758->id))->toBeNull()
        ->and($charges->firstWhere('compte_id', (int) $this->compte616->id))->toBeNull();
});

it('ventile les montants exacts par séance et par compte', function (): void {
    creerLigneCompteFirst($this, 'recette', $this->compte706, 80.0, (int) $this->operation->id, 1);
    creerLigneCompteFirst($this, 'recette', $this->compte706, 30.0, (int) $this->operation->id, 2);
    creerLigneCompteFirst($this, 'depense', $this->compte606, 20.0, (int) $this->operation->id, 1);
    creerLigneCompteFirst($this, 'recette', $this->compte758, 777.0, (int) $this->autreOperation->id, 1);

    $resultat = $this->builder->rapportSeances(2025, [(int) $this->operation->id]);
    $produit = collect($resultat['produits'])
        ->flatMap(fn (array $famille): array => $famille['comptes'])
        ->firstWhere('compte_id', (int) $this->compte706->id);
    $charge = collect($resultat['charges'])
        ->flatMap(fn (array $famille): array => $famille['comptes'])
        ->firstWhere('compte_id', (int) $this->compte606->id);

    expect($resultat['seances'])->toBe([1, 2])
        ->and($produit['seances'])->toBe([1 => 80.0, 2 => 30.0])
        ->and($produit['total'])->toBe(110.0)
        ->and($charge['seances'])->toBe([1 => 20.0, 2 => 0.0])
        ->and($charge['total'])->toBe(20.0);
});

it('exclut les lignes techniques de classes 4 et 5 du compte de résultat', function (): void {
    $transactionRecette = Transaction::factory()->asRecette()->create([
        'date' => '2025-10-01',
        'montant_total' => 100.0,
        'saisi_par' => $this->user->id,
    ]);
    $transactionRecette->lignes()->forceDelete();

    $compte411 = Compte::ofNumeroSysteme('411');
    TransactionLigne::factory()->create([
        'transaction_id' => $transactionRecette->id,
        'compte_id' => $this->compte706->id,
        'montant' => 100.0,
        'debit' => 0.0,
        'credit' => 100.0,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $transactionRecette->id,
        'compte_id' => $compte411->id,
        'montant' => 100.0,
        'debit' => 100.0,
        'credit' => 0.0,
    ]);

    $transactionDepense = Transaction::factory()->asDepense()->create([
        'date' => '2025-10-01',
        'montant_total' => 40.0,
        'saisi_par' => $this->user->id,
    ]);
    $transactionDepense->lignes()->forceDelete();

    $compte401 = Compte::ofNumeroSysteme('401');
    TransactionLigne::factory()->create([
        'transaction_id' => $transactionDepense->id,
        'compte_id' => $this->compte606->id,
        'montant' => 40.0,
        'debit' => 40.0,
        'credit' => 0.0,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $transactionDepense->id,
        'compte_id' => $compte401->id,
        'montant' => 40.0,
        'debit' => 0.0,
        'credit' => 40.0,
    ]);

    $resultat = $this->builder->compteDeResultat(2025);
    $produits = collect($resultat['produits'])->flatMap(fn (array $famille): array => $famille['comptes']);
    $charges = collect($resultat['charges'])->flatMap(fn (array $famille): array => $famille['comptes']);

    expect($produits->pluck('compte_id')->all())->toBe([(int) $this->compte706->id])
        ->and($charges->pluck('compte_id')->all())->toBe([(int) $this->compte606->id])
        ->and((float) $resultat['produits'][0]['montant_n'])->toBe(100.0)
        ->and((float) $resultat['charges'][0]['montant_n'])->toBe(40.0);
});

it('retourne les familles et comptes attendus avec leurs montants exacts', function (): void {
    creerLigneCompteFirst($this, 'recette', $this->compte706, 340.0);
    creerLigneCompteFirst($this, 'recette', $this->compte758, 290.0);
    creerLigneCompteFirst($this, 'depense', $this->compte606, 195.0);
    creerLigneCompteFirst($this, 'depense', $this->compte616, 230.0);

    $resultat = $this->builder->compteDeResultat(2025);

    foreach ([
        [$this->compte706, $resultat['produits'], 340.0],
        [$this->compte758, $resultat['produits'], 290.0],
        [$this->compte606, $resultat['charges'], 195.0],
        [$this->compte616, $resultat['charges'], 230.0],
    ] as [$compte, $familles, $montant]) {
        $familleModele = $compte->famille();
        $famille = collect($familles)->firstWhere('famille_id', (int) $familleModele->id);
        $ligneCompte = collect($famille['comptes'])->firstWhere('compte_id', (int) $compte->id);

        expect($famille['famille_nom'])->toBe($familleModele->libelle())
            ->and($ligneCompte['compte_nom'])->toBe($compte->intitule)
            ->and((float) $ligneCompte['montant_n'])->toBe($montant);
    }

    expect((float) collect($resultat['produits'])->sum('montant_n'))->toBe(630.0)
        ->and((float) collect($resultat['charges'])->sum('montant_n'))->toBe(425.0);
});

it('marque équilibrée une recette comptant saisie par le service', function (): void {
    $transaction = $this->transactionService->create([
        'type' => 'recette',
        'date' => '2025-10-05',
        'libelle' => 'Cotisation comptant',
        'montant_total' => '100.00',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_id' => $this->compteBancaire->id,
        'tiers_id' => $this->tiers->id,
    ], [
        ['compte_id' => $this->compte706->id, 'montant' => '100.00'],
    ]);

    expect($transaction->fresh()->equilibree)->toBeTrue();
});

it('dérive le même statut pour une écriture directe et une écriture convertie', function (): void {
    $resolver = app(EtatReglementResolver::class);
    $converter = app(TransactionConverter::class);

    $transactionDirecte = $this->transactionService->create([
        'type' => 'recette',
        'date' => '2025-10-05',
        'libelle' => 'Subvention directe',
        'montant_total' => '200.00',
        'mode_paiement' => ModePaiement::Virement->value,
        'compte_id' => $this->compteBancaire->id,
        'tiers_id' => $this->tiers->id,
    ], [
        ['compte_id' => $this->compte706->id, 'montant' => '200.00'],
    ]);

    $transactionAConvertir = Transaction::create([
        'association_id' => $this->association->id,
        'type' => 'recette',
        'date' => '2025-10-06',
        'libelle' => 'Subvention à convertir',
        'montant_total' => 200.00,
        'mode_paiement' => ModePaiement::Virement->value,
        'compte_id' => $this->compteBancaire->id,
        'tiers_id' => $this->tiers->id,
        'statut_reglement' => StatutReglement::Recu->value,
        'saisi_par' => $this->user->id,
        'equilibree' => false,
        'type_ecriture' => 'normale',
    ]);
    TransactionLigne::withoutEvents(fn () => TransactionLigne::create([
        'transaction_id' => $transactionAConvertir->id,
        'compte_id' => $this->compte706->id,
        'montant' => 200.00,
        'debit' => 0.0,
        'credit' => 0.0,
    ]));

    DB::transaction(fn () => $converter->convertir($transactionAConvertir->fresh()));

    expect($transactionAConvertir->fresh()->equilibree)->toBeTrue()
        ->and($resolver->resolve($transactionDirecte->fresh()))->toBe(StatutReglement::Recu)
        ->and($resolver->resolve($transactionAConvertir->fresh()))->toBe(StatutReglement::Recu);
});
