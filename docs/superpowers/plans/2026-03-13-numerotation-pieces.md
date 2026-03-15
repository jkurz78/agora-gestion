# Numérotation des pièces comptables — Plan d'implémentation

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Assigner automatiquement un numéro de pièce séquentiel par exercice (format `2025-2026:00001`) à chaque transaction lors de sa création, et l'afficher dans toutes les vues concernées.

**Architecture:** Une table `sequences` stocke le dernier numéro assigné par exercice. `NumeroPieceService::assign()` utilise `upsert()` + `SELECT FOR UPDATE` + `UPDATE` à l'intérieur du `DB::transaction()` de chaque service métier. Une colonne `numero_piece VARCHAR(20)` est ajoutée aux 5 tables de transactions.

**Tech Stack:** Laravel 11, Pest PHP (RefreshDatabase), Livewire 4, Bootstrap 5, MySQL

---

## Task 1 : Migrations

**Files:**
- Create: `database/migrations/2026_03_13_200000_create_sequences_table.php`
- Create: `database/migrations/2026_03_13_200001_add_numero_piece_to_transactions.php`
- Create: `tests/Feature/NumeroPieceMigrationTest.php`

- [ ] **Step 1 : Écrire les tests schema (RED)**

Créer `tests/Feature/NumeroPieceMigrationTest.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('la table sequences existe avec les bonnes colonnes', function () {
    expect(Schema::hasTable('sequences'))->toBeTrue();
    expect(Schema::hasColumn('sequences', 'exercice'))->toBeTrue();
    expect(Schema::hasColumn('sequences', 'dernier_numero'))->toBeTrue();
});

it('recettes a la colonne numero_piece', function () {
    expect(Schema::hasColumn('recettes', 'numero_piece'))->toBeTrue();
});

it('depenses a la colonne numero_piece', function () {
    expect(Schema::hasColumn('depenses', 'numero_piece'))->toBeTrue();
});

it('dons a la colonne numero_piece', function () {
    expect(Schema::hasColumn('dons', 'numero_piece'))->toBeTrue();
});

it('cotisations a la colonne numero_piece', function () {
    expect(Schema::hasColumn('cotisations', 'numero_piece'))->toBeTrue();
});

it('virements_internes a la colonne numero_piece', function () {
    expect(Schema::hasColumn('virements_internes', 'numero_piece'))->toBeTrue();
});
```

- [ ] **Step 2 : Vérifier que les tests échouent**

```bash
./vendor/bin/sail artisan test --filter NumeroPieceMigrationTest
```

Attendu : 6 failures.

- [ ] **Step 3 : Créer la migration sequences**

Créer `database/migrations/2026_03_13_200000_create_sequences_table.php` :

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
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('exercice', 9)->unique();  // ex. "2025-2026"
            $table->unsignedInteger('dernier_numero')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
```

- [ ] **Step 4 : Créer la migration numero_piece**

Créer `database/migrations/2026_03_13_200001_add_numero_piece_to_transactions.php` :

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
        foreach (['recettes', 'depenses', 'dons', 'cotisations', 'virements_internes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('numero_piece', 20)->nullable()->unique()->after('id');
            });
        }
    }

    public function down(): void
    {
        foreach (['recettes', 'depenses', 'dons', 'cotisations', 'virements_internes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(["{$t->getTable()}_numero_piece_unique"]);
                $t->dropColumn('numero_piece');
            });
        }
    }
};
```

> **Note :** `$t->getTable()` peut ne pas être disponible dans une closure Blueprint. Si la migration échoue, remplacer le `down()` par 5 appels séparés avec les noms de contraintes hardcodés (ex. `recettes_numero_piece_unique`).

- [ ] **Step 5 : Appliquer les migrations**

```bash
./vendor/bin/sail artisan migrate
```

- [ ] **Step 6 : Vérifier GREEN**

```bash
./vendor/bin/sail artisan test --filter NumeroPieceMigrationTest
```

Attendu : 6 tests passent.

- [ ] **Step 7 : Commit**

