# Lot 3 — État de flux de trésorerie

## Contexte

Troisième lot de la refonte des rapports comptables. Les lots 1 (réorganisation menu + analyse pivot) et 2 (enrichissement CR par opérations) sont terminés. Ce lot ajoute un nouveau rapport : l'état de flux de trésorerie, principalement destiné à la présentation en assemblée générale.

Le rapport s'inspire du format standard du plan de trésorerie simplifié pour associations (encaissements/décaissements consolidés, pas de découpage IAS 7 activités opérationnelles/investissement/financement).

La logique de calcul existe déjà dans `ClotureWizard::computeFinancialSummary()` — on l'extrait et la consolide.

## Accès et navigation

- Nouvelle entrée dans le dropdown **Rapports** de la navbar : **"Flux de trésorerie"** avec icône `bi-cash-stack`
- Position : entre "CR par opérations" et le séparateur (avant "Analyse financière")
- Route : `GET /compta/rapports/flux-tresorerie` — nom `compta.rapports.flux-tresorerie`
- Pas de sélecteur d'exercice : affiche toujours l'exercice ouvert (`ExerciceService::exerciceAffiche()`)

## Mention de statut

Bandeau en haut du rapport selon le statut de l'exercice :

- **Exercice ouvert** : `<div class="alert alert-info">` — *"Rapport provisoire — Exercice 2025-2026 en cours"*
- **Exercice clos** : `<div class="alert alert-success">` — *"Rapport définitif — Exercice 2025-2026 clôturé le 15/09/2026"*

Cette mention sera aussi reprise en en-tête des exports PDF (lot 4).

## Section 1 — Synthèse annuelle

Tableau vertical affiché par défaut. Vision consolidée tous comptes bancaires confondus.

### Structure

```
Solde de trésorerie au 1er septembre 2025           12 345,00
+ Encaissements (recettes)                           87 654,00
- Décaissements (dépenses)                           65 432,00
= Variation de trésorerie                            22 222,00
─────────────────────────────────────────────────────────────────
Solde de trésorerie théorique au 31 août 2026        34 567,00

Rapprochement bancaire
  Solde théorique                                    34 567,00
  - Recettes non pointées (12 écritures)              1 200,00
  + Dépenses non pointées (5 écritures)                 800,00
  = Solde bancaire réel                              34 167,00
```

### Règles de calcul

- **Périmètre des comptes** : uniquement les comptes bancaires réels (`est_systeme = false`). Les comptes techniques (effet à recevoir, remise bancaire) sont exclus — comme sur le dashboard et le rapprochement bancaire. Le traitement des effets à recevoir vis-à-vis des écritures non pointées est un sujet à revisiter ultérieurement.
- **Solde d'ouverture consolidé** : somme sur tous les comptes bancaires réels du solde d'ouverture de l'exercice. Pour chaque compte : `solde_reel_actuel - recettes_exercice + depenses_exercice - virements_in + virements_out` (logique existante dans `ClotureWizard`).
- **Encaissements** : `SUM(montant_total)` des transactions de type `recette` avec scope `forExercice()`.
- **Décaissements** : `SUM(montant_total)` des transactions de type `depense` avec scope `forExercice()`.
- **Virements internes** : s'annulent en consolidé (entrée sur un compte = sortie sur un autre). Ne figurent pas dans le rapport.
- **Solde théorique** : solde d'ouverture + encaissements - décaissements.
- **Écritures non pointées** : transactions de l'exercice sans `rapprochement_id` (non rattachées à un rapprochement bancaire verrouillé). Séparées en recettes et dépenses non pointées, avec le nombre d'écritures entre parenthèses.
- **Solde bancaire réel** : solde théorique - recettes non pointées + dépenses non pointées.

## Section 2 — Tableau mensuel (toggle)

Case à cocher **"Flux mensuels"** (`#[Url] public bool $fluxMensuels = false`). Masqué par défaut. Quand activé, affiche le tableau mensuel sous la synthèse.

### Structure

| Mois | Recettes | Dépenses | Solde (R-D) | Trésorerie cumulée |
|------|----------|----------|-------------|---------------------|
| Septembre 2025 | 8 500,00 | 4 200,00 | 4 300,00 | 16 645,00 |
| Octobre 2025 | 7 200,00 | 6 100,00 | 1 100,00 | 17 745,00 |
| ... | ... | ... | ... | ... |
| Août 2026 | 5 400,00 | 3 800,00 | 1 600,00 | 34 567,00 |
| **Total** | **87 654,00** | **65 432,00** | **22 222,00** | **34 567,00** |

### Règles

