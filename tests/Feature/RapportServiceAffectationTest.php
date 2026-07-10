<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Services\RapportService;
use App\Tenant\TenantContext;

/**
 * DC-4 : CompteResultatBuilder retourne désormais 'sous_categorie_id' = compte_id (résolu
 * depuis sous_categorie_id via code_cerfa = numero_pcg). Ce helper retrouve ce compte_id
 * pour les assertions qui, avant DC-4, comparaient directement à SousCategorie::$id.
 */
function affectationCompteIdPour(SousCategorie $sousCategorie): int
{
    return (int) Compte::where('numero_pcg', $sousCategorie->code_cerfa)->firstOrFail()->id;
}

/**
 * Ligne de ventilation compte-first (dépense: débit, recette: crédit), compte
 * résolu depuis le code_cerfa de la sous-catégorie.
 */
function affectationLigne(Transaction $tx, SousCategorie $sc, float $montant, ?int $operationId = null): TransactionLigne
{
    $estDepense = $tx->type->value === 'depense';

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sc->id,
        'operation_id' => $operationId,
        'seance' => null,
        'montant' => $montant,
        'compte_id' => affectationCompteIdPour($sc),
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
    $this->categorie = Categorie::factory()->create(['type' => TypeCategorie::Recette]);
    // code_cerfa déclenche la matérialisation Compte/Famille (SousCategorieCompteObserver puis CompteObserver).
    $this->sousCategorie = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id, 'code_cerfa' => '706']);
});

afterEach(function () {
    TenantContext::clear();
});

it('le rapport onglet 2 prend en compte les affectations au lieu de operation_id ligne', function () {
    // Recette de 20 000 sans opération directe
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->sousCategorie, 20000.00);

    // Affectation de 8000 à op1
    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 8000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    // $rapport['produits'] est une liste de catégories, chaque catégorie ayant une clé 'sous_categories'.
    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($this->sousCategorie))
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($this->sousCategorie));
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
    affectationLigne($recette, $this->sousCategorie, 5000.00, (int) $this->op1->id);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($this->sousCategorie))
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($this->sousCategorie));
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 2 prend en compte les affectations de dépenses', function () {
    $categorieD = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD = SousCategorie::factory()->create(['categorie_id' => $categorieD->id, 'code_cerfa' => '606']);

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 12000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = affectationLigne($depense, $sousCatD, 12000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 7000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $charges = collect($rapport['charges'] ?? []);
    $cat = $charges->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($sousCatD))
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($sousCatD));
    expect((float) ($scRow['montant'] ?? 0))->toBe(7000.0);
});

it('le rapport onglet 3 prend en compte les affectations de recettes avec séance', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 3000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->sousCategorie, 3000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'seance' => 2,
        'montant' => 3000.00,
        'notes' => null,
    ]);

    $rapport = $this->service->rapportSeances(2025, [$this->op1->id]);

    // rapportSeances retourne ['seances' => [...], 'charges' => [...], 'produits' => [...]]
    // 'produits' est une liste de catégories, chacune avec 'sous_categories'
    // et chaque sous-catégorie a une clé 'seances' = [seance_num => montant]
    expect($rapport['seances'])->toContain(2);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($this->sousCategorie))
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($this->sousCategorie));
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
    $ligne = affectationLigne($recette, $this->sousCategorie, 20000.00);

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
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($this->sousCategorie)));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($this->sousCategorie));
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(20000.0);
});

it('compteDeResultat global : recette ventilée entièrement sans opération — montant complet visible', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 10000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->sousCategorie, 10000.00);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => null,
        'montant' => 10000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultat(2025);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($this->sousCategorie)));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($this->sousCategorie));
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(10000.0);
});

it('compteDeResultat global : dépense ventilée partiellement sans opération — les deux parts sont comptées', function () {
    $categorieD = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD = SousCategorie::factory()->create(['categorie_id' => $categorieD->id, 'code_cerfa' => '606']);

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 9000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = affectationLigne($depense, $sousCatD, 9000.00);

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
    $cat = $charges->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($sousCatD)));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($sousCatD));
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(9000.0);
});

it('compteDeResultatOperations filtré : affectation sans opération n\'apparaît pas dans le filtre opération', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = affectationLigne($recette, $this->sousCategorie, 20000.00);

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
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($this->sousCategorie)));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($this->sousCategorie));
    // Seuls les 5 000 rattachés à op1 doivent apparaître, pas les 15 000 sans opération
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 3 prend en compte les affectations de dépenses avec séance', function () {
    $categorieD = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD = SousCategorie::factory()->create(['categorie_id' => $categorieD->id, 'code_cerfa' => '606']);

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 4000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = affectationLigne($depense, $sousCatD, 4000.00);

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
    $cat = $charges->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', affectationCompteIdPour($sousCatD))
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', affectationCompteIdPour($sousCatD));
    expect((float) ($scRow['seances'][3] ?? 0))->toBe(4000.0);
});
