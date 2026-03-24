# Lot 6 — Verrouillage automatique des versements HelloAsso — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-créer un rapprochement bancaire verrouillé sur le compte HelloAsso pour chaque cashout complet, protégeant les transactions liées.

**Architecture:** La sync existante est enrichie : les payments sont fetchés sur N-1→N pour capter les cashouts cross-exercice. Pour chaque cashout complet (somme transactions = montant cashout), un VirementInterne + RapprochementBancaire verrouillé sont créés dans une même DB::transaction. Une nouvelle méthode `createVerrouilleAuto()` sur `RapprochementBancaireService` gère la création directe en statut Verrouille.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, MySQL

**Spec:** `docs/superpowers/specs/2026-03-23-lot6-verrouillage-versements-design.md`

---

### Task 1: `createVerrouilleAuto()` sur RapprochementBancaireService

**Files:**
- Modify: `app/Services/RapprochementBancaireService.php`
- Test: `tests/Feature/Lot6/RapprochementAutoTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    User::factory()->create();
    $this->actingAs(User::first());
    $this->compte = CompteBancaire::factory()->create(['nom' => 'HelloAsso', 'solde_initial' => 0]);
});

it('creates a locked rapprochement with pointed transactions and virement', function () {
    // Create 2 transactions on the HA account
    $tx1 = Transaction::factory()->create([
        'compte_id' => $this->compte->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'saisi_par' => User::first()->id,
    ]);
    $tx2 = Transaction::factory()->create([
        'compte_id' => $this->compte->id,
        'type' => 'recette',
        'montant_total' => 50.00,
        'saisi_par' => User::first()->id,
    ]);

    // Create the virement (sortie du compte HA)
    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => CompteBancaire::factory()->create()->id,
        'montant' => 75.00,
        'saisi_par' => User::first()->id,
    ]);

    $service = new RapprochementBancaireService;
    $rapprochement = $service->createVerrouilleAuto(
        compte: $this->compte,
        dateFin: '2025-10-20',
        soldeFin: 0.00,
        transactionIds: [$tx1->id, $tx2->id],
        virementId: $virement->id,
    );

    expect($rapprochement->statut)->toBe(StatutRapprochement::Verrouille);
    expect($rapprochement->verrouille_at)->not->toBeNull();
    expect($rapprochement->solde_ouverture)->toBe('0.00');
    expect($rapprochement->solde_fin)->toBe('0.00');
    expect($rapprochement->date_fin->toDateString())->toBe('2025-10-20');

    // Transactions are pointed
    expect($tx1->fresh()->rapprochement_id)->toBe($rapprochement->id);
    expect($tx1->fresh()->pointe)->toBeTrue();
    expect($tx2->fresh()->rapprochement_id)->toBe($rapprochement->id);

    // Virement is pointed as source
    expect($virement->fresh()->rapprochement_source_id)->toBe($rapprochement->id);
});

it('uses solde_fin of last locked rapprochement as solde_ouverture', function () {
    // Create a previous locked rapprochement
    RapprochementBancaire::create([
        'compte_id' => $this->compte->id,
        'date_fin' => '2025-09-15',
        'solde_ouverture' => 0.00,
        'solde_fin' => 0.00,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => User::first()->id,
    ]);

    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => CompteBancaire::factory()->create()->id,
        'montant' => 0,
        'saisi_par' => User::first()->id,
    ]);

    $service = new RapprochementBancaireService;
    $rapprochement = $service->createVerrouilleAuto(
        compte: $this->compte,
        dateFin: '2025-10-20',
        soldeFin: 0.00,
        transactionIds: [],
        virementId: $virement->id,
    );

    expect($rapprochement->solde_ouverture)->toBe('0.00');
});

it('works even when a manual rapprochement en cours exists', function () {
    // Manually create an "en cours" rapprochement
    RapprochementBancaire::create([
        'compte_id' => $this->compte->id,
        'date_fin' => '2025-12-31',
        'solde_ouverture' => 0.00,
        'solde_fin' => 100.00,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => User::first()->id,
    ]);

    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => CompteBancaire::factory()->create()->id,
        'montant' => 0,
        'saisi_par' => User::first()->id,
    ]);

    $service = new RapprochementBancaireService;
    // Should NOT throw — auto-lock bypasses the "en cours" guard
    $rapprochement = $service->createVerrouilleAuto(
        compte: $this->compte,
        dateFin: '2025-10-20',
        soldeFin: 0.00,
        transactionIds: [],
        virementId: $virement->id,
    );

    expect($rapprochement->isVerrouille())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Lot6/RapprochementAutoTest.php`
