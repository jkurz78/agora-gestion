# Ergonomie — Flatpickr dates + Tab-to-select autocomplete

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer tous les `type="date"` de l'app par un composant Flatpickr avec saisie libre, et ajouter Tab-to-select sur TiersAutocomplete et SousCategorieAutocomplete.

**Architecture:** Composant Blade `<x-date-input>` avec Alpine.js (bundlé Livewire 4) + `wire:ignore` pour protéger l'instance Flatpickr des re-renders. Tab-to-select : 2 lignes Alpine dans les vues blade existantes.

**Tech Stack:** Laravel 11, Livewire 4, Alpine.js (bundlé), Flatpickr via CDN, Bootstrap 5 CDN.

---

## Chunk 1 : Flatpickr sur tous les champs date

### Task 1 : CDN Flatpickr + fonction de parsing dans app.blade.php

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

Ajouter dans le `<head>`, **avant** le script Livewire (Flatpickr doit être disponible avant qu'Alpine s'initialise) :

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
<script>
window.svsParseFlatpickrDate = function(str) {
    str = (str || '').trim();
    const y = new Date().getFullYear();
    function make(d, m, yr) {
        d = parseInt(d, 10); m = parseInt(m, 10) - 1; yr = parseInt(yr, 10);
        const dt = new Date(yr, m, d);
        return (!isNaN(dt) && dt.getDate()===d && dt.getMonth()===m && dt.getFullYear()===yr) ? dt : null;
    }
    if (/^\d{8}$/.test(str)) return make(str.slice(0,2), str.slice(2,4), str.slice(4,8));
    if (/^\d{4}$/.test(str))  return make(str.slice(0,2), str.slice(2,4), y);
    const p = str.split(/[\/\-\.]/);
    if (p.length === 2) return make(p[0], p[1], y);
    if (p.length === 3) return make(p[0], p[1], p[2]);
    return null;
};
</script>
```

- [ ] **Step 1 : Localiser la balise `<head>` dans `resources/views/layouts/app.blade.php`**

Ouvrir le fichier et repérer l'endroit où charger le CSS et JS Flatpickr — avant `@livewireScripts` ou avant la fermeture de `</head>`.

- [ ] **Step 2 : Ajouter le CDN Flatpickr et la fonction globale**

Insérer le bloc ci-dessus dans le `<head>`. Vérifier que `flatpickr` et `window.svsParseFlatpickrDate` sont bien déclarés avant le chargement d'Alpine/Livewire.

- [ ] **Step 3 : Vérifier manuellement dans le navigateur**

Ouvrir http://localhost, ouvrir la console JS (F12) :
- `typeof flatpickr` → doit retourner `"function"`, pas `"undefined"`
- `window.svsParseFlatpickrDate('31052025')` → doit retourner un objet `Date` avec `getDate()=31`, `getMonth()=4` (mai=4), `getFullYear()=2025`
- `window.svsParseFlatpickrDate('3105')` → doit retourner un objet `Date` avec `getDate()=31`, `getMonth()=4`, `getFullYear()` = année en cours

- [ ] **Step 4 : Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: charger Flatpickr CDN + fonction svsParseFlatpickrDate dans le layout"
```

---

### Task 2 : Composant Blade `<x-date-input>`

**Files:**
- Create: `resources/views/components/date-input.blade.php`

Le composant accepte les props `name`, `value`, `disabled`. Tous les autres attributs (notamment `wire:model`, `wire:model.live`) sont transmis via `$attributes` à l'input hidden.

Quand Flatpickr sélectionne une date, il dispatch `input` (pour `wire:model.live`) ET `change` (pour `wire:model` lazy) sur l'input hidden, couvrant tous les cas.

`wire:ignore` sur le conteneur protège l'instance Flatpickr des morphs Livewire.