```bash
git add database/migrations/2026_03_13_200000_create_sequences_table.php \
        database/migrations/2026_03_13_200001_add_numero_piece_to_transactions.php \
        tests/Feature/NumeroPieceMigrationTest.php
git commit -m "test+migrate: table sequences et colonne numero_piece sur les 5 tables de transactions"
```

---

## Task 2 : Modèles et `NumeroPieceService`

**Files:**
- Modify: `app/Models/Recette.php`
- Modify: `app/Models/Depense.php`
- Modify: `app/Models/Don.php`
- Modify: `app/Models/Cotisation.php`
- Modify: `app/Models/VirementInterne.php`
- Create: `app/Services/NumeroPieceService.php`
- Create: `tests/Feature/Services/NumeroPieceServiceTest.php`

- [ ] **Step 1 : Ajouter `numero_piece` au `$fillable` des 5 modèles**

Dans chacun des 5 modèles, ajouter `'numero_piece'` au tableau `$fillable` :

`app/Models/Recette.php` — ajouter `'numero_piece'` en première position :
```php
protected $fillable = [
    'numero_piece',
    'date', 'libelle', 'montant_total', 'mode_paiement',
    'tiers', 'reference', 'compte_id', 'pointe', 'notes',
    'saisi_par', 'rapprochement_id',
];
```

Répéter pour `Depense.php`, `Don.php`, `Cotisation.php`, `VirementInterne.php` — ajouter `'numero_piece'` au `$fillable` existant.

- [ ] **Step 2 : Écrire les tests de `NumeroPieceService` (RED)**

Créer `tests/Feature/Services/NumeroPieceServiceTest.php` :

```php
<?php

declare(strict_types=1);

use App\Services\NumeroPieceService;
use Carbon\Carbon;

beforeEach(function () {
    $this->service = app(NumeroPieceService::class);
});

it('exerciceFromDate retourne le bon exercice pour un mois >= 9', function () {
    $date = Carbon::parse('2025-09-15');
    expect($this->service->exerciceFromDate($date))->toBe('2025-2026');
});

it('exerciceFromDate retourne le bon exercice pour un mois < 9', function () {
    $date = Carbon::parse('2026-02-10');
    expect($this->service->exerciceFromDate($date))->toBe('2025-2026');
});

it('exerciceFromDate retourne le bon exercice pour août', function () {
    $date = Carbon::parse('2026-08-31');
    expect($this->service->exerciceFromDate($date))->toBe('2025-2026');
});

it('assign retourne le premier numéro de séquence formaté', function () {
    $date = Carbon::parse('2025-09-01');
    $numero = $this->service->assign($date);
    expect($numero)->toBe('2025-2026:00001');
});

it('assign incrémente la séquence au deuxième appel sur le même exercice', function () {
    $date = Carbon::parse('2025-10-15');
    $this->service->assign($date);
    $numero = $this->service->assign($date);
    expect($numero)->toBe('2025-2026:00002');
});

it('assign démarre une nouvelle séquence pour un nouvel exercice', function () {
    $this->service->assign(Carbon::parse('2025-10-01')); // 2025-2026:00001
    $numero = $this->service->assign(Carbon::parse('2026-09-01')); // nouvel exercice
    expect($numero)->toBe('2026-2027:00001');
});

it('deux appels consécutifs donnent des numéros différents', function () {
    $date = Carbon::parse('2026-01-01');
    $a = $this->service->assign($date);
    $b = $this->service->assign($date);
    expect($a)->not->toBe($b);
});
```

- [ ] **Step 3 : Vérifier que les tests échouent**

```bash
./vendor/bin/sail artisan test --filter NumeroPieceServiceTest
```

Attendu : erreur "class not found" ou failures.

- [ ] **Step 4 : Créer `NumeroPieceService`**

