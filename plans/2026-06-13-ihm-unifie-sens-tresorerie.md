# IHM unifié recette/dépense piloté par sensTresorerie — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Piloter l'affichage IHM par le sens de trésorerie réel (`sensTresorerie()`) au lieu du type comptable (`$type`), afin que les extournes (et à terme les OD) affichent le bon wording et le bon routage.

**Architecture:** Trois phases incrémentales : (1) nettoyage IHM — PJ généralisées + suppression filtre tiers + rename `$sensLabel` → `$sensTresorerie`, (2) migration des branchements blade de `$type` vers `$sensTresorerie` dans TransactionForm, (3) ajout d'un scope SQL dérivant le sens depuis les lignes D/C du compte 512x et migration de `RemiseBancaireSelection` + badges liste. Chaque phase produit un code fonctionnel et testable. Les verrouillages (6 axes) et la ventilation ne sont pas touchés — ils sont orthogonaux au sens.

**Tech Stack:** PHP 8.x, Laravel 11, Livewire 4, Pest PHP, MySQL/SQLite :memory:

**Rappels sécurité :** Cast `(int)` obligatoire des deux côtés dans `===` PK/FK. NEVER `migrate:fresh`. Tests sur SQLite `:memory:` via `phpunit.xml`.

**Convention :** la sous-catégorie autocomplete (6xx/7xx) et la pose du `statut_reglement` à la création restent pilotées par `$type` — c'est de la logique comptable, pas de l'affichage.

---

## Phase 1 — Nettoyage IHM (PJ + tiers + rename)

### Task 1 : Généraliser les PJ à toutes les transactions (plus de condition `$type === 'depense'`)

**Files:**
- Modify: `app/Livewire/TransactionForm.php:583,693,701,709`
- Modify: `resources/views/livewire/transaction-form.blade.php:237,254`
- Test: `tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php`

- [ ] **Step 1: RED — test qu'une recette accepte une PJ au save**

Créer `tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    // Sous-catégorie de type recette (classe 7xx)
    $catRecette = \App\Models\Categorie::factory()->create([
        'association_id' => $this->association->id,
        'type' => 'recette',
    ]);
    $this->scRecette = SousCategorie::factory()->create([
        'categorie_id' => $catRecette->id,
        'association_id' => $this->association->id,
    ]);
});

afterEach(fn () => TenantContext::clear());

test('une recette accepte une PJ header au save', function () {
    Storage::fake('local');

    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette')
        ->set('date', '2025-10-15')
        ->set('mode_paiement', 'cheque')
        ->set('compte_id', $this->compte->id)
        ->set('lignes.0.sous_categorie_id', (string) $this->scRecette->id)
        ->set('lignes.0.montant', '100.00')
        ->set('pieceJointe', UploadedFile::fake()->create('justificatif.pdf', 100, 'application/pdf'))
        ->call('save');

    $component->assertHasNoErrors();
    $tx = Transaction::latest()->first();
    expect($tx->piece_jointe_path)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php --filter="recette accepte une PJ"
```

Expected: FAIL — la PJ n'est pas sauvegardée car `$this->type === 'depense'` est faux pour une recette.

- [ ] **Step 3: GREEN — supprimer les conditions `$type === 'depense'` sur les PJ**

Dans `app/Livewire/TransactionForm.php` :

**L583** — Validation PJ : remplacer `if ($this->pieceJointe !== null && $this->type === 'depense')` par `if ($this->pieceJointe !== null)`.

**L693** — Save PJ header : remplacer `if ($this->pieceJointe !== null && $this->type === 'depense')` par `if ($this->pieceJointe !== null)`.

**L701** — Save IncomingDoc : remplacer `if ($this->incomingDocumentId !== null && $this->type === 'depense')` par `if ($this->incomingDocumentId !== null)`.

**L709** — Save FactureDeposee : remplacer `if ($this->factureDeposeeId !== null && $this->type === 'depense')` par `if ($this->factureDeposeeId !== null)`.

Dans `resources/views/livewire/transaction-form.blade.php` :

**L237** — bloc PJ OCR : remplacer `@if ($ocrMode && $type === 'depense' && ! $exerciceCloture)` par `@if ($ocrMode && ! $exerciceCloture)`.

