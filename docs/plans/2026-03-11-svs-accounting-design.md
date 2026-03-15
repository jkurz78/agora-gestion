# SVS Accounting — Design Document

## Context

Standalone accounting application for Association Soigner Vivre Sourire (SVS), a French loi 1901 non-profit. Separate from the SVS public website.

## Stack

- **Backend:** PHP 8.x procédural, PDO/MySQL
- **Frontend:** Bootstrap 5
- **Serveur:** Apache
- **Langue:** Français

## Exercice comptable

L'exercice va du **1er septembre au 31 août**. Il est identifié par son année de début (INT). `exercice = 2025` signifie 01/09/2025 → 31/08/2026, affiché "2025-2026" dans l'UI.

Toutes les requêtes par exercice utilisent :

```sql
WHERE date BETWEEN CONCAT(exercice, '-09-01') AND CONCAT(exercice + 1, '-08-31')
```

L'exercice en cours est calculé automatiquement : si `MONTH(NOW()) >= 9` → `exercice = YEAR(NOW())`, sinon `exercice = YEAR(NOW()) - 1`.

## Authentification

- Comptes individuels par email/mot de passe (bcrypt)
- Tous les utilisateurs ont les mêmes droits (pas de rôles)
- Réinitialisation de mot de passe par email (token + lien expirant 1h)

## Structure de fichiers

```
/
├── config/
│   └── db.php                  # connexion PDO
├── includes/
│   ├── auth.php                # vérification session (require en tête de page)
│   ├── header.php              # navbar Bootstrap
│   └── footer.php
├── pages/
│   ├── login.php
│   ├── logout.php
│   ├── reset-password.php      # demande + confirmation token
│   ├── dashboard.php
│   ├── depenses.php
│   ├── recettes.php
│   ├── budget.php
│   ├── membres.php
│   ├── dons.php
│   ├── operations.php
│   ├── rapprochement.php       # rapprochement bancaire
│   ├── rapports.php            # compte de résultat CERFA + rapport par séances
│   └── parametres.php          # catégories, utilisateurs, comptes bancaires
├── sql/
│   └── schema.sql
└── index.php                   # redirect → login ou dashboard
```

## Schéma de base de données (16 tables)

### `users`

| Colonne          | Type                  | Notes  |
| ---------------- | --------------------- | ------ |
| id               | INT PK AUTO_INCREMENT |        |
| nom              | VARCHAR(100)          |        |
| email            | VARCHAR(150) UNIQUE   |        |
| password_hash    | VARCHAR(255)          | bcrypt |
| reset_token      | VARCHAR(64) NULL      |        |
| reset_expires_at | DATETIME NULL         |        |
| created_at       | DATETIME              |        |

### `comptes_bancaires`

| Colonne            | Type                  | Notes                    |
| ------------------ | --------------------- | ------------------------ |
| id                 | INT PK AUTO_INCREMENT |                          |
| nom                | VARCHAR(150)          | ex: "Compte courant CIC" |
| iban               | VARCHAR(34) NULL      |                          |
| solde_initial      | DECIMAL(10,2)         |                          |
| date_solde_initial | DATE                  |                          |
| created_at         | DATETIME              |                          |

### `categories`

| Colonne    | Type                      | Notes |
| ---------- | ------------------------- | ----- |
| id         | INT PK AUTO_INCREMENT     |       |
| nom        | VARCHAR(100)              |       |
| type       | ENUM('depense','recette') |       |
| created_at | DATETIME                  |       |

### `sous_categories`

| Colonne      | Type                  | Notes                  |
| ------------ | --------------------- | ---------------------- |
| id           | INT PK AUTO_INCREMENT |                        |
| categorie_id | INT FK                |                        |
| nom          | VARCHAR(100)          |                        |
| code_cerfa   | VARCHAR(10) NULL      | ex: "641", "74", "755" |
| created_at   | DATETIME              |                        |

### `operations`

| Colonne        | Type                        | Notes                               |
| -------------- | --------------------------- | ----------------------------------- |
| id             | INT PK AUTO_INCREMENT       |                                     |
| nom            | VARCHAR(150)                |                                     |
| description    | TEXT NULL                   |                                     |
| date_debut     | DATE NULL                   |                                     |
| date_fin       | DATE NULL                   |                                     |
| nombre_seances | INT NULL                    | si NULL : pas de gestion par séance |
| statut         | ENUM('en_cours','cloturee') |                                     |
| created_at     | DATETIME                    |                                     |

### `depenses` _(en-tête de pièce)_

