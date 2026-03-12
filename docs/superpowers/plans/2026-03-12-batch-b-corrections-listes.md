# Batch B — Corrections & Améliorations des listes — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cinq évolutions indépendantes — colonne Référence, filtre Bénéficiaire/Payeur, erreurs FK, persistance de l'onglet Comptes bancaires, et bouton Modifier un compte bancaire.

**Architecture:** Modifications de vues Blade et composants Livewire pour les listes ; ajout de try/catch dans trois controllers pour les FK ; une modale Bootstrap réutilisée pour l'édition des comptes bancaires (JS vanilla + data-* attributes) ; mécanisme de tab-activation via session flash + DOMContentLoaded.

**Tech Stack:** Laravel 11, Blade, Livewire 4, Bootstrap 5 CDN, Pest PHP, MySQL (Sail)

---

## Fichiers modifiés

| Fichier | Raison |
|---------|--------|
| `resources/views/livewire/depense-list.blade.php` | Colonne Référence (#9), filtre Bénéficiaire (#10) |
| `resources/views/livewire/recette-list.blade.php` | Colonne Référence (#9), filtre Payeur (#10) |
| `app/Livewire/DepenseList.php` | Propriété + clause `beneficiaire` (#10) |
| `app/Livewire/RecetteList.php` | Propriété + clause `payeur` (#10) |
| `app/Http/Controllers/CompteBancaireController.php` | Try/catch destroy (#6), activeTab store/update/destroy (#2) |
| `app/Http/Controllers/CategorieController.php` | Try/catch destroy (#6) |
| `app/Http/Controllers/SousCategorieController.php` | Try/catch destroy (#6) |
| `resources/views/parametres/index.blade.php` | Script activeTab (#2), bouton + modale + JS (#1) |
| `tests/Livewire/DepenseListTest.php` | Tests #9, #10 |
| `tests/Livewire/RecetteListTest.php` | Tests #9, #10 |
| `tests/Feature/CompteBancaireTest.php` | Tests #6, #2 |
| `tests/Feature/CategorieTest.php` | Test #6 |
| `tests/Feature/SousCategorieTest.php` | Test #6 |

---

## Chunk 1 : Listes dépenses & recettes

### Task 1 : #9 — Colonne « Référence »

**Files:**
- Modify: `resources/views/livewire/depense-list.blade.php`
- Modify: `resources/views/livewire/recette-list.blade.php`
- Test: `tests/Livewire/DepenseListTest.php`
- Test: `tests/Livewire/RecetteListTest.php`

**Contexte :** Le champ `reference` existe déjà sur les modèles `Depense` et `Recette` (dans `$fillable` et chargé par la query). Les vues ont actuellement 7 colonnes (Date, Libellé, Montant, Mode paiement, Bénéficiaire/Payeur, Pointé, Actions). La colonne Référence s'insère après Libellé, avant Montant → 8 colonnes.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à la fin de `tests/Livewire/DepenseListTest.php` :
```php
it('displays reference column in depense list', function () {
    Depense::factory()->create([
        'libelle' => 'Achat fournitures',
        'reference' => 'REF-2025-042',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->assertSee('Référence')
        ->assertSee('REF-2025-042');
});
```

Ajouter à la fin de `tests/Livewire/RecetteListTest.php` :
```php
it('displays reference column in recette list', function () {
    Recette::factory()->create([
        'libelle' => 'Cotisation membre',
        'reference' => 'REF-REC-007',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->assertSee('Référence')
        ->assertSee('REF-REC-007');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail test --filter="displays reference column" 2>&1
```
Attendu : 2 tests FAIL — « Référence » non trouvé dans le rendu.

- [ ] **Step 3 : Mettre à jour `depense-list.blade.php`**

**a) En-tête — insérer `<th>Référence</th>` après `<th>Libellé</th>` :**

Remplacer :
```html
                    <th>Libellé</th>
                    <th class="text-end">Montant</th>
```
par :
```html
                    <th>Libellé</th>
                    <th>Référence</th>
                    <th class="text-end">Montant</th>
```

**b) Corps du tableau — insérer la cellule après `{{ $depense->libelle }}` :**

Remplacer :
```html
                        <td>{{ $depense->libelle }}</td>
                        <td class="text-end">{{ number_format((float) $depense->montant_total, 2, ',', ' ') }} &euro;</td>
```
par :
```html
                        <td>{{ $depense->libelle }}</td>
                        <td>{{ $depense->reference ?? '-' }}</td>
                        <td class="text-end">{{ number_format((float) $depense->montant_total, 2, ',', ' ') }} &euro;</td>
```

**c) État vide — mettre à jour le colspan 7 → 8 :**

Remplacer :
```html
                        <td colspan="7" class="text-muted text-center">Aucune dépense trouvée.</td>
```
par :
```html
                        <td colspan="8" class="text-muted text-center">Aucune dépense trouvée.</td>
```

- [ ] **Step 4 : Mettre à jour `recette-list.blade.php`** (même structure)

**a)** Remplacer :
```html
                    <th>Libellé</th>
                    <th class="text-end">Montant</th>
```
par :
```html
                    <th>Libellé</th>
                    <th>Référence</th>
                    <th class="text-end">Montant</th>
```

**b)** Remplacer :
```html
                        <td>{{ $recette->libelle }}</td>
                        <td class="text-end">{{ number_format((float) $recette->montant_total, 2, ',', ' ') }} &euro;</td>
```
par :
```html
                        <td>{{ $recette->libelle }}</td>
                        <td>{{ $recette->reference ?? '-' }}</td>
                        <td class="text-end">{{ number_format((float) $recette->montant_total, 2, ',', ' ') }} &euro;</td>
```

**c)** Remplacer :
```html
                        <td colspan="7" class="text-muted text-center">Aucune recette trouvée.</td>
```
par :
```html
                        <td colspan="8" class="text-muted text-center">Aucune recette trouvée.</td>
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail test --filter="displays reference column" 2>&1
```
Attendu : 2 tests PASS.

- [ ] **Step 6 : Commit**

```bash
git add resources/views/livewire/depense-list.blade.php \
        resources/views/livewire/recette-list.blade.php \
        tests/Livewire/DepenseListTest.php \
        tests/Livewire/RecetteListTest.php
git commit -m "feat: add Référence column to depense and recette lists (#9)"
```

---

### Task 2 : #10 — Filtre « Bénéficiaire » / « Payeur »

**Files:**
- Modify: `app/Livewire/DepenseList.php`
- Modify: `resources/views/livewire/depense-list.blade.php`
- Modify: `app/Livewire/RecetteList.php`
- Modify: `resources/views/livewire/recette-list.blade.php`
- Test: `tests/Livewire/DepenseListTest.php`
- Test: `tests/Livewire/RecetteListTest.php`

**Contexte :** Les colonnes Bénéficiaire (Depense) et Payeur (Recette) s'affichent déjà dans le tableau. Il manque seulement la propriété Livewire + la clause WHERE + le champ de filtre dans la zone des filtres. La zone de filtres a actuellement 5 colonnes `col-md-2` (5×2 = 10 colonnes Bootstrap). L'ajout d'une 6e remplit parfaitement la ligne à 12 colonnes.

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à la fin de `tests/Livewire/DepenseListTest.php` :
```php
it('filters depenses by beneficiaire', function () {
    Depense::factory()->create([
        'libelle' => 'Dépense Alpha',
        'beneficiaire' => 'Alpha Corp',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);
    Depense::factory()->create([
        'libelle' => 'Dépense Beta',
        'beneficiaire' => 'Beta SA',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(DepenseList::class)
        ->set('beneficiaire', 'Alpha')
        ->assertSee('Dépense Alpha')
        ->assertDontSee('Dépense Beta');
});
```

Ajouter à la fin de `tests/Livewire/RecetteListTest.php` :
```php
it('filters recettes by payeur', function () {
    Recette::factory()->create([
        'libelle' => 'Recette Gamma',
        'payeur' => 'Gamma SARL',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'libelle' => 'Recette Delta',
        'payeur' => 'Delta Inc',
        'date' => '2025-10-15',
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(RecetteList::class)
        ->set('payeur', 'Gamma')
        ->assertSee('Recette Gamma')
        ->assertDontSee('Recette Delta');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail test --filter="filters (depenses by beneficiaire|recettes by payeur)" 2>&1
```
Attendu : 2 tests FAIL — propriété `beneficiaire`/`payeur` inexistante sur le composant.

- [ ] **Step 3 : Mettre à jour `app/Livewire/DepenseList.php`**

**a)** Ajouter la propriété après `public ?string $pointe = null;` :
```php
    public ?string $pointe = null;

    public ?string $beneficiaire = null;
```

**b)** Ajouter la méthode `updatedBeneficiaire()` après `updatedPointe()` :
```php
    public function updatedPointe(): void
    {
        $this->resetPage();
    }

    public function updatedBeneficiaire(): void
    {
        $this->resetPage();
    }
```

