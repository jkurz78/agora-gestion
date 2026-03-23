# Lot 2 — Suppression des tables dons/cotisations et nettoyage du code

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Supprimer les tables `dons` et `cotisations` et tout le code associé, en remplaçant les requêtes par des requêtes sur le modèle unifié `transactions` + `transaction_lignes` + sous-catégories.

**Architecture:** Pas de migration de données (aucune donnée en production). Les tables sont droppées, les modèles/services/composants supprimés, et les services adaptés pour requêter `transaction_lignes` jointes aux `sous_categories` via les flags `pour_dons` / `pour_cotisations`. Les routes `/dons` et `/cotisations` restent mais pointent vers `TransactionUniverselle` filtré par sous-catégorie.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, MySQL

**Spec:** `docs/superpowers/specs/2026-03-22-helloasso-integration-design.md` — section "Lot 2" (lignes 382-398) + section "Impact sur les écrans existants" (lignes 298-365)

---

## File Structure

### Files to DELETE

| File | Reason |
|---|---|
| `app/Models/Don.php` | Modèle supprimé |
| `app/Models/Cotisation.php` | Modèle supprimé |
| `app/Services/DonService.php` | Service supprimé |
| `app/Services/CotisationService.php` | Service supprimé |
| `app/Livewire/DonForm.php` | Composant supprimé |
| `app/Livewire/DonList.php` | Composant supprimé |
| `app/Livewire/CotisationForm.php` | Composant supprimé |
| `app/Livewire/CotisationList.php` | Composant supprimé |
| `database/factories/DonFactory.php` | Factory supprimée |
| `database/factories/CotisationFactory.php` | Factory supprimée |
| `resources/views/livewire/don-form.blade.php` | Vue supprimée |
| `resources/views/livewire/don-list.blade.php` | Vue supprimée |
| `resources/views/livewire/cotisation-form.blade.php` | Vue supprimée |
| `resources/views/livewire/cotisation-list.blade.php` | Vue supprimée |
| `tests/Feature/DonTest.php` | Test supprimé |
| `tests/Feature/CotisationTest.php` | Test supprimé |
| `tests/Feature/Livewire/DonFormTest.php` | Test supprimé |
| `tests/Feature/Livewire/DonListTest.php` | Test supprimé |
| `tests/Feature/Livewire/CotisationListTest.php` | Test supprimé |
| `tests/Livewire/DonFormTest.php` | Test supprimé |
| `tests/Livewire/DonListTest.php` | Test supprimé |
| `tests/Livewire/CotisationFormTest.php` | Test supprimé |
| `tests/Livewire/CotisationFormOpenForTiersTest.php` | Test supprimé |
| `tests/Feature/Migrations/FinalizeDonsTiersIdTest.php` | Test supprimé (tables n'existent plus) |
| `tests/Feature/Migrations/FinalizeCotisationsTiersIdTest.php` | Test supprimé (tables n'existent plus) |

### Files to MODIFY

| File | Change |
|---|---|
| `app/Models/Tiers.php` | Retirer `dons()`, `cotisations()` relations |
| `app/Models/CompteBancaire.php` | Retirer `dons()`, `cotisations()` relations |
| `app/Models/RapprochementBancaire.php` | Retirer `dons()`, `cotisations()` relations |
| `app/Models/Operation.php` | Retirer `dons()` relation |
| `app/Models/User.php` | Retirer `dons()` relation |
| `app/Services/SoldeService.php` | Retirer branches `dons()` / `cotisations()` |
| `app/Services/RapprochementBancaireService.php` | Retirer requêtes Don/Cotisation |
| `app/Services/RapportService.php` | Retirer requêtes Don/Cotisation des produits |
| `app/Services/TransactionUniverselleService.php` | Retirer `brancheDon()`, `brancheCotisation()` |
| `app/Services/TransactionCompteService.php` | Retirer branches don/cotisation du UNION |
| `app/Services/TiersService.php` | Mettre à jour commentaire |
| `app/Livewire/TransactionUniverselle.php` | Ajouter `$sousCategorieFilter`, retirer refs don/cotisation |
| `app/Livewire/TransactionCompteList.php` | Retirer `deleteDon()`, `deleteCotisation()`, etc. |
| `app/Livewire/RapprochementDetail.php` | Retirer blocs Don/Cotisation |
| `app/Livewire/Dashboard.php` | Remplacer `Don::forExercice()` et cotisations |
| `app/Http/Controllers/RapprochementPdfController.php` | Retirer blocs Don/Cotisation |
| `routes/web.php` | Garder routes, passer sousCategorieFilter |
| `resources/views/layouts/app.blade.php` | Retirer `<livewire:don-form />`, `<livewire:cotisation-form />` |
| `resources/views/livewire/transaction-universelle.blade.php` | Retirer boutons/badges don/cotisation |
| `resources/views/livewire/rapprochement-detail.blade.php` | Retirer badges don/cotisation |
| `resources/views/livewire/dashboard.blade.php` | Adapter sections dons/cotisations |
| `resources/views/dons/index.blade.php` | Utiliser `sousCategorieFilter="pour_dons"` |
| `resources/views/cotisations/index.blade.php` | Utiliser `sousCategorieFilter="pour_cotisations"` |

### Files to CREATE

| File | Purpose |
|---|---|
| `database/migrations/2026_03_22_200001_drop_dons_and_cotisations_tables.php` | DROP TABLE |
| `tests/Feature/Lot2/DropDonsCotisationsTest.php` | Vérifie que les tables sont droppées |
| `tests/Feature/Lot2/SoldeServiceUnifiedTest.php` | Vérifie SoldeService sans don/cotisation |
| `tests/Feature/Lot2/RapprochementUnifiedTest.php` | Vérifie rapprochement sans don/cotisation |
| `tests/Feature/Lot2/RapportServiceUnifiedTest.php` | Vérifie rapport sans don/cotisation |
| `tests/Feature/Lot2/TransactionUniverselleSousCategorieFilterTest.php` | Vérifie le filtre par sous-catégorie |
| `tests/Feature/Lot2/DashboardUnifiedTest.php` | Vérifie dashboard sans don/cotisation |

---

## Tasks

### Task 1: Migration DROP TABLE + suppression modèles/factories/services

**Files:**
- Create: `database/migrations/2026_03_22_200001_drop_dons_and_cotisations_tables.php`
- Create: `tests/Feature/Lot2/DropDonsCotisationsTest.php`
- Delete: `app/Models/Don.php`, `app/Models/Cotisation.php`
- Delete: `app/Services/DonService.php`, `app/Services/CotisationService.php`
- Delete: `database/factories/DonFactory.php`, `database/factories/CotisationFactory.php`

**Contexte:** Pas de données en production. `DROP TABLE IF EXISTS` pour gérer les bases de test.

- [ ] **Step 1: Écrire le test de la migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('drops the dons table', function () {
    expect(Schema::hasTable('dons'))->toBeFalse();
});

it('drops the cotisations table', function () {
    expect(Schema::hasTable('cotisations'))->toBeFalse();
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/DropDonsCotisationsTest.php --stop-on-failure`
Expected: FAIL (les tables existent encore)

- [ ] **Step 3: Écrire la migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('dons');
        Schema::dropIfExists('cotisations');
    }

    public function down(): void
    {
        // Pas de rollback — les tables sont abandonnées définitivement.
        // Les migrations de création originales restent dans l'historique.
    }
};
```

- [ ] **Step 4: Lancer le test pour vérifier qu'il passe**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/DropDonsCotisationsTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 5: Supprimer les modèles Don et Cotisation**

Supprimer les fichiers :
- `app/Models/Don.php`
- `app/Models/Cotisation.php`

- [ ] **Step 6: Supprimer les services DonService et CotisationService**

Supprimer les fichiers :
- `app/Services/DonService.php`
- `app/Services/CotisationService.php`

- [ ] **Step 7: Supprimer les factories**

Supprimer les fichiers :
- `database/factories/DonFactory.php`
- `database/factories/CotisationFactory.php`

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(lot2): drop dons/cotisations tables, delete models/services/factories"
```

---

### Task 2: Supprimer les composants Livewire don/cotisation + vues + tests

**Files:**
- Delete: `app/Livewire/DonForm.php`, `app/Livewire/DonList.php`
- Delete: `app/Livewire/CotisationForm.php`, `app/Livewire/CotisationList.php`
- Delete: `resources/views/livewire/don-form.blade.php`, `resources/views/livewire/don-list.blade.php`
- Delete: `resources/views/livewire/cotisation-form.blade.php`, `resources/views/livewire/cotisation-list.blade.php`
- Delete: All Don/Cotisation test files (see File Structure)
- Modify: `resources/views/layouts/app.blade.php` (retirer `<livewire:don-form />` et `<livewire:cotisation-form />`)

- [ ] **Step 1: Supprimer les composants Livewire**

Supprimer :
- `app/Livewire/DonForm.php`
- `app/Livewire/DonList.php`
- `app/Livewire/CotisationForm.php`
- `app/Livewire/CotisationList.php`

- [ ] **Step 2: Supprimer les vues Livewire**

Supprimer :
- `resources/views/livewire/don-form.blade.php`
- `resources/views/livewire/don-list.blade.php`
- `resources/views/livewire/cotisation-form.blade.php`
- `resources/views/livewire/cotisation-list.blade.php`

- [ ] **Step 3: Retirer les formulaires modaux du layout**

Dans `resources/views/layouts/app.blade.php`, supprimer les lignes 350-351 :
```php
    <livewire:don-form />
    <livewire:cotisation-form />
```

- [ ] **Step 4: Supprimer tous les tests Don/Cotisation**

Supprimer :
- `tests/Feature/DonTest.php`
- `tests/Feature/CotisationTest.php`
- `tests/Feature/Livewire/DonFormTest.php`
- `tests/Feature/Livewire/DonListTest.php`
- `tests/Feature/Livewire/CotisationListTest.php`
- `tests/Livewire/DonFormTest.php`
- `tests/Livewire/DonListTest.php`
- `tests/Livewire/CotisationFormTest.php`
- `tests/Livewire/CotisationFormOpenForTiersTest.php`
- `tests/Feature/Migrations/FinalizeDonsTiersIdTest.php`
- `tests/Feature/Migrations/FinalizeCotisationsTiersIdTest.php`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(lot2): delete Don/Cotisation livewire components, views, and tests"
```

---

### Task 3: Nettoyer les relations don/cotisation dans les modèles

**Files:**
- Modify: `app/Models/Tiers.php:50-58` — retirer `dons()` et `cotisations()`
- Modify: `app/Models/CompteBancaire.php:51-58` — retirer `dons()` et `cotisations()`
- Modify: `app/Models/RapprochementBancaire.php:67-75` — retirer `dons()` et `cotisations()`
- Modify: `app/Models/Operation.php:59-62` — retirer `dons()`
- Modify: `app/Models/User.php:49-52` — retirer `dons()`
- Modify: `app/Services/TiersService.php:29` — mettre à jour commentaire

**Contexte:** Ces relations référencent les modèles Don et Cotisation supprimés en Task 1. Les imports `use` associés doivent aussi être retirés.

- [ ] **Step 1: Nettoyer Tiers.php**

Dans `app/Models/Tiers.php` :
- Retirer l'import `use App\Models\Don;`
- Retirer l'import `use App\Models\Cotisation;`
- Supprimer les méthodes `dons()` (lignes 50-53) et `cotisations()` (lignes 55-58)

- [ ] **Step 2: Nettoyer CompteBancaire.php**

Dans `app/Models/CompteBancaire.php` :
- Retirer l'import `use App\Models\Don;`
- Retirer l'import `use App\Models\Cotisation;`
- Supprimer les méthodes `dons()` (lignes 51-54) et `cotisations()` (lignes 56-59)

- [ ] **Step 3: Nettoyer RapprochementBancaire.php**

Dans `app/Models/RapprochementBancaire.php` :
- Retirer l'import `use App\Models\Don;`
- Retirer l'import `use App\Models\Cotisation;`
- Supprimer les méthodes `dons()` (lignes 67-70) et `cotisations()` (lignes 72-75)

- [ ] **Step 4: Nettoyer Operation.php**

Dans `app/Models/Operation.php` :
- Retirer l'import `use App\Models\Don;`
- Supprimer la méthode `dons()` (lignes 59-62)

- [ ] **Step 5: Nettoyer User.php**

Dans `app/Models/User.php` :
- Retirer l'import `use App\Models\Don;`
- Supprimer la méthode `dons()` (lignes 49-52)

- [ ] **Step 6: Mettre à jour le commentaire dans TiersService.php**

Dans `app/Services/TiersService.php`, ligne 29, remplacer le commentaire :
```php
// Plan B ajoutera ici la vérification des FK (dons, cotisations, depenses, recettes)
```
par :
```php
// Plan B ajoutera ici la vérification des FK (transactions)
```

- [ ] **Step 7: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS (les tests qui utilisaient Don/Cotisation ont été supprimés en Task 2)

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(lot2): remove dons/cotisations relations from models"
```

---

### Task 4: Adapter SoldeService

**Files:**
- Modify: `app/Services/SoldeService.php`
- Create: `tests/Feature/Lot2/SoldeServiceUnifiedTest.php`

**Contexte:** `SoldeService::solde()` inclut actuellement `$compte->cotisations()->sum()` dans les entrées et `$compte->dons()->sum()` dans les sorties. Ces branches doivent être retirées car toutes les écritures (y compris dons et cotisations) passent désormais par `transactions` (type `recette`) dont le montant est déjà inclus dans `$compte->recettes()->sum('montant_total')`.

**Note importante :** Les dons étaient comptés en **sorties** dans SoldeService (ce qui est incorrect pour un don reçu). Avec l'unification, les dons deviennent des recettes (type `recette`) donc `montant_total` positif, inclus dans les entrées via `recettes()`. La suppression est correcte.

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Services\SoldeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('computes solde from transactions only without don/cotisation tables', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial' => 1000.00,
        'date_solde_initial' => '2025-09-01',
    ]);

    // Recette (type=recette) — couvre aussi les anciens dons/cotisations
    Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 200.00,
        'date' => '2025-10-01',
    ]);

    // Dépense
    Transaction::factory()->asDepense()->create([
        'compte_id' => $compte->id,
        'montant_total' => 50.00,
        'date' => '2025-10-15',
    ]);

    $solde = app(SoldeService::class)->solde($compte);

    // 1000 + 200 - 50 = 1150
    expect($solde)->toBe(1150.00);
});

it('excludes transactions before date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial' => 500.00,
        'date_solde_initial' => '2025-10-01',
    ]);

    Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 100.00,
        'date' => '2025-09-15', // avant date_solde_initial
    ]);

    Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 300.00,
        'date' => '2025-11-01', // après
    ]);

    $solde = app(SoldeService::class)->solde($compte);

    // 500 + 300 = 800 (la recette du 15/09 est exclue)
    expect($solde)->toBe(800.00);
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/SoldeServiceUnifiedTest.php --stop-on-failure`
Expected: FAIL — le SoldeService appelle encore `$compte->dons()` et `$compte->cotisations()` qui n'existent plus (relations supprimées en Task 3)

- [ ] **Step 3: Adapter SoldeService.php**

Remplacer le contenu de `app/Services/SoldeService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\VirementInterne;

final class SoldeService
{
    /**
     * Compute the current real balance of a bank account.
     *
     * Starts from solde_initial (at date_solde_initial) and adds all inflows
     * (recettes, virements received) and subtracts all outflows
     * (depenses, virements sent) since that date. Soft-deleted records
     * are automatically excluded via Eloquent global scopes.
     */
    public function solde(CompteBancaire $compte): float
    {
        $dateRef = $compte->date_solde_initial?->toDateString() ?? '1900-01-01';

        $entrees =
            (float) $compte->recettes()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        $sorties =
            (float) $compte->depenses()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) VirementInterne::where('compte_source_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        return round((float) $compte->solde_initial + $entrees - $sorties, 2);
    }
}
```

- [ ] **Step 4: Relancer le test**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/SoldeServiceUnifiedTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(lot2): simplify SoldeService — remove don/cotisation branches"
```

---

### Task 5: Adapter RapprochementBancaireService + RapprochementDetail + RapprochementPdfController

**Files:**
- Modify: `app/Services/RapprochementBancaireService.php`
- Modify: `app/Livewire/RapprochementDetail.php`
- Modify: `app/Http/Controllers/RapprochementPdfController.php`
- Modify: `resources/views/livewire/rapprochement-detail.blade.php`
- Create: `tests/Feature/Lot2/RapprochementUnifiedTest.php`

**Contexte :** Le service de rapprochement et ses consommateurs (détail Livewire, PDF) effectuent des requêtes séparées sur Don et Cotisation. Après unification, les dons et cotisations sont des Transactions de type `recette` — les requêtes existantes sur `Transaction` les incluent déjà. Il suffit de retirer les blocs Don/Cotisation.

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\VirementInterne;
use App\Services\RapprochementBancaireService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates solde pointage without don/cotisation tables', function () {
    $compte = CompteBancaire::factory()->create(['solde_initial' => 1000.00]);
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'solde_ouverture' => 1000.00,
        'solde_fin' => 1200.00,
    ]);

    // Une recette pointée (couvre l'ancien cas don/cotisation)
    Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $service = app(RapprochementBancaireService::class);
    $solde = $service->calculerSoldePointage($rapprochement);

    // 1000 + 200 = 1200
    expect($solde)->toBe(1200.00);
});

it('toggle only accepts depense, recette, virement types', function () {
    $compte = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
    ]);

    $service = app(RapprochementBancaireService::class);

    expect(fn () => $service->toggleTransaction($rapprochement, 'don', 1))
        ->toThrow(\InvalidArgumentException::class);

    expect(fn () => $service->toggleTransaction($rapprochement, 'cotisation', 1))
        ->toThrow(\InvalidArgumentException::class);
});

it('supprimer resets only transactions and virements', function () {
    $compte = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
    ]);

    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $service = app(RapprochementBancaireService::class);
    $service->supprimer($rapprochement);

    expect(Transaction::find($tx->id)->rapprochement_id)->toBeNull();
    expect(Transaction::find($tx->id)->pointe)->toBeFalse();
    expect(RapprochementBancaire::find($rapprochement->id))->toBeNull();
});
```

- [ ] **Step 2: Lancer le test**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/RapprochementUnifiedTest.php --stop-on-failure`
Expected: Le test `toggle only accepts` échoue car `don`/`cotisation` sont encore acceptés.

- [ ] **Step 3: Adapter RapprochementBancaireService.php**

Dans `app/Services/RapprochementBancaireService.php` :

1. Retirer les imports `use App\Models\Cotisation;` et `use App\Models\Don;`

2. `calculerSoldePointage()` — retirer les 2 lignes Don/Cotisation (lignes 71-72) :
```php
// SUPPRIMER :
$solde += (float) Don::where('rapprochement_id', $rapprochement->id)->sum('montant');
$solde += (float) Cotisation::where('rapprochement_id', $rapprochement->id)->sum('montant');
```

3. `toggleTransaction()` — dans le match des modèles (lignes 105-109 et 123-127), retirer les cas `don` et `cotisation` :
```php
// AVANT :
$model = match ($type) {
    'depense', 'recette' => Transaction::findOrFail($id),
    'don' => Don::findOrFail($id),
    'cotisation' => Cotisation::findOrFail($id),
    default => throw new \InvalidArgumentException("Type de transaction inconnu : {$type}"),
};
// APRÈS :
$model = match ($type) {
    'depense', 'recette' => Transaction::findOrFail($id),
    default => throw new \InvalidArgumentException("Type de transaction inconnu : {$type}"),
};
```
Faire ce remplacement aux **deux** endroits (lignes ~105 et ~123).

4. `supprimer()` — retirer les blocs Don et Cotisation (lignes 172-176) :
```php
// SUPPRIMER :
Don::where('rapprochement_id', $id)
    ->update(['rapprochement_id' => null, 'pointe' => false]);

Cotisation::where('rapprochement_id', $id)
    ->update(['rapprochement_id' => null, 'pointe' => false]);
```

- [ ] **Step 4: Adapter RapprochementDetail.php**

Dans `app/Livewire/RapprochementDetail.php` :

1. Retirer les imports `use App\Models\Cotisation;` et `use App\Models\Don;`
2. Supprimer entièrement le bloc "Dons" (lignes 162-189) et le bloc "Cotisations" (lignes 191-216)

- [ ] **Step 5: Adapter RapprochementPdfController.php**

Dans `app/Http/Controllers/RapprochementPdfController.php` :

1. Retirer les imports `use App\Models\Cotisation;` et `use App\Models\Don;`
2. Dans `collectTransactions()`, supprimer le bloc Don (lignes 92-108) et le bloc Cotisation (lignes 110-124)

- [ ] **Step 6: Adapter rapprochement-detail.blade.php**

Dans `resources/views/livewire/rapprochement-detail.blade.php`, retirer les `@case('don')` et `@case('cotisation')` (lignes 170-171) du switch de badges.

- [ ] **Step 7: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/RapprochementUnifiedTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(lot2): remove don/cotisation from rapprochement service, detail, and PDF"
```

---

### Task 6: Adapter TransactionUniverselleService + TransactionCompteService

**Files:**
- Modify: `app/Services/TransactionUniverselleService.php`
- Modify: `app/Services/TransactionCompteService.php`

**Contexte:** Les deux services construisent des UNION SQL incluant des branches `dons` et `cotisations`. Ces branches sont supprimées car toutes les écritures sont désormais dans `transactions`.

- [ ] **Step 1: Adapter TransactionUniverselleService.php**

Dans `app/Services/TransactionUniverselleService.php` :

1. Retirer la mention de `'don','cotisation'` du docblock du paramètre `$types` (ligne 15). Le nouveau docblock :
```php
* @param  array<string>|null  $types  null=all; subset of ['depense','recette','virement']
```

2. Dans `buildUnion()`, retirer les entrées `don` et `cotisation` du tableau `$include` (lignes 98-99) :
```php
// SUPPRIMER :
'don' => $types === null || in_array('don', $types, true),
'cotisation' => $types === null || in_array('cotisation', $types, true),
```

3. Retirer les blocs conditionnels qui incluent les branches don/cotisation (lignes 110-114) :
```php
// SUPPRIMER :
if ($include['don']) {
    $queries[] = $this->brancheDon($compteId, $tiersId, $dateDebut, $dateFin);
}
if ($include['cotisation']) {
    $queries[] = $this->brancheCotisation($compteId, $tiersId, $dateDebut, $dateFin);
}
```

4. Supprimer entièrement les méthodes `brancheDon()` (lignes 215-249) et `brancheCotisation()` (lignes 251-285).

- [ ] **Step 2: Adapter TransactionCompteService.php**

Dans `app/Services/TransactionCompteService.php` :

1. Dans `buildUnion()`, supprimer les blocs `$dons` (lignes 95-102) et `$cotisations` (lignes 104-111).

2. Adapter le return pour ne plus inclure `$dons` et `$cotisations` dans le union (lignes 131-135) :
```php
// AVANT :
return $transactions
    ->unionAll($dons)
    ->unionAll($cotisations)
    ->unionAll($virementsSource)
    ->unionAll($virementsDestination);

// APRÈS :
return $transactions
    ->unionAll($virementsSource)
    ->unionAll($virementsDestination);
```

- [ ] **Step 3: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor(lot2): remove don/cotisation UNION branches from transaction services"
```

---

### Task 7: Adapter TransactionUniverselle + TransactionCompteList + vues

**Files:**
- Modify: `app/Livewire/TransactionUniverselle.php`
- Modify: `app/Livewire/TransactionCompteList.php`
- Modify: `resources/views/livewire/transaction-universelle.blade.php`
- Create: `tests/Feature/Lot2/TransactionUniverselleSousCategorieFilterTest.php`

**Contexte :** TransactionUniverselle doit :
1. Retirer les types `don`/`cotisation` des types disponibles
2. Ajouter une prop `$sousCategorieFilter` qui filtre les transactions ayant au moins une TransactionLigne dont la sous-catégorie porte le flag correspondant
3. Passer ce filtre au service

- [ ] **Step 1: Écrire le test pour sousCategorieFilter**

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('filters transactions by sous-categorie pour_dons flag', function () {
    $compte = CompteBancaire::factory()->create();
    $scDon = SousCategorie::factory()->pourDons()->create();
    $scAutre = SousCategorie::factory()->create(['pour_dons' => false, 'pour_cotisations' => false]);

    // Transaction avec ligne don
    $txDon = Transaction::factory()->asRecette()->create(['compte_id' => $compte->id]);
    TransactionLigne::factory()->create([
        'transaction_id' => $txDon->id,
        'sous_categorie_id' => $scDon->id,
        'montant' => 100,
    ]);

    // Transaction avec ligne autre
    $txAutre = Transaction::factory()->asRecette()->create(['compte_id' => $compte->id]);
    TransactionLigne::factory()->create([
        'transaction_id' => $txAutre->id,
        'sous_categorie_id' => $scAutre->id,
        'montant' => 50,
    ]);

    Livewire::test(\App\Livewire\TransactionUniverselle::class, [
        'sousCategorieFilter' => 'pour_dons',
    ])
        ->assertSee($txDon->libelle)
        ->assertDontSee($txAutre->libelle);
});

it('filters transactions by sous-categorie pour_cotisations flag', function () {
    $compte = CompteBancaire::factory()->create();
    $scCot = SousCategorie::factory()->pourCotisations()->create();
    $scAutre = SousCategorie::factory()->create(['pour_dons' => false, 'pour_cotisations' => false]);

    $txCot = Transaction::factory()->asRecette()->create(['compte_id' => $compte->id]);
    TransactionLigne::factory()->create([
        'transaction_id' => $txCot->id,
        'sous_categorie_id' => $scCot->id,
        'montant' => 80,
    ]);

    $txAutre = Transaction::factory()->asRecette()->create(['compte_id' => $compte->id]);
    TransactionLigne::factory()->create([
        'transaction_id' => $txAutre->id,
        'sous_categorie_id' => $scAutre->id,
        'montant' => 30,
    ]);

    Livewire::test(\App\Livewire\TransactionUniverselle::class, [
        'sousCategorieFilter' => 'pour_cotisations',
    ])
        ->assertSee($txCot->libelle)
        ->assertDontSee($txAutre->libelle);
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/TransactionUniverselleSousCategorieFilterTest.php --stop-on-failure`
Expected: FAIL (la prop `sousCategorieFilter` n'existe pas encore)

- [ ] **Step 3: Ajouter sousCategorieFilter à TransactionUniverselleService**

Dans `app/Services/TransactionUniverselleService.php`, ajouter un paramètre `?string $sousCategorieFilter = null` à la méthode `paginate()` et à `buildUnion()`.

Dans `paginate()`, ajouter le paramètre après `$pointe` :
```php
?string $sousCategorieFilter = null,
```

Passer ce paramètre à `buildUnion()` et aux deux appels internes.

Dans `buildUnion()`, ajouter le paramètre et le passer à `brancheDepense()` et `brancheRecette()`.

Dans `brancheDepense()` et `brancheRecette()`, ajouter le paramètre `?string $sousCategorieFilter = null` et ajouter le filtre :
```php
->when($sousCategorieFilter, fn ($q) => $q->whereExists(function ($sub) use ($sousCategorieFilter) {
    $sub->select(DB::raw(1))
        ->from('transaction_lignes as tl_filter')
        ->join('sous_categories as sc_filter', 'sc_filter.id', '=', 'tl_filter.sous_categorie_id')
        ->whereColumn('tl_filter.transaction_id', 'tx.id')
        ->where("sc_filter.{$sousCategorieFilter}", true);
}))
```

Dans `buildUnion()`, quand `$sousCategorieFilter` n'est pas null, **exclure les virements** car les vues filtrées (Dons, Cotisations) ne montrent que des recettes :
```php
if ($include['virement'] && $sousCategorieFilter === null) {
    $queries[] = $this->brancheVirementSortant($compteId, $tiersId, $dateDebut, $dateFin);
    $queries[] = $this->brancheVirementEntrant($compteId, $tiersId, $dateDebut, $dateFin);
}
```

- [ ] **Step 4: Ajouter la prop sousCategorieFilter à TransactionUniverselle.php**

Dans `app/Livewire/TransactionUniverselle.php` :

1. Ajouter la propriété :
```php
public ?string $sousCategorieFilter = null; // 'pour_dons', 'pour_cotisations', etc.
```

2. Dans `mount()`, ajouter le paramètre `?string $sousCategorieFilter = null` et l'assigner.

3. Retirer les imports `use App\Models\Don;`, `use App\Models\Cotisation;`, `use App\Services\DonService;`, `use App\Services\CotisationService;`.

4. Dans `toggleType()`, remplacer la liste par défaut (ligne 139) :
```php
// AVANT :
$allTypes = $this->lockedTypes ?? ['depense', 'recette', 'don', 'cotisation', 'virement'];
// APRÈS :
$allTypes = $this->lockedTypes ?? ['depense', 'recette', 'virement'];
```

5. Dans `openEdit()`, retirer les cas `don` et `cotisation` du match (lignes 223-224) et de `$allowed` (ligne 217).

6. Supprimer `fetchDonDetail()` (lignes 269-282) et `fetchCotisationDetail()` (lignes 284-295).

7. Dans `fetchDetail()`, retirer les cas `don` et `cotisation` du match (lignes 244-245).

8. Supprimer `deleteDon()` (lignes 326-333) et `deleteCotisation()` (lignes 335-342).

9. Dans `deleteRow()`, retirer les cas `don` et `cotisation` du match et de `$allowed`.

10. Supprimer les event listeners `onDonSaved()` et `onCotisationSaved()` (lignes 357-361).

11. Dans `render()`, passer `sousCategorieFilter` au service :
```php
$result = app(TransactionUniverselleService::class)->paginate(
    // ... paramètres existants ...
    sousCategorieFilter: $this->sousCategorieFilter,
);
```

12. Modifier `availableTypes` dans le retour du `render()` :
```php
'availableTypes' => $this->lockedTypes ?? ['depense', 'recette', 'virement'],
'sousCategorieFilter' => $this->sousCategorieFilter,
```

13. Quand `$sousCategorieFilter` est défini, les boutons toggle Type ne doivent pas s'afficher (spec ligne 319 : "Les boutons toggle Type ne s'affichent pas quand ce filtre est actif"). Ceci est géré côté vue (Step 6).

- [ ] **Step 5: Adapter TransactionCompteList.php**

Dans `app/Livewire/TransactionCompteList.php` :

1. Retirer les imports : `use App\Models\Cotisation;`, `use App\Models\Don;`, `use App\Services\CotisationService;`, `use App\Services\DonService;`

2. Dans `deleteTransaction()` (le match principal), retirer les cas `'don'` et `'cotisation'` (lignes 84-85) :
```php
// AVANT :
match ($sourceType) {
    'depense', 'recette' => $this->deleteTransactionGeneric($id),
    'don' => $this->deleteDon($id),
    'cotisation' => $this->deleteCotisation($id),
    'virement_sortant', 'virement_entrant' => $this->deleteVirement($id),
    default => null,
};
// APRÈS :
match ($sourceType) {
    'depense', 'recette' => $this->deleteTransactionGeneric($id),
    'virement_sortant', 'virement_entrant' => $this->deleteVirement($id),
    default => null,
};
```

3. Supprimer les méthodes `deleteDon()` (lignes 104-111) et `deleteCotisation()` (lignes 113-119).

4. Dans `redirectToEdit()`, retirer le cas `'don'` (ligne 135) et `'cotisation'` (lignes 137). Remplacer le match :
```php
$url = match ($sourceType) {
    'depense', 'recette' => url('/transactions').'?edit='.$id,
    'virement_sortant', 'virement_entrant' => route('virements.index').'?edit='.$id,
    default => route('dashboard'),
};
```

5. Supprimer la méthode `buildCotisationEditUrl()` (lignes 144-152).

- [ ] **Step 6: Adapter transaction-universelle.blade.php**

Dans `resources/views/livewire/transaction-universelle.blade.php` :

1. Retirer `'don'` et `'cotisation'` des tableaux de mapping dispatch (lignes 16-17), labels (lignes 29-30), badges (lignes 52-53, 169-170, 459-460).

2. Retirer les blocs `@if(in_array('don', $availableTypes))` et `@if(in_array('cotisation', $availableTypes))` des boutons "Nouveau" (lignes 95-103, 139-147).

3. Dans la section de détail expandée, retirer le bloc `{{-- Don ou Cotisation --}}` (ligne 587 et suivantes).

4. **Masquer les boutons toggle Type quand `sousCategorieFilter` est actif** (spec : "Les boutons toggle Type ne s'affichent pas quand ce filtre est actif"). Envelopper la barre de toggle Type dans `@if(!$sousCategorieFilter)` ... `@endif`. Quand le filtre est actif, toutes les transactions affichées sont de type recette, le toggle est inutile.

5. **Masquer les boutons "Nouveau don" et "Nouvelle cotisation"** dans les dropdowns "Nouveau" — ils n'existent plus (déjà traité au point 2). Quand `sousCategorieFilter` est actif, le bouton "Nouveau" ouvre directement le TransactionForm standard (type recette).

- [ ] **Step 7: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/TransactionUniverselleSousCategorieFilterTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(lot2): add sousCategorieFilter, remove don/cotisation from TransactionUniverselle"
```

---

### Task 8: Adapter RapportService

**Files:**
- Modify: `app/Services/RapportService.php`
- Create: `tests/Feature/Lot2/RapportServiceUnifiedTest.php`

**Contexte :** `RapportService::fetchProduitsRows()` agrège séparément les recettes, les dons et les cotisations. Après unification, les dons et cotisations sont des transactions recette — ils sont déjà couverts par `accumulerRecettesResolues()`. Les blocs Don et Cotisation doivent être supprimés. Idem pour `fetchProduitsSeancesRows()`.

**Point d'attention — cotisations et exercice :** L'ancien code filtrait les cotisations par `exercice` (pas par date). Après unification, les cotisations sont des TransactionLignes avec un champ `exercice` nullable. Le filtre par `transaction.date` (via `accumulerRecettesResolues`) est suffisant pour le compte de résultat global. Le champ `exercice` sur `transaction_lignes` sera utilisé par les requêtes spécifiques "cotisations par exercice" dans le lot 3+.

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RapportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes don-type recettes in produits', function () {
    $cat = Categorie::factory()->create(['type' => TypeCategorie::Recette]);
    $scDon = SousCategorie::factory()->pourDons()->create(['categorie_id' => $cat->id, 'nom' => 'Dons manuels']);

    $compte = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => '2025-11-15',
        'montant_total' => 150.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scDon->id,
        'montant' => 150.00,
    ]);

    $result = app(RapportService::class)->compteDeResultat(2025);

    $produits = collect($result['produits']);
    $found = $produits->flatMap(fn ($cat) => $cat['sous_categories'])
        ->firstWhere('label', 'Dons manuels');

    expect($found)->not->toBeNull();
    expect($found['montant_n'])->toBe(150.00);
});

it('includes cotisation-type recettes in produits', function () {
    $cat = Categorie::factory()->create(['type' => TypeCategorie::Recette]);
    $scCot = SousCategorie::factory()->pourCotisations()->create(['categorie_id' => $cat->id, 'nom' => 'Cotisations']);

    $compte = CompteBancaire::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 80.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scCot->id,
        'montant' => 80.00,
    ]);

    $result = app(RapportService::class)->compteDeResultat(2025);

    $produits = collect($result['produits']);
    $found = $produits->flatMap(fn ($cat) => $cat['sous_categories'])
        ->firstWhere('label', 'Cotisations');

    expect($found)->not->toBeNull();
    expect($found['montant_n'])->toBe(80.00);
});
```

- [ ] **Step 2: Lancer le test**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/RapportServiceUnifiedTest.php --stop-on-failure`
Expected: PASS (les recettes sont déjà agrégées via `accumulerRecettesResolues`)

- [ ] **Step 3: Adapter RapportService.php**

Dans `app/Services/RapportService.php` :

1. Retirer les imports `use App\Models\Cotisation;` et `use App\Models\Don;`

2. Dans `fetchProduitsRows()` (lignes 209-264), supprimer le bloc "Dons" (lignes 237-249) et le bloc "Cotisations" (lignes 251-261). La méthode ne conserve que l'appel à `accumulerRecettesResolues()` :

```php
private function fetchProduitsRows(string $start, string $end, int $exercice, ?array $operationIds = null): Collection
{
    $map = [];
    $this->accumulerRecettesResolues($start, $end, $operationIds, $map);

    return collect(array_values($map))->map(fn ($row) => (object) $row);
}
```

Note : le paramètre `$exercice` n'est plus utilisé dans cette méthode mais reste dans la signature car il est passé par les appelants. Il pourra être nettoyé dans un refactoring ultérieur.

3. Dans `fetchProduitsSeancesRows()` (lignes 456-505), supprimer le bloc "Dons par séance" (lignes 486-495) :

```php
private function fetchProduitsSeancesRows(string $start, string $end, array $operationIds): Collection
{
    /** @var array<int, array<int, array{...}>> */
    $map = [];

    // Recettes par séance (avec résolution des affectations)
    $this->accumulerRecettesSeancesResolues($start, $end, $operationIds, $map);

    $flat = [];
    foreach ($map as $seanceMap) {
        foreach ($seanceMap as $entry) {
            $flat[] = $entry;
        }
    }

    return collect($flat)->map(fn ($row) => (object) $row);
}
```

- [ ] **Step 4: Relancer le test**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/RapportServiceUnifiedTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(lot2): remove don/cotisation queries from RapportService"
```

---

### Task 9: Adapter Dashboard

**Files:**
- Modify: `app/Livewire/Dashboard.php`
- Modify: `resources/views/livewire/dashboard.blade.php`
- Create: `tests/Feature/Lot2/DashboardUnifiedTest.php`

**Contexte :** Le dashboard affiche "Derniers dons" via `Don::forExercice()` et "Membres sans cotisation" via `Tiers::whereHas('cotisations')`. Ces requêtes doivent être remplacées par des requêtes sur `Transaction` + `TransactionLigne` jointes aux sous-catégories.

**Changement de comportement intentionnel** pour "Membres sans cotisation" : l'ancien code ne montrait que les membres ayant cotisé au moins une fois dans le passé mais pas cette année (`whereHas('cotisations').whereDoesntHave(...)` ). Le nouveau code montre **tous les tiers de type membre** sans cotisation pour l'exercice courant (`Tiers::where('type', 'membre')->whereDoesntHave(...)`). C'est plus utile car on voit aussi les nouveaux membres qui n'ont jamais cotisé.

- [ ] **Step 1: Écrire le test**

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows recent donations from transaction_lignes with pour_dons sous-categorie', function () {
    $compte = CompteBancaire::factory()->create();
    $scDon = SousCategorie::factory()->pourDons()->create();
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);

    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'tiers_id' => $tiers->id,
        'date' => now()->subDays(5),
        'montant_total' => 100.00,
        'libelle' => 'Don test',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scDon->id,
        'montant' => 100.00,
    ]);

    Livewire::test(\App\Livewire\Dashboard::class)
        ->assertSee('Don test');
});