**L254** — bloc PJ standard : remplacer `@if ($type === 'depense' && ! $exerciceCloture && ! $ocrMode)` par `@if (! $exerciceCloture && ! $ocrMode)`.

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php
```

- [ ] **Step 5: Run full suite to verify no regression**

```bash
./vendor/bin/pest --stop-on-failure
```

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TransactionForm.php resources/views/livewire/transaction-form.blade.php tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php
git commit -m "feat(ihm): généraliser PJ à toutes les transactions (pas juste dépenses)"
```

---

### Task 2 : Supprimer le filtre tiers dans TransactionForm

**Files:**
- Modify: `resources/views/livewire/transaction-form.blade.php:166`
- Test: `tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php` (ajouter un test)

- [ ] **Step 1: RED — test qu'un tiers `pour_depenses=true, pour_recettes=false` apparaît en saisie recette**

Ajouter dans `tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php` :

```php
test('le filtre tiers ne restreint plus par type — un tiers depenses-only est proposé en saisie recette', function () {
    $tiers = \App\Models\Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Fournisseur Unique',
        'pour_depenses' => true,
        'pour_recettes' => false,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette');

    // Le composant TiersAutocomplete intégré ne doit PAS filtrer par type.
    // On vérifie que le blade ne passe plus filtre="recettes" au sous-composant.
    $html = $component->html();
    // L'attribut filtre="" (tous) ou pas de filtre — pas filtre="recettes"
    expect($html)->not->toContain('filtre="recettes"');
    expect($html)->not->toContain('filtre="depenses"');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php --filter="filtre tiers"
```

Expected: FAIL — le blade contient `filtre="recettes"`.

- [ ] **Step 3: GREEN — supprimer le filtre tiers**

Dans `resources/views/livewire/transaction-form.blade.php` **L166**, remplacer :

```blade
<livewire:tiers-autocomplete wire:model="tiers_id" filtre="{{ $type === 'depense' ? 'depenses' : 'recettes' }}" :defaultSearch="$ocrTiersNom ?? ''" :key="'transaction-tiers-'.($transactionId ?? 'new').'-'.($tiers_id ?? '0').'-'.($ocrTiersNom ?? '')" />
```

par :

```blade
<livewire:tiers-autocomplete wire:model="tiers_id" filtre="tous" :defaultSearch="$ocrTiersNom ?? ''" :key="'transaction-tiers-'.($transactionId ?? 'new').'-'.($tiers_id ?? '0').'-'.($ocrTiersNom ?? '')" />
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php --filter="filtre tiers"
```

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/pest --stop-on-failure
```

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/transaction-form.blade.php tests/Feature/Livewire/TransactionFormPjGeneraliseeTest.php
git commit -m "feat(ihm): supprimer filtre tiers par type dans TransactionForm — tous les tiers proposés"
```

---

## Phase 2 — Rename `$sensLabel` → `$sensTresorerie` et migration branchements blade

### Task 3 : Rename `$sensLabel` → `$sensTresorerie` dans TransactionForm

**Files:**
- Modify: `app/Livewire/TransactionForm.php:94,158,481-483,492`
- Modify: `resources/views/livewire/transaction-form.blade.php:19,23,97,173,298`

- [ ] **Step 1: RED — test que la propriété `$sensTresorerie` est bien posée**

Créer `tests/Feature/Livewire/TransactionFormSensTresorerieTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
});

afterEach(fn () => TenantContext::clear());

test('showNewForm recette pose sensTresorerie = recette', function () {
    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette');

    expect($component->get('sensTresorerie'))->toBe('recette');
});

test('showNewForm depense pose sensTresorerie = depense', function () {
    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense');

    expect($component->get('sensTresorerie'))->toBe('depense');
});

test('edit recette normale pose sensTresorerie = recette', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    expect($component->get('sensTresorerie'))->toBe('recette');
    expect($component->get('type'))->toBe('recette');
});

test('edit miroir extourne de recette pose sensTresorerie = depense', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    expect($component->get('sensTresorerie'))->toBe('depense');
    expect($component->get('type'))->toBe('recette');
    expect($component->get('isExtourneMiroir'))->toBeTrue();
});

test('edit miroir extourne de depense pose sensTresorerie = recette', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    expect($component->get('sensTresorerie'))->toBe('recette');
    expect($component->get('type'))->toBe('depense');
    expect($component->get('isExtourneMiroir'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionFormSensTresorerieTest.php
```