**c)** Dans `render()`, ajouter la clause de filtre après le bloc `if ($this->pointe ...)` :
```php
        if ($this->pointe !== null && $this->pointe !== '') {
            $query->where('pointe', $this->pointe === '1');
        }

        if ($this->beneficiaire) {
            $query->where('beneficiaire', 'like', '%'.$this->beneficiaire.'%');
        }
```

- [ ] **Step 4 : Mettre à jour `app/Livewire/RecetteList.php`** (même structure)

**a)** Ajouter la propriété après `public ?string $pointe = null;` :
```php
    public ?string $pointe = null;

    public ?string $payeur = null;
```

**b)** Ajouter la méthode `updatedPayeur()` après `updatedPointe()` :
```php
    public function updatedPointe(): void
    {
        $this->resetPage();
    }

    public function updatedPayeur(): void
    {
        $this->resetPage();
    }
```

**c)** Dans `render()`, ajouter la clause après le bloc `if ($this->pointe ...)` :
```php
        if ($this->pointe !== null && $this->pointe !== '') {
            $query->where('pointe', $this->pointe === '1');
        }

        if ($this->payeur) {
            $query->where('payeur', 'like', '%'.$this->payeur.'%');
        }
```

- [ ] **Step 5 : Ajouter le champ filtre dans `depense-list.blade.php`**

