# Harmonisation des écrans de liste — Lots A et B

## Contexte

Après analyse des 12 écrans de liste de l'application, deux catégories d'améliorations indépendantes ont été identifiées :

- **Lot A** : harmonisation visuelle pure (uniquement des templates Blade, sauf A5 qui retire une colonne)
- **Lot B** : ajout de liens de navigation inter-écrans (Blade + vérification eager loading)

---

## Lot A — Harmonisation visuelle

### A1 · TiersList : en-tête dark

**Problème :** `TiersList` est le seul écran avec un en-tête de tableau clair. Tous les autres utilisent `table-dark` avec le style maison.

**Correction :** Sur le `<thead>` de `tiers-list.blade.php`, remplacer la classe d'en-tête actuelle par :

```blade
<thead>
  <tr class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
```

**Fichier :** `resources/views/livewire/tiers-list.blade.php`

---

### A2 · TiersList : colonnes Dépenses / Recettes — icônes au lieu de badges texte

**Problème :** Les colonnes "Depenses" et "Recettes" affichent un badge "Oui" uniquement quand vrai, rien sinon — incohérent avec le traitement de Pointé sur les autres écrans.

**Correction :** Conserver les deux colonnes séparées, raccourcir les en-têtes en `Dép.` et `Rec.`, remplacer le badge "Oui" par `bi-check-lg text-success` et afficher `—` quand faux — même pattern que la colonne Pointé :

```blade
{{-- En-têtes --}}
<th>Dép.</th>
<th>Rec.</th>

{{-- Colonne Dépenses --}}
<td>
  @if($tiers->pour_depenses)
    <i class="bi bi-check-lg text-success"></i>
  @else
    <span class="text-muted">—</span>
  @endif
</td>

{{-- Colonne Recettes --}}
<td>
  @if($tiers->pour_recettes)
    <i class="bi bi-check-lg text-success"></i>
  @else
    <span class="text-muted">—</span>
  @endif
</td>
```

**Fichier :** `resources/views/livewire/tiers-list.blade.php`

---

### A3 · Colonne Pointé : icône uniforme

**Problème :** La colonne Pointé est affichée différemment selon les écrans.

| Écran | Affichage actuel |
|---|---|
| `don-list` | Badge texte "Oui" / "Non" |
| `cotisation-list` | Badge texte |
| `membre-list` | Caractère `✓` / `—` (déjà proche de la cible) |

**Correction :** Uniformiser sur les trois écrans :

```blade
@if($item->pointe)
  <i class="bi bi-check-lg text-success"></i>
@else
  <span class="text-muted">—</span>
@endif
```

> Pour `membre-list.blade.php` : remplacer le caractère Unicode `✓` existant par `<i class="bi bi-check-lg text-success"></i>`.

**Fichiers :** `don-list.blade.php`, `cotisation-list.blade.php`, `membre-list.blade.php`

> Ne pas toucher : `transaction-compte-list` et `rapprochement-detail` ont déjà l'affichage correct.

---

### A4 · CotisationList : suppression de la colonne Exercice

**Problème :** La colonne "Exercice" affiche toujours l'exercice courant — redondante avec le contexte de la page.

**Correction :** Supprimer dans `cotisation-list.blade.php` :
- Le `<th>Exercice</th>` (ou équivalent) dans l'en-tête
- La `<td>` correspondante dans chaque ligne

**Fichier :** `resources/views/livewire/cotisation-list.blade.php`

---

### A5 · Notes en tooltip : `bi-sticky`

**Problème :** Les notes sont saisies mais jamais affichées dans les listes. La colonne Notes de `VirementInterneList` est souvent vide.

**Périmètre de ce lot :** uniquement les deux vues basées sur Eloquent direct. Les vues `transaction-compte-list` et `tiers-transactions` utilisent des UNION SQL bruts (`selectRaw`) qui ne sélectionnent pas `notes` — les modifier sort du périmètre Blade-only de ce lot.

**Correction :** Afficher `bi-sticky` avec tooltip si `!empty($item->notes)`, accolé au libellé.

```blade
{{ $item->libelle ?? $item->reference }}
@if(!empty($item->notes))
  <i class="bi bi-sticky text-muted ms-1" title="{{ $item->notes }}"></i>
@endif
```

**Fichiers et champs :**

| Fichier | Champ | Accolé à | Action supplémentaire |
|---|---|---|---|
| `virement-interne-list.blade.php` | `$virement->notes` | Colonne Référence | Supprimer la colonne Notes existante |
| `transaction-list.blade.php` | `$transaction->notes` | Colonne Libellé | Vérifier eager loading (voir ci-dessous) |

**Eager loading pour `transaction-list` :** Dans `app/Livewire/TransactionList.php`, vérifier que la requête inclut `notes` dans le select. Le modèle `Transaction` a `notes` dans `$fillable` — sauf sélection partielle explicite, le champ est chargé automatiquement.