```blade
@props([
    'id'       => null,
    'name'     => '',
    'value'    => '',
    'disabled' => false,
])

@if ($disabled)
    <input type="text"
           value="{{ $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '' }}"
           class="form-control bg-light" disabled>
@else
    <div class="input-group" wire:ignore
         @if($id) id="{{ $id }}" @endif
         x-data="{
             fp: null,
             init() {
                 const hidden = this.\$refs.hidden;
                 this.fp = flatpickr(this.\$refs.input, {
                     locale: 'fr',
                     dateFormat: 'd/m/Y',
                     allowInput: true,
                     disableMobile: true,
                     defaultDate: hidden.value || null,
                     parseDate(str) { return window.svsParseFlatpickrDate(str); },
                     onChange(dates) {
                         if (!dates.length) return;
                         const d = dates[0];
                         const iso = d.getFullYear() + '-'
                             + String(d.getMonth()+1).padStart(2,'0') + '-'
                             + String(d.getDate()).padStart(2,'0');
                         hidden.value = iso;
                         hidden.dispatchEvent(new Event('input'));
                         hidden.dispatchEvent(new Event('change'));
                     },
                 });
             },
             destroy() { if (this.fp) this.fp.destroy(); }
         }">
        <input type="text"
               x-ref="input"
               class="form-control"
               placeholder="jj/mm/aaaa"
               autocomplete="off">
        <span class="input-group-text" style="cursor:pointer"
              @click="fp && fp.toggle()">
            <i class="bi bi-calendar3"></i>
        </span>
        <input type="hidden"
               x-ref="hidden"
               name="{{ $name }}"
               value="{{ $value }}"
               {{ $attributes->filter(fn($v, $k) => str_starts_with($k, 'wire:')) }}>
    </div>
@endif
```

- [ ] **Step 1 : Créer `resources/views/components/date-input.blade.php`**

Copier exactement le code ci-dessus. Vérifier :
- Les `\$refs` sont bien échappés (`\$` dans une chaîne x-data)
- `$attributes->filter(...)` est du PHP Blade, pas du JS

- [ ] **Step 2 : Test rapide en local**

Dans n'importe quelle vue de test ou directement dans un formulaire existant, ajouter temporairement :
```blade
<x-date-input name="test" value="2025-05-31" />
```
Vérifier que le calendrier s'affiche avec la date 31/05/2025 pré-remplie, que la saisie libre `3105` donne 31/05/2026, que l'icône ouvre le calendrier en français.

- [ ] **Step 3 : Commit**

```bash
git add resources/views/components/date-input.blade.php
git commit -m "feat: composant Blade x-date-input avec Flatpickr et saisie libre"
```

---

### Task 3 : Vues Livewire — formulaires avec pattern verrouillé

**Files:**
- Modify: `resources/views/livewire/depense-form.blade.php`
- Modify: `resources/views/livewire/recette-form.blade.php`

Ces deux vues ont le même pattern (bloc `@if ($isLocked) ... @else ... @endif`) pour le champ date.

**Pattern à remplacer (depense-form et recette-form) :**

```blade
{{-- AVANT --}}
@if ($isLocked)
    <input type="text" value="{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}" class="form-control bg-light" disabled>
@else
    <input type="date" wire:model="date" id="date"
           class="form-control @error('date') is-invalid @enderror">
    @error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
@endif

{{-- APRÈS --}}
<x-date-input name="date" wire:model="date" :value="$date" :disabled="$isLocked" />
@error('date') <div class="invalid-feedback">{{ $message }}</div> @enderror
```

- [ ] **Step 1 : Remplacer dans `depense-form.blade.php`**

Chercher le bloc `@if ($isLocked)` autour de `type="date"` (ligne ~31-37). Remplacer par le pattern ci-dessus.

- [ ] **Step 2 : Remplacer dans `recette-form.blade.php`**

Même remplacement (structure identique).

- [ ] **Step 3 : Tester manuellement les deux formulaires**

- Ouvrir une dépense non verrouillée → le champ date doit afficher le calendrier Flatpickr
- Ouvrir une dépense verrouillée → le champ date doit être en lecture seule (texte grisé, format jj/mm/aaaa)
- Modifier la date d'une dépense non verrouillée → la valeur doit bien être sauvegardée

- [ ] **Step 4 : Commit**

```bash
git add resources/views/livewire/depense-form.blade.php resources/views/livewire/recette-form.blade.php
git commit -m "feat: Flatpickr sur champ date depense-form et recette-form"
```

---

### Task 4 : Vues Livewire — formulaires simples et filtres

**Files:**
- Modify: `resources/views/livewire/cotisation-form.blade.php`
- Modify: `resources/views/livewire/don-form.blade.php`
- Modify: `resources/views/livewire/virement-interne-form.blade.php`
- Modify: `resources/views/livewire/rapprochement-list.blade.php`
- Modify: `resources/views/livewire/tiers-transactions.blade.php`
- Modify: `resources/views/livewire/transaction-compte-list.blade.php`

**Pattern A — Formulaire simple (cotisation-form, don-form, virement-interne-form) :**