Ajouter un nouveau `<div class="col-md-2">` après la div Pointé (juste avant la fermeture `</div>` de `<div class="row g-3">`) :

Remplacer :
```html
                <div class="col-md-2">
                    <label for="filter-pointe" class="form-label">Pointé</label>
                    <select wire:model.live="pointe" id="filter-pointe" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="1">Oui</option>
                        <option value="0">Non</option>
                    </select>
                </div>
            </div>
```
par :
```html
                <div class="col-md-2">
                    <label for="filter-pointe" class="form-label">Pointé</label>
                    <select wire:model.live="pointe" id="filter-pointe" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="1">Oui</option>
                        <option value="0">Non</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-beneficiaire" class="form-label">Bénéficiaire</label>
                    <input type="text" wire:model.live.debounce.300ms="beneficiaire"
                           id="filter-beneficiaire"
                           class="form-control form-control-sm" placeholder="Bénéficiaire...">
                </div>
            </div>
```

- [ ] **Step 6 : Ajouter le champ filtre dans `recette-list.blade.php`** (même pattern)

Remplacer :
```html
                <div class="col-md-2">
                    <label for="filter-pointe" class="form-label">Pointé</label>
                    <select wire:model.live="pointe" id="filter-pointe" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="1">Oui</option>
                        <option value="0">Non</option>
                    </select>
                </div>
            </div>
```
par :
```html
                <div class="col-md-2">
                    <label for="filter-pointe" class="form-label">Pointé</label>
                    <select wire:model.live="pointe" id="filter-pointe" class="form-select form-select-sm">
                        <option value="">Tous</option>
                        <option value="1">Oui</option>
                        <option value="0">Non</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filter-payeur" class="form-label">Payeur</label>
                    <input type="text" wire:model.live.debounce.300ms="payeur"
                           id="filter-payeur"
                           class="form-control form-control-sm" placeholder="Payeur...">
                </div>
            </div>
```

