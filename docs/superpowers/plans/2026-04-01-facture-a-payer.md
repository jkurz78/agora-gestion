# Facture "à payer" — Créances à recevoir — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre de facturer avant le règlement en utilisant un compte système "Créances à recevoir", avec encaissement ultérieur via bouton sur la fiche facture.

**Architecture:** Approche transaction-first — l'utilisateur crée une recette sur le compte "Créances à recevoir" (formulaire existant), puis génère la facture via le flux V1 existant. Un nouveau service `encaisser()` déplace les transactions du compte système vers un compte bancaire réel. Aucun nouveau modèle, aucune modification de schéma.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, Bootstrap 5

**Spec:** `docs/superpowers/specs/2026-04-01-facture-a-payer-design.md`

---

## File Map

| File | Action | Responsabilité |
|------|--------|----------------|
| `database/migrations/2026_04_01_100001_create_creances_a_recevoir_compte.php` | Create | Migration : seed du compte système |
| `app/Livewire/TransactionForm.php:358` | Modify | Exposer "Créances à recevoir" pour les recettes uniquement |
| `app/Services/FactureService.php` | Modify | Ajouter méthode `encaisser()` |
| `app/Livewire/FactureShow.php` | Modify | Charger données encaissement, dispatch action |
| `resources/views/livewire/facture-show.blade.php` | Modify | Bouton "Enregistrer le règlement" + modale + badge "Non réglée" |
| `app/Livewire/FactureList.php` | Modify | Filtre `non_reglee` + post-filtrage PHP pour `acquittee` |
| `resources/views/livewire/facture-list.blade.php` | Modify | Option "Non réglée" dans le select + badge |
| `tests/Feature/Services/FactureServiceEncaisserTest.php` | Create | Tests encaissement |
| `tests/Feature/CreancesARecevoirTest.php` | Create | Tests intégration bout en bout |

---

### Task 1 : Migration — Compte système "Créances à recevoir"

**Files:**
- Create: `database/migrations/2026_04_01_100001_create_creances_a_recevoir_compte.php`
- Test: `tests/Feature/CreancesARecevoirTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/CreancesARecevoirTest.php

declare(strict_types=1);

use App\Models\CompteBancaire;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has a system account named Créances à recevoir', function () {
    $compte = CompteBancaire::where('nom', 'Créances à recevoir')->first();

    expect($compte)->not->toBeNull()
        ->and($compte->est_systeme)->toBeTrue()
        ->and($compte->actif_recettes_depenses)->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail test --filter="has a system account named Créances à recevoir"`
Expected: FAIL — no such account exists yet.

- [ ] **Step 3: Create the migration**

```php
<?php
// database/migrations/2026_04_01_100001_create_creances_a_recevoir_compte.php

declare(strict_types=1);

use App\Models\CompteBancaire;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        CompteBancaire::create([
            'nom' => 'Créances à recevoir',
            'iban' => '',
            'solde_initial' => 0,
            'date_solde_initial' => now()->toDateString(),
            'actif_recettes_depenses' => true,
            'actif_dons_cotisations' => false,
            'est_systeme' => true,
        ]);
    }

    public function down(): void
    {
        CompteBancaire::where('nom', 'Créances à recevoir')
            ->where('est_systeme', true)
            ->delete();
    }
};
```

- [ ] **Step 4: Run migration**

Run: `./vendor/bin/sail artisan migrate`

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail test --filter="has a system account named Créances à recevoir"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_01_100001_create_creances_a_recevoir_compte.php tests/Feature/CreancesARecevoirTest.php
git commit -m "feat: add Créances à recevoir system account migration"
```

---

### Task 2 : TransactionForm — Exposer le compte pour les recettes uniquement

**Files:**
- Modify: `app/Livewire/TransactionForm.php:358`
- Test: `tests/Feature/CreancesARecevoirTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CreancesARecevoirTest.php`:

```php
it('includes Créances à recevoir in comptes list for recettes', function () {
    $creances = CompteBancaire::where('nom', 'Créances à recevoir')->first();
    $compteNormal = CompteBancaire::factory()->create(['actif_recettes_depenses' => true]);

    // The query used by TransactionForm for recettes should include "Créances à recevoir"
    $comptes = CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get();
    expect($comptes->pluck('id')->toArray())->toContain($creances->id)
        ->and($comptes->pluck('id')->toArray())->toContain($compteNormal->id);
});

