# Comptes Bancaires — Attributs actif & édition Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter deux attributs booléens (`actif_recettes_depenses`, `actif_dons_cotisations`) aux comptes bancaires, permettre leur édition via un modal Bootstrap, et filtrer les comptes disponibles dans les formulaires Livewire.

**Architecture:** Migration pour les deux colonnes booléennes avec `default(true)` ; `prepareForValidation()` dans les FormRequests pour gérer les cases non cochées (non soumises par le navigateur) ; modal Bootstrap unique rempli via `data-update-url` et JS pour l'édition ; les quatre composants Livewire filtrent les comptes selon l'attribut pertinent.

**Tech Stack:** Laravel 11, Pest PHP, Bootstrap 5 (CDN), Blade, Livewire 4

---

## Chunk 1: Migration, modèle, factory et FormRequests

### Task 1: Migration et couche modèle

**Files:**
- Create: `database/migrations/2026_03_13_000001_add_actif_fields_to_comptes_bancaires_table.php`
- Modify: `database/factories/CompteBancaireFactory.php`
- Modify: `app/Models/CompteBancaire.php`
- Modify: `app/Http/Requests/StoreCompteBancaireRequest.php`
- Modify: `app/Http/Requests/UpdateCompteBancaireRequest.php`
- Test: `tests/Feature/CompteBancaireTest.php`

- [ ] **Step 1 : Écrire les tests pour les nouveaux champs**

Ajouter dans `tests/Feature/CompteBancaireTest.php` :

```php
it('defaults actif_recettes_depenses and actif_dons_cotisations to true', function () {
    $compte = CompteBancaire::factory()->create();

    expect($compte->actif_recettes_depenses)->toBeTrue();
    expect($compte->actif_dons_cotisations)->toBeTrue();
});

it('can store a compte bancaire with actif flags', function () {
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Caisse',
            'solde_initial' => 0,
            'date_solde_initial' => '2024-01-01',
            'actif_recettes_depenses' => '1',
            'actif_dons_cotisations' => '0',
        ])
        ->assertRedirect(route('parametres.comptes-bancaires.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('comptes_bancaires', [
        'nom' => 'Caisse',
        'actif_recettes_depenses' => true,
        'actif_dons_cotisations' => false,
    ]);
});

it('treats missing actif checkbox as false when storing', function () {
    // Les cases non cochées ne sont pas soumises par le navigateur
    $this->actingAs($this->user)
        ->post(route('parametres.comptes-bancaires.store'), [
            'nom' => 'Caisse sans flags',
            'solde_initial' => 0,
            'date_solde_initial' => '2024-01-01',
            // Aucun actif_* soumis : simule une case décochée
        ])
        ->assertRedirect(route('parametres.comptes-bancaires.index'));

    $this->assertDatabaseHas('comptes_bancaires', [
        'nom' => 'Caisse sans flags',
        'actif_recettes_depenses' => false,
        'actif_dons_cotisations' => false,
    ]);
});

it('can update actif flags on a compte bancaire', function () {
    $compte = CompteBancaire::factory()->create([
        'actif_recettes_depenses' => true,
        'actif_dons_cotisations' => true,
    ]);

    $this->actingAs($this->user)
        ->put(route('parametres.comptes-bancaires.update', $compte), [
            'nom' => $compte->nom,
            'solde_initial' => $compte->solde_initial,
            'date_solde_initial' => $compte->date_solde_initial->format('Y-m-d'),
            'actif_recettes_depenses' => '0',
            'actif_dons_cotisations' => '1',
        ])
        ->assertRedirect(route('parametres.comptes-bancaires.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('comptes_bancaires', [
        'id' => $compte->id,
        'actif_recettes_depenses' => false,
        'actif_dons_cotisations' => true,
    ]);
});

it('treats missing actif checkbox as false when updating', function () {
    $compte = CompteBancaire::factory()->create([
        'actif_recettes_depenses' => true,
        'actif_dons_cotisations' => true,
    ]);

    // Simule un PUT sans les cases cochées (navigateur ne soumet pas les cases décochées)
    $this->actingAs($this->user)
        ->put(route('parametres.comptes-bancaires.update', $compte), [
            'nom' => $compte->nom,
            'solde_initial' => $compte->solde_initial,
            'date_solde_initial' => $compte->date_solde_initial->format('Y-m-d'),
        ])
        ->assertRedirect(route('parametres.comptes-bancaires.index'));

    $this->assertDatabaseHas('comptes_bancaires', [
        'id' => $compte->id,
        'actif_recettes_depenses' => false,
        'actif_dons_cotisations' => false,
    ]);
});
```

