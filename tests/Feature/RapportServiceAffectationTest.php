<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use App\Models\User;
use App\Models\Association;
use App\Tenant\TenantContext;
use App\Services\RapportService;

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
    $this->sousCategorie = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);
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
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => null,
        'montant' => 20000.00,
    ]);

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
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
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
    TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => $this->op1->id,
        'montant' => 5000.00,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 2 prend en compte les affectations de dépenses', function () {
    $categorieD = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD = SousCategorie::factory()->create(['categorie_id' => $categorieD->id]);

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 12000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sousCatD->id,
        'operation_id' => null,
        'montant' => 12000.00,
    ]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 7000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $charges = collect($rapport['charges'] ?? []);
    $cat = $charges->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $sousCatD->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $sousCatD->id);
    expect((float) ($scRow['montant'] ?? 0))->toBe(7000.0);
});

it('le rapport onglet 3 prend en compte les affectations de recettes avec séance', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 3000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => null,
        'seance' => null,
        'montant' => 3000.00,
    ]);

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
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
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
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => null,
        'montant' => 20000.00,
    ]);

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
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(20000.0);
});

it('compteDeResultat global : recette ventilée entièrement sans opération — montant complet visible', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 10000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => null,
        'montant' => 10000.00,
    ]);

    TransactionLigneAffectation::create([
        'transaction_ligne_id' => $ligne->id,
        'operation_id' => null,
        'montant' => 10000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultat(2025);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(10000.0);
});

it('compteDeResultat global : dépense ventilée partiellement sans opération — les deux parts sont comptées', function () {
    $categorieD = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD = SousCategorie::factory()->create(['categorie_id' => $categorieD->id]);

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 9000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sousCatD->id,
        'operation_id' => null,
        'montant' => 9000.00,
    ]);

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
    $cat = $charges->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $sousCatD->id));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $sousCatD->id);
    expect((float) ($scRow['montant_n'] ?? 0))->toBe(9000.0);
});

it('compteDeResultatOperations filtré : affectation sans opération n\'apparaît pas dans le filtre opération', function () {
    $recette = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $recette->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => null,
        'montant' => 20000.00,
    ]);

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
    $cat = $produits->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id));
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    // Seuls les 5 000 rattachés à op1 doivent apparaître, pas les 15 000 sans opération
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 3 prend en compte les affectations de dépenses avec séance', function () {
    $categorieD = Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD = SousCategorie::factory()->create(['categorie_id' => $categorieD->id]);

    $depense = Transaction::factory()->asDepense()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 4000.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $depense->id,
        'sous_categorie_id' => $sousCatD->id,
        'operation_id' => null,
        'seance' => null,
        'montant' => 4000.00,
    ]);

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
    $cat = $charges->first(fn ($c) => collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $sousCatD->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $sousCatD->id);
    expect((float) ($scRow['seances'][3] ?? 0))->toBe(4000.0);
});
