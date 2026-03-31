# Facturation Factur-X — Plan d'implémentation V1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à l'association de facturer ses prestations avec génération de PDF Factur-X conformes.

**Architecture:** Pattern identique aux remises bancaires — sélection de transactions recette → brouillon → validation → PDF. Modèle `Facture` avec `FactureLigne` (copie figée), pivot `facture_transaction`, verrouillage des transactions validées. PDF via dompdf + `atgp/factur-x` pour PDF/A-3.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Pest PHP, dompdf, atgp/factur-x

**Spec:** `docs/superpowers/specs/2026-03-31-facturation-facturx-design.md`

---

## File Structure

### New files to create

```
app/Enums/StatutFacture.php
app/Enums/TypeLigneFacture.php
app/Models/Facture.php
app/Models/FactureLigne.php
app/Services/FactureService.php
app/Livewire/FactureList.php
app/Livewire/FactureEdit.php
app/Livewire/FactureShow.php
app/Http/Controllers/FacturePdfController.php
resources/views/gestion/factures/index.blade.php
resources/views/gestion/factures/edit.blade.php
resources/views/gestion/factures/show.blade.php
resources/views/livewire/facture-list.blade.php
resources/views/livewire/facture-edit.blade.php
resources/views/livewire/facture-show.blade.php
resources/views/pdf/facture.blade.php
database/migrations/2026_03_31_100001_add_bic_domiciliation_to_comptes_bancaires.php
database/migrations/2026_03_31_100002_add_siret_facture_to_association.php
database/migrations/2026_03_31_100003_create_factures_table.php
database/migrations/2026_03_31_100004_create_facture_lignes_table.php
database/migrations/2026_03_31_100005_create_facture_transaction_table.php
tests/Feature/Models/FactureModelTest.php
tests/Feature/Services/FactureServiceTest.php
tests/Feature/Services/FactureServiceValidationTest.php
tests/Feature/Services/FactureServiceLockTest.php
tests/Feature/Livewire/FactureListTest.php
tests/Feature/Livewire/FactureEditTest.php
tests/Feature/Livewire/FactureShowTest.php
tests/Feature/FacturePdfTest.php
```

### Existing files to modify

```
app/Models/Transaction.php              — add factures() relation + isLockedByFacture()
app/Services/TransactionService.php     — add facture lock checks in update/delete/affecterLigne
app/Livewire/Parametres/AssociationForm.php — add facturation tab
resources/views/livewire/parametres/association-form.blade.php — add facturation tab
resources/views/layouts/app.blade.php   — add Factures nav item
routes/web.php                          — add factures routes
database/seeders/DatabaseSeeder.php     — seed facture defaults on association
```

---

## Task 1: Enums + Migrations

**Files:**
- Create: `app/Enums/StatutFacture.php`
- Create: `app/Enums/TypeLigneFacture.php`
- Create: `database/migrations/2026_03_31_100001_add_bic_domiciliation_to_comptes_bancaires.php`
- Create: `database/migrations/2026_03_31_100002_add_siret_facture_to_association.php`
- Create: `database/migrations/2026_03_31_100003_create_factures_table.php`
- Create: `database/migrations/2026_03_31_100004_create_facture_lignes_table.php`
- Create: `database/migrations/2026_03_31_100005_create_facture_transaction_table.php`

- [ ] **Step 1: Create StatutFacture enum**

```php
// app/Enums/StatutFacture.php
<?php
declare(strict_types=1);
namespace App\Enums;

enum StatutFacture: string {
    case Brouillon = 'brouillon';
    case Validee = 'validee';
    case Annulee = 'annulee';
}
```

- [ ] **Step 2: Create TypeLigneFacture enum**

```php
// app/Enums/TypeLigneFacture.php
<?php
declare(strict_types=1);
namespace App\Enums;

enum TypeLigneFacture: string {
    case Montant = 'montant';
    case Texte = 'texte';
}
```

- [ ] **Step 3: Create migration add_bic_domiciliation_to_comptes_bancaires**

Add `bic` (string, nullable) and `domiciliation` (string, nullable) to `comptes_bancaires`.

- [ ] **Step 4: Create migration add_siret_facture_to_association**

Add to `association`:
- `siret` (string, nullable)
- `forme_juridique` (string, nullable, default "Association loi 1901")
- `facture_conditions_reglement` (string, nullable)
- `facture_mentions_legales` (text, nullable)
- `facture_mentions_penalites` (text, nullable)
- `facture_compte_bancaire_id` (foreignId → comptes_bancaires, nullable)

- [ ] **Step 5: Create migration create_factures_table**