- [ ] **Step 7 : Lancer les tests**

```bash
./vendor/bin/sail test --filter="filters (depenses by beneficiaire|recettes by payeur)" 2>&1
```
Attendu : 2 tests PASS.

- [ ] **Step 8 : Lancer la suite complète pour vérifier l'absence de régressions**

```bash
./vendor/bin/sail test 2>&1
```
Attendu : tous les tests PASS.

- [ ] **Step 9 : Commit**

```bash
git add app/Livewire/DepenseList.php \
        app/Livewire/RecetteList.php \
        resources/views/livewire/depense-list.blade.php \
        resources/views/livewire/recette-list.blade.php \
        tests/Livewire/DepenseListTest.php \
        tests/Livewire/RecetteListTest.php
git commit -m "feat: add Bénéficiaire/Payeur filter to depense and recette lists (#10)"
```

---

## Chunk 2 : Paramètres — erreurs FK, onglet actif, bouton Modifier

### Task 3 : #6 — Erreurs FK → message utilisateur

**Files:**
- Modify: `app/Http/Controllers/CompteBancaireController.php`
- Modify: `app/Http/Controllers/CategorieController.php`
- Modify: `app/Http/Controllers/SousCategorieController.php`
- Test: `tests/Feature/CompteBancaireTest.php`
- Test: `tests/Feature/CategorieTest.php`
- Test: `tests/Feature/SousCategorieTest.php`

**Contexte :**
- Le composant `x-flash-message` gère déjà les clés `success` ET `error` — aucune modification nécessaire sur ce fichier.
- Les codes d'erreur MySQL autres que `23000` sont re-throwés pour ne pas masquer des erreurs inattendues.
- Pour `CompteBancaireController.destroy()`, le spec combine l'item #6 et l'item #2 : la méthode `destroy()` reçoit à la fois le try/catch ET `->with('activeTab', 'comptes')` dès cette tâche (car les deux items partagent exactement le même bloc).
- Pour `CategorieController` et `SousCategorieController`, pas d'`activeTab`.

**Violations FK à simuler en test :**
- `CompteBancaire` : créer une `Depense` liée au compte (`compte_id`) → FK sur `depenses.compte_id`
- `Categorie` : créer une `SousCategorie` liée à la catégorie (`categorie_id`) → FK sur `sous_categories.categorie_id`
- `SousCategorie` : créer une `DepenseLigne` liée à la sous-catégorie (`sous_categorie_id`) → FK sur `depense_lignes.sous_categorie_id`

> **Note pour la SousCategorie :** La création d'une DepenseLigne nécessite une Depense parente. Vérifier `database/factories/` pour un `DepenseLigneFactory` ou utiliser `DB::table('depense_lignes')->insert([...])` avec les colonnes requises (consulter la migration `*create_depense_lignes_table*`).

- [ ] **Step 1 : Écrire les tests qui échouent**

Ajouter à la fin de `tests/Feature/CompteBancaireTest.php` :
```php
it('returns flash error when destroying a compte bancaire with linked depenses', function () {
    $compte = CompteBancaire::factory()->create();
    \App\Models\Depense::factory()->create([
        'compte_id' => $compte->id,
        'saisi_par' => $this->user->id,
        'date' => '2025-10-15',
    ]);

    $this->actingAs($this->user)
        ->delete(route('parametres.comptes-bancaires.destroy', $compte))
        ->assertRedirect(route('parametres.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('comptes_bancaires', ['id' => $compte->id]);
});
```

