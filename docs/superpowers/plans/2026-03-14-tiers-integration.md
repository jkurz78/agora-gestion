# Tiers Intégration — Plan d'implémentation

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer les modèles `Donateur` et `Membre` par `Tiers` dans toutes les migrations, modèles, formulaires, listes et services.

**Architecture:** Migration en deux phases — ajouter la colonne `tiers_id` à côté des anciennes FK (sans les supprimer), migrer les données, puis supprimer les anciennes colonnes dans le même commit que les mises à jour modèle/factory. Chaque commit laisse la suite de tests verte.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP, MySQL via Sail

---

## Fichiers concernés

### Créer
- `database/migrations/2026_03_14_200001_add_membre_fields_to_tiers.php`
- `database/migrations/2026_03_14_200002_add_tiers_id_fk_to_transactions.php`
- `database/migrations/2026_03_14_200003_migrate_donateurs_to_tiers.php`
- `database/migrations/2026_03_14_200004_migrate_membres_to_tiers.php`
- `database/migrations/2026_03_14_200005_finalize_dons_tiers_id.php`
- `database/migrations/2026_03_14_200006_finalize_cotisations_tiers_id.php`
- `database/migrations/2026_03_14_200007_finalize_depenses_recettes_tiers_id.php`
- `app/Livewire/TiersAutocomplete.php`
- `resources/views/livewire/tiers-autocomplete.blade.php`
- `tests/Livewire/TiersAutocompleteTest.php`

### Modifier
- `app/Models/Tiers.php` — ajouter champs membre + relations dons/cotisations/depenses/recettes
- `app/Models/Don.php` — `donateur_id` → `tiers_id`, `donateur()` → `tiers()`
- `app/Models/Cotisation.php` — `membre_id` → `tiers_id`, `membre()` → `tiers()`
- `app/Models/Depense.php` — supprimer string `tiers`, ajouter `tiers_id` FK + relation
- `app/Models/Recette.php` — idem Depense
- `database/factories/TiersFactory.php` — état `membre()`, `pourDons()`
- `database/factories/DonFactory.php` — `donateur_id` → `tiers_id`
- `database/factories/CotisationFactory.php` — `membre_id` → `tiers_id`
- `app/Services/DonService.php` — supprimer param `$newDonateur`
- `app/Services/CotisationService.php` — `Membre` → `Tiers`
- `app/Services/TransactionCompteService.php` — mettre à jour tous les JOINs
- `app/Livewire/DonForm.php` — `donateur_id`/`creatingDonateur` → `tiers_id` + TiersAutocomplete
- `resources/views/livewire/don-form.blade.php`
- `app/Livewire/DepenseForm.php` — string `tiers` → `tiers_id` + TiersAutocomplete
- `resources/views/livewire/depense-form.blade.php`
- `app/Livewire/RecetteForm.php` — idem DepenseForm
- `resources/views/livewire/recette-form.blade.php`
- `app/Livewire/CotisationForm.php` — `Membre` → `Tiers`
- `resources/views/livewire/cotisation-form.blade.php`
- `app/Livewire/DonList.php` — `donateur_search` → filtre tiers via JOIN
- `resources/views/livewire/don-list.blade.php`
- `app/Livewire/DepenseList.php` — filtre `tiers` string → JOIN sur `tiers`
- `resources/views/livewire/depense-list.blade.php`
- `app/Livewire/Dashboard.php` — `Membre` → `Tiers`
- `app/Http/Controllers/MembreController.php` — `Membre` → `Tiers` filtré
- `app/Http/Requests/StoreMembreRequest.php` — champs Tiers + membre
- `app/Http/Requests/UpdateMembreRequest.php` — idem
- `resources/views/membres/index.blade.php`
- `resources/views/membres/create.blade.php`
- `resources/views/membres/edit.blade.php`
- `resources/views/membres/show.blade.php`
- `routes/web.php` — paramètre resource `membres` → `tiers`

### Supprimer
- `app/Models/Donateur.php`
- `app/Models/Membre.php`
- `database/factories/DonateurFactory.php`
- `database/factories/MembreFactory.php`

### Modifier (tests)
- `tests/Livewire/DonFormTest.php`
- `tests/Livewire/DonListTest.php`
- `tests/Livewire/CotisationFormTest.php`
- `tests/Livewire/DashboardTest.php`
- `tests/Feature/MembreTest.php`
- `tests/Feature/DonTest.php`
- `tests/Feature/CotisationTest.php`
- `tests/Feature/Services/TransactionCompteServiceTest.php`

---

## Chunk 1 : Migrations schéma (Tasks 1-2)

### Task 1 : Étendre la table tiers avec les champs membre

**Files:**
- Create: `database/migrations/2026_03_14_200001_add_membre_fields_to_tiers.php`

- [ ] **Step 1 : Écrire le test qui vérifie la structure**

```php
// tests/Feature/Migrations/AddMembreFieldsToTiersTest.php
<?php

declare(strict_types=1);

it('tiers table has date_adhesion statut_membre notes_membre columns', function () {
    expect(\Schema::hasColumn('tiers', 'date_adhesion'))->toBeTrue();
    expect(\Schema::hasColumn('tiers', 'statut_membre'))->toBeTrue();
    expect(\Schema::hasColumn('tiers', 'notes_membre'))->toBeTrue();
});
```

- [ ] **Step 2 : Lancer le test pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/AddMembreFieldsToTiersTest.php
```
Attendu : FAIL — colonnes absentes.

- [ ] **Step 3 : Écrire la migration**

```php
<?php
// database/migrations/2026_03_14_200001_add_membre_fields_to_tiers.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiers', function (Blueprint $table): void {
            $table->date('date_adhesion')->nullable()->after('adresse');
            $table->string('statut_membre')->nullable()->after('date_adhesion');
            $table->text('notes_membre')->nullable()->after('statut_membre');
        });
    }

    public function down(): void
    {
        Schema::table('tiers', function (Blueprint $table): void {
            $table->dropColumn(['date_adhesion', 'statut_membre', 'notes_membre']);
        });
    }
};
```

- [ ] **Step 4 : Lancer le test pour confirmer le succès**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/AddMembreFieldsToTiersTest.php
```
Attendu : PASS.

- [ ] **Step 5 : Pint + commit**

```bash
./vendor/bin/sail artisan pint database/migrations/2026_03_14_200001_add_membre_fields_to_tiers.php tests/Feature/Migrations/AddMembreFieldsToTiersTest.php
git add database/migrations/2026_03_14_200001_add_membre_fields_to_tiers.php tests/Feature/Migrations/AddMembreFieldsToTiersTest.php
git commit -m "feat: ajouter champs membre à la table tiers (date_adhesion, statut_membre, notes_membre)"
```

---

### Task 2 : Ajouter tiers_id FK sur dons, cotisations, depenses, recettes

**Files:**
- Create: `database/migrations/2026_03_14_200002_add_tiers_id_fk_to_transactions.php`

Cette migration ajoute `tiers_id` nullable à côté des colonnes existantes (`donateur_id`, `membre_id`, string `tiers`). Les colonnes existantes ne sont PAS supprimées ici — elles le seront dans les Tasks 5-7.

- [ ] **Step 1 : Écrire le test**

```php
// tests/Feature/Migrations/AddTiersIdFkToTransactionsTest.php
<?php

declare(strict_types=1);

it('dons table has tiers_id column', function () {
    expect(\Schema::hasColumn('dons', 'tiers_id'))->toBeTrue();
});

it('cotisations table has tiers_id column', function () {
    expect(\Schema::hasColumn('cotisations', 'tiers_id'))->toBeTrue();
});

it('depenses table has tiers_id column', function () {
    expect(\Schema::hasColumn('depenses', 'tiers_id'))->toBeTrue();
});

it('recettes table has tiers_id column', function () {
    expect(\Schema::hasColumn('recettes', 'tiers_id'))->toBeTrue();
});
```

- [ ] **Step 2 : Lancer pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/AddTiersIdFkToTransactionsTest.php
```
Attendu : FAIL.

- [ ] **Step 3 : Écrire la migration**

```php
<?php
// database/migrations/2026_03_14_200002_add_tiers_id_fk_to_transactions.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dons', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('donateur_id');
        });

        Schema::table('cotisations', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('membre_id');
        });

        Schema::table('depenses', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('tiers');
        });

        Schema::table('recettes', function (Blueprint $table): void {
            $table->foreignId('tiers_id')->nullable()->constrained('tiers')->nullOnDelete()->after('tiers');
        });
    }

    public function down(): void
    {
        Schema::table('dons', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
        Schema::table('cotisations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
        Schema::table('depenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
        Schema::table('recettes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tiers_id');
        });
    }
};
```

- [ ] **Step 4 : Lancer pour confirmer le succès**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/AddTiersIdFkToTransactionsTest.php
```
Attendu : PASS.

- [ ] **Step 5 : Vérifier que toute la suite de tests est encore verte**

```bash
./vendor/bin/sail artisan test
```
Attendu : toute la suite passe (les factory existantes utilisent encore donateur_id et membre_id).

- [ ] **Step 6 : Pint + commit**

```bash
./vendor/bin/sail artisan pint database/migrations/2026_03_14_200002_add_tiers_id_fk_to_transactions.php tests/Feature/Migrations/AddTiersIdFkToTransactionsTest.php
git add database/migrations/2026_03_14_200002_add_tiers_id_fk_to_transactions.php tests/Feature/Migrations/AddTiersIdFkToTransactionsTest.php
git commit -m "feat: ajouter colonne tiers_id nullable sur dons, cotisations, depenses, recettes"
```

---

## Chunk 2 : Migrations données (Tasks 3-4)

### Task 3 : Migrer donateurs → tiers, remplir dons.tiers_id

**Files:**
- Create: `database/migrations/2026_03_14_200003_migrate_donateurs_to_tiers.php`

