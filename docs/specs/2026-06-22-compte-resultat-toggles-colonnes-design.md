# Compte de résultat — toggles colonnes « N‑1 » et « budget » (écran + exports)

**Date** : 2026-06-22
**Branche** : `main` (V4, préparation de clôture)
**Statut** : design validé, prêt pour plan d'implémentation.

## Problème

Le rapport **Compte de résultat** affiche toujours toutes les colonnes de comparaison :
`Label · N‑1 · N · Budget · Écart · Barre`. Pour produire un document de clôture / AG, on veut pouvoir **masquer** la comparaison **N‑1** et/ou la comparaison **budget** — surtout dans les **exports XLSX et PDF**.

## Solution : deux toggles, réutilisant le pattern de l'écran « CR par opérations »

Le composant `RapportCompteResultatOperations` utilise déjà des toggles `#[Url]` propagés à l'export. On **réutilise ce pattern** à l'identique (pas de nouvelle mécanique).

### 1. État — `App\Livewire\RapportCompteResultat`

Ajouter deux propriétés (calquées sur `RapportCompteResultatOperations` L16‑29) :

```php
use Livewire\Attributes\Url;

#[Url(as: 'n1')]
public bool $compareN1 = true;      // afficher la colonne N‑1

#[Url(as: 'budget')]
public bool $compareBudget = true;  // afficher Budget + Écart + Barre
```

Défauts **ON** → comportement actuel strictement inchangé tant qu'on ne touche pas aux toggles. État dans l'URL : survit au reload, partageable, propagé naturellement à l'export.

`render()` passe `compareN1` et `compareBudget` à la vue.

### 2. UI — en-tête du blade `livewire/rapport-compte-resultat.blade.php`

Deux `form-switch` Bootstrap à côté du bouton **Exporter**, identiques au switch « Opérations en colonnes » de CR‑OP (blade ops L104‑106) :

```html
<div class="form-check form-switch mb-0">
    <input type="checkbox" wire:model.live="compareN1" class="form-check-input" id="toggleN1">
    <label class="form-check-label small" for="toggleN1">Afficher N‑1</label>
</div>
<div class="form-check form-switch mb-0">
    <input type="checkbox" wire:model.live="compareBudget" class="form-check-input" id="toggleBudget">
    <label class="form-check-label small" for="toggleBudget">Afficher budget &amp; écart</label>
</div>
```

(Libellés ajustables — pas de jargon.)

### 3. Écran — masquage conditionnel des colonnes

Dans `livewire/rapport-compte-resultat.blade.php`, envelopper chaque cellule/colonne concernée :

- **`@if($compareN1)`** → colonne **N‑1** (`cr-n1`) : en‑tête, lignes catégorie (L145) et sous‑catégorie (L155), lignes de totaux, et le **bloc provisions / résultat N‑1** (les colonnes `*_n1` du `render()`).
- **`@if($compareBudget)`** → groupe **Budget (L147/157) · Écart (L148/158) · Barre (L149/159)** : en‑tête + toutes les lignes + totaux.

Le nombre de colonnes de l'en‑tête doit suivre (cohérence du `<thead>` / `colspan` éventuels).

### 4. Exports — le point clé

**`exportUrl()`** (composant) ajoute les deux params, comme CR‑OP (ops L40‑45) :

```php
'n1' => $this->compareN1 ? '1' : '0',
'budget' => $this->compareBudget ? '1' : '0',
```

**`App\Http\Controllers\RapportExportController`** lit les params **avec défaut `true`** (les exports hors‑écran restent complets) et construit les colonnes en conséquence :

- **XLSX** — `xlsxCompteResultat()` (L120) : l'en‑tête est aujourd'hui figé
  `['Type','Catégorie','Sous-catégorie', $labelN1, $label, 'Budget','Écart']` (L140). Le rendre **conditionnel** (retirer `$labelN1` si `!n1` ; retirer `Budget`+`Écart` si `!budget`) et aligner les cellules de données + totaux (L147‑180).
- **PDF** — `exportPdf()` (L1085) → `pdfCompteResultatData()` (L1133) → vue `resources/views/pdf/rapport-compte-resultat.blade.php`. Lire les params, les passer à la vue, et y appliquer le **même masquage conditionnel** que l'écran.

Lecture des params : `$request->boolean('n1', true)` / `$request->boolean('budget', true)`.

### 5. Périmètre

- **Compte de résultat principal uniquement.** La variante « par opérations » a déjà ses propres toggles (axe prévisionnel/réalisé) et n'est pas concernée.
- Masquer le budget retire **aussi** Écart + Barre (sans budget ils n'ont pas de sens). Les deux toggles sont **indépendants**.

## Tests

**Livewire** (`tests/Livewire/RapportCompteResultatTest.php`) :
- `set('compareN1', false)` → `assertDontSee` le label de l'en‑tête N‑1 (et un montant N‑1 connu) ; ON par défaut → `assertSee`.
- `set('compareBudget', false)` → `assertDontSee` « Budget »/« Écart » et la barre (`assertDontSeeHtml('budget-bar-fill')`).
- `exportUrl('xlsx')` contient `n1=…` et `budget=…` cohérents avec l'état.

**Export** (feature, route `rapports.export`) :
- `?format=xlsx&n1=0` → l'en‑tête XLSX **n'a pas** la colonne N‑1.
- `?format=xlsx&budget=0` → pas de colonnes Budget/Écart.
- Sans params → toutes les colonnes (rétrocompat).
- (PDF : au moins un test que la route répond 200 avec les params ; le contenu colonne est couvert par l'écran qui partage la logique de masquage.)

## Hors périmètre

- Pas de nouveau toggle au‑delà des deux demandés (YAGNI).
- Pas de modification de la variante « par opérations ».
- Pas de persistance « collante » par utilisateur (l'URL suffit, cf. décision 2026‑06‑22).

## Réutilisation / références

- Pattern toggles + export : `app/Livewire/RapportCompteResultatOperations.php` (L16‑45) + `resources/views/livewire/rapport-compte-resultat-operations.blade.php` (L104‑106).
- Lecture params export : `RapportExportController` L300‑304.