Ajouter à la fin de `tests/Feature/CategorieTest.php` :
```php
it('returns flash error when destroying a categorie with sous-categories', function () {
    $categorie = Categorie::factory()->create();
    \App\Models\SousCategorie::factory()->create(['categorie_id' => $categorie->id]);

    $this->actingAs($this->user)
        ->delete(route('parametres.categories.destroy', $categorie))
        ->assertRedirect(route('parametres.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('categories', ['id' => $categorie->id]);
});
```

Ajouter en haut de `tests/Feature/SousCategorieTest.php`, parmi les `use` existants :
```php
use App\Models\DepenseLigne;
```

Ajouter à la fin de `tests/Feature/SousCategorieTest.php` :
```php
it('returns flash error when destroying a sous-categorie with linked lignes', function () {
    $sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);

    $depense = \App\Models\Depense::factory()->create([
        'saisi_par' => $this->user->id,
        'date' => '2025-10-15',
    ]);
    DepenseLigne::factory()->create([
        'depense_id'        => $depense->id,
        'sous_categorie_id' => $sc->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('parametres.sous-categories.destroy', $sc))
        ->assertRedirect(route('parametres.index'))
        ->assertSessionHas('error');

    $this->assertDatabaseHas('sous_categories', ['id' => $sc->id]);
});
```
> `DepenseLigneFactory` est confirmé présent dans `database/factories/`. La table `depense_lignes` utilise `$timestamps = false` — ne pas passer `created_at`/`updated_at` en insert brut.

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail test --filter="returns flash error when destroying" 2>&1
```
Attendu : 3 tests FAIL — l'application crashe (QueryException non gérée) au lieu de rediriger avec `error`.

- [ ] **Step 3 : Mettre à jour `CompteBancaireController.php`**

Ajouter l'import en haut du fichier (après les imports existants) :
```php
use Illuminate\Http\RedirectResponse;
```
(déjà présent — vérifier avant d'ajouter)

Remplacer la méthode `destroy()` entière :
```php
    public function destroy(CompteBancaire $comptesBancaire): RedirectResponse
    {
        $comptesBancaire->delete();

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire supprimé avec succès.');
    }
```
par :
```php
    public function destroy(CompteBancaire $comptesBancaire): RedirectResponse
    {
        try {
            $comptesBancaire->delete();

            return redirect()->route('parametres.index')
                ->with('success', 'Compte bancaire supprimé avec succès.')
                ->with('activeTab', 'comptes');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.')
                    ->with('activeTab', 'comptes');
            }
            throw $e;
        }
    }
```

- [ ] **Step 4 : Mettre à jour `CategorieController.php`**

Remplacer la méthode `destroy()` entière :
```php
    public function destroy(Categorie $category): RedirectResponse
    {
        $category->delete();

        return redirect()->route('parametres.index')
            ->with('success', 'Catégorie supprimée avec succès.');
    }
