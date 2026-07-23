# Postes tiers ouverts et règlements datés — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendre les dettes 401 et créances 411 visibles et réglables, totalement ou partiellement, depuis l'exercice courant avec une date réelle, y compris lorsqu'elles proviennent des à-nouveaux.

**Architecture:** `PostesTiersOuvertsService` fournit une projection unique des postes métier et de leurs descendants AN. `PosteTiersReglementService` verrouille, découpe et lettre les lignes, tandis que `EcritureGenerator` reçoit explicitement la ligne à solder. Des composants Livewire communs exposent cette mécanique dans un écran dédié, Transactions et le formulaire de transaction.

**Tech Stack:** PHP 8.3, Laravel 11, Eloquent/Query Builder, Livewire 4, Bootstrap 5, MySQL Laravel Sail, Pest PHP.

## Global Constraints

- Travailler exclusivement sur la branche `feat/compta-v5`.
- Respecter `declare(strict_types=1)`, `final class` et les types explicites.
- Toute mutation métier s'exécute dans `DB::transaction()`.
- Toute lecture brute inclut `TenantContext::currentId()` et reste fail-closed.
- Caster les deux côtés des comparaisons PK/FK strictes en `(int)`.
- Calculer et comparer les montants en centimes entiers ; ne jamais décider un solde avec des flottants.
- Utiliser une modale Bootstrap ; ne jamais introduire `wire:confirm` ou `confirm()` natif.
- Utiliser `table-dark` avec `style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880"` pour les en-têtes.
- Ne pas modifier ni embarquer les changements utilisateur déjà présents dans `app/Services/RemiseBancaireService.php`, `config/version.php`, `docs/compta-partie-double.md`, `docs/recette/2026-07-recette-fonctionnelle-v5.md`, `tests/Feature/Services/RemiseBancaireServicePartieDoubleTest.php`, `docs/compta-etats-reglement.md` et `outputs/`.
- Les règlements groupés de plusieurs postes, les compensations et les avoirs restent hors périmètre.
- Chaque tâche suit RED → GREEN → REFACTOR et se termine par son propre commit.

---

## Cartographie des fichiers

### Fichiers créés

- `database/migrations/2026_07_23_000000_add_poste_tiers_parent_id_to_transaction_lignes.php` — filiation d'une fraction payée vers le poste canonique.
- `app/DTOs/Compta/PosteTiersOuvert.php` — projection immuable d'un poste affichable.
- `app/DTOs/Compta/PosteTiersReglementData.php` — commande typée de règlement.
- `app/DTOs/Compta/ReglementPosteTiers.php` — projection d'une T2 pour l'historique et l'annulation.
- `app/Services/Compta/PostesTiersOuvertsService.php` — requête, regroupement, origine métier et historique des règlements.
- `app/Services/Compta/PosteTiersReglementService.php` — règlement total/partiel et annulation.
- `app/Services/Compta/TransactionAvecReglementService.php` — orchestration atomique T1 puis T2 depuis le formulaire.
- `app/Livewire/Compta/PosteTiersReglementModal.php` — modale commune de règlement.
- `app/Livewire/Compta/AnnulationReglementTiersModal.php` — confirmation d'annulation.
- `app/Livewire/Compta/PostesTiersOuverts.php` — page dédiée et filtres.
- `resources/views/livewire/compta/poste-tiers-reglement-modal.blade.php` — formulaire partagé.
- `resources/views/livewire/compta/annulation-reglement-tiers-modal.blade.php` — modale d'annulation.
- `resources/views/livewire/compta/postes-tiers-ouverts.blade.php` — tableau dédié.
- `tests/Feature/Compta/PosteTiersParentSchemaTest.php`
- `tests/Feature/Services/Compta/PostesTiersOuvertsServiceTest.php`
- `tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php`
- `tests/Feature/Services/Compta/AnnulationReglementTiersTest.php`
- `tests/Feature/Livewire/PosteTiersReglementModalTest.php`
- `tests/Feature/Livewire/PostesTiersOuvertsTest.php`

### Fichiers modifiés

- `app/Models/TransactionLigne.php` — fillable, cast et relations parent/enfants.
- `app/Models/Transaction.php` — détection d'un règlement tiers existant.
- `app/Services/Compta/ANouveau/PosteReporteResolver.php` — exposition de la racine métier et résolution depuis une fraction.
- `app/Services/Compta/EcritureGenerator.php` — ligne tiers source explicite pour T2.
- `app/Services/Compta/EtatReglementResolver.php` — statut agrégé sur tous les règlements d'un poste.
- `app/Services/ReglementOperationService.php` — délégation des anciens points d'entrée vers le nouveau service.
- `app/Services/TransactionUniverselleService.php` — branche UNION des reports AN.
- `app/Livewire/TransactionUniverselle.php` — ouverture de la modale commune et rafraîchissement.
- `resources/views/livewire/transaction-universelle.blade.php` — badge/report, action et suppression des anciennes modales dupliquées.
- `app/Livewire/ReglementTable.php` — ouverture de la modale datée depuis la grille d'une opération.
- `resources/views/livewire/reglement-table.blade.php` — inclusion de la modale commune.
- `app/Livewire/TransactionForm.php` — date réelle, états ouvert/partiel/soldé et annulation.
- `resources/views/livewire/transaction-form.blade.php` — champs de règlement et historique.
- `app/Services/TransactionService.php` — préservation des écritures réglées lors d'une édition.
- `routes/web.php` — route de la page dédiée.
- `resources/views/components/sidebar.blade.php` — entrée de navigation.
- Tests existants ciblant les anciens boutons, le formulaire, les AN et le statut dérivé.

---

### Task 1: Filiation des fractions de poste tiers

**Files:**
- Create: `database/migrations/2026_07_23_000000_add_poste_tiers_parent_id_to_transaction_lignes.php`
- Modify: `app/Models/TransactionLigne.php`
- Test: `tests/Feature/Compta/PosteTiersParentSchemaTest.php`

**Interfaces:**
- Produces: `TransactionLigne::posteTiersParent(): BelongsTo`
- Produces: `TransactionLigne::fractionsPosteTiers(): HasMany`
- Produces: cast nullable `poste_tiers_parent_id` vers `int`

- [ ] **Step 1: Écrire le test de schéma en échec**

