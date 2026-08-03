<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;

/**
 * DC-4 : CompteResultatBuilder retourne désormais 'compte_id' = compte_id.
 * Avec Compte::factory() directement, le compte_id est déjà connu — ce helper
 * ne fait plus qu'exposer l'id sous forme entière pour les assertions.
 */
function affectationCompteIdPour(Compte $compte): int
{
    return (int) $compte->id;
}

/**
 * Ligne de ventilation compte-first (dépense: débit, recette: crédit).
 */
function affectationLigne(Transaction $tx, Compte $compte, float $montant, ?int $operationId = null): TransactionLigne
{
    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'operation_id' => $operationId,
        'seance' => null,
        'montant' => $montant,
        'compte_id' => $compte->id,
        'debit' => $estDepense ? $montant : 0.0,
        'credit' => $estDepense ? 0.0 : $montant,
    ]);
}

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
    $this->service = app(RapportService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->op1 = Operation::factory()->create();
    $this->compteVentilation = Compte::factory()->numero('706')->create();
});

afterEach(function () {
    TenantContext::clear();
});

it('expose une hiérarchie famille et comptes avec des clés métier explicites', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 2500.00,
    ]);
    $recette->lignes()->forceDelete();
    affectationLigne($recette, $this->compteVentilation, 2500.00, (int) $this->op1->id);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);
    $famille = collect($rapport['produits'])->first();
    $compte = collect($famille['comptes'] ?? [])->first();

    expect($famille)->toHaveKeys(['famille_id', 'famille_nom', 'comptes'])
        ->and($compte)->toHaveKeys(['compte_id', 'compte_nom', 'montant']);
});

it('échoue fermé sur toutes les requêtes brutes quand le tenant est absent', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 2500.00,
    ]);
    $recette->lignes()->forceDelete();
    affectationLigne($recette, $this->compteVentilation, 2500.00);
    BudgetLine::factory()->create([
        'compte_id' => $this->compteVentilation->id,
        'exercice' => 2025,
        'montant_prevu' => 3000.00,
    ]);

    TenantContext::clear();

    $rapport = $this->service->compteDeResultat(2025);
    $source = file_get_contents(app_path('Services/Rapports/CompteResultatBuilder.php'));

    expect($rapport['charges'])->toBeEmpty()
        ->and($rapport['produits'])->toBeEmpty()
        ->and($source)->not->toContain('->when(TenantContext::hasBooted()')
        ->and(substr_count((string) $source, 'scopeToCurrentTenant('))->toBe(14);
});

it('le rapport onglet 2 prend en compte les affectations au lieu de operation_id ligne', function () {
    // Recette de 20 000 sans opération directe
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->compteVentilation, 20000.00);

    // Affectation de 8000 à op1
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 8000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    // $rapport['produits'] est une liste de catégories, chaque catégorie ayant une clé 'comptes'.
    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($this->compteVentilation))
    );
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($this->compteVentilation));
    // Le rapport doit voir 8000 sur op1, pas 0 (car la ligne avait operation_id null)
    expect((float) ($scRow['montant'] ?? 0))->toBe(8000.0);
});

it('une ligne sans affectation continue d\'utiliser son operation_id direct', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 5000.00,
    ]);
    $recette->lignes()->forceDelete();
    affectationLigne($recette, $this->compteVentilation, 5000.00, (int) $this->op1->id);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($this->compteVentilation))
    );
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($this->compteVentilation));
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 2 prend en compte les affectations de dépenses', function () {
    $compteDepense = Compte::factory()->numero('606')->create();

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 12000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = affectationLigne($depense, $compteDepense, 12000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 7000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $charges = collect($rapport['charges'] ?? []);
    $cat = $charges->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($compteDepense))
    );
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($compteDepense));
    expect((float) ($scRow['montant'] ?? 0))->toBe(7000.0);
});

it('le rapport onglet 3 prend en compte les affectations de recettes avec séance', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 3000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->compteVentilation, 3000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'seance' => 2,
        'montant' => 3000.00,
        'notes' => null,
    ]);

    $rapport = $this->service->rapportSeances(2025, [$this->op1->id]);

    // rapportSeances retourne ['seances' => [...], 'charges' => [...], 'produits' => [...]]
    // 'produits' est une liste de catégories, chacune avec 'comptes'
    // et chaque compte a une clé 'seances' = [seance_num => montant]
    expect($rapport['seances'])->toContain(2);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($this->compteVentilation))
    );
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($this->compteVentilation));
    expect((float) ($scRow['seances'][2] ?? 0))->toBe(3000.0);
});

// ── compteDeResultat global (onglet 1) + ventilations ────────────────────────

it('compteDeResultat global : recette ventilée partiellement sans opération — les deux parts sont comptées', function () {
    // Cas exact du bug : 15 000 sans opération + 5 000 avec opération = 20 000 au total
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->compteVentilation, 20000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => null,
        'montant' => 15000.00,
        'seance' => null,
        'notes' => null,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 5000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultat(2025);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($this->compteVentilation)));
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($this->compteVentilation));
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(20000.0);
});

it('compteDeResultat global : recette ventilée entièrement sans opération — montant complet visible', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 10000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->compteVentilation, 10000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => null,
        'montant' => 10000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultat(2025);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($this->compteVentilation)));
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($this->compteVentilation));
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(10000.0);
});

it('compteDeResultat global : dépense ventilée partiellement sans opération — les deux parts sont comptées', function () {
    $compteDepense = Compte::factory()->numero('606')->create();

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 9000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = affectationLigne($depense, $compteDepense, 9000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => null,
        'montant' => 6000.00,
        'seance' => null,
        'notes' => null,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 3000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultat(2025);

    $charges = collect($rapport['charges'] ?? []);
    $cat = $charges->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($compteDepense)));
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($compteDepense));
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(9000.0);
});

it('compteDeResultatOperations filtré : affectation sans opération n\'apparaît pas dans le filtre opération', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->compteVentilation, 20000.00);

    // 15 000 sans opération, 5 000 avec opération
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => null,
        'montant' => 15000.00,
        'seance' => null,
        'notes' => null,
    ]);
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 5000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($this->compteVentilation)));
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($this->compteVentilation));
    // Seuls les 5 000 rattachés à op1 doivent apparaître, pas les 15 000 sans opération
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 3 prend en compte les affectations de dépenses avec séance', function () {
    $compteDepense = Compte::factory()->numero('606')->create();

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 4000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = affectationLigne($depense, $compteDepense, 4000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'seance' => 3,
        'montant' => 4000.00,
        'notes' => null,
    ]);

    $rapport = $this->service->rapportSeances(2025, [$this->op1->id]);

    expect($rapport['seances'])->toContain(3);

    $charges = collect($rapport['charges'] ?? []);
    $cat = $charges->first(fn ($c) => collect($c['comptes'] ?? [])->contains('compte_id', affectationCompteIdPour($compteDepense))
    );
    $scRow = collect($cat['comptes'] ?? [])->firstWhere('compte_id', affectationCompteIdPour($compteDepense));
    expect((float) ($scRow['seances'][3] ?? 0))->toBe(4000.0);
});