Expected: FAIL — `createVerrouilleAuto` method doesn't exist

- [ ] **Step 3: Implement `createVerrouilleAuto()`**

Add to `app/Services/RapprochementBancaireService.php` after the existing `create()` method:

```php
/**
 * Crée un rapprochement directement verrouillé (auto-généré par la sync HelloAsso).
 * Ne vérifie pas s'il existe un rapprochement en cours — indépendant du workflow manuel.
 *
 * @param  list<int>  $transactionIds  IDs des transactions à pointer
 */
public function createVerrouilleAuto(
    CompteBancaire $compte,
    string $dateFin,
    float $soldeFin,
    array $transactionIds,
    int $virementId,
): RapprochementBancaire {
    return DB::transaction(function () use ($compte, $dateFin, $soldeFin, $transactionIds, $virementId) {
        $rapprochement = RapprochementBancaire::create([
            'compte_id' => $compte->id,
            'date_fin' => $dateFin,
            'solde_ouverture' => $this->calculerSoldeOuverture($compte),
            'solde_fin' => $soldeFin,
            'statut' => StatutRapprochement::Verrouille,
            'verrouille_at' => now(),
            'saisi_par' => auth()->id() ?? 1,
        ]);

        if (! empty($transactionIds)) {
            Transaction::whereIn('id', $transactionIds)
                ->update(['rapprochement_id' => $rapprochement->id, 'pointe' => true]);
        }

        VirementInterne::where('id', $virementId)
            ->update(['rapprochement_source_id' => $rapprochement->id]);

        return $rapprochement;
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Lot6/RapprochementAutoTest.php`
Expected: 3 tests PASS

- [ ] **Step 5: Run Pint and commit**

```bash
./vendor/bin/pint app/Services/RapprochementBancaireService.php tests/Feature/Lot6/RapprochementAutoTest.php
git add app/Services/RapprochementBancaireService.php tests/Feature/Lot6/RapprochementAutoTest.php
git commit -m "feat(lot6): createVerrouilleAuto() sur RapprochementBancaireService"
```

---

### Task 2: Refonte de `synchroniserCashouts()` — complétude + rapprochement auto

**Files:**
- Modify: `app/Services/HelloAssoSyncService.php`
- Test: `tests/Feature/Lot6/HelloAssoSyncCashoutV2Test.php`

**Context:** La méthode `synchroniserCashouts()` existante crée le virement sans vérifier la complétude et sans rapprochement. On la refond pour :
1. Trier les cashouts par date
2. Vérifier la complétude (somme transactions == montant cashout)
3. Si complet : créer virement + rapprochement auto verrouillé
4. Si incomplet : skip le virement, remonter un warning
5. Mettre à jour `helloasso_cashout_id` sur les transactions trouvées (sans filtre exercice)

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\HelloAssoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    User::factory()->create();
    $this->actingAs(User::first());

    $this->compteHA = CompteBancaire::factory()->create(['nom' => 'HelloAsso', 'solde_initial' => 0]);
    $this->compteCourant = CompteBancaire::factory()->create(['nom' => 'Compte courant']);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'test',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $this->compteHA->id,
        'compte_versement_id' => $this->compteCourant->id,
    ]);
});

it('creates virement + locked rapprochement for a complete cashout', function () {
    // 2 transactions already in DB, linked via helloasso_payment_id
    $tx1 = Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 101,
        'saisi_par' => User::first()->id,
    ]);
    $tx2 = Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 50.00,
        'helloasso_payment_id' => 102,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3001,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500, // 75.00€ in cents
            'payments' => [['id' => 101], ['id' => 102]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    // Virement created
    expect($result['virements_created'])->toBe(1);
    $virement = VirementInterne::where('helloasso_cashout_id', 3001)->first();
    expect($virement)->not->toBeNull();
    expect($virement->montant)->toBe('75.00');

    // Rapprochement created and locked
    expect($result['rapprochements_created'])->toBe(1);
    $rapprochement = RapprochementBancaire::where('compte_id', $this->compteHA->id)
        ->where('statut', StatutRapprochement::Verrouille)
        ->first();
    expect($rapprochement)->not->toBeNull();
    expect($rapprochement->solde_fin)->toBe($rapprochement->solde_ouverture);

    // Transactions pointed and have cashout_id
    expect($tx1->fresh()->helloasso_cashout_id)->toBe(3001);
    expect($tx1->fresh()->rapprochement_id)->toBe($rapprochement->id);
    expect($tx2->fresh()->helloasso_cashout_id)->toBe(3001);
    expect($tx2->fresh()->rapprochement_id)->toBe($rapprochement->id);

    // Virement pointed as source
    expect($virement->fresh()->rapprochement_source_id)->toBe($rapprochement->id);
});