it('excludes Créances à recevoir from comptes list for non-recette contexts', function () {
    $creances = CompteBancaire::where('nom', 'Créances à recevoir')->first();

    // The query used by TransactionUniverselle (listing, filters) excludes system accounts
    $comptes = CompteBancaire::where('est_systeme', false)->orderBy('nom')->get();
    expect($comptes->pluck('id')->toArray())->not->toContain($creances->id);
});
```

- [ ] **Step 2: Run tests to verify they pass (already)**

Run: `./vendor/bin/sail test --filter="includes Créances à recevoir|excludes Créances à recevoir"`

These tests should already PASS because `actif_recettes_depenses = true` is set in the migration. The `TransactionForm` uses `where('actif_recettes_depenses', true)` which already includes the new account. **No code change needed in `TransactionForm.php`** — the migration alone solves this.

But we need to ensure the account is hidden for dépenses/virements in the **Blade template**. The `TransactionForm` component passes a `$type` property. We need to filter in the view.

- [ ] **Step 3: Check current Blade rendering of compte dropdown**

Read `resources/views/livewire/transaction-form.blade.php` around line 96-102 to see how the `<select>` for `compte_id` is rendered. The select iterates over all `$comptes` without type filtering.

- [ ] **Step 4: Add conditional filtering in the Blade template**

In `resources/views/livewire/transaction-form.blade.php`, modify the compte `<option>` loop to skip system accounts when the type is not `recette`:

```blade
@foreach ($comptes as $compte)
    @if (! $compte->est_systeme || $type === 'recette')
        <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
    @endif
@endforeach
```

This ensures "Créances à recevoir" only appears for recettes. For dépenses and virements, system accounts are hidden.

- [ ] **Step 5: Run all transaction form tests**

Run: `./vendor/bin/sail test --filter="TransactionForm"`
Expected: PASS (no regression)

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/CreancesARecevoirTest.php resources/views/livewire/transaction-form.blade.php
git commit -m "feat: expose Créances à recevoir in recette form only"
```

---

### Task 3 : FactureService::encaisser()