it('shows members without cotisation for current exercice', function () {
    $compte = CompteBancaire::factory()->create();
    $scCot = SousCategorie::factory()->pourCotisations()->create();

    // Membre avec cotisation
    $membreAvec = Tiers::factory()->membre()->create(['nom' => 'Avec', 'prenom' => 'Cot']);
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $compte->id,
        'tiers_id' => $membreAvec->id,
        'date' => now(),
        'montant_total' => 50.00,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $scCot->id,
        'montant' => 50.00,
        'exercice' => app(\App\Services\ExerciceService::class)->current(),
    ]);

    // Membre sans cotisation
    $membreSans = Tiers::factory()->membre()->create(['nom' => 'Sans', 'prenom' => 'Cot']);

    Livewire::test(\App\Livewire\Dashboard::class)
        ->assertDontSee('Avec Cot')
        ->assertSee('Sans');
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/DashboardUnifiedTest.php --stop-on-failure`
Expected: FAIL (Dashboard utilise encore Don::forExercice())

- [ ] **Step 3: Adapter Dashboard.php**

Remplacer le contenu de `app/Livewire/Dashboard.php` :

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\BudgetLine;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\BudgetService;
use App\Services\ExerciceService;
use App\Services\SoldeService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class Dashboard extends Component
{
    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $budgetService = app(BudgetService::class);
        $exercice = $exerciceService->current();

        $range = $exerciceService->dateRange($exercice);
        $startDate = $range['start']->toDateString();
        $endDate = $range['end']->toDateString();

        // Solde général
        $totalRecettes = (float) Transaction::where('type', 'recette')->forExercice($exercice)->sum('montant_total');
        $totalDepenses = (float) Transaction::where('type', 'depense')->forExercice($exercice)->sum('montant_total');
        $soldeGeneral = $totalRecettes - $totalDepenses;

        // Budget résumé
        $budgetLines = BudgetLine::forExercice($exercice)
            ->with('sousCategorie.categorie')
            ->get();

        $totalPrevu = (float) $budgetLines->sum('montant_prevu');
        $totalRealise = 0.0;
        foreach ($budgetLines as $line) {
            $totalRealise += $budgetService->realise($line->sous_categorie_id, $exercice);
        }

        // Dernières dépenses
        $dernieresDepenses = Transaction::where('type', 'depense')->forExercice($exercice)
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Dernières recettes
        $dernieresRecettes = Transaction::where('type', 'recette')->forExercice($exercice)
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Derniers dons — transactions ayant au moins une ligne avec sous-cat pour_dons
        $donSousCategorieIds = SousCategorie::where('pour_dons', true)->pluck('id');
        $derniersDons = Transaction::where('type', 'recette')
            ->forExercice($exercice)
            ->whereHas('lignes', fn ($q) => $q->whereIn('sous_categorie_id', $donSousCategorieIds))
            ->with('tiers')
            ->latest('date')->latest('id')
            ->take(5)
            ->get();

        // Membres sans cotisation pour l'exercice courant
        // Un membre a cotisé s'il est tiers d'une transaction ayant une TransactionLigne
        // avec sous-cat pour_cotisations=true et exercice = exercice courant
        $cotSousCategorieIds = SousCategorie::where('pour_cotisations', true)->pluck('id');
        $membresSansCotisation = Tiers::where('type', 'membre')
            ->whereDoesntHave('transactions', function ($q) use ($cotSousCategorieIds, $exercice) {
                $q->whereHas('lignes', function ($lq) use ($cotSousCategorieIds, $exercice) {
                    $lq->whereIn('sous_categorie_id', $cotSousCategorieIds)
                        ->where('exercice', $exercice);
                });
            })
            ->orderBy('nom')
            ->get();

        // Comptes bancaires avec soldes courants
        $soldeService = app(SoldeService::class);
        $comptesAvecSolde = CompteBancaire::orderBy('nom')->get()
            ->map(fn (CompteBancaire $c) => [
                'compte' => $c,
                'solde' => $soldeService->solde($c),
            ]);

        return view('livewire.dashboard', [
            'soldeGeneral' => $soldeGeneral,
            'totalRecettes' => $totalRecettes,
            'totalDepenses' => $totalDepenses,
            'totalPrevu' => $totalPrevu,
            'totalRealise' => $totalRealise,
            'dernieresDepenses' => $dernieresDepenses,
            'dernieresRecettes' => $dernieresRecettes,
            'derniersDons' => $derniersDons,
            'membresSansCotisation' => $membresSansCotisation,
            'comptesAvecSolde' => $comptesAvecSolde,
        ]);
    }
}
```

