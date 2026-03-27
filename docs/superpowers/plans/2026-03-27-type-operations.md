# Type d'opération — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduire une entité "Type d'opération" pour catégoriser les opérations avec sous-catégorie comptable, tarifs, logo, et flags confidentiel/adhérents.

**Architecture:** Nouveau modèle `TypeOperation` avec table de tarifs enfants. Composant Livewire `TypeOperationManager` (CRUD) réutilisé dans les deux espaces et en modale inline. Impacts transversaux sur les écrans existants (opérations, participants, rapports, exports PDF, services).

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 (CDN), Pest PHP, MySQL

**Spec:** `docs/superpowers/specs/2026-03-27-type-operations-design.md`

---

## File Structure

### Fichiers à créer

| Fichier | Responsabilité |
|---------|---------------|
| `app/Models/TypeOperation.php` | Modèle Eloquent avec relations, scopes |
| `app/Models/TypeOperationTarif.php` | Modèle tarifs enfant |
| `app/Livewire/TypeOperationManager.php` | Composant CRUD (liste + modale création/édition) |
| `resources/views/livewire/type-operation-manager.blade.php` | Vue du composant Livewire |
| `resources/views/parametres/type-operations/index.blade.php` | Page wrapper Paramètres |
| `database/migrations/2026_03_27_100000_create_type_operations_table.php` | Migration table type_operations |
| `database/migrations/2026_03_27_100001_create_type_operation_tarifs_table.php` | Migration table tarifs |
| `database/migrations/2026_03_27_100002_add_type_operation_id_to_operations_table.php` | Ajout FK + suppression sous_categorie_id |
| `database/migrations/2026_03_27_100003_add_type_operation_tarif_id_to_participants_table.php` | Ajout FK tarif sur participants |
| `database/seeders/TypeOperationSeeder.php` | Seeder de données de test |
| `tests/Feature/TypeOperationTest.php` | Tests CRUD type d'opération |
| `tests/Feature/OperationTypeTest.php` | Tests intégration opération ↔ type |

### Fichiers à modifier

| Fichier | Nature de la modification |
|---------|--------------------------|
| `app/Models/Operation.php` | Swap FK sous_categorie → type_operation, nouvelle relation |
| `app/Models/Participant.php` | Ajout FK type_operation_tarif_id, relation |
| `app/Http/Controllers/OperationController.php` | Charger typeOperations au lieu de sousCategories |
| `app/Http/Requests/StoreOperationRequest.php` | Valider type_operation_id au lieu de sous_categorie_id |
| `app/Http/Requests/UpdateOperationRequest.php` | Idem + verrouillage si participants |
| `app/Livewire/GestionOperations.php` | Filtre par type, bannière migration, dropdown groupé |
| `app/Livewire/ParticipantTable.php` | Colonnes adhérent/tarif, masquage confidentiel/token |
| `app/Livewire/RapportCompteResultatOperations.php` | Filtre par type |
| `app/Livewire/RapportSeances.php` | Filtre par type |
| `app/Services/RemiseBancaireService.php` | sous_categorie via typeOperation |
| `app/Services/HelloAssoSyncService.php` | sous_categorie via typeOperation |
| `resources/views/operations/create.blade.php` | Sélecteur type + bouton "+" |
| `resources/views/operations/edit.blade.php` | Sélecteur type + verrouillage |
| `resources/views/operations/index.blade.php` | Colonne type + filtre |
| `resources/views/livewire/gestion-operations.blade.php` | Filtre type, bannière, dropdown groupé |
| `resources/views/livewire/participant-table.blade.php` | Colonnes adhérent/tarif, masquage médical/token |
| `resources/views/layouts/app.blade.php` | Lien navigation "Types d'opération" |
| `app/Http/Controllers/ParticipantPdfController.php` | Logo type en en-tête, asso en pied de page |
| `app/Http/Controllers/SeancePdfController.php` | Idem |
| `resources/views/pdf/participants-liste.blade.php` | Logo type, masquage confidentiel |
| `resources/views/pdf/participants-annuaire.blade.php` | Logo type, masquage confidentiel |
| `resources/views/pdf/seance-emargement.blade.php` | Logo type |
| `resources/views/pdf/seances-matrice.blade.php` | Logo type |
| `routes/web.php` | Route resource type-operations dans $registerParametres |
| `database/seeders/DatabaseSeeder.php` | Appel TypeOperationSeeder |
| `database/factories/OperationFactory.php` | Utiliser type_operation_id au lieu de sous_categorie_id |
| `tests/Feature/RemiseBancaireServiceTest.php` | Adapter création opérations avec type_operation_id |
| `tests/Feature/RemiseBancaireShowTest.php` | Idem |
| `tests/Feature/RemiseBancaireValidationTest.php` | Idem |
| `tests/Feature/RemiseBancairePdfTest.php` | Idem |
| `tests/Feature/ClotureCheckServiceTest.php` | Idem |
| `tests/Feature/TransactionInscriptionValidationTest.php` | Idem |