Créer `app/Services/NumeroPieceService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class NumeroPieceService
{
    public function assign(Carbon $date): string
    {
        $exercice = $this->exerciceFromDate($date);

        // Garantit que la ligne existe avant le SELECT FOR UPDATE
        // (upsert avec liste vide de colonnes à update = INSERT IGNORE)
        DB::table('sequences')->upsert(
            [['exercice' => $exercice, 'dernier_numero' => 0]],
            ['exercice'],
            [],
        );

        $sequence = DB::table('sequences')
            ->where('exercice', $exercice)
            ->lockForUpdate()
            ->first();

        $numero = $sequence->dernier_numero + 1;

        DB::table('sequences')
            ->where('exercice', $exercice)
            ->update(['dernier_numero' => $numero, 'updated_at' => now()]);

        return $exercice . ':' . str_pad((string) $numero, 5, '0', STR_PAD_LEFT);
    }

    public function exerciceFromDate(Carbon $date): string
    {
        $year = $date->year;
        if ($date->month >= 9) {
            return "{$year}-" . ($year + 1);
        }

        return ($year - 1) . "-{$year}";
    }
}
```

> **Important :** `assign()` doit toujours être appelé à l'intérieur d'un `DB::transaction()` pour que le rollback de la table `sequences` fonctionne correctement en cas d'échec.

- [ ] **Step 5 : Vérifier GREEN**

```bash
./vendor/bin/sail artisan test --filter NumeroPieceServiceTest
```

Attendu : 7 tests passent.

- [ ] **Step 6 : Run pint et commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    app/Models/Recette.php \
    app/Models/Depense.php \
    app/Models/Don.php \
    app/Models/Cotisation.php \
    app/Models/VirementInterne.php \
    app/Services/NumeroPieceService.php

git add app/Models/Recette.php app/Models/Depense.php app/Models/Don.php \
        app/Models/Cotisation.php app/Models/VirementInterne.php \
        app/Services/NumeroPieceService.php \
        tests/Feature/Services/NumeroPieceServiceTest.php
