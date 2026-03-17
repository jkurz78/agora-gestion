# Ergonomie — Saisie de dates et Tab-to-select autocomplete

## Objectif

Deux améliorations ergonomiques transversales :

1. **Flatpickr sur tous les champs date** : permettre la saisie libre (`31052025`, `31/05`, etc.) avec complétion de l'année en cours, et un calendrier popup en français en option.
2. **Tab-to-select sur TiersAutocomplete et SousCategorieAutocomplete** : valider l'item surligné dans le dropdown quand l'utilisateur appuie sur Tab pour passer au champ suivant.

---

## Évolution 1 — Composant Blade `<x-date-input>`

### Comportement attendu

| Saisie | Résultat affiché | Valeur soumise (hidden/wire:model) |
|--------|-----------------|-----------------------------------|
| `31052025` | 31/05/2025 | `2025-05-31` |
| `31/05/2025` | 31/05/2025 | `2025-05-31` |
| `3105` | 31/05/2026 | `2026-05-31` |
| `31/05` | 31/05/2026 | `2026-05-31` |
| Clic icône | Calendrier popup FR | (après sélection) |

### Architecture

**Fichiers créés/modifiés :**

- Créer : `resources/views/components/date-input.blade.php`
- Modifier : `resources/views/layouts/app.blade.php` — ajout CDN Flatpickr (CSS + JS, **sans defer**)
- Modifier : ~20 occurrences de `type="date"` dans les vues listées ci-dessous

**Note** : Alpine.js est bundlé par Livewire 4 — aucun CDN supplémentaire n'est nécessaire. Flatpickr est chargé séparément via CDN car ce n'est pas une dépendance de Livewire.

### Composant Blade

Le composant utilise Alpine.js (déjà fourni par Livewire 4) pour initialiser Flatpickr proprement et survivre aux re-renders Livewire.

```blade
@props([
    'name'       => '',
    'value'      => '',
    'disabled'   => false,
    'wireModel'  => null,
])

@if ($disabled)
    <input type="text"
           value="{{ $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '' }}"
           class="form-control bg-light" disabled>
@else
    {{-- wire:ignore empêche Livewire de morpher le conteneur et de détruire l'instance Flatpickr --}}
    <div class="input-group" wire:ignore
         x-data="{
             fp: null,
             init() {
                 const hidden = this.$refs.hidden;
                 this.fp = flatpickr(this.$refs.input, {
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
                     },
                 });
             },
             destroy() { if (this.fp) this.fp.destroy(); }
         }">
        <input type="text"
               x-ref="input"
               class="form-control {{ $attributes->get('class') }}"
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
               @if($wireModel) wire:model="{{ $wireModel }}" @endif>
    </div>
@endif
```

### Fonction de parsing partagée

Déclarer `window.svsParseFlatpickrDate` dans `app.blade.php` (ou un fichier JS dédié) :

```javascript
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
```

### CDN à ajouter dans `layouts/app.blade.php` (dans `<head>`, sans defer)

```html
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
```

**Pourquoi sans `defer`** : Alpine.js s'initialise au `DOMContentLoaded`. Les scripts sans defer sont disponibles à ce moment, garantissant que `flatpickr` est défini quand `x-init` s'exécute.

### Utilisation dans les vues

**Vues Livewire** (avec `wire:model`) :
```blade
{{-- Avant --}}
<input type="date" wire:model="date" class="form-control">

{{-- Après --}}
<x-date-input name="date" wire:model="date" :value="$date" />
```

**Vues Livewire verrouillées** :
```blade
<x-date-input name="date" wire:model="date" :value="$date" :disabled="$isLocked" />
```

**Vues non-Livewire** (formulaires POST classiques) :
```blade
{{-- Avant --}}
<input type="date" name="date_debut" class="form-control" value="{{ old('date_debut', $operation->date_debut) }}">

{{-- Après --}}
<x-date-input name="date_debut" :value="old('date_debut', $operation->date_debut ?? '')" />
```

### Vues à modifier — périmètre complet

