# Lot 2 — Enrichissement CR par opérations

## Contexte

Le CR par opérations (`RapportCompteResultatOperations`) permet de visualiser charges et produits ventilés par catégorie/sous-catégorie pour une sélection d'opérations. Le lot 1 a mis en place les écrans dédiés et la navigation. Le lot 2 enrichit ce rapport avec deux dimensions supplémentaires (séances, tiers) et remplace le sélecteur d'opérations.

## 1. Sélecteur d'opérations hiérarchique

### Remplacement du sélecteur actuel

Le filtre par type (dropdown) + checkboxes horizontales est remplacé par un **dropdown multi-select hiérarchique** unique.

### Structure du dropdown

```
Bouton: "Sélectionnez des opérations..." | "3 opérations sélectionnées" | "Nom opération" (si une seule)
┌─────────────────────────────────────────┐
│ ☐ Sous-catégorie A (label)             │
│   ☐ Type Opération X                   │
│     ☐ Opération 2025-2026              │
│     ☐ Opération été 2026              │
│   ☐ Type Opération Y                   │
│     ☐ Opération Z                      │
│ ☐ Sous-catégorie B                     │
│   ☐ Type Opération W                   │
│     ☐ Opération ...                    │
└─────────────────────────────────────────┘
```

### Comportement

- **Hiérarchie** : SousCategorie (du TypeOperation) → TypeOperation → Opération
- **Sélection en cascade** : cocher un niveau parent coche tous ses descendants
- **État indéterminé** : un parent dont certains enfants sont cochés affiche un tiret (indeterminate)
- **Reste ouvert** au clic intérieur (`data-bs-auto-close="outside"`)
- **Premier chargement** (pas de sélection en session/query string) : le dropdown s'ouvre automatiquement
- **Retour sur la page** : la sélection est restaurée via query string (`?ops=1,3,5`)
- **Opérations filtrées** par exercice courant (`Operation::forExercice()`)
- **Types inactifs exclus** (`TypeOperation::actif()`)

## 2. Toggle "Séances en colonnes"

### Switch Bootstrap sur la barre de filtres

Un `form-check form-switch` labellé "Séances en colonnes", sur la même ligne que le dropdown.

### Comportement désactivé (par défaut)

Tableau inchangé : une seule colonne "Montant".

### Comportement activé

Le tableau passe en mode croisé avec les colonnes :

| | Hors séances | S1 | S2 | ... | Total |

- **"Hors séances"** : première colonne, regroupe les montants dont `TransactionLigne.seance` est null ou 0
- **S1, S2, ...** : une colonne par numéro de séance distinct trouvé dans les données des opérations sélectionnées, triées par numéro
- **Total** : dernière colonne, somme de la ligne
- **Cellules à zéro** : affichées en tiret "—"
- **Lignes catégorie** : affichent uniquement le total (pas de ventilation par séance) — sujet à révision après test utilisateur
- **Lignes sous-catégorie** : ventilation complète par séance
- **Lignes Total charges/recettes** : ventilation complète par séance

### Persistance

État du toggle en query string (`&seances=1`).

## 3. Toggle "Tiers en lignes"

### Switch Bootstrap sur la barre de filtres

Un `form-check form-switch` labellé "Tiers en lignes", à côté du toggle séances.

### Comportement désactivé (par défaut)

Tableau à 2 niveaux : Catégorie → Sous-catégorie (comportement actuel).

### Comportement activé

Ajout d'un **3e niveau d'indentation** sous chaque sous-catégorie :

```
CHARGES
  Catégorie A                          1 200,00
    Sous-catégorie 1                     500,00
      👤 DUPONT Jean                     300,00
      🏢 Martin SAS                     200,00
    Sous-catégorie 2                     700,00
      (sans tiers)                       700,00
```

- **Tiers** : issu de `Transaction.tiers_id` (jointure TransactionLigne → Transaction → Tiers)
- **Particulier** : icône `bi bi-person text-muted` + NOM Prénom (nom en majuscules via l'accesseur Tiers existant)
- **Entreprise** : icône `bi bi-building text-muted` + Raison sociale
- **Sans tiers** : ligne en italique "(sans tiers)", pas d'icône
- **Tri** : alphabétique par nom complet au sein de chaque sous-catégorie
- **Sous-catégories** : conservent leur sous-total en gras

### Persistance

État du toggle en query string (`&tiers=1`).

## 4. Combinaison des deux toggles

Quand les deux toggles sont actifs simultanément, on obtient la matrice complète :

- **Lignes** : Catégorie → Sous-catégorie → Tiers
- **Colonnes** : Hors séances | S1 | S2 | ... | Total

Aucune restriction : les deux sont indépendants et disponibles même en multi-sélection d'opérations.

## 5. Barre de filtres — Layout final

Une seule ligne horizontale dans une card :

```
[▼ Sélectionnez des opérations...]  [◻ Séances en colonnes]  [◻ Tiers en lignes]
```

- Remplace intégralement la card filtre actuelle (dropdown type + checkboxes + bouton CSV)
- Le bouton CSV est retiré (lot 4 — exports)
- Économie maximale en hauteur d'écran

## 6. Technique

### RapportService

La méthode `compteDeResultatOperations()` est enrichie (ou déclinée) pour accepter deux booléens supplémentaires :

- `$parSeance` : ajoute `TransactionLigne.seance` au GROUP BY
- `$parTiers` : joint `Transaction.tiers_id → Tiers` et ajoute au GROUP BY

Combinaisons :
- Aucun toggle : `GROUP BY categorie, sous_categorie` (existant)
- Séances : `GROUP BY categorie, sous_categorie, seance`
- Tiers : `GROUP BY categorie, sous_categorie, tiers_id`
- Les deux : `GROUP BY categorie, sous_categorie, tiers_id, seance`

Le service retourne les données agrégées avec les dimensions demandées. Le composant Livewire pivote les colonnes dans le Blade.

### Composant Livewire

- Propriétés : `array $selectedOperationIds`, `bool $parSeances = false`, `bool $parTiers = false`
- Toutes les propriétés avec attribut `#[Url]` pour la persistance query string
- Le dropdown s'ouvre automatiquement au mount si `$selectedOperationIds` est vide
- Chaque changement de toggle/sélection = re-render Livewire (requête SQL adaptée)

### Gestion des affectations

La logique existante de double agrégation (TransactionLignes directes + TransactionLigneAffectations) est conservée. Le champ `seance` existe sur les deux tables. Le `tiers_id` est toujours sur la Transaction parente.

## 7. Hors périmètre

- **Export CSV/Excel/PDF** : lot 4
- **Pagination** : non applicable (rapport complet)
- **Lien vers le détail des séances** : le champ `seance` est un int, pas une FK vers la table `seances`