```blade
{{-- AVANT (exemple cotisation-form) --}}
<input type="date" wire:model="date_paiement" id="date_paiement"
       class="form-control @error('date_paiement') is-invalid @enderror">
@error('date_paiement') <div class="invalid-feedback">{{ $message }}</div> @enderror

{{-- APRÈS --}}
<x-date-input name="date_paiement" wire:model="date_paiement" :value="$date_paiement" />
@error('date_paiement') <div class="invalid-feedback">{{ $message }}</div> @enderror
```

Adapter le nom de la propriété Livewire selon chaque formulaire :
- `cotisation-form` : `date_paiement`
- `don-form` : `date`
- `virement-interne-form` : `date`

**Pattern B — Filtre live (tiers-transactions, transaction-compte-list, rapprochement-list) :**

```blade
{{-- AVANT (exemple tiers-transactions) --}}
<input type="date" wire:model.live="dateDebut" class="form-control form-control-sm">

{{-- APRÈS --}}
<x-date-input name="date_debut" wire:model.live="dateDebut" :value="$dateDebut" />
```

Pour `rapprochement-list`, le champ utilise `wire:model` (pas live) :
```blade
{{-- AVANT --}}
<input type="date" wire:model="date_fin"
       class="form-control @error('date_fin') is-invalid @enderror">
@error('date_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror

{{-- APRÈS --}}
<x-date-input name="date_fin" wire:model="date_fin" :value="$date_fin" />
@error('date_fin') <div class="invalid-feedback">{{ $message }}</div> @enderror
```

Pour `transaction-compte-list`, deux champs live (`dateDebut` et `dateFin`).
Pour `tiers-transactions`, deux champs live (`dateDebut` et `dateFin`).

**Important :** Vérifier dans chaque composant Livewire PHP que la propriété exposée correspond bien au nom passé à `:value`. Par exemple `$this->dateDebut` dans `TiersTransactions.php` doit être accessible comme `$dateDebut` dans la vue.

- [ ] **Step 1 : Remplacer dans cotisation-form, don-form, virement-interne-form**

Un remplacement par fichier, selon Pattern A.

- [ ] **Step 2 : Remplacer dans rapprochement-list**

Pattern B sans `.live`.

- [ ] **Step 3 : Remplacer dans tiers-transactions**

Deux champs, Pattern B avec `.live`.

- [ ] **Step 4 : Remplacer dans transaction-compte-list**

Deux champs, Pattern B avec `.live`.

- [ ] **Step 5 : Tester manuellement**

- Créer un don → saisir une date → vérifier sauvegarde
- Ouvrir la liste tiers-transactions → changer le filtre date → vérifier que la liste se filtre en temps réel
- Ouvrir transaction-compte-list → idem

- [ ] **Step 6 : Lancer les tests**

```bash
./vendor/bin/sail pest
```
Expected: tous les tests passent (pas de régression).

- [ ] **Step 7 : Commit**

```bash
git add resources/views/livewire/cotisation-form.blade.php \
        resources/views/livewire/don-form.blade.php \
        resources/views/livewire/virement-interne-form.blade.php \
        resources/views/livewire/rapprochement-list.blade.php \
        resources/views/livewire/tiers-transactions.blade.php \
        resources/views/livewire/transaction-compte-list.blade.php
git commit -m "feat: Flatpickr sur champs date — formulaires simples et filtres Livewire"
```

---

### Task 5 : Vues non-Livewire (formulaires POST classiques)

**Files:**
- Modify: `resources/views/operations/create.blade.php`
- Modify: `resources/views/operations/edit.blade.php`
- Modify: `resources/views/parametres/comptes-bancaires/index.blade.php`

Ces vues utilisent des formulaires POST classiques (pas de `wire:model`). Le composant fonctionne sans `wire:model` : l'input hidden a `name="{{ $name }}"` et la valeur ISO est soumise avec le formulaire.

**Pattern — operations/create.blade.php :**

```blade
{{-- AVANT --}}
<input type="date" name="date_debut" id="date_debut"
       class="form-control"
       value="{{ old('date_debut') }}">

{{-- APRÈS --}}
<x-date-input name="date_debut" :value="old('date_debut', '')" />
```

```blade
{{-- AVANT --}}
<input type="date" name="date_fin" id="date_fin"
       class="form-control"
       value="{{ old('date_fin') }}">

{{-- APRÈS --}}
<x-date-input name="date_fin" :value="old('date_fin', '')" />
```