Schema from spec section 2.1. Key points:
- `numero` string unique nullable
- `statut` string default 'brouillon'
- `tiers_id` FK constrained
- `compte_bancaire_id` FK nullable constrained
- `montant_total` decimal(10,2) default 0
- `numero_avoir` string unique nullable (V2 but column exists now)
- `date_annulation` date nullable
- `notes` text nullable
- `saisi_par` FK → users constrained
- `exercice` integer
- NO soft deletes
- timestamps

- [ ] **Step 6: Create migration create_facture_lignes_table**

Schema from spec section 2.2:
- `facture_id` FK constrained cascadeOnDelete
- `transaction_ligne_id` FK nullable constrained nullOnDelete
- `type` string default 'montant'
- `libelle` string
- `montant` decimal(10,2) nullable
- `ordre` integer

- [ ] **Step 7: Create migration create_facture_transaction_table (pivot)**

Schema from spec section 2.3:
- `facture_id` FK constrained cascadeOnDelete
- `transaction_id` FK constrained cascadeOnDelete
- unique index on (facture_id, transaction_id)
- No timestamps, no id

- [ ] **Step 8: Run migrations**

Run: `./vendor/bin/sail artisan migrate`
Expected: all 5 migrations run successfully

- [ ] **Step 9: Add bic/domiciliation to CompteBancaire model fillable**

Modify: `app/Models/CompteBancaire.php` — add `'bic'` and `'domiciliation'` to `$fillable`

- [ ] **Step 10: Commit**

```bash
git add -A && git commit -m "feat(facturation): enums + migrations (factures, facture_lignes, pivot, enrichissements)"
```

---

## Task 2: Models Facture + FactureLigne

**Files:**
- Create: `app/Models/Facture.php`
- Create: `app/Models/FactureLigne.php`
- Modify: `app/Models/Transaction.php`

- [ ] **Step 1: Create Facture model**

```php
// app/Models/Facture.php
<?php
declare(strict_types=1);
namespace App\Models;

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Facture extends Model
{
    protected $fillable = [
        'numero', 'date', 'statut', 'tiers_id', 'compte_bancaire_id',
        'conditions_reglement', 'mentions_legales', 'montant_total',
        'numero_avoir', 'date_annulation', 'notes', 'saisi_par', 'exercice',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'date_annulation' => 'date',
            'statut' => StatutFacture::class,
            'montant_total' => 'decimal:2',
            'exercice' => 'integer',
            'tiers_id' => 'integer',
            'compte_bancaire_id' => 'integer',
            'saisi_par' => 'integer',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class);
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(FactureLigne::class)->orderBy('ordre');
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'facture_transaction');
    }

    public function montantRegle(): float
    {
        return (float) $this->transactions()
            ->whereNotNull('remise_id')
            ->sum('montant_total');
    }

    public function isAcquittee(): bool
    {
        return $this->statut === StatutFacture::Validee
            && $this->montantRegle() >= (float) $this->montant_total;
    }
}
```

- [ ] **Step 2: Create FactureLigne model**

```php
// app/Models/FactureLigne.php
<?php
declare(strict_types=1);
namespace App\Models;

use App\Enums\TypeLigneFacture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FactureLigne extends Model
{
    protected $table = 'facture_lignes';

    public $timestamps = false;

    protected $fillable = [
        'facture_id', 'transaction_ligne_id', 'type', 'libelle', 'montant', 'ordre',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeLigneFacture::class,
            'montant' => 'decimal:2',
            'ordre' => 'integer',
            'facture_id' => 'integer',
            'transaction_ligne_id' => 'integer',
        ];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function transactionLigne(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class);
    }
}
```

- [ ] **Step 3: Add factures() relation and isLockedByFacture() on Transaction model**

Modify: `app/Models/Transaction.php`

Add import: `use App\Enums\StatutFacture;`

Add relation:
```php
public function factures(): BelongsToMany
{
    return $this->belongsToMany(Facture::class, 'facture_transaction');
}
```

Add lock method:
```php
public function isLockedByFacture(): bool
{
    return $this->factures()
        ->where('statut', StatutFacture::Validee)
        ->exists();
}
```

- [ ] **Step 4: Write model tests**

Create `tests/Feature/Models/FactureModelTest.php` testing:
- Facture creation with all fields
- Relations (tiers, compteBancaire, saisiPar, lignes, transactions)
- `montantRegle()` returns 0 when no remise_id on linked transactions
- `montantRegle()` returns sum when remise_id is set
- `isAcquittee()` returns false when not fully paid
- `isAcquittee()` returns true when fully paid
- `isLockedByFacture()` on Transaction returns true when linked to validated facture
- `isLockedByFacture()` on Transaction returns false when linked to brouillon
- `isLockedByFacture()` on Transaction returns false when linked to annulée

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Models/FactureModelTest.php`
Expected: all tests pass

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(facturation): models Facture + FactureLigne + Transaction relations"
```