```
par :
```php
    public function destroy(Categorie $category): RedirectResponse
    {
        try {
            $category->delete();

            return redirect()->route('parametres.index')
                ->with('success', 'Catégorie supprimée avec succès.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
```

- [ ] **Step 5 : Mettre à jour `SousCategorieController.php`**

Remplacer la méthode `destroy()` entière :
```php
    public function destroy(SousCategorie $sousCategory): RedirectResponse
    {
        $sousCategory->delete();

        return redirect()->route('parametres.index')
            ->with('success', 'Sous-catégorie supprimée avec succès.');
    }
```
par :
```php
    public function destroy(SousCategorie $sousCategory): RedirectResponse
    {
        try {
            $sousCategory->delete();

            return redirect()->route('parametres.index')
                ->with('success', 'Sous-catégorie supprimée avec succès.');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return redirect()->route('parametres.index')
                    ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
            }
            throw $e;
        }
    }
```

- [ ] **Step 6 : Lancer les tests**

```bash
./vendor/bin/sail test --filter="returns flash error when destroying" 2>&1
```
Attendu : 3 tests PASS.

- [ ] **Step 7 : Lancer la suite complète**

```bash
./vendor/bin/sail test 2>&1
```
Attendu : tous les tests PASS.

- [ ] **Step 8 : Commit**

```bash
git add app/Http/Controllers/CompteBancaireController.php \
        app/Http/Controllers/CategorieController.php \
        app/Http/Controllers/SousCategorieController.php \
        tests/Feature/CompteBancaireTest.php \
        tests/Feature/CategorieTest.php \
        tests/Feature/SousCategorieTest.php
git commit -m "feat: catch FK constraint errors in destroy() and show flash error message (#6)"
```

---

### Task 4 : #2 — Rester sur l'onglet « Comptes bancaires » après action

**Files:**
- Modify: `app/Http/Controllers/CompteBancaireController.php`
- Modify: `resources/views/parametres/index.blade.php`
- Test: `tests/Feature/CompteBancaireTest.php`

**Contexte :**
- `destroy()` a déjà `->with('activeTab', 'comptes')` depuis la tâche précédente (#6).
- Il reste à ajouter `->with('activeTab', 'comptes')` à `store()` et `update()`.
- Dans la vue, un script `DOMContentLoaded` active l'onglet Bootstrap dont l'`id` est `comptes-tab` (ligne 21 de la vue).
- Le mécanisme est extensible : pour tout autre onglet, passer l'`id` de son bouton tab.

- [ ] **Step 1 : Modifier les tests existants pour qu'ils échouent**

Dans `tests/Feature/CompteBancaireTest.php`, ajouter `->assertSessionHas('activeTab', 'comptes')` aux tests de store et update :

```php
it('can store a compte bancaire', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Compte Courant',
            'iban' => 'FR7630006000011234567890189',
            'solde_initial' => 1500.50,
            'date_solde_initial' => '2024-01-01',
        ])
        ->assertRedirect(route('parametres.index'))
        ->assertSessionHas('activeTab', 'comptes');  // ← ajouter cette ligne

    $this->assertDatabaseHas('comptes_bancaires', [
        'nom' => 'Compte Courant',
        'iban' => 'FR7630006000011234567890189',
    ]);
});
```

```php
it('can update a compte bancaire', function () {
    $compte = CompteBancaire::factory()->create();

    $this->actingAs($this->user)
        ->put(route('parametres.comptes-bancaires.update', $compte), [
            'nom' => 'Nom modifié',
            'iban' => 'FR7630006000011234567890189',
            'solde_initial' => 2000,
            'date_solde_initial' => '2024-06-01',
        ])
        ->assertRedirect(route('parametres.index'))
        ->assertSessionHas('activeTab', 'comptes');  // ← ajouter cette ligne

    $this->assertDatabaseHas('comptes_bancaires', [
        'id' => $compte->id,
        'nom' => 'Nom modifié',
    ]);
});
```

> Le test `can destroy a compte bancaire` vérifie déjà la redirection. Ajouter `->assertSessionHas('activeTab', 'comptes')` optionnellement — la logique est déjà en place depuis Task 3.

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail test --filter="can (store|update) a compte bancaire" 2>&1
```
Attendu : 2 tests FAIL — session ne contient pas `activeTab`.

- [ ] **Step 3 : Mettre à jour `CompteBancaireController.php`**

Remplacer la méthode `store()` :
```php
    public function store(StoreCompteBancaireRequest $request): RedirectResponse
    {
        CompteBancaire::create($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire créé avec succès.');
    }
```
par :
```php
    public function store(StoreCompteBancaireRequest $request): RedirectResponse
    {
        CompteBancaire::create($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire créé avec succès.')
            ->with('activeTab', 'comptes');
    }
```

