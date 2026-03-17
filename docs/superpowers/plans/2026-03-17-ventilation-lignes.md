# Ventilation de lignes & verrouillage — Plan d'implémentation

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre d'affecter analytiquement une ligne de recette/dépense à plusieurs opérations via une table dédiée, et solidifier le verrouillage post-rapprochement dans la couche service.

**Architecture:** Table d'affectations séparée (`recette_ligne_affectations` / `depense_ligne_affectations`) — la ligne source reste intacte. `RecetteService::update()` / `DepenseService::update()` enforces les invariants. `RapportService` résout les affectations à la requête.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Pest PHP

---

## Avant de commencer

```bash
# Créer la branche de travail
git checkout -b feature/ventilation-lignes

# Démarrer l'environnement
docker compose -f ../svs-infra/compose.yaml up -d

# Vérifier que les tests passent au départ
./vendor/bin/sail pest --stop-on-failure
```

---

## Chunk 1 : Couche données — migrations et modèles

### Task 1 : Migration `recette_ligne_affectations`

**Files:**
- Create: `database/migrations/2026_03_17_000001_create_recette_ligne_affectations_table.php`

- [ ] **Écrire la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recette_ligne_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recette_ligne_id')->constrained('recette_lignes')->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->unsignedInteger('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recette_ligne_affectations');
    }
};
```

- [ ] **Exécuter la migration**

```bash
./vendor/bin/sail artisan migrate
```

Attendu : `Migrating: 2026_03_17_000001_create_recette_ligne_affectations_table` puis `Migrated`.

- [ ] **Committer**

```bash
git add database/migrations/2026_03_17_000001_create_recette_ligne_affectations_table.php
git commit -m "feat: migration recette_ligne_affectations"
```

---

### Task 2 : Migration `depense_ligne_affectations`

**Files:**
- Create: `database/migrations/2026_03_17_000002_create_depense_ligne_affectations_table.php`

- [ ] **Écrire la migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depense_ligne_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depense_ligne_id')->constrained('depense_lignes')->cascadeOnDelete();
            $table->foreignId('operation_id')->nullable()->constrained('operations')->nullOnDelete();
            $table->unsignedInteger('seance')->nullable();
            $table->decimal('montant', 10, 2);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depense_ligne_affectations');
    }
};
```

- [ ] **Exécuter la migration**

```bash
./vendor/bin/sail artisan migrate
```

- [ ] **Committer**

```bash
git add database/migrations/2026_03_17_000002_create_depense_ligne_affectations_table.php
git commit -m "feat: migration depense_ligne_affectations"
```

---

### Task 3 : Modèle `RecetteLigneAffectation`

**Files:**
- Create: `app/Models/RecetteLigneAffectation.php`
- Modify: `app/Models/RecetteLigne.php`