Cette migration de données est un no-op en tests (base fraîche, pas de donateurs). Elle est utile pour la production.

- [ ] **Step 1 : Écrire le test (vérification de la logique)**

```php
// tests/Feature/Migrations/MigrateDonateursTotiersTest.php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('migrates a donateur row to tiers and links the don', function () {
    // Arrange: insert raw data bypassing models
    $donateurId = DB::table('donateurs')->insertGetId([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'email' => 'marie@example.com',
        'adresse' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $compteId = DB::table('comptes_bancaires')->insertGetId([
        'nom' => 'Test compte',
        'solde_initial' => 0,
        'date_solde_initial' => now(),
        'actif_recettes_depenses' => true,
        'actif_dons_cotisations' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $userId = DB::table('users')->insertGetId([
        'name' => 'User Test',
        'email' => 'u@t.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $donId = DB::table('dons')->insertGetId([
        'donateur_id' => $donateurId,
        'tiers_id' => null,
        'date' => '2025-10-01',
        'montant' => 100,
        'mode_paiement' => 'especes',
        'saisi_par' => $userId,
        'compte_id' => $compteId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Act: run the migration logic manually
    $donateurRows = DB::table('donateurs')->get();
    foreach ($donateurRows as $donateur) {
        $tiersId = DB::table('tiers')->insertGetId([
            'type' => 'particulier',
            'nom' => $donateur->nom,
            'prenom' => $donateur->prenom,
            'email' => $donateur->email,
            'telephone' => null,
            'adresse' => $donateur->adresse,
            'pour_depenses' => false,
            'pour_recettes' => true,
            'created_at' => $donateur->created_at,
            'updated_at' => $donateur->updated_at,
        ]);
        DB::table('dons')->where('donateur_id', $donateur->id)->update(['tiers_id' => $tiersId]);
    }

    // Assert
    $don = DB::table('dons')->find($donId);
    expect($don->tiers_id)->not->toBeNull();

    $tiers = DB::table('tiers')->find($don->tiers_id);
    expect($tiers->nom)->toBe('Dupont');
    expect($tiers->prenom)->toBe('Marie');
    expect($tiers->pour_recettes)->toBe(1);
});
```

- [ ] **Step 2 : Lancer pour confirmer le succès (le test valide la logique, pas la migration)**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/MigrateDonateursTotiersTest.php
```
Attendu : PASS (le test valide la logique de migration, pas dépendant de la migration elle-même).

- [ ] **Step 3 : Écrire la migration**

```php
<?php
// database/migrations/2026_03_14_200003_migrate_donateurs_to_tiers.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $donateurs = DB::table('donateurs')->get();

        foreach ($donateurs as $donateur) {
            $tiersId = DB::table('tiers')->insertGetId([
                'type'          => 'particulier',
                'nom'           => $donateur->nom,
                'prenom'        => $donateur->prenom,
                'email'         => $donateur->email,
                'telephone'     => null,
                'adresse'       => $donateur->adresse,
                'pour_depenses' => false,
                'pour_recettes' => true,
                'created_at'    => $donateur->created_at,
                'updated_at'    => $donateur->updated_at,
            ]);

            DB::table('dons')
                ->where('donateur_id', $donateur->id)
                ->update(['tiers_id' => $tiersId]);
        }
    }

    public function down(): void
    {
        // Irréversible : ne pas faire de rollback des données
    }
};
```

- [ ] **Step 4 : Tester manuellement en dev (optionnel)**

```bash
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail artisan test
```

- [ ] **Step 5 : Pint + commit**

```bash
./vendor/bin/sail artisan pint database/migrations/2026_03_14_200003_migrate_donateurs_to_tiers.php tests/Feature/Migrations/MigrateDonateursTotiersTest.php
git add database/migrations/2026_03_14_200003_migrate_donateurs_to_tiers.php tests/Feature/Migrations/MigrateDonateursTotiersTest.php
git commit -m "feat: migration données donateurs → tiers, relier dons.tiers_id"
```

---

### Task 4 : Migrer membres → tiers, remplir cotisations.tiers_id

**Files:**
- Create: `database/migrations/2026_03_14_200004_migrate_membres_to_tiers.php`

- [ ] **Step 1 : Écrire le test de logique**

```php
// tests/Feature/Migrations/MigrateMembresATiersTest.php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('migrates a membre row to tiers and links the cotisation', function () {
    $membreId = DB::table('membres')->insertGetId([
        'nom'           => 'Martin',
        'prenom'        => 'Jean',
        'email'         => 'jean@example.com',
        'telephone'     => '0600000000',
        'adresse'       => null,
        'date_adhesion' => '2023-09-01',
        'statut'        => 'actif',
        'notes'         => 'Bénévole',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $compteId = DB::table('comptes_bancaires')->insertGetId([
        'nom' => 'Test', 'solde_initial' => 0, 'date_solde_initial' => now(),
        'actif_recettes_depenses' => true, 'actif_dons_cotisations' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $cotisationId = DB::table('cotisations')->insertGetId([
        'membre_id'    => $membreId,
        'tiers_id'     => null,
        'exercice'     => 2025,
        'montant'      => 50,
        'date_paiement' => '2025-10-01',
        'mode_paiement' => 'virement',
        'compte_id'    => $compteId,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    // Run migration logic manually
    $membres = DB::table('membres')->get();
    foreach ($membres as $membre) {
        $tiersId = DB::table('tiers')->insertGetId([
            'type'          => 'particulier',
            'nom'           => $membre->nom,
            'prenom'        => $membre->prenom,
            'email'         => $membre->email,
            'telephone'     => $membre->telephone,
            'adresse'       => $membre->adresse,
            'pour_depenses' => false,
            'pour_recettes' => false,
            'date_adhesion' => $membre->date_adhesion,
            'statut_membre' => $membre->statut,
            'notes_membre'  => $membre->notes,
            'created_at'    => $membre->created_at,
            'updated_at'    => $membre->updated_at,
        ]);
        DB::table('cotisations')->where('membre_id', $membre->id)->update(['tiers_id' => $tiersId]);
    }

    $cotisation = DB::table('cotisations')->find($cotisationId);
    expect($cotisation->tiers_id)->not->toBeNull();

    $tiers = DB::table('tiers')->find($cotisation->tiers_id);
    expect($tiers->nom)->toBe('Martin');
    expect($tiers->statut_membre)->toBe('actif');
    expect($tiers->date_adhesion)->toBe('2023-09-01');
});
```

- [ ] **Step 2 : Lancer pour confirmer PASS**

```bash
./vendor/bin/sail artisan test tests/Feature/Migrations/MigrateMembresATiersTest.php
```

- [ ] **Step 3 : Écrire la migration**

```php
<?php
// database/migrations/2026_03_14_200004_migrate_membres_to_tiers.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $membres = DB::table('membres')->get();

        foreach ($membres as $membre) {
            $tiersId = DB::table('tiers')->insertGetId([
                'type'          => 'particulier',
                'nom'           => $membre->nom,
                'prenom'        => $membre->prenom,
                'email'         => $membre->email,
                'telephone'     => $membre->telephone,
                'adresse'       => $membre->adresse,
                'pour_depenses' => false,
                'pour_recettes' => false,
                'date_adhesion' => $membre->date_adhesion,
                'statut_membre' => $membre->statut,
                'notes_membre'  => $membre->notes,
                'created_at'    => $membre->created_at,
                'updated_at'    => $membre->updated_at,
            ]);

            DB::table('cotisations')
                ->where('membre_id', $membre->id)
                ->update(['tiers_id' => $tiersId]);
        }
    }

    public function down(): void
    {
        // Irréversible
    }
};
```

- [ ] **Step 4 : Pint + commit**

```bash
./vendor/bin/sail artisan pint database/migrations/2026_03_14_200004_migrate_membres_to_tiers.php tests/Feature/Migrations/MigrateMembresATiersTest.php
git add database/migrations/2026_03_14_200004_migrate_membres_to_tiers.php tests/Feature/Migrations/MigrateMembresATiersTest.php
git commit -m "feat: migration données membres → tiers, relier cotisations.tiers_id"
```

---

## Chunk 3 : Swap modèle Don + Cotisation (Tasks 5-6)

Ces deux tasks suppriment les anciennes FK et les modèles obsolètes. La suite de tests doit rester verte après chaque commit.

### Task 5 : Swap Don — migration finale + modèle + factory + tests

**Files:**
- Create: `database/migrations/2026_03_14_200005_finalize_dons_tiers_id.php`
- Modify: `app/Models/Don.php`
- Modify: `database/factories/DonFactory.php`
- Delete: `app/Models/Donateur.php`
- Delete: `database/factories/DonateurFactory.php`
- Modify: `tests/Livewire/DonFormTest.php`
- Modify: `tests/Livewire/DonListTest.php`
- Modify: `tests/Feature/DonTest.php`

- [ ] **Step 1 : Écrire les tests mis à jour**

Dans `tests/Livewire/DonFormTest.php`, remplacer toutes les occurrences de `Donateur::factory()` et `donateur_id` par `Tiers::factory()->pourRecettes()` et `tiers_id` :

```php
<?php

declare(strict_types=1);

use App\Livewire\DonForm;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create();
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders the form component', function () {
    Livewire::test(DonForm::class)
        ->assertOk()
        ->assertSee('Nouveau don');
});

it('can save a don with existing tiers', function () {
    $tiers = Tiers::factory()->pourRecettes()->create();

    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '100.00')
        ->set('mode_paiement', 'virement')
        ->set('tiers_id', $tiers->id)
        ->set('compte_id', $this->compte->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'montant'    => '100.00',
        'tiers_id'   => $tiers->id,
        'saisi_par'  => $this->user->id,
    ]);
});