- 12 lignes correspondant aux mois de l'exercice (septembre année N → août année N+1).
- **Recettes / Dépenses** : agrégation par mois (`YEAR(date)`, `MONTH(date)`) avec scope `forExercice()`.
- **Solde (R-D)** : recettes - dépenses du mois.
- **Trésorerie cumulée** : solde d'ouverture + somme cumulative des soldes mensuels. La dernière ligne retombe sur le solde théorique de la section 1.
- Ligne **Total** en pied de tableau : somme des colonnes. La trésorerie cumulée du total = solde théorique.
- Mois sans mouvement : afficher 0,00 (ne pas omettre le mois).
- Labels des mois en français : "Septembre 2025", "Octobre 2025", etc.

## Styling

Cohérent avec les rapports existants (CR, CR par opérations) :

- En-tête de tableau : `table-dark` avec `--bs-table-bg: #3d5473`
- Ligne de total : fond `#5a7fa8`, texte blanc
- Ligne de résultat (variation trésorerie, solde théorique) : fond `#3d5473`, texte blanc
- Bloc rapprochement : fond légèrement distinct (`#f7f9fc`) pour le séparer visuellement
- Format nombres : `number_format($value, 2, ',', ' ')` (convention française)
- Font-size : 13px sur les tableaux (comme le CR)

## Architecture technique

### Composant Livewire

`app/Livewire/RapportFluxTresorerie.php` :
- Propriété `#[Url] public bool $fluxMensuels = false`
- `mount()` : récupère l'exercice ouvert, appelle le service
- `render()` : passe les données à la vue

### Méthode service

`RapportService::fluxTresorerie(int $exercice): array` retourne :

```php
[
    'exercice' => [
        'annee' => 2025,
        'label' => '2025-2026',
        'date_debut' => '2025-09-01',
        'date_fin' => '2026-08-31',
        'is_cloture' => false,
        'date_cloture' => null,
    ],
    'synthese' => [
        'solde_ouverture' => 12345.00,
        'total_recettes' => 87654.00,
        'total_depenses' => 65432.00,
        'variation' => 22222.00,
        'solde_theorique' => 34567.00,
    ],
    'rapprochement' => [
        'solde_theorique' => 34567.00,
        'recettes_non_pointees' => 1200.00,
        'nb_recettes_non_pointees' => 12,
        'depenses_non_pointees' => 800.00,
        'nb_depenses_non_pointees' => 5,
        'solde_reel' => 34167.00,
    ],
    'mensuel' => [
        ['mois' => 'Septembre 2025', 'recettes' => 8500.00, 'depenses' => 4200.00, 'solde' => 4300.00, 'cumul' => 16645.00],
        ['mois' => 'Octobre 2025', ...],
        // ... 12 entrées
    ],
]
```

### Calcul du solde d'ouverture consolidé

Reprend la logique de `ClotureWizard::computeFinancialSummary()` mais agrège tous les comptes :

```
Pour chaque CompteBancaire actif :
  solde_ouverture_compte = solde_reel - recettes_exercice + depenses_exercice
                           - virements_in_exercice + virements_out_exercice
Solde_ouverture_consolidé = SUM(solde_ouverture_compte)
```

Où `solde_reel` vient de `SoldeService::solde($compte)`.

### Fichiers à créer/modifier

| Action | Fichier |
|--------|---------|
| Créer | `app/Livewire/RapportFluxTresorerie.php` |
| Créer | `resources/views/livewire/rapport-flux-tresorerie.blade.php` |
| Créer | `resources/views/rapports/flux-tresorerie.blade.php` (page layout) |
| Modifier | `app/Services/RapportService.php` — ajouter `fluxTresorerie()` |
| Modifier | `routes/web.php` — ajouter la route |
| Modifier | `resources/views/layouts/app.blade.php` — ajouter entrée menu |

## Exports PDF (préparation lot 4)

Le service retourne déjà toutes les données nécessaires. Le lot 4 ajoutera :

- **Page 1** : synthèse + tableau mensuel + bloc rapprochement, avec mention "Rapport provisoire" ou "Rapport définitif — clôturé le ..."
- **Page 2** : détail des écritures non pointées — colonnes : numéro de pièce, date, tiers, libellé, montant (recette ou dépense)

Pour préparer le lot 4, le service expose aussi la liste brute des écritures non pointées (pas seulement les totaux).

## Ce qu'on ne fait PAS

- Pas de comparaison N-1
- Pas de détail par compte bancaire (vision consolidée uniquement)
- Pas de ventilation par catégorie ou opération (c'est le rôle du CR)
- Pas de sélecteur d'exercice (exercice ouvert uniquement)
- Pas de graphiques (futur dashboard)
- Pas d'export PDF/Excel (lot 4)