---

## Task 3: FactureService — Core CRUD

**Files:**
- Create: `app/Services/FactureService.php`
- Create: `tests/Feature/Services/FactureServiceTest.php`

- [ ] **Step 1: Write failing tests for creer() and supprimerBrouillon()**

Tests in `tests/Feature/Services/FactureServiceTest.php`:
- `creer()` creates a brouillon with correct defaults from association
- `creer()` throws if exercice is closed
- `supprimerBrouillon()` deletes facture + lignes + pivot
- `supprimerBrouillon()` throws if facture is validée

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceTest.php`
Expected: FAIL (FactureService class not found)

- [ ] **Step 3: Implement FactureService with creer() and supprimerBrouillon()**

```php
// app/Services/FactureService.php
<?php
declare(strict_types=1);
namespace App\Services;

use App\Enums\StatutFacture;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Seance;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class FactureService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function creer(int $tiersId): Facture
    {
        $exercice = $this->exerciceService->current();
        $this->exerciceService->assertOuvert($exercice);

        return DB::transaction(function () use ($tiersId, $exercice) {
            $asso = Association::first();
            return Facture::create([
                'date' => now()->toDateString(),
                'statut' => StatutFacture::Brouillon,
                'tiers_id' => $tiersId,
                'compte_bancaire_id' => $asso?->facture_compte_bancaire_id,
                'conditions_reglement' => $asso?->facture_conditions_reglement ?? 'Payable à réception',
                'mentions_legales' => $asso?->facture_mentions_legales ?? "TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé",
                'montant_total' => 0,
                'saisi_par' => auth()->id(),
                'exercice' => $exercice,
            ]);
        });
    }

    public function supprimerBrouillon(Facture $facture): void
    {
        if ($facture->statut !== StatutFacture::Brouillon) {
            throw new \RuntimeException('Seul un brouillon peut être supprimé.');
        }
        DB::transaction(function () use ($facture) {
            $facture->lignes()->delete();
            $facture->transactions()->detach();
            $facture->delete();
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceTest.php`
Expected: PASS

- [ ] **Step 5: Write failing tests for ajouterTransactions() and retirerTransaction()**

Tests:
- `ajouterTransactions()` creates pivot entries + generates facture_lignes from transaction_lignes
- `ajouterTransactions()` generates correct auto-libellé (sous-catégorie — opération — séance n)
- `ajouterTransactions()` rejects transactions not belonging to the facture's tiers
- `ajouterTransactions()` rejects transactions already facturées (linked to brouillon or validée)
- `retirerTransaction()` removes pivot + deletes corresponding facture_lignes
- `retirerTransaction()` throws on non-brouillon

- [ ] **Step 6: Implement ajouterTransactions() and retirerTransaction()**

Key logic for `ajouterTransactions()`:
- Validate all transactions are `type=recette`, `tiers_id` matches, and not already in a non-annulée facture
- Insert into `facture_transaction` pivot
- For each transaction's lignes, create a `FactureLigne` with:
  - `type = montant`
  - `libelle` = auto-generated from sous_categorie, operation, seance (resolve date via Seance model)
  - `montant` = transaction_ligne.montant
  - `transaction_ligne_id` = FK
  - `ordre` = sequential (max current + 1)

Key logic for `retirerTransaction()`:
- Verify brouillon
- Delete `facture_lignes` where `transaction_ligne_id` in the transaction's lignes
- Detach from pivot

- [ ] **Step 7: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "feat(facturation): FactureService core CRUD (creer, ajouter/retirer transactions, supprimer)"
```

---

## Task 4: FactureService — Validation & Numbering

**Files:**
- Modify: `app/Services/FactureService.php`
- Create: `tests/Feature/Services/FactureServiceValidationTest.php`

- [ ] **Step 1: Write failing tests for valider()**

Tests in `tests/Feature/Services/FactureServiceValidationTest.php`:
- `valider()` assigns sequential numero `F-{exercice}-{seq}`
- `valider()` freezes montant_total as sum of montant lignes
- `valider()` changes statut to validee
- `valider()` rejects empty facture (no montant lines)
- `valider()` rejects if exercice is closed
- `valider()` rejects if date < last validated facture date (chronological constraint)
- `valider()` correctly increments sequence (create 3 factures, verify F-2025-0001, 0002, 0003)
- `valider()` zero-pads the sequence to 4 digits

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceValidationTest.php`
Expected: FAIL

- [ ] **Step 3: Implement valider()**

```php
public function valider(Facture $facture): void
{
    if ($facture->statut !== StatutFacture::Brouillon) {
        throw new \RuntimeException('Seul un brouillon peut être validé.');
    }

    $montantLignes = $facture->lignes()
        ->where('type', TypeLigneFacture::Montant)
        ->count();
    if ($montantLignes === 0) {
        throw new \RuntimeException('La facture doit contenir au moins une ligne avec montant.');
    }

    DB::transaction(function () use ($facture) {
        // Check exercice is open inside the transaction
        $this->exerciceService->assertOuvert($facture->exercice);

        // Lock ALL factures of this exercice upfront (single lock for both
        // chronological constraint and sequential numbering)
        $exerciceFactures = Facture::where('exercice', $facture->exercice)
            ->where('statut', StatutFacture::Validee)
            ->lockForUpdate()
            ->get();

        // Chronological constraint
        $lastValidated = $exerciceFactures->sortByDesc('date')->first();
        if ($lastValidated && $facture->date->lt($lastValidated->date)) {
            throw new \RuntimeException(
                "La date doit être postérieure ou égale au {$lastValidated->date->format('d/m/Y')} (dernière facture validée {$lastValidated->numero})."
            );
        }

        // Sequential numbering (from locked set)
        $maxSeq = $exerciceFactures
            ->filter(fn ($f) => $f->numero !== null)
            ->map(fn ($f) => (int) last(explode('-', $f->numero)))
            ->max() ?? 0;

        $seq = $maxSeq + 1;
        $numero = sprintf('F-%d-%04d', $facture->exercice, $seq);

        $montantTotal = (float) $facture->lignes()
            ->where('type', TypeLigneFacture::Montant)
            ->sum('montant');

        $facture->update([
            'numero' => $numero,
            'montant_total' => $montantTotal,
            'statut' => StatutFacture::Validee,
        ]);
    });
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceValidationTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(facturation): validation facture avec numérotation séquentielle et contrainte chronologique"
```

---

## Task 5: FactureService — Line Management

**Files:**
- Modify: `app/Services/FactureService.php`
- Add tests to: `tests/Feature/Services/FactureServiceTest.php`

- [ ] **Step 1: Write failing tests for line management methods**

Tests:
- `majOrdre()` swaps ordre of two adjacent lines (up and down)
- `majOrdre()` does nothing at top/bottom boundary
- `majOrdre()` throws on non-brouillon
- `majLibelle()` updates the libellé of a line
- `majLibelle()` throws on non-brouillon
- `ajouterLigneTexte()` creates a texte line with correct ordre (max+1)
- `supprimerLigne()` deletes a texte line
- `supprimerLigne()` throws when trying to delete a montant line
- `supprimerLigne()` throws on non-brouillon

- [ ] **Step 2: Implement line management methods**

```php
public function majOrdre(Facture $facture, int $ligneId, string $direction): void
{
    $this->assertBrouillon($facture);
    $lignes = $facture->lignes()->orderBy('ordre')->get();
    $index = $lignes->search(fn ($l) => $l->id === $ligneId);
    if ($index === false) return;

    $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapIndex < 0 || $swapIndex >= $lignes->count()) return;

    $ordreA = $lignes[$index]->ordre;
    $ordreB = $lignes[$swapIndex]->ordre;
    $lignes[$index]->update(['ordre' => $ordreB]);
    $lignes[$swapIndex]->update(['ordre' => $ordreA]);
}

public function majLibelle(Facture $facture, int $ligneId, string $libelle): void
{
    $this->assertBrouillon($facture);
    $facture->lignes()->where('id', $ligneId)->update(['libelle' => $libelle]);
}

public function ajouterLigneTexte(Facture $facture, string $texte): void
{
    $this->assertBrouillon($facture);
    $maxOrdre = (int) $facture->lignes()->max('ordre');
    $facture->lignes()->create([
        'type' => TypeLigneFacture::Texte,
        'libelle' => $texte,
        'montant' => null,
        'transaction_ligne_id' => null,
        'ordre' => $maxOrdre + 1,
    ]);
}

public function supprimerLigne(Facture $facture, int $ligneId): void
{
    $this->assertBrouillon($facture);
    $ligne = $facture->lignes()->findOrFail($ligneId);
    if ($ligne->type !== TypeLigneFacture::Texte) {
        throw new \RuntimeException('Seules les lignes de texte peuvent être supprimées individuellement.');
    }
    $ligne->delete();
}

private function assertBrouillon(Facture $facture): void
{
    if ($facture->statut !== StatutFacture::Brouillon) {
        throw new \RuntimeException('Cette action n\'est possible que sur un brouillon.');
    }
}
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat(facturation): gestion des lignes (ordre, libellé, texte)"
```

---

## Task 6: TransactionService — Facture Lock Integration

**Files:**
- Modify: `app/Services/TransactionService.php`
- Create: `tests/Feature/Services/FactureServiceLockTest.php`

- [ ] **Step 1: Write failing tests for facture lock**

Tests in `tests/Feature/Services/FactureServiceLockTest.php`:
- Transaction linked to validated facture: `update()` allows changing date, libelle, notes
- Transaction linked to validated facture: `update()` blocks changing montant_total
- Transaction linked to validated facture: `update()` blocks changing nb lignes
- Transaction linked to validated facture: `update()` blocks changing ligne montant
- Transaction linked to validated facture: `update()` blocks changing ligne sous_categorie_id
- Transaction linked to validated facture: `update()` blocks changing ligne operation_id
- Transaction linked to validated facture: `delete()` throws
- Transaction linked to validated facture: `affecterLigne()` throws
- Transaction linked to validated facture: `supprimerAffectations()` throws
- Transaction linked to brouillon facture: all operations allowed

Helper function: `makeFactureLockedTransaction()` — creates a transaction linked to a validated facture.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceLockTest.php`
Expected: FAIL

- [ ] **Step 3: Add facture lock checks to TransactionService**

Modify `app/Services/TransactionService.php`:

In `update()`, after the existing `isLockedByRemise()` check, add:
```php
if ($transaction->isLockedByFacture()) {
    $this->assertLockedByFactureInvariants($transaction, $data, $lignes);
}
```

In `delete()`, add:
```php
if ($transaction->isLockedByFacture()) {
    throw new \RuntimeException('Cette transaction est liée à une facture validée et ne peut pas être supprimée.');
}
```

In `affecterLigne()`, at the beginning after loading transaction:
```php
if ($transaction->isLockedByFacture()) {
    throw new \RuntimeException('Cette transaction est liée à une facture validée. La ventilation ne peut pas être modifiée.');
}
```

In `supprimerAffectations()`, same check.

New private method:
```php
private function assertLockedByFactureInvariants(Transaction $transaction, array $data, array $lignes): void
{
    if ((int) round((float) $transaction->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
        throw new \RuntimeException('Le montant total ne peut pas être modifié sur une transaction facturée.');
    }
    $existingLignes = $transaction->lignes()->get()->keyBy('id');
    if (count($lignes) !== $existingLignes->count()) {
        throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une transaction facturée.');
    }
    foreach ($lignes as $ligneData) {
        $id = $ligneData['id'] ?? null;
        if ($id === null || !$existingLignes->has($id)) {
            throw new \RuntimeException('Ligne inconnue sur une transaction facturée.');
        }
        $existing = $existingLignes->get($id);
        if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
            throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une transaction facturée.');
        }
        if ((int) $existing->sous_categorie_id !== (int) $ligneData['sous_categorie_id']) {
            throw new \RuntimeException('La sous-catégorie ne peut pas être modifiée sur une transaction facturée.');
        }
        $existingOpId = $existing->operation_id;
        $newOpId = $ligneData['operation_id'] !== '' ? (int) $ligneData['operation_id'] : null;
        if ($existingOpId !== $newOpId) {
            throw new \RuntimeException('L\'opération ne peut pas être modifiée sur une transaction facturée.');
        }
        $existingSeance = $existing->seance;
        $newSeance = isset($ligneData['seance']) && $ligneData['seance'] !== '' ? (int) $ligneData['seance'] : null;
        if ($existingSeance !== $newSeance) {
            throw new \RuntimeException('La séance ne peut pas être modifiée sur une transaction facturée.');
        }
    }
}
```

Also update the `update()` method logic: when locked by facture, update only allowed fields on lignes (notes only), similar to the rapprochement pattern.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Services/FactureServiceLockTest.php`
Expected: PASS

- [ ] **Step 5: Run ALL existing lock tests to ensure no regression**

Run: `./vendor/bin/sail test tests/Feature/TransactionServiceLockTest.php tests/Feature/TransactionAffectationTest.php`
Expected: all PASS (no regressions on existing lock behavior)

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(facturation): intégration verrou facture dans TransactionService"
```

---

## Task 7: Paramètres Facturation

**Files:**
- Modify: `app/Livewire/Parametres/AssociationForm.php`
- Modify: `resources/views/livewire/parametres/association-form.blade.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Update Association model $fillable**

Read `app/Models/Association.php` first, then add `'siret'`, `'forme_juridique'`, `'facture_conditions_reglement'`, `'facture_mentions_legales'`, `'facture_mentions_penalites'`, `'facture_compte_bancaire_id'` to the `$fillable` array. Also add `'facture_compte_bancaire_id' => 'integer'` to `casts()`.

- [ ] **Step 2: Add facture fields to AssociationForm component**

Read `app/Livewire/Parametres/AssociationForm.php` first, then add the 6 new properties (siret, forme_juridique, + 4 facture_*) and include them in save/mount.

- [ ] **Step 3: Add Facturation tab to the Blade template**

Read the existing `association-form.blade.php` to understand the tab pattern, then add a "Facturation" tab with:
- SIRET (input text)
- Forme juridique (input text)
- Conditions de règlement (textarea)
- Mentions légales (textarea)
- Mentions pénalités B2B (textarea)
- Compte bancaire par défaut (select from non-système comptes)

- [ ] **Step 4: Update DatabaseSeeder with default facture values**

In the `association` insert, add:
- `forme_juridique` → `'Association loi 1901'`
- `facture_conditions_reglement` → `'Payable à réception'`
- `facture_mentions_legales` → `"TVA non applicable, art. 261-7-1° du CGI\nPas d'escompte pour paiement anticipé"`
- `facture_mentions_penalites` → `"En cas de retard de paiement, pénalités au taux de 3× le taux d'intérêt légal. Indemnité forfaitaire de recouvrement : 40 € (art. D441-5 C.Com)."`

- [ ] **Step 5: Test manually that the parametres screen loads and saves**

Run: `./vendor/bin/sail artisan migrate:fresh --seed`
Then open the app and verify the Facturation tab.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(facturation): onglet paramètres facturation (conditions, mentions, compte)"
```

---

## Task 8: Livewire FactureList

**Files:**
- Create: `app/Livewire/FactureList.php`
- Create: `resources/views/livewire/facture-list.blade.php`
- Create: `resources/views/gestion/factures/index.blade.php`
- Create: `tests/Feature/Livewire/FactureListTest.php`

- [ ] **Step 1: Write failing Livewire tests**

Tests:
- Renders the list page (200 status, sees Livewire component)
- Displays existing factures with correct badges (brouillon grey, validée blue, acquittée green)
- Filters by exercice
- Filters by statut
- Filters by tiers name search
- Creates a new brouillon and redirects to edit
- Click on brouillon row redirects to edit, click on validée redirects to show

- [ ] **Step 2: Implement FactureList Livewire component**

Follow the `RemiseBancaireList` pattern:
- Props: `exercice`, `filterStatut`, `filterTiers`
- Method `creer(int $tiersId)` → calls `FactureService::creer()` → redirects to `gestion.factures.edit`
- Method `supprimer(int $id)` → calls `FactureService::supprimerBrouillon()`
- Render: paginated list with columns (numéro, date, tiers, montant, réglé, statut)
- Badges: brouillon=secondary, validée=primary, acquittée=success, annulée=danger

- [ ] **Step 3: Create the Blade view**

Follow the existing list pattern with `table-dark` headers (`--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880`).
Tiers dropdown for new facture creation.

- [ ] **Step 4: Create the page wrapper view**

`resources/views/gestion/factures/index.blade.php` — extends layout, includes Livewire component.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/FactureListTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(facturation): écran FactureList (liste, filtres, création brouillon)"
```

---

## Task 9: Livewire FactureEdit

**Files:**
- Create: `app/Livewire/FactureEdit.php`
- Create: `resources/views/livewire/facture-edit.blade.php`
- Create: `resources/views/gestion/factures/edit.blade.php`
- Create: `tests/Feature/Livewire/FactureEditTest.php`

- [ ] **Step 1: Write failing Livewire tests**

Tests:
- Renders with facture data and available transactions
- Shows only recette transactions for the correct tiers, not already facturées
- `toggleTransaction()` adds/removes transactions and updates lignes
- Updates libellé on a ligne
- Moves a ligne up/down
- Adds a texte line
- Deletes a texte line
- `valider()` validates the facture and redirects to `gestion.factures.show`
- `supprimer()` deletes the brouillon and redirects to `gestion.factures`
- Redirects to `gestion.factures` if facture is not brouillon

- [ ] **Step 2: Implement FactureEdit Livewire component**

Key props: `Facture $facture` (mount via route model binding)

Key features:
- `$availableTransactions` — queried in render(): recettes du tiers, non facturées (tous exercices)
- `$selectedTransactionIds` — derived from pivot
- `toggleTransaction(int $txId)` — calls service ajouterTransactions or retirerTransaction
- `updateLibelle(int $ligneId, string $libelle)` — calls service majLibelle
- `moveUp(int $ligneId)` / `moveDown(int $ligneId)` — calls service majOrdre
- `addTexte()` — calls service ajouterLigneTexte
- `deleteTexte(int $ligneId)` — calls service supprimerLigne
- `valider()` — calls service valider, redirect to show
- `supprimer()` — calls service supprimerBrouillon, redirect to list
- `sauvegarder()` — saves brouillon metadata directly on the facture model (date, conditions_reglement, mentions_legales, compte_bancaire_id, notes). The Livewire component handles this directly (no service method needed, consistent with how AssociationForm saves).

Mount guard: redirect if not brouillon.

- [ ] **Step 3: Create the Blade view**

Two panels layout:
- **Top**: table of available transactions with checkboxes
- **Bottom**: ordered list of facture_lignes with inline libellé edit, ↑↓ buttons, delete for texte
- Metadata form (date, conditions, mentions, compte bancaire)
- Action buttons (Enregistrer, Valider, Supprimer)

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/FactureEditTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(facturation): écran FactureEdit (sélection transactions, édition lignes, validation)"
```

---

## Task 10: Livewire FactureShow

**Files:**
- Create: `app/Livewire/FactureShow.php`
- Create: `resources/views/livewire/facture-show.blade.php`
- Create: `resources/views/gestion/factures/show.blade.php`
- Create: `tests/Feature/Livewire/FactureShowTest.php`

- [ ] **Step 1: Write failing Livewire tests**

Tests:
- Renders with all facture data (numero, date, tiers, lignes, total)
- Shows montant réglé calculated dynamically
- Shows badge "Acquittée" when fully paid
- Shows PDF download link
- Redirects to edit if facture is brouillon

- [ ] **Step 2: Implement FactureShow**

Simple read-only component displaying all facture info.
- Lignes ordered by `ordre`
- Texte lines displayed in bold without montant
- Montant réglé via `$facture->montantRegle()`
- Badge logic: acquittée (green) if `isAcquittee()`
- Download PDF button linking to `route('gestion.factures.pdf', $facture)`

**Important:** The page wrapper Blade views (`gestion/factures/edit.blade.php` and `gestion/factures/show.blade.php`) must pass the `$facture` to the Livewire component: `<livewire:facture-edit :facture="$facture" />` and `<livewire:facture-show :facture="$facture" />`. All route references across Livewire components must use the `gestion.` prefix (routes are inside the `gestion.` named group).

- [ ] **Step 3: Create the Blade views**

Read-only table of lines, metadata section, action buttons.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/sail test tests/Feature/Livewire/FactureShowTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(facturation): écran FactureShow (consultation lecture seule)"
```

---

## Task 11: PDF Blade Template

**Files:**
- Create: `resources/views/pdf/facture.blade.php`

- [ ] **Step 1: Create the invoice PDF Blade template**

Follow the pattern of `resources/views/pdf/remise-bancaire.blade.php` and `resources/views/pdf/attestation-presence.blade.php`.

Structure:
- **Header**: logo (base64), association name + forme_juridique, address, SIRET, email, phone
- **Title**: "FACTURE" + numéro + date
- **Client block**: tiers name/entreprise, address (adresse_ligne1, code_postal, ville)
- **Lines table**: Désignation | Montant (€). Texte lines in bold spanning full width. Montant lines right-aligned.
- **Total row**: bold, right-aligned
- **Acquittée stamp**: if applicable, "ACQUITTÉE" mention
- **Bank details**: IBAN, BIC, domiciliation (if compte_bancaire_id set)
- **Footer**: conditions de règlement, mentions légales, mentions pénalités (conditional on `tiers.type !== 'particulier'`)

Use inline CSS (dompdf requirement). A4 portrait.

- [ ] **Step 2: Commit**

```bash
git add -A && git commit -m "feat(facturation): template PDF facture Blade"
```

---

## Task 12: Factur-X Integration + PDF Controller

**Files:**
- Create: `app/Http/Controllers/FacturePdfController.php`
- Create: `tests/Feature/FacturePdfTest.php`

- [ ] **Step 1: Install atgp/factur-x**

Run: `./vendor/bin/sail composer require atgp/factur-x`

Verify installation: `./vendor/bin/sail php -r "echo class_exists('\Atgp\FacturX\Facturx') ? 'OK' : 'FAIL';"`

- [ ] **Step 2: Add genererPdf() to FactureService**

```php
public function genererPdf(Facture $facture): string
{
    $facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions']);
    $association = Association::first();

    // Step 1: generate visual PDF via dompdf
    $headerLogoBase64 = null;
    $headerLogoMime = null;
    if ($association?->logo_path) {
        $logoContent = Storage::disk('public')->get($association->logo_path);
        if ($logoContent) {
            $ext = pathinfo($association->logo_path, PATHINFO_EXTENSION);
            $headerLogoMime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
            $headerLogoBase64 = base64_encode($logoContent);
        }
    }

    $pdf = Pdf::loadView('pdf.facture', [
        'facture' => $facture,
        'association' => $association,
        'headerLogoBase64' => $headerLogoBase64,
        'headerLogoMime' => $headerLogoMime,
        'montantRegle' => $facture->montantRegle(),
        'isAcquittee' => $facture->isAcquittee(),
    ])->setPaper('a4', 'portrait');

    $pdfContent = $pdf->output();

    // Step 2: generate Factur-X XML (profile MINIMUM)
    $xml = $this->genererFacturXml($facture, $association);

    // Step 3: embed XML into PDF via atgp/factur-x → PDF/A-3
    $facturx = new \Atgp\FacturX\Facturx();
    return $facturx->generateFacturX($pdfContent, $xml, \Atgp\FacturX\Facturx::PROFIL_MINIMUM);
}
```

- [ ] **Step 3: Implement genererFacturXml() private method**

Generate Factur-X MINIMUM profile XML with fields:
- BT-1: numero, BT-2: date, BT-3: 380, BT-5: EUR
- BT-27: association name, BT-30: SIRET
- BT-44: tiers name
- BT-109/112: montant_total, BT-115: montant dû

Follow the Factur-X MINIMUM XSD schema. The XML is a CrossIndustryInvoice document.

- [ ] **Step 4: Create FacturePdfController**

```php
// app/Http/Controllers/FacturePdfController.php
<?php
declare(strict_types=1);
namespace App\Http\Controllers;

use App\Models\Facture;
use App\Services\FactureService;
use Illuminate\Http\Response;

final class FacturePdfController extends Controller
{
    public function __invoke(Facture $facture, FactureService $service): Response
    {
        $pdfContent = $service->genererPdf($facture);
        $filename = "Facture {$facture->numero} - {$facture->tiers->displayName()}.pdf";

        $inline = request()->query('mode') === 'inline';
        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . "; filename=\"{$filename}\"",
        ]);
    }
}
```

- [ ] **Step 5: Write tests**

Tests in `tests/Feature/FacturePdfTest.php`:
- PDF route returns 200 with content-type application/pdf for a validated facture
- PDF contains the facture numero in the response

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/sail test tests/Feature/FacturePdfTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat(facturation): génération PDF Factur-X (dompdf + atgp/factur-x PDF/A-3)"
```

---

## Task 13: Routes, Navigation, Seeder

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Add routes**

In `routes/web.php`, in the gestion group (after remises-bancaires routes):

```php
Route::view('/factures', 'gestion.factures.index')->name('factures');
Route::get('/factures/{facture}/edit', function (Facture $facture) {
    return view('gestion.factures.edit', compact('facture'));
})->name('factures.edit');
Route::get('/factures/{facture}', function (Facture $facture) {
    return view('gestion.factures.show', compact('facture'));
})->name('factures.show');
Route::get('/factures/{facture}/pdf', FacturePdfController::class)
    ->name('factures.pdf');
```

Add the necessary imports at the top of routes/web.php:
```php
use App\Http\Controllers\FacturePdfController;
use App\Models\Facture;
```

- [ ] **Step 2: Add navigation item**

In `resources/views/layouts/app.blade.php`, after the "Remises en banque" nav item, add:

```html
{{-- Factures --}}
<li class="nav-item">
    <a class="nav-link {{ request()->routeIs('gestion.factures*') ? 'active' : '' }}"
       href="{{ route('gestion.factures') }}">
        <i class="bi bi-receipt"></i> Factures
    </a>
</li>
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL tests pass (no regressions)

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat(facturation): routes, navigation, intégration complète V1"
```

---

## Task 14: Integration Testing & Polish

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: ALL pass

- [ ] **Step 2: Run pint for code style**

Run: `./vendor/bin/sail php vendor/bin/pint`

- [ ] **Step 3: Manual smoke test**

1. `./vendor/bin/sail artisan migrate:fresh --seed`
2. Login as admin@svs.fr
3. Create a few recette transactions for a tiers
4. Go to Factures → New → select tiers
5. Select transactions → verify lignes generated with auto-libellé
6. Add a texte line, reorder, modify libellé
7. Validate → verify numero assigned, status changes
8. Download PDF → verify Factur-X content
9. Verify transaction is locked (try editing from transactions list)

- [ ] **Step 4: Final commit if any polish needed**

```bash
git add -A && git commit -m "style: pint formatting + polish facturation V1"
```