Remplacer la méthode `update()` :
```php
    public function update(UpdateCompteBancaireRequest $request, CompteBancaire $comptesBancaire): RedirectResponse
    {
        $comptesBancaire->update($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire mis à jour avec succès.');
    }
```
par :
```php
    public function update(UpdateCompteBancaireRequest $request, CompteBancaire $comptesBancaire): RedirectResponse
    {
        $comptesBancaire->update($request->validated());

        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire mis à jour avec succès.')
            ->with('activeTab', 'comptes');
    }
```

- [ ] **Step 4 : Ajouter le script d'activation d'onglet dans `parametres/index.blade.php`**

Le fichier se termine par un bloc `<script>` contenant `editCategorie()` (ligne 426), suivi de `</x-app-layout>`. Insérer le script d'activation avant la fermeture de `</x-app-layout>` :

Remplacer :
```blade
    <script>
        function editCategorie(btn, id, nom, type) {
            const newNom = prompt('Nom de la catégorie :', nom);
            if (newNom === null) return;
            const form = btn.closest('form');
            form.querySelector('input[name="nom"]').value = newNom;
            form.submit();
        }
    </script>
</x-app-layout>
```
par :
```blade
    <script>
        function editCategorie(btn, id, nom, type) {
            const newNom = prompt('Nom de la catégorie :', nom);
            if (newNom === null) return;
            const form = btn.closest('form');
            form.querySelector('input[name="nom"]').value = newNom;
            form.submit();
        }
    </script>

    @if(session('activeTab') === 'comptes')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            bootstrap.Tab.getOrCreateInstance(
                document.getElementById('comptes-tab')
            ).show();
        });
    </script>
    @endif
</x-app-layout>
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail test --filter="can (store|update) a compte bancaire" 2>&1
```
Attendu : 2 tests PASS.

- [ ] **Step 6 : Lancer la suite complète**

```bash
./vendor/bin/sail test 2>&1
```
Attendu : tous les tests PASS.

- [ ] **Step 7 : Commit**

```bash
git add app/Http/Controllers/CompteBancaireController.php \
        resources/views/parametres/index.blade.php \
        tests/Feature/CompteBancaireTest.php
git commit -m "feat: persist Comptes bancaires tab active after store/update/destroy (#2)"
```

---

### Task 5 : #1 — Bouton Modifier un compte bancaire (modale Bootstrap)

**Files:**
- Modify: `resources/views/parametres/index.blade.php`
- Test: `tests/Feature/CompteBancaireTest.php` (test existant `can update a compte bancaire` couvre déjà la route `PUT`)

**Contexte :**
- `update()` est déjà implémenté dans `CompteBancaireController`.
- La route `PUT /parametres/comptes-bancaires/{id}` est déjà testée.
- Il s'agit d'une modification purement de vue : un seul `<div class="modal">` dans la page, réutilisé pour tous les comptes via `data-*` attributes.
- Le `show.bs.modal` event de Bootstrap fournit `event.relatedTarget` qui est le bouton déclencheur.
- L'action du formulaire est mise à jour dynamiquement par le script JS.

> Aucun nouveau test feature n'est requis : la route `update` est déjà couverte. Optionnellement, ajouter un test de vue qui vérifie la présence du modal dans la réponse HTML de la page paramètres.

- [ ] **Step 1 : (Optionnel) Ajouter un test de vue**

Ajouter à la fin de `tests/Feature/CompteBancaireTest.php` :
```php
it('shows edit modal trigger button on parametres page', function () {
    CompteBancaire::factory()->create(['nom' => 'Compte Test']);

    $this->actingAs($this->user)
        ->get(route('parametres.index'))
        ->assertSee('editCompteBancaireModal')
        ->assertSee('bi-pencil');
});
```

- [ ] **Step 2 : Lancer le test optionnel pour vérifier qu'il échoue**

