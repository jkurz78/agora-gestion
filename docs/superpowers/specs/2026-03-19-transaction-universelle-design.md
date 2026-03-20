# Transaction Universelle — Design Spec

## Contexte

L'application dispose de plusieurs écrans de liste de transactions fragmentés :
`TransactionList`, `TransactionCompteList`, `TiersTransactions`, `DonList`, `CotisationList`.
Chacun est un composant Livewire distinct avec sa propre logique de filtrage et ses propres colonnes.

L'objectif est de les remplacer par un composant unique **TransactionUniverselle** qui réunit toutes les fonctions, paramétrable par des *props verrouillées* (contexte d'appel) et des *filtres libres* (choix de l'utilisateur).

L'écran `/virements` (VirementInterneList) reste un écran dédié — philosophie propre, usage rare.

---

## 1. Architecture du composant

### Props verrouillées (contexte d'appel)

Ces props sont injectées par la route ou le composant parent. L'utilisateur ne peut pas les modifier. Les filtres correspondants ne s'affichent pas.

| Prop | Type | Usage |
|---|---|---|
| `$compteId` | `?int` | Vue par compte — verrouille Compte |
| `$tiersId` | `?int` | Vue par tiers — verrouille Tiers |
| `$types` | `?array` | Types autorisés (ex. `['don']`) — masque le filtre Type |
| `$exercice` | `?int` | Exercice — verrouille la plage de dates |

Quand `$exercice` est fourni, les dates sont verrouillées sur cet exercice — la colonne Date affiche un label discret "Exercice XXXX–XXXX" sans loupe.

Quand `$exercice` est null, l'écran démarre sur l'exercice courant (résolu via `ExerciceService::exerciceCourant()`) mais l'utilisateur peut modifier librement la plage — y compris la vider pour accéder à toutes les écritures.

### Filtres libres (utilisateur)

Filtres que l'utilisateur peut manipuler librement. Ils s'affichent uniquement si le champ correspondant n'est pas verrouillé.

- **Type** — boutons toggle au-dessus du tableau. Un bouton **"Toutes"** en premier, suivi d'un bouton par type disponible dans le scope. Comportement : "Toutes" est actif par défaut et s'active en exclusivité (désactive les autres) ; activer un type spécifique désactive "Toutes" ; si tous les types spécifiques sont désactivés, "Toutes" se réactive automatiquement.
- **Date** — QBE popover sur la colonne Date : Exercice en cours / Mois en cours / Trimestre en cours / Dates libres (date début + date fin)
- **Tiers / Contrepartie** — QBE popover, recherche textuelle. La recherche porte sur le champ `tiers` du UNION, qui contient le nom du tiers pour les dépenses/recettes/dons/cotisations, et le nom du compte contrepartie (`→ Livret A`, `← CCP`) pour les virements.
- **Compte** — QBE popover, select parmi les comptes
- **Référence** — QBE popover, recherche textuelle
- **N° pièce** — QBE popover, recherche textuelle
- **Libellé** — QBE popover, recherche textuelle
- **Mode de paiement** — QBE popover, select
- **Pointé** — QBE popover, select (Tous / Oui / Non)

La barre au-dessus du tableau contient **uniquement les boutons Type**. Tous les autres filtres passent par les popovers QBE des colonnes. Il n'y a pas de champs date dans la barre principale.

> **Note :** Les dons et cotisations n'ont pas de référence dans le UNION (colonne `NULL`). Le filtre Référence actif retournera zéro résultat pour ces types — comportement attendu, non bloquant.

---

## 2. Colonnes et affichage

### Ordre des colonnes

| # | Colonne | Visible si | Notes |
|---|---|---|---|
| 1 | N° pièce | toujours | `—` si absent |
| 2 | Date | toujours | Format `j/m` |
| 3 | Type | plusieurs types affichés | Badge court (DÉP, REC, DON, COT, VIR) coloré |
| 4 | Référence | toujours | |
| 5 | Tiers / Contrepartie | Tiers non verrouillé | Vide pour COT/DON si tiers = même que verrouillé |
| 6 | Compte | Compte non verrouillé | |
| 7 | Libellé | toujours | |
| 8 | Catégorie | toujours | Triangle ▶ si multi-lignes (développable), valeur directe si ligne unique |
| 9 | Mode paiement | toujours | |
| 10 | Montant | toujours | Couleur rouge/vert selon signe |
| 11 | Pointé | toujours | ✓ / vide |
| 12 | Solde courant | conditionnel (voir §3) | |
| 13 | Actions | toujours | |

### Badges Type

| Type | Code | Couleur Bootstrap |
|---|---|---|
| Dépense | DÉP | `danger` (#dc3545) |
| Recette | REC | `success` (#198754) |
| Don | DON | `primary` (#0d6efd) |
| Cotisation | COT | `purple` (#6f42c1) |
| Virement interne | VIR | `orange` (#fd7e14) |

### Virements internes

Les virements apparaissent en deux lignes (sortante + entrante) issues d'un UNION, sur le modèle de `TransactionCompteService`. La contrepartie est placée dans la colonne **Tiers / Contrepartie** :
- Ligne sortante : `→ Livret A`
- Ligne entrante : `← CCP`

Les deux lignes portent le **même badge VIR** (pas de distinction visuelle sortant/entrant par le badge — la flèche dans la colonne Tiers suffit).

Le filtre "Tiers / Contrepartie" recherche dans ce champ, couvrant ainsi les tiers réels et les contreparties de virement.

Le bouton "Modifier" sur l'une ou l'autre des deux lignes ouvre **VirementInterneForm** pour le virement complet.

**Stratégie d'aliasing UNION :** le champ `id` de chaque ligne du résultat est toujours la clé primaire de l'entité source. Pour `source_type IN ('virement_sortant', 'virement_entrant')`, `id = virement_interne.id`. Le consommateur utilise `source_type` pour savoir comment interpréter `id` et quel formulaire ouvrir — pas besoin de colonne `virement_interne_id` séparée.

---

## 3. Solde courant (colonne conditionnelle)

La colonne Solde courant s'affiche **uniquement** si les trois conditions sont réunies :
1. Un seul compte est sélectionné (ou verrouillé)
2. Tous les types **du scope courant** sont inclus (aucun filtre Type actif parmi les types disponibles)
3. Aucun filtre libre autre que les dates n'est actif

Le solde est calculé cumulativement sur la liste triée par date ascendante. Si les conditions ne sont plus réunies, la colonne disparaît silencieusement.

---

## 4. Filtres Type — sélection multiple

Les types sont affichés comme **boutons toggle** au-dessus du tableau (extension du pattern existant). Chaque bouton utilise la couleur du type. Le bouton "Toutes" réinitialise tous les autres. Quand l'utilisateur active un ou plusieurs types spécifiques, "Toutes" se désactive automatiquement.

Les couleurs restent sobres (pas de fond plein sur tous les boutons, seulement sur les actifs).

---

## 5. QBE — filtres dans les en-têtes de colonnes

Chaque colonne filtrable dispose d'une **icône loupe** visible au survol. Un clic ouvre un **popover** positionné sous l'en-tête avec le contrôle adapté (input texte, select, datepicker pour les dates). Un filtre actif affiche un **badge** dans l'en-tête ("CCP ×") permettant de l'effacer d'un clic.

---

## 6. Actions par type

### Règle globale — disponibilité des actions

**Exercice clôturé :** Si l'exercice affiché est marqué comme clôturé (future colonne `cloture` sur la table `exercices`), **tous** les boutons Modifier et Supprimer sont désactivés (`disabled`, grisés) pour toutes les lignes. Un bandeau ou label discret indique que l'exercice est en lecture seule. Le composant détermine cet état lui-même à partir de l'exercice courant — pas de prop `$readonly` externe. Tant que la table `exercices` n'existe pas, cette vérification est un no-op (exercice jamais clôturé).

**Transaction pointée :** Les boutons Modifier et Supprimer sont masqués quand la transaction est pointée (rapprochement bancaire validé). Seule exception : TransactionForm affiche le verrou et permet de le lever explicitement depuis le modal.

### Dépenses / Recettes

- **Modifier** : ouvre le modal TransactionForm existant (incluant les lignes de ventilation et l'accès au verrou si rapprochement)
- **Supprimer** : confirmation, soft delete

### Dons

- **Modifier** : ouvre DonForm modal (Lot 1)
- **Supprimer** : confirmation, soft delete

### Cotisations

- **Modifier** : ouvre CotisationForm modal (Lot 1) — le champ Tiers/Membre est affiché en lecture seule, non modifiable
- **Supprimer** : confirmation, suppression

### Virements internes

- **Modifier** : ouvre VirementInterneForm modal (Lot 1)
- **Supprimer** : confirmation, supprime le virement complet (les deux demi-écritures). Les deux lignes du UNION disparaissent au rechargement.

---

## 7. Expansion de ligne

Un clic sur la ligne (hors actions) ouvre une zone de détail inline (toggle Alpine.js + appel Livewire au premier ouverture) :
- Lignes de ventilation (dépenses/recettes multi-lignes)
- **Opération** — opération budgétaire associée (si renseignée)
- **Séance** — séance/réunion d'association liée (si renseignée)
- Notes / observations
- Informations de saisie (date, auteur)

---

## 8. Bouton "Nouveau"

En vue libre (aucun type verrouillé), un bouton **"+ Nouvelle transaction"** en haut à droite ouvre un **dropdown Bootstrap** listant les types disponibles (Dépense, Recette, Don, Cotisation). Le clic sur un type dispatche l'événement Livewire `openModal` du formulaire correspondant.

En vue verrouillée sur un type unique, le bouton est direct :
- `$types = ['depense']` → "Nouvelle dépense" → TransactionForm
- `$types = ['don']` → "Nouveau don" → DonForm
- `$types = ['cotisation']` → "Nouvelle cotisation" → CotisationForm

---

## 9. Carte d'intégration et lots

### Écrans existants

| Écran actuel | Route | Composant | Sort |
|---|---|---|---|
| Liste transactions | `/transactions` | TransactionList | Remplacé — Lot 3 |
| Transactions par compte | `/transactions/compte/{id}` | TransactionCompteList | Remplacé — Lot 4 |
| Transactions par tiers | `/membres/{id}/transactions` | TiersTransactions | Remplacé — Lot 5 |
| Liste dons | `/dons` | DonList | Remplacé — Lot 6 |
| Liste cotisations | `/cotisations` | CotisationList | Remplacé — Lot 7 |
| Liste virements | `/virements` | VirementInterneList | **Conservé** |
| Rapprochement | `/rapprochement` | — | **Conservé** |

### Lots de livraison

**Contrat d'événements pour les modaux (Lot 1) :**

| Formulaire | Événement Livewire | Payload |
|---|---|---|
| TransactionForm (existant) | `open-transaction-form` | `['id' => null\|int]` |
| DonForm | `open-don-form` | `['id' => null\|int]` |
| CotisationForm | `open-cotisation-form` | `['id' => null\|int]` |
| VirementInterneForm | `open-virement-form` | `['id' => null\|int]` |

`id = null` → nouveau, `id = X` → édition.

| Lot | Contenu | Dépendances |
|---|---|---|
| **1** | Convertir DonForm, CotisationForm et VirementInterneForm en modaux Bootstrap autonomes écoutant les événements ci-dessus | Aucune |
| **2** | Implémenter TransactionUniverselle (route `/transactions/all` provisoire) | Lot 1 |
| **3** | Remplacer `/transactions` par TransactionUniverselle | Lot 2 |
| **4** | Remplacer `/transactions/compte/{id}` | Lot 2 |
| **5** | Remplacer `/membres/{id}/transactions` | Lot 2 |
| **6** | Remplacer `/dons` | Lot 2 |
| **7** | Remplacer `/cotisations` | Lot 2 |

Chaque lot est indépendant et déployable séparément. Les anciens composants coexistent jusqu'au lot qui les remplace.

> **Lot futur possible — Lot 8 :** Remplacer `/virements` (VirementInterneList) par une vue TransactionUniverselle verrouillée sur `$types = ['virement']`. Non planifié dans cette release.

---

## 10. Contraintes techniques

- Stack : Laravel 11 + Livewire 4 + Bootstrap 5 (CDN)
- `declare(strict_types=1)` + `final class` sur le composant Livewire
- Requête UNION héritée de `TransactionCompteService` — à extraire dans un `TransactionUniverselleService`
- Scope `forExercice(int $annee)` pour la borne de dates ; exercice courant résolu via `ExerciceService::exerciceCourant()` si `$exercice` n'est pas injecté
- Tri **SQL** sur le UNION (pas JS). Ordre par défaut : **date ASC, id ASC** quand la colonne Solde courant est visible (comportement relevé bancaire) ; **date DESC, id DESC** sinon. Le tri client JS (`data-sort` Alpine) n'est pas utilisé. Le tri par Montant global n'est pas prévu dans ce lot.
- Pagination : `WithPagination` + sélecteur "par page" (10/25/50) — même pattern que les composants existants
- Expansion de ligne : toggle Alpine.js sur `wire:click` pour révéler une zone inline ; les détails (lignes de ventilation) sont chargés via un appel Livewire distinct déclenché à l'ouverture (pas de `lazy` Livewire global sur la liste)
- SoftDeletes respectés (Depense, Recette, Don)
- Locale fr partout