- [ ] **Step 4: Adapter dashboard.blade.php**

Dans `resources/views/livewire/dashboard.blade.php`, mettre à jour la section "Derniers dons" (ligne 157-190). Les données passent par `$derniersDons` qui sont maintenant des Transaction — adapter l'affichage :

Remplacer le bloc des dons (lignes 175-180) par :
```blade
@forelse ($derniersDons as $don)
    <tr>
        <td class="small text-nowrap">{{ $don->date->format('d/m/Y') }}</td>
        <td class="small">{{ $don->tiers ? $don->tiers->displayName() : 'Anonyme' }}</td>
        <td class="text-end small fw-semibold text-nowrap">{{ number_format((float) $don->montant_total, 2, ',', ' ') }} &euro;</td>
    </tr>
@empty
```

Note : le changement principal est `$don->montant` → `$don->montant_total` car c'est maintenant un objet Transaction.

La section "Membres sans cotisation" reste identique en vue (même structure de données passée : collection de Tiers).

- [ ] **Step 5: Relancer le test**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Lot2/DashboardUnifiedTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor(lot2): adapt Dashboard to unified model — dons/cotisations via transaction_lignes"
```

---

### Task 10: Adapter routes + navigation + page views

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/dons/index.blade.php`
- Modify: `resources/views/cotisations/index.blade.php`