| Colonne       | Type                                                   | Notes                  |
| ------------- | ------------------------------------------------------ | ---------------------- |
| id            | INT PK AUTO_INCREMENT                                  |                        |
| date          | DATE                                                   |                        |
| libelle       | VARCHAR(255)                                           |                        |
| montant_total | DECIMAL(10,2)                                          |                        |
| mode_paiement | ENUM('virement','cheque','especes','cb','prelevement') |                        |
| beneficiaire  | VARCHAR(150) NULL                                      |                        |
| reference     | VARCHAR(100) NULL                                      | n° pièce               |
| compte_id     | INT FK NULL                                            | compte bancaire        |
| pointe        | TINYINT(1) DEFAULT 0                                   | rapprochement bancaire |
| notes         | TEXT NULL                                              |                        |
| saisi_par     | INT FK → users.id                                      |                        |
| created_at    | DATETIME                                               |                        |

### `depense_lignes` _(ventilation analytique)_

| Colonne           | Type                  | Notes                                |
| ----------------- | --------------------- | ------------------------------------ |
| id                | INT PK AUTO_INCREMENT |                                      |
| depense_id        | INT FK                |                                      |
| sous_categorie_id | INT FK                |                                      |
| operation_id      | INT FK NULL           |                                      |
| seance            | INT NULL              | entre 1 et operations.nombre_seances |
| montant           | DECIMAL(10,2)         |                                      |
| notes             | TEXT NULL             |                                      |

### `recettes` _(en-tête de pièce)_

| Colonne       | Type                                                   | Notes |
| ------------- | ------------------------------------------------------ | ----- |
| id            | INT PK AUTO_INCREMENT                                  |       |
| date          | DATE                                                   |       |
| libelle       | VARCHAR(255)                                           |       |
| montant_total | DECIMAL(10,2)                                          |       |
| mode_paiement | ENUM('virement','cheque','especes','cb','prelevement') |       |
| payeur        | VARCHAR(150) NULL                                      |       |
| reference     | VARCHAR(100) NULL                                      |       |
| compte_id     | INT FK NULL                                            |       |
| pointe        | TINYINT(1) DEFAULT 0                                   |       |
| notes         | TEXT NULL                                              |       |
| saisi_par     | INT FK → users.id                                      |       |
| created_at    | DATETIME                                               |       |

### `recette_lignes` _(ventilation analytique)_

| Colonne           | Type                  | Notes |
| ----------------- | --------------------- | ----- |
| id                | INT PK AUTO_INCREMENT |       |
| recette_id        | INT FK                |       |
| sous_categorie_id | INT FK                |       |
| operation_id      | INT FK NULL           |       |
| seance            | INT NULL              |       |
| montant           | DECIMAL(10,2)         |       |
| notes             | TEXT NULL             |       |

### `budget_lines`

| Colonne           | Type                  | Notes                         |
| ----------------- | --------------------- | ----------------------------- |
| id                | INT PK AUTO_INCREMENT |                               |
| sous_categorie_id | INT FK                |                               |
| exercice          | INT                   | ex: 2025 = exercice 2025-2026 |
| montant_prevu     | DECIMAL(10,2)         |                               |
| notes             | TEXT NULL             |                               |
| created_at        | DATETIME              |                               |

### `membres`

| Colonne       | Type                    | Notes |
| ------------- | ----------------------- | ----- |
| id            | INT PK AUTO_INCREMENT   |       |
| nom           | VARCHAR(100)            |       |
| prenom        | VARCHAR(100)            |       |
| email         | VARCHAR(150) NULL       |       |
| telephone     | VARCHAR(20) NULL        |       |
| adresse       | TEXT NULL               |       |
| date_adhesion | DATE NULL               |       |
| statut        | ENUM('actif','inactif') |       |
| notes         | TEXT NULL               |       |
| created_at    | DATETIME                |       |

### `cotisations`

| Colonne       | Type                                                   | Notes                         |
| ------------- | ------------------------------------------------------ | ----------------------------- |
| id            | INT PK AUTO_INCREMENT                                  |                               |
| membre_id     | INT FK                                                 |                               |
| exercice      | INT                                                    | ex: 2025 = exercice 2025-2026 |
| montant       | DECIMAL(10,2)                                          |                               |
| date_paiement | DATE                                                   |                               |
| mode_paiement | ENUM('virement','cheque','especes','cb','prelevement') |                               |
| compte_id     | INT FK NULL                                            |                               |
| pointe        | TINYINT(1) DEFAULT 0                                   |                               |
| created_at    | DATETIME                                               |                               |

