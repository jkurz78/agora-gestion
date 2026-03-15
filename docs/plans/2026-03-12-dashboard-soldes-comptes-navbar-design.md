# Dashboard — Soldes comptes bancaires & Refonte navbar — Design

**Date :** 2026-03-12
**Branche :** staging

---

## Contexte

Deux évolutions sur l'interface principale :

1. **Dashboard** — afficher le solde courant réel de chaque compte bancaire, restructurer la Row 1 et déplacer le sélecteur d'exercice dans l'en-tête.
2. **Navbar** — réordonner les entrées et supprimer "Opérations" (désormais dans Paramètres).

---

## 1. Dashboard — Soldes des comptes bancaires

### En-tête

Le titre "Tableau de bord" et le sélecteur d'exercice passent sur la même ligne (flex justify-content-between). La carte "Exercice" de la Row 1 est supprimée.

```
Tableau de bord                          [Exercice 2024-2025 ▼]
```

### Row 1 restructurée

```
┌─────────────────┬──────────────────────────────────────────────────┐
│  Solde général  │  Soldes des comptes bancaires                     │
│   (col-md-4)    │  ┌──────────────┬──────────────┬──────────────┐  │
│                 │  │  Compte A    │  Compte B    │  Compte C    │  │
│  XX XXX,XX €    │  │  XX XXX,XX € │  XX XXX,XX € │ XX XXX,XX €  │  │
│  Recettes: ...  │  └──────────────┴──────────────┴──────────────┘  │
│  Dépenses: ...  │   (col-md-8, une mini-carte par compte)           │
└─────────────────┴──────────────────────────────────────────────────┘
```

- `col-md-4` pour le solde général (réduit depuis col-md-8)
- `col-md-8` pour les comptes, avec une mini-carte Bootstrap par compte (`col` dans une `row g-2`)
- Les soldes des comptes sont **indépendants de l'exercice sélectionné** (solde réel à aujourd'hui)

### Calcul du solde courant

```
solde_courant(compte) = solde_initial
  + SUM(recettes.montant_total  WHERE compte_id = compte.id AND date >= date_solde_initial)
  + SUM(cotisations.montant     WHERE compte_id = compte.id AND date >= date_solde_initial)
  + SUM(virements_internes.montant WHERE compte_destination_id = compte.id AND date >= date_solde_initial)
  - SUM(depenses.montant_total  WHERE compte_id = compte.id AND date >= date_solde_initial)
  - SUM(dons.montant            WHERE compte_id = compte.id AND date >= date_solde_initial)
  - SUM(virements_internes.montant WHERE compte_source_id = compte.id AND date >= date_solde_initial)
```

Seuls les enregistrements non soft-deleted sont comptabilisés (comportement Eloquent par défaut).

### Architecture

- **Nouveau `app/Services/SoldeService.php`** — méthode `solde(CompteBancaire $compte): float`
  Cohérent avec le pattern `BudgetService::realise()`.
- **`app/Livewire/Dashboard.php`** — charge `CompteBancaire::orderBy('nom')->get()` et calcule le solde de chacun via `SoldeService`
- **`resources/views/livewire/dashboard.blade.php`** — restructure l'en-tête + Row 1

---

## 2. Navbar — réordonnement

### Nouvel ordre

| Position | Route | Icône | Label |
|----------|-------|-------|-------|
| 1 | `dashboard` | speedometer2 | Tableau de bord |
| 2 | `depenses.index` | arrow-down-circle | Dépenses |
| 3 | `recettes.index` | arrow-up-circle | Recettes |
| 4 | `virements.index` | arrow-left-right | Virements |
| 5 | `budget.index` | piggy-bank | Budget |
| 6 | `rapprochement.index` | bank | Rapprochement |
| 7 | `membres.index` | people | Membres |
| 8 | `dons.index` | heart | Dons |
| 9 | `rapports.index` | file-earmark-bar-graph | Rapports |
| 10 | `parametres.index` | gear | Paramètres |

**Supprimé :** `operations.index` (Opérations est désormais accessible via Paramètres > onglet Opérations)

### Fichier impacté

- `resources/views/layouts/app.blade.php` — tableau `$navItems` uniquement

---

## Fichiers à modifier / créer

| Action | Fichier |
|--------|---------|
| Créer | `app/Services/SoldeService.php` |
| Modifier | `app/Livewire/Dashboard.php` |
| Modifier | `resources/views/livewire/dashboard.blade.php` |
| Modifier | `resources/views/layouts/app.blade.php` |
