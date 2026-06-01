# Journal de banque — Slice 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduire la notion de journal comptable (`vente`/`achat`/`banque`/`od`) sur les transactions, masquer le journal de banque (T2/T4) des écrans opérationnels, et corriger l'inflation des agrégats legacy — sans toucher à la numérotation.

**Architecture:** Colonne `journal` (enum) sur `transactions`, posée à la création par un hook modèle (par `type`) + override explicite `banque` pour les écritures de trésorerie d'`EcritureGenerator` (T2/T4). Backfill structurel des données existantes (présence de ligne classe 6/7 → opérationnel, sinon banque). Masquage = scope `operationnel()` appliqué aux listes et agrégats opérationnels. Découplage de `RemiseBancaireService::modifier()` du critère `reference IS NULL` vers le critère structurel 512X.

**Tech Stack:** Laravel 11, Pest, MySQL/MariaDB (Sail), enums PHP castés.

**Spec:** `docs/specs/2026-06-01-journal-de-banque-slice1.md`

**Commande de test :** `./vendor/bin/sail test <path>` (SQLite :memory:, override phpunit.xml). **Ne jamais** lancer la suite avec un `bootstrap/cache/config.php` figé sur mysql (RefreshDatabase détruirait la base clonée — voir clone-prod-to-localhost.sh). Vérifier `ls bootstrap/cache/config.php` → absent avant de tester.

**Convention commit :** trailer `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`. Ne jamais stager `config/version.php` ni les `.gitignore` de `storage/app/`.

---

### Task 1: Enum `JournalComptable`

**Files:**
- Create: `app/Enums/JournalComptable.php`
- Test: `tests/Unit/Enums/JournalComptableTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;

it('expose les 4 journaux avec leurs valeurs', function () {
    expect(JournalComptable::Vente->value)->toBe('vente');
    expect(JournalComptable::Achat->value)->toBe('achat');
    expect(JournalComptable::Banque->value)->toBe('banque');
    expect(JournalComptable::Od->value)->toBe('od');
});

it('fournit un libellé lisible', function () {
    expect(JournalComptable::Vente->label())->toBe('Journal des ventes');
    expect(JournalComptable::Banque->label())->toBe('Journal de banque');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Unit/Enums/JournalComptableTest.php`
Expected: FAIL — `Class "App\Enums\JournalComptable" not found`.