**Files:**
- Modify: `app/Services/FactureService.php`
- Create: `tests/Feature/Services/FactureServiceEncaisserTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Services/FactureServiceEncaisserTest.php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\Tiers;
use App\Models\User;
use App\Services\FactureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->tiers = Tiers::factory()->create();
    $this->service = app(FactureService::class);
    $this->compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
    $this->compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
});

function createFactureValideeAvecCreance(
    object $testContext,
    float $montant = 100.00,
): Facture {
    $transaction = Transaction::create([
        'type' => TypeTransaction::Recette,
        'date' => '2025-11-15',
        'libelle' => 'Créance test',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Virement,
        'compte_id' => $testContext->compteCreances->id,
        'tiers_id' => $testContext->tiers->id,
        'saisi_par' => $testContext->user->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => \App\Models\SousCategorie::factory()->create()->id,
        'montant' => $montant,
    ]);

    $facture = $testContext->service->creer($testContext->tiers->id);
    $testContext->service->ajouterTransactions($facture, [$transaction->id]);
    $testContext->service->valider($facture);
    $facture->refresh();

    return $facture;
}

describe('encaisser()', function () {
    it('moves transactions from system account to real account', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);

        $transaction = Transaction::find($transactionIds[0]);
        expect($transaction->compte_id)->toBe($this->compteReel->id);
    });

    it('makes facture acquittée after encaissement', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);

        $facture->refresh();
        expect($facture->isAcquittee())->toBeTrue();
    });

    it('rejects encaissement on brouillon facture', function () {
        $facture = $this->service->creer($this->tiers->id);

        $this->service->encaisser($facture, [], $this->compteReel->id);
    })->throws(RuntimeException::class, 'Seule une facture validée peut être encaissée.');

    it('rejects encaissement on already acquittée facture', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        // First encaissement
        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);
        $facture->refresh();

        // Second encaissement should fail
        $this->service->encaisser($facture, $transactionIds, $this->compteReel->id);
    })->throws(RuntimeException::class, 'Cette facture est déjà intégralement réglée.');

    it('rejects encaissement to a system account', function () {
        $facture = createFactureValideeAvecCreance($this);
        $transactionIds = $facture->transactions->pluck('id')->toArray();

        $this->service->encaisser($facture, $transactionIds, $this->compteCreances->id);
    })->throws(RuntimeException::class, 'Le compte de destination doit être un compte bancaire réel.');

    it('rejects encaissement of transaction already on real account', function () {
        // Create a facture with a transaction on a real account (already paid)
        $transaction = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-11-15',
            'libelle' => 'Déjà encaissée',
            'montant_total' => 50.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $this->compteReel->id,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => \App\Models\SousCategorie::factory()->create()->id,
            'montant' => 50.00,
        ]);

        $facture = $this->service->creer($this->tiers->id);
        $this->service->ajouterTransactions($facture, [$transaction->id]);
        $this->service->valider($facture);
        $facture->refresh();

        $this->service->encaisser($facture, [$transaction->id], $this->compteReel->id);
    })->throws(RuntimeException::class, 'Cette transaction est déjà encaissée.');

    it('supports partial encaissement (only some transactions)', function () {
        // Create two créance transactions
        $tx1 = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-11-15',
            'libelle' => 'Créance 1',
            'montant_total' => 100.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $this->compteCreances->id,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx1->id,
            'sous_categorie_id' => \App\Models\SousCategorie::factory()->create()->id,
            'montant' => 100.00,
        ]);

        $tx2 = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-11-15',
            'libelle' => 'Créance 2',
            'montant_total' => 200.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $this->compteCreances->id,
            'tiers_id' => $this->tiers->id,
            'saisi_par' => $this->user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $tx2->id,
            'sous_categorie_id' => \App\Models\SousCategorie::factory()->create()->id,
            'montant' => 200.00,
        ]);

        $facture = $this->service->creer($this->tiers->id);
        $this->service->ajouterTransactions($facture, [$tx1->id, $tx2->id]);
        $this->service->valider($facture);
        $facture->refresh();

        // Encaisser only tx1
        $this->service->encaisser($facture, [$tx1->id], $this->compteReel->id);

        $facture->refresh();
        expect($facture->montantRegle())->toBe(100.00)
            ->and($facture->isAcquittee())->toBeFalse();

        // Encaisser tx2
        $this->service->encaisser($facture, [$tx2->id], $this->compteReel->id);

        $facture->refresh();
        expect($facture->montantRegle())->toBe(300.00)
            ->and($facture->isAcquittee())->toBeTrue();
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test --filter="FactureServiceEncaisserTest"`
Expected: FAIL — `encaisser()` method does not exist.

- [ ] **Step 3: Implement `encaisser()` in FactureService**

Add to `app/Services/FactureService.php`, after the `valider()` method:

```php
/**
 * Move selected transactions from a system account (Créances à recevoir)
 * to a real bank account, marking them as paid on the invoice.
 *
 * @param  array<int>  $transactionIds
 */
public function encaisser(Facture $facture, array $transactionIds, int $compteBancaireId): void
{
    if ($facture->statut !== StatutFacture::Validee) {
        throw new \RuntimeException('Seule une facture validée peut être encaissée.');
    }

    if ($facture->isAcquittee()) {
        throw new \RuntimeException('Cette facture est déjà intégralement réglée.');
    }

    $compteDestination = \App\Models\CompteBancaire::findOrFail($compteBancaireId);
    if ($compteDestination->est_systeme) {
        throw new \RuntimeException('Le compte de destination doit être un compte bancaire réel.');
    }

    DB::transaction(function () use ($facture, $transactionIds, $compteBancaireId): void {
        foreach ($transactionIds as $transactionId) {
            $transaction = $facture->transactions()->findOrFail($transactionId);

            if (! $transaction->compte->est_systeme) {
                throw new \RuntimeException('Cette transaction est déjà encaissée.');
            }

            $transaction->update(['compte_id' => $compteBancaireId]);
        }
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test --filter="FactureServiceEncaisserTest"`
Expected: PASS (all 7 tests)