---

## Task 1 : Migrations et modèles

**Files:**
- Create: `database/migrations/2026_03_27_100000_create_type_operations_table.php`
- Create: `database/migrations/2026_03_27_100001_create_type_operation_tarifs_table.php`
- Create: `database/migrations/2026_03_27_100002_add_type_operation_id_to_operations_table.php`
- Create: `database/migrations/2026_03_27_100003_add_type_operation_tarif_id_to_participants_table.php`
- Create: `app/Models/TypeOperation.php`
- Create: `app/Models/TypeOperationTarif.php`
- Modify: `app/Models/Operation.php`
- Modify: `app/Models/Participant.php`

- [ ] **Step 1 : Créer la migration `type_operations`**

```php
Schema::create('type_operations', function (Blueprint $table) {
    $table->id();
    $table->string('code', 20)->unique();
    $table->string('nom', 150)->unique();
    $table->text('description')->nullable();
    $table->foreignId('sous_categorie_id')->constrained('sous_categories');
    $table->integer('nombre_seances')->nullable();
    $table->boolean('confidentiel')->default(false);
    $table->boolean('reserve_adherents')->default(false);
    $table->boolean('actif')->default(true);
    $table->string('logo_path', 255)->nullable();
    $table->timestamps();
});
```

- [ ] **Step 2 : Créer la migration `type_operation_tarifs`**

```php
Schema::create('type_operation_tarifs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('type_operation_id')->constrained('type_operations')->cascadeOnDelete();
    $table->string('libelle', 100);
    $table->decimal('montant', 10, 2);
    $table->timestamps();
    $table->unique(['type_operation_id', 'libelle']);
});
```

- [ ] **Step 3 : Créer la migration modification `operations`**

```php
// up()
Schema::table('operations', function (Blueprint $table) {
    $table->foreignId('type_operation_id')->nullable()->after('statut')->constrained('type_operations');
});
Schema::table('operations', function (Blueprint $table) {
    $table->dropConstrainedForeignId('sous_categorie_id');
});

// down()
Schema::table('operations', function (Blueprint $table) {
    $table->foreignId('sous_categorie_id')->nullable()->constrained('sous_categories');
});
Schema::table('operations', function (Blueprint $table) {
    $table->dropConstrainedForeignId('type_operation_id');
});
```

- [ ] **Step 4 : Créer la migration ajout tarif sur `participants`**

```php
Schema::table('participants', function (Blueprint $table) {
    $table->foreignId('type_operation_tarif_id')->nullable()->after('operation_id')
          ->constrained('type_operation_tarifs');
});
```

- [ ] **Step 5 : Créer le modèle `TypeOperation`**

Fichier `app/Models/TypeOperation.php` :
- `declare(strict_types=1)`, `final class`
- Fillable : `code`, `nom`, `description`, `sous_categorie_id`, `nombre_seances`, `confidentiel`, `reserve_adherents`, `actif`, `logo_path`
- Casts : `confidentiel` → boolean, `reserve_adherents` → boolean, `actif` → boolean, `nombre_seances` → integer, `sous_categorie_id` → integer
- Relations : `sousCategorie()` BelongsTo, `tarifs()` HasMany TypeOperationTarif, `operations()` HasMany Operation
- Scope : `scopeActif(Builder $query)` → `$query->where('actif', true)`

- [ ] **Step 6 : Créer le modèle `TypeOperationTarif`**

Fichier `app/Models/TypeOperationTarif.php` :
- `declare(strict_types=1)`, `final class`
- Fillable : `type_operation_id`, `libelle`, `montant`
- Casts : `montant` → decimal:2, `type_operation_id` → integer
- Relations : `typeOperation()` BelongsTo, `participants()` HasMany Participant

- [ ] **Step 7 : Modifier le modèle `Operation`**