- [ ] **Step 2 : Lancer les nouveaux tests pour confirmer qu'ils échouent**

```bash
./vendor/bin/sail artisan test --filter="defaults actif|can store a compte bancaire with actif|treats missing actif|can update actif"
```

Résultat attendu : FAIL (colonnes inexistantes)

- [ ] **Step 3 : Créer la migration**

Créer `database/migrations/2026_03_13_000001_add_actif_fields_to_comptes_bancaires_table.php` :

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
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->boolean('actif_recettes_depenses')->default(true)->after('date_solde_initial');
            $table->boolean('actif_dons_cotisations')->default(true)->after('actif_recettes_depenses');
        });
    }

    public function down(): void
    {
        Schema::table('comptes_bancaires', function (Blueprint $table) {
            $table->dropColumn(['actif_recettes_depenses', 'actif_dons_cotisations']);
        });
    }
};
```

- [ ] **Step 4 : Mettre à jour le modèle `CompteBancaire`**

Dans `app/Models/CompteBancaire.php`, remplacer le `$fillable` et `casts()` existants par :

```php
protected $fillable = [
    'nom',
    'iban',
    'solde_initial',
    'date_solde_initial',
    'actif_recettes_depenses',
    'actif_dons_cotisations',
];

protected function casts(): array
{
    return [
        'solde_initial' => 'decimal:2',
        'date_solde_initial' => 'date',
        'actif_recettes_depenses' => 'boolean',
        'actif_dons_cotisations' => 'boolean',
    ];
}
```

- [ ] **Step 5 : Mettre à jour la factory**

Dans `database/factories/CompteBancaireFactory.php`, ajouter dans `definition()` :

```php
'actif_recettes_depenses' => true,
'actif_dons_cotisations' => true,
```

- [ ] **Step 6 : Mettre à jour `StoreCompteBancaireRequest`**

Dans `app/Http/Requests/StoreCompteBancaireRequest.php`, ajouter la méthode `prepareForValidation()` et les deux règles dans `rules()` :

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'actif_recettes_depenses' => $this->boolean('actif_recettes_depenses'),
        'actif_dons_cotisations'  => $this->boolean('actif_dons_cotisations'),
    ]);
}

public function rules(): array
{
    return [
        'nom' => ['required', 'string', 'max:150'],
        'iban' => ['nullable', 'string', 'max:34'],
        'solde_initial' => ['required', 'numeric'],
        'date_solde_initial' => ['required', 'date'],
        'actif_recettes_depenses' => ['boolean'],
        'actif_dons_cotisations' => ['boolean'],
    ];
}
```

Note : `$this->boolean('key')` retourne `false` si la clé est absente — ce qui est le comportement voulu pour les cases décochées.

- [ ] **Step 7 : Mettre à jour `UpdateCompteBancaireRequest`**

Même modification dans `app/Http/Requests/UpdateCompteBancaireRequest.php` :

```php
protected function prepareForValidation(): void
{
    $this->merge([
        'actif_recettes_depenses' => $this->boolean('actif_recettes_depenses'),
        'actif_dons_cotisations'  => $this->boolean('actif_dons_cotisations'),
    ]);
}

public function rules(): array
{
    return [
        'nom' => ['required', 'string', 'max:150'],
        'iban' => ['nullable', 'string', 'max:34'],
        'solde_initial' => ['required', 'numeric'],
        'date_solde_initial' => ['required', 'date'],
        'actif_recettes_depenses' => ['boolean'],
        'actif_dons_cotisations' => ['boolean'],
    ];
}
```

- [ ] **Step 8 : Lancer la migration et les tests**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter=CompteBancaire
```

Résultat attendu : tous les tests PASS

- [ ] **Step 9 : Commit**

```bash
git add database/migrations/2026_03_13_000001_add_actif_fields_to_comptes_bancaires_table.php \
        app/Models/CompteBancaire.php \
        database/factories/CompteBancaireFactory.php \
        app/Http/Requests/StoreCompteBancaireRequest.php \
        app/Http/Requests/UpdateCompteBancaireRequest.php \
        tests/Feature/CompteBancaireTest.php