git commit -m "feat: NumeroPieceService + numero_piece dans les fillable des 5 modèles"
```

---

## Task 3 : Intégration dans les 5 services métier

**Files:**
- Modify: `app/Services/RecetteService.php`
- Modify: `app/Services/DepenseService.php`
- Modify: `app/Services/DonService.php`
- Modify: `app/Services/CotisationService.php`
- Modify: `app/Services/VirementInterneService.php`
- Modify: `tests/Feature/RecetteTest.php`
- Modify: `tests/Feature/DepenseTest.php`
- Modify: `tests/Feature/DonTest.php`
- Modify: `tests/Feature/CotisationTest.php`
- Modify: `tests/Feature/VirementInterneTest.php`

**Contexte :** Chaque service a déjà un `DB::transaction()` dans `create()`, sauf `CotisationService` qui n'en a pas — il faudra l'ajouter. Le `assign()` doit être appelé **à l'intérieur** de la transaction.

- [ ] **Step 1 : Écrire les tests d'intégration (RED)**

Ajouter à `tests/Feature/RecetteTest.php` :

```php
it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte = \App\Models\CompteBancaire::factory()->create();

    $recette = app(\App\Services\RecetteService::class)->create([
        'date'          => '2025-10-01',
        'libelle'       => 'Test',
        'montant_total' => 100,
        'mode_paiement' => 'virement',
        'compte_id'     => $compte->id,
    ], []);

    expect($recette->numero_piece)->not->toBeNull();
    expect($recette->numero_piece)->toStartWith('2025-2026:');
});
```

Ajouter à `tests/Feature/DepenseTest.php` : même structure que Recette.

Ajouter à `tests/Feature/DonTest.php` :
```php
it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte = \App\Models\CompteBancaire::factory()->create();
    $donateur = \App\Models\Donateur::factory()->create();

    $don = app(\App\Services\DonService::class)->create([
        'date'          => '2025-10-01',
        'montant'       => 50,
        'mode_paiement' => 'especes',
        'compte_id'     => $compte->id,
        'donateur_id'   => $donateur->id,
    ]);

    expect($don->numero_piece)->not->toBeNull();
    expect($don->numero_piece)->toStartWith('2025-2026:');
});
```

Ajouter à `tests/Feature/CotisationTest.php` :
```php
it('create assigne un numero_piece non null', function () {
    $compte = \App\Models\CompteBancaire::factory()->create();
    $membre = \App\Models\Membre::factory()->create();

    $cotisation = app(\App\Services\CotisationService::class)->create($membre, [
        'date_paiement' => '2025-10-01',
        'exercice'      => 2025,
        'montant'       => 80,
        'mode_paiement' => 'virement',
        'compte_id'     => $compte->id,
    ]);

    expect($cotisation->numero_piece)->not->toBeNull();
    expect($cotisation->numero_piece)->toStartWith('2025-2026:');
});
```

Ajouter à `tests/Feature/VirementInterneTest.php` :
```php
it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte1 = \App\Models\CompteBancaire::factory()->create();
    $compte2 = \App\Models\CompteBancaire::factory()->create();

    $virement = app(\App\Services\VirementInterneService::class)->create([
        'date'                   => '2025-10-01',
        'montant'                => 200,
        'compte_source_id'       => $compte1->id,
        'compte_destination_id'  => $compte2->id,
    ]);

    expect($virement->numero_piece)->not->toBeNull();
    expect($virement->numero_piece)->toStartWith('2025-2026:');
});
```

- [ ] **Step 2 : Vérifier que les tests échouent (RED)**

```bash
./vendor/bin/sail artisan test --filter "create assigne un numero_piece"
```

Attendu : 5 failures.

- [ ] **Step 3 : Mettre à jour `RecetteService::create()`**

Dans `app/Services/RecetteService.php`, ajouter les imports et l'appel :

```php
use Carbon\Carbon;
```

Résultat final de `create()` :

```php
public function create(array $data, array $lignes): Recette
{
    return DB::transaction(function () use ($data, $lignes) {
        $data['saisi_par'] = auth()->id();
        $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
        $recette = Recette::create($data);
        foreach ($lignes as $ligne) {
            $recette->lignes()->create($ligne);
        }

        return $recette;
    });
}
```

- [ ] **Step 4 : Mettre à jour `DepenseService::create()`**

Même modification que RecetteService. Résultat final de `create()` :

```php
public function create(array $data, array $lignes): Depense
{
    return DB::transaction(function () use ($data, $lignes) {
        $data['saisi_par'] = auth()->id();
        $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
        $depense = Depense::create($data);
        foreach ($lignes as $ligne) {
            $depense->lignes()->create($ligne);
        }

        return $depense;
    });
}
```

- [ ] **Step 5 : Mettre à jour `DonService::create()`**

Dans `app/Services/DonService.php`, ajouter `use Carbon\Carbon;`. Résultat final de `create()` :

```php
public function create(array $data, ?array $newDonateur = null): Don
{
    return DB::transaction(function () use ($data, $newDonateur) {
        if ($newDonateur) {
            $donateur = Donateur::create($newDonateur);
            $data['donateur_id'] = $donateur->id;
        }

        $data['saisi_par'] = auth()->id();
        $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));

        return Don::create($data);
    });
}
```

- [ ] **Step 6 : Mettre à jour `CotisationService::create()`**

`CotisationService` n'a pas de `DB::transaction()` — il faut l'ajouter. La date est `$data['date_paiement']`. Résultat final du fichier complet :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cotisation;
use App\Models\Membre;
use App\Services\NumeroPieceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class CotisationService
{
    public function create(Membre $membre, array $data): Cotisation
    {
        return DB::transaction(function () use ($membre, $data) {
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(
                Carbon::parse($data['date_paiement'])
            );

            return $membre->cotisations()->create($data);
        });
    }

    public function delete(Cotisation $cotisation): void
    {
        if ($cotisation->rapprochement_id !== null) {
            throw new \RuntimeException("Cette cotisation est pointée dans un rapprochement et ne peut pas être supprimée.");
        }

        $cotisation->delete();
    }
}
```

- [ ] **Step 7 : Mettre à jour `VirementInterneService::create()`**