it('can save an anonymous don (tiers_id null)', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->set('date', '2025-10-15')
        ->set('montant', '50.00')
        ->set('mode_paiement', 'especes')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'montant'   => '50.00',
        'tiers_id'  => null,
        'saisi_par' => $this->user->id,
    ]);
});

it('validates required fields', function () {
    Livewire::test(DonForm::class)
        ->set('showForm', true)
        ->call('save')
        ->assertHasErrors(['date', 'montant', 'mode_paiement']);
});

it('can load existing don for editing', function () {
    $tiers = Tiers::factory()->pourRecettes()->create();
    $don = Don::factory()->create([
        'tiers_id'     => $tiers->id,
        'montant'      => 150.00,
        'mode_paiement' => 'cb',
        'objet'        => 'Don annuel',
        'saisi_par'    => $this->user->id,
        'compte_id'    => $this->compte->id,
    ]);

    Livewire::test(DonForm::class)
        ->call('edit', $don->id)
        ->assertSet('donId', $don->id)
        ->assertSet('tiers_id', $tiers->id)
        ->assertSet('mode_paiement', 'cb')
        ->assertSet('showForm', true);
});

it('can update an existing don', function () {
    $don = Don::factory()->create([
        'date'          => '2025-10-15',
        'montant'       => 100.00,
        'mode_paiement' => 'especes',
        'saisi_par'     => $this->user->id,
        'compte_id'     => $this->compte->id,
    ]);

    Livewire::test(DonForm::class)
        ->call('edit', $don->id)
        ->set('montant', '250.00')
        ->set('objet', 'Nouvel objet')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('don-saved');

    $this->assertDatabaseHas('dons', [
        'id'     => $don->id,
        'montant' => '250.00',
    ]);
});

it('rejette une date hors exercice', function () {
    Livewire::test(DonForm::class)
        ->call('showNewForm')
        ->set('date', '2025-08-01')
        ->set('montant', '100.00')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date']);
});
```

Dans `tests/Livewire/DonListTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\DonList;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders with dons', function () {
    $tiers = Tiers::factory()->pourRecettes()->create(['nom' => 'Dupont', 'prenom' => 'Marie']);

    Don::factory()->create([
        'tiers_id'  => $tiers->id,
        'date'      => '2025-10-15',
        'montant'   => 100.00,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DonList::class)
        ->assertOk()
        ->assertSee('Dupont');
});

it('can delete a don with soft delete', function () {
    $don = Don::factory()->create([
        'date'      => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DonList::class)
        ->call('delete', $don->id);

    $this->assertSoftDeleted('dons', ['id' => $don->id]);
});
```

Dans `tests/Feature/DonTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\User;

it('create assigne un numero_piece non null', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->pourRecettes()->create();

    $don = app(\App\Services\DonService::class)->create([
        'date'          => '2025-10-01',
        'montant'       => 50,
        'mode_paiement' => 'especes',
        'compte_id'     => $compte->id,
        'tiers_id'      => $tiers->id,
    ]);

    expect($don->numero_piece)->not->toBeNull();
    expect($don->numero_piece)->toStartWith('2025-2026:');
});
```

- [ ] **Step 2 : Lancer les tests pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/DonFormTest.php tests/Livewire/DonListTest.php tests/Feature/DonTest.php
```
Attendu : FAIL — `Tiers` factory states `pourRecettes` existent, mais `Don` model utilise encore `donateur_id`.

- [ ] **Step 3 : Écrire la migration de finalisation**

```php
<?php
// database/migrations/2026_03_14_200005_finalize_dons_tiers_id.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dons', function (Blueprint $table): void {
            // Supprimer la FK donateur_id
            $table->dropForeign(['donateur_id']);
            $table->dropColumn('donateur_id');
        });

        // Supprimer la table donateurs (plus référencée)
        Schema::dropIfExists('donateurs');
    }

    public function down(): void
    {
        // Non applicable — migration irréversible en production
    }
};
```

- [ ] **Step 4 : Mettre à jour `app/Models/Don.php`**

Remplacer `donateur_id` par `tiers_id`, supprimer `donateur()`, ajouter `tiers()` :

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Don extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'dons';

    protected $fillable = [
        'tiers_id',
        'date',
        'montant',
        'mode_paiement',
        'objet',
        'operation_id',
        'seance',
        'compte_id',
        'pointe',
        'recu_emis',
        'saisi_par',
        'rapprochement_id',
        'numero_piece',
    ];

    protected function casts(): array
    {
        return [
            'date'             => 'date',
            'montant'          => 'decimal:2',
            'mode_paiement'    => ModePaiement::class,
            'pointe'           => 'boolean',
            'recu_emis'        => 'boolean',
            'tiers_id'         => 'integer',
            'compte_id'        => 'integer',
            'operation_id'     => 'integer',
            'seance'           => 'integer',
            'saisi_par'        => 'integer',
            'rapprochement_id' => 'integer',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function rapprochement(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_id');
    }

    public function isLockedByRapprochement(): bool
    {
        return $this->rapprochement_id !== null
            && $this->rapprochement?->isVerrouille() === true;
    }

    /** @param Builder<Don> $query */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->whereBetween('date', [
            "{$exercice}-09-01",
            ($exercice + 1).'-08-31',
        ]);
    }
}
```

- [ ] **Step 5 : Mettre à jour `database/factories/DonFactory.php`**

```php
<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Don> */
class DonFactory extends Factory
{
    protected $model = Don::class;

    public function definition(): array
    {
        return [
            'tiers_id'      => Tiers::factory()->pourRecettes(),
            'date'          => fake()->dateTimeBetween('-1 year', 'now'),
            'montant'       => fake()->randomFloat(2, 10, 5000),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'objet'         => fake()->optional()->sentence(3),
            'operation_id'  => null,
            'seance'        => null,
            'compte_id'     => CompteBancaire::factory(),
            'pointe'        => fake()->boolean(20),
            'recu_emis'     => fake()->boolean(30),
            'saisi_par'     => User::factory(),
        ];
    }
}
```

- [ ] **Step 6 : Mettre à jour `app/Services/DonService.php`**

Supprimer le param `$newDonateur` (plus nécessaire : la création inline de tiers se fait via TiersService séparément) :

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Don;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class DonService
{
    public function create(array $data): Don
    {
        return DB::transaction(function () use ($data): Don {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));

            return Don::create($data);
        });
    }

    public function update(Don $don, array $data): Don
    {
        $don->update($data);

        return $don->fresh();
    }

    public function delete(Don $don): void
    {
        if ($don->rapprochement_id !== null) {
            throw new \RuntimeException('Ce don est pointé dans un rapprochement et ne peut pas être supprimé.');
        }

        $don->delete();
    }
}
```

- [ ] **Step 7 : Supprimer `app/Models/Donateur.php` et `database/factories/DonateurFactory.php`**

```bash
rm app/Models/Donateur.php database/factories/DonateurFactory.php
```

- [ ] **Step 8 : Lancer toute la suite de tests**

```bash
./vendor/bin/sail artisan test
```
Attendu : PASS. Si des tests non listés échouent encore à cause de `Donateur`, les corriger dans ce même commit.

- [ ] **Step 9 : Pint + commit**

```bash
./vendor/bin/sail artisan pint
git add -u
git commit -m "feat: remplacer donateur_id par tiers_id sur dons, supprimer modèle Donateur"
```

---

### Task 6 : Swap Cotisation — migration finale + modèle + factory + tests

**Files:**
- Create: `database/migrations/2026_03_14_200006_finalize_cotisations_tiers_id.php`
- Modify: `app/Models/Cotisation.php`
- Modify: `database/factories/CotisationFactory.php`
- Delete: `app/Models/Membre.php`
- Delete: `database/factories/MembreFactory.php`
- Modify: `tests/Livewire/CotisationFormTest.php`
- Modify: `tests/Feature/CotisationTest.php`

- [ ] **Step 1 : Mettre à jour les tests**

Dans `tests/Livewire/CotisationFormTest.php` (remplacer `Membre` → `Tiers`) :

```php
<?php

declare(strict_types=1);

use App\Livewire\CotisationForm;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->tiers = Tiers::factory()->membre()->create();
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('renders for a tiers membre', function () {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->assertOk()
        ->assertSee('Cotisations');
});

it('can add a cotisation', function () {
    $compte = CompteBancaire::factory()->create();

    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->set('montant', '50.00')
        ->set('date_paiement', '2025-10-01')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('cotisations', [
        'tiers_id'     => $this->tiers->id,
        'exercice'     => 2025,
        'montant'      => '50.00',
        'mode_paiement' => 'virement',
    ]);
});

it('validates required fields when adding a cotisation', function () {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->set('montant', '')
        ->set('mode_paiement', '')
        ->set('date_paiement', '')
        ->call('save')
        ->assertHasErrors(['montant', 'mode_paiement', 'date_paiement']);
});

it('can delete a cotisation via soft delete', function () {
    $cotisation = Cotisation::factory()->create([
        'tiers_id' => $this->tiers->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->call('delete', $cotisation->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('cotisations', ['id' => $cotisation->id]);
});

it('rejette une date_paiement avant le début de l\'exercice', function () {
    Livewire::actingAs($this->user)
        ->test(CotisationForm::class, ['tiers' => $this->tiers])
        ->set('date_paiement', '2025-08-31')
        ->set('montant', '50')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date_paiement']);
});
```

Dans `tests/Feature/CotisationTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\CompteBancaire;
use App\Models\Tiers;

it('create assigne un numero_piece non null', function () {
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->membre()->create();

    $cotisation = app(\App\Services\CotisationService::class)->create($tiers, [
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

- [ ] **Step 2 : Lancer pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/CotisationFormTest.php tests/Feature/CotisationTest.php
```
Attendu : FAIL — le factory state `membre()` n'existe pas encore sur Tiers.

- [ ] **Step 3 : Écrire la migration de finalisation**