git commit -m "feat: add actif_recettes_depenses and actif_dons_cotisations to comptes_bancaires"
```

---

## Chunk 2: Vue — formulaire d'ajout et modal d'édition

### Task 2: Mise à jour de la vue `comptes-bancaires/index.blade.php`

**Files:**
- Modify: `resources/views/parametres/comptes-bancaires/index.blade.php`

- [ ] **Step 1 : Ajouter les cases à cocher dans le formulaire d'ajout**

Dans le formulaire d'ajout (`.collapse#addCompteForm`), la ligne actuelle est sur 5 colonnes Bootstrap (3+3+2+2+2). Insérer une nouvelle colonne pour les cases à cocher entre `col-md-2` (date) et `col-md-2` (bouton), et réduire le bouton à `col-md-1` :

```blade
<div class="col-md-2">
    <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" name="actif_recettes_depenses"
               id="cb_actif_rd" value="1" checked>
        <label class="form-check-label" for="cb_actif_rd">Rec./Dép.</label>
    </div>
    <div class="form-check">
        <input class="form-check-input" type="checkbox" name="actif_dons_cotisations"
               id="cb_actif_dc" value="1" checked>
        <label class="form-check-label" for="cb_actif_dc">Dons/Cot.</label>
    </div>
</div>
```

Changer le `<div class="col-md-2">` du bouton "Enregistrer" en `<div class="col-md-1">`.

- [ ] **Step 2 : Ajouter les colonnes dans l'en-tête du tableau**

Remplacer `<th style="width: 180px;">Actions</th>` par :

```blade
<th class="text-center">Rec./Dép.</th>
<th class="text-center">Dons/Cot.</th>
<th style="width: 130px;">Actions</th>
```

Mettre aussi à jour le `colspan` de la ligne `@empty` : remplacer `colspan="5"` par `colspan="7"`.

- [ ] **Step 3 : Ajouter les cellules dans chaque ligne du tableau**

Dans la boucle `@forelse`, avant la cellule Actions, insérer :

```blade
<td class="text-center">
    @if ($compte->actif_recettes_depenses)
        <i class="bi bi-check-circle-fill text-success"></i>
    @else
        <i class="bi bi-x-circle text-secondary"></i>
    @endif
</td>
<td class="text-center">
    @if ($compte->actif_dons_cotisations)
        <i class="bi bi-check-circle-fill text-success"></i>
    @else
        <i class="bi bi-x-circle text-secondary"></i>
    @endif
</td>
```

- [ ] **Step 4 : Ajouter le bouton Modifier dans chaque ligne**

Dans la cellule Actions, avant le bouton supprimer, ajouter :

```blade
<button type="button" class="btn btn-sm btn-outline-primary"
        data-bs-toggle="modal"
        data-bs-target="#editCompteModal"
        data-update-url="{{ route('parametres.comptes-bancaires.update', $compte) }}"
        data-nom="{{ addslashes($compte->nom) }}"
        data-iban="{{ addslashes($compte->iban ?? '') }}"
        data-solde="{{ $compte->solde_initial }}"
        data-date="{{ $compte->date_solde_initial->format('Y-m-d') }}"
        data-actif-rd="{{ $compte->actif_recettes_depenses ? '1' : '0' }}"
        data-actif-dc="{{ $compte->actif_dons_cotisations ? '1' : '0' }}"
        onclick="fillEditModal(this)">
    <i class="bi bi-pencil"></i>
</button>
```

- [ ] **Step 5 : Ajouter le modal Bootstrap d'édition et le script JS**

Juste avant la fermeture `</x-app-layout>`, ajouter :