- [ ] **Créer le modèle**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RecetteLigneAffectation extends Model
{
    protected $table = 'recette_ligne_affectations';

    protected $fillable = [
        'recette_ligne_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'recette_ligne_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
        ];
    }

    public function recetteLigne(): BelongsTo
    {
        return $this->belongsTo(RecetteLigne::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
```

- [ ] **Ajouter la relation `hasMany` dans `RecetteLigne`**

Dans `app/Models/RecetteLigne.php`, ajouter après la méthode `operation()` :

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function affectations(): HasMany
{
    return $this->hasMany(RecetteLigneAffectation::class);
}
```

Et ajouter l'import `HasMany` dans les `use` en haut du fichier.

- [ ] **Committer**

```bash
git add app/Models/RecetteLigneAffectation.php app/Models/RecetteLigne.php
git commit -m "feat: modèle RecetteLigneAffectation et relation hasMany"
```

---

### Task 4 : Modèle `DepenseLigneAffectation`

**Files:**
- Create: `app/Models/DepenseLigneAffectation.php`
- Modify: `app/Models/DepenseLigne.php`

- [ ] **Créer le modèle**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DepenseLigneAffectation extends Model
{
    protected $table = 'depense_ligne_affectations';

    protected $fillable = [
        'depense_ligne_id',
        'operation_id',
        'seance',
        'montant',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'depense_ligne_id' => 'integer',
            'operation_id' => 'integer',
            'seance' => 'integer',
        ];
    }

    public function depenseLigne(): BelongsTo
    {
        return $this->belongsTo(DepenseLigne::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
```

- [ ] **Ajouter la relation `hasMany` dans `DepenseLigne`**

Dans `app/Models/DepenseLigne.php`, ajouter après la méthode `operation()` :

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

public function affectations(): HasMany
{
    return $this->hasMany(DepenseLigneAffectation::class);
}
```

- [ ] **Vérifier que les tests existants passent**

```bash
./vendor/bin/sail pest --stop-on-failure
```

Attendu : tous verts.

- [ ] **Committer**

```bash
git add app/Models/DepenseLigneAffectation.php app/Models/DepenseLigne.php
git commit -m "feat: modèle DepenseLigneAffectation et relation hasMany"
```

---

## Chunk 2 : Couche service — verrouillage et affectations

### Task 5 : Tests du verrouillage `RecetteService`

**Files:**
- Create: `tests/Feature/RecetteServiceLockTest.php`

- [ ] **Écrire les tests**

```php
<?php

use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\RecetteService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(RecetteService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->sousCategorie = SousCategorie::factory()->create();
});

function makeLockedRecette(CompteBancaire $compte): Recette
{
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id'    => $compte->id,
        'statut'       => \App\Enums\StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);
    $recette = Recette::factory()->create([
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 100.00,
        'rapprochement_id' => $rapprochement->id,
    ]);
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'montant' => 100.00,
    ]);
    return $recette->fresh(['lignes', 'rapprochement']);
}

it('update rejette la modification de date sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => '2025-11-01',
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de compte_id sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $autreCompte = CompteBancaire::factory()->create();
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $autreCompte->id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de montant de ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '999.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de sous_categorie_id de ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $autreSousCategorie = SousCategorie::factory()->create();
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $autreSousCategorie->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette l\'ajout d\'une ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [
        ['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
        ['sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ])
    )->toThrow(\RuntimeException::class);
});

it('update accepte la modification de libelle et notes sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => 'Nouveau libellé',
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
        'notes' => 'Nouvelle note',
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($recette->fresh()->libelle)->toBe('Nouveau libellé');
    expect($recette->fresh()->notes)->toBe('Nouvelle note');
});

it('update accepte la modification d\'operation_id de ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $operation = \App\Models\Operation::factory()->create();
    $ligne = $recette->lignes->first();

    $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => $operation->id, 'seance' => null, 'notes' => null]]);

    expect($recette->fresh(['lignes'])->lignes->first()->operation_id)->toBe($operation->id);
});

it('update accepte la modification de tiers_id sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $tiers = \App\Models\Tiers::factory()->create();
    $ligne = $recette->lignes->first();

    $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
        'tiers_id' => $tiers->id,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($recette->fresh()->tiers_id)->toBe($tiers->id);
});

it('update rejette la suppression d\'une ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    // La recette a 1 ligne, on soumet 0 lignes
    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [])
    )->toThrow(\RuntimeException::class);
});
```

- [ ] **Lancer les tests pour confirmer qu'ils échouent**

```bash
./vendor/bin/sail pest tests/Feature/RecetteServiceLockTest.php
```

Attendu : tous FAIL (service pas encore modifié).

- [ ] **Committer les tests**

```bash
git add tests/Feature/RecetteServiceLockTest.php
git commit -m "test: verrouillage RecetteService — tests en attente d'implémentation"
```

---

### Task 6 : Implémentation du verrouillage dans `RecetteService`

**Files:**
- Modify: `app/Services/RecetteService.php`

- [ ] **Remplacer `update()` par la version avec enforcement**

```php
public function update(Recette $recette, array $data, array $lignes): Recette
{
    $recette->loadMissing('rapprochement');

    if ($recette->isLockedByRapprochement()) {
        $this->assertLockedInvariants($recette, $data, $lignes);
    }

    return DB::transaction(function () use ($recette, $data, $lignes) {
        $recette->update($data);

        if ($recette->isLockedByRapprochement()) {
            // Pièce verrouillée : mise à jour ligne par ligne via ID
            foreach ($lignes as $ligneData) {
                $recette->lignes()->where('id', $ligneData['id'])->update([
                    'operation_id' => $ligneData['operation_id'],
                    'seance'       => $ligneData['seance'],
                    'notes'        => $ligneData['notes'],
                ]);
            }
        } else {
            // Pièce non verrouillée : comportement existant
            $recette->lignes()->forceDelete();
            foreach ($lignes as $ligne) {
                $recette->lignes()->create($ligne);
            }
        }

        return $recette->fresh();
    });
}

private function assertLockedInvariants(Recette $recette, array $data, array $lignes): void
{
    if ($recette->date->format('Y-m-d') !== $data['date']) {
        throw new \RuntimeException('La date ne peut pas être modifiée sur une recette rapprochée.');
    }

    if ((int) $recette->compte_id !== (int) $data['compte_id']) {
        throw new \RuntimeException('Le compte bancaire ne peut pas être modifié sur une recette rapprochée.');
    }

    if ((int) round((float) $recette->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
        throw new \RuntimeException('Le montant total ne peut pas être modifié sur une recette rapprochée.');
    }

    $existingLignes = $recette->lignes()->get()->keyBy('id');

    if (count($lignes) !== $existingLignes->count()) {
        throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une recette rapprochée.');
    }

    foreach ($lignes as $ligneData) {
        $id = $ligneData['id'] ?? null;
        if ($id === null || ! $existingLignes->has($id)) {
            throw new \RuntimeException('Ligne inconnue ou sans identifiant sur une recette rapprochée.');
        }
        $existing = $existingLignes->get($id);
        if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
            throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une recette rapprochée.');
        }
        if ((int) $existing->sous_categorie_id !== (int) $ligneData['sous_categorie_id']) {
            throw new \RuntimeException('La sous-catégorie d\'une ligne ne peut pas être modifiée sur une recette rapprochée.');
        }
    }
}
```

- [ ] **Lancer les tests**

```bash
./vendor/bin/sail pest tests/Feature/RecetteServiceLockTest.php
```

Attendu : tous verts.

- [ ] **Lancer tous les tests**

```bash
./vendor/bin/sail pest --stop-on-failure
```

Attendu : aucune régression.

- [ ] **Committer**

```bash
git add app/Services/RecetteService.php
git commit -m "feat: verrouillage RecetteService — enforcement dans la couche service"
```

---

### Task 7 : Verrouillage `DepenseService` (symétrique)

**Files:**
- Create: `tests/Feature/DepenseServiceLockTest.php`
- Modify: `app/Services/DepenseService.php`

- [ ] **Écrire les tests** (tous les cas, symétrique à `RecetteServiceLockTest`)

```php
<?php

use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\DepenseService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DepenseService::class);
    $this->compte = CompteBancaire::factory()->create();
});

function makeLockedDepense(CompteBancaire $compte): Depense
{
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id'    => $compte->id,
        'statut'       => \App\Enums\StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);
    $depense = Depense::factory()->create([
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
    ]);
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'montant' => 200.00,
    ]);
    return $depense->fresh(['lignes', 'rapprochement']);
}

it('update rejette la modification de date sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => '2025-12-01',
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de compte_id sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $autreCompte = CompteBancaire::factory()->create();
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $autreCompte->id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de montant de ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '999.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de sous_categorie_id de ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $autreSousCategorie = SousCategorie::factory()->create();
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $autreSousCategorie->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette l\'ajout d\'une ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [
        ['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
        ['sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ])
    )->toThrow(\RuntimeException::class);
});

it('update accepte la modification de tiers_id sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $tiers = \App\Models\Tiers::factory()->create();
    $ligne = $depense->lignes->first();

    $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
        'tiers_id' => $tiers->id,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($depense->fresh()->tiers_id)->toBe($tiers->id);
});

it('update accepte la modification de libelle et notes sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => 'Libellé modifié',
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
        'notes' => 'Nouvelle note',
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($depense->fresh()->libelle)->toBe('Libellé modifié');
    expect($depense->fresh()->notes)->toBe('Nouvelle note');
});

it('update accepte la modification d\'operation_id de ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $operation = Operation::factory()->create();
    $ligne = $depense->lignes->first();

    $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => $operation->id, 'seance' => null, 'notes' => null]]);

    expect($depense->fresh(['lignes'])->lignes->first()->operation_id)->toBe($operation->id);
});
```

- [ ] **Lancer les tests pour confirmer l'échec**

```bash
./vendor/bin/sail pest tests/Feature/DepenseServiceLockTest.php
```

- [ ] **Implémenter le verrouillage dans `DepenseService`**

Remplacer `update()` par :

```php
public function update(Depense $depense, array $data, array $lignes): Depense
{
    $depense->loadMissing('rapprochement');

    if ($depense->isLockedByRapprochement()) {
        $this->assertLockedInvariants($depense, $data, $lignes);
    }

    return DB::transaction(function () use ($depense, $data, $lignes) {
        $depense->update($data);

        if ($depense->isLockedByRapprochement()) {
            foreach ($lignes as $ligneData) {
                $depense->lignes()->where('id', $ligneData['id'])->update([
                    'operation_id' => $ligneData['operation_id'],
                    'seance'       => $ligneData['seance'],
                    'notes'        => $ligneData['notes'],
                ]);
            }
        } else {
            $depense->lignes()->forceDelete();
            foreach ($lignes as $ligne) {
                $depense->lignes()->create($ligne);
            }
        }

        return $depense->fresh();
    });
}

private function assertLockedInvariants(Depense $depense, array $data, array $lignes): void
{
    if ($depense->date->format('Y-m-d') !== $data['date']) {
        throw new \RuntimeException('La date ne peut pas être modifiée sur une dépense rapprochée.');
    }

    if ((int) $depense->compte_id !== (int) $data['compte_id']) {
        throw new \RuntimeException('Le compte bancaire ne peut pas être modifié sur une dépense rapprochée.');
    }

    if ((int) round((float) $depense->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
        throw new \RuntimeException('Le montant total ne peut pas être modifié sur une dépense rapprochée.');
    }

    $existingLignes = $depense->lignes()->get()->keyBy('id');

    if (count($lignes) !== $existingLignes->count()) {
        throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une dépense rapprochée.');
    }

    foreach ($lignes as $ligneData) {
        $id = $ligneData['id'] ?? null;
        if ($id === null || ! $existingLignes->has($id)) {
            throw new \RuntimeException('Ligne inconnue ou sans identifiant sur une dépense rapprochée.');
        }
        $existing = $existingLignes->get($id);
        if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
            throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une dépense rapprochée.');
        }
        if ((int) $existing->sous_categorie_id !== (int) $ligneData['sous_categorie_id']) {
            throw new \RuntimeException('La sous-catégorie d\'une ligne ne peut pas être modifiée sur une dépense rapprochée.');
        }
    }
}
```

- [ ] **Lancer tous les tests**

```bash
./vendor/bin/sail pest --stop-on-failure
```

- [ ] **Committer**

```bash
git add tests/Feature/DepenseServiceLockTest.php app/Services/DepenseService.php
git commit -m "feat: verrouillage DepenseService — enforcement dans la couche service"
```

---

### Task 8 : Supprimer la re-congélation dans les composants Livewire

**Files:**
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `app/Livewire/DepenseForm.php`

- [ ] **Dans `RecetteForm::save()`, supprimer le bloc de re-congélation**

> Note : les numéros de ligne ci-dessous correspondent à `RecetteForm` au moment de la rédaction du plan. Vérifier dans le fichier avant d'éditer.

> **Important — validation de date :** La règle Livewire `after_or_equal: dateDebut` s'applique encore après la suppression de la re-congélation. Pour une recette verrouillée dont la date appartient à un exercice passé, cette validation bloquerait l'édition des champs mutables. Il faut conditionner la validation de date à la pièce non verrouillée. Remplacer la règle :
> ```php
> 'date' => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
> ```
> par :
> ```php
> 'date' => $this->isLocked
>     ? ['required', 'date']
>     : ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
> ```
> Appliquer le même correctif dans `DepenseForm`.

Supprimer ces lignes (actuellement lignes 121-133) :

```php
if ($this->recetteId) {
    $existing = Recette::with('lignes')->findOrFail($this->recetteId);
    if ($existing->isLockedByRapprochement()) {
        // Re-freeze the locked fields from the DB, ignoring user input
        $this->date = $existing->date->format('Y-m-d');
        $this->compte_id = $existing->compte_id;
        // Re-freeze ligne montants
        foreach ($existing->lignes as $i => $ligne) {
            if (isset($this->lignes[$i])) {
                $this->lignes[$i]['montant'] = (string) $ligne->montant;
            }
        }
    }
}
```

- [ ] **Dans `RecetteForm::edit()`, stocker l'`id` de chaque ligne**

Modifier le mapping des lignes (actuellement ligne 98-104) pour inclure l'`id` :

```php
$this->lignes = $recette->lignes->map(fn ($ligne) => [
    'id'               => $ligne->id,
    'sous_categorie_id' => (string) $ligne->sous_categorie_id,
    'operation_id'     => (string) ($ligne->operation_id ?? ''),
    'seance'           => (string) ($ligne->seance ?? ''),
    'montant'          => (string) $ligne->montant,
    'notes'            => (string) ($ligne->notes ?? ''),
])->toArray();
```

- [ ] **Dans `RecetteForm::save()`, transmettre l'`id` au service**

Modifier le mapping des lignes avant l'appel au service (actuellement ligne 173-179) :

```php
$lignes = collect($this->lignes)->map(fn ($l) => [
    'id'               => isset($l['id']) ? (int) $l['id'] : null,
    'sous_categorie_id' => (int) $l['sous_categorie_id'],
    'operation_id'     => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
    'seance'           => $l['seance'] !== '' ? (int) $l['seance'] : null,
    'montant'          => $l['montant'],
    'notes'            => $l['notes'] ?: null,
])->toArray();
```

- [ ] **Dans `DepenseForm::save()`, supprimer le bloc de re-congélation**

> Note : les numéros de ligne ci-dessous correspondent à `DepenseForm` au moment de la rédaction du plan. Vérifier dans le fichier avant d'éditer.

Supprimer ces lignes (actuellement lignes 121-133 de `DepenseForm`) :

```php
if ($this->depenseId) {
    $existing = Depense::with('lignes')->findOrFail($this->depenseId);
    if ($existing->isLockedByRapprochement()) {
        // Re-freeze the locked fields from the DB, ignoring user input
        $this->date = $existing->date->format('Y-m-d');
        $this->compte_id = $existing->compte_id;
        // Re-freeze ligne montants
        foreach ($existing->lignes as $i => $ligne) {
            if (isset($this->lignes[$i])) {
                $this->lignes[$i]['montant'] = (string) $ligne->montant;
            }
        }
    }
}
```

- [ ] **Dans `DepenseForm::edit()`, stocker l'`id` de chaque ligne**

Modifier le mapping des lignes (actuellement lignes 98-104 de `DepenseForm`) :

```php
$this->lignes = $depense->lignes->map(fn ($ligne) => [
    'id'               => $ligne->id,
    'sous_categorie_id' => (string) $ligne->sous_categorie_id,
    'operation_id'     => (string) ($ligne->operation_id ?? ''),
    'seance'           => (string) ($ligne->seance ?? ''),
    'montant'          => (string) $ligne->montant,
    'notes'            => (string) ($ligne->notes ?? ''),
])->toArray();
```

- [ ] **Dans `DepenseForm::save()`, transmettre l'`id` au service**

Modifier le mapping des lignes (actuellement lignes 173-179 de `DepenseForm`) :

```php
$lignes = collect($this->lignes)->map(fn ($l) => [
    'id'               => isset($l['id']) ? (int) $l['id'] : null,
    'sous_categorie_id' => (int) $l['sous_categorie_id'],
    'operation_id'     => $l['operation_id'] !== '' ? (int) $l['operation_id'] : null,
    'seance'           => $l['seance'] !== '' ? (int) $l['seance'] : null,
    'montant'          => $l['montant'],
    'notes'            => $l['notes'] ?: null,
])->toArray();
```

- [ ] **Dans `RecetteForm::addLigne()`, inclure la clé `id`**

> Prérequis pour Task 12 : `isset($ligne['id'])` dans la vue blade et `pluck('id')` dans `render()` supposent que la clé `id` est toujours présente dans chaque élément de `$this->lignes`. Les lignes ajoutées manuellement doivent avoir `'id' => null` (pas encore persistées).

Remplacer la méthode `addLigne()` dans `RecetteForm` :

```php
public function addLigne(): void
{
    $this->lignes[] = [
        'id'               => null,
        'sous_categorie_id' => '',
        'operation_id'     => '',
        'seance'           => '',
        'montant'          => '',
        'notes'            => '',
    ];
}
```

- [ ] **Dans `DepenseForm::addLigne()`, inclure la clé `id`**

Remplacer la méthode `addLigne()` dans `DepenseForm` (même structure) :

```php
public function addLigne(): void
{
    $this->lignes[] = [
        'id'               => null,
        'sous_categorie_id' => '',
        'operation_id'     => '',
        'seance'           => '',
        'montant'          => '',
        'notes'            => '',
    ];
}
```

- [ ] **Lancer tous les tests**

```bash
./vendor/bin/sail pest --stop-on-failure
```

- [ ] **Committer**

```bash
git add app/Livewire/RecetteForm.php app/Livewire/DepenseForm.php
git commit -m "refactor: supprimer re-congélation Livewire — délégué au service"
```

---

### Task 9 : Tests d'affectation `RecetteService`

**Files:**
- Create: `tests/Feature/RecetteAffectationTest.php`

- [ ] **Écrire les tests**

```php
<?php

use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\RecetteLigneAffectation;
use App\Models\User;
use App\Services\RecetteService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(RecetteService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->op1 = Operation::factory()->create();
    $this->op2 = Operation::factory()->create();
});

function makeRecetteAvecLigne(CompteBancaire $compte, float $montant = 20000.00): RecetteLigne
{
    $recette = Recette::factory()->create([
        'compte_id' => $compte->id,
        'montant_total' => $montant,
    ]);
    return RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'montant' => $montant,
    ]);
}

it('affecterLigne crée des affectations en remplacement', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(2);
    expect((float) RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->sum('montant'))->toBe(20000.0);
});

it('affecterLigne accepte une seule affectation', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 5000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(1);
});

it('affecterLigne rejette si la somme ne correspond pas au montant de la ligne', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]))->toThrow(\RuntimeException::class, 'somme');
});

it('affecterLigne rejette si un montant est nul ou négatif', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '0.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]))->toThrow(\RuntimeException::class);
});

it('affecterLigne remplace les affectations existantes', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(2);
});

it('affecterLigne ne modifie pas la ligne source ni le montant_total', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);
    $montantAvant = $ligne->montant;
    $totalAvant = $ligne->recette->montant_total;

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    expect($ligne->fresh()->montant)->toBe($montantAvant);
    expect($ligne->recette->fresh()->montant_total)->toBe($totalAvant);
});

it('supprimerAffectations supprime toutes les affectations d\'une ligne', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);
    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->supprimerAffectations($ligne);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(0);
});
```

- [ ] **Lancer les tests pour confirmer l'échec**

```bash
./vendor/bin/sail pest tests/Feature/RecetteAffectationTest.php
```

- [ ] **Committer les tests**

```bash
git add tests/Feature/RecetteAffectationTest.php
git commit -m "test: affectations RecetteService — tests en attente d'implémentation"
```

---

### Task 10 : Implémentation `affecterLigne` et `supprimerAffectations` dans `RecetteService`

**Files:**
- Modify: `app/Services/RecetteService.php`

- [ ] **Ajouter les deux méthodes**

```php
use App\Models\RecetteLigne;

public function affecterLigne(RecetteLigne $ligne, array $affectations): void
{
    foreach ($affectations as $a) {
        if ((int) round((float) ($a['montant'] ?? 0) * 100) <= 0) {
            throw new \RuntimeException('Chaque affectation doit avoir un montant positif.');
        }
    }

    $somme = (int) round(
        collect($affectations)->sum(fn ($a) => (float) $a['montant']) * 100
    );
    $attendu = (int) round((float) $ligne->montant * 100);

    if ($somme !== $attendu) {
        throw new \RuntimeException(
            "La somme des affectations ({$somme} centimes) ne correspond pas au montant de la ligne ({$attendu} centimes)."
        );
    }

    DB::transaction(function () use ($ligne, $affectations) {
        $ligne->affectations()->delete();
        foreach ($affectations as $a) {
            $ligne->affectations()->create([
                'operation_id' => $a['operation_id'] ?: null,
                'seance'       => $a['seance'] ?: null,
                'montant'      => $a['montant'],
                'notes'        => $a['notes'] ?: null,
            ]);
        }
    });
}

public function supprimerAffectations(RecetteLigne $ligne): void
{
    $ligne->affectations()->delete();
}
```

- [ ] **Lancer les tests**

```bash
./vendor/bin/sail pest tests/Feature/RecetteAffectationTest.php
```

Attendu : tous verts.

- [ ] **Lancer tous les tests**

```bash
./vendor/bin/sail pest --stop-on-failure
```

- [ ] **Committer**

```bash
git add app/Services/RecetteService.php
git commit -m "feat: RecetteService::affecterLigne et supprimerAffectations"
```

---

### Task 11 : `affecterLigne` et `supprimerAffectations` dans `DepenseService`

**Files:**
- Create: `tests/Feature/DepenseAffectationTest.php`
- Modify: `app/Services/DepenseService.php`

- [ ] **Écrire les tests**

```php
<?php

use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\DepenseLigneAffectation;
use App\Models\Operation;
use App\Models\User;
use App\Services\DepenseService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DepenseService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->op1 = Operation::factory()->create(['exercice' => 2025]);
    $this->op2 = Operation::factory()->create(['exercice' => 2025]);
});

function makeDepenseAvecLigne(CompteBancaire $compte, float $montant = 20000.00): DepenseLigne
{
    $depense = Depense::factory()->create([
        'compte_id' => $compte->id,
        'montant_total' => $montant,
    ]);
    return DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'montant' => $montant,
    ]);
}

it('affecterLigne crée des affectations en remplacement', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(2);
    expect((float) DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->sum('montant'))->toBe(20000.0);
});

it('affecterLigne accepte une seule affectation', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 5000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(1);
});

it('affecterLigne rejette si la somme ne correspond pas au montant de la ligne', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]))->toThrow(\RuntimeException::class, 'somme');
});

it('affecterLigne rejette si un montant est nul ou négatif', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '0.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]))->toThrow(\RuntimeException::class);
});

it('affecterLigne remplace les affectations existantes', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(2);
});

it('affecterLigne ne modifie pas la ligne source ni le montant_total', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);
    $montantAvant = $ligne->montant;
    $totalAvant = $ligne->depense->montant_total;

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    expect($ligne->fresh()->montant)->toBe($montantAvant);
    expect($ligne->depense->fresh()->montant_total)->toBe($totalAvant);
});

it('supprimerAffectations supprime toutes les affectations d\'une ligne', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);
    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->supprimerAffectations($ligne);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(0);
});
```

- [ ] **Lancer les tests pour confirmer l'échec**

```bash
./vendor/bin/sail pest tests/Feature/DepenseAffectationTest.php
```

- [ ] **Implémenter dans `DepenseService`**

```php
use App\Models\DepenseLigne;

public function affecterLigne(DepenseLigne $ligne, array $affectations): void
{
    foreach ($affectations as $a) {
        if ((int) round((float) ($a['montant'] ?? 0) * 100) <= 0) {
            throw new \RuntimeException('Chaque affectation doit avoir un montant positif.');
        }
    }

    $somme = (int) round(
        collect($affectations)->sum(fn ($a) => (float) $a['montant']) * 100
    );
    $attendu = (int) round((float) $ligne->montant * 100);

    if ($somme !== $attendu) {
        throw new \RuntimeException(
            "La somme des affectations ({$somme} centimes) ne correspond pas au montant de la ligne ({$attendu} centimes)."
        );
    }

    DB::transaction(function () use ($ligne, $affectations) {
        $ligne->affectations()->delete();
        foreach ($affectations as $a) {
            $ligne->affectations()->create([
                'operation_id' => $a['operation_id'] ?: null,
                'seance'       => $a['seance'] ?: null,
                'montant'      => $a['montant'],
                'notes'        => $a['notes'] ?: null,
            ]);
        }
    });
}

public function supprimerAffectations(DepenseLigne $ligne): void
{
    $ligne->affectations()->delete();
}
```

- [ ] **Lancer tous les tests**

```bash
./vendor/bin/sail pest --stop-on-failure
```

- [ ] **Committer**

```bash
git add tests/Feature/DepenseAffectationTest.php app/Services/DepenseService.php
git commit -m "feat: DepenseService::affecterLigne et supprimerAffectations"
```

---

## Chunk 3 : UI — Panneau de ventilation

### Task 12 : Composant Livewire `RecetteForm` — ventilation

**Files:**
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

- [ ] **Ajouter les propriétés et méthodes de ventilation dans `RecetteForm`**

Ajouter dans la classe :

```php
// État du panneau de ventilation
public ?int $ventilationLigneId = null;

/** @var array<int, array{operation_id: string, seance: string, montant: string, notes: string}> */
public array $affectations = [];

public function ouvrirVentilation(int $ligneId): void
{
    $ligne = \App\Models\RecetteLigne::with('affectations')->findOrFail($ligneId);
    $this->ventilationLigneId = $ligneId;

    if ($ligne->affectations->isEmpty()) {
        $this->affectations = [[
            'operation_id' => (string) ($ligne->operation_id ?? ''),
            'seance'       => (string) ($ligne->seance ?? ''),
            'montant'      => (string) $ligne->montant,
            'notes'        => (string) ($ligne->notes ?? ''),
        ]];
    } else {
        $this->affectations = $ligne->affectations->map(fn ($a) => [
            'operation_id' => (string) ($a->operation_id ?? ''),
            'seance'       => (string) ($a->seance ?? ''),
            'montant'      => (string) $a->montant,
            'notes'        => (string) ($a->notes ?? ''),
        ])->toArray();
    }
}

public function fermerVentilation(): void
{
    $this->ventilationLigneId = null;
    $this->affectations = [];
}

public function addAffectation(): void
{
    $this->affectations[] = ['operation_id' => '', 'seance' => '', 'montant' => '', 'notes' => ''];
}

public function removeAffectation(int $index): void
{
    array_splice($this->affectations, $index, 1);
}

public function saveVentilation(): void
{
    $this->validate([
        'affectations'                  => ['required', 'array', 'min:1'],
        'affectations.*.montant'        => ['required', 'numeric', 'min:0.01'],
        'affectations.*.operation_id'   => ['nullable'],
        'affectations.*.seance'         => ['nullable', 'integer', 'min:1'],
        'affectations.*.notes'          => ['nullable', 'string', 'max:255'],
    ]);

    $ligne = \App\Models\RecetteLigne::findOrFail($this->ventilationLigneId);

    app(\App\Services\RecetteService::class)->affecterLigne(
        $ligne,
        collect($this->affectations)->map(fn ($a) => [
            'operation_id' => $a['operation_id'] !== '' ? (int) $a['operation_id'] : null,
            'seance'       => $a['seance'] !== '' ? (int) $a['seance'] : null,
            'montant'      => $a['montant'],
            'notes'        => $a['notes'] ?: null,
        ])->toArray()
    );

    $this->fermerVentilation();
    $this->dispatch('recette-saved');
}

public function supprimerVentilation(): void
{
    $ligne = \App\Models\RecetteLigne::findOrFail($this->ventilationLigneId);
    app(\App\Services\RecetteService::class)->supprimerAffectations($ligne);
    $this->fermerVentilation();
    $this->dispatch('recette-saved');
}
```

- [ ] **Mettre à jour la vue `recette-form.blade.php`**

Dans la boucle `@forelse ($lignes as $index => $ligne)`, après la cellule des notes, modifier la cellule d'action :

```blade
<td class="text-center">
    @if (! $isLocked)
        <button type="button" wire:click="removeLigne({{ $index }})"
                class="btn btn-sm btn-outline-danger">
            <i class="bi bi-trash"></i>
        </button>
    @endif
    @if (isset($ligne['id']))
        <button type="button"
                wire:click="ouvrirVentilation({{ $ligne['id'] }})"
                class="btn btn-sm btn-outline-warning ms-1">
            <i class="bi bi-scissors"></i>
            {{ in_array($ligne['id'], $lignesAffectations) ? 'Modifier ventilation' : 'Ventiler' }}
        </button>
    @endif
</td>
```

Ajouter le panneau de ventilation après le tableau, dans la `card-body`, avant les boutons d'action :

```blade
@if ($ventilationLigneId)
    @php
        $ligneSrc = \App\Models\RecetteLigne::with('sousCategorie')->find($ventilationLigneId);
    @endphp
    @if ($ligneSrc)
    <div class="border border-primary border-2 rounded p-3 mb-3" style="background:#f0f7ff">
        <div class="fw-bold text-primary mb-2">
            <i class="bi bi-scissors"></i>
            Ventilation — {{ $ligneSrc->sousCategorie->nom }} ({{ number_format($ligneSrc->montant, 2, ',', ' ') }} €)
        </div>

        <table class="table table-sm mb-2">
            <thead class="table-light">
                <tr>
                    <th>Opération</th>
                    <th style="width:100px">Séance</th>
                    <th style="width:120px">Montant *</th>
                    <th>Notes</th>
                    <th style="width:40px"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($affectations as $ai => $aff)
                <tr wire:key="aff-{{ $ai }}">
                    <td>
                        <select wire:model.live="affectations.{{ $ai }}.operation_id" class="form-select form-select-sm">
                            <option value="">— Aucune (reste non affecté) —</option>
                            @foreach ($operations as $op)
                                <option value="{{ $op->id }}">{{ $op->nom }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        @php
                            $selOp = $aff['operation_id'] !== '' ? $operations->firstWhere('id', (int) $aff['operation_id']) : null;
                        @endphp
                        @if ($selOp?->nombre_seances)
                            <select wire:model="affectations.{{ $ai }}.seance" class="form-select form-select-sm">
                                <option value="">--</option>
                                @for ($s = 1; $s <= $selOp->nombre_seances; $s++)
                                    <option value="{{ $s }}">{{ $s }}</option>
                                @endfor
                            </select>
                        @endif
                    </td>
                    <td>
                        <input type="number" wire:model.live="affectations.{{ $ai }}.montant"
                               step="0.01" min="0.01"
                               class="form-control form-control-sm text-end @error('affectations.'.$ai.'.montant') is-invalid @enderror">
                        @error('affectations.'.$ai.'.montant') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </td>
                    <td>
                        <input type="text" wire:model="affectations.{{ $ai }}.notes" class="form-control form-control-sm">
                    </td>
                    <td class="text-center">
                        <button type="button" wire:click="removeAffectation({{ $ai }})" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @php
            $resteEn100 = (int) round((float) $ligneSrc->montant * 100)
                        - (int) round(collect($affectations)->sum(fn($a) => (float)($a['montant'] ?? 0)) * 100);
            $reste = $resteEn100 / 100;
        @endphp

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <button type="button" wire:click="addAffectation" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-plus-lg"></i> Ajouter une ligne
            </button>
            <span class="badge {{ $resteEn100 === 0 ? 'bg-success' : 'bg-warning text-dark' }}">
                Reste : {{ number_format($reste, 2, ',', ' ') }} €
            </span>
            <div class="ms-auto d-flex gap-2">
                <button type="button" wire:click="supprimerVentilation" class="btn btn-sm btn-outline-danger"
                        wire:confirm="Supprimer toute la ventilation ?">
                    Annuler la ventilation
                </button>
                <button type="button" wire:click="fermerVentilation" class="btn btn-sm btn-secondary">Fermer</button>
                <button type="button" wire:click="saveVentilation"
                        class="btn btn-sm btn-success"
                        @if($resteEn100 !== 0) disabled title="La somme doit être exacte" @endif>
                    <i class="bi bi-check-lg"></i> Enregistrer
                </button>
            </div>
        </div>
    </div>
    @endif
@endif
```

- [ ] **Dans `RecetteForm::render()`, passer les IDs des lignes ventilées à la vue**

Modifier le tableau passé à `view()` pour y ajouter `lignesAffectations` (évite les N+1 en blade) :

```php
return view('livewire.recette-form', [
    'comptes'             => CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get(),
    'sousCategories'      => $sousCategories,
    'operations'          => Operation::where('statut', StatutOperation::EnCours)->orderBy('nom')->get(),
    'modesPaiement'       => ModePaiement::cases(),
    'recette_numero_piece' => $this->recetteId
        ? Recette::select('id', 'numero_piece')->find($this->recetteId)?->numero_piece
        : null,
    'lignesAffectations'  => $this->recetteId
        ? \App\Models\RecetteLigneAffectation::whereIn(
            'recette_ligne_id',
            collect($this->lignes)->pluck('id')->filter()->toArray()
        )->pluck('recette_ligne_id')->unique()->toArray()
        : [],
]);
```

- [ ] **Tester manuellement dans le navigateur**

Ouvrir http://localhost et modifier une recette avec plusieurs lignes. Vérifier :
- Le bouton "Ventiler" apparaît sur chaque ligne existante (avec `id`)
- Le panneau s'ouvre avec la ligne source en lecture seule
- Le compteur "Reste" se met à jour
- L'enregistrement est bloqué si reste ≠ 0
- Après enregistrement, rouvrir le formulaire : les affectations sont pré-remplies et le bouton dit "Modifier ventilation"

- [ ] **Committer**

```bash
git add app/Livewire/RecetteForm.php resources/views/livewire/recette-form.blade.php
git commit -m "feat: panneau de ventilation dans RecetteForm"
```

---

### Task 13 : Ventilation dans `DepenseForm` (symétrique)

**Files:**
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `resources/views/livewire/depense-form.blade.php`

- [ ] **Ajouter les propriétés et méthodes de ventilation dans `DepenseForm`**

```php
// État du panneau de ventilation
public ?int $ventilationLigneId = null;

/** @var array<int, array{operation_id: string, seance: string, montant: string, notes: string}> */
public array $affectations = [];

public function ouvrirVentilation(int $ligneId): void
{
    $ligne = \App\Models\DepenseLigne::with('affectations')->findOrFail($ligneId);
    $this->ventilationLigneId = $ligneId;

    if ($ligne->affectations->isEmpty()) {
        $this->affectations = [[
            'operation_id' => (string) ($ligne->operation_id ?? ''),
            'seance'       => (string) ($ligne->seance ?? ''),
            'montant'      => (string) $ligne->montant,
            'notes'        => (string) ($ligne->notes ?? ''),
        ]];
    } else {
        $this->affectations = $ligne->affectations->map(fn ($a) => [
            'operation_id' => (string) ($a->operation_id ?? ''),
            'seance'       => (string) ($a->seance ?? ''),
            'montant'      => (string) $a->montant,
            'notes'        => (string) ($a->notes ?? ''),
        ])->toArray();
    }
}

public function fermerVentilation(): void
{
    $this->ventilationLigneId = null;
    $this->affectations = [];
}

public function addAffectation(): void
{
    $this->affectations[] = ['operation_id' => '', 'seance' => '', 'montant' => '', 'notes' => ''];
}

public function removeAffectation(int $index): void
{
    array_splice($this->affectations, $index, 1);
}

public function saveVentilation(): void
{
    $this->validate([
        'affectations'                  => ['required', 'array', 'min:1'],
        'affectations.*.montant'        => ['required', 'numeric', 'min:0.01'],
        'affectations.*.operation_id'   => ['nullable'],
        'affectations.*.seance'         => ['nullable', 'integer', 'min:1'],
        'affectations.*.notes'          => ['nullable', 'string', 'max:255'],
    ]);

    $ligne = \App\Models\DepenseLigne::findOrFail($this->ventilationLigneId);

    app(\App\Services\DepenseService::class)->affecterLigne(
        $ligne,
        collect($this->affectations)->map(fn ($a) => [
            'operation_id' => $a['operation_id'] !== '' ? (int) $a['operation_id'] : null,
            'seance'       => $a['seance'] !== '' ? (int) $a['seance'] : null,
            'montant'      => $a['montant'],
            'notes'        => $a['notes'] ?: null,
        ])->toArray()
    );

    $this->fermerVentilation();
    $this->dispatch('depense-saved');
}

public function supprimerVentilation(): void
{
    $ligne = \App\Models\DepenseLigne::findOrFail($this->ventilationLigneId);
    app(\App\Services\DepenseService::class)->supprimerAffectations($ligne);
    $this->fermerVentilation();
    $this->dispatch('depense-saved');
}
```

- [ ] **Dans `DepenseForm::render()`, passer les IDs de lignes ventilées à la vue**

Ajouter dans le tableau retourné par `render()` (même pattern que pour RecetteForm) :

```php
'lignesAffectations' => $this->depenseId
    ? \App\Models\DepenseLigneAffectation::whereIn(
        'depense_ligne_id',
        collect($this->lignes)->pluck('id')->filter()->toArray()
    )->pluck('depense_ligne_id')->unique()->toArray()
    : [],
```

- [ ] **Mettre à jour la vue `depense-form.blade.php`**

Dans la boucle `@forelse ($lignes as $index => $ligne)`, modifier la cellule d'action de la même façon que pour `recette-form.blade.php` :

```blade
<td class="text-center">
    @if (! $isLocked)
        <button type="button" wire:click="removeLigne({{ $index }})"
                class="btn btn-sm btn-outline-danger">
            <i class="bi bi-trash"></i>
        </button>
    @endif
    @if (isset($ligne['id']))
        <button type="button"
                wire:click="ouvrirVentilation({{ $ligne['id'] }})"
                class="btn btn-sm btn-outline-warning ms-1">
            <i class="bi bi-scissors"></i>
            {{ in_array($ligne['id'], $lignesAffectations) ? 'Modifier ventilation' : 'Ventiler' }}
        </button>
    @endif
</td>
```

Ajouter le panneau de ventilation après le tableau, identique à `recette-form.blade.php` en remplaçant :
- `\App\Models\RecetteLigne` → `\App\Models\DepenseLigne`
- `\App\Models\RecetteLigneAffectation` → `\App\Models\DepenseLigneAffectation`
- `recette_ligne_id` → `depense_ligne_id` dans tout référencement

Copier intégralement le bloc `@if ($ventilationLigneId)` de `recette-form.blade.php` et appliquer ces 3 substitutions.

> Note : `DepenseForm::render()` passe déjà `'operations'` à la vue — aucune modification de `render()` nécessaire au-delà de l'ajout de `lignesAffectations`.

- [ ] **Tester manuellement dans le navigateur** (même vérifications que Task 12)

- [ ] **Committer**

```bash
git add app/Livewire/DepenseForm.php resources/views/livewire/depense-form.blade.php
git commit -m "feat: panneau de ventilation dans DepenseForm"
```

---

## Chunk 4 : RapportService — intégration des affectations

### Task 14 : Helper de résolution des lignes ventilées

**Files:**
- Modify: `app/Services/RapportService.php`

La stratégie : pour chaque méthode `fetch*Rows()` qui filtre par `operation_id`, on remplace la requête simple par deux requêtes accumulées :
1. Lignes **sans affectations** → utiliser `ligne.operation_id` et `ligne.montant`
2. Lignes **avec affectations** → utiliser `affectation.operation_id` et `affectation.montant`

- [ ] **Écrire les tests avant de modifier `RapportService`**

Créer `tests/Feature/RapportServiceAffectationTest.php` :

```php
<?php

use App\Models\CompteBancaire;
use App\Models\Categorie;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\DepenseLigneAffectation;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\RecetteLigneAffectation;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\RapportService;
use App\Enums\TypeCategorie;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(RapportService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->op1 = Operation::factory()->create(['exercice' => 2025]);
    $this->categorie = Categorie::factory()->create(['type' => TypeCategorie::Recette]);
    $this->sousCategorie = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);
});

it('le rapport onglet 2 prend en compte les affectations au lieu de operation_id ligne', function () {
    // Recette de 20 000 sans opération directe
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 20000.00,
    ]);
    $ligne = RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => null,
        'montant' => 20000.00,
    ]);

    // Affectation de 8000 à op1
    RecetteLigneAffectation::create([
        'recette_ligne_id' => $ligne->id,
        'operation_id' => $this->op1->id,
        'montant' => 8000.00,
        'seance' => null,
        'notes' => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    // $rapport['produits'] est une liste de catégories (buildHierarchySimple),
    // chaque catégorie ayant une clé 'sous_categories'. Il faut traverser la hiérarchie.
    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    // Le rapport doit voir 8000 sur op1, pas 0 (car la ligne avait operation_id null)
    expect((float) ($scRow['montant'] ?? 0))->toBe(8000.0);
});

it('une ligne sans affectation continue d\'utiliser son operation_id direct', function () {
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'date' => '2025-10-15',
        'montant_total' => 5000.00,
    ]);
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => $this->op1->id,
        'montant' => 5000.00,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    expect((float) ($scRow['montant'] ?? 0))->toBe(5000.0);
});

it('le rapport onglet 2 prend en compte les affectations de dépenses', function () {
    $categorieD = \App\Models\Categorie::factory()->create(['type' => TypeCategorie::Depense]);
    $sousCatD   = \App\Models\SousCategorie::factory()->create(['categorie_id' => $categorieD->id]);
    $compte     = \App\Models\CompteBancaire::factory()->create();

    $depense = \App\Models\Depense::factory()->create([
        'compte_id'    => $compte->id,
        'date'         => '2025-10-15',
        'montant_total' => 12000.00,
    ]);
    $ligne = \App\Models\DepenseLigne::factory()->create([
        'depense_id'       => $depense->id,
        'sous_categorie_id' => $sousCatD->id,
        'operation_id'     => null,
        'montant'          => 12000.00,
    ]);

    \App\Models\DepenseLigneAffectation::create([
        'depense_ligne_id' => $ligne->id,
        'operation_id'     => $this->op1->id,
        'montant'          => 7000.00,
        'seance'           => null,
        'notes'            => null,
    ]);

    $rapport = $this->service->compteDeResultatOperations(2025, [$this->op1->id]);

    $charges = collect($rapport['charges'] ?? []);
    $cat = $charges->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $sousCatD->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $sousCatD->id);
    expect((float) ($scRow['montant'] ?? 0))->toBe(7000.0);
});

it('le rapport onglet 3 prend en compte les affectations de recettes avec séance', function () {
    $recette = \App\Models\Recette::factory()->create([
        'compte_id'    => $this->compte->id,
        'date'         => '2025-10-15',
        'montant_total' => 3000.00,
    ]);
    $ligne = \App\Models\RecetteLigne::factory()->create([
        'recette_id'       => $recette->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id'     => null,
        'seance'           => null,
        'montant'          => 3000.00,
    ]);

    RecetteLigneAffectation::create([
        'recette_ligne_id' => $ligne->id,
        'operation_id'     => $this->op1->id,
        'seance'           => 2,
        'montant'          => 3000.00,
        'notes'            => null,
    ]);

    $rapport = $this->service->rapportSeances(2025, [$this->op1->id]);

    // rapportSeances retourne ['seances' => [...], 'charges' => [...], 'produits' => [...]]
    // 'produits' est une liste de catégories, chacune avec 'sous_categories'
    // et chaque sous-catégorie a une clé 'seances' = [seance_num => montant]
    expect($rapport['seances'])->toContain(2);

    $produits = collect($rapport['produits'] ?? []);
    $cat = $produits->first(fn ($c) =>
        collect($c['sous_categories'] ?? [])->contains('sous_categorie_id', $this->sousCategorie->id)
    );
    $scRow = collect($cat['sous_categories'] ?? [])->firstWhere('sous_categorie_id', $this->sousCategorie->id);
    expect((float) ($scRow['seances'][2] ?? 0))->toBe(3000.0);
});
```

- [ ] **Lancer les tests pour confirmer l'échec**

```bash
./vendor/bin/sail pest tests/Feature/RapportServiceAffectationTest.php
```

- [ ] **Committer les tests**

```bash
git add tests/Feature/RapportServiceAffectationTest.php
git commit -m "test: RapportService intègre les affectations — tests en attente"
```

---

### Task 15 : Modifier `RapportService` — méthodes `fetchDepenseRows` et `fetchDepenseSeancesRows`

**Files:**
- Modify: `app/Services/RapportService.php`

Pour `fetchDepenseRows()`, remplacer le filtre `whereIn('depense_lignes.operation_id', $operationIds)` par une approche en deux parties accumulées :

- [ ] **Ajouter un helper privé `accumulerDepenses()`**

```php
/**
 * Accumule les dépenses en résolvant les affectations.
 * Lignes avec affectations → utilise les affectations.
 * Lignes sans affectations → utilise operation_id de la ligne.
 *
 * @param array<int>|null $operationIds
 * @param array<int, array{categorie_id:int,categorie_nom:string,sous_categorie_id:int,sous_categorie_nom:string,montant:float}> $map
 */
private function accumulerDepensesResolues(string $start, string $end, ?array $operationIds, array &$map): void
{
    // Partie 1 : lignes sans affectations
    $q1 = DB::table('depense_lignes')
        ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
        ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
        ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
        ->leftJoin('depense_ligne_affectations as dla', 'dla.depense_ligne_id', '=', 'depense_lignes.id')
        ->whereNull('depense_lignes.deleted_at')
        ->whereNull('d.deleted_at')
        ->whereNull('dla.id')
        ->whereBetween('d.date', [$start, $end])
        ->select([
            'c.id as categorie_id', 'c.nom as categorie_nom',
            'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
            DB::raw('SUM(depense_lignes.montant) as montant'),
        ])
        ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');

    if ($operationIds !== null) {
        $q1->whereIn('depense_lignes.operation_id', $operationIds);
    }

    // Partie 2 : lignes avec affectations (utiliser affectation.montant et affectation.operation_id)
    $q2 = DB::table('depense_ligne_affectations as dla')
        ->join('depense_lignes', 'depense_lignes.id', '=', 'dla.depense_ligne_id')
        ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
        ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
        ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
        ->whereNull('depense_lignes.deleted_at')
        ->whereNull('d.deleted_at')
        ->whereBetween('d.date', [$start, $end])
        ->select([
            'c.id as categorie_id', 'c.nom as categorie_nom',
            'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
            DB::raw('SUM(dla.montant) as montant'),
        ])
        ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');

    if ($operationIds !== null) {
        $q2->whereIn('dla.operation_id', $operationIds);
    }

    foreach ([$q1->get(), $q2->get()] as $rows) {
        foreach ($rows as $row) {
            $scId = (int) $row->sous_categorie_id;
            if (isset($map[$scId])) {
                $map[$scId]['montant'] += (float) $row->montant;
            } else {
                $map[$scId] = [
                    'categorie_id'      => (int) $row->categorie_id,
                    'categorie_nom'     => $row->categorie_nom,
                    'sous_categorie_id' => $scId,
                    'sous_categorie_nom' => $row->sous_categorie_nom,
                    'montant'           => (float) $row->montant,
                ];
            }
        }
    }
}
```

- [ ] **Modifier `fetchDepenseRows()` pour utiliser le helper**

Remplacer le corps de `fetchDepenseRows()` par :

```php
private function fetchDepenseRows(string $start, string $end, ?array $operationIds = null): Collection
{
    $map = [];
    $this->accumulerDepensesResolues($start, $end, $operationIds, $map);
    return collect(array_values($map))->map(fn ($row) => (object) $row);
}
```

- [ ] **Ajouter le helper pour les séances dépenses `accumulerDepensesSeancesResolues()`**

```php
private function accumulerDepensesSeancesResolues(string $start, string $end, array $operationIds, array &$map): void
{
    // Lignes sans affectations
    $rows1 = DB::table('depense_lignes')
        ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
        ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
        ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
        ->leftJoin('depense_ligne_affectations as dla', 'dla.depense_ligne_id', '=', 'depense_lignes.id')
        ->whereNull('depense_lignes.deleted_at')->whereNull('d.deleted_at')
        ->whereNull('dla.id')
        ->whereNotNull('depense_lignes.seance')
        ->whereIn('depense_lignes.operation_id', $operationIds)
        ->whereBetween('d.date', [$start, $end])
        ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'depense_lignes.seance', DB::raw('SUM(depense_lignes.montant) as montant')])
        ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'depense_lignes.seance')
        ->get();

    // Lignes avec affectations
    $rows2 = DB::table('depense_ligne_affectations as dla')
        ->join('depense_lignes', 'depense_lignes.id', '=', 'dla.depense_ligne_id')
        ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
        ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
        ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
        ->whereNull('depense_lignes.deleted_at')->whereNull('d.deleted_at')
        ->whereNotNull('dla.seance')
        ->whereIn('dla.operation_id', $operationIds)
        ->whereBetween('d.date', [$start, $end])
        ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'dla.seance', DB::raw('SUM(dla.montant) as montant')])
        ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'dla.seance')
        ->get();

    foreach ([$rows1, $rows2] as $rows) {
        foreach ($rows as $row) {
            $scId = (int) $row->sous_categorie_id;
            $seance = (int) $row->seance;
            if (isset($map[$scId][$seance])) {
                $map[$scId][$seance]['montant'] += (float) $row->montant;
            } else {
                $map[$scId][$seance] = [
                    'categorie_id' => (int) $row->categorie_id, 'categorie_nom' => $row->categorie_nom,
                    'sous_categorie_id' => $scId, 'sous_categorie_nom' => $row->sous_categorie_nom,
                    'seance' => $seance, 'montant' => (float) $row->montant,
                ];
            }
        }
    }
}
```

- [ ] **Modifier `fetchDepenseSeancesRows()` pour utiliser le helper**

```php
private function fetchDepenseSeancesRows(string $start, string $end, array $operationIds): Collection
{
    $map = [];
    $this->accumulerDepensesSeancesResolues($start, $end, $operationIds, $map);
    $flat = [];
    foreach ($map as $seanceMap) {
        foreach ($seanceMap as $entry) {
            $flat[] = $entry;
        }
    }
    return collect($flat)->map(fn ($row) => (object) $row);
}
```

- [ ] **Lancer les tests**

```bash
./vendor/bin/sail pest tests/Feature/RapportServiceAffectationTest.php
./vendor/bin/sail pest --stop-on-failure
```

- [ ] **Committer**

```bash
git add app/Services/RapportService.php
git commit -m "feat: RapportService résout les affectations pour dépenses"
```

---

### Task 16 : Modifier `RapportService` — méthodes recettes

**Files:**
- Modify: `app/Services/RapportService.php`

Même approche pour les recettes dans `fetchProduitsRows()` et `fetchProduitsSeancesRows()`.

- [ ] **Dans `fetchProduitsRows()`, remplacer le bloc recettes**

Supprimer **entièrement** le bloc suivant (du commentaire `// Recettes` inclus jusqu'à `$accumuler($rq->get());` inclus, ligne 192) — NE PAS toucher le bloc `// Dons` qui suit :

```php
// Recettes
$rq = RecetteLigne::query()
    ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
    ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
    ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
    ->whereNull('recette_lignes.deleted_at')
    ->whereNull('r.deleted_at')
    ->whereBetween('r.date', [$start, $end])
    ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(recette_lignes.montant) as montant')])
    ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
if ($operationIds !== null) {
    $rq->whereIn('recette_lignes.operation_id', $operationIds);
}
$accumuler($rq->get());
```

Et insérer **à la place** les deux requêtes accumulées :

```php
// Recettes — Partie 1 : lignes sans affectations
$rq1 = DB::table('recette_lignes')
    ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
    ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
    ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
    ->leftJoin('recette_ligne_affectations as rla', 'rla.recette_ligne_id', '=', 'recette_lignes.id')
    ->whereNull('recette_lignes.deleted_at')->whereNull('r.deleted_at')
    ->whereNull('rla.id')
    ->whereBetween('r.date', [$start, $end])
    ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(recette_lignes.montant) as montant')])
    ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
if ($operationIds !== null) {
    $rq1->whereIn('recette_lignes.operation_id', $operationIds);
}
$accumuler($rq1->get());

// Recettes — Partie 2 : lignes avec affectations
$rq2 = DB::table('recette_ligne_affectations as rla')
    ->join('recette_lignes', 'recette_lignes.id', '=', 'rla.recette_ligne_id')
    ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
    ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
    ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
    ->whereNull('recette_lignes.deleted_at')->whereNull('r.deleted_at')
    ->whereBetween('r.date', [$start, $end])
    ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(rla.montant) as montant')])
    ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
if ($operationIds !== null) {
    $rq2->whereIn('rla.operation_id', $operationIds);
}
$accumuler($rq2->get());
```

- [ ] **Dans `fetchProduitsSeancesRows()`, remplacer le bloc recettes par la décomposition avec affectations**

Supprimer **entièrement** le bloc `// Recettes par séance` (de ce commentaire jusqu'à `->get());` inclus) — NE PAS toucher le bloc `// Dons par séance` qui suit :

```php
// Recettes par séance
$accumuler(RecetteLigne::query()
    ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
    ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
    ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
    ->whereNull('recette_lignes.deleted_at')
    ->whereNull('r.deleted_at')
    ->whereBetween('r.date', [$start, $end])
    ->whereNotNull('recette_lignes.seance')
    ->whereIn('recette_lignes.operation_id', $operationIds)
    ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'recette_lignes.seance', DB::raw('SUM(recette_lignes.montant) as montant')])
    ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'recette_lignes.seance')
    ->get());
```

Et insérer **à la place** :

```php
// Recettes par séance — Partie 1 : lignes sans affectations
$accumuler(DB::table('recette_lignes')
    ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
    ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
    ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
    ->leftJoin('recette_ligne_affectations as rla', 'rla.recette_ligne_id', '=', 'recette_lignes.id')
    ->whereNull('recette_lignes.deleted_at')->whereNull('r.deleted_at')
    ->whereNull('rla.id')
    ->whereNotNull('recette_lignes.seance')
    ->whereIn('recette_lignes.operation_id', $operationIds)
    ->whereBetween('r.date', [$start, $end])
    ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'recette_lignes.seance', DB::raw('SUM(recette_lignes.montant) as montant')])
    ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'recette_lignes.seance')
    ->get());

// Recettes par séance — Partie 2 : lignes avec affectations
$accumuler(DB::table('recette_ligne_affectations as rla')
    ->join('recette_lignes', 'recette_lignes.id', '=', 'rla.recette_ligne_id')
    ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
    ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
    ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
    ->whereNull('recette_lignes.deleted_at')->whereNull('r.deleted_at')
    ->whereNotNull('rla.seance')
    ->whereIn('rla.operation_id', $operationIds)
    ->whereBetween('r.date', [$start, $end])
    ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'rla.seance', DB::raw('SUM(rla.montant) as montant')])
    ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'rla.seance')
    ->get());
```

Note : `$accumuler` est la closure déjà définie dans `fetchProduitsSeancesRows()` qui accumule dans `$map[$scId][$seance]`.

- [ ] **Lancer tous les tests**

```bash
./vendor/bin/sail pest --stop-on-failure
```

Attendu : tous verts, y compris `RapportServiceAffectationTest`.

- [ ] **Committer**

```bash
git add app/Services/RapportService.php
git commit -m "feat: RapportService résout les affectations pour recettes"
```

---

## Finalisation

- [ ] **Lancer la suite complète une dernière fois**

```bash
./vendor/bin/sail pest
```

- [ ] **Linter PSR-12**

```bash
./vendor/bin/sail pint --test
```

Si des erreurs : `./vendor/bin/sail pint` puis commit.

- [ ] **Commit final si pint a modifié des fichiers**

```bash
git add -p
git commit -m "style: pint PSR-12"
```

- [ ] **Vérifier que la branche est prête pour la revue**

```bash
git log --oneline main..HEAD
```