- [ ] **Step 3: Create the enum**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum JournalComptable: string
{
    case Vente = 'vente';
    case Achat = 'achat';
    case Banque = 'banque';
    case Od = 'od';

    public function label(): string
    {
        return match ($this) {
            self::Vente => 'Journal des ventes',
            self::Achat => 'Journal des achats',
            self::Banque => 'Journal de banque',
            self::Od => 'Journal des opérations diverses',
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Unit/Enums/JournalComptableTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/JournalComptable.php tests/Unit/Enums/JournalComptableTest.php
git commit -m "feat(v5): enum JournalComptable (vente/achat/banque/od)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Colonne `journal` (nullable) sur `transactions`

**Files:**
- Create: `database/migrations/2026_06_01_000001_add_journal_to_transactions.php`
- Test: `tests/Feature/Journal/JournalColumnTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('ajoute la colonne journal sur transactions', function () {
    expect(Schema::hasColumn('transactions', 'journal'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Journal/JournalColumnTest.php`
Expected: FAIL — `journal` column absent (assertion false).

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Slice 1 « journal de banque » — voir docs/specs/2026-06-01-journal-de-banque-slice1.md.
 *
 * Ajoute la colonne `journal` (ENUM) sur `transactions`. Nullable à ce stade :
 * le backfill (migration 2026_06_01_000002) la peuple puis la passe NOT NULL.
 * Les nouvelles lignes reçoivent leur journal via le hook Transaction::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('journal', ['vente', 'achat', 'banque', 'od'])
                ->nullable()
                ->after('type_ecriture');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('journal');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Journal/JournalColumnTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_01_000001_add_journal_to_transactions.php tests/Feature/Journal/JournalColumnTest.php
git commit -m "feat(v5): colonne journal (nullable) sur transactions

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Cast + hook `creating` + scope `operationnel` sur `Transaction`

**Files:**
- Modify: `app/Models/Transaction.php` (méthode `casts()` ~ligne 55 ; ajouter `booted()` et `scopeOperationnel()`)
- Test: `tests/Feature/Journal/TransactionJournalAssignmentTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Transaction;

it('pose journal=vente à la création d\'une recette sans journal explicite', function () {
    $tx = Transaction::factory()->asRecette()->create(['journal' => null]);
    expect($tx->fresh()->journal)->toBe(JournalComptable::Vente);
});

it('pose journal=achat à la création d\'une dépense sans journal explicite', function () {
    $tx = Transaction::factory()->asDepense()->create(['journal' => null]);
    expect($tx->fresh()->journal)->toBe(JournalComptable::Achat);
});

it('préserve un journal explicite (banque) à la création', function () {
    $tx = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Banque]);
    expect($tx->fresh()->journal)->toBe(JournalComptable::Banque);
});

it('scopeOperationnel exclut le journal de banque', function () {
    $vente = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Vente]);
    $banque = Transaction::factory()->asRecette()->create(['journal' => JournalComptable::Banque]);

    $ids = Transaction::operationnel()->pluck('id')->all();

    expect($ids)->toContain($vente->id);
    expect($ids)->not->toContain($banque->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Journal/TransactionJournalAssignmentTest.php`
Expected: FAIL — `journal` non casté (renvoie string, pas l'enum) + `operationnel` scope inexistant (BadMethodCallException).

- [ ] **Step 3: Add cast, boot hook, and scope**

Dans `app/Models/Transaction.php`, ajouter `'journal' => JournalComptable::class` au tableau retourné par `casts()` :

```php
    protected function casts(): array
    {
        return [
            'type' => TypeTransaction::class,
            // ... casts existants ...
            'mode_paiement' => ModePaiement::class,
            'journal' => JournalComptable::class,
        ];
    }
```

Ajouter le `use App\Enums\JournalComptable;` en tête de fichier (avec les autres `use`).

Ajouter la méthode `booted()` (hook de création) et le scope `operationnel()` dans la classe :

```php
    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction): void {
            if ($transaction->journal !== null) {
                return;
            }
            $transaction->journal = $transaction->type === TypeTransaction::Recette
                ? JournalComptable::Vente
                : JournalComptable::Achat;
        });
    }

    /**
     * Restreint aux écritures opérationnelles (ventes/achats), excluant le
     * journal de banque (T2/T4) et OD. Utilisé par les listes et agrégats
     * recettes/dépenses.
     */
    public function scopeOperationnel(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('journal', [
            JournalComptable::Vente->value,
            JournalComptable::Achat->value,
        ]);
    }
```

> Note : si une méthode `booted()` existe déjà sur `Transaction`, ajouter le `static::creating(...)` à l'intérieur plutôt que de créer une 2ᵉ méthode. (Vérifié au 2026-06-01 : aucune `booted()`/`boot()` existante.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Journal/TransactionJournalAssignmentTest.php`
Expected: PASS (4 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Transaction.php tests/Feature/Journal/TransactionJournalAssignmentTest.php
git commit -m "feat(v5): Transaction.journal — cast + hook creating (par type) + scope operationnel

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `EcritureGenerator` pose `journal=banque` sur T2 et T4

**Files:**
- Modify: `app/Services/Compta/EcritureGenerator.php` (`createTransactionHeader` ~ligne 1268 ; appel T2 dans `pourEncaissementCreance` ~ligne 873 ; appel T4 dans `pourRemiseBancaire` ~ligne 1425)
- Test: `tests/Feature/Journal/EcritureGeneratorJournalTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Tiers;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    SystemeSeeder::seed();
});

it('pose journal=banque sur la T2 d\'encaissement de créance', function () {
    $tenantId = (int) TenantContext::currentId();
    $tiers = Tiers::factory()->create(['association_id' => $tenantId]);
    $compte706 = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '706', 'intitule' => 'Prestations',
        'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $generator = app(EcritureGenerator::class);

    // T1 : créance à crédit
    $t1 = $generator->pourRecetteACredit(
        $tiers,
        [['compte' => $compte706, 'montant' => 60.0]],
        new DateTimeImmutable('2025-10-01'),
    );

    // T2 : encaissement de la créance
    $compte512 = Compte::ofNumeroSysteme('512');
    $t2 = $generator->pourEncaissementCreance(
        $t1,
        $compte512,
        new DateTimeImmutable('2025-10-05'),
    );

    expect($t2->fresh()->journal)->toBe(JournalComptable::Banque);
    // La créance T1, elle, est une vente
    expect($t1->fresh()->journal)->toBe(JournalComptable::Vente);
});
```

> Note d'implémentation : adapter la signature exacte de `pourEncaissementCreance` et `pourRecetteACredit` aux paramètres réels (lire les signatures dans `app/Services/Compta/EcritureGenerator.php` aux lignes ~474 et ~820). Le point vérifié par le test est uniquement `journal` sur T1 (vente) vs T2 (banque). Si la résolution du compte d'encaissement diffère, utiliser le compte de portage attendu par la méthode.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Journal/EcritureGeneratorJournalTest.php`
Expected: FAIL — la T2 a `journal=vente` (posé par le hook via type=recette), pas `banque`.

- [ ] **Step 3: Add `journal` param to `createTransactionHeader` and pass `banque` from T2/T4**

Dans `createTransactionHeader` (ajouter le paramètre + la clé `journal`) :

```php
    private function createTransactionHeader(
        TypeTransaction $type,
        \DateTimeInterface $date,
        string $libelle,
        float $montant,
        ?ModePaiement $modePaiement,
        string $typeEcriture = 'normale',
        ?JournalComptable $journal = null,
    ): Transaction {
        return Transaction::create([
            'association_id' => (int) TenantContext::currentId(),
            'type' => $type,
            'date' => $date->format('Y-m-d'),
            'libelle' => $libelle,
            'montant_total' => $montant,
            'mode_paiement' => $modePaiement,
            'saisi_par' => Auth::id(),
            'equilibree' => true,
            'type_ecriture' => $typeEcriture,
            'journal' => $journal,
        ]);
    }
```

> Quand `$journal` est `null`, le hook `Transaction::creating` (Task 3) le remplit par `type`. Les `pour*` opérationnels (`pourRecetteComptant`, `pourRecetteACredit`, `pourDepenseComptant`, `pourDepenseACredit`, `pourReglementFournisseur`) n'ont rien à passer → vente/achat automatiques.

Ajouter `use App\Enums\JournalComptable;` en tête de `EcritureGenerator.php`.

Dans `pourEncaissementCreance` (T2), l'appel `createTransactionHeader(...)` reçoit en plus :

```php
            $t2 = $this->createTransactionHeader(
                type: TypeTransaction::Recette,
                date: $datePaiement,
                libelle: $libelleEffectif,
                montant: $montant,
                modePaiement: $mode,
                journal: JournalComptable::Banque,
            );
```

Dans `pourRemiseBancaire` (T4), idem :

```php
            $t4 = $this->createTransactionHeader(
                type: TypeTransaction::Recette,
                date: $remise->date instanceof \DateTimeInterface
                    ? $remise->date
                    : new \DateTimeImmutable((string) $remise->date),
                libelle: $libelle,
                montant: $total,
                modePaiement: $mode,
                journal: JournalComptable::Banque,
            );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Journal/EcritureGeneratorJournalTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/EcritureGenerator.php tests/Feature/Journal/EcritureGeneratorJournalTest.php
git commit -m "feat(v5): EcritureGenerator pose journal=banque sur T2 (encaissement) et T4 (remise)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Backfill `journal` sur l'existant + passage NOT NULL

**Files:**
- Create: `database/migrations/2026_06_01_000002_backfill_journal_in_transactions.php`
- Test: `tests/Feature/Journal/JournalBackfillTest.php`

**Règle :** une transaction ayant ≥1 ligne de compte classe 6 ou 7 → opérationnelle (`type=recette ⇒ vente`, `type=depense ⇒ achat`) ; sinon → `banque`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

/** Crée une transaction + lignes en forçant journal=NULL (état pré-backfill). */
function txAvecLignes(int $assoId, string $type, array $comptesClasses): Transaction
{
    $tx = Transaction::factory()->create(['association_id' => $assoId, 'type' => $type]);
    DB::table('transactions')->where('id', $tx->id)->update(['journal' => null]);
    $tx->lignes()->forceDelete();
    foreach ($comptesClasses as $i => $classe) {
        $compte = Compte::create([
            'association_id' => $assoId, 'numero_pcg' => "{$classe}0{$i}", 'intitule' => "C{$classe}{$i}",
            'classe' => $classe, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx->id, 'compte_id' => $compte->id,
            'debit' => $classe === 6 ? 10 : 0, 'credit' => $classe === 7 ? 10 : 0,
            'montant' => 0, 'sous_categorie_id' => null,
        ]);
    }

    return $tx;
}

it('classe les transactions selon la présence de ligne 6/7', function () {
    $assoId = (int) TenantContext::currentId();
    $vente = txAvecLignes($assoId, 'recette', [7, 4]);   // produit + 411 → vente
    $achat = txAvecLignes($assoId, 'depense', [6, 4]);   // charge + 401 → achat
    $banque = txAvecLignes($assoId, 'recette', [5, 5]);  // 512/5112 seulement → banque

    // Rejouer la migration de backfill
    Artisan::call('migrate', ['--force' => true]);

    expect($vente->fresh()->journal)->toBe(JournalComptable::Vente);
    expect($achat->fresh()->journal)->toBe(JournalComptable::Achat);
    expect($banque->fresh()->journal)->toBe(JournalComptable::Banque);
});
```

> Note : sur SQLite :memory:, toutes les migrations tournent déjà avant le test. Pour exercer le backfill sur des données posées dans le test, le test ré-applique la règle en appelant explicitement le helper de backfill. **Préférer** extraire la logique de backfill dans une classe `App\Services\Compta\Migrations\JournalBackfiller` (méthode statique `run(): void`) — comme `CompteIdBackfiller` (Step 36) — et appeler `JournalBackfiller::run()` dans le test après avoir posé les fixtures, plutôt que `Artisan::call('migrate')`. Réécrire le Step 1 en conséquence :
>
> ```php
>     App\Services\Compta\Migrations\JournalBackfiller::run();
> ```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Journal/JournalBackfillTest.php`
Expected: FAIL — `JournalBackfiller` inexistant (ou journal reste null).

- [ ] **Step 3: Create the backfiller + migration**

Créer `app/Services/Compta/Migrations/JournalBackfiller.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services\Compta\Migrations;

use Illuminate\Support\Facades\DB;

/**
 * Slice 1 « journal de banque ». Peuple transactions.journal :
 *   - ≥1 ligne de compte classe 6/7 → opérationnel (recette=vente, depense=achat) ;
 *   - sinon (trésorerie/bilan seul) → banque.
 * Idempotent : ne touche que les lignes journal IS NULL.
 */
final class JournalBackfiller
{
    public static function run(): void
    {
        // Opérationnel : a une ligne classe 6/7
        DB::statement(<<<'SQL'
            UPDATE transactions t
            SET t.journal = CASE WHEN t.type = 'recette' THEN 'vente' ELSE 'achat' END
            WHERE t.journal IS NULL
              AND EXISTS (
                SELECT 1 FROM transaction_lignes tl
                JOIN comptes c ON c.id = tl.compte_id
                WHERE tl.transaction_id = t.id
                  AND c.classe IN (6, 7)
                  AND tl.deleted_at IS NULL
              )
        SQL);

        // Reste : aucune ligne 6/7 → banque
        DB::statement(<<<'SQL'
            UPDATE transactions t
            SET t.journal = 'banque'
            WHERE t.journal IS NULL
        SQL);
    }
}
```

Créer `database/migrations/2026_06_01_000002_backfill_journal_in_transactions.php` :

```php
<?php

declare(strict_types=1);

use App\Services\Compta\Migrations\JournalBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1 « journal de banque ». Peuple transactions.journal sur l'existant
 * puis passe la colonne NOT NULL. Voir docs/specs/2026-06-01-journal-de-banque-slice1.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        JournalBackfiller::run();

        // Toutes les lignes sont désormais peuplées → NOT NULL.
        DB::statement("ALTER TABLE transactions MODIFY journal ENUM('vente','achat','banque','od') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE transactions MODIFY journal ENUM('vente','achat','banque','od') NULL");
    }
};
```

> ⚠️ SQLite (tests) ne supporte pas `ALTER TABLE ... MODIFY ... ENUM`. Garder le `MODIFY` conditionné au driver MySQL :
>
> ```php
>         if (DB::getDriverName() === 'mysql') {
>             DB::statement("ALTER TABLE transactions MODIFY journal ENUM('vente','achat','banque','od') NOT NULL");
>         }
> ```
>
> (En SQLite l'enum est de toute façon une colonne texte ; le hook `creating` garantit le non-null applicatif.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Journal/JournalBackfillTest.php`
Expected: PASS (rejouer = idempotent : un 2ᵉ `JournalBackfiller::run()` ne change rien car `journal IS NULL` ne matche plus).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Compta/Migrations/JournalBackfiller.php database/migrations/2026_06_01_000002_backfill_journal_in_transactions.php tests/Feature/Journal/JournalBackfillTest.php
git commit -m "feat(v5): backfill journal (classe 6/7 → vente/achat, sinon banque) + NOT NULL

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Masquer le journal de banque de la liste opérationnelle

**Files:**
- Modify: `app/Services/TransactionUniverselleService.php` (méthodes `brancheRecette` et `brancheDepense` — ajouter le filtre journal sur le `where('tx.type', ...)`)
- Test: `tests/Feature/Journal/TransactionListeMasquageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Association;
use App\Models\Transaction;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('exclut les transactions du journal de banque de la liste opérationnelle', function () {
    $assoId = (int) TenantContext::currentId();
    $vente = Transaction::factory()->asRecette()->create(['association_id' => $assoId, 'journal' => JournalComptable::Vente]);
    $banque = Transaction::factory()->asRecette()->create(['association_id' => $assoId, 'journal' => JournalComptable::Banque]);

    $page = app(TransactionUniverselleService::class)->paginate(
        include: ['recette' => true, 'depense' => true, 'virement' => false],
        perPage: 50,
    );
    $ids = collect($page->items())->pluck('id')->all();

    expect($ids)->toContain($vente->id);
    expect($ids)->not->toContain($banque->id);
});
```

> Note : adapter l'appel `paginate(...)` à la signature réelle (lire `app/Services/TransactionUniverselleService.php:20`). Le seul invariant testé : une tx `journal=banque` (type recette) n'apparaît pas, une tx `journal=vente` apparaît.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Journal/TransactionListeMasquageTest.php`
Expected: FAIL — `$banque->id` présent dans la liste.

- [ ] **Step 3: Add the journal filter to both branches**

Dans `brancheDepense` et `brancheRecette`, juste après `->where('tx.type', 'depense')` (resp. `'recette'`), ajouter :

```php
            ->whereIn('tx.journal', ['vente', 'achat'])
```

(Les branches virements lisent `virements_internes` et ne sont pas concernées.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Journal/TransactionListeMasquageTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/TransactionUniverselleService.php tests/Feature/Journal/TransactionListeMasquageTest.php
git commit -m "feat(v5): masque le journal de banque de la liste opérationnelle (T2/T4)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Corriger les agrégats legacy (inflation par les T4)

**Files:**
- Modify: `app/Livewire/Dashboard.php` (lignes ~37, 38, 73, 80)
- Modify: `app/Livewire/GestionDashboard.php` (lignes ~43, 53)
- Modify: `app/Livewire/Exercices/ClotureWizard.php` (lignes ~152, 153)
- Modify: `app/Services/Rapports/FluxTresorerieBuilder.php` (lignes ~63, 64)
- Test: `tests/Feature/Journal/AgregatsMasquageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Models\Association;
use App\Models\Transaction;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('le total recettes du flux de trésorerie exclut le journal de banque', function () {
    $assoId = (int) TenantContext::currentId();
    // Exercice 2025 : 1er sept 2025 → 31 août 2026
    Transaction::factory()->asRecette()->create(['association_id' => $assoId, 'journal' => JournalComptable::Vente, 'montant_total' => 100, 'date' => '2025-10-01']);
    Transaction::factory()->asRecette()->create(['association_id' => $assoId, 'journal' => JournalComptable::Banque, 'montant_total' => 80, 'date' => '2025-10-02']);

    $flux = app(FluxTresorerieBuilder::class)->build(2025);

    // Le total recettes ne doit compter que la vente (100), pas la remise banque (80)
    expect($flux['total_recettes'] ?? $flux['totalRecettes'])->toBe(100.0);
});
```

> Note : adapter la clé de retour réelle de `FluxTresorerieBuilder::build()` (lire le service). Le seul invariant : la tx `journal=banque` n'est pas comptée dans le total recettes.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Journal/AgregatsMasquageTest.php`
Expected: FAIL — total = 180 (inclut la banque).

- [ ] **Step 3: Add `->operationnel()` to each aggregate query**

Dans chaque fichier, insérer `->operationnel()` dans la chaîne après `Transaction::where('type', 'recette')` / `where('type', 'depense')`. Exemples :

`app/Services/Rapports/FluxTresorerieBuilder.php` :
```php
        $totalRecettes = round((float) Transaction::where('type', 'recette')->operationnel()->forExercice($exercice)->sum('montant_total'), 2);
        $totalDepenses = round((float) Transaction::where('type', 'depense')->operationnel()->forExercice($exercice)->sum('montant_total'), 2);
```

`app/Livewire/Dashboard.php` (lignes 37-38, et les `dernieresRecettes`/`dernieresDepenses` lignes 73/80) :
```php
        $totalRecettes = (float) Transaction::where('type', 'recette')->operationnel()->forExercice($exercice)->sum('montant_total');
        $totalDepenses = (float) Transaction::where('type', 'depense')->operationnel()->forExercice($exercice)->sum('montant_total');
        // ...
        $dernieresDepenses = Transaction::where('type', 'depense')->operationnel()->forExercice($exercice) /* ...suite inchangée... */;
        $dernieresRecettes = Transaction::where('type', 'recette')->operationnel()->forExercice($exercice) /* ...suite inchangée... */;
```

`app/Livewire/GestionDashboard.php` (lignes 43, 53) : ajouter `->operationnel()` après `where('type', 'recette')`.

`app/Livewire/Exercices/ClotureWizard.php` (lignes 152, 153) : ajouter `->operationnel()` après `where('type', 'recette')` / `where('type', 'depense')`.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail test tests/Feature/Journal/AgregatsMasquageTest.php`
Expected: PASS (total = 100.0).

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Dashboard.php app/Livewire/GestionDashboard.php app/Livewire/Exercices/ClotureWizard.php app/Services/Rapports/FluxTresorerieBuilder.php tests/Feature/Journal/AgregatsMasquageTest.php
git commit -m "fix(v5): agrégats recettes/dépenses excluent le journal de banque (inflation T4)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Découpler `RemiseBancaireService::modifier()` du critère `reference IS NULL`

**Files:**
- Modify: `app/Services/RemiseBancaireService.php` (`modifier()` ~lignes 145-199 : 3 emplacements `reference`)
- Test: `tests/Feature/Journal/ModifierRemiseDecoupleTest.php` (+ la suite remise existante doit rester verte)

**Principe :** la T4 = la transaction de remise portant une ligne 512X au débit (`queryT4($remise)`). Les sources = les autres transactions de la remise. Remplacer les 3 usages de `reference IS [NOT] NULL` par ce critère structurel.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Transaction;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

it('modifier() identifie la T4 par sa ligne 512X même si les sources ont reference=null', function () {
    // Construire une remise chèque comptabilisée avec ≥2 sources, puis forcer
    // reference=null sur toutes les transactions sources (cas prod réel — Finding 2).
    // Retirer une source via modifier() : la T4 ne doit PAS être traitée comme une source,
    // et l'index de renumérotation ne doit pas être décalé.
    [$remise, $sources] = creerRemiseChequeComptabilisee($this->association, montants: [40.0, 60.0, 20.0]);

    Transaction::whereIn('id', collect($sources)->pluck('id'))->update(['reference' => null]);

    $service = app(RemiseBancaireService::class);
    // Garder seulement 2 sources (retirer la 3ᵉ)
    $garder = collect($sources)->take(2)->pluck('id')->all();
    $service->modifier($remise->fresh(), $garder);

    // La T4 existe toujours et porte le total des 2 sources gardées (100.0)
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereHas('lignes', fn ($q) => $q->where('debit', '>', 0)->whereHas('compte', fn ($c) => $c->bancaires()))
        ->first();
    expect($t4)->not->toBeNull();
    expect((float) $t4->montant_total)->toBe(100.0);

    // La source retirée est repassée en attente (remise_id null)
    $retiree = Transaction::find(collect($sources)->last()->id);
    expect($retiree->remise_id)->toBeNull();
});
```

> Note : `creerRemiseChequeComptabilisee(...)` est un helper à écrire dans le test (ou réutiliser un helper de fixture remise existant — chercher dans `tests/` un helper de remise comptabilisée, p. ex. dans `tests/Feature/Rappro/` ou `tests/Feature/Remise/`). Il crée N transactions chèques « reçu » + une `RemiseBancaire`, puis appelle `RemiseBancaireService::comptabiliser`/`modifier` pour générer la T4. Réutiliser le helper existant des tests remise plutôt que d'en réécrire un.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test tests/Feature/Journal/ModifierRemiseDecoupleTest.php`
Expected: FAIL — avec le critère `reference IS NULL`, les sources à reference=null sont confondues avec la T4 → décalage / T4 mal gérée.

- [ ] **Step 3: Replace the 3 reference-based criteria with the structural one**

Dans `modifier()` (`app/Services/RemiseBancaireService.php`), remplacer :

**(a) Sélection des sources à retirer** (~ligne 148) — au lieu de `->whereNotNull('reference')`, exclure la T4 par son id :

```php
            $t4Id = optional($this->queryT4($remise)->first())->id;

            $aRetirer = Transaction::where('remise_id', $remise->id)
                ->whereNotIn('id', $transactionIds)
                ->when($t4Id !== null, fn ($q) => $q->where('id', '!=', $t4Id))
                ->get();
```

**(b) Comptage d'index** (~ligne 173) — compter les sources (toutes les tx de la remise sauf la T4) :

```php
            $index = Transaction::where('remise_id', $remise->id)
                ->when($t4Id !== null, fn ($q) => $q->where('id', '!=', $t4Id))
                ->count();
```

**(c) Boucle de renumérotation des nouvelles sources** (~ligne 178) — itérer sur les `$transactionIds` qui ne sont pas la T4 et pas encore référencés. Comme `$transactionIds` sont des ids de sources sélectionnées par l'utilisateur (la T4 n'y figure jamais), remplacer `->whereNull('reference')` par une exclusion explicite de la T4 + absence de référence :

```php
            foreach (Transaction::whereIn('id', $transactionIds)
                ->when($t4Id !== null, fn ($q) => $q->where('id', '!=', $t4Id))
                ->whereNull('reference')
                ->get() as $tx) {
```

> Note : `$t4Id` est capturé **avant** `supprimerT4SiExiste()` (lignes ~197). Mais `modifier()` recrée la T4 ensuite (`recreerT4`), donc capturer `$t4Id` en tête du `DB::transaction(...)` reste correct pour les opérations (a)/(b)/(c) qui précèdent `supprimerT4SiExiste`. Vérifier l'ordre : capture en début de transaction, avant (a).
>
> Mettre à jour les commentaires de code obsolètes mentionnant « reference=null / sentinelle » (lignes ~146-147, 169-172).

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Journal/ModifierRemiseDecoupleTest.php`
Expected: PASS.

Run (non-régression remise) : `./vendor/bin/sail test tests/Feature/Rappro/ tests/Feature/Remise/ --filter=emise`
Expected: PASS (suite remise existante verte). Adapter le chemin si les tests remise sont ailleurs (grep `RemiseBancaireService` dans `tests/`).

- [ ] **Step 5: Commit**

```bash
git add app/Services/RemiseBancaireService.php tests/Feature/Journal/ModifierRemiseDecoupleTest.php
git commit -m "refactor(v5): modifier() identifie la T4 par ligne 512X, plus par reference (fin sentinelle)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Vérification finale (suite complète + clone réel)

**Files:** aucun (validation).

- [ ] **Step 1: Vérifier l'absence de config cachée (sinon RefreshDatabase détruit la base)**

Run: `ls bootstrap/cache/config.php && echo "DANGER: clear it" || echo "OK"`
Si présent : `./vendor/bin/sail artisan config:clear`.

- [ ] **Step 2: Suite complète**

Run: `./vendor/bin/sail test`
Expected: `0 failed` (les `deprecated` PHP 8.5 sont du bruit ignoré).

- [ ] **Step 3: Pint**

Run: `./vendor/bin/sail bin pint app/ tests/`
Expected: tout vert (formaté).

- [ ] **Step 4: (manuel) Valider sur le clone prod**

Après re-clone (`./scripts/clone-prod-to-localhost.sh`), vérifier :
- la liste des transactions ne montre plus les « Remise chèque » T4 ;
- le dashboard « total recettes » 2025 passe de 32 889 € à **31 334 €** (correction de l'inflation de 1 555 €) ;
- `compta:dump-transaction <id_T4>` montre toujours la T4 (journal=banque), le rapprochement et le compte 512X intacts.

- [ ] **Step 5: Commit (si ajustements Pint)**

```bash
git add -A -- app/ tests/ database/
git commit -m "chore(v5): pint + finitions slice journal de banque

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Notes transverses

- **Multi-tenant** : toutes les requêtes ajoutées respectent le scope existant (les agrégats Eloquent héritent du `TenantScope` ; le backfill SQL est global mais ne lit/écrit que des colonnes propres à chaque ligne, sans fuite inter-tenant).
- **Hors périmètre** (rappel spec) : numérotation des journaux, référence métier T4 `RBC-xxxxx`, UI journaux visibles + bascule vocabulaire, conversion des `VirementInterne` en écritures de banque. → Slices 2 et 3.
- **Pas de masquage global** : on n'ajoute **pas** de global scope sur `Transaction` (cela casserait rapprochement, relevé 512X, backfill). Le masquage est explicite via `operationnel()` aux chokepoints listés.