```blade
{{-- Modal édition compte bancaire --}}
<div class="modal fade" id="editCompteModal" tabindex="-1" aria-labelledby="editCompteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editCompteForm" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title" id="editCompteModalLabel">Modifier le compte bancaire</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label for="edit_nom" class="form-label">Nom <span class="text-danger">*</span></label>
                        <input type="text" name="nom" id="edit_nom" class="form-control" required maxlength="150">
                    </div>
                    <div class="col-12">
                        <label for="edit_iban" class="form-label">IBAN</label>
                        <input type="text" name="iban" id="edit_iban" class="form-control" maxlength="34">
                    </div>
                    <div class="col-md-6">
                        <label for="edit_solde" class="form-label">Solde initial <span class="text-danger">*</span></label>
                        <input type="number" name="solde_initial" id="edit_solde" class="form-control" required step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label for="edit_date" class="form-label">Date solde <span class="text-danger">*</span></label>
                        <input type="date" name="date_solde_initial" id="edit_date" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="actif_recettes_depenses"
                                   id="edit_actif_rd" value="1">
                            <label class="form-check-label" for="edit_actif_rd">
                                Utilisable pour les recettes et dépenses
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="actif_dons_cotisations"
                                   id="edit_actif_dc" value="1">
                            <label class="form-check-label" for="edit_actif_dc">
                                Utilisable pour les dons et cotisations
                            </label>
                        </div>
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
function fillEditModal(btn) {
    const form = document.getElementById('editCompteForm');
    form.action = btn.dataset.updateUrl;
    document.getElementById('edit_nom').value = btn.dataset.nom;
    document.getElementById('edit_iban').value = btn.dataset.iban;
    document.getElementById('edit_solde').value = btn.dataset.solde;
    document.getElementById('edit_date').value = btn.dataset.date;
    document.getElementById('edit_actif_rd').checked = btn.dataset.actifRd === '1';
    document.getElementById('edit_actif_dc').checked = btn.dataset.actifDc === '1';
}
</script>
```

- [ ] **Step 6 : Vérifier manuellement dans le navigateur**

- Ouvrir http://localhost/parametres/comptes-bancaires
- Vérifier l'affichage du tableau avec les nouvelles colonnes (icônes ✓/✗)
- Cliquer "Modifier" → le modal s'ouvre avec les données pré-remplies (cases cochées/décochées correctement)
- Décocher une case, soumettre → vérifier que la valeur passe à `false` en base
- Vérifier que l'icône dans le tableau est mise à jour après rechargement

- [ ] **Step 7 : Commit**

```bash
git add resources/views/parametres/comptes-bancaires/index.blade.php
git commit -m "feat: edit modal and actif checkboxes for comptes bancaires"
```

---

## Chunk 3: Filtrage dans les formulaires Livewire

### Task 3: Filtrer les comptes dans les quatre formulaires Livewire

**Files:**
- Modify: `app/Livewire/RecetteForm.php`
- Modify: `app/Livewire/DepenseForm.php`
- Modify: `app/Livewire/DonForm.php`
- Modify: `app/Livewire/CotisationForm.php`

- [ ] **Step 1 : Mettre à jour `RecetteForm::render()`**

Dans `app/Livewire/RecetteForm.php`, remplacer :

```php
'comptes' => CompteBancaire::orderBy('nom')->get(),
```

par :

```php
'comptes' => CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get(),
```

- [ ] **Step 2 : Mettre à jour `DepenseForm::render()`**

Dans `app/Livewire/DepenseForm.php`, remplacer :

```php
'comptes' => CompteBancaire::orderBy('nom')->get(),
```

par :

```php
'comptes' => CompteBancaire::where('actif_recettes_depenses', true)->orderBy('nom')->get(),
```

- [ ] **Step 3 : Mettre à jour `DonForm::render()`**

Dans `app/Livewire/DonForm.php`, remplacer :

```php
'comptes' => CompteBancaire::orderBy('nom')->get(),
```

par :

```php
'comptes' => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
```

- [ ] **Step 4 : Mettre à jour `CotisationForm::render()`**

Dans `app/Livewire/CotisationForm.php`, remplacer :

```php
'comptes' => CompteBancaire::orderBy('nom')->get(),
```

par :

```php
'comptes' => CompteBancaire::where('actif_dons_cotisations', true)->orderBy('nom')->get(),
```

- [ ] **Step 5 : Lancer tous les tests**

```bash
./vendor/bin/sail artisan test
```

Résultat attendu : tous les tests PASS

- [ ] **Step 6 : Vérifier manuellement le filtrage**

- Marquer un compte bancaire comme inactif pour Rec./Dép. via le modal d'édition
- Ouvrir le formulaire de saisie d'une recette → ce compte ne doit plus apparaître dans la liste déroulante
- Idem pour une dépense
- Marquer un compte inactif pour Dons/Cot.
- Ouvrir le formulaire de saisie d'un don → ce compte ne doit plus apparaître

- [ ] **Step 7 : Commit**

```bash
git add app/Livewire/RecetteForm.php \
        app/Livewire/DepenseForm.php \
        app/Livewire/DonForm.php \
        app/Livewire/CotisationForm.php
git commit -m "feat: filter comptes bancaires by actif flag in Livewire forms"
```