### `donateurs`

| Colonne    | Type                  | Notes |
| ---------- | --------------------- | ----- |
| id         | INT PK AUTO_INCREMENT |       |
| nom        | VARCHAR(100)          |       |
| prenom     | VARCHAR(100)          |       |
| email      | VARCHAR(150) NULL     |       |
| adresse    | TEXT NULL             |       |
| created_at | DATETIME              |       |

### `dons`

| Colonne       | Type                                                   | Notes                  |
| ------------- | ------------------------------------------------------ | ---------------------- |
| id            | INT PK AUTO_INCREMENT                                  |                        |
| donateur_id   | INT FK NULL                                            | nullable = don anonyme |
| date          | DATE                                                   |                        |
| montant       | DECIMAL(10,2)                                          |                        |
| mode_paiement | ENUM('virement','cheque','especes','cb','prelevement') |                        |
| objet         | VARCHAR(255) NULL                                      |                        |
| operation_id  | INT FK NULL                                            |                        |
| seance        | INT NULL                                               |                        |
| compte_id     | INT FK NULL                                            |                        |
| pointe        | TINYINT(1) DEFAULT 0                                   |                        |
| recu_emis     | TINYINT(1) DEFAULT 0                                   | pour v2 reçu fiscal    |
| saisi_par     | INT FK → users.id                                      |                        |
| created_at    | DATETIME                                               |                        |

## Modules et écrans

### Tableau de bord

- Exercice en cours calculé automatiquement (Sept→Août)
- Solde général (total recettes − total dépenses, exercice en cours)
- Budget prévu vs réalisé par catégorie
- Dernières dépenses et recettes saisies
- Derniers dons reçus
- Membres avec cotisation en attente pour l'exercice en cours

### Budget prévisionnel

- Liste des lignes par exercice (sélecteur "2024-2025", "2025-2026"…)
- Ajout / édition / suppression de ligne budgétaire
- Vue synthèse : prévu vs réalisé par catégorie

### Dépenses

- Liste avec filtres : période, catégorie/sous-catégorie, opération, compte bancaire, pointé
- Formulaire d'ajout/édition : en-tête + lignes de ventilation dynamiques
  - Sur chaque ligne : si opération sélectionnée avec `nombre_seances`, afficher sélecteur séance (1..N)
- Suppression

### Recettes

- Identique aux dépenses (symétrique)

### Membres

- Liste : nom, statut, cotisation exercice en cours (payée ✓/✗)
- Fiche membre : informations + historique des cotisations
- Ajout / édition
- Enregistrer une cotisation (avec compte bancaire + pointé)

### Dons

- Liste des dons avec donateur (ou "Anonyme")
- Fiche donateur avec historique des dons
- Ajout de don (sélection ou création du donateur, compte bancaire, séance si opération avec séances)

### Opérations

- Liste des opérations (en cours / clôturées)
- Fiche opération : dépenses + recettes rattachées, solde de l'opération

### Rapprochement bancaire

- Sélection du compte + période
- Liste des transactions non pointées (dépenses, recettes, dons, cotisations)
- Solde théorique = solde initial + total pointé recettes − total pointé dépenses
- Bouton pointer/dépointer par transaction

### Rapports

#### Compte de résultat (format CERFA 15059-02)

- Filtre exercice _(obligatoire)_
- Filtre opérations : "Toutes" _(défaut)_ ou sélection multiple
- Présentation charges/produits agrégés par `code_cerfa`
- Export CSV

#### Rapport par séances

- Sélection de l'opération _(filtrée sur celles avec `nombre_seances` renseigné)_
- Tableau pivot : lignes = catégories/sous-catégories, colonnes = Séance 1…N + Total
- Charges et produits séparés, solde par séance
- Export CSV

### Paramètres

- Gestion des catégories et sous-catégories (avec code CERFA)
- Gestion des comptes bancaires (ajout / édition / suppression)
- Gestion des utilisateurs de l'application (ajout / suppression)

## Sécurité

- Mots de passe hashés avec `password_hash()` (bcrypt)
- Sessions PHP natives, vérification sur chaque page protégée
- Requêtes PDO préparées (protection injection SQL)
- Tokens de réinitialisation à usage unique avec expiration (1h)
- Protection CSRF sur tous les formulaires (token caché)

## Hors scope MVP (V2)

- Reçus fiscaux PDF (reçu fiscal loi 1901 pour les dons)
- Pièces jointes / justificatifs
- Export CERFA complet (bilan + compte de résultat)
- Import relevé bancaire CSV