**Contexte :** Les routes `/dons` et `/cotisations` restent mais les vues passent `sousCategorieFilter` à TransactionUniverselle au lieu de `lockedTypes`.

- [ ] **Step 1: Adapter les vues dons/index et cotisations/index**

`resources/views/dons/index.blade.php` :
```blade
<x-app-layout>
    <div class="container-fluid py-3">
        <livewire:transaction-universelle
            sous-categorie-filter="pour_dons"
            page-title="Dons"
            page-title-icon="heart" />
    </div>
</x-app-layout>
```

`resources/views/cotisations/index.blade.php` :
```blade
<x-app-layout>
    <div class="container-fluid py-3">
        <livewire:transaction-universelle
            sous-categorie-filter="pour_cotisations"
            page-title="Cotisations"
            page-title-icon="people" />
    </div>
</x-app-layout>
```

Note : on utilise `sous-categorie-filter` (kebab-case dans Blade) qui mappe à la prop `$sousCategorieFilter` (camelCase dans PHP). On ne passe plus `locked-types` — le composant affiche tous les types de transaction mais filtre par sous-catégorie.

- [ ] **Step 2: Vérifier que les routes sont inchangées dans web.php**

Les routes existantes restent identiques :
```php
Route::view('/dons', 'dons.index')->name('dons.index');
Route::view('/cotisations', 'cotisations.index')->name('cotisations.index');
```