Expected: FAIL — propriété `sensTresorerie` n'existe pas (encore nommée `sensLabel`).

- [ ] **Step 3: GREEN — renommer `$sensLabel` → `$sensTresorerie`**

Dans `app/Livewire/TransactionForm.php` :

**L94** — Déclaration propriété : renommer `public string $sensLabel = '';` → `public string $sensTresorerie = '';`. Mettre à jour le docblock (L89-93) pour refléter le nouveau nom.

**L158** — `showNewForm()` : renommer `$this->sensLabel = $type;` → `$this->sensTresorerie = $type;`.

**L481-483** — `edit()` : renommer les 3 occurrences de `$this->sensLabel` → `$this->sensTresorerie`.

**L492** — `resetForm()` : dans le tableau passé à `$this->reset([...])`, remplacer `'sensLabel'` par `'sensTresorerie'`.

Dans `resources/views/livewire/transaction-form.blade.php` — remplacer toutes les occurrences de `$sensLabel` par `$sensTresorerie` (5 occurrences : L19, L23, L97, L173, L298).

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionFormSensTresorerieTest.php
```

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/pest --stop-on-failure
```

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TransactionForm.php resources/views/livewire/transaction-form.blade.php tests/Feature/Livewire/TransactionFormSensTresorerieTest.php
git commit -m "refactor(ihm): rename sensLabel → sensTresorerie dans TransactionForm"
```

---

### Task 4 : Le badge dans la blade affiche le bon sens pour les extournes

**Files:**
- Modify: aucun (déjà migré sur `$sensLabel` → maintenant `$sensTresorerie`)
- Test: `tests/Feature/Livewire/TransactionFormSensTresorerieTest.php` (ajouter)

- [ ] **Step 1: Test blade — le badge et le titre reflètent le sens de trésorerie**

Ajouter dans `tests/Feature/Livewire/TransactionFormSensTresorerieTest.php` :

```php
test('blade affiche badge Dépense pour miroir extourne de recette', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    $component->assertSee('Dépense')
        ->assertSee('Remboursement (extourne)')
        ->assertDontSee('badge bg-success');
});

test('blade affiche badge Recette pour miroir extourne de dépense', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    $component->assertSee('Recette')
        ->assertSee('Remboursement (extourne)');
});

test('blade affiche "Paiement effectué ?" pour miroir extourne de recette (sens=depense)', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->assertSee('Paiement effectué');
});

test('blade affiche "Paiement déjà reçu ?" pour miroir extourne de dépense (sens=recette)', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->assertSee('Paiement déjà reçu');
});
```

- [ ] **Step 2: Run tests — should all pass (blade already uses `$sensTresorerie`)**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionFormSensTresorerieTest.php
```

Expected: PASS — le badge, le titre et le label paiement utilisent déjà `$sensTresorerie`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/TransactionFormSensTresorerieTest.php
git commit -m "test(ihm): vérifier badges et labels pilotés par sensTresorerie dans TransactionForm"
```

---

## Phase 3 — Scope SQL sens trésorerie et migration liste + remise

### Task 5 : Scope Eloquent `scopeSensTresorerieSql()` sur Transaction

Ce scope ajoute une colonne virtuelle `sens_tresorerie_sql` au query builder, calculée depuis les lignes D/C du compte de trésorerie (512x). Pas de colonne dénormalisée — la vérité est dans les écritures PD.

**Files:**
- Modify: `app/Models/Transaction.php`
- Test: `tests/Feature/Models/TransactionSensTresorerieSqlTest.php`

- [ ] **Step 1: RED — test du scope sur recette/dépense normales et extournes**

Créer `tests/Feature/Models/TransactionSensTresorerieSqlTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\LettrageService;
use App\Tenant\TenantContext;
use DateTimeImmutable;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $this->association->id]);

    $this->compte512 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '512_BQ',
        'classe' => 5,
        'nom' => 'Banque Principale',
    ]);
    $this->compteBancaire = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
    ]);
    $this->compte411 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '411',
        'classe' => 4,
        'nom' => 'Clients',
    ]);
    $this->compte706 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706',
        'classe' => 7,
        'nom' => 'Prestations',
    ]);
    $this->compte401 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '401',
        'classe' => 4,
        'nom' => 'Fournisseurs',
    ]);
    $this->compte606 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '606',
        'classe' => 6,
        'nom' => 'Achats',
    ]);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