Dans `app/Models/Operation.php` :
- Dans `$fillable` : remplacer `'sous_categorie_id'` par `'type_operation_id'`
- Dans `casts()` : remplacer `'sous_categorie_id' => 'integer'` par `'type_operation_id' => 'integer'`
- Remplacer la relation `sousCategorie()` par `typeOperation()` → `$this->belongsTo(TypeOperation::class)`
- Supprimer l'ancienne relation `sousCategorie()` — ne PAS ajouter de helper de remplacement. Les accès à la sous-catégorie doivent passer explicitement par `$operation->typeOperation->sousCategorie`. Un helper masquerait le fait que ce n'est plus une relation Eloquent et casserait les eager loads.

- [ ] **Step 8 : Modifier le modèle `Participant`**

Dans `app/Models/Participant.php` :
- Ajouter `'type_operation_tarif_id'` dans `$fillable`
- Ajouter `'type_operation_tarif_id' => 'integer'` dans `casts()`
- Ajouter relation `typeOperationTarif()` → `$this->belongsTo(TypeOperationTarif::class)`

- [ ] **Step 9 : Mettre à jour `OperationFactory`**

Dans `database/factories/OperationFactory.php` :
- Remplacer `'sous_categorie_id'` par `'type_operation_id'` → créer un `TypeOperation` via factory ou `TypeOperation::factory()`
- Créer `database/factories/TypeOperationFactory.php` avec des valeurs par défaut sensées (code fake unique, nom fake, sous_categorie_id existante, confidentiel=false, actif=true)
- Créer `database/factories/TypeOperationTarifFactory.php`
- Cela garantit que TOUS les tests existants qui utilisent `Operation::factory()` obtiennent automatiquement un type valide.

- [ ] **Step 10 : Exécuter les migrations**

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

Vérifier que les tables sont créées et que les migrations existantes passent.

- [ ] **Step 11 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): add migrations and models for TypeOperation and TypeOperationTarif"
```

---

## Task 2 : Composant Livewire TypeOperationManager (CRUD)

**Files:**
- Create: `app/Livewire/TypeOperationManager.php`
- Create: `resources/views/livewire/type-operation-manager.blade.php`
- Create: `resources/views/parametres/type-operations/index.blade.php`
- Create: `tests/Feature/TypeOperationTest.php`

- [ ] **Step 1 : Écrire les tests CRUD**

Créer `tests/Feature/TypeOperationTest.php` avec Pest :
- `it('displays the type operations list')` — accès à la page, voit le tableau
- `it('creates a type operation with tarifs')` — appel Livewire, vérifie insertion en base
- `it('validates required fields')` — code, nom, sous_categorie_id requis
- `it('edits a type operation')` — modification nom/code, vérifie mise à jour
- `it('prevents deletion when operations exist')` — erreur si opérations rattachées
- `it('deletes a type operation without operations')` — suppression OK
- `it('prevents deletion of tarif used by participants')` — erreur si FK
- `it('uploads a logo')` — test upload image
- `it('filters by active status')` — filtre actif/inactif
- `it('enforces unique code and nom')` — validation unicité

- [ ] **Step 2 : Vérifier que les tests échouent**

```bash
./vendor/bin/sail test tests/Feature/TypeOperationTest.php
```

Expected : FAIL (composant n'existe pas encore)

- [ ] **Step 3 : Créer le composant Livewire `TypeOperationManager`**

Fichier `app/Livewire/TypeOperationManager.php` :
- Propriétés publiques : `$showModal`, `$editingId`, `$code`, `$nom`, `$description`, `$sous_categorie_id`, `$nombre_seances`, `$confidentiel`, `$reserve_adherents`, `$actif`, `$logo`, `$tarifs` (array de `['libelle' => '', 'montant' => '']`), `$newTarifLibelle`, `$newTarifMontant`, `$filter` (actif/inactif/tous)
- Méthodes : `render()`, `openCreate()`, `openEdit($id)`, `save()`, `delete($id)`, `addTarif()`, `removeTarif($index)`, `updatedFilter()`
- Validation dans `save()` avec `$this->validate([...])` :
  - `code` : required, string, max:20, unique (sauf si editing)
  - `nom` : required, string, max:150, unique (sauf si editing)
  - `sous_categorie_id` : required, exists:sous_categories,id
  - `nombre_seances` : nullable, integer, min:1
  - `logo` : nullable, image, max:512
- Upload logo via `WithFileUploads` trait, stockage dans `type-operations/`
- Gestion des tarifs : en mémoire dans `$tarifs`, persistés au `save()`
- Listeners : `openTypeOperationModal` (pour le bouton "+" du formulaire opération)
- Après save, dispatch `typeOperationCreated` avec l'ID (pour le formulaire opération)

- [ ] **Step 4 : Créer la vue Livewire `type-operation-manager.blade.php`**

Structure de la vue :
- **Toolbar** : filtre Tous/Actifs/Inactifs (select `wire:model.live="filter"`) + bouton "Nouveau type" (`wire:click="openCreate"`)
- **Tableau** : en-tête `table-dark` bleu (convention app), colonnes Logo/Code/Nom/Sous-catégorie/Séances/Confidentiel/Adhérents/Actif/Tarifs/Actions
  - Types inactifs : `class="{{ !$type->actif ? 'opacity-50' : '' }}"`
  - Logo : miniature 32×32 ou placeholder
  - Pastilles vertes/grises pour confidentiel et adhérents
  - Badge Actif/Inactif
  - Compteur tarifs en badge
  - Actions : éditer (`wire:click="openEdit({{ $type->id }})"`) + supprimer avec confirm
  - Tri cliquable JS côté client sur Code et Nom (convention app : `data-sort`)
- **Modale Bootstrap** : `wire:model="showModal"`
  - Code (1/3) + Nom (2/3) en grid
  - Description textarea
  - Sous-catégorie select (`pour_inscriptions = true`) + Nb séances
  - 3 blocs options avec explications (confidentiel, adhérents, actif)
  - Upload logo avec preview
  - Section tarifs : liste existante avec bouton ✕ + ligne d'ajout (libellé + montant + bouton Ajouter)
  - Boutons Annuler / Enregistrer

- [ ] **Step 5 : Créer la page wrapper**

Fichier `resources/views/parametres/type-operations/index.blade.php` :
- Extend layout, section title "Types d'opération"
- `@livewire('type-operation-manager')`

- [ ] **Step 6 : Lancer les tests**

```bash
./vendor/bin/sail test tests/Feature/TypeOperationTest.php
```

Expected : tous les tests passent.

- [ ] **Step 7 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): add TypeOperationManager Livewire CRUD component"
```