Pas de modification nécessaire dans `routes/web.php`.

- [ ] **Step 3: Lancer les tests**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --stop-on-failure`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor(lot2): update dons/cotisations pages to use sousCategorieFilter"
```

---

### Task 11: Vérification BudgetService + suite complète + Pint

**Files:**
- No new files

**Contexte :** Vérifier que `BudgetService` fonctionne sans les tables dons/cotisations (il requête déjà `transaction_lignes` directement, donc aucun changement attendu). Lancer la suite complète et Pint.

- [ ] **Step 1: Vérifier BudgetService**

Relire `app/Services/BudgetService.php` et confirmer qu'il n'a aucune référence à Don/Cotisation. Le service requête `TransactionLigne::where('sous_categorie_id', ...)` directement → aucun changement nécessaire.

- [ ] **Step 2: Chercher les références résiduelles**

Vérifier qu'il ne reste aucune référence à `Don::`, `Cotisation::`, `DonService`, `CotisationService`, `DonForm`, `CotisationForm`, `don-form`, `cotisation-form`, `DonList`, `CotisationList`, `don-list`, `cotisation-list` dans le code source PHP et Blade (hors migrations et ce plan).

Run: `grep -rn "Don::\|Cotisation::\|DonService\|CotisationService\|DonForm\|CotisationForm\|DonList\|CotisationList\|don-form\|cotisation-form\|don-list\|cotisation-list" app/ resources/views/ routes/ tests/ --include="*.php" --include="*.blade.php" | grep -v "node_modules" | grep -v "vendor"`