- [ ] **Step 5: Run full facture test suite for regression**

Run: `./vendor/bin/sail test --filter="FactureService"`
Expected: PASS (no regression)

- [ ] **Step 6: Commit**

```bash
git add app/Services/FactureService.php tests/Feature/Services/FactureServiceEncaisserTest.php
git commit -m "feat: add FactureService::encaisser() for créances"
```

---

### Task 4 : UI — Badge "Non réglée" + bouton "Enregistrer le règlement" sur facture-show

**Files:**
- Modify: `app/Livewire/FactureShow.php`
- Modify: `resources/views/livewire/facture-show.blade.php`

- [ ] **Step 1: Update FactureShow component to pass encaissement data and handle action**

Replace the content of `app/Livewire/FactureShow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutFacture;
use App\Models\CompteBancaire;
use App\Models\Facture;
use App\Services\FactureService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class FactureShow extends Component
{
    public Facture $facture;

    /** @var array<int> */
    public array $selectedTransactionIds = [];

    public ?int $encaissementCompteId = null;

    public function mount(Facture $facture): void
    {
        if ($facture->statut === StatutFacture::Brouillon) {
            $this->redirect(route('gestion.factures.edit', $facture));

            return;
        }

        $facture->load(['tiers', 'compteBancaire', 'lignes', 'transactions.compte']);
        $this->facture = $facture;
    }

    public function toggleTransaction(int $id): void
    {
        if (in_array($id, $this->selectedTransactionIds, true)) {
            $this->selectedTransactionIds = array_values(array_diff($this->selectedTransactionIds, [$id]));
        } else {
            $this->selectedTransactionIds[] = $id;
        }
    }

    public function encaisser(): void
    {
        if ($this->encaissementCompteId === null) {
            session()->flash('error', 'Veuillez sélectionner un compte bancaire de destination.');

            return;
        }

        if (count($this->selectedTransactionIds) === 0) {
            session()->flash('error', 'Veuillez sélectionner au moins une transaction à encaisser.');

            return;
        }

        try {
            app(FactureService::class)->encaisser(
                $this->facture,
                $this->selectedTransactionIds,
                $this->encaissementCompteId,
            );

            $this->selectedTransactionIds = [];
            $this->encaissementCompteId = null;
            $this->facture->load(['transactions.compte']);

            session()->flash('success', 'Encaissement enregistré.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $montantRegle = $this->facture->montantRegle();
        $isAcquittee = $this->facture->isAcquittee();

        // Transactions on system accounts (pending encaissement)
        $transactionsAEncaisser = $this->facture->transactions
            ->filter(fn ($t) => $t->compte->est_systeme);

        // Real bank accounts for destination dropdown
        $comptesDestination = CompteBancaire::where('est_systeme', false)
            ->orderBy('nom')
            ->get();

        return view('livewire.facture-show', [
            'montantRegle' => $montantRegle,
            'isAcquittee' => $isAcquittee,
            'transactionsAEncaisser' => $transactionsAEncaisser,
            'comptesDestination' => $comptesDestination,
        ]);
    }
}
```

- [ ] **Step 2: Update the Blade template — badge "Non réglée" in header**

In `resources/views/livewire/facture-show.blade.php`, replace the badge logic (lines 13-19):

```blade
@if ($isAcquittee)
    <span class="badge bg-success fs-6">Acquittée</span>
@elseif ($facture->statut === \App\Enums\StatutFacture::Annulee)
    <span class="badge bg-danger fs-6">Annulée</span>
@elseif ($montantRegle > 0)
    <span class="badge bg-warning text-dark fs-6">Partiellement réglée</span>
@else
    <span class="badge bg-secondary fs-6">Non réglée</span>
@endif
```