```php
<?php
// database/migrations/2026_03_14_200006_finalize_cotisations_tiers_id.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotisations', function (Blueprint $table): void {
            $table->dropForeign(['membre_id']);
            $table->dropColumn('membre_id');
        });

        Schema::dropIfExists('membres');
    }

    public function down(): void
    {
        // Non applicable
    }
};
```

- [ ] **Step 4 : Mettre à jour `app/Models/Cotisation.php`**

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Cotisation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tiers_id',
        'exercice',
        'montant',
        'date_paiement',
        'mode_paiement',
        'compte_id',
        'pointe',
        'rapprochement_id',
        'numero_piece',
    ];

    protected function casts(): array
    {
        return [
            'montant'          => 'decimal:2',
            'date_paiement'    => 'date',
            'mode_paiement'    => ModePaiement::class,
            'pointe'           => 'boolean',
            'tiers_id'         => 'integer',
            'compte_id'        => 'integer',
            'exercice'         => 'integer',
            'rapprochement_id' => 'integer',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class, 'compte_id');
    }

    public function rapprochement(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_id');
    }

    public function isLockedByRapprochement(): bool
    {
        return $this->rapprochement_id !== null
            && $this->rapprochement?->isVerrouille() === true;
    }

    /** @param Builder<Cotisation> $query */
    public function scopeForExercice(Builder $query, int $exercice): Builder
    {
        return $query->where('exercice', $exercice);
    }
}
```

- [ ] **Step 5 : Mettre à jour `database/factories/CotisationFactory.php`**

```php
<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cotisation> */
class CotisationFactory extends Factory
{
    protected $model = Cotisation::class;

    public function definition(): array
    {
        return [
            'tiers_id'      => Tiers::factory()->membre(),
            'exercice'      => (int) date('Y'),
            'montant'       => fake()->randomFloat(2, 10, 200),
            'date_paiement' => fake()->dateTimeBetween('-1 year', 'now'),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'compte_id'     => CompteBancaire::factory(),
            'pointe'        => fake()->boolean(30),
        ];
    }
}
```

- [ ] **Step 6 : Ajouter l'état `membre()` et `pourRecettes()` à `database/factories/TiersFactory.php`**

```php
<?php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cotisation;
use App\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tiers> */
final class TiersFactory extends Factory
{
    protected $model = Tiers::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['entreprise', 'particulier']);

        return [
            'type'           => $type,
            'nom'            => $type === 'entreprise' ? fake()->company() : fake()->lastName(),
            'prenom'         => $type === 'particulier' ? fake()->firstName() : null,
            'email'          => fake()->optional()->safeEmail(),
            'telephone'      => fake()->optional()->phoneNumber(),
            'adresse'        => fake()->optional()->address(),
            'pour_depenses'  => fake()->boolean(60),
            'pour_recettes'  => fake()->boolean(40),
            'date_adhesion'  => null,
            'statut_membre'  => null,
            'notes_membre'   => null,
        ];
    }

    public function pourDepenses(): static
    {
        return $this->state(['pour_depenses' => true]);
    }

    public function pourRecettes(): static
    {
        return $this->state(['pour_recettes' => true]);
    }

    public function membre(): static
    {
        return $this->state([
            'type'          => 'particulier',
            'prenom'        => fake()->firstName(),
            'pour_recettes' => false,
            'date_adhesion' => fake()->dateTimeBetween('-3 years', 'now'),
            'statut_membre' => 'actif',
        ]);
    }

    public function withCotisation(int $exercice): static
    {
        return $this->afterCreating(function (Tiers $tiers) use ($exercice): void {
            Cotisation::factory()->create([
                'tiers_id' => $tiers->id,
                'exercice' => $exercice,
            ]);
        });
    }
}
```

- [ ] **Step 7 : Mettre à jour `app/Services/CotisationService.php`**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Cotisation;
use App\Models\Tiers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class CotisationService
{
    public function create(Tiers $tiers, array $data): Cotisation
    {
        return DB::transaction(function () use ($tiers, $data): Cotisation {
            $data['tiers_id'] = $tiers->id;
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(
                Carbon::parse($data['date_paiement'])
            );

            return Cotisation::create($data);
        });
    }

    public function delete(Cotisation $cotisation): void
    {
        if ($cotisation->rapprochement_id !== null) {
            throw new \RuntimeException('Cette cotisation est pointée dans un rapprochement et ne peut pas être supprimée.');
        }

        $cotisation->delete();
    }
}
```

- [ ] **Step 8 : Mettre à jour `app/Models/Tiers.php` (ajouter les relations + fillable étendu)**

```php
<?php
// app/Models/Tiers.php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Tiers extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse',
        'pour_depenses',
        'pour_recettes',
        'date_adhesion',
        'statut_membre',
        'notes_membre',
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses' => 'boolean',
            'pour_recettes' => 'boolean',
            'date_adhesion' => 'date',
        ];
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->nom;
        }

        return trim(($this->prenom ? $this->prenom.' ' : '').$this->nom);
    }

    public function dons(): HasMany
    {
        return $this->hasMany(Don::class);
    }

    public function cotisations(): HasMany
    {
        return $this->hasMany(Cotisation::class);
    }

    public function depenses(): HasMany
    {
        return $this->hasMany(Depense::class);
    }

    public function recettes(): HasMany
    {
        return $this->hasMany(Recette::class);
    }

    /** @param \Illuminate\Database\Eloquent\Builder<Tiers> $query */
    public function scopeMembres(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('statut_membre');
    }
}
```

- [ ] **Step 9 : Supprimer les modèles Membre**

```bash
rm app/Models/Membre.php database/factories/MembreFactory.php
```

- [ ] **Step 10 : Lancer toute la suite de tests**

```bash
./vendor/bin/sail artisan test
```
Attendu : PASS. Corriger toute référence restante à `Membre` dans les tests si besoin.

- [ ] **Step 11 : Pint + commit**

```bash
./vendor/bin/sail artisan pint
git add -u
git rm app/Models/Donateur.php app/Models/Membre.php database/factories/DonateurFactory.php database/factories/MembreFactory.php 2>/dev/null || true
git commit -m "feat: remplacer membre_id par tiers_id sur cotisations, supprimer modèle Membre"
```

---

## Chunk 4 : Swap Depense + Recette (Task 7)

### Task 7 : Supprimer colonne string tiers des depenses et recettes

**Files:**
- Create: `database/migrations/2026_03_14_200007_finalize_depenses_recettes_tiers_id.php`
- Modify: `app/Models/Depense.php`
- Modify: `app/Models/Recette.php`
- Modify: `tests/Livewire/DepenseFormTest.php`
- Modify: `tests/Livewire/RecetteFormTest.php`

- [ ] **Step 1 : Mettre à jour `tests/Livewire/DepenseFormTest.php`**

Remplacer `->set('tiers', 'Fournisseur XYZ')` par `->set('tiers_id', $tiers->id)` et `'tiers' => 'Fournisseur XYZ'` par `'tiers_id' => $tiers->id`. Dans les tests qui n'ont pas de tiers, omettre le champ (tiers_id est nullable).

Aussi ajouter en haut : `use App\Models\Tiers;`

Dans le `beforeEach`, créer `$this->tiersPourDepenses = Tiers::factory()->pourDepenses()->create();`

Dans le test `can save a new depense` :
```php
->set('tiers_id', $this->tiersPourDepenses->id)
// ...
$this->assertDatabaseHas('depenses', [
    'libelle'  => 'Achat fournitures',
    'tiers_id' => $this->tiersPourDepenses->id,
]);
```

Les autres tests n'utilisent pas `tiers`, ils passent tel quel (tiers_id nullable).

- [ ] **Step 2 : Vérifier que tests/Livewire/RecetteFormTest.php n'utilise pas `tiers` string**

```bash
grep -n "tiers" tests/Livewire/RecetteFormTest.php
```
Si oui, faire les mêmes ajustements que pour DepenseFormTest.

- [ ] **Step 3 : Lancer les tests concernés pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/DepenseFormTest.php
```
Attendu : FAIL.

- [ ] **Step 4 : Écrire la migration**

```php
<?php
// database/migrations/2026_03_14_200007_finalize_depenses_recettes_tiers_id.php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depenses', function (Blueprint $table): void {
            $table->dropColumn('tiers');
        });

        Schema::table('recettes', function (Blueprint $table): void {
            $table->dropColumn('tiers');
        });
    }

    public function down(): void
    {
        Schema::table('depenses', function (Blueprint $table): void {
            $table->string('tiers')->nullable();
        });
        Schema::table('recettes', function (Blueprint $table): void {
            $table->string('tiers')->nullable();
        });
    }
};
```

- [ ] **Step 5 : Mettre à jour `app/Models/Depense.php`**

Remplacer `'tiers'` par `'tiers_id'` dans `$fillable`, supprimer le cast implicite, ajouter `'tiers_id' => 'integer'` dans `casts()`, ajouter la relation `tiers()` :

```php
protected $fillable = [
    'date', 'libelle', 'montant_total', 'mode_paiement',
    'tiers_id', 'reference', 'compte_id', 'pointe',
    'notes', 'saisi_par', 'rapprochement_id', 'numero_piece',
];

protected function casts(): array
{
    return [
        'date'             => 'date',
        'montant_total'    => 'decimal:2',
        'mode_paiement'    => ModePaiement::class,
        'pointe'           => 'boolean',
        'tiers_id'         => 'integer',
        'compte_id'        => 'integer',
        'saisi_par'        => 'integer',
        'rapprochement_id' => 'integer',
    ];
}