Expected: Aucune ligne trouvée (ou uniquement des faux positifs dans des commentaires/strings inoffensifs).

- [ ] **Step 3: Lancer Pint**

Run: `./vendor/bin/sail exec -T laravel.test ./vendor/bin/pint`

- [ ] **Step 4: Lancer migrate:fresh --seed**

Run: `./vendor/bin/sail exec -T laravel.test php artisan migrate:fresh --seed`
Expected: Succès sans erreur.

- [ ] **Step 5: Lancer la suite de tests complète**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test`
Expected: Tous les tests passent.

- [ ] **Step 6: Commit si Pint a modifié des fichiers**

```bash
git add -A
git commit -m "style(lot2): pint formatting"
```

---

## Notes pour l'implémenteur

1. **Ordre des tâches** : Les tâches 1-3 (suppression) doivent être faites en premier. Les tâches 4-10 (adaptation) sont relativement indépendantes mais doivent venir après les suppressions. La tâche 11 est la vérification finale.

2. **Tests existants** : Beaucoup de tests seront supprimés (Task 2). D'autres tests existants dans la suite qui utilisent le seeder pourraient être impactés si le seeder crée des dons/cotisations. Vérifier le seeder.

3. **`actif_dons_cotisations`** sur `CompteBancaire` : Ce champ booléen filtre les comptes visibles dans les formulaires don/cotisation. Il n'est plus utilisé après suppression des formulaires mais reste en base. Ne pas le supprimer dans ce lot (nettoyage futur).

4. **`pour_dons` / `pour_cotisations`** sur `SousCategorie` : Ces flags **restent** — ils sont réutilisés pour le filtrage dans TransactionUniverselle (sousCategorieFilter) et Dashboard.

5. **Pas de migration de données** : Le user a confirmé qu'il n'y a aucune donnée de production dans les tables dons et cotisations.