afterEach(fn () => TenantContext::clear());

test('sensTresorerieSql retourne recette pour une recette comptant avec D 512x', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Vente,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 100.00,
    ]);
    // Ligne 512x au débit = entrée d'argent
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 100.00,
        'credit' => 0,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('recette');
});

test('sensTresorerieSql retourne depense pour une dépense comptant avec C 512x', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'journal' => JournalComptable::Achat,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 200.00,
    ]);
    // Ligne 512x au crédit = sortie d'argent
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 0,
        'credit' => 200.00,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('depense');
});

test('sensTresorerieSql retourne depense pour miroir extourne de recette (C 512x)', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'journal' => JournalComptable::Vente,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 100.00,
    ]);
    // Miroir : 512x au crédit (argent sort = dépense)
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 0,
        'credit' => 100.00,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('depense');
});

test('sensTresorerieSql retourne recette pour miroir extourne de dépense (D 512x)', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'journal' => JournalComptable::Achat,
        'equilibree' => true,
        'compte_id' => $this->compteBancaire->id,
        'montant_total' => 200.00,
    ]);
    // Miroir : 512x au débit (argent entre = recette)
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 200.00,
        'credit' => 0,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('recette');
});

test('sensTresorerieSql fallback sur type pour tx sans lignes PD', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'equilibree' => false,
    ]);
    // Pas de ligne 512x → fallback sur type

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('recette');
});

test('sensTresorerieSql fallback depense pour tx legacy sans lignes PD', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'equilibree' => false,
    ]);

    $result = Transaction::sensTresorerieSql()->find($tx->id);
    expect($result->sens_tresorerie_sql)->toBe('depense');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Models/TransactionSensTresorerieSqlTest.php
```

Expected: FAIL — scope `sensTresorerieSql` n'existe pas.

- [ ] **Step 3: GREEN — implémenter le scope**

Dans `app/Models/Transaction.php`, ajouter après le scope `scopeOperationnel` (L105-111) :

```php
/**
 * Ajoute une colonne virtuelle `sens_tresorerie_sql` dérivée des lignes D/C
 * du compte de trésorerie (classe 5, numero_pcg LIKE '512_%').
 *
 * Si la transaction a des lignes PD sur un 512x :
 *   SUM(debit) > SUM(credit) → 'recette' (argent entre)
 *   SUM(credit) > SUM(debit) → 'depense' (argent sort)
 * Sinon fallback sur la colonne `type` (legacy sans PD).
 */
public function scopeSensTresorerieSql(Builder $query): Builder
{
    return $query->addSelect(DB::raw("
        COALESCE(
            (SELECT CASE
                WHEN SUM(tl_st.debit) > SUM(tl_st.credit) THEN 'recette'
                WHEN SUM(tl_st.credit) > SUM(tl_st.debit) THEN 'depense'
                ELSE NULL
            END
            FROM transaction_lignes tl_st
            INNER JOIN comptes c_st ON c_st.id = tl_st.compte_id
            WHERE tl_st.transaction_id = transactions.id
              AND c_st.classe = 5
              AND c_st.numero_pcg LIKE '512\\_%'),
            transactions.type
        ) AS sens_tresorerie_sql
    "));
}
```

Ajouter `use Illuminate\Support\Facades\DB;` en tête du fichier si pas déjà importé.

**Note MySQL :** le `LIKE '512\_%'` utilise le backslash comme escape par défaut en MySQL. En SQLite (tests), c'est identique. Le `_` est un wildcard dans LIKE, d'où l'échappement.

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Models/TransactionSensTresorerieSqlTest.php
```

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/pest --stop-on-failure
```

- [ ] **Step 6: Commit**

```bash
git add app/Models/Transaction.php tests/Feature/Models/TransactionSensTresorerieSqlTest.php
git commit -m "feat(compta): scope sensTresorerieSql — sens dérivé des lignes D/C 512x"
```

---

### Task 6 : Migrer `RemiseBancaireSelection::buildBaseQuery()` vers le sens D/C

**Files:**
- Modify: `app/Livewire/RemiseBancaireSelection.php:143-158`
- Test: `tests/Feature/Livewire/RemiseBancaireSelectionTest.php` (ajouter)

- [ ] **Step 1: RED — test qu'un T2 de remboursement de dépense (type=depense, sens=recette) apparaît dans la remise**

Ajouter à la fin de `tests/Feature/Livewire/RemiseBancaireSelectionTest.php` :

```php
test('un T2 de remboursement de dépense (type=depense, sens=recette via D 512x) apparaît dans la remise', function () {
    $compte512 = \App\Models\Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '512_BQ',
        'classe' => 5,
        'nom' => 'Banque',
    ]);
    $tiers = \App\Models\Tiers::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Remboursé',
        'prenom' => 'Pierre',
    ]);

    // Transaction de type dépense mais avec sens trésorerie = recette
    // (c'est un T2 d'encaissement de remboursement fournisseur)
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => \App\Enums\ModePaiement::Cheque,
        'montant_total' => 50.00,
        'statut_reglement' => StatutReglement::EnMain,
        'tiers_id' => $tiers->id,
        'remise_id' => null,
        'type_ecriture' => 'extourne',
        'journal' => \App\Enums\JournalComptable::Banque,
        'equilibree' => true,
        'extournee_at' => null,
    ]);
    // Ligne 512x au débit = argent entre = sens recette
    \App\Models\TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte512->id,
        'debit' => 50.00,
        'credit' => 0,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertSee('Pierre REMBOURSÉ');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Livewire/RemiseBancaireSelectionTest.php --filter="remboursement de dépense"