---

## Task 3 : Routes et navigation

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1 : Ajouter la route dans `$registerParametres`**

Dans `routes/web.php`, dans la closure `$registerParametres` (utilisée par les deux espaces compta et gestion), ajouter :

```php
Route::view('type-operations', 'parametres.type-operations.index')->name('parametres.type-operations.index');
```

Note : pas besoin de resource controller — le CRUD est entièrement Livewire.

- [ ] **Step 2 : Ajouter le lien dans la navigation**

Dans `resources/views/layouts/app.blade.php`, dans la section Paramètres du dropdown (repérer le bloc des liens paramètres), ajouter un lien "Types d'opération" avec icône `bi-collection` :

```blade
<li>
    <a class="dropdown-item {{ request()->routeIs('*.parametres.type-operations.*') ? 'active' : '' }}"
       href="{{ route($espacePrefix . '.parametres.type-operations.index') }}">
        <i class="bi bi-collection"></i> Types d'opération
    </a>
</li>
```

L'ajouter dans les deux espaces (compta et gestion) — si `$registerParametres` est partagé, c'est automatique.

- [ ] **Step 3 : Tester manuellement**

Vérifier dans le navigateur :
- `/compta/parametres/type-operations` → affiche le composant
- `/gestion/parametres/type-operations` → affiche le composant
- Le lien apparaît dans le menu Paramètres des deux espaces

- [ ] **Step 4 : Commit**

```bash
git add routes/web.php resources/views/layouts/app.blade.php && git commit -m "feat(type-operation): add routes and navigation links"
```

---

## Task 4 : Formulaires création/édition d'opération (compta)

**Files:**
- Modify: `app/Http/Controllers/OperationController.php`
- Modify: `app/Http/Requests/StoreOperationRequest.php`
- Modify: `app/Http/Requests/UpdateOperationRequest.php`
- Modify: `resources/views/operations/create.blade.php`
- Modify: `resources/views/operations/edit.blade.php`
- Modify: `tests/Feature/OperationTest.php`

- [ ] **Step 1 : Mettre à jour les tests existants**