Note: we add a `Partiellement réglée` state for mixed invoices (some transactions on real accounts, some on system).

- [ ] **Step 3: Add "Enregistrer le règlement" button and modal in the Paiement card**

In the Paiement card (after the `@if ($isAcquittee)` block around line 94-98), add:

```blade
@if ($isAcquittee)
    <div class="text-center mt-3">
        <span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> Acquittée</span>
    </div>
@elseif ($transactionsAEncaisser->isNotEmpty())
    <div class="text-center mt-3">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#encaissementModal">
            <i class="bi bi-cash-coin"></i> Enregistrer le règlement
        </button>
    </div>
@endif
```

- [ ] **Step 4: Add the encaissement modal at the end of the template (before closing `</div>`)**

```blade
{{-- Modale d'encaissement --}}
@if ($transactionsAEncaisser->isNotEmpty())
<div class="modal fade" id="encaissementModal" tabindex="-1" wire:ignore.self>
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Enregistrer le règlement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @if (session('error'))
                    <div class="alert alert-danger alert-sm">{{ session('error') }}</div>
                @endif

                <p class="text-muted small mb-3">Sélectionnez les créances reçues et le compte bancaire de destination.</p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Créances en attente</label>
                    @foreach ($transactionsAEncaisser as $tx)
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="tx-{{ $tx->id }}"
                                   wire:click="toggleTransaction({{ $tx->id }})"
                                   @checked(in_array($tx->id, $selectedTransactionIds))>
                            <label class="form-check-label" for="tx-{{ $tx->id }}">
                                {{ $tx->libelle }}
                                <span class="fw-semibold text-nowrap">— {{ number_format((float) $tx->montant_total, 2, ',', "\u{202f}") }}&nbsp;&euro;</span>
                            </label>
                        </div>
                    @endforeach
                </div>

                <div class="mb-3">
                    <label for="encaissement-compte" class="form-label fw-semibold">Compte bancaire de destination</label>
                    <select wire:model="encaissementCompteId" id="encaissement-compte" class="form-select">
                        <option value="">-- Choisir --</option>
                        @foreach ($comptesDestination as $compte)
                            <option value="{{ $compte->id }}">{{ $compte->nom }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="encaisser" data-bs-dismiss="modal">
                    <i class="bi bi-check-lg"></i> Confirmer l'encaissement
                </button>
            </div>
        </div>
    </div>
</div>
@endif
```

- [ ] **Step 5: Manual smoke test**

