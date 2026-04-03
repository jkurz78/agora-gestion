# Lot 1 — Réorganisation Rapports + Analyse Financière

**Date :** 2026-04-03
**Scope :** Dropdown menu, écrans dédiés, dédoublement pivot table

## Contexte

Les rapports comptables sont aujourd'hui sur une seule page `/compta/rapports` avec 3 onglets Bootstrap (CR, CR par opérations, Rapport par séances). L'analyse pivot table est côté gestion avec un toggle données participants / financières.

Ce lot réorganise la navigation et sépare les écrans. Le rapport par séances est temporairement retiré (il reviendra au lot 2 via des cases à cocher sur le CR par opérations).

## Lots prévus (vue d'ensemble)

1. **Lot 1 (ce spec)** — Réorganisation menu + écrans dédiés + analyse financière pivot
2. **Lot 2** — Enrichissement CR par opérations (séances en colonnes, tiers en lignes, meilleur sélecteur d'opérations, dimensions temporelles pivot)
3. **Lot 3** — État de trésorerie (nouveau rapport)
4. **Lot 4** — Exports (CSV, Excel, PDF mis en page) sur tous les rapports

## Section 1 : Routes & Navigation

### Nouvelles routes (groupe `compta`)

| Route                              | Nom                              | Écran                  |
|------------------------------------|----------------------------------|------------------------|
| `/compta/rapports/compte-resultat` | `compta.rapports.compte-resultat`| CR complet             |
| `/compta/rapports/operations`      | `compta.rapports.operations`     | CR par opérations      |
| `/compta/rapports/analyse`         | `compta.rapports.analyse`        | Analyse financière     |

L'ancienne route `/compta/rapports` redirige (301) vers `/compta/rapports/compte-resultat`.

### Dropdown navbar

Le lien "Rapports" (icône `bi-file-earmark-bar-graph`) devient un dropdown Bootstrap :

```
▾ Rapports
   Compte de résultat
   Compte de résultat par opérations
   ──────────────
   Analyse financière
```

Chaque item est un lien vers sa route dédiée.

## Section 2 : Écrans CR et CR par opérations

### Compte de résultat

Écran identique à l'actuel Tab 1. Le contenu de `livewire/rapport-compte-resultat.blade.php` est déplacé dans sa propre page avec layout. Le composant Livewire `RapportCompteResultat` ne change pas.

### CR par opérations

Le composant `RapportCompteResultatOperations` est déplacé sur son propre écran. Comportement identique à l'actuel Tab 2 (tableau simple, sélection d'opérations par type). Pas de cases à cocher séances/tiers dans ce lot.

Le composant `RapportSeances` et sa vue sont supprimés. La fonctionnalité séances reviendra au lot 2 intégrée dans le CR par opérations.

## Section 3 : Dédoublement du Pivot Table

### Approche retenue : composant unique paramétré (Approche A)

Le composant `AnalysePivot` est paramétré avec un `$mode` passé en attribut :

```blade
{{-- Côté gestion --}}
<livewire:analyse-pivot mode="participants" />

{{-- Côté compta --}}
<livewire:analyse-pivot mode="financier" />
```

### Changements sur le composant

- Suppression du toggle participants/financier et de la propriété `$activeView`
- Ajout d'une propriété `$mode` (paramètre mount, non modifiable par l'utilisateur)
- La méthode de données appelée dépend de `$mode`
- La vue n'affiche plus les boutons de bascule

### Côté gestion (`/gestion/analyse`)

La page existante instancie le composant en `mode="participants"`. Comportement identique à aujourd'hui sans le toggle.

### Côté compta (`/compta/rapports/analyse`)

Nouvelle page instanciant le composant en `mode="financier"`. Ajout de 3 dimensions temporelles dérivées de `transactions.date` :

- **Mois** (ex: "Janvier 2026")
- **Trimestre** (ex: "T1 2025-2026", calé sur l'exercice sept→août)
- **Semestre** (ex: "S1 2025-2026")

### Dimensions financières finales

Catégorie, Sous-catégorie, Opération, Type opération, Séance n°, Tiers, Type tiers, Date, **Mois**, **Trimestre**, **Semestre**, Type (transaction), Compte bancaire.

Mesure : Somme(Montant).

Note : les dimensions temporelles (Mois, Trimestre, Semestre) ne sont ajoutées que côté financier. Côté gestion/participants, les séances structurent déjà le temps.

## Section 4 : Fichiers impactés

### Créations

| Fichier | Rôle |
|---------|------|
| `resources/views/rapports/compte-resultat.blade.php` | Page dédiée CR |
| `resources/views/rapports/operations.blade.php` | Page dédiée CR par opérations |
| `resources/views/rapports/analyse.blade.php` | Page dédiée pivot financier |

### Modifications

| Fichier | Changement |
|---------|-----------|
| `routes/web.php` | 3 nouvelles routes + redirect ancien `/compta/rapports` |
| `resources/views/layouts/app.blade.php` | Lien "Rapports" → dropdown 3 items + séparateur |
| `app/Livewire/AnalysePivot.php` | `$activeView` → `$mode` (mount param), suppression toggle, ajout dimensions temporelles query financière |
| `resources/views/livewire/analyse-pivot.blade.php` | Suppression boutons toggle |
| `resources/views/gestion/analyse/index.blade.php` | Passage `mode="participants"` |

### Suppressions

| Fichier | Raison |
|---------|--------|
| `resources/views/rapports/index.blade.php` | Remplacé par les 3 écrans dédiés |
| `app/Livewire/RapportSeances.php` | Supprimé, reviendra lot 2 via CR opérations |
| `resources/views/livewire/rapport-seances.blade.php` | Idem |

### Inchangés

- `app/Services/RapportService.php` — aucun changement
- Modèles Eloquent — aucun changement
- `app/Livewire/RapportCompteResultat.php` — aucun changement
- `app/Livewire/RapportCompteResultatOperations.php` — aucun changement