Ajouter `use Carbon\Carbon;`. Résultat final de `create()` :

```php
public function create(array $data): VirementInterne
{
    return DB::transaction(function () use ($data) {
        $data['saisi_par'] = auth()->id();
        $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));

        return VirementInterne::create($data);
    });
}
```

- [ ] **Step 8 : Vérifier GREEN**

```bash
./vendor/bin/sail artisan test
```

Attendu : tous les tests passent.

- [ ] **Step 9 : Run pint et commit**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    app/Services/RecetteService.php \
    app/Services/DepenseService.php \
    app/Services/DonService.php \
    app/Services/CotisationService.php \
    app/Services/VirementInterneService.php

git add app/Services/RecetteService.php \
        app/Services/DepenseService.php \
        app/Services/DonService.php \
        app/Services/CotisationService.php \
        app/Services/VirementInterneService.php \
        tests/Feature/RecetteTest.php \
        tests/Feature/DepenseTest.php \
        tests/Feature/DonTest.php \
        tests/Feature/CotisationTest.php \
        tests/Feature/VirementInterneTest.php
git commit -m "feat: assign numero_piece dans les 5 services métier à la création"
```

---

## Task 4 : `TransactionCompteService` — ajouter `numero_piece` au UNION

**Files:**
- Modify: `app/Services/TransactionCompteService.php`

**Contexte :** `buildUnion()` construit 6 branches avec `selectRaw()`. Chaque `selectRaw` doit inclure `numero_piece` en dernière position pour que les colonnes du UNION ALL soient alignées.

- [ ] **Step 1 : Mettre à jour les 6 `selectRaw` dans `buildUnion()`**

Dans `app/Services/TransactionCompteService.php`, localiser la méthode `buildUnion()` et ajouter `numero_piece` à la fin de chaque `selectRaw` :

**Recettes** (ligne `$recettes = DB::table(...)`) :
```php
->selectRaw("r.id, 'recette' as source_type, r.date, 'Recette' as type_label, r.tiers, r.libelle, r.reference, r.montant_total as montant, r.mode_paiement, r.pointe, r.numero_piece")
```

**Dépenses** :
```php
->selectRaw("d.id, 'depense' as source_type, d.date, 'Dépense' as type_label, d.tiers, d.libelle, d.reference, -(d.montant_total) as montant, d.mode_paiement, d.pointe, d.numero_piece")
```

**Dons** :
```php
->selectRaw("dn.id, 'don' as source_type, dn.date, 'Don' as type_label, TRIM(CONCAT(COALESCE(`do`.prenom,''), ' ', COALESCE(`do`.nom,''))) as tiers, dn.objet as libelle, NULL as reference, dn.montant, dn.mode_paiement, dn.pointe, dn.numero_piece")
```

**Cotisations** :
```php
->selectRaw("c.id, 'cotisation' as source_type, c.date_paiement as date, 'Cotisation' as type_label, TRIM(CONCAT(COALESCE(m.prenom,''), ' ', COALESCE(m.nom,''))) as tiers, CONCAT('Cotisation ', c.exercice) as libelle, NULL as reference, c.montant, c.mode_paiement, c.pointe, c.numero_piece")
```

**Virements sortants** (`$virementsSource`) :
```php
->selectRaw("vi.id, 'virement_sortant' as source_type, vi.date, 'Virement sortant' as type_label, cb.nom as tiers, CONCAT('Virement vers ', cb.nom) as libelle, vi.reference, -(vi.montant) as montant, NULL as mode_paiement, NULL as pointe, vi.numero_piece")
```

**Virements entrants** (`$virementsDestination`) :
```php
->selectRaw("vi.id, 'virement_entrant' as source_type, vi.date, 'Virement entrant' as type_label, cb.nom as tiers, CONCAT('Virement depuis ', cb.nom) as libelle, vi.reference, vi.montant, NULL as mode_paiement, NULL as pointe, vi.numero_piece")
```

- [ ] **Step 2 : Run tous les tests**

```bash
./vendor/bin/sail artisan test
```

Attendu : tous les tests passent (le UNION est valide si toutes les branches ont le même nombre de colonnes).

- [ ] **Step 3 : Commit**

```bash
git add app/Services/TransactionCompteService.php
git commit -m "feat: inclure numero_piece dans les 6 branches du UNION ALL de TransactionCompteService"
```

---

## Task 5 : Affichage

**Files:**
- Modify: `resources/views/livewire/recette-list.blade.php`
- Modify: `resources/views/livewire/depense-list.blade.php`
- Modify: `resources/views/livewire/transaction-compte-list.blade.php`
- Modify: `resources/views/livewire/recette-form.blade.php`
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `tests/Feature/Livewire/RecetteListTest.php`
- Modify: `tests/Feature/Livewire/DepenseListTest.php`
- Modify: `tests/Feature/Livewire/TransactionCompteListTest.php`
- Modify: `tests/Feature/Livewire/RecetteFormTest.php`
- Modify: `tests/Feature/Livewire/DepenseFormTest.php`

- [ ] **Step 1 : Écrire les tests Livewire d'affichage (RED)**

Ajouter à `tests/Feature/Livewire/RecetteListTest.php` :

```php
it('affiche la colonne N° dans la liste des recettes', function () {
    $user = \App\Models\User::factory()->create();
    $compte = \App\Models\CompteBancaire::factory()->create();
    $recette = \App\Models\Recette::factory()->create([
        'numero_piece' => '2025-2026:00001',
        'compte_id'    => $compte->id,
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\RecetteList::class)
        ->assertSee('N°')
        ->assertSee('2025-2026:00001');
});
```

Ajouter à `tests/Feature/Livewire/DepenseListTest.php` :

```php
it('affiche la colonne N° dans la liste des dépenses', function () {
    $user = \App\Models\User::factory()->create();
    $compte = \App\Models\CompteBancaire::factory()->create();
    $depense = \App\Models\Depense::factory()->create([
        'numero_piece' => '2025-2026:00001',
        'compte_id'    => $compte->id,
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseList::class)
        ->assertSee('N°')
        ->assertSee('2025-2026:00001');
});
```

Ajouter à `tests/Feature/Livewire/TransactionCompteListTest.php` :

```php
it('affiche la colonne N° pièce dans les transactions du compte', function () {
    $user = \App\Models\User::factory()->create();
    $compte = \App\Models\CompteBancaire::factory()->create();
    \App\Models\Recette::factory()->create([
        'numero_piece' => '2025-2026:00042',
        'compte_id'    => $compte->id,
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\TransactionCompteList::class, ['compteId' => $compte->id])
        ->assertSee('N° pièce')
        ->assertSee('2025-2026:00042');
});
```

Ajouter à `tests/Feature/Livewire/RecetteFormTest.php` :

```php
it('affiche le numero_piece en mode édition', function () {
    $user = \App\Models\User::factory()->create();
    $compte = \App\Models\CompteBancaire::factory()->create();
    $recette = \App\Models\Recette::factory()->create([
        'numero_piece' => '2025-2026:00007',
        'compte_id'    => $compte->id,
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\RecetteForm::class, ['recetteId' => $recette->id])
        ->assertSee('2025-2026:00007');
});
```

Ajouter à `tests/Feature/Livewire/DepenseFormTest.php` :

```php
it('affiche le numero_piece en mode édition', function () {
    $user = \App\Models\User::factory()->create();
    $compte = \App\Models\CompteBancaire::factory()->create();
    $depense = \App\Models\Depense::factory()->create([
        'numero_piece' => '2025-2026:00008',
        'compte_id'    => $compte->id,
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseForm::class, ['depenseId' => $depense->id])
        ->assertSee('2025-2026:00008');
});
```

- [ ] **Step 2 : Vérifier que les tests échouent (RED)**

```bash
./vendor/bin/sail artisan test --filter "affiche la colonne N|affiche le numero_piece"
```

Attendu : 5 failures (colonnes et champs absents des vues).

- [ ] **Step 3 : Ajouter la colonne N° dans `recette-list.blade.php`**

Localiser l'en-tête du tableau `<thead>`. Ajouter `<th>N°</th>` comme **première colonne** avant l'en-tête `Date` :

```blade
<thead class="table-dark">
    <tr>
        <th>N°</th>
        <th>Date</th>
        {{-- ... colonnes existantes ... --}}
    </tr>
</thead>
```

Dans le `@foreach` des lignes du tableau, ajouter `<td>` en première position :

```blade
<td class="text-muted small">{{ $recette->numero_piece ?? '—' }}</td>
```

- [ ] **Step 4 : Ajouter la colonne N° dans `depense-list.blade.php`**

Même modification que `recette-list.blade.php` — `<th>N°</th>` en premier dans `<thead>`, `<td>{{ $depense->numero_piece ?? '—' }}</td>` en premier dans chaque ligne.

- [ ] **Step 5 : Ajouter la colonne N° pièce dans `transaction-compte-list.blade.php`**

Localiser le `<thead>` du tableau. Ajouter **avant** la colonne `Date` :

```blade
<th>N° pièce</th>
```

Dans le `@forelse ($transactions as $tx)`, ajouter **avant** la cellule `Date` :

```blade
<td class="text-muted small">{{ $tx->numero_piece ?? '—' }}</td>
```

Mettre également à jour le `colspan` dans la ligne "Aucune transaction trouvée" (passer de 8 à 9, ou de 9 à 10 si solde actif) :

```blade
<td colspan="{{ $showSolde ? 10 : 9 }}" class="text-center text-muted">
    Aucune transaction trouvée.
</td>
```

- [ ] **Step 6 : Afficher le numéro dans `recette-form.blade.php` (mode édition)**

Localiser le `<div class="card-header ...">` qui affiche `{{ $recetteId ? 'Modifier la recette' : 'Nouvelle recette' }}`. Ajouter juste **après** ce `div.card-header`, à l'intérieur de `@if ($recetteId)` :

```blade
@if ($recetteId)
    @php $recetteModel = \App\Models\Recette::find($recetteId); @endphp
    @if ($recetteModel?->numero_piece)
        <div class="px-3 pt-2 text-muted small">
            N° pièce : <strong>{{ $recetteModel->numero_piece }}</strong>
        </div>
    @endif
@endif
```

Placer ce bloc **entre** le `div.card-header` et le `div.card-body`.

- [ ] **Step 7 : Afficher le numéro dans `depense-form.blade.php` (mode édition)**

Même modification que `recette-form.blade.php` — remplacer `Recette::find($recetteId)` par `Depense::find($depenseId)` et `$recetteId` par `$depenseId`.

- [ ] **Step 8 : Run tous les tests (GREEN)**

```bash
./vendor/bin/sail artisan test
```

Attendu : tous les tests passent, y compris les 5 nouveaux tests Livewire.

- [ ] **Step 9 : Run pint et commit final**

```bash
./vendor/bin/sail exec laravel.test ./vendor/bin/pint \
    resources/views/livewire/recette-list.blade.php \
    resources/views/livewire/depense-list.blade.php \
    resources/views/livewire/transaction-compte-list.blade.php \
    resources/views/livewire/recette-form.blade.php \
    resources/views/livewire/depense-form.blade.php

git add resources/views/livewire/recette-list.blade.php \
        resources/views/livewire/depense-list.blade.php \
        resources/views/livewire/transaction-compte-list.blade.php \
        resources/views/livewire/recette-form.blade.php \
        resources/views/livewire/depense-form.blade.php \
        tests/Feature/Livewire/RecetteListTest.php \
        tests/Feature/Livewire/DepenseListTest.php \
        tests/Feature/Livewire/TransactionCompteListTest.php \
        tests/Feature/Livewire/RecetteFormTest.php \
        tests/Feature/Livewire/DepenseFormTest.php
git commit -m "feat: affichage numero_piece dans les listes recettes/depenses, transactions, et formulaires d'édition"
```