```php
<?php

declare(strict_types=1);

use App\Models\TransactionLigne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('ajoute la filiation nullable des fractions de poste tiers', function (): void {
    expect(Schema::hasColumn('transaction_lignes', 'poste_tiers_parent_id'))->toBeTrue();

    $parent = TransactionLigne::factory()->create();
    $fraction = TransactionLigne::factory()->create([
        'transaction_id' => (int) $parent->transaction_id,
        'poste_tiers_parent_id' => (int) $parent->id,
    ]);

    expect($fraction->posteTiersParent?->is($parent))->toBeTrue()
        ->and($parent->fractionsPosteTiers()->pluck('id')->all())
        ->toContain((int) $fraction->id);
});

it('refuse une filiation vers une ligne inexistante', function (): void {
    expect(fn () => TransactionLigne::factory()->create([
        'poste_tiers_parent_id' => 999999999,
    ]))->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Compta/PosteTiersParentSchemaTest.php`

Expected: FAIL car la colonne et les relations n'existent pas.

- [ ] **Step 3: Ajouter la migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->foreignId('poste_tiers_parent_id')
                ->nullable()
                ->after('lettrage_code')
                ->constrained('transaction_lignes')
                ->nullOnDelete();
            $table->index(
                ['transaction_id', 'poste_tiers_parent_id', 'lettrage_code'],
                'tl_poste_tiers_ouvert_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lignes', function (Blueprint $table): void {
            $table->dropIndex('tl_poste_tiers_ouvert_idx');
            $table->dropConstrainedForeignId('poste_tiers_parent_id');
        });
    }
};
```

- [ ] **Step 4: Ajouter le modèle**

Ajouter `poste_tiers_parent_id` à `$fillable` et aux casts, puis :

```php
public function posteTiersParent(): BelongsTo
{
    return $this->belongsTo(self::class, 'poste_tiers_parent_id');
}

public function fractionsPosteTiers(): HasMany
{
    return $this->hasMany(self::class, 'poste_tiers_parent_id');
}
```

- [ ] **Step 5: Vérifier GREEN et le rollback**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Compta/PosteTiersParentSchemaTest.php tests/Feature/Compta/ANouveau/ANouveauSchemaTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_23_000000_add_poste_tiers_parent_id_to_transaction_lignes.php app/Models/TransactionLigne.php tests/Feature/Compta/PosteTiersParentSchemaTest.php
git commit -m "feat(compta): tracer les fractions de postes tiers"
```

---

### Task 2: Projection unique des postes ouverts

**Files:**
- Create: `app/DTOs/Compta/PosteTiersOuvert.php`
- Create: `app/DTOs/Compta/ReglementPosteTiers.php`
- Create: `app/Services/Compta/PostesTiersOuvertsService.php`
- Modify: `app/Services/Compta/ANouveau/PosteReporteResolver.php`
- Test: `tests/Feature/Services/Compta/PostesTiersOuvertsServiceTest.php`

**Interfaces:**
- Produces: `PostesTiersOuvertsService::chercher(int $exercice, ?string $compte, ?int $tiersId, ?int $exerciceOrigine, ?string $recherche): Collection`
- Produces: `PostesTiersOuvertsService::paginer(int $exercice, ?string $compte, ?int $tiersId, ?int $exerciceOrigine, ?string $recherche, int $parPage, int $page): LengthAwarePaginator`
- Produces: `PostesTiersOuvertsService::trouver(int $ligneId, int $exercice): PosteTiersOuvert`
- Produces: `PostesTiersOuvertsService::pourTransaction(Transaction $transaction, int $exercice): ?PosteTiersOuvert`
- Produces: `PostesTiersOuvertsService::soldeActifPourTransaction(Transaction $transaction): int`
- Produces: `PostesTiersOuvertsService::reglements(Transaction $transaction): Collection`
- Produces: `PosteReporteResolver::racineId(TransactionLigne $ligne): int`

- [ ] **Step 1: Écrire les tests de lecture en échec**

Créer des T1 401 et 411 avec `EcritureGenerator`, puis vérifier :

```php
$postes = app(PostesTiersOuvertsService::class)->chercher(
    exercice: 2025,
    compte: null,
    tiersId: null,
    exerciceOrigine: null,
    recherche: null,
);

expect($postes)->toHaveCount(2)
    ->and($postes->firstWhere('numeroCompte', '411')?->soldeCentimes)->toBe(10000)
    ->and($postes->firstWhere('numeroCompte', '401')?->soldeCentimes)->toBe(4550);
```

Après génération AN, vérifier que le poste de N+1 porte :

```php
expect($poste->estReporte)->toBeTrue()
    ->and($poste->dateAffichage->toDateString())->toBe('2026-09-01')
    ->and($poste->dateOrigine->toDateString())->toBe('2026-08-20')
    ->and($poste->numeroPiece)->toBe($t1->numero_piece)
    ->and($poste->reference)->toBe('REF-CLIENT-42')
    ->and($poste->transactionOrigineId)->toBe((int) $t1->id);
```

Ajouter un test de recherche sur tiers/référence/pièce/libellé, un test de filtre 401/411, un test d'isolation avec une seconde association et un test regroupant deux fractions non lettrées partageant le même `poste_tiers_parent_id`.

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/PostesTiersOuvertsServiceTest.php`

Expected: FAIL car les DTO et le service n'existent pas.

- [ ] **Step 3: Créer les DTO immuables**

```php
<?php

declare(strict_types=1);

namespace App\DTOs\Compta;

use Carbon\CarbonImmutable;