**Pattern — operations/edit.blade.php :**

```blade
{{-- AVANT --}}
<input type="date" name="date_debut" id="date_debut"
       class="form-control"
       value="{{ old('date_debut', $operation->date_debut?->format('Y-m-d')) }}">

{{-- APRÈS --}}
<x-date-input name="date_debut" :value="old('date_debut', $operation->date_debut?->format('Y-m-d') ?? '')" />
```

```blade
{{-- date_fin — même pattern --}}
<x-date-input name="date_fin" :value="old('date_fin', $operation->date_fin?->format('Y-m-d') ?? '')" />
```

**Pattern — comptes-bancaires/index.blade.php, create modal (ligne ~42) :**

```blade
{{-- AVANT --}}
<input type="date" name="date_solde_initial" id="cb_date" class="form-control" required>

{{-- APRÈS --}}
<x-date-input name="date_solde_initial" value="" />
```

**Pattern — comptes-bancaires/index.blade.php, edit modal (ligne ~154) :**

Le script JS `fillEditModal()` (ligne ~189) fait actuellement :
```javascript
document.getElementById('edit_date').value = btn.dataset.date;
```

Avec le composant, `id="edit_date"` est sur le wrapper `<div>`, donc l'input text est accessible via le sélecteur enfant. Flatpickr stocke son instance sur l'élément via `._flatpickr`.

```blade
{{-- AVANT --}}
<input type="date" name="date_solde_initial" id="edit_date" class="form-control" required>

{{-- APRÈS --}}
<x-date-input name="date_solde_initial" id="edit_date" value="" />
```

Mettre à jour la fonction `fillEditModal()` dans le `<script>` de la vue :

```javascript
{{-- AVANT (ligne ~189) --}}
document.getElementById('edit_date').value = btn.dataset.date;

{{-- APRÈS --}}
const editDateWrapper = document.getElementById('edit_date');
const editDateInput = editDateWrapper ? editDateWrapper.querySelector('input[type=text]') : null;
if (editDateInput && editDateInput._flatpickr) {
    editDateInput._flatpickr.setDate(btn.dataset.date, true, 'Y-m-d');
}
```

Note : `btn.dataset.date` doit être au format `Y-m-d` (ex: `2025-12-31`). Vérifier l'attribut `data-date` sur les boutons d'édition pour confirmer le format.

- [ ] **Step 1 : Remplacer dans operations/create.blade.php**

2 champs : `date_debut` et `date_fin`.

- [ ] **Step 2 : Remplacer dans operations/edit.blade.php**

2 champs : `date_debut` et `date_fin` avec valeurs préremplies depuis `$operation`.

- [ ] **Step 3 : Vérifier le modal edit de comptes-bancaires**

Lire les lignes autour de `id="edit_date"` dans `parametres/comptes-bancaires/index.blade.php` pour voir si la valeur est injectée via JS ou via Blade. Adapter le remplacement en conséquence.

- [ ] **Step 4 : Remplacer dans comptes-bancaires/index.blade.php**

2 champs (create et edit modal).

- [ ] **Step 5 : Tester manuellement**

- Créer une opération → saisir date_debut et date_fin → vérifier sauvegarde
- Éditer une opération → vérifier que les dates sont préremplies dans Flatpickr

- [ ] **Step 6 : Lancer les tests**

```bash
./vendor/bin/sail pest
```
Expected: tous les tests passent.

- [ ] **Step 7 : Commit**

```bash
git add resources/views/operations/create.blade.php \
        resources/views/operations/edit.blade.php \
        resources/views/parametres/comptes-bancaires/index.blade.php
git commit -m "feat: Flatpickr sur champs date — vues non-Livewire (operations, comptes)"
```

---

## Chunk 2 : Tab-to-select sur TiersAutocomplete et SousCategorieAutocomplete

### Task 6 : Tab-to-select dans TiersAutocomplete

**Files:**
- Modify: `resources/views/livewire/tiers-autocomplete.blade.php`

Le fichier utilise déjà `x-on:keydown.enter.prevent` (ligne ~30) qui sélectionne l'item surligné via `document.querySelectorAll('[data-nav-item]')`. Le Tab doit faire la même chose mais **sans** `prevent` pour laisser le focus passer au champ suivant.

**Localiser le bloc keyboard handler** (lignes ~18-37) :

