# Écran Animateurs — Spec de design

**Date** : 2026-04-06
**Onglet** : Animateurs (sur OperationDetail, à droite de Participants)

## Contexte

Un animateur est un tiers qui émet des factures (dépenses) liées à une opération. Ce statut est **dérivé** des transactions existantes — pas de table dédiée. L'écran offre une matrice de suivi séances × animateurs pour visualiser les factures reçues et faciliter leur saisie.

## Principe clé

**Pas de nouvelle entité.** La liste des animateurs d'une opération se déduit dynamiquement :

```sql
SELECT DISTINCT t.id, t.nom, t.prenom
FROM transactions tx
JOIN transaction_lignes tl ON tl.transaction_id = tx.id
JOIN tiers t ON t.id = tx.tiers_id
WHERE tl.operation_id = :operation_id
  AND tx.type = 'depense'
```

## Modèle de données : pas de migration

Le champ `seance` (entier) sur `transaction_lignes` est **conservé tel quel**. Pas de FK vers `seances`.

La matrice joint sur les deux colonnes existantes :

```sql
LEFT JOIN seances s
  ON s.operation_id = tl.operation_id
  AND s.numero = tl.seance
```

**Justification** : une FK introduirait des risques sur l'existant — orphelins si les séances sont créées après les transactions, blocage de la saisie libre côté Compta, impact sur les restitutions. Le join sur `(operation_id, numero)` est fiable (couple unique dans `seances`) et ne touche à rien.

**Aucun impact** sur le code existant ni les rapports.

## Composant Livewire : `AnimateurManager`

### Emplacement

Nouvel onglet "Animateurs" dans `OperationDetail`, positionné juste à droite de "Participants".

### Données chargées

- Séances de l'opération (colonnes)
- Tiers distincts ayant des dépenses liées à cette opération (lignes)
- `TransactionLigne` groupées par (`tiers_id`, `seance` (numéro), `sous_categorie_id`) avec `SUM(montant)`, jointes aux `seances` via `operation_id + numero`

### Structure de la matrice

```
                              | S1     | S2     | S3     | Total
─────────────────────────────────────────────────────────────────
Dupont Marie                  | 180€   | 180€   |   —    | 360€
  Animation parcours          | 150€   | 150€   |   —    | 300€
  Frais déplacement           |  30€   |  30€   |   —    |  60€
─────────────────────────────────────────────────────────────────
Martin Jean                   | 200€   |   —    | 200€   | 400€
  Animation formation          | 200€   |   —    | 200€   | 400€
─────────────────────────────────────────────────────────────────
Total                         | 380€   | 180€   | 200€   | 760€
```

#### Lignes

- **Ligne parent** : nom du tiers (animateur), totaux par séance, total général en gras
- **Sous-lignes** : une par sous-catégorie utilisée par ce tiers, montants ventilés par séance

#### Colonnes

- Une colonne par séance (en-tête : numéro de séance, ex. "S1", "S2"…)
- Colonne "Total" à droite

#### Interactions dans les cases

- **Case avec montant** : cliquable → ouvre la modale d'édition de la transaction
- **⊕ vert** : toujours visible dans chaque case, permet d'ajouter une nouvelle transaction
- **Case vide** (`—`) : indique l'absence de facture, ce n'est pas forcément une anomalie

#### Totaux

- **Ligne de total par animateur** (dernière colonne)
- **Ligne de total par séance** (dernière ligne)
- **Total général** (coin bas-droit)

### Sélecteur de tiers (ajout d'un nouvel animateur)

Sous la matrice, un autocomplete sur les tiers existants. La sélection d'un tiers ouvre directement la modale de saisie avec ce tiers pré-rempli (pas de séance pré-remplie — à choisir dans la modale).

Une fois la transaction enregistrée, le tiers apparaît automatiquement dans la matrice.

### Modale de saisie de transaction

#### En-tête (lecture seule)

- **Tiers** : nom complet (pré-rempli, non modifiable)

#### Champs de la transaction

- **Date** : défaut = aujourd'hui
- **Référence** : numéro de facture (champ texte libre)
- **Compte bancaire** (`compte_id`) : optionnel, peut être renseigné plus tard
- **Mode de paiement** : optionnel (nullable)

#### Lignes de la transaction (dynamiques)

Chaque ligne contient 4 colonnes :

| Opération | Séance | Sous-catégorie | Montant |
|-----------|--------|----------------|---------|
| *pré-rempli (opération courante)* | *pré-rempli si clic depuis case* | *à saisir* | *à saisir* |

- **Opération** : pré-rempli avec l'opération courante, mais modifiable (pour factures multi-opérations)
- **Séance** : pré-rempli si clic depuis une case de la matrice, sinon sélecteur ; modifiable
- **Sous-catégorie** : sélecteur (sous-catégories de type dépense)
- **Montant** : décimal

Bouton "Ajouter une ligne" pour les factures multi-lignes (multi-séances, multi-sous-catégories, voire multi-opérations).

#### Enregistrement

Crée :
- Une `Transaction` avec :
  - `type` = depense
  - `tiers_id` = animateur sélectionné
  - `date` = date saisie
  - `reference` = référence saisie
  - `libelle` = `Facture d'animation {référence} de {tiers.nom_complet}`
  - `montant_total` = somme des montants des lignes
- N `TransactionLigne` avec :
  - `operation_id` = opération choisie par ligne
  - `seance` = numéro de la séance choisie (nullable, entier)
  - `sous_categorie_id` = sous-catégorie choisie
  - `montant` = montant saisi
  - `notes` = `{opération.nom} — Séance {seance.numero} — {sous_catégorie.nom}`

### Modale d'édition

Même modale que la saisie, pré-remplie avec les données de la transaction existante (toutes ses lignes, y compris celles d'autres opérations le cas échéant). Permet de modifier date, référence, et les lignes.

## Hors périmètre

- Gestion de droits spécifiques pour les animateurs (pas de rôle utilisateur)
- Vue consolidée multi-opérations (futur éventuel)
- Relance automatique des animateurs par email
- Pré-remplissage de sous-catégorie par animateur (trop variable)