---

### A6 · Boutons d'action : taille uniforme `btn-sm`

**Problème :** Certains écrans n'utilisent pas `btn-sm`. Les espacements entre boutons varient.

**Correction :** Sur tous les tableaux listés ci-dessous, les boutons d'action suivent ce pattern :

```blade
<div class="d-flex gap-1">
  <button class="btn btn-sm btn-outline-secondary" title="Modifier">
    <i class="bi bi-pencil"></i>
  </button>
  <button class="btn btn-sm btn-outline-danger" title="Supprimer">
    <i class="bi bi-trash"></i>
  </button>
</div>
```

**Fichiers à auditer et corriger :**
- `resources/views/livewire/don-list.blade.php`
- `resources/views/livewire/cotisation-list.blade.php`
- `resources/views/livewire/virement-interne-list.blade.php`
- `resources/views/livewire/membre-list.blade.php`

> `tiers-list`, `transaction-list` et `transaction-compte-list` ont déjà `btn-sm` — ne pas toucher.

---

## Lot B — Navigation inter-écrans

### B1 · MembreList : lien vers les transactions du membre

**Problème :** Depuis la liste des membres, aucun accès à l'historique des transactions.

**Contexte technique :** Dans `MembreList.php`, la requête porte sur le modèle `Tiers` directement (pas sur un modèle `Membre`). La variable dans la vue est donc un objet `Tiers` — son identifiant est `$membre->id`.

**Correction :** Ajouter un bouton dans la colonne Actions :

```blade
<a href="{{ route('tiers.transactions', $membre->id) }}"
   class="btn btn-sm btn-outline-secondary"
   title="Voir les transactions">
  <i class="bi bi-clock-history"></i>
</a>
```

**Eager loading :** Aucun prérequis supplémentaire — `$membre->id` est toujours présent.

**Fichier :** `resources/views/livewire/membre-list.blade.php`

---

### B2 · CotisationList : nom du membre cliquable

**Problème :** Le nom du membre dans `CotisationList` est du texte brut, sans lien vers ses transactions.

**Contexte technique :** Le modèle `Cotisation` a une relation `tiers()` (pas `membre()`). L'objet retourné est un `Tiers` dont l'id est `$cotisation->tiers->id`.

**Correction :** Rendre le nom cliquable avec guard null-safe :

```blade
@if($cotisation->tiers)
  <a href="{{ route('tiers.transactions', $cotisation->tiers->id) }}"
     class="text-decoration-none text-reset">
    {{ $cotisation->tiers->displayName() }}
  </a>
@else
  <span class="text-muted">—</span>
@endif
```

**Eager loading :** Dans `app/Livewire/CotisationList.php`, vérifier que la relation `tiers` est chargée en eager loading (`with('tiers')`). Si la requête actuelle utilise déjà `with('tiers')`, rien à modifier côté PHP.

**Fichiers :** `resources/views/livewire/cotisation-list.blade.php`, éventuellement `app/Livewire/CotisationList.php`

---

## Fichiers non touchés

- `transaction-list.blade.php` — seule l'icône notes (A5) est ajoutée
- `transaction-compte-list.blade.php` — hors périmètre (UNION SQL, notes non disponibles)
- `tiers-transactions.blade.php` — hors périmètre (UNION SQL, notes non disponibles)
- `rapprochement-list.blade.php` / `rapprochement-detail.blade.php` — non concernés
- `budget-table.blade.php` — non concerné

---

## Tests

**Lot A**
- TiersList : en-tête a la classe `table-dark` et le style bleu
- TiersList : colonne Dépenses affiche `bi-check-lg` si `pour_depenses`, `—` sinon ; idem pour Recettes
- DonList, CotisationList : Pointé affiche `bi-check-lg` si pointé, `—` sinon
- MembreList : Pointé affiche `bi-check-lg` (Bootstrap Icon, pas le caractère Unicode)
- CotisationList : aucune colonne Exercice dans le tableau
- VirementList : aucune colonne Notes ; icône `bi-sticky` visible si notes non vides, absent sinon
- TransactionList : icône `bi-sticky` visible sur une transaction avec notes, absent sans notes ; vérifier avec notes = `null` ET notes = `""` (les deux doivent être silencieux)
- Tous les boutons d'action des 5 fichiers A6 ont la classe `btn-sm`

**Lot B**
- MembreList : bouton `bi-clock-history` présent sur chaque ligne ; lien pointe vers la page transactions du tiers avec le bon ID
- CotisationList : nom du membre est un lien `<a>` pointant vers la page transactions du tiers avec le bon ID
- CotisationList : ligne avec `tiers = null` affiche `—` sans erreur