Dans `tests/Feature/OperationTest.php` :
- Remplacer les références à `sous_categorie_id` par `type_operation_id`
- Créer un `TypeOperation` en factory/setup pour les tests
- Ajouter test : `it('locks type when participants exist')` — tenter de modifier le type d'une opération avec participants → erreur 422

- [ ] **Step 2 : Modifier le controller**

Dans `app/Http/Controllers/OperationController.php` :
- `create()` : charger `TypeOperation::actif()->orderBy('nom')->get()` au lieu de `SousCategorie`
- `edit()` : charger idem + passer `$hasParticipants = $operation->participants()->exists()`
- Mettre à jour les imports

- [ ] **Step 3 : Modifier les validators**

`StoreOperationRequest.php` :
- Remplacer `'sous_categorie_id' => ['nullable', 'exists:sous_categories,id']` par `'type_operation_id' => ['required', 'exists:type_operations,id']`

`UpdateOperationRequest.php` :
- Même remplacement
- Ajouter règle conditionnelle : si l'opération a des participants, interdire la modification de `type_operation_id`

```php
public function rules(): array
{
    $rules = [...];
    if ($this->route('operation')->participants()->exists()) {
        $rules['type_operation_id'] = ['required', 'integer', Rule::in([$this->route('operation')->type_operation_id])];
    }
    return $rules;
}
```

- [ ] **Step 4 : Modifier la vue `create.blade.php`**

- Remplacer le select sous-catégorie par un select type d'opération avec bouton "+" :

```blade
<div class="mb-3">
    <label for="type_operation_id" class="form-label">Type d'opération <span class="text-danger">*</span></label>
    <div class="input-group">
        <select name="type_operation_id" id="type_operation_id" class="form-select @error('type_operation_id') is-invalid @enderror" required>
            <option value="">— Sélectionner —</option>
            @foreach ($typeOperations as $type)
                <option value="{{ $type->id }}" data-nombre-seances="{{ $type->nombre_seances }}"
                    {{ old('type_operation_id') == $type->id ? 'selected' : '' }}>
                    {{ $type->code }} — {{ $type->nom }}
                </option>
            @endforeach
        </select>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#typeOperationModal">
            <i class="bi bi-plus-lg"></i>
        </button>
    </div>
</div>
```

- Ajouter JS pour pré-remplir `nombre_seances` au changement de type
- Inclure `@livewire('type-operation-manager')` pour la modale inline
- Écouter l'événement `typeOperationCreated` pour ajouter la nouvelle option au select et la sélectionner

- [ ] **Step 5 : Modifier la vue `edit.blade.php`**

- Même sélecteur type que create
- Si `$hasParticipants` : désactiver le select + afficher message d'alerte :

```blade
@if($hasParticipants)
    <div class="alert alert-info mb-3">
        <i class="bi bi-lock"></i> Le type ne peut plus être modifié car des participants sont inscrits.
    </div>
@endif
```

- [ ] **Step 6 : Lancer les tests**

```bash
./vendor/bin/sail test tests/Feature/OperationTest.php
```

- [ ] **Step 7 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): integrate type selector in operation create/edit forms"
```

---

## Task 5 : Liste des opérations compta

**Files:**
- Modify: `app/Http/Controllers/OperationController.php` (méthode `index`)
- Modify: `resources/views/operations/index.blade.php`

- [ ] **Step 1 : Modifier la méthode `index` du controller**

- Eager load `typeOperation` dans la query
- Passer la liste des types actifs pour le filtre
- Filtrer par `type_operation_id` si paramètre GET présent

- [ ] **Step 2 : Modifier la vue `index.blade.php`**

- Ajouter un select filtre par type au-dessus du tableau (submit automatique, même pattern que le filtre exercice existant)
- Ajouter colonne "Type" dans le tableau (badge avec le code)
- Ajouter `data-sort` pour le tri JS sur la colonne Type

- [ ] **Step 3 : Tester manuellement**

- Vérifier que le filtre fonctionne
- Vérifier que la colonne Type s'affiche

- [ ] **Step 4 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): add type column and filter to operations list"
```

---

## Task 6 : Espace Gestion — filtre et bannière

**Files:**
- Modify: `app/Livewire/GestionOperations.php`
- Modify: `resources/views/livewire/gestion-operations.blade.php`

- [ ] **Step 1 : Modifier le composant `GestionOperations`**