it('skips virement and rapprochement for an incomplete cashout', function () {
    // Only 1 transaction exists, cashout expects 2
    Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 201,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3002,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500, // 75.00€ but only 25€ of transactions
            'payments' => [['id' => 201], ['id' => 202]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(0);
    expect($result['rapprochements_created'])->toBe(0);
    expect($result['cashouts_incomplets'])->toHaveCount(1);
    expect(VirementInterne::where('helloasso_cashout_id', 3002)->count())->toBe(0);
});

it('updates helloasso_cashout_id on transactions even when incomplete', function () {
    $tx = Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 301,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3003,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500, // incomplete
            'payments' => [['id' => 301], ['id' => 302]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $service->synchroniserCashouts($cashOuts);

    // cashout_id is set even though cashout is incomplete
    expect($tx->fresh()->helloasso_cashout_id)->toBe(3003);
});

it('skips already-processed cashouts (idempotent)', function () {
    // Virement already exists for this cashout
    VirementInterne::factory()->create([
        'helloasso_cashout_id' => 3004,
        'compte_source_id' => $this->compteHA->id,
        'compte_destination_id' => $this->compteCourant->id,
        'montant' => 75.00,
        'saisi_par' => User::first()->id,
    ]);

    $cashOuts = [
        [
            'id' => 3004,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 7500,
            'payments' => [['id' => 401]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(0);
    expect($result['rapprochements_created'])->toBe(0);
    // Only 1 virement exists (not duplicated)
    expect(VirementInterne::where('helloasso_cashout_id', 3004)->count())->toBe(1);
});

it('processes multiple cashouts in chronological order', function () {
    Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 50.00,
        'helloasso_payment_id' => 501,
        'saisi_par' => User::first()->id,
    ]);
    Transaction::factory()->create([
        'compte_id' => $this->compteHA->id,
        'type' => 'recette',
        'montant_total' => 25.00,
        'helloasso_payment_id' => 502,
        'saisi_par' => User::first()->id,
    ]);

    // Cashouts given in reverse order — should be sorted by date
    $cashOuts = [
        [
            'id' => 3006,
            'date' => '2025-11-20T10:00:00+02:00',
            'amount' => 2500,
            'payments' => [['id' => 502]],
        ],
        [
            'id' => 3005,
            'date' => '2025-10-20T10:00:00+02:00',
            'amount' => 5000,
            'payments' => [['id' => 501]],
        ],
    ];

    $service = new HelloAssoSyncService($this->parametres);
    $result = $service->synchroniserCashouts($cashOuts);

    expect($result['virements_created'])->toBe(2);
    expect($result['rapprochements_created'])->toBe(2);

    // Check chronological order: first rapprochement has earlier date
    $rapprochements = RapprochementBancaire::where('compte_id', $this->compteHA->id)
        ->orderBy('date_fin')
        ->get();
    expect($rapprochements)->toHaveCount(2);
    expect($rapprochements[0]->date_fin->toDateString())->toBe('2025-10-20');
    expect($rapprochements[1]->date_fin->toDateString())->toBe('2025-11-20');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Lot6/HelloAssoSyncCashoutV2Test.php`
Expected: FAIL — `rapprochements_created` key missing, old behavior creates virement without checking completeness

- [ ] **Step 3: Rewrite `synchroniserCashouts()` and `processCashout()`**

Replace the existing methods in `app/Services/HelloAssoSyncService.php`:

```php
/**
 * Import HelloAsso cash-outs: verify completeness, create virements + auto-locked rapprochements.
 *
 * @param  list<array<string, mixed>>  $cashOuts
 * @return array{virements_created: int, virements_updated: int, rapprochements_created: int, cashouts_incomplets: list<string>, info_exercice_precedent: list<string>, errors: list<string>}
 */
public function synchroniserCashouts(array $cashOuts): array
{
    $virementsCreated = 0;
    $rapprochementsCreated = 0;
    $cashoutsIncomplets = [];
    $errors = [];

    // Sort by cashout date (chronological) for consistent rapprochement chain
    usort($cashOuts, fn ($a, $b) => strcmp($a['date'], $b['date']));

    foreach ($cashOuts as $cashOut) {
        try {
            $result = $this->processCashout($cashOut);
            $virementsCreated += $result['created'];
            $rapprochementsCreated += $result['rapprochement_created'];
            if ($result['incomplet'] !== null) {
                $cashoutsIncomplets[] = $result['incomplet'];
            }
        } catch (\Throwable $e) {
            $errors[] = "Cashout #{$cashOut['id']} : {$e->getMessage()}";
        }
    }

    return [
        'virements_created' => $virementsCreated,
        'virements_updated' => 0,
        'rapprochements_created' => $rapprochementsCreated,
        'cashouts_incomplets' => $cashoutsIncomplets,
        'info_exercice_precedent' => [],
        'errors' => $errors,
    ];
}

/**
 * @return array{created: int, rapprochement_created: int, incomplet: ?string}
 */
private function processCashout(array $cashOut): array
{
    $result = ['created' => 0, 'rapprochement_created' => 0, 'incomplet' => null];

    // Idempotence: skip if virement already exists
    $existingVirement = VirementInterne::where('helloasso_cashout_id', $cashOut['id'])->exists();
    if ($existingVirement) {
        return $result;
    }

    $cashOutDate = Carbon::parse($cashOut['date']);
    $montantEuros = round($cashOut['amount'] / 100, 2);

    // Collect payment IDs from the cashout
    $paymentIds = collect($cashOut['payments'] ?? [])->pluck('id')->filter()->all();

    // Find matching transactions in DB (no exercice filter)
    $transactions = Transaction::whereIn('helloasso_payment_id', $paymentIds)->get();

    // Update helloasso_cashout_id on found transactions (even if incomplete)
    Transaction::whereIn('helloasso_payment_id', $paymentIds)
        ->whereNull('helloasso_cashout_id')
        ->update(['helloasso_cashout_id' => $cashOut['id']]);

    // Check completeness
    $sumTransactions = round((float) $transactions->sum('montant_total'), 2);

    if (abs($sumTransactions - $montantEuros) > 0.01) {
        $result['incomplet'] = sprintf(
            'Cashout #%d incomplet : écart de %.2f € (versement %.2f €, transactions %.2f €)',
            $cashOut['id'],
            abs($montantEuros - $sumTransactions),
            $montantEuros,
            $sumTransactions,
        );

        return $result;
    }

    // Complete → create virement + auto-locked rapprochement
    DB::transaction(function () use ($cashOut, $cashOutDate, $montantEuros, $transactions, &$result) {
        $virement = VirementInterne::create([
            'date' => $cashOutDate->toDateString(),
            'montant' => $montantEuros,
            'compte_source_id' => $this->parametres->compte_helloasso_id,
            'compte_destination_id' => $this->parametres->compte_versement_id,
            'notes' => 'Versement HelloAsso du '.$cashOutDate->format('d/m/Y'),
            'reference' => "HA-CO-{$cashOut['id']}",
            'helloasso_cashout_id' => $cashOut['id'],
            'saisi_par' => auth()->id() ?? 1,
            'numero_piece' => app(NumeroPieceService::class)->assign($cashOutDate),
        ]);
        $result['created']++;

        // Auto-locked rapprochement
        $compte = CompteBancaire::find($this->parametres->compte_helloasso_id);
        $soldeOuverture = app(RapprochementBancaireService::class)->calculerSoldeOuverture($compte);

        app(RapprochementBancaireService::class)->createVerrouilleAuto(
            compte: $compte,
            dateFin: $cashOutDate->toDateString(),
            soldeFin: $soldeOuverture, // net = 0, so solde_fin = solde_ouverture
            transactionIds: $transactions->pluck('id')->all(),
            virementId: $virement->id,
        );
        $result['rapprochement_created']++;
    });

    return $result;
}
```

**Important:** Also add the missing import at the top of the file:

```php
use App\Models\CompteBancaire;
use App\Services\RapprochementBancaireService;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Lot6/HelloAssoSyncCashoutV2Test.php`
Expected: 5 tests PASS

- [ ] **Step 5: Run existing cashout tests to check for regressions**

Run: `./vendor/bin/sail test tests/Feature/Lot5/HelloAssoSyncCashoutTest.php`
Expected: Some tests may fail due to changed return format (`rapprochements_created` key, completeness check). Update them to match the new behavior.

**If tests fail**, update `tests/Feature/Lot5/HelloAssoSyncCashoutTest.php`:
- The `synchroniserCashouts()` return array now has `rapprochements_created`, `cashouts_incomplets`, `info_exercice_precedent` keys
- Tests that create a cashout without matching transactions will now get an incomplet warning instead of creating a virement
- Adjust assertions accordingly

- [ ] **Step 6: Run Pint and commit**

```bash
./vendor/bin/pint app/Services/HelloAssoSyncService.php tests/Feature/Lot6/HelloAssoSyncCashoutV2Test.php
git add app/Services/HelloAssoSyncService.php tests/Feature/Lot6/HelloAssoSyncCashoutV2Test.php
git commit -m "feat(lot6): refonte synchroniserCashouts — complétude + rapprochement auto verrouillé"
```

---

### Task 3: Adapter le composant Livewire — fetch payments élargi + rapport enrichi

**Files:**
- Modify: `app/Livewire/Parametres/HelloassoSync.php`
- Test: `tests/Feature/Lot6/HelloAssoSyncComponentV2Test.php`

**Context:** Le composant doit :
1. Fetcher les payments sur N-1→N (au lieu de N seul)
2. Passer les cashouts extraits au service refondu
3. Afficher les nouvelles infos du rapport (rapprochements créés, cashouts incomplets)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Livewire\Parametres\HelloassoSync;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Models\VirementInterne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $compteHA = CompteBancaire::factory()->create(['nom' => 'HelloAsso', 'solde_initial' => 0]);
    $compteCourant = CompteBancaire::factory()->create(['nom' => 'Compte courant']);
    $scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true, 'nom' => 'Don']);

    HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
        'compte_helloasso_id' => $compteHA->id,
        'compte_versement_id' => $compteCourant->id,
        'sous_categorie_don_id' => $scDon->id,
    ]);

    User::factory()->create();
    Tiers::factory()->avecHelloasso()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
});

it('creates rapprochement auto when cashout is complete', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 100, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00',
                        'formSlug' => 'dons-libres', 'formType' => 'Donation',
                        'items' => [['id' => 1001, 'amount' => 5000, 'state' => 'Processed', 'type' => 'Donation', 'name' => 'Don']],
                        'user' => ['firstName' => 'Jean', 'lastName' => 'Dupont'],
                        'payer' => ['firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean@test.com'],
                        'payments' => [['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00', 'paymentMeans' => 'Card']],
                    ],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
        '*/v5/organizations/mon-asso/payments*' => Http::sequence()
            ->push([
                'data' => [
                    ['id' => 201, 'amount' => 5000, 'date' => '2025-10-15T10:00:00+02:00',
                        'idCashOut' => 5001, 'cashOutDate' => '2025-10-20T10:00:00+02:00', 'cashOutState' => 'CashedOut'],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser')
        ->assertSee('1 créée')
        ->assertSee('Rapprochements')
        ->assertSee('Synchronisation terminée');

    expect(VirementInterne::where('helloasso_cashout_id', 5001)->count())->toBe(1);
    expect(RapprochementBancaire::where('statut', StatutRapprochement::Verrouille)->count())->toBe(1);
});

it('fetches payments with extended range N-1 to N', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/orders*' => Http::sequence()
            ->push(['data' => [], 'pagination' => []])
            ->push(['data' => [], 'pagination' => []]),
        '*/v5/organizations/mon-asso/payments*' => Http::sequence()
            ->push(['data' => [], 'pagination' => []])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSync::class)
        ->call('synchroniser');

    // Verify the payments endpoint was called with N-1 start date
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/payments')) {
            return false;
        }
        // For exercice 2025: orders use from=2025-09-01, payments should use from=2024-09-01
        $from = $request['from'] ?? '';

        return str_starts_with($from, '2024-09-01') || str_starts_with($from, '2025-09-01');
    });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/sail test tests/Feature/Lot6/HelloAssoSyncComponentV2Test.php`
Expected: FAIL

- [ ] **Step 3: Update the Livewire component**

Modify `app/Livewire/Parametres/HelloassoSync.php`:

1. Remove the debug logging code
2. Fetch payments with plage élargie N-1→N
3. Update result array with new keys from refactored `synchroniserCashouts()`

```php
public function synchroniser(): void
{
    $this->erreur = null;
    $this->result = null;

    $parametres = HelloAssoParametres::where('association_id', 1)->first();
    if ($parametres === null || $parametres->client_id === null) {
        $this->erreur = 'Paramètres HelloAsso non configurés.';

        return;
    }

    if ($parametres->compte_helloasso_id === null) {
        $this->erreur = 'Compte HelloAsso non configuré. Configurez-le dans la section ci-dessus.';

        return;
    }

    try {
        $client = new HelloAssoApiClient($parametres);

        $exerciceService = app(ExerciceService::class);
        $range = $exerciceService->dateRange($this->exercice);
        $from = $range['start']->toDateString();
        $to = $range['end']->toDateString();

        $orders = $client->fetchOrders($from, $to);
    } catch (\RuntimeException $e) {
        $this->erreur = $e->getMessage();

        return;
    }

    $syncService = new HelloAssoSyncService($parametres);
    $syncResult = $syncService->synchroniser($orders, $this->exercice);
    $this->result = [
        'transactionsCreated' => $syncResult->transactionsCreated,
        'transactionsUpdated' => $syncResult->transactionsUpdated,
        'lignesCreated' => $syncResult->lignesCreated,
        'lignesUpdated' => $syncResult->lignesUpdated,
        'ordersSkipped' => $syncResult->ordersSkipped,
        'errors' => $syncResult->errors,
        'virementsCreated' => 0,
        'virementsUpdated' => 0,
        'rapprochementsCreated' => 0,
        'cashoutsIncomplets' => [],
        'cashoutSkipped' => false,
    ];

    // Cashout sync — fetch payments with extended range (N-1 → N)
    if ($parametres->compte_versement_id === null) {
        $this->result['cashoutSkipped'] = true;
    } else {
        try {
            $rangePrev = $exerciceService->dateRange($this->exercice - 1);
            $paymentsFrom = $rangePrev['start']->toDateString();

            $payments = $client->fetchPayments($paymentsFrom, $to);
            $cashOuts = HelloAssoApiClient::extractCashOutsFromPayments($payments);
            $cashoutResult = $syncService->synchroniserCashouts($cashOuts);

            $this->result['virementsCreated'] = $cashoutResult['virements_created'];
            $this->result['virementsUpdated'] = $cashoutResult['virements_updated'];
            $this->result['rapprochementsCreated'] = $cashoutResult['rapprochements_created'];
            $this->result['cashoutsIncomplets'] = $cashoutResult['cashouts_incomplets'];

            if (! empty($cashoutResult['errors'])) {
                $this->result['errors'] = array_merge($this->result['errors'], $cashoutResult['errors']);
            }
        } catch (\RuntimeException $e) {
            $this->result['errors'][] = "Cashouts : {$e->getMessage()}";
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail test tests/Feature/Lot6/HelloAssoSyncComponentV2Test.php`
Expected: 2 tests PASS

- [ ] **Step 5: Run Pint and commit**

```bash
./vendor/bin/pint app/Livewire/Parametres/HelloassoSync.php tests/Feature/Lot6/HelloAssoSyncComponentV2Test.php
git add app/Livewire/Parametres/HelloassoSync.php tests/Feature/Lot6/HelloAssoSyncComponentV2Test.php
git commit -m "feat(lot6): fetch payments élargi N-1→N + rapport enrichi dans le composant"
```

---

### Task 4: Mise à jour de la vue Blade — rapport enrichi

**Files:**
- Modify: `resources/views/livewire/parametres/helloasso-sync.blade.php`

- [ ] **Step 1: Update the Blade view**

Replace the current rapport section in the Blade view. The key additions:
- Line for rapprochements created
- Section for cashouts incomplets (warnings)
- Remove old `integrityWarnings` section (replaced by `cashoutsIncomplets`)

```blade
@if($result)
    <div class="alert {{ count($result['errors']) > 0 ? 'alert-warning' : 'alert-success' }}">
        <strong><i class="bi bi-check-circle me-1"></i> Synchronisation terminée</strong>
        <ul class="mb-0 mt-2">
            <li>Transactions : <strong>{{ $result['transactionsCreated'] }} créée(s)</strong>, <strong>{{ $result['transactionsUpdated'] }} mise(s) à jour</strong></li>
            <li>Lignes : <strong>{{ $result['lignesCreated'] }} créée(s)</strong>, <strong>{{ $result['lignesUpdated'] }} mise(s) à jour</strong></li>
            @if($result['ordersSkipped'] > 0)
                <li>Commandes ignorées : <strong>{{ $result['ordersSkipped'] }}</strong></li>
            @endif
            @if(($result['virementsCreated'] ?? 0) > 0 || ($result['virementsUpdated'] ?? 0) > 0)
                <li>Virements : <strong>{{ $result['virementsCreated'] }} créé(s)</strong>, <strong>{{ $result['virementsUpdated'] }} mis à jour</strong></li>
            @endif
            @if(($result['rapprochementsCreated'] ?? 0) > 0)
                <li>Rapprochements auto-verrouillés : <strong>{{ $result['rapprochementsCreated'] }}</strong></li>
            @endif
        </ul>
    </div>

    @if(!empty($result['cashoutSkipped']))
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i> Versements non synchronisés : le compte de versement n'est pas configuré dans les paramètres HelloAsso.
        </div>
    @endif

    @if(!empty($result['cashoutsIncomplets']))
        <div class="alert alert-warning">
            <strong><i class="bi bi-exclamation-triangle me-1"></i> Versements incomplets :</strong>
            <ul class="mb-0 mt-1">
                @foreach($result['cashoutsIncomplets'] as $warning)
                    <li class="small">{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(count($result['errors']) > 0)
        <div class="alert alert-danger">
            <strong><i class="bi bi-exclamation-triangle me-1"></i> {{ count($result['errors']) }} erreur(s) :</strong>
            <ul class="mb-0 mt-1">
                @foreach($result['errors'] as $error)
                    <li class="small">{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
@endif
```

- [ ] **Step 2: Commit**

```bash
./vendor/bin/pint resources/views/livewire/parametres/helloasso-sync.blade.php
git add resources/views/livewire/parametres/helloasso-sync.blade.php
git commit -m "feat(lot6): rapport enrichi — rapprochements auto + cashouts incomplets"
```

---

### Task 5: Adapter les tests Lot 5 existants

**Files:**
- Modify: `tests/Feature/Lot5/HelloAssoSyncCashoutTest.php`
- Modify: `tests/Feature/Lot5/HelloAssoSyncComponentCashoutTest.php`

**Context:** Les tests Lot 5 attendent l'ancien format de retour de `synchroniserCashouts()` et l'ancien comportement (création du virement sans vérification de complétude). Il faut les adapter au nouveau comportement.

- [ ] **Step 1: Update `HelloAssoSyncCashoutTest.php`**

Key changes:
- Tests that create virements must now provide matching transactions with `helloasso_payment_id` for completeness
- The return array has new keys: `rapprochements_created`, `cashouts_incomplets`, `info_exercice_precedent`
- Tests about "integrity warnings" become tests about "cashouts incomplets"
- The `integrity_warnings` key is replaced by `cashouts_incomplets`

- [ ] **Step 2: Update `HelloAssoSyncComponentCashoutTest.php`**

Key changes:
- The component result now has `rapprochementsCreated` and `cashoutsIncomplets` keys
- The payments endpoint mock needs to be present (separate from orders)
- The "skip cashout" test may need minor adjustment

- [ ] **Step 3: Run all tests**

Run: `./vendor/bin/sail test`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
./vendor/bin/pint tests/Feature/Lot5/
git add tests/Feature/Lot5/
git commit -m "fix(lot5): adapter tests au nouveau format synchroniserCashouts"
```

---

### Task 6: Run full test suite + cleanup

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/sail test`
Expected: All tests PASS

- [ ] **Step 2: Remove debug logging if any remains**

Check `app/Livewire/Parametres/HelloassoSync.php` for any remaining `\Log::info` debug lines and remove them.

- [ ] **Step 3: Run Pint on all modified files**

```bash
./vendor/bin/pint
```

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore(lot6): cleanup et vérification suite de tests complète"
```