1. Create a recette on "Créances à recevoir" for a tiers
2. Create a facture for that tiers, select the créance transaction
3. Validate the facture
4. Verify: badge shows "Non réglée", "Reste dû" shows the full amount, button "Enregistrer le règlement" is visible
5. Click the button, check a transaction, select a real account, confirm
6. Verify: badge changes to "Acquittée", button disappears

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/FactureShow.php resources/views/livewire/facture-show.blade.php
git commit -m "feat: add encaissement button and modal on facture show"
```

---

### Task 5 : Listing factures — Filtre "Non réglée" et post-filtrage acquittée

**Files:**
- Modify: `app/Livewire/FactureList.php`
- Modify: `resources/views/livewire/facture-list.blade.php`

- [ ] **Step 1: Update FactureList component with proper filtering**

Replace `app/Livewire/FactureList.php` render method (lines 60-101) with:

```php
public function render(): View
{
    $exercice = app(ExerciceService::class)->current();

    $query = Facture::with('tiers')
        ->where('exercice', $exercice);

    // Pre-filter by statut in SQL
    if ($this->filterStatut !== '' && in_array($this->filterStatut, ['brouillon', 'annulee'])) {
        $query->where('statut', $this->filterStatut);
    } elseif (in_array($this->filterStatut, ['validee', 'acquittee', 'non_reglee'])) {
        // All three require statut = validee in SQL; PHP post-filter distinguishes them
        $query->where('statut', StatutFacture::Validee);
    }

    if ($this->filterTiers !== '') {
        $search = $this->filterTiers;
        $query->whereHas('tiers', function ($q) use ($search): void {
            $q->where('nom', 'like', "%{$search}%")
                ->orWhere('prenom', 'like', "%{$search}%")
                ->orWhere('entreprise', 'like', "%{$search}%");
        });
    }

    $query->orderByDesc('date')->orderByDesc('id');

    // For acquittee/non_reglee filters we need PHP post-filtering
    // Load all validée factures (volume is low for an association)
    if (in_array($this->filterStatut, ['acquittee', 'non_reglee'])) {
        $allFactures = $query->get();

        $filtered = $allFactures->filter(function (Facture $f) {
            $acquittee = $f->isAcquittee();

            return $this->filterStatut === 'acquittee' ? $acquittee : ! $acquittee;
        });

        // Manual pagination is not needed for small volume — show all
        $tiers = Tiers::where('pour_recettes', true)->orderBy('nom')->get();

        return view('livewire.facture-list', [
            'factures' => new \Illuminate\Pagination\LengthAwarePaginator(
                $filtered->forPage($this->getPage(), 20),
                $filtered->count(),
                20,
                $this->getPage(),
                ['path' => request()->url()],
            ),
            'tiers' => $tiers,
        ]);
    }

    $factures = $query->paginate(20);

    $tiers = Tiers::where('pour_recettes', true)
        ->orderBy('nom')
        ->get();

    return view('livewire.facture-list', [
        'factures' => $factures,
        'tiers' => $tiers,
    ]);
}
```

- [ ] **Step 2: Update Blade — add "Non réglée" option and badge**

In `resources/views/livewire/facture-list.blade.php`, update the statut `<select>` (lines 34-40):

```blade
<select wire:model.live="filterStatut" class="form-select">
    <option value="">Tous</option>
    <option value="brouillon">Brouillon</option>
    <option value="validee">Validée (toutes)</option>
    <option value="non_reglee">Non réglée</option>
    <option value="acquittee">Acquittée</option>
    <option value="annulee">Annulée</option>
</select>
```

Update the badge display in the table (lines 100-116) to add the "Non réglée" state:

```blade
@if ($acquittee)
    <span class="badge bg-success" style="font-size:.7rem">
        <i class="bi bi-check-circle"></i> Acquittée
    </span>
@elseif ($facture->statut === \App\Enums\StatutFacture::Brouillon)
    <span class="badge bg-secondary" style="font-size:.7rem">
        <i class="bi bi-pencil"></i> Brouillon
    </span>
@elseif ($facture->statut === \App\Enums\StatutFacture::Validee && $montantRegle > 0)
    <span class="badge bg-warning text-dark" style="font-size:.7rem">
        <i class="bi bi-hourglass-split"></i> Partiellement réglée
    </span>
@elseif ($facture->statut === \App\Enums\StatutFacture::Validee)
    <span class="badge bg-secondary" style="font-size:.7rem">
        <i class="bi bi-clock"></i> Non réglée
    </span>
@elseif ($facture->statut === \App\Enums\StatutFacture::Annulee)
    <span class="badge bg-danger" style="font-size:.7rem">
        <i class="bi bi-x-circle"></i> Annulée
    </span>
@endif
```

- [ ] **Step 3: Manual smoke test**

1. Create some factures in different states (brouillon, acquittée, non réglée, mixte)
2. Verify each filter shows the correct factures
3. Verify the tiers search works in combination with status filter

- [ ] **Step 4: Commit**

```bash
git add app/Livewire/FactureList.php resources/views/livewire/facture-list.blade.php
git commit -m "feat: add Non réglée filter and badges on facture list"
```

---

### Task 6 : Tests d'intégration bout en bout

**Files:**
- Modify: `tests/Feature/CreancesARecevoirTest.php` (append)

- [ ] **Step 1: Add end-to-end integration tests**

Append to `tests/Feature/CreancesARecevoirTest.php`:

```php
use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\FactureService;