- Ajouter propriété `#[Url] public ?int $filterTypeId = null;`
- Dans `render()` :
  - Filtrer les opérations par `type_operation_id` si `$filterTypeId` est renseigné
  - Eager load `typeOperation`
  - Calculer `$hasMissingTypes = Operation::whereNull('type_operation_id')->exists()`
  - Passer `$typeOperations = TypeOperation::actif()->orderBy('nom')->get()` à la vue
- Grouper les opérations par type dans la collection passée à la vue

- [ ] **Step 2 : Modifier la vue `gestion-operations.blade.php`**

- Ajouter bannière d'alerte conditionnelle en haut :

```blade
@if($hasMissingTypes)
    <div class="alert alert-warning d-flex align-items-center mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <div>
            Des opérations ne sont pas encore associées à un type.
            <a href="{{ route($espacePrefix . '.operations.index') }}">Mettre à jour</a>
        </div>
    </div>
@endif
```

- Ajouter filtre par type (select) à côté du sélecteur d'opération
- Grouper les opérations dans le dropdown par type (`<optgroup label="PSA — Parcours de soins A">`)

- [ ] **Step 3 : Tester manuellement**

- Vérifier la bannière (créer une opération sans type en base)
- Vérifier le filtre par type
- Vérifier le groupement dans le dropdown

- [ ] **Step 4 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): add type filter, banner, and grouped dropdown in gestion"
```

---

## Task 7 : Tableau des participants — adhérent, tarif, masquage

**Files:**
- Modify: `app/Livewire/ParticipantTable.php`
- Modify: `resources/views/livewire/participant-table.blade.php`

- [ ] **Step 1 : Modifier le composant `ParticipantTable`**

- Eager load `typeOperationTarif` dans la query des participants
- Eager load `operation.typeOperation` si pas déjà fait
- Ajouter méthode helper `isAdherent(Participant $participant): bool` :
  - Vérifier si le tiers lié a une cotisation active sur l'exercice en cours
  - Utiliser : `$participant->tiers->recettes()->whereHas('sousCategorie', fn($q) => $q->where('pour_cotisations', true))->where('exercice', $exercice)->exists()` — ou équivalent selon le modèle de cotisation existant
- Ajouter propriété computed `$isConfidentiel` et `$isReserveAdherents` depuis `$this->operation->typeOperation`

- [ ] **Step 2 : Modifier la vue participant-table**

- Colonne **Adhérent** (conditionnelle) :
```blade
<th>Adhérent</th>
```
Dans le body :
```blade
<td>
    @if($participant->tiers && $this->isAdherent($participant))
        <span class="badge bg-success">Oui</span>
    @elseif($operation->typeOperation?->reserve_adherents)
        <span class="badge bg-danger">Non</span>
    @endif
</td>
```

- Colonne **Tarif** :
```blade
<td>{{ $participant->typeOperationTarif?->libelle ?? '—' }}</td>
```

- **Masquage colonnes médicales** si `!$operation->typeOperation?->confidentiel` :
  - Encapsuler les `<th>` et `<td>` des colonnes kiné, date naissance, taille, poids dans `@if($operation->typeOperation?->confidentiel)`

- **Masquage bouton token** si `$operation->typeOperation?->confidentiel` :
  - Encapsuler le bouton "Créer token" dans `@if(!$operation->typeOperation?->confidentiel)`

- [ ] **Step 3 : Modifier la modale d'inscription**

- Ajouter un select tarif dans la modale d'ajout de participant :
```blade
@if($operation->typeOperation?->tarifs->count())
    <div class="mb-3">
        <label class="form-label">Tarif</label>
        <select wire:model="editTypeOperationTarifId" class="form-select">
            <option value="">— Aucun —</option>
            @foreach($operation->typeOperation->tarifs as $tarif)
                <option value="{{ $tarif->id }}">{{ $tarif->libelle }} — {{ number_format($tarif->montant, 2, ',', ' ') }} €</option>
            @endforeach
        </select>
    </div>
@endif
```

- [ ] **Step 4 : Tester manuellement**

- Créer un type confidentiel avec tarifs et un type non-confidentiel
- Vérifier masquage des colonnes médicales
- Vérifier masquage du bouton token
- Vérifier l'affichage adhérent (vert/rouge/vide)
- Vérifier le sélecteur de tarif à l'inscription

- [ ] **Step 5 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): add adherent/tarif columns and confidential masking in participants"
```

