# Type d'opération — Spec de design

## Résumé

Introduire une entité **Type d'opération** qui catégorise les opérations (parcours de soins, formations, etc.). Le type porte les attributs structurants de l'opération : sous-catégorie comptable, nombre de séances par défaut, caractère confidentiel, restriction aux adhérents, logo et grille tarifaire. Cela permet de regrouper les opérations par type, de filtrer dans les écrans et rapports, et de standardiser le paramétrage.

## Modèle de données

### Nouvelle table `type_operations`

| Colonne              | Type           | Contraintes                     | Description                                            |
|----------------------|----------------|---------------------------------|--------------------------------------------------------|
| `id`                 | bigint PK      | auto-increment                  |                                                        |
| `code`               | varchar(20)    | required, unique                | Code court (ex: "PSA", "FORM-S")                       |
| `nom`                | varchar(150)   | required, unique                | Nom complet                                            |
| `description`        | text           | nullable                        | Description libre                                      |
| `sous_categorie_id`  | foreignId      | required, FK sous_categories    | Sous-catégorie comptable pour les inscriptions          |
| `nombre_seances`     | integer        | nullable, min:1                 | Nombre de séances par défaut (copié dans l'opération)  |
| `confidentiel`       | boolean        | default false                   | Active champs médicaux, masque token formulaire         |
| `reserve_adherents`  | boolean        | default false                   | Signale les non-adhérents dans la liste participants    |
| `actif`              | boolean        | default true                    | Type disponible dans les sélecteurs                    |
| `logo_path`          | varchar(255)   | nullable                        | Chemin image uploadée (storage)                        |
| `created_at`         | timestamp      |                                 |                                                        |
| `updated_at`         | timestamp      |                                 |                                                        |

### Nouvelle table `type_operation_tarifs`

| Colonne              | Type           | Contraintes                     | Description         |
|----------------------|----------------|---------------------------------|---------------------|
| `id`                 | bigint PK      | auto-increment                  |                     |
| `type_operation_id`  | foreignId      | required, FK cascade delete     |                     |
| `libelle`            | varchar(100)   | required, unique par type       | Ex: "Plein tarif"   |
| `montant`            | decimal(10,2)  | required                        | Montant en €        |
| `created_at`         | timestamp      |                                 |                     |
| `updated_at`         | timestamp      |                                 |                     |

### Modifications sur tables existantes

**`operations`** :
- Ajout : `type_operation_id` (foreignId, nullable, FK type_operations)
- Suppression : `sous_categorie_id` (migré vers le type)

**`participants`** :
- Ajout : `type_operation_tarif_id` (foreignId, nullable, FK type_operation_tarifs)

### Relations Eloquent

```
TypeOperation hasMany Operation
TypeOperation hasMany TypeOperationTarif
TypeOperation belongsTo SousCategorie
Operation belongsTo TypeOperation
TypeOperationTarif belongsTo TypeOperation
Participant belongsTo TypeOperationTarif (nullable)
```

## Composants et écrans

### Écran Paramètres > Types d'opération (nouveau)

Composant Livewire `TypeOperationManager` réutilisé dans les deux espaces (compta et gestion).

**Tableau** :
- Colonnes : Logo, Code (tri), Nom (tri), Sous-catégorie, Séances, Confidentiel, Adhérents, Actif, Nb tarifs, Actions
- Filtre : Tous / Actifs / Inactifs
- Types inactifs affichés en opacité réduite
- Actions : éditer (modale), supprimer (si aucune opération rattachée)

**Modale création/édition** (même composant) :
- Code (1/3 largeur) + Nom (2/3 largeur) — obligatoires
- Description — optionnelle
- Sous-catégorie (sélecteur, `pour_inscriptions = true`) + Nb séances par défaut
- 3 options avec texte explicatif :
  - **Données confidentielles** : "Active les champs médicaux (kiné, date de naissance, taille, poids) dans la fiche participant et les séances. Masque la fonction de création de token pour le formulaire d'auto-saisie."
  - **Réservé aux adhérents** : "Seuls les membres ayant une cotisation active sur l'exercice en cours peuvent s'inscrire. Les participants non adhérents sont signalés en rouge dans la liste."
  - **Actif** : "Un type inactif n'apparaît plus dans les sélecteurs lors de la création d'une opération. Les opérations existantes conservent leur type."
- Upload logo (PNG/JPG, max 512 Ko) — remplace le logo asso sur les exports PDF gestion
- Section tarifs dynamique : liste de lignes libellé + montant, ajout/suppression inline

Cette modale est réutilisée depuis le bouton "+" du formulaire d'opération.

**Note technique :** Les formulaires de création/édition d'opération côté compta sont actuellement des vues Blade classiques (pas Livewire). Le bouton "+" ouvrira la modale Livewire `TypeOperationManager` embarquée dans la page Blade via `@livewire`.

### Impacts sur les écrans existants

#### Formulaire création/édition d'opération (compta)
- Sélecteur "Type d'opération" avec bouton "+" pour créer au vol (ouvre la modale)
- Le choix du type pré-remplit `nombre_seances` (modifiable ensuite)
- Suppression du sélecteur `sous_categorie_id`
- Verrouillage du type dès qu'un participant est inscrit (sélecteur désactivé + message explicatif)

#### Liste des opérations (compta — `/compta/operations`)
- Nouvelle colonne Type (code en badge)
- Filtre par type au-dessus du tableau

#### Espace Gestion > Opérations
- Filtre par type dans le sélecteur d'opération (groupé par type dans le dropdown)
- Bannière d'alerte tant qu'il existe des opérations sans type assigné : message incitant à mettre à jour les opérations existantes

#### Tableau des participants
- Nouvelle colonne Adhérent :
  - Si `reserve_adherents = true` : badge vert (adhérent) ou rouge (non adhérent)
  - Si `reserve_adherents = false` : badge vert (adhérent) ou vide
  - Adhérent = cotisation active sur l'exercice en cours pour le tiers
- Colonne Tarif avec le libellé du tarif choisi à l'inscription
- Affichage du bouton "Créer token" uniquement si `confidentiel = true` (le formulaire auto-déclaratif sert à saisir les données médicales ; les opérations non confidentielles passent par HelloAsso)
- Masquage des colonnes médicales si `confidentiel = false`

#### Modale inscription participant
- Sélecteur de tarif parmi les tarifs du type (nullable)

#### Écran règlements
- Pré-remplissage de `montant_prevu` depuis le tarif du participant quand les valeurs sont vides au premier affichage

#### Séances / Présences
- Masquage de la colonne kiné si `confidentiel = false`

#### Rapports
- Filtre par type dans le rapport compte de résultat par opération
- Filtre par type dans le rapport par séances

#### Exports PDF (espace gestion)
- Logo du type en en-tête (remplace le logo de l'association)
- Logo de l'association en pied de page (petit, ~15 mm)
- Case "données confidentielles" masquée si le type ne le prévoit pas

#### Remise en banque (RemiseBancaireService)
- Utilise `operation->typeOperation->sousCategorie` au lieu de `operation->sousCategorie` pour créer les lignes de transaction lors de la validation d'une remise

#### Sync HelloAsso
- Utilise `operation->typeOperation->sousCategorie` au lieu de `operation->sousCategorie`

#### Navigation
- Ajout de "Types d'opération" dans le menu Paramètres des deux espaces (compta et gestion)

## Règles métier

### Suppression d'un type
- Interdite si des opérations sont rattachées (message d'erreur explicite)
- Alternative : désactiver le type

### Verrouillage du type sur une opération
- Modifiable tant qu'il n'y a aucun participant inscrit
- Dès le premier participant : sélecteur désactivé + message "Le type ne peut plus être modifié car des participants sont inscrits"

### Suppression d'un tarif
- Interdite si des participants utilisent ce tarif (FK `type_operation_tarif_id`)
- Message : "Ce tarif est utilisé par X participant(s)"

### Bannière de migration
- Affichée dans Gestion > Opérations tant qu'il existe des opérations avec `type_operation_id = NULL`
- Ton informatif, pas bloquant
- Disparaît automatiquement quand toutes les opérations ont un type

### Champ `reserve_adherents`
- Pas de blocage à l'inscription d'un non-adhérent (signalement visuel uniquement)
- Raison : l'inscription peut précéder le paiement de la cotisation

## Migration des données existantes

- `type_operation_id` est nullable en base
- Suppression de `sous_categorie_id` sur la table `operations`
- Bannière d'alerte dans l'espace gestion pour inciter les utilisateurs à catégoriser les opérations existantes
- Le volume d'opérations est faible, la migration manuelle est réaliste