final readonly class PosteTiersOuvert
{
    /**
     * @param array<int> $ligneIdsOuvertes
     */
    public function __construct(
        public int $ligneActionId,
        public int $ligneCanoniqueId,
        public array $ligneIdsOuvertes,
        public int $transactionOrigineId,
        public int $compteId,
        public string $numeroCompte,
        public int $tiersId,
        public string $tiersNom,
        public int $soldeCentimes,
        public CarbonImmutable $dateOrigine,
        public CarbonImmutable $dateAffichage,
        public ?string $numeroPiece,
        public ?string $reference,
        public string $libelle,
        public int $exerciceOrigine,
        public int $exerciceActif,
        public bool $estReporte,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\DTOs\Compta;

use App\Enums\ModePaiement;
use Carbon\CarbonImmutable;

final readonly class ReglementPosteTiers
{
    public function __construct(
        public int $transactionId,
        public int $montantCentimes,
        public CarbonImmutable $date,
        public ModePaiement $mode,
        public bool $annulable,
    ) {}
}
```

- [ ] **Step 4: Exposer la racine AN**

Rendre publique la méthode existante sans changer sa règle :

```php
public function racineId(TransactionLigne $ligne): int
{
    $ligneCanoniqueId = $ligne->poste_tiers_parent_id ?? (int) $ligne->id;
    $origine = ANouveauLigneOrigine::query()
        ->where('ligne_an_id', $ligneCanoniqueId)
        ->latest('generation_id')
        ->first();

    return $origine?->ligne_racine_id ?? $ligneCanoniqueId;
}
```

Adapter `depuisLigne()` pour commencer par `posteTiersParent` lorsqu'une fraction est fournie.

- [ ] **Step 5: Implémenter la requête de base**

Dans `PostesTiersOuvertsService`, construire une requête tenant-scopée sur `transaction_lignes as ouverte`, joindre `transactions as active_tx`, `comptes`, `tiers`, le parent canonique, `a_nouveau_ligne_origines`, la ligne racine et sa transaction. Les invariants de regroupement sont :

```php
$canonique = DB::raw('COALESCE(ouverte.poste_tiers_parent_id, ouverte.id)');
$soldeSql = "SUM(CASE WHEN comptes.numero_pcg = '411'
    THEN ROUND(ouverte.debit * 100) - ROUND(ouverte.credit * 100)
    ELSE ROUND(ouverte.credit * 100) - ROUND(ouverte.debit * 100)
END)";
```

La requête impose :

```php
->whereIn('comptes.numero_pcg', ['401', '411'])
->whereNotNull('ouverte.tiers_id')
->whereNull('ouverte.lettrage_code')
->whereNull('ouverte.deleted_at')
->whereNull('active_tx.deleted_at')
->where('active_tx.association_id', TenantContext::currentId())
->whereBetween('active_tx.date', [
    $range['start']->toDateString(),
    $range['end']->toDateString(),
])
->groupBy($canonique, 'comptes.id', 'comptes.numero_pcg', 'ouverte.tiers_id')
->havingRaw("{$soldeSql} > 0");
```

Mapper chaque groupe vers `PosteTiersOuvert`. Pour un AN, prendre les champs de `racine_tx`; sinon ceux de `active_tx`. La date d'affichage d'un report est `$range['start']`.

- [ ] **Step 6: Implémenter recherche et historique**

`chercher()` applique les filtres sur la projection métier et trie par date d'origine, numéro de pièce puis identifiant. `paginer()` applique exactement la même requête et retourne un `LengthAwarePaginator`. `soldeActifPourTransaction()` suit la dernière génération AN active, additionne toutes les fractions non lettrées de la racine et fonctionne sans dépendre de la session. `reglements()` suit la même racine, collecte toutes les lignes canoniques et fractions portant un `lettrage_code`, retrouve leur ligne paire dans une autre transaction et retourne une T2 unique par transaction. `annulable` vaut faux si T2 a `rapprochement_id`, `remise_id` ou si sa ligne de portage 5112/530 est lettrée vers une remise.

- [ ] **Step 7: Vérifier GREEN**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/PostesTiersOuvertsServiceTest.php tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php`

Expected: PASS sans requête inter-tenant.

- [ ] **Step 8: Commit**

```bash
git add app/DTOs/Compta/PosteTiersOuvert.php app/DTOs/Compta/ReglementPosteTiers.php app/Services/Compta/PostesTiersOuvertsService.php app/Services/Compta/ANouveau/PosteReporteResolver.php tests/Feature/Services/Compta/PostesTiersOuvertsServiceTest.php tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php
git commit -m "feat(compta): projeter les postes tiers ouverts"
```

---

### Task 3: Générateur T2 avec ligne source explicite

**Files:**
- Modify: `app/Services/Compta/EcritureGenerator.php`
- Test: `tests/Feature/Services/Compta/EcritureGeneratorPourReglementTest.php`
- Test: `tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php`

**Interfaces:**
- Consumes: `TransactionLigne $ligneTiersSource`
- Produces: `EcritureGenerator::pourReglement(Transaction $t1, ModePaiement $mode, Compte $compteTresorerie, DateTimeInterface $datePaiement, ?string $libelle = null, ?TransactionLigne $ligneTiersSource = null): Transaction`

- [ ] **Step 1: Écrire le test en échec**

Créer une T1 avec deux lignes 411 ouvertes de même tiers, puis appeler :

```php
$t2 = app(EcritureGenerator::class)->pourReglement(
    t1: $t1,
    mode: ModePaiement::Virement,
    compteTresorerie: $banque,
    datePaiement: new DateTimeImmutable('2026-07-23'),
    ligneTiersSource: $secondeLigne,
);

expect($secondeLigne->fresh()->lettrage_code)->not->toBeNull()
    ->and($premiereLigne->fresh()->lettrage_code)->toBeNull()
    ->and($t2->date->toDateString())->toBe('2026-07-23');
```

Ajouter les refus suivants : ligne d'une autre transaction métier/racine, ligne lettrée, ligne sans tiers, compte autre que 401/411.

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/EcritureGeneratorPourReglementTest.php`

Expected: FAIL car le paramètre nommé n'existe pas.

- [ ] **Step 3: Étendre la signature sans casser les appels existants**

```php
public function pourReglement(
    Transaction $t1,
    ModePaiement $mode,
    Compte $compteTresorerie,
    \DateTimeInterface $datePaiement,
    ?string $libelle = null,
    ?TransactionLigne $ligneTiersSource = null,
): Transaction {
    $ligneTiersSource ??= $this->posteReporteResolver->pourTransaction(
        $t1,
        Carbon::instance($datePaiement),
    );

    $this->assertLigneTiersReglable($t1, $ligneTiersSource);

    return $this->creerReglementDepuisLigne(
        $t1,
        $ligneTiersSource,
        $mode,
        $compteTresorerie,
        $datePaiement,
        $libelle,
    );
}
```

Extraire le corps actuel dans `creerReglementDepuisLigne()` sans changer sa matrice D/C. `assertLigneTiersReglable()` vérifie compte 401/411, tiers, non-lettrage et racine identique via `PosteReporteResolver::racineId()`.

- [ ] **Step 4: Vérifier GREEN et les anciens appels**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/EcritureGeneratorPourReglementTest.php tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php tests/Feature/Services/ReglementOperationServicePartieDoubleTest.php`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/EcritureGenerator.php tests/Feature/Services/Compta/EcritureGeneratorPourReglementTest.php tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php
git commit -m "refactor(compta): cibler explicitement le poste réglé"
```

---

### Task 4: Règlement total et partiel atomique

**Files:**
- Create: `app/DTOs/Compta/PosteTiersReglementData.php`
- Create: `app/Services/Compta/PosteTiersReglementService.php`
- Modify: `app/Services/ReglementOperationService.php`
- Modify: `app/Services/Compta/EtatReglementResolver.php`
- Test: `tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php`
- Test: `tests/Feature/Services/ReglementOperationServiceUnifieTest.php`

**Interfaces:**
- Produces: `PosteTiersReglementService::regler(PosteTiersReglementData $data): Transaction`
- Produces: anciens `marquerRecu()` et `marquerPaye()` délégués au nouveau service

- [ ] **Step 1: Créer le DTO de commande**

```php
<?php

declare(strict_types=1);

namespace App\DTOs\Compta;

use App\Enums\ModePaiement;
use Carbon\CarbonImmutable;

final readonly class PosteTiersReglementData
{
    public function __construct(
        public int $ligneId,
        public int $montantCentimes,
        public CarbonImmutable $date,
        public ModePaiement $mode,
        public ?int $compteBancaireId,
        public int $exercice,
    ) {}
}
```

- [ ] **Step 2: Écrire les tests de règlement en échec**

Pour 411 D 100 €, régler 30 € et vérifier :

```php
$t2 = $service->regler(new PosteTiersReglementData(
    ligneId: (int) $ligne411->id,
    montantCentimes: 3000,
    date: CarbonImmutable::parse('2026-07-23'),
    mode: ModePaiement::Virement,
    compteBancaireId: (int) $compteBancaire->id,
    exercice: 2025,
));

$ligne411->refresh();
$fraction = $ligne411->fractionsPosteTiers()->sole();

expect((int) round((float) $ligne411->debit * 100))->toBe(7000)
    ->and((int) round((float) $fraction->debit * 100))->toBe(3000)
    ->and($fraction->lettrage_code)->not->toBeNull()
    ->and($t2->date->toDateString())->toBe('2026-07-23')
    ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
```

Ajouter le miroir 401, le règlement total, deux partiels successifs, un descendant AN, les montants 0/négatif/supérieur, une date hors exercice, un exercice clôturé, un compte de trésorerie introuvable et un poste d'un autre tenant.

- [ ] **Step 3: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php`

Expected: FAIL car le service n'existe pas.

- [ ] **Step 4: Implémenter le verrouillage et la validation**

Le cœur de `regler()` suit exactement cet ordre :

```php
return DB::transaction(function () use ($data): Transaction {
    $ligneDemandee = TransactionLigne::query()
        ->with(['compte', 'transaction'])
        ->lockForUpdate()
        ->findOrFail($data->ligneId);

    $this->exerciceService->assertOuvert($data->exercice);
    $this->assertDateDansExercice($data->date, $data->exercice);

    $poste = $this->postesOuverts->trouver((int) $ligneDemandee->id, $data->exercice);
    $this->assertMontant($data->montantCentimes, $poste->soldeCentimes);

    $ligneALettrer = $this->preparerPartPayee($poste, $data->montantCentimes);
    $compteTresorerie = $this->resoudreCompteTresorerie($ligneALettrer, $data);
    $t1 = Transaction::findOrFail($poste->transactionOrigineId);

    $t2 = $this->ecritureGenerator->pourReglement(
        t1: $t1,
        mode: $data->mode,
        compteTresorerie: $compteTresorerie,
        datePaiement: $data->date,
        ligneTiersSource: $ligneALettrer,
    );

    $this->etatReglementResolver->syncer($t1->fresh());

    return $t2;
});
```

Résoudre la trésorerie avec `CompteTresorerieResolver::resoudre()` et lever une exception métier si le résultat est nul.

- [ ] **Step 5: Implémenter le découpage**

Verrouiller toutes les `ligneIdsOuvertes`. Si plusieurs fractions ouvertes existent, les consolider dans la première ligne non lettrée avant tout nouveau découpage. Pour un partiel :

```php
$montantOuvert = $this->montantCentimes($canonique);
$reliquat = $montantOuvert - $montantPayeCentimes;

$canonique->update($this->montantsSelonSens($canonique, $reliquat));

$fraction = $canonique->replicate([
    'id',
    'lettrage_code',
    'deleted_at',
]);
$fraction->fill($this->montantsSelonSens($canonique, $montantPayeCentimes));
$fraction->poste_tiers_parent_id = (int) ($canonique->poste_tiers_parent_id ?? $canonique->id);
$fraction->save();

return $fraction->fresh(['compte', 'transaction']);
```

Pour un total, retourner directement la ligne ouverte verrouillée. Ne jamais toucher aux lignes 6/7 ou à `transaction_ligne_affectations`.

- [ ] **Step 6: Agréger le statut**

Avant la logique actuelle d'`EtatReglementResolver::resolve()`, demander le solde métier :

```php
if ($this->postesOuverts->soldeActifPourTransaction($t1) > 0) {
    return StatutReglement::EnAttente;
}
```

Lorsque le poste est soldé avec plusieurs T2, agréger ainsi : `EnMain` si au moins un portage 5112/530 reste non remis ; `Pointe` si toutes les branches bancaires terminales sont rapprochées ; `Recu` dans les autres cas.

- [ ] **Step 7: Déléguer les anciens boutons**

Le nouveau `PosteTiersReglementService` exige immédiatement une date explicite. Pour éviter de modifier prématurément les composants Livewire qui seront remplacés en tâche 8, conserver temporairement les signatures publiques existantes de `ReglementOperationService::marquerRecu()` et `marquerPaye()`. Ces wrappers de compatibilité délèguent au nouveau service avec la date actuellement dérivée par le flux historique et portent un `@deprecated`.

```php
/** @deprecated Les interfaces utilisateur doivent ouvrir PosteTiersReglementModal. */
public function marquerRecu(
    Transaction $transaction,
    ?ModePaiement $mode = null,
    ?int $compteId = null,
): void
```

La tâche 8 supprime tous les appels UI à ces wrappers au profit de la modale datée. Les traitements internes de rapprochement conservent leur date métier connue. La tâche 10 vérifie avec `rg` qu'aucun composant Livewire n'appelle encore `marquerRecu()`, `marquerPaye()` ou `marquerRegle()`.

- [ ] **Step 8: Vérifier GREEN**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php tests/Feature/Services/ReglementOperationServiceUnifieTest.php tests/Feature/Services/ReglementOperationStatutDeriveTest.php`

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/DTOs/Compta/PosteTiersReglementData.php app/Services/Compta/PosteTiersReglementService.php app/Services/ReglementOperationService.php app/Services/Compta/EtatReglementResolver.php tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php tests/Feature/Services/ReglementOperationServiceUnifieTest.php tests/Feature/Services/ReglementOperationStatutDeriveTest.php
git commit -m "feat(compta): gérer les règlements tiers partiels"
```

---

### Task 5: Annulation sécurisée et verrouillage des transactions réglées

**Files:**
- Modify: `app/Services/Compta/PosteTiersReglementService.php`
- Modify: `app/Models/Transaction.php`
- Modify: `app/Services/TransactionService.php`
- Test: `tests/Feature/Services/Compta/AnnulationReglementTiersTest.php`
- Test: `tests/Feature/Services/TransactionServicePartieDoubleTest.php`

**Interfaces:**
- Produces: `PosteTiersReglementService::annuler(int $transactionReglementId): void`
- Produces: `Transaction::aUnReglementTiers(): bool`

- [ ] **Step 1: Écrire les tests d'annulation en échec**

Tester un total, un partiel avec parent encore ouvert, un partiel dont le parent a ensuite été soldé, une T2 rapprochée, une T2 liée à une remise et un rollback provoqué après délettrage.

Assertions du partiel avec parent ouvert :

```php
$service->annuler((int) $t2Partiel->id);

expect(Transaction::withTrashed()->find($t2Partiel->id))->toBeNull()
    ->and((int) round((float) $parent->fresh()->debit * 100))->toBe(10000)
    ->and($fraction->fresh())->toBeNull()
    ->and($t1->fresh()->statut_reglement)->toBe(StatutReglement::EnAttente);
```

Assertions lorsque le parent a déjà été soldé :

```php
expect($fraction->fresh()->lettrage_code)->toBeNull()
    ->and((int) round((float) $fraction->fresh()->debit * 100))->toBe(3000)
    ->and($postes->pourTransaction($t1, 2025)?->soldeCentimes)->toBe(3000);
```

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/AnnulationReglementTiersTest.php`

Expected: FAIL car `annuler()` n'existe pas.

- [ ] **Step 3: Implémenter l'annulation atomique**

Dans `annuler()` :

1. verrouiller T2 et ses lignes ;
2. refuser `rapprochement_id`, `remise_id`, une ligne de portage 5112/530 lettrée ou un exercice clôturé ;
3. trouver la ligne tiers T2 et sa paire par `lettrage_code` ;
4. délettrer la paire via `LettrageService` avant toute suppression ;
5. force-delete les lignes T2 puis T2 ;
6. si la paire est une fraction et son parent est non lettré, additionner les montants au parent puis soft-delete la fraction ;
7. sinon laisser la paire délettrée comme fraction ouverte ;
8. synchroniser la transaction métier racine.

Lever des exceptions métier en français ; ne jamais retourner silencieusement.

- [ ] **Step 4: Protéger l'édition d'une transaction réglée**

Ajouter :

```php
public function aUnReglementTiers(): bool
{
    return $this->lignes()
        ->whereHas('compte', fn (Builder $query) => $query->whereIn('numero_pcg', ['401', '411']))
        ->where(function (Builder $query): void {
            $query->whereNotNull('lettrage_code')
                ->orWhereHas('fractionsPosteTiers', fn (Builder $fraction) => $fraction->whereNotNull('lettrage_code'));
        })
        ->exists();
}
```

Dans `TransactionService::update()`, si cette méthode vaut vrai, interdire toute modification de date, type, tiers, montant, compte bancaire ou ventilation. Autoriser seulement `libelle`, `reference`, `notes` et les pièces jointes sans supprimer/recréer les lignes comptables. Ajouter un test prouvant qu'une édition de libellé préserve parent, fractions, T2 et codes de lettrage.

- [ ] **Step 5: Vérifier GREEN**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Compta/PosteTiersReglementService.php app/Models/Transaction.php app/Services/TransactionService.php tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/TransactionServicePartieDoubleTest.php
git commit -m "feat(compta): sécuriser l annulation des règlements"
```

---

### Task 6: Modales Livewire communes

**Files:**
- Create: `app/Livewire/Compta/PosteTiersReglementModal.php`
- Create: `app/Livewire/Compta/AnnulationReglementTiersModal.php`
- Create: `resources/views/livewire/compta/poste-tiers-reglement-modal.blade.php`
- Create: `resources/views/livewire/compta/annulation-reglement-tiers-modal.blade.php`
- Test: `tests/Feature/Livewire/PosteTiersReglementModalTest.php`

**Interfaces:**
- Consumes event: `poste-tiers-reglement:ouvrir` avec `ligneId`, `exercice`
- Emits event: `poste-tiers-reglement:enregistre`
- Consumes event: `poste-tiers-reglement:annuler` avec `transactionReglementId`
- Emits event: `poste-tiers-reglement:annule`

- [ ] **Step 1: Écrire les tests Livewire en échec**

```php
Livewire::test(PosteTiersReglementModal::class, ['exercice' => 2025])
    ->dispatch('poste-tiers-reglement:ouvrir', ligneId: (int) $ligne->id, exercice: 2025)
    ->assertSet('montant', '100,00')
    ->assertSet('dateReglement', '2026-07-23')
    ->set('montant', '30,00')
    ->set('mode', ModePaiement::Virement->value)
    ->set('compteBancaireId', (int) $compteBancaire->id)
    ->call('enregistrer')
    ->assertHasNoErrors()
    ->assertDispatched('poste-tiers-reglement:enregistre');
```

Figer `CarbonImmutable::setTestNow()` pour tester aujourd'hui, borne basse et borne haute. Tester les messages de montant et date invalides.

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PosteTiersReglementModalTest.php`

Expected: FAIL car les composants n'existent pas.

- [ ] **Step 3: Implémenter la modale de règlement**

Propriétés publiques :

```php
public int $exercice;
public ?int $ligneId = null;
public string $montant = '';
public string $dateReglement = '';
public string $mode = '';
public ?int $compteBancaireId = null;
public string $titre = '';
public string $posteOrigine = '';
```

`ouvrir()` charge le poste par le service, préremplit le montant avec `number_format($centimes / 100, 2, ',', ' ')` et la date avec la date du jour bornée par `ExerciceService::dateRange()`. `enregistrer()` parse la virgule en centimes, valide, construit `PosteTiersReglementData`, appelle le service puis ferme la modale.

- [ ] **Step 4: Implémenter les vues Bootstrap**

La vue de règlement contient les quatre champs, un résumé de la pièce/référence d'origine, les erreurs Livewire et :

```html
<div class="modal fade"
     wire:ignore.self
     x-data
     x-on:poste-tiers-reglement-modal-open.window="bootstrap.Modal.getOrCreateInstance($el).show()"
     x-on:poste-tiers-reglement-modal-close.window="bootstrap.Modal.getOrCreateInstance($el).hide()">
```

La modale d'annulation affiche la date et le montant de T2, exige un clic explicite sur `Annuler le règlement` et appelle `PosteTiersReglementService::annuler()`.

- [ ] **Step 5: Vérifier GREEN**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PosteTiersReglementModalTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Compta/PosteTiersReglementModal.php app/Livewire/Compta/AnnulationReglementTiersModal.php resources/views/livewire/compta/poste-tiers-reglement-modal.blade.php resources/views/livewire/compta/annulation-reglement-tiers-modal.blade.php tests/Feature/Livewire/PosteTiersReglementModalTest.php
git commit -m "feat(compta): ajouter les modales de règlement tiers"
```

---

### Task 7: Écran dédié « Postes tiers ouverts »

**Files:**
- Create: `app/Livewire/Compta/PostesTiersOuverts.php`
- Create: `resources/views/livewire/compta/postes-tiers-ouverts.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/sidebar.blade.php`
- Test: `tests/Feature/Livewire/PostesTiersOuvertsTest.php`

**Interfaces:**
- Consumes: `PostesTiersOuvertsService::paginer(int $exercice, ?string $compte, ?int $tiersId, ?int $exerciceOrigine, ?string $recherche, int $parPage, int $page): LengthAwarePaginator`
- Emits: `poste-tiers-reglement:ouvrir`

- [ ] **Step 1: Écrire les tests d'écran en échec**

Tester l'authentification, le rendu 401/411, les deux références d'origine, le badge AN, chaque filtre et l'ouverture de la modale :

```php
Livewire::test(PostesTiersOuverts::class)
    ->assertSee('Postes tiers ouverts')
    ->assertSee($t1->numero_piece)
    ->assertSee('REF-CLIENT-42')
    ->assertSee('Report AN')
    ->set('filtreCompte', '401')
    ->assertDontSee('REF-CLIENT-42')
    ->call('regler', (int) $ligne401->id)
    ->assertDispatched(
        'poste-tiers-reglement:ouvrir',
        ligneId: (int) $ligne401->id,
        exercice: 2025,
    );
```

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PostesTiersOuvertsTest.php`

Expected: FAIL car route et composant n'existent pas.

- [ ] **Step 3: Implémenter composant et route**

Le composant expose `filtreCompte`, `filtreTiersId`, `filtreExerciceOrigine`, `recherche`, réinitialise la pagination à chaque changement et se rafraîchit sur `poste-tiers-reglement:enregistre`/`annule`.

```php
Route::get('/postes-tiers-ouverts', PostesTiersOuverts::class)
    ->name('postes-tiers-ouverts');
```

- [ ] **Step 4: Implémenter tableau et navigation**

Ajouter l'entrée `Postes tiers ouverts` immédiatement après `Recettes & dépenses`. Le tableau affiche Type, Tiers, Solde, Date d'origine, Pièce, Référence, Libellé, Exercice, Action. Utiliser :

```html
<thead class="table-dark"
       style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
```

Ajouter `data-sort` ISO pour dates et centimes pour soldes. Inclure les deux composants de modale une seule fois sous le tableau.

- [ ] **Step 5: Vérifier GREEN**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/PostesTiersOuvertsTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Compta/PostesTiersOuverts.php resources/views/livewire/compta/postes-tiers-ouverts.blade.php routes/web.php resources/views/components/sidebar.blade.php tests/Feature/Livewire/PostesTiersOuvertsTest.php
git commit -m "feat(compta): ajouter l écran des postes tiers ouverts"
```

---

### Task 8: Reports AN et règlement daté dans Transactions

**Files:**
- Modify: `app/Services/Compta/PostesTiersOuvertsService.php`
- Modify: `app/Services/TransactionUniverselleService.php`
- Modify: `app/Livewire/TransactionUniverselle.php`
- Modify: `resources/views/livewire/transaction-universelle.blade.php`
- Modify: `app/Livewire/ReglementTable.php`
- Modify: `resources/views/livewire/reglement-table.blade.php`
- Modify: `tests/Feature/TransactionUniverselleServiceTest.php`
- Modify: `tests/Feature/Livewire/TransactionUniverselleMarquerRecuTest.php`
- Modify: `tests/Feature/Livewire/TransactionUniverselleTest.php`
- Modify: `tests/Feature/Livewire/ReglementTableTest.php`

**Interfaces:**
- Produces: `PostesTiersOuvertsService::brancheReportsTransactions(int $exercice): Builder`
- Consumes event: `poste-tiers-reglement:enregistre`

- [ ] **Step 1: Écrire les tests UNION en échec**

Après génération AN, paginer l'exercice N+1 et vérifier une ligne unique :

```php
$row = collect($result['paginator']->items())
    ->firstWhere('source_type', 'report_an');

expect($row)->not->toBeNull()
    ->and($row->date)->toBe('2026-09-01')
    ->and($row->numero_piece)->toBe($t1->numero_piece)
    ->and($row->reference)->toBe('REF-CLIENT-42')
    ->and((int) $row->poste_tiers_ligne_id)->toBe((int) $ligneAN->id);
```

Vérifier l'absence de doublon en N, l'application des bornes de date, du tiers, de la référence, du numéro de pièce et des types. Un 411 a `sens_tresorerie = recette`, un 401 `depense`.

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/TransactionUniverselleServiceTest.php`

Expected: FAIL car la branche `report_an` est absente.

- [ ] **Step 3: Étendre la forme commune de l'UNION**

Ajouter à toutes les branches existantes :

```sql
NULL as poste_tiers_ligne_id,
0 as is_report_an
```

La branche report fournit :

```sql
ligne_action_id as id,
'report_an' as source_type,
date_affichage as date,
numero_piece_origine as numero_piece,
reference_origine as reference,
ligne_action_id as poste_tiers_ligne_id,
1 as is_report_an
```

Les autres colonnes sont explicitement : tiers, type et identifiant du tiers d'origine ; libellé d'origine ; `compte_ventilation_nom = NULL` ; `nb_lignes = 1` ; compte bancaire et mode à `NULL` ; montant positif pour 411 et négatif pour 401 ; `pointe = 0` ; `statut_reglement = 'en_attente'` ; remise, rapprochement, notes, pièces jointes, extourne et règlement planifié à `NULL` ; indicateurs HelloAsso, miroir et verrou facture à `0` ; sens `recette` pour 411 et `depense` pour 401. Ne l'ajouter que si les types incluent le sens du poste et si aucun filtre `compteId`, `usageFilter` ou `ndfUniquement` ne l'exclut.

- [ ] **Step 4: Remplacer les anciennes actions Livewire**

Supprimer les propriétés `recuTxId/recuMode/recuCompteId` et `payeTxId/payeMode/payeCompteId` ainsi que les deux modales dupliquées. Pour une transaction ordinaire, résoudre son poste par `pourTransaction()` ; pour `report_an`, utiliser `poste_tiers_ligne_id`. Dans les deux cas :

```php
$this->dispatch(
    'poste-tiers-reglement:ouvrir',
    ligneId: $ligneId,
    exercice: $this->exercice ?? app(ExerciceService::class)->current(),
);
```

Inclure `<livewire:compta.poste-tiers-reglement-modal>` et écouter l'événement de succès pour rafraîchir.

- [ ] **Step 5: Brancher aussi la grille Règlements**

Remplacer `ReglementTable::marquerRecu(int $transactionId)` par une résolution du poste suivie du même événement `poste-tiers-reglement:ouvrir`. Inclure la modale commune dans `reglement-table.blade.php` et rafraîchir la grille sur `poste-tiers-reglement:enregistre`. Ajouter un test prouvant que le clic ouvre la modale avec le bon `ligneId` sans créer immédiatement T2.

- [ ] **Step 6: Adapter le rendu**

La ligne virtuelle affiche le badge `Report AN`, interdit édition/suppression/extourne, conserve expansion et action de règlement. Les boutons ordinaires `Marquer payé/reçu` ouvrent désormais la modale datée.

- [ ] **Step 7: Vérifier GREEN**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/TransactionUniverselleServiceTest.php tests/Feature/Livewire/TransactionUniverselleMarquerRecuTest.php tests/Feature/Livewire/TransactionUniverselleTest.php tests/Feature/Livewire/ReglementTableTest.php`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Compta/PostesTiersOuvertsService.php app/Services/TransactionUniverselleService.php app/Livewire/TransactionUniverselle.php resources/views/livewire/transaction-universelle.blade.php app/Livewire/ReglementTable.php resources/views/livewire/reglement-table.blade.php tests/Feature/TransactionUniverselleServiceTest.php tests/Feature/Livewire/TransactionUniverselleMarquerRecuTest.php tests/Feature/Livewire/TransactionUniverselleTest.php tests/Feature/Livewire/ReglementTableTest.php
git commit -m "feat(compta): régler les reports depuis Transactions"
```

---

### Task 9: Date et état de règlement dans le formulaire de transaction

**Files:**
- Modify: `app/Livewire/TransactionForm.php`
- Modify: `resources/views/livewire/transaction-form.blade.php`
- Create: `app/Services/Compta/TransactionAvecReglementService.php`
- Modify: `app/Services/TransactionService.php`
- Modify: `tests/Feature/Livewire/TransactionFormStatutReglementTest.php`
- Modify: `tests/Feature/Livewire/TransactionFormSensTresorerieTest.php`
- Create: `tests/Feature/Livewire/TransactionFormReglementDateTest.php`

**Interfaces:**
- Consumes: `PosteTiersReglementService::regler(PosteTiersReglementData $data): Transaction`
- Consumes: `PostesTiersOuvertsService::reglements(Transaction $transaction): Collection`
- Produces: `TransactionAvecReglementService::enregistrer(?Transaction $transaction, array $data, array $lignes, ?CarbonImmutable $dateReglement, ?ModePaiement $mode, ?int $compteBancaireId, int $exercice): Transaction`
- Emits: événements des modales communes pour le reliquat et l'annulation

- [ ] **Step 1: Écrire les tests du formulaire en échec**

À la création avec `paiementRecu = true`, saisir date transaction `2026-07-10` et date règlement `2026-07-23`, puis vérifier :

```php
$t1 = Transaction::where('journal', JournalComptable::Vente->value)->sole();
$t2 = Transaction::where('journal', JournalComptable::Banque->value)->sole();

expect($t1->date->toDateString())->toBe('2026-07-10')
    ->and($t2->date->toDateString())->toBe('2026-07-23')
    ->and($t1->mode_paiement)->toBeNull()
    ->and($t1->statut_reglement)->toBe(StatutReglement::Recu);
```

Tester la création non réglée, l'édition ouverte Non→Oui, l'état partiel avec reliquat, l'état soldé, l'historique, l'ouverture d'annulation et la préservation des lettrages lors d'une modification de libellé.

- [ ] **Step 2: Vérifier RED**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/TransactionFormReglementDateTest.php tests/Feature/Livewire/TransactionFormStatutReglementTest.php`

Expected: FAIL car la date de règlement n'existe pas.

- [ ] **Step 3: Ajouter l'état Livewire**

Propriétés :

```php
public string $dateReglement = '';
public string $etatPaiement = 'ouvert';
public int $soldeRestantCentimes = 0;
public bool $isLockedByReglement = false;

/** @var array<int, array{transactionId:int,date:string,montant:string,mode:string,annulable:bool}> */
public array $reglementsEnregistres = [];
```

Au `mount/reset`, utiliser `ExerciceService::defaultDate()`. À `edit()`, charger le poste et l'historique : `ouvert`, `partiel` ou `solde`. `isLockedByReglement` protège les champs comptables une fois une T2 créée.

- [ ] **Step 4: Séparer T1 et T2 à la sauvegarde**

Pour recette/dépense, toujours envoyer à `TransactionService` une T1 à crédit :

```php
$doitRegler = $this->paiementRecu;
$modeReglement = $this->mode_paiement;
$compteReglementId = $this->compte_id;

$data['mode_paiement'] = null;
$data['statut_reglement'] = StatutReglement::EnAttente->value;
```

Le formulaire transmet les données à l'orchestrateur :

```php
$transaction = app(TransactionAvecReglementService::class)->enregistrer(
    transaction: $this->transactionId === null
        ? null
        : Transaction::findOrFail($this->transactionId),
    data: $data,
    lignes: $lignes,
    dateReglement: $doitRegler
        ? CarbonImmutable::parse($this->dateReglement)
        : null,
    mode: $doitRegler ? ModePaiement::from($modeReglement) : null,
    compteBancaireId: $doitRegler ? $compteReglementId : null,
    exercice: $exerciceService->current(),
);
```

Créer `TransactionAvecReglementService`. Sa méthode `enregistrer()` ouvre la transaction SQL de façade, appelle `TransactionService::create()` ou `update()`, résout la nouvelle ligne ouverte et, lorsque date et mode sont présents, construit alors `PosteTiersReglementData` avec le véritable `ligneActionId` et le solde total. Elle appelle `PosteTiersReglementService::regler()` puis retourne T1 fraîche. Le formulaire appelle uniquement cet orchestrateur ; un échec de T2 annule donc aussi T1.

- [ ] **Step 5: Adapter le formulaire**

- création ou poste ouvert : radios Oui/Non ;
- Oui : afficher date de règlement, mode, compte bancaire ;
- partiel : afficher `Partiellement réglé — reste X €` et bouton `Régler le reliquat` ;
- soldé : afficher `Payé` ou `Reçu` ;
- sous l'état : liste date, montant, mode et bouton `Annuler le règlement` seulement si `annulable`.

Les champs comptables verrouillés après règlement affichent un cadenas et un message expliquant qu'il faut annuler les règlements avant de modifier les montants, la date, le tiers ou la ventilation.

- [ ] **Step 6: Vérifier GREEN**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/TransactionFormReglementDateTest.php tests/Feature/Livewire/TransactionFormStatutReglementTest.php tests/Feature/Livewire/TransactionFormSensTresorerieTest.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/TransactionForm.php resources/views/livewire/transaction-form.blade.php app/Services/Compta/TransactionAvecReglementService.php app/Services/TransactionService.php tests/Feature/Livewire/TransactionFormReglementDateTest.php tests/Feature/Livewire/TransactionFormStatutReglementTest.php tests/Feature/Livewire/TransactionFormSensTresorerieTest.php
git commit -m "feat(compta): dater les règlements depuis le formulaire"
```

---

### Task 10: AN, clôture, régressions et recette

**Files:**
- Modify: `tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php`
- Modify: `tests/Unit/Services/Compta/ANouveau/ANouveauPreviewBuilderTest.php`
- Modify: `tests/Feature/Livewire/Exercices/ANouveauClotureTest.php`
- Create: `docs/recette/2026-07-recette-postes-tiers-ouverts.md`

**Interfaces:**
- Verifies: seul le reliquat non lettré est repris
- Verifies: un poste soldé ne produit aucune nouvelle ligne AN

- [ ] **Step 1: Ajouter les scénarios de clôture**

Cas partiel 100 € → 30 € payé :

```php
$preview = app(ANouveauPreviewBuilder::class)->build(2025);
$poste411 = collect($preview->lignes)
    ->first(fn ($ligne) => $ligne->numeroCompte === '411');

expect($poste411)->not->toBeNull()
    ->and($poste411->debitCentimes)->toBe(7000);
```

Après règlement total :

```php
$preview = app(ANouveauPreviewBuilder::class)->build(2025);

expect(collect($preview->lignes)
    ->contains(fn ($ligne) => $ligne->numeroCompte === '411'
        && $ligne->tiersId === (int) $tiers->id))->toBeFalse();
```

Après génération AN, régler le reliquat en N+1 et vérifier que N reste non lettré historiquement, que seule la ligne AN/fraction est lettrée et que N+2 ne reprend rien.

- [ ] **Step 2: Exécuter les tests ciblés comptables**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Services/Compta/PosteTiersReglementServiceTest.php tests/Feature/Services/Compta/AnnulationReglementTiersTest.php tests/Feature/Services/Compta/ANouveau tests/Unit/Services/Compta/ANouveau`

Expected: PASS.

- [ ] **Step 3: Formater uniquement les fichiers du lot**

Run: `./vendor/bin/pint app/DTOs/Compta app/Services/Compta/PostesTiersOuvertsService.php app/Services/Compta/PosteTiersReglementService.php app/Livewire/Compta app/Models/Transaction.php app/Models/TransactionLigne.php app/Services/Compta/ANouveau/PosteReporteResolver.php app/Services/Compta/EcritureGenerator.php app/Services/Compta/EtatReglementResolver.php app/Services/ReglementOperationService.php app/Services/TransactionUniverselleService.php app/Livewire/TransactionUniverselle.php app/Livewire/TransactionForm.php app/Services/TransactionService.php routes/web.php tests/Feature/Compta tests/Feature/Services/Compta tests/Feature/Livewire/PosteTiersReglementModalTest.php tests/Feature/Livewire/PostesTiersOuvertsTest.php tests/Feature/Livewire/TransactionFormReglementDateTest.php`

Expected: aucune erreur ; vérifier ensuite que Pint n'a pas touché un fichier utilisateur hors lot.

- [ ] **Step 4: Exécuter les suites Livewire et services concernées**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact tests/Feature/Livewire/TransactionUniverselleTest.php tests/Feature/Livewire/TransactionUniverselleMarquerRecuTest.php tests/Feature/Livewire/TransactionFormStatutReglementTest.php tests/Feature/Livewire/TransactionFormReglementDateTest.php tests/Feature/Services/ReglementOperationServicePartieDoubleTest.php tests/Feature/Services/ReglementOperationServiceUnifieTest.php tests/Feature/Services/ReglementOperationStatutDeriveTest.php`

Expected: PASS.

- [ ] **Step 5: Exécuter la suite complète**

Run: `php -d memory_limit=1G ./vendor/bin/pest --compact`

Expected: exit code 0.

- [ ] **Step 6: Réaliser la recette navigateur locale**

Sur `http://localhost`, avec `admin@monasso.fr / password` :

1. vérifier l'entrée `Comptabilité → Postes tiers ouverts` ;
2. créer une recette 411 non reçue avec numéro de pièce et référence ;
3. l'encaisser partiellement depuis l'écran dédié à une date choisie ;
4. vérifier le reliquat dans Transactions ;
5. solder ce reliquat depuis Transactions ;
6. vérifier les deux T2 à leurs dates réelles ;
7. créer une dépense 401 payée dès la saisie avec date transaction différente de la date de paiement ;
8. annuler une T2 non rapprochée ;
9. vérifier que l'annulation est refusée après rapprochement ;
10. vérifier le rendu desktop et mobile sans erreur console.

- [ ] **Step 7: Écrire la fiche de recette du lot**

Créer `docs/recette/2026-07-recette-postes-tiers-ouverts.md` avec les onze contrôles de l'étape 6, une colonne Résultat et une colonne Observations. Ne pas modifier `docs/recette/2026-07-recette-fonctionnelle-v5.md`, qui porte déjà des changements utilisateur.

- [ ] **Step 8: Commit final de couverture**

```bash
git add tests/Feature/Services/Compta/ANouveau/PosteReporteReglementTest.php tests/Unit/Services/Compta/ANouveau/ANouveauPreviewBuilderTest.php tests/Feature/Livewire/Exercices/ANouveauClotureTest.php
git add docs/recette/2026-07-recette-postes-tiers-ouverts.md
git commit -m "test(compta): couvrir les reliquats dans les AN"
```

- [ ] **Step 9: Vérifier le périmètre final**

Run: `git status --short`

Expected: seuls les changements utilisateur préexistants restent hors commits ; aucun fichier du lot n'est non suivi.

Run: `rg -n -- "marquer(Recu|Paye|Regle)\\(" app/Livewire`

Expected: aucune interface utilisateur ne crée encore un règlement par les wrappers historiques sans date.