---

## Task 8 : Écran règlements — pré-remplissage tarif

**Files:**
- Modify: `app/Livewire/ReglementTable.php`
- Modify: `resources/views/livewire/reglement-table.blade.php`

- [ ] **Step 1 : Ajouter le pré-remplissage**

Quand l'écran règlements s'ouvre pour un participant et que `montant_prevu` est vide :
- Lire `$participant->typeOperationTarif?->montant`
- Pré-remplir chaque ligne de règlement avec ce montant

- [ ] **Step 3 : Tester manuellement**

- Inscrire un participant avec un tarif
- Ouvrir l'onglet règlements → vérifier que montant_prevu est pré-rempli

- [ ] **Step 4 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): pre-fill reglement amount from participant tarif"
```

---

## Task 9 : Séances / Présences — masquage kiné

**Files:**
- Identifier les vues/composants des séances et présences qui affichent la colonne kiné

- [ ] **Step 1 : Identifier les fichiers**

Rechercher les fichiers qui affichent la colonne `kine` dans les vues séances/présences.

- [ ] **Step 2 : Conditionner l'affichage de la colonne kiné**

Encapsuler la colonne kiné dans `@if($operation->typeOperation?->confidentiel)`.

- [ ] **Step 3 : Tester manuellement**

- Opération confidentielle → colonne kiné visible
- Opération non confidentielle → colonne kiné masquée

- [ ] **Step 4 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): hide kine column when type is not confidential"
```

---

## Task 10 : Services — RemiseBancaire et HelloAsso

**Files:**
- Modify: `app/Services/RemiseBancaireService.php`
- Modify: `app/Services/HelloAssoSyncService.php`

- [ ] **Step 1 : Modifier `RemiseBancaireService`**

Remplacer toutes les occurrences de `$operation->sous_categorie_id` par `$operation->typeOperation->sous_categorie_id`.
Attention : eager loader `typeOperation` dans les requêtes si nécessaire, pour éviter les N+1.

Lignes concernées (vérifier au moment de l'implémentation — la migration aura supprimé la colonne) :
- Validation que la sous-catégorie existe
- Création des lignes de transaction

Gérer le cas où `$operation->typeOperation` est null (opération sans type pendant la transition) : logger un warning et skipper ou utiliser un fallback.

- [ ] **Step 2 : Modifier `HelloAssoSyncService`**

Résoudre `sous_categorie_id` via `operation->typeOperation->sous_categorie_id`.

**Attention :** Ce service utilise `Operation::where('id', $operationId)->value('sous_categorie_id')` — c'est un accès direct à la colonne qui n'existe plus. Il faut réécrire en chargeant le modèle avec sa relation :

```php
// Avant (cassé après migration) :
$sousCategorieId = Operation::where('id', $operationId)->value('sous_categorie_id');

// Après :
$sousCategorieId = Operation::with('typeOperation')->find($operationId)?->typeOperation?->sous_categorie_id;
```

Mettre à jour toutes les occurrences similaires dans le fichier.

- [ ] **Step 2b : Mettre à jour les tests impactés**

Les tests suivants créent des opérations avec `sous_categorie_id` et vont casser :
- `tests/Feature/RemiseBancaireServiceTest.php`
- `tests/Feature/RemiseBancaireShowTest.php`
- `tests/Feature/RemiseBancaireValidationTest.php`
- `tests/Feature/RemiseBancairePdfTest.php`
- `tests/Feature/ClotureCheckServiceTest.php`
- `tests/Feature/TransactionInscriptionValidationTest.php`

Si la factory `Operation::factory()` a été mise à jour (Task 1 Step 9), la plupart devraient passer automatiquement. Vérifier les tests qui passent `sous_categorie_id` explicitement et remplacer par la création d'un `TypeOperation` avec la bonne sous-catégorie.

- [ ] **Step 3 : Lancer les tests existants**

```bash
./vendor/bin/sail test
```

Vérifier qu'aucun test existant ne casse.

- [ ] **Step 4 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): resolve sous-categorie via type in RemiseBancaire and HelloAsso services"
```

---

## Task 11 : Rapports — filtre par type

**Files:**
- Modify: `app/Livewire/RapportCompteResultatOperations.php`
- Modify: `app/Livewire/RapportSeances.php`
- Modify: les vues Blade associées

- [ ] **Step 1 : Modifier `RapportCompteResultatOperations`**

- Ajouter propriété `public ?int $filterTypeId = null;`
- Filtrer `$selectedOperationIds` par type si `$filterTypeId` est renseigné
- Passer la liste des types à la vue pour le select filtre

- [ ] **Step 2 : Modifier `RapportSeances`**

- Même ajout de filtre par type

- [ ] **Step 3 : Modifier les vues associées**

Ajouter un select filtre par type au-dessus de chaque rapport, avant le sélecteur d'opérations :

```blade
<div class="mb-3">
    <label class="form-label">Filtrer par type</label>
    <select wire:model.live="filterTypeId" class="form-select form-select-sm" style="max-width: 250px;">
        <option value="">Tous les types</option>
        @foreach($typeOperations as $type)
            <option value="{{ $type->id }}">{{ $type->code }} — {{ $type->nom }}</option>
        @endforeach
    </select>