public function tiers(): BelongsTo
{
    return $this->belongsTo(Tiers::class);
}
```

Ajouter l'import : `use Illuminate\Database\Eloquent\Relations\BelongsTo;` et `use App\Models\Tiers;` si non présent.

- [ ] **Step 6 : Mettre à jour `app/Models/Recette.php`** (même chose que Depense)

- [ ] **Step 7 : Lancer toute la suite de tests**

```bash
./vendor/bin/sail artisan test
```
Attendu : PASS.

- [ ] **Step 8 : Pint + commit**

```bash
./vendor/bin/sail artisan pint
git add -u
git commit -m "feat: remplacer colonne string tiers par tiers_id FK sur depenses et recettes"
```

---

## Chunk 5 : Composant TiersAutocomplete (Task 8)

### Task 8 : Composant Livewire TiersAutocomplete réutilisable

**Files:**
- Create: `app/Livewire/TiersAutocomplete.php`
- Create: `resources/views/livewire/tiers-autocomplete.blade.php`
- Create: `tests/Livewire/TiersAutocompleteTest.php`

Ce composant est `#[Modelable]` : il expose `$tiersId` (int|null) que le parent bind via `wire:model`.

Il reçoit un paramètre `$filtre` : `'depenses'`, `'recettes'`, `'dons'` ou `'tous'` (défaut `'tous'`).

Comportement :
- Champ texte avec autocomplétion en temps réel via `wire:model.live="search"`
- Dropdown Bootstrap positionné en absolu sous le champ
- Une fois sélectionné : affiche le `displayName()` avec bouton ✕ pour déselectionner
- Option "+ Créer" si aucun résultat exact → ouvre mini-modale (nom + type)

- [ ] **Step 1 : Écrire les tests**

```php
<?php
// tests/Livewire/TiersAutocompleteTest.php
declare(strict_types=1);

use App\Livewire\TiersAutocomplete;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders empty without search', function () {
    Livewire::test(TiersAutocomplete::class)
        ->assertOk()
        ->assertDontSee('dropdown-menu');
});

it('shows matching tiers on search', function () {
    Tiers::factory()->pourDepenses()->create(['nom' => 'Martin Électricité', 'prenom' => null, 'type' => 'entreprise']);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'Martin')
        ->assertSee('Martin Électricité');
});

it('does not show tiers filtered out by filtre', function () {
    Tiers::factory()->create(['nom' => 'Recettes Only', 'pour_depenses' => false, 'pour_recettes' => true, 'type' => 'entreprise', 'prenom' => null]);

    Livewire::test(TiersAutocomplete::class, ['filtre' => 'depenses'])
        ->set('search', 'Recettes')
        ->assertDontSee('Recettes Only');
});

it('selects a tiers and sets tiersId', function () {
    $tiers = Tiers::factory()->pourDepenses()->create(['nom' => 'Durand SA', 'type' => 'entreprise', 'prenom' => null]);

    Livewire::test(TiersAutocomplete::class)
        ->call('selectTiers', $tiers->id)
        ->assertSet('tiersId', $tiers->id)
        ->assertSet('search', '')
        ->assertSet('open', false);
});

it('clears selection on clearTiers', function () {
    $tiers = Tiers::factory()->pourDepenses()->create();

    Livewire::test(TiersAutocomplete::class)
        ->call('selectTiers', $tiers->id)
        ->call('clearTiers')
        ->assertSet('tiersId', null)
        ->assertSet('search', '');
});

it('can create a new tiers inline', function () {
    Livewire::test(TiersAutocomplete::class)
        ->set('search', 'Nouveau Fournisseur')
        ->call('openCreateModal')
        ->set('newTiersNom', 'Nouveau Fournisseur')
        ->set('newTiersType', 'entreprise')
        ->call('confirmCreate')
        ->assertSet('open', false);

    $this->assertDatabaseHas('tiers', ['nom' => 'Nouveau Fournisseur', 'type' => 'entreprise']);
});
```

- [ ] **Step 2 : Lancer pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersAutocompleteTest.php
```
Attendu : FAIL — composant absent.

- [ ] **Step 3 : Écrire `app/Livewire/TiersAutocomplete.php`**

```php
<?php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Livewire\Attributes\Modelable;
use Livewire\Component;

final class TiersAutocomplete extends Component
{
    #[Modelable]
    public ?int $tiersId = null;

    public string $search = '';

    public bool $open = false;

    /** @var string 'depenses'|'recettes'|'dons'|'tous' */
    public string $filtre = 'tous';

    public bool $showCreateModal = false;

    public string $newTiersNom = '';

    public string $newTiersType = 'entreprise';

    public function updatedSearch(): void
    {
        $this->open = $this->search !== '';
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Tiers> */
    public function getFilteredTiersProperty(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->search === '') {
            return collect();
        }

        return Tiers::query()
            ->when($this->filtre === 'depenses', fn ($q) => $q->where('pour_depenses', true))
            ->when($this->filtre === 'recettes', fn ($q) => $q->where('pour_recettes', true))
            ->when($this->filtre === 'dons', fn ($q) => $q->where('pour_recettes', true))
            ->where(function ($q): void {
                $q->where('nom', 'like', '%'.$this->search.'%')
                    ->orWhere('prenom', 'like', '%'.$this->search.'%');
            })
            ->orderBy('nom')
            ->limit(8)
            ->get();
    }

    public function selectTiers(int $id): void
    {
        $this->tiersId = $id;
        $this->search = '';
        $this->open = false;
    }

    public function clearTiers(): void
    {
        $this->tiersId = null;
        $this->search = '';
    }

    public function openCreateModal(): void
    {
        $this->newTiersNom = $this->search;
        $this->showCreateModal = true;
        $this->open = false;
    }

    public function confirmCreate(): void
    {
        $this->validate([
            'newTiersNom'  => ['required', 'string', 'max:255'],
            'newTiersType' => ['required', 'in:entreprise,particulier'],
        ]);

        $tiers = app(\App\Services\TiersService::class)->create([
            'nom'           => $this->newTiersNom,
            'type'          => $this->newTiersType,
            'pour_depenses' => in_array($this->filtre, ['depenses', 'tous'], true),
            'pour_recettes' => in_array($this->filtre, ['recettes', 'dons', 'tous'], true),
        ]);

        $this->tiersId = $tiers->id;
        $this->search = '';
        $this->showCreateModal = false;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $selectedTiers = $this->tiersId ? Tiers::find($this->tiersId) : null;

        return view('livewire.tiers-autocomplete', [
            'selectedTiers'  => $selectedTiers,
            'filteredTiers'  => $this->filteredTiers,
        ]);
    }
}
```

- [ ] **Step 4 : Écrire `resources/views/livewire/tiers-autocomplete.blade.php`**

```blade
<div class="position-relative">
    @if ($selectedTiers)
        {{-- Tiers sélectionné --}}
        <div class="d-flex align-items-center gap-2 border rounded px-2 py-1 bg-light">
            <span class="badge bg-secondary">{{ $selectedTiers->type === 'entreprise' ? 'Ent.' : 'Part.' }}</span>
            <span class="fw-semibold">{{ $selectedTiers->displayName() }}</span>
            <button type="button" class="btn-close ms-auto" wire:click="clearTiers" aria-label="Retirer"></button>
        </div>
    @else
        {{-- Champ de recherche --}}
        <input
            type="text"
            class="form-control"
            placeholder="Tapez pour rechercher un tiers…"
            wire:model.live="search"
            autocomplete="off"
        >

        @if ($open && count($filteredTiers) > 0)
        <ul class="list-group position-absolute w-100 z-3 shadow-sm" style="top:100%">
            @foreach ($filteredTiers as $tiers)
            <li class="list-group-item list-group-item-action d-flex gap-2 py-2"
                wire:click="selectTiers({{ $tiers->id }})"
                style="cursor:pointer">
                <span class="badge bg-secondary">{{ $tiers->type === 'entreprise' ? 'Ent.' : 'Part.' }}</span>
                <span>{{ $tiers->displayName() }}</span>
            </li>
            @endforeach
            @if ($search && !$filteredTiers->contains(fn($t) => strtolower($t->displayName()) === strtolower($search)))
            <li class="list-group-item list-group-item-action text-primary py-2"
                wire:click="openCreateModal"
                style="cursor:pointer">
                + Créer "{{ $search }}"
            </li>
            @endif
        </ul>
        @elseif ($open && $search)
        <ul class="list-group position-absolute w-100 z-3 shadow-sm" style="top:100%">
            <li class="list-group-item text-muted fst-italic py-2">Aucun résultat pour "{{ $search }}"</li>
            <li class="list-group-item list-group-item-action text-primary py-2"
                wire:click="openCreateModal"
                style="cursor:pointer">
                + Créer "{{ $search }}"
            </li>
        </ul>
        @endif
    @endif

    {{-- Mini-modale création rapide --}}
    @if ($showCreateModal)
    <div class="modal d-block" tabindex="-1" style="background:rgba(0,0,0,.4)">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Créer un tiers</h6>
                    <button type="button" class="btn-close" wire:click="$set('showCreateModal', false)"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" wire:model="newTiersNom">
                        @error('newTiersNom') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Type</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" wire:model="newTiersType" value="entreprise" id="ta_ent">
                                <label class="form-check-label" for="ta_ent">Entreprise</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" wire:model="newTiersType" value="particulier" id="ta_part">
                                <label class="form-check-label" for="ta_part">Particulier</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="$set('showCreateModal', false)">Annuler</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="confirmCreate">Créer et sélectionner</button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/TiersAutocompleteTest.php