```bash
./vendor/bin/sail test --filter="shows edit modal trigger button" 2>&1
```
Attendu : FAIL — `editCompteBancaireModal` non trouvé.

- [ ] **Step 3 : Modifier `parametres/index.blade.php` — ajouter le bouton Modifier dans le tableau**

Dans la section des comptes bancaires, la cellule Actions (lignes 255-263) contient seulement le bouton Supprimer. Ajouter le bouton Modifier avant le formulaire de suppression.

Remplacer :
```html
                            <td>
                                <form action="{{ route('parametres.comptes-bancaires.destroy', $compte) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Supprimer ce compte bancaire ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
```
par :
```html
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#editCompteBancaireModal"
                                        data-id="{{ $compte->id }}"
                                        data-nom="{{ $compte->nom }}"
                                        data-iban="{{ $compte->iban }}"
                                        data-solde="{{ $compte->solde_initial }}"
                                        data-date="{{ $compte->date_solde_initial?->format('Y-m-d') }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form action="{{ route('parametres.comptes-bancaires.destroy', $compte) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Supprimer ce compte bancaire ?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
```

- [ ] **Step 4 : Ajouter la modale et le script JS dans `parametres/index.blade.php`**

Insérer la modale et son script juste avant le bloc `<script>` contenant `editCategorie`. Remplacer le début du bloc final :

```blade
    <script>
        function editCategorie(btn, id, nom, type) {
```
par :
```blade
    {{-- Modale d'édition d'un compte bancaire --}}
    <div class="modal fade" id="editCompteBancaireModal" tabindex="-1"
         aria-labelledby="editCompteBancaireModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCompteBancaireModalLabel">Modifier le compte bancaire</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <form method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_cb_nom" class="form-label">Nom <span class="text-danger">*</span></label>
                            <input type="text" name="nom" id="edit_cb_nom" class="form-control" required maxlength="150">
                        </div>
                        <div class="mb-3">
                            <label for="edit_cb_iban" class="form-label">IBAN</label>
                            <input type="text" name="iban" id="edit_cb_iban" class="form-control" maxlength="34">
                        </div>
                        <div class="mb-3">
                            <label for="edit_cb_solde" class="form-label">Solde initial <span class="text-danger">*</span></label>
                            <input type="number" name="solde_initial" id="edit_cb_solde" class="form-control" required step="0.01">
                        </div>
                        <div class="mb-3">
                            <label for="edit_cb_date" class="form-label">Date solde <span class="text-danger">*</span></label>
                            <input type="date" name="date_solde_initial" id="edit_cb_date" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('editCompteBancaireModal')
            .addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                this.querySelector('[name="nom"]').value             = btn.dataset.nom;
                this.querySelector('[name="iban"]').value            = btn.dataset.iban ?? '';
                this.querySelector('[name="solde_initial"]').value   = btn.dataset.solde;
                this.querySelector('[name="date_solde_initial"]').value = btn.dataset.date ?? '';
                this.querySelector('form').action =
                    '/parametres/comptes-bancaires/' + btn.dataset.id;
            });
    </script>

    <script>
        function editCategorie(btn, id, nom, type) {
```

- [ ] **Step 5 : Lancer le test optionnel**

```bash
./vendor/bin/sail test --filter="shows edit modal trigger button" 2>&1
```
Attendu : PASS.

- [ ] **Step 6 : Lancer la suite complète**

```bash
./vendor/bin/sail test 2>&1
```
Attendu : tous les tests PASS.

- [ ] **Step 7 : Commit**

```bash
git add resources/views/parametres/index.blade.php \
        tests/Feature/CompteBancaireTest.php
git commit -m "feat: add edit button and Bootstrap modal for compte bancaire (#1)"
```

---

## Vérification finale

- [ ] **Lancer la suite complète une dernière fois**

```bash
./vendor/bin/sail test 2>&1
```
Attendu : tous les tests PASS, aucune régression.