</div>
```

- [ ] **Step 4 : Tester manuellement**

- Sélectionner un type → les opérations filtrées
- Vérifier que le rapport se met à jour

- [ ] **Step 5 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): add type filter to P&L and seances reports"
```

---

## Task 12 : Exports PDF — logo et confidentiel

**Files:**
- Modify: `app/Http/Controllers/ParticipantPdfController.php`
- Modify: `app/Http/Controllers/SeancePdfController.php`
- Modify: `resources/views/pdf/participants-liste.blade.php`
- Modify: `resources/views/pdf/participants-annuaire.blade.php`
- Modify: `resources/views/pdf/seance-emargement.blade.php`
- Modify: `resources/views/pdf/seances-matrice.blade.php`

- [ ] **Step 1 : Modifier les controllers PDF**

Dans `ParticipantPdfController` et `SeancePdfController` :
- Eager load `operation.typeOperation`
- Résoudre le logo : `$headerLogo = $operation->typeOperation?->logo_path` (si défini), sinon logo association
- Passer `$footerLogo` = logo association (toujours, en petit, ~15mm)
- Passer `$isConfidentiel = $operation->typeOperation?->confidentiel ?? false`

- [ ] **Step 2 : Modifier les vues PDF**

Pour chaque vue PDF :
- **En-tête** : utiliser `$headerLogo` au lieu du logo association
- **Pied de page** : ajouter le logo association en petit (~15mm) :

```blade
<div style="position: fixed; bottom: 10mm; left: 10mm;">
    @if($footerLogo)
        <img src="{{ storage_path('app/' . $footerLogo) }}" style="height: 15mm;" alt="">
    @endif
</div>
```

- **Case données confidentielles** : encapsuler dans `@if($isConfidentiel)` les champs/colonnes médicaux

- [ ] **Step 3 : Tester manuellement**

- Générer un PDF pour une opération avec type ayant un logo → logo type en en-tête, logo asso en pied
- Générer un PDF pour une opération avec type sans logo → logo asso en en-tête (fallback)
- Vérifier le masquage des colonnes confidentielles

- [ ] **Step 4 : Commit**

```bash
git add -A && git commit -m "feat(type-operation): use type logo in PDF exports with association footer"
```

---

## Task 13 : Seeder et nettoyage

**Files:**
- Create: `database/seeders/TypeOperationSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: tests existants si besoin

- [ ] **Step 1 : Créer `TypeOperationSeeder`**

Créer 2-3 types de test avec tarifs :
- "PSA" / "Parcours de soins A" : confidentiel=true, reserve_adherents=true, 30 séances, 3 tarifs
- "FORM" / "Formation" : confidentiel=false, reserve_adherents=false, 12 séances, 2 tarifs
- Utiliser une sous-catégorie existante (`pour_inscriptions = true`)

- [ ] **Step 2 : Mettre à jour `DatabaseSeeder`**

- Appeler `TypeOperationSeeder` avant `OperationsTiersSeeder`
- Dans `OperationsTiersSeeder` : assigner un type aux opérations créées

- [ ] **Step 3 : Lancer migrate:fresh --seed**

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

Vérifier que tout passe sans erreur.

- [ ] **Step 4 : Lancer la suite de tests complète**

```bash
./vendor/bin/sail test
```

Vérifier 0 failures.

- [ ] **Step 5 : Appliquer Pint**

```bash
./vendor/bin/pint
```

- [ ] **Step 6 : Commit final**

```bash
git add -A && git commit -m "feat(type-operation): add seeders and ensure full test suite passes"
```