```
Attendu : PASS.

- [ ] **Step 6 : Pint + commit**

```bash
./vendor/bin/sail artisan pint app/Livewire/TiersAutocomplete.php tests/Livewire/TiersAutocompleteTest.php
git add app/Livewire/TiersAutocomplete.php resources/views/livewire/tiers-autocomplete.blade.php tests/Livewire/TiersAutocompleteTest.php
git commit -m "feat: composant Livewire TiersAutocomplete réutilisable (#[Modelable])"
```

---

## Chunk 6 : Mise à jour des formulaires (Tasks 9-11)

### Task 9 : Mettre à jour DonForm + vue don-form

**Files:**
- Modify: `app/Livewire/DonForm.php`
- Modify: `resources/views/livewire/don-form.blade.php`

DonForm supprime `$donateur_id`, `$creatingDonateur`, `$new_donateur_*`. Il expose `$tiers_id` (nullable int) qui sera bindé au composant `TiersAutocomplete`.

- [ ] **Step 1 : Confirmer que les tests DonFormTest passent déjà** (mis à jour à la Task 5)

```bash
./vendor/bin/sail artisan test tests/Livewire/DonFormTest.php
```
Attendu : FAIL (DonForm utilise encore `donateur_id`).

- [ ] **Step 2 : Réécrire `app/Livewire/DonForm.php`**

```php
<?php
declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\Operation;
use App\Services\DonService;
use App\Services\ExerciceService;
use Livewire\Attributes\On;
use Livewire\Component;

final class DonForm extends Component
{
    public ?int $donId = null;

    public string $date = '';

    public string $montant = '';

    public string $mode_paiement = '';

    public ?string $objet = null;

    public ?int $tiers_id = null;

    public ?int $operation_id = null;

    public ?int $seance = null;

    public ?int $compte_id = null;

    public bool $showForm = false;

    #[On('edit-don')]
    public function edit(int $id): void
    {
        $don = Don::findOrFail($id);

        $this->donId        = $don->id;
        $this->date         = $don->date->format('Y-m-d');
        $this->montant      = (string) $don->montant;
        $this->mode_paiement = $don->mode_paiement->value;
        $this->objet        = $don->objet;
        $this->tiers_id     = $don->tiers_id;
        $this->operation_id = $don->operation_id;
        $this->seance       = $don->seance;
        $this->compte_id    = $don->compte_id;
        $this->showForm     = true;
    }

    public function resetForm(): void
    {
        $this->reset([
            'donId', 'date', 'montant', 'mode_paiement', 'objet',
            'tiers_id', 'operation_id', 'seance', 'compte_id', 'showForm',
        ]);
        $this->resetValidation();
    }