```blade
x-on:keydown.down.prevent="..."
x-on:keydown.up.prevent="..."
x-on:keydown.enter.prevent="
    let items = $root.querySelectorAll('[data-nav-item]');
    if (highlighted >= 0 && items[highlighted]) {
        items[highlighted].click();
        highlighted = -1;
    }
"
x-on:keydown.escape="$wire.set('open', false); highlighted = -1"
```

**Ajouter après `keydown.escape` :**

```blade
x-on:keydown.tab="
    let items = document.querySelectorAll('[data-nav-item]');
    if (highlighted >= 0 && items[highlighted]) {
        items[highlighted].click();
        highlighted = -1;
    }
"
```

Note : pas de `.prevent` → Tab laisse le navigateur passer au champ suivant après l'exécution du handler.

- [ ] **Step 1 : Lire `tiers-autocomplete.blade.php` pour repérer l'emplacement exact**

Localiser `x-on:keydown.escape` — le handler Tab vient juste après.

- [ ] **Step 2 : Ajouter le handler `keydown.tab`**

Insérer le bloc ci-dessus après `x-on:keydown.escape`.

- [ ] **Step 3 : Tester manuellement**

Ouvrir le formulaire de dépense → taper dans le champ tiers → attendre le dropdown → flèche bas pour surligner un item → Tab → l'item doit être sélectionné ET le focus doit passer au champ suivant.

- [ ] **Step 4 : Tester le cas sans item surligné**

Ouvrir le dropdown → Tab sans flèche → le dropdown doit se fermer, focus passe normalement, aucun item sélectionné.

- [ ] **Step 5 : Commit**

```bash
git add resources/views/livewire/tiers-autocomplete.blade.php
git commit -m "feat: Tab-to-select dans TiersAutocomplete"
```

---

### Task 7 : Tab-to-select dans SousCategorieAutocomplete

**Files:**
- Modify: `resources/views/livewire/sous-categorie-autocomplete.blade.php`

Même principe. Le sélecteur est `[data-sc-nav-{{ $this->getId() }}]` (utilisé par les handlers ArrowDown/ArrowUp/Enter existants, lignes ~34-50).

**Localiser le bloc keyboard handler** (lignes ~25-52) :

```blade
x-on:keydown.down.prevent="
    let items = document.querySelectorAll('[data-sc-nav-{{ $this->getId() }}]');
    ...
"
x-on:keydown.up.prevent="..."
x-on:keydown.enter.prevent="
    let items = document.querySelectorAll('[data-sc-nav-{{ $this->getId() }}]');
    if (highlighted >= 0 && items[highlighted]) {
        items[highlighted].click();
        highlighted = -1;
    }
"
x-on:keydown.escape="$wire.set('open', false); highlighted = -1"
```

**Ajouter après `keydown.escape` :**

```blade
x-on:keydown.tab="
    let items = document.querySelectorAll('[data-sc-nav-{{ $this->getId() }}]');
    if (highlighted >= 0 && items[highlighted]) {
        items[highlighted].click();
        highlighted = -1;
    }
"
```

- [ ] **Step 1 : Lire `sous-categorie-autocomplete.blade.php` pour repérer l'emplacement exact**

Localiser `x-on:keydown.escape` — le handler Tab vient juste après.

- [ ] **Step 2 : Ajouter le handler `keydown.tab`**

Insérer le bloc ci-dessus.

- [ ] **Step 3 : Tester manuellement**

Ouvrir le formulaire de dépense → taper dans le champ sous-catégorie → dropdown → flèche bas → surligner un item → Tab → item sélectionné, focus passe au champ suivant.

- [ ] **Step 4 : Lancer les tests finaux**

```bash
./vendor/bin/sail pest
```
Expected: tous les tests passent.

- [ ] **Step 5 : Commit**

```bash
git add resources/views/livewire/sous-categorie-autocomplete.blade.php
git commit -m "feat: Tab-to-select dans SousCategorieAutocomplete"
```

---

## Vérification finale

Après les 7 tâches :

```bash
./vendor/bin/sail pint
./vendor/bin/sail pest
```

Vérifier manuellement :
- [ ] Saisie `31052025` → 31/05/2025 sur tous les formulaires testés
- [ ] Saisie `3105` → 31/05/2026 (année en cours)
- [ ] Calendrier popup en français sur tous les formulaires
- [ ] Champs verrouillés (dépenses/recettes) → lecture seule, pas de Flatpickr
- [ ] Tab-to-select fonctionne sur TiersAutocomplete et SousCategorieAutocomplete