**Vues Livewire (wire:model) :**
- `resources/views/livewire/depense-form.blade.php`
- `resources/views/livewire/recette-form.blade.php`
- `resources/views/livewire/cotisation-form.blade.php`
- `resources/views/livewire/don-form.blade.php`
- `resources/views/livewire/virement-interne-form.blade.php`
- `resources/views/livewire/rapprochement-list.blade.php`
- `resources/views/livewire/tiers-transactions.blade.php`
- `resources/views/livewire/transaction-compte-list.blade.php`

**Vues non-Livewire (formulaires POST) :**
- `resources/views/operations/create.blade.php` (2 dates : date_debut, date_fin)
- `resources/views/operations/edit.blade.php` (2 dates : date_debut, date_fin)
- `resources/views/parametres/comptes-bancaires/index.blade.php` (2 dates : create et edit modal)

---

## Évolution 2 — Tab-to-select sur TiersAutocomplete et SousCategorieAutocomplete

### Comportement attendu

| Situation | Avant | Après |
|-----------|-------|-------|
| Dropdown ouvert, item surligné (`highlighted >= 0`), appui Tab | Item non validé, focus passe au champ suivant | Item validé **puis** focus passe |
| Dropdown ouvert, aucun item surligné (`highlighted === -1`), Tab | Dropdown se ferme | Inchangé |
| Dropdown fermé, Tab | Focus passe normalement | Inchangé |

### Architecture

La navigation clavier est entièrement **côté client Alpine.js** dans les vues blade. La propriété `highlighted` est dans le `x-data` local du composant. Aucune modification PHP nécessaire.

**Dans `resources/views/livewire/tiers-autocomplete.blade.php`**, sur l'input principal, ajouter un handler `keydown.tab` à la suite des handlers existants (même motif que le handler Enter existant qui utilise `document.querySelectorAll('[data-nav-item]')`) :

```blade
x-on:keydown.tab="
    let items = document.querySelectorAll('[data-nav-item]');
    if (highlighted >= 0 && items[highlighted]) {
        items[highlighted].click();
        highlighted = -1;
    }
"
```

**Dans `resources/views/livewire/sous-categorie-autocomplete.blade.php`**, même ajout — le sélecteur est différent (même motif que les handlers ArrowDown/ArrowUp/Enter existants) :

```blade
x-on:keydown.tab="
    let items = document.querySelectorAll('[data-sc-nav-{{ $this->getId() }}]');
    if (highlighted >= 0 && items[highlighted]) {
        items[highlighted].click();
        highlighted = -1;
    }
"
```

**Note importante** : `keydown.tab` ne doit **pas** appeler `preventDefault()` — on laisse le navigateur gérer le focus vers le champ suivant après que le clic ait été dispatché.

### Fichiers à modifier

- `resources/views/livewire/tiers-autocomplete.blade.php` — ajout `x-on:keydown.tab` sur l'input
- `resources/views/livewire/sous-categorie-autocomplete.blade.php` — idem

Aucun fichier PHP à modifier.

---

## Tests

### Évolution 1 (Flatpickr)

Pas de tests PHP automatisés — c'est du rendu Blade + JS client. Vérification manuelle :
- Saisie `31052025`, `3105`, `31/05`, `31/05/2025` dans chacune des 11 vues
- Vérification que `wire:model` reçoit bien la valeur ISO (`Y-m-d`)
- Vérification que le calendrier s'ouvre et est en français
- Vérification que les champs `disabled` affichent le format `dd/mm/yyyy` sans Flatpickr

### Évolution 2 (Tab-to-select)

Tests Livewire existants à compléter dans les fichiers de test correspondants :
- Tab avec `highlighted >= 0` → item sélectionné + `tiersId` mis à jour
- Tab avec `highlighted === -1` → aucune sélection, pas d'erreur

---

## Périmètre et exclusions

- Le select d'opération (`<select>` natif) reste tel quel — le select natif valide la sélection au Tab correctement.
- SousCategorieAutocomplete reçoit le même traitement Tab-to-select que TiersAutocomplete (structure identique).
- Aucun changement à la logique de validation Livewire côté serveur.