```

Expected: FAIL — le `where('type', Recette)` exclut cette dépense.

- [ ] **Step 3: GREEN — remplacer le filtre `type` par la sous-requête D/C dans `buildBaseQuery()`**

Dans `app/Livewire/RemiseBancaireSelection.php`, remplacer la méthode `buildBaseQuery()` (L143-158) :

```php
private function buildBaseQuery(): Builder
{
    return Transaction::query()
        ->operationnel()
        ->where('mode_paiement', $this->remise->mode_paiement->value)
        ->whereNull('extournee_at')
        ->where('type_ecriture', '!=', 'extourne')
        ->whereIn('statut_reglement', [
            StatutReglement::EnMain->value,
            StatutReglement::Recu->value,
        ])
        ->where(function ($q): void {
            $q->whereNull('remise_id')
                ->orWhere('remise_id', $this->remise->id);
        })
        // Sens trésorerie = recette (argent entre) : filtre par lignes D/C 512x
        // au lieu de type=recette, pour inclure les T2 de remboursement de dépense.
        ->where(function ($q): void {
            $q->whereExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('transaction_lignes as tl_r')
                    ->join('comptes as c_r', 'c_r.id', '=', 'tl_r.compte_id')
                    ->whereColumn('tl_r.transaction_id', 'transactions.id')
                    ->where('c_r.classe', 5)
                    ->where('c_r.numero_pcg', 'LIKE', '512\_%')
                    ->havingRaw('SUM(tl_r.debit) > SUM(tl_r.credit)');
            })
            // Fallback legacy : tx sans PD (pas de ligne 512x) mais type=recette
            ->orWhere(function ($q): void {
                $q->where('type', TypeTransaction::Recette->value)
                    ->whereNotExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('transaction_lignes as tl_f')
                            ->join('comptes as c_f', 'c_f.id', '=', 'tl_f.compte_id')
                            ->whereColumn('tl_f.transaction_id', 'transactions.id')
                            ->where('c_f.classe', 5)
                            ->where('c_f.numero_pcg', 'LIKE', '512\_%');
                    });
            });
        });
}
```

Ajouter `use Illuminate\Support\Facades\DB;` en tête du fichier si pas déjà importé.

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Livewire/RemiseBancaireSelectionTest.php --filter="remboursement de dépense"
```

- [ ] **Step 5: Run full suite (tous les tests remise)**

```bash
./vendor/bin/pest tests/Feature/Livewire/RemiseBancaireSelectionTest.php
```

Puis :

```bash
./vendor/bin/pest --stop-on-failure
```

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/RemiseBancaireSelection.php tests/Feature/Livewire/RemiseBancaireSelectionTest.php
git commit -m "fix(remise): filtrer par sens trésorerie D/C au lieu de type — inclut T2 remboursement"
```

---

### Task 7 : Badges dans TransactionUniverselle (liste) pilotés par le sens de trésorerie

**Files:**
- Modify: `app/Services/TransactionUniverselleService.php:169,234`
- Modify: `resources/views/livewire/transaction-universelle.blade.php:489-495`
- Test: `tests/Feature/Livewire/TransactionUniverselleSensBadgeTest.php`

- [ ] **Step 1: RED — test que le badge d'un miroir d'extourne de recette est DÉP (rouge)**

Créer `tests/Feature/Livewire/TransactionUniverselleSensBadgeTest.php` :

```php
<?php