describe('full workflow: créance → facture → encaissement', function () {
    it('completes the full lifecycle', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tiers = Tiers::factory()->create();
        $compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
        $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
        $service = app(FactureService::class);
        $sousCategorie = SousCategorie::factory()->create();

        // 1. Create recette on Créances à recevoir
        $transaction = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-06-15',
            'libelle' => 'Prestation yoga mutuelle',
            'montant_total' => 250.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $compteCreances->id,
            'tiers_id' => $tiers->id,
            'saisi_par' => $user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $transaction->id,
            'sous_categorie_id' => $sousCategorie->id,
            'montant' => 250.00,
        ]);

        // 2. Create facture and attach transaction
        $facture = $service->creer($tiers->id);
        $service->ajouterTransactions($facture, [$transaction->id]);

        // 3. Validate
        $service->valider($facture);
        $facture->refresh();

        expect($facture->statut)->toBe(StatutFacture::Validee)
            ->and($facture->montantRegle())->toBe(0.0)
            ->and($facture->isAcquittee())->toBeFalse();

        // 4. Encaisser
        $service->encaisser($facture, [$transaction->id], $compteReel->id);
        $facture->refresh();

        expect($facture->montantRegle())->toBe(250.0)
            ->and($facture->isAcquittee())->toBeTrue();

        // 5. Transaction is now on the real account
        $transaction->refresh();
        expect($transaction->compte_id)->toBe($compteReel->id);
    });

    it('handles mixed invoice (real + créance transactions)', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tiers = Tiers::factory()->create();
        $compteCreances = CompteBancaire::where('nom', 'Créances à recevoir')->firstOrFail();
        $compteReel = CompteBancaire::factory()->create(['est_systeme' => false]);
        $service = app(FactureService::class);
        $sc = SousCategorie::factory()->create();

        // Transaction already paid (real account)
        $txPaid = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-06-15',
            'libelle' => 'Déjà payée',
            'montant_total' => 100.00,
            'mode_paiement' => ModePaiement::CarteBancaire,
            'compte_id' => $compteReel->id,
            'tiers_id' => $tiers->id,
            'saisi_par' => $user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $txPaid->id,
            'sous_categorie_id' => $sc->id,
            'montant' => 100.00,
        ]);

        // Transaction pending (créance)
        $txCreance = Transaction::create([
            'type' => TypeTransaction::Recette,
            'date' => '2025-06-15',
            'libelle' => 'En attente mutuelle',
            'montant_total' => 200.00,
            'mode_paiement' => ModePaiement::Virement,
            'compte_id' => $compteCreances->id,
            'tiers_id' => $tiers->id,
            'saisi_par' => $user->id,
        ]);
        TransactionLigne::create([
            'transaction_id' => $txCreance->id,
            'sous_categorie_id' => $sc->id,
            'montant' => 200.00,
        ]);

        // Create mixed facture
        $facture = $service->creer($tiers->id);
        $service->ajouterTransactions($facture, [$txPaid->id, $txCreance->id]);
        $service->valider($facture);
        $facture->refresh();

        // Partially paid (100 out of 300)
        expect((float) $facture->montant_total)->toBe(300.0)
            ->and($facture->montantRegle())->toBe(100.0)
            ->and($facture->isAcquittee())->toBeFalse();

        // Encaisser the créance
        $service->encaisser($facture, [$txCreance->id], $compteReel->id);
        $facture->refresh();

        expect($facture->montantRegle())->toBe(300.0)
            ->and($facture->isAcquittee())->toBeTrue();
    });
});
```

- [ ] **Step 2: Run all tests**

Run: `./vendor/bin/sail test --filter="CreancesARecevoirTest"`
Expected: PASS (all tests)

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: PASS (no regression)

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/CreancesARecevoirTest.php
git commit -m "test: add end-to-end tests for créances à recevoir workflow"
```

---

### Task 7 : Pint + commit final

- [ ] **Step 1: Run pint**

Run: `./vendor/bin/sail exec laravel.test ./vendor/bin/pint`

- [ ] **Step 2: Run full test suite one last time**

Run: `./vendor/bin/sail test`
Expected: PASS

- [ ] **Step 3: Commit formatting if any changes**

```bash
git add -A
git commit -m "style: apply pint formatting"
```