    public function showNewForm(): void
    {
        $this->resetForm();
        $this->date     = app(ExerciceService::class)->defaultDate();
        $this->showForm = true;
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range     = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin   = $range['end']->toDateString();

        $this->validate([
            'date'          => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
            'montant'       => ['required', 'numeric', 'min:0.01'],
            'mode_paiement' => ['required', 'in:virement,cheque,especes,cb,prelevement'],
            'objet'         => ['nullable', 'string', 'max:255'],
            'tiers_id'      => ['nullable', 'exists:tiers,id'],
            'operation_id'  => ['nullable'],
            'seance'        => ['nullable', 'integer', 'min:1'],
            'compte_id'     => ['nullable', 'exists:comptes_bancaires,id'],
        ], [
            'date.after_or_equal'  => 'La date doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
            'date.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
        ]);

        if ($this->operation_id && $this->seance) {
            $operation = Operation::find($this->operation_id);
            if ($operation && $operation->nombre_seances && $this->seance > $operation->nombre_seances) {
                $this->addError('seance', 'La séance doit être entre 1 et '.$operation->nombre_seances.'.');

                return;
            }
        }

        $data = [
            'date'          => $this->date,
            'montant'       => $this->montant,
            'mode_paiement' => $this->mode_paiement,
            'objet'         => $this->objet ?: null,
            'tiers_id'      => $this->tiers_id,
            'operation_id'  => $this->operation_id,
            'seance'        => $this->seance,
            'compte_id'     => $this->compte_id,
        ];

        $service = app(DonService::class);

        if ($this->donId) {
            $service->update(Don::findOrFail($this->donId), $data);
        } else {
            $service->create($data);
        }

        $this->dispatch('don-saved');
        $this->resetForm();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.don-form', [
            'operations'    => Operation::orderBy('nom')->get(),
            'comptes'       => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
```

- [ ] **Step 3 : Mettre à jour `resources/views/livewire/don-form.blade.php`**

Lire le fichier existant et :
- Supprimer le bloc select `<select … donateur_id>` et le bloc `@if ($creatingDonateur)` (création inline)
- Remplacer par `<livewire:tiers-autocomplete wire:model="tiers_id" filtre="dons" />`
- Supprimer les boutons "Nouveau donateur" / "Sélectionner existant"

- [ ] **Step 4 : Lancer les tests DonForm**

```bash
./vendor/bin/sail artisan test tests/Livewire/DonFormTest.php
```
Attendu : PASS.

- [ ] **Step 5 : Pint + commit**

```bash
./vendor/bin/sail artisan pint app/Livewire/DonForm.php
git add app/Livewire/DonForm.php resources/views/livewire/don-form.blade.php
git commit -m "feat: DonForm utilise TiersAutocomplete (tiers_id) à la place du select donateur"
```

---

### Task 10 : Mettre à jour DepenseForm + RecetteForm

**Files:**
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

- [ ] **Step 1 : Confirmer que les tests actuels passent (avant modification)**

```bash
./vendor/bin/sail artisan test tests/Livewire/DepenseFormTest.php tests/Livewire/RecetteFormTest.php
```
Attendu : FAIL (les tests ont déjà été mis à jour à la Task 7 pour utiliser `tiers_id`).

- [ ] **Step 2 : Modifier `app/Livewire/DepenseForm.php`**

- Remplacer `public ?string $tiers = null;` → `public ?int $tiers_id = null;`
- Dans `showNewForm()` : changer `'tiers'` → `'tiers_id'` dans le reset
- Dans `edit()` : `$this->tiers = $depense->tiers;` → `$this->tiers_id = $depense->tiers_id;`
- Dans `resetForm()` : changer `'tiers'` → `'tiers_id'`
- Dans `save()` :
  - Valider `'tiers_id' => ['nullable', 'exists:tiers,id']` à la place de `tiers` string
  - Dans `$data` : `'tiers_id' => $this->tiers_id` à la place de `'tiers' => $this->tiers ?: null`
- Dans `render()` : supprimer le paramètre `tiers` s'il était passé à la vue

- [ ] **Step 3 : Modifier `resources/views/livewire/depense-form.blade.php`**

Lire le fichier et remplacer le champ texte `wire:model="tiers"` par :
```blade
<livewire:tiers-autocomplete wire:model="tiers_id" filtre="depenses" />
```

- [ ] **Step 4 : Faire les mêmes modifications sur `RecetteForm.php` et `recette-form.blade.php`**

(même structure, `filtre="recettes"`)

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/DepenseFormTest.php tests/Livewire/RecetteFormTest.php
```
Attendu : PASS.

- [ ] **Step 6 : Pint + commit**

```bash
./vendor/bin/sail artisan pint app/Livewire/DepenseForm.php app/Livewire/RecetteForm.php
git add app/Livewire/DepenseForm.php resources/views/livewire/depense-form.blade.php \
        app/Livewire/RecetteForm.php resources/views/livewire/recette-form.blade.php
git commit -m "feat: DepenseForm et RecetteForm utilisent TiersAutocomplete (tiers_id)"
```

---

### Task 11 : Mettre à jour CotisationForm + CotisationService

**Files:**
- Modify: `app/Livewire/CotisationForm.php`
- Modify: `resources/views/livewire/cotisation-form.blade.php`

CotisationForm reçoit `mount(Tiers $tiers)` à la place de `mount(Membre $membre)`.

- [ ] **Step 1 : Confirmer l'échec des tests CotisationForm** (mis à jour à la Task 6)

```bash
./vendor/bin/sail artisan test tests/Livewire/CotisationFormTest.php
```
Attendu : FAIL.

- [ ] **Step 2 : Mettre à jour `app/Livewire/CotisationForm.php`**

```php
<?php
declare(strict_types=1);

namespace App\Livewire;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Services\CotisationService;
use App\Services\ExerciceService;
use Livewire\Component;

final class CotisationForm extends Component
{
    public Tiers $tiers;

    public string $montant = '';

    public string $date_paiement = '';

    public string $mode_paiement = '';

    public string $compte_id = '';

    public function mount(Tiers $tiers): void
    {
        $this->tiers        = $tiers;
        $this->date_paiement = app(ExerciceService::class)->defaultDate();
    }

    public function save(): void
    {
        $exerciceService = app(ExerciceService::class);
        $range     = $exerciceService->dateRange($exerciceService->current());
        $dateDebut = $range['start']->toDateString();
        $dateFin   = $range['end']->toDateString();

        $validated = $this->validate([
            'montant'       => ['required', 'numeric', 'min:0.01'],
            'date_paiement' => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
            'mode_paiement' => ['required', 'string'],
            'compte_id'     => ['nullable'],
        ], [
            'date_paiement.after_or_equal'  => 'La date de paiement doit être dans l\'exercice en cours (à partir du '.$range['start']->format('d/m/Y').').',
            'date_paiement.before_or_equal' => 'La date de paiement doit être dans l\'exercice en cours (jusqu\'au '.$range['end']->format('d/m/Y').').',
        ]);

        $validated['exercice']  = app(ExerciceService::class)->current();
        $validated['compte_id'] = $validated['compte_id'] !== '' ? (int) $validated['compte_id'] : null;

        app(CotisationService::class)->create($this->tiers, $validated);

        $this->reset(['montant', 'mode_paiement', 'compte_id']);
        $this->date_paiement = app(ExerciceService::class)->defaultDate();

        $this->tiers->load('cotisations.compte');
    }

    public function delete(int $id): void
    {
        $cotisation = Cotisation::findOrFail($id);

        try {
            app(CotisationService::class)->delete($cotisation);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->tiers->load('cotisations.compte');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.cotisation-form', [
            'cotisations'   => $this->tiers->cotisations()->with('compte')->latest()->get(),
            'comptes'       => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }
}
```

- [ ] **Step 3 : Mettre à jour `resources/views/livewire/cotisation-form.blade.php`**

Lire le fichier et remplacer toutes les occurrences de `$membre` par `$tiers`.

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/CotisationFormTest.php
```
Attendu : PASS.

- [ ] **Step 5 : Pint + commit**

```bash
./vendor/bin/sail artisan pint app/Livewire/CotisationForm.php
git add app/Livewire/CotisationForm.php resources/views/livewire/cotisation-form.blade.php
git commit -m "feat: CotisationForm utilise Tiers à la place de Membre"
```

---

## Chunk 7 : Listes, Dashboard, MembreController, TransactionService (Tasks 12-14)

### Task 12 : DonList + Dashboard

**Files:**
- Modify: `app/Livewire/DonList.php`
- Modify: `resources/views/livewire/don-list.blade.php`
- Modify: `app/Livewire/DepenseList.php`
- Modify: `resources/views/livewire/depense-list.blade.php`
- Modify: `app/Livewire/Dashboard.php`
- Modify: `tests/Livewire/DashboardTest.php`

- [ ] **Step 1 : Mettre à jour `tests/Livewire/DashboardTest.php`**

Remplacer `Membre::factory()` par `Tiers::factory()->membre()` et `Cotisation::factory()->create(['membre_id' => ...])` par `Cotisation::factory()->create(['tiers_id' => ...])` :

```php
it('shows membres without cotisation', function () {
    $tiersAvecCotisation = Tiers::factory()->membre()->create([
        'nom' => 'Durand', 'prenom' => 'Marie',
    ]);
    Cotisation::factory()->create([
        'tiers_id' => $tiersAvecCotisation->id,
        'exercice' => $this->exercice,
    ]);

    $tiersSansCotisation = Tiers::factory()->membre()->create([
        'nom' => 'Martin', 'prenom' => 'Pierre',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Martin')
        ->assertSee('Pierre')
        ->assertDontSee('Durand');
});
```

- [ ] **Step 2 : Lancer pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/DashboardTest.php
```
Attendu : FAIL.

- [ ] **Step 3 : Mettre à jour `app/Livewire/Dashboard.php`**

Remplacer :
```php
use App\Models\Membre;
// ...
$membresSansCotisation = Membre::whereDoesntHave('cotisations', function ($q) use ($exercice) {
    $q->where('exercice', $exercice);
})->orderBy('nom')->get();
// et
->with('donateur')
```

Par :
```php
use App\Models\Tiers;
// ...
$membresSansCotisation = Tiers::query()->membres()
    ->whereDoesntHave('cotisations', function ($q) use ($exercice): void {
        $q->where('exercice', $exercice);
    })
    ->orderBy('nom')
    ->get();
// et
->with('tiers')
```

- [ ] **Step 4 : Mettre à jour `app/Livewire/DonList.php`**

Remplacer `$donateur_search` → `$tiers_search`, la méthode `updatedDonateurSearch` → `updatedTiersSearch`, et le filtre de requête :

```php
public string $tiers_search = '';

public function updatedTiersSearch(): void { $this->resetPage(); }

// Dans render() :
if ($this->tiers_search !== '') {
    $search = $this->tiers_search;
    $query->whereHas('tiers', function ($q) use ($search): void {
        $q->where('nom', 'like', "%{$search}%")
          ->orWhere('prenom', 'like', "%{$search}%");
    });
}
```

Supprimer `toggleDonateurHistory` et `$showDonateurId` s'ils ne sont plus utilisés dans la vue.

- [ ] **Step 5 : Mettre à jour `app/Livewire/DepenseList.php`**

Le filtre `$tiers` (string) cherchait sur `depenses.tiers`. Maintenant on fait une JOIN via la relation :

```php
// Changer dans render() :
if ($this->tiers) {
    $tiersSearch = $this->tiers;
    $query->whereHas('tiers', function ($q) use ($tiersSearch): void {
        $q->where('nom', 'like', "%{$tiersSearch}%")
          ->orWhere('prenom', 'like', "%{$tiersSearch}%");
    });
}
```

- [ ] **Step 6 : Lancer toute la suite**

```bash
./vendor/bin/sail artisan test tests/Livewire/DashboardTest.php tests/Livewire/DonListTest.php
```
Attendu : PASS.

- [ ] **Step 7 : Pint + commit**

```bash
./vendor/bin/sail artisan pint app/Livewire/Dashboard.php app/Livewire/DonList.php app/Livewire/DepenseList.php
git add app/Livewire/Dashboard.php resources/views/livewire/don-list.blade.php \
        app/Livewire/DonList.php app/Livewire/DepenseList.php resources/views/livewire/depense-list.blade.php \
        tests/Livewire/DashboardTest.php
git commit -m "feat: DonList + Dashboard + DepenseList utilisent Tiers (filtre tiers_search)"
```

---

### Task 13 : MembreController + vues + routes

**Files:**
- Modify: `app/Http/Controllers/MembreController.php`
- Modify: `app/Http/Requests/StoreMembreRequest.php`
- Modify: `app/Http/Requests/UpdateMembreRequest.php`
- Modify: `resources/views/membres/index.blade.php`
- Modify: `resources/views/membres/create.blade.php`
- Modify: `resources/views/membres/edit.blade.php`
- Modify: `resources/views/membres/show.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/MembreTest.php`

Le MembreController gère les Tiers avec `statut_membre NOT NULL` (ce sont les membres). L'URL `/membres` reste identique. Le paramètre de route change de `{membre}` à `{tiers}`.

- [ ] **Step 1 : Mettre à jour `tests/Feature/MembreTest.php`**

```php
<?php

declare(strict_types=1);

use App\Models\Tiers;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication to access membres index', function () {
    $this->get(route('membres.index'))
        ->assertRedirect(route('login'));
});

it('can list membres', function () {
    $tiers = Tiers::factory()->membre()->create();

    $this->actingAs($this->user)
        ->get(route('membres.index'))
        ->assertOk()
        ->assertSee($tiers->nom)
        ->assertSee($tiers->prenom);
});

it('can create a membre with valid data', function () {
    $this->actingAs($this->user)
        ->post(route('membres.store'), [
            'nom'           => 'Dupont',
            'prenom'        => 'Jean',
            'type'          => 'particulier',
            'email'         => 'jean@example.com',
            'telephone'     => '0612345678',
            'adresse'       => '1 rue de Paris',
            'date_adhesion' => '2025-01-15',
            'statut_membre' => 'actif',
            'notes_membre'  => 'Test notes',
        ])
        ->assertRedirect(route('membres.index'));

    $this->assertDatabaseHas('tiers', [
        'nom'    => 'Dupont',
        'prenom' => 'Jean',
        'email'  => 'jean@example.com',
    ]);
});

it('validates required fields when creating a membre', function () {
    $this->actingAs($this->user)
        ->post(route('membres.store'), [])
        ->assertSessionHasErrors(['nom', 'statut_membre']);
});

it('can view membre show page', function () {
    $tiers = Tiers::factory()->membre()->create();

    $this->actingAs($this->user)
        ->get(route('membres.show', $tiers))
        ->assertOk()
        ->assertSee($tiers->nom)
        ->assertSee($tiers->prenom);
});
```

- [ ] **Step 2 : Lancer pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/MembreTest.php
```
Attendu : FAIL.

- [ ] **Step 3 : Mettre à jour `routes/web.php`**

```php
Route::resource('membres', MembreController::class)->parameters(['membres' => 'tiers']);
```

- [ ] **Step 4 : Mettre à jour `app/Http/Requests/StoreMembreRequest.php`**

```php
public function rules(): array
{
    return [
        'nom'           => ['required', 'string', 'max:100'],
        'prenom'        => ['nullable', 'string', 'max:100'],
        'type'          => ['required', 'in:entreprise,particulier'],
        'email'         => ['nullable', 'email', 'max:150'],
        'telephone'     => ['nullable', 'string', 'max:20'],
        'adresse'       => ['nullable', 'string'],
        'date_adhesion' => ['nullable', 'date'],
        'statut_membre' => ['required', 'in:actif,inactif'],
        'notes_membre'  => ['nullable', 'string'],
    ];
}
```

- [ ] **Step 5 : Mettre à jour `app/Http/Requests/UpdateMembreRequest.php`** (idem)

- [ ] **Step 6 : Mettre à jour `app/Http/Controllers/MembreController.php`**

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreMembreRequest;
use App\Http\Requests\UpdateMembreRequest;
use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\TiersService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class MembreController extends Controller
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    public function index(): View
    {
        $exercice = $this->exerciceService->current();

        $membres = Tiers::query()->membres()
            ->with(['cotisations' => function ($query) use ($exercice): void {
                $query->forExercice($exercice);
            }])
            ->orderBy('nom')
            ->get();

        return view('membres.index', [
            'membres'       => $membres,
            'exercice'      => $exercice,
            'exerciceLabel' => $this->exerciceService->label($exercice),
        ]);
    }

    public function create(): View
    {
        return view('membres.create');
    }

    public function store(StoreMembreRequest $request): RedirectResponse
    {
        Tiers::create($request->validated());

        return redirect()->route('membres.index')
            ->with('success', 'Membre ajouté avec succès.');
    }

    public function show(Tiers $tiers): View
    {
        $tiers->load('cotisations.compte');

        return view('membres.show', ['membre' => $tiers]);
    }

    public function edit(Tiers $tiers): View
    {
        return view('membres.edit', ['membre' => $tiers]);
    }

    public function update(UpdateMembreRequest $request, Tiers $tiers): RedirectResponse
    {
        $tiers->update($request->validated());

        return redirect()->route('membres.show', $tiers)
            ->with('success', 'Membre mis à jour avec succès.');
    }

    public function destroy(Tiers $tiers): RedirectResponse
    {
        try {
            app(\App\Services\TiersService::class)->delete($tiers);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('membres.index')
            ->with('success', 'Membre supprimé avec succès.');
    }
}
```

Note : on passe `$tiers` à la vue sous le nom `$membre` pour que les vues Blade n'aient pas à changer leurs variables (les vues restent identiques dans leur structure, on change juste les références aux propriétés si nécessaire).

- [ ] **Step 7 : Mettre à jour les vues `resources/views/membres/`**

Dans `show.blade.php` :
- Changer `<livewire:cotisation-form :membre="$membre" />` → `<livewire:cotisation-form :tiers="$membre" />`
- Changer `$membre->statut === \App\Enums\StatutMembre::Actif` → `$membre->statut_membre === 'actif'`
- Changer `$membre->statut->label()` → `ucfirst($membre->statut_membre ?? '')`
- Changer `$membre->date_adhesion?->format('d/m/Y')` reste valide (Tiers a date_adhesion casté en date)
- Changer `$membre->notes` → `$membre->notes_membre`

Dans `index.blade.php` : remplacer `$membre->statut` → `$membre->statut_membre` si utilisé.

Dans `create.blade.php` et `edit.blade.php` : ajouter/remplacer les champs `statut_membre`, `type`, supprimer `statut` (ancien field).

- [ ] **Step 8 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/MembreTest.php
```
Attendu : PASS.

- [ ] **Step 9 : Pint + commit**

```bash
./vendor/bin/sail artisan pint app/Http/Controllers/MembreController.php app/Http/Requests/
git add -u
git commit -m "feat: MembreController utilise Tiers filtré par statut_membre, vues mises à jour"
```

---

### Task 14 : TransactionCompteService — mettre à jour tous les JOINs

**Files:**
- Modify: `app/Services/TransactionCompteService.php`
- Modify: `tests/Feature/Services/TransactionCompteServiceTest.php`

- [ ] **Step 1 : Mettre à jour `tests/Feature/Services/TransactionCompteServiceTest.php`**

Remplacer :
- `Donateur::factory()->create(...)` → `Tiers::factory()->pourRecettes()->create(...)`
- `'donateur_id' => $donateur->id` → `'tiers_id' → $tiers->id`
- `Membre::factory()->create(...)` → `Tiers::factory()->membre()->create(...)`
- `'membre_id' => $membre->id` → `'tiers_id' => $tiers->id`
- Corriger les assertions sur `$don->tiers` (affichage du nom via CASE WHEN)

Exemple du test mis à jour pour les dons :

```php
it('retourne un don avec le nom du tiers comme tiers', function () {
    $tiers = Tiers::factory()->pourRecettes()->create(['type' => 'particulier', 'prenom' => 'Marie', 'nom' => 'Dupont']);
    // ...
    Don::factory()->create(['tiers_id' => $tiers->id, 'compte_id' => $compte->id, 'date' => '2025-11-01', 'saisi_par' => $userId]);
    // ...
    expect(trim($don->tiers))->toContain('Marie');
    expect(trim($don->tiers))->toContain('Dupont');
});

it('retourne une cotisation avec le nom du tiers comme tiers', function () {
    $tiers = Tiers::factory()->membre()->create(['prenom' => 'Jean', 'nom' => 'Martin']);
    // ...
    Cotisation::factory()->create(['tiers_id' => $tiers->id, ...]);
    // ...
    expect(trim($cotisation->tiers))->toContain('Jean');
    expect(trim($cotisation->tiers))->toContain('Martin');
});
```

Lire le fichier complet avant de le modifier pour voir tous les tests à adapter.

- [ ] **Step 2 : Lancer les tests pour confirmer l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/TransactionCompteServiceTest.php
```
Attendu : FAIL.

- [ ] **Step 3 : Mettre à jour `app/Services/TransactionCompteService.php`**

Remplacer dans `buildUnion()` :

Pour `$recettes` — remplacer `r.tiers` (string) par JOIN :
```php
$recettes = DB::table('recettes as r')
    ->leftJoin('tiers as tr', 'tr.id', '=', 'r.tiers_id')
    ->selectRaw("r.id, 'recette' as source_type, r.date, 'Recette' as type_label,
        CASE WHEN tr.type = 'entreprise' THEN tr.nom
             ELSE TRIM(CONCAT(COALESCE(tr.prenom,''), ' ', COALESCE(tr.nom,'')))
        END as tiers,
        r.libelle, r.reference, r.montant_total as montant, r.mode_paiement, r.pointe, r.numero_piece")
    ->where('r.compte_id', $id)
    ->whereNull('r.deleted_at')
    ->when($dateDebut, fn (Builder $q) => $q->where('r.date', '>=', $dateDebut))
    ->when($dateFin, fn (Builder $q) => $q->where('r.date', '<=', $dateFin))
    ->when($tiersLike, fn (Builder $q) => $q->where(function (Builder $inner) use ($tiersLike): void {
        $inner->where('tr.nom', 'like', $tiersLike)
              ->orWhere('tr.prenom', 'like', $tiersLike);
    }));
```

Pour `$depenses` — même structure avec `d.tiers_id` → JOIN `tiers as td` :
```php
$depenses = DB::table('depenses as d')
    ->leftJoin('tiers as td', 'td.id', '=', 'd.tiers_id')
    ->selectRaw("d.id, 'depense' as source_type, d.date, 'Dépense' as type_label,
        CASE WHEN td.type = 'entreprise' THEN td.nom
             ELSE TRIM(CONCAT(COALESCE(td.prenom,''), ' ', COALESCE(td.nom,'')))
        END as tiers,
        d.libelle, d.reference, -(d.montant_total) as montant, d.mode_paiement, d.pointe, d.numero_piece")
    ->where('d.compte_id', $id)
    ->whereNull('d.deleted_at')
    ->when($dateDebut, fn (Builder $q) => $q->where('d.date', '>=', $dateDebut))
    ->when($dateFin, fn (Builder $q) => $q->where('d.date', '<=', $dateFin))
    ->when($tiersLike, fn (Builder $q) => $q->where(function (Builder $inner) use ($tiersLike): void {
        $inner->where('td.nom', 'like', $tiersLike)
              ->orWhere('td.prenom', 'like', $tiersLike);
    }));
```

Pour `$dons` — remplacer JOIN `donateurs` par JOIN `tiers as tn` :
```php
$dons = DB::table('dons as dn')
    ->leftJoin('tiers as tn', 'tn.id', '=', 'dn.tiers_id')
    ->selectRaw("dn.id, 'don' as source_type, dn.date, 'Don' as type_label,
        CASE WHEN tn.type = 'entreprise' THEN tn.nom
             ELSE TRIM(CONCAT(COALESCE(tn.prenom,''), ' ', COALESCE(tn.nom,'')))
        END as tiers,
        dn.objet as libelle, NULL as reference, dn.montant, dn.mode_paiement, dn.pointe, dn.numero_piece")
    ->where('dn.compte_id', $id)
    ->whereNull('dn.deleted_at')
    ->when($dateDebut, fn (Builder $q) => $q->where('dn.date', '>=', $dateDebut))
    ->when($dateFin, fn (Builder $q) => $q->where('dn.date', '<=', $dateFin))
    ->when($tiersLike, fn (Builder $q) => $q->where(function (Builder $inner) use ($tiersLike): void {
        $inner->where('tn.nom', 'like', $tiersLike)
              ->orWhere('tn.prenom', 'like', $tiersLike);
    }));
```

Pour `$cotisations` — remplacer JOIN `membres` par JOIN `tiers as tc` :
```php
$cotisations = DB::table('cotisations as c')
    ->leftJoin('tiers as tc', 'tc.id', '=', 'c.tiers_id')
    ->selectRaw("c.id, 'cotisation' as source_type, c.date_paiement as date, 'Cotisation' as type_label,
        CASE WHEN tc.type = 'entreprise' THEN tc.nom
             ELSE TRIM(CONCAT(COALESCE(tc.prenom,''), ' ', COALESCE(tc.nom,'')))
        END as tiers,
        CONCAT('Cotisation ', c.exercice) as libelle, NULL as reference, c.montant, c.mode_paiement, c.pointe, c.numero_piece")
    ->where('c.compte_id', $id)
    ->whereNull('c.deleted_at')
    ->when($dateDebut, fn (Builder $q) => $q->where('c.date_paiement', '>=', $dateDebut))
    ->when($dateFin, fn (Builder $q) => $q->where('c.date_paiement', '<=', $dateFin))
    ->when($tiersLike, fn (Builder $q) => $q->where(function (Builder $inner) use ($tiersLike): void {
        $inner->where('tc.nom', 'like', $tiersLike)
              ->orWhere('tc.prenom', 'like', $tiersLike);
    }));
```

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/TransactionCompteServiceTest.php
```
Attendu : PASS.

- [ ] **Step 5 : Lancer toute la suite de tests**

```bash
./vendor/bin/sail artisan test
```
Attendu : toute la suite passe.

- [ ] **Step 6 : Pint + commit**

```bash
./vendor/bin/sail artisan pint app/Services/TransactionCompteService.php
git add app/Services/TransactionCompteService.php tests/Feature/Services/TransactionCompteServiceTest.php
git commit -m "feat: TransactionCompteService utilise tiers JOIN pour dons, cotisations, depenses, recettes"
```

---

## Vérification finale

- [ ] Lancer toute la suite de tests une dernière fois

```bash
./vendor/bin/sail artisan test
```
Attendu : toute la suite verte, 0 erreur.

- [ ] Vérifier manuellement dans le navigateur :
  - `/depenses` → champ tiers autocomplete fonctionne
  - `/dons` → champ tiers autocomplete fonctionne
  - `/membres` → liste les tiers avec statut_membre, cotisations fonctionnent
  - Compte bancaire → liste des transactions affiche correctement les tiers

- [ ] Si tout est vert → utiliser `superpowers:finishing-a-development-branch`