declare(strict_types=1);

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\TransactionUniverselle;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compteBancaire = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->compte512 = Compte::factory()->create([
        'association_id' => $this->association->id,
        'numero_pcg' => '512_BQ',
        'classe' => 5,
        'nom' => 'Banque',
    ]);
});

afterEach(fn () => TenantContext::clear());

test('badge recette normale est REC vert', function () {
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'journal' => JournalComptable::Vente,
        'date' => '2025-10-15',
    ]);

    Livewire::test(TransactionUniverselle::class)
        ->set('filterDateDebut', '2025-09-01')
        ->set('filterDateFin', '2026-08-31')
        ->assertSeeHtml('text-bg-success')
        ->assertSee('REC');
});

test('badge miroir extourne de recette est DÉP rouge (sens=dépense)', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'type_ecriture' => 'extourne',
        'journal' => JournalComptable::Vente,
        'equilibree' => true,
        'date' => '2025-10-15',
    ]);
    // Ligne 512x au crédit (argent sort = sens dépense)
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'compte_id' => $this->compte512->id,
        'debit' => 0,
        'credit' => 100.00,
    ]);
    // Marquer comme miroir d'extourne
    \App\Models\Extourne::create([
        'transaction_origine_id' => $tx->id,
        'transaction_extourne_id' => $tx->id,
        'motif' => 'test',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(TransactionUniverselle::class)
        ->set('filterDateDebut', '2025-09-01')
        ->set('filterDateFin', '2026-08-31')
        ->assertSee('DÉP');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionUniverselleSensBadgeTest.php --filter="miroir extourne"
```

Expected: FAIL — le badge montre REC (basé sur `source_type`) au lieu de DÉP.

- [ ] **Step 3: GREEN — ajouter `sens_tresorerie` dans les branches SQL et adapter le badge blade**

Dans `app/Services/TransactionUniverselleService.php` :

**Dans `brancheDepense()` (L167-218)**, ajouter au `selectRaw` après la ligne `tx.reglement_id,` (avant le EXISTS `is_locked_by_facture`) :

```sql
COALESCE(
    (SELECT CASE
        WHEN SUM(tl_st.debit) > SUM(tl_st.credit) THEN 'recette'
        WHEN SUM(tl_st.credit) > SUM(tl_st.debit) THEN 'depense'
        ELSE NULL
    END
    FROM transaction_lignes tl_st
    INNER JOIN comptes c_st ON c_st.id = tl_st.compte_id
    WHERE tl_st.transaction_id = tx.id
      AND c_st.classe = 5
      AND c_st.numero_pcg LIKE '512\\_%'),
    'depense'
) AS sens_tresorerie,
```

**Dans `brancheRecette()` (L232-283)**, ajouter la même sous-requête mais avec fallback `'recette'` :

```sql
COALESCE(
    (SELECT CASE
        WHEN SUM(tl_st.debit) > SUM(tl_st.credit) THEN 'recette'
        WHEN SUM(tl_st.credit) > SUM(tl_st.debit) THEN 'depense'
        ELSE NULL
    END
    FROM transaction_lignes tl_st
    INNER JOIN comptes c_st ON c_st.id = tl_st.compte_id
    WHERE tl_st.transaction_id = tx.id
      AND c_st.classe = 5
      AND c_st.numero_pcg LIKE '512\\_%'),
    'recette'
) AS sens_tresorerie,
```

**Dans les branches virement** (`brancheVirementSortant` L295, `brancheVirementEntrant` L341), ajouter au selectRaw :

```sql
'virement' AS sens_tresorerie,
```

**Ajouter `'sens_tresorerie'`** au tableau `$allowedColumns` dans le `selectColumns` en haut de la classe (L42) si applicable, ou dans le `sortBy` allow-list.

**Dans `resources/views/livewire/transaction-universelle.blade.php`**, remplacer le bloc badge (L489-495) :

De :
```blade
[$badgeClass, $badgeLabel] = match($tx->source_type) {
    'depense'              => ['danger',    'DÉP'],
    'recette'              => ['success',   'REC'],
    'virement_sortant',
    'virement_entrant'     => ['warning',   'VIR'],
    default                => ['secondary', '?'],
};
```

Par :
```blade
[$badgeClass, $badgeLabel] = match($tx->sens_tresorerie ?? $tx->source_type) {
    'depense'              => ['danger',    'DÉP'],
    'recette'              => ['success',   'REC'],
    'virement',
    'virement_sortant',
    'virement_entrant'     => ['warning',   'VIR'],
    default                => ['secondary', '?'],
};
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Livewire/TransactionUniverselleSensBadgeTest.php
```

- [ ] **Step 5: Run full suite**

```bash
./vendor/bin/pest --stop-on-failure
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/TransactionUniverselleService.php resources/views/livewire/transaction-universelle.blade.php tests/Feature/Livewire/TransactionUniverselleSensBadgeTest.php
git commit -m "feat(ihm): badges liste pilotés par sens trésorerie D/C au lieu de source_type"
```

---

### Task 8 : Migrer les `$sensTreso` locaux dans la blade liste vers `$tx->sens_tresorerie`

Maintenant que la colonne `sens_tresorerie` est dans le SQL, on peut simplifier les calculs locaux dans la blade de `transaction-universelle.blade.php`.

**Files:**
- Modify: `resources/views/livewire/transaction-universelle.blade.php:587-590,653-655`
- Test: tests existants (pas de nouveau test — refactoring interne)

- [ ] **Step 1: Remplacer les calculs `$sensTreso` manuels par `$tx->sens_tresorerie`**

Dans `resources/views/livewire/transaction-universelle.blade.php` :

**L586-590** (bloc badge statut), remplacer :

```blade
// sensTresorerie: les miroirs d'extourne inversent la direction naturelle
$sensTresorerie = $isExtourneMiroir
    ? ($tx->source_type === 'recette' ? 'depense' : 'recette')
    : $tx->source_type;
$isDepense = $sensTresorerie === 'depense';
```

Par :

```blade
$isDepense = ($tx->sens_tresorerie ?? $tx->source_type) === 'depense';
```

**L651-655** (bloc bouton marquer reçu/payé), remplacer :

```blade
// $sensTresorerie est déjà calculé dans le bloc badge ci-dessus (même scope @foreach).
// Si le badge a été sauté (isExtourneOrigine), on recalcule ici pour sécurité.
$sensTreso = $isExtourneMiroir
    ? ($tx->source_type === 'recette' ? 'depense' : 'recette')
    : $tx->source_type;
```

Par :

```blade
$sensTreso = $tx->sens_tresorerie ?? $tx->source_type;
```

- [ ] **Step 2: Run full suite**

```bash
./vendor/bin/pest --stop-on-failure
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/transaction-universelle.blade.php
git commit -m "refactor(ihm): simplifier calcul sensTreso dans liste — lire depuis SQL au lieu de recalculer"
```

---

## Récapitulatif

| Task | Description | Phase |
|------|-------------|-------|
| 1 | PJ généralisées à toutes les transactions | Phase 1 |
| 2 | Suppression filtre tiers dans TransactionForm | Phase 1 |
| 3 | Rename `$sensLabel` → `$sensTresorerie` | Phase 2 |
| 4 | Tests blade badges/labels pilotés par `$sensTresorerie` | Phase 2 |
| 5 | Scope SQL `sensTresorerieSql()` (D/C 512x) | Phase 3 |
| 6 | Migration `RemiseBancaireSelection` | Phase 3 |
| 7 | Badges liste pilotés par sens SQL | Phase 3 |
| 8 | Simplifier `$sensTreso` locaux dans blade liste | Phase 3 |

**Hors scope (Phase ultérieure documentée mais pas implémentée ici) :**
- Dashboard : `where('type', 'recette'/'depense')` — à migrer quand on voudra des totaux entrées/sorties réelles vs type comptable (décision métier requise)
- CompteResultatBuilder / BudgetService : classent par nature comptable (6xx/7xx), pas par sens de trésorerie — **ne pas migrer**
- CompteBancaire::depenses()/recettes() et RapprochementBancaire idem — à migrer si les soldes doivent refléter les remboursements
