# Spec — Évolutions opérations : encadrement prévisionnel, compte de résultat avec prévisions, communication encadrants élargie

**Date** : 2026-05-13
**Branche cible** : `feat/operations-encadrement-previsionnel` (à créer)
**Auteur** : Jurgen Kurz (cadrage), Claude (rédaction)

## 1. Contexte

L'onglet **Encadrement** d'une opération (composant `AnimateurManager`) affiche aujourd'hui une matrice tiers × séance lue exclusivement depuis les `transaction_lignes` de dépenses réalisées. Trois manques structurels en découlent :

1. **Aucun prévisionnel** : impossible de planifier ce qu'on attend de chaque encadrant (montants, sous-catégories, ventilation par séance) avant que les factures n'arrivent.
2. **Tiers fantômes** : un encadrant ajouté à la main vit dans l'état Livewire (`addedTiersIds`) et disparaît dès qu'il n'a pas reçu de transaction.
3. **Encadrants bénévoles invisibles** : un encadrant qui ne facture jamais (bénévole) n'apparaît jamais dans la liste de destinataires de l'onglet **Communication**, qui dérive aussi des transactions réalisées.

Par ailleurs, le rapport **Compte de résultat opérations** est aujourd'hui borné au réalisé. Le pilotage budgétaire opérationnel manque d'une vue prévisionnel / réalisé / écart.

## 2. Objectifs

- Pouvoir saisir et persister un **prévisionnel d'encadrement** par tiers × sous-catégorie × séance.
- Restituer ce prévisionnel à côté du réalisé dans le rapport **Compte de résultat opérations** et dans le **bilan financier** de l'onglet Infos.
- Inclure les encadrants prévus (mais sans facture) dans la liste des destinataires de l'onglet **Communication**.

## 3. Non-objectifs

- **Pas de comptabilisation automatique** depuis le prévisionnel encadrement (≠ Règlement). La création des dépenses reste manuelle via la modale OCR existante.
- **Pas de prévisionnel de recettes côté Règlement** : la structure existante (`reglements.montant_prevu`) est déjà notre source ; rien à modifier sur le composant `ReglementTable`.
- **Pas d'export PDF / Excel des prévisions encadrement** par lui-même : seul l'export du compte de résultat est étendu (toggle prévisionnel).
- **Pas de mode de paiement** ni de **remise** sur l'encadrement (asymétrie volontaire avec `reglements`).

## 4. Périmètre fonctionnel

### 4.1 Sujet 1 — Onglet Encadrement symétrique de Règlement

#### 4.1.1 Modèle de données

Nouvelle table `encadrement_previsions` :

| Colonne | Type | Contraintes |
|---|---|---|
| `id` | bigint unsigned | PK |
| `association_id` | bigint unsigned | FK (multi-tenant) |
| `operation_id` | bigint unsigned | FK `operations.id`, cascadeOnDelete |
| `tiers_id` | bigint unsigned | FK `tiers.id`, cascadeOnDelete |
| `sous_categorie_id` | bigint unsigned | FK `sous_categories.id`, restrictOnDelete |
| `seance_id` | bigint unsigned nullable | FK `seances.id`, cascadeOnDelete |
| `montant_prevu` | decimal(10,2) | default 0 |
| `created_at` / `updated_at` | timestamps | |

Décisions de schéma :

- **`seance_id` est NOT NULL au MVP**. Le besoin "prévision hors séance" n'a pas été remonté et compliquerait l'unique constraint (MySQL traite NULL comme distinct). Le réalisé "hors séance" reste affiché comme aujourd'hui (sous-info dans la matrice).
- Unique `(operation_id, tiers_id, sous_categorie_id, seance_id)`.
- Index `(operation_id, tiers_id)` pour la matrice.
- Modèle `App\Models\EncadrementPrevision` étend `App\Models\TenantModel` (scope global fail-closed sur `association_id`).

#### 4.1.2 UI cible

Composant : `AnimateurManager` réécrit autour de la matrice symétrique de `ReglementTable`.

Structure de la matrice :

```
                          | S1  | S2  | … | Sn  | Total
---------------------------------------------------------
[Tiers A]                 |  P  |  P  |   |  P  | ΣP
                          |  R  |  R  |   |  R  | ΣR
  └─ Sous-catégorie X     |  p  |  p  |   |  p  | Σp     ← cellule éditable inline
                          |  r  |  r  |   |  r  | Σr     ← réalisé (lecture seule)
  └─ Sous-catégorie Y     |  p  |  p  |   |  p  | Σp
                          |  r  |  r  |   |  r  | Σr
  [+ Ajouter une ligne]                                  ← bouton sous chaque tiers
---------------------------------------------------------
[Tiers B]                 …
---------------------------------------------------------
Total                     | ΣS1P| …                | Σ
                          | ΣS1R| …                | Σ
Écart                     |  Δ  | …                | Δ
```

- **Cellule prévu** : éditable inline, même UX que `ReglementTable` (clic → input, blur → save).
- **Cellule réalisé** : lecture seule, lien clic-pour-éditer la transaction (UX actuelle conservée).
- **Bouton "+ Ajouter une ligne"** sous chaque encadrant : ouvre un mini-sélecteur de sous-catégorie (filtre `depense`) et crée une nouvelle ligne `encadrement_previsions` (avec `montant_prevu = 0` sur toutes les séances).
- **Bouton "Recopier 1re séance"** par ligne sous-catégorie (cohérent avec Reglement).
- **Bouton "✕"** pour supprimer une ligne sous-catégorie (uniquement si la ligne n'a aucun réalisé associé — sinon désactivé avec tooltip).
- **Ajout d'un encadrant** : conservé en bas du tableau via `livewire:tiers-autocomplete`. Au lieu de pousser dans `$addedTiersIds`, on crée une première ligne vide en base (`encadrement_previsions` avec `montant_prevu = 0`, sans sous-catégorie ?). 
  - **Décision** : impossible d'avoir une ligne sans `sous_categorie_id` (FK obligatoire). Donc le bouton "Ajouter un encadrant" sélectionne le tiers, puis affiche un sélecteur de sous-catégorie inline ; les deux étapes créent la première ligne en base.

#### 4.1.3 Source du réalisé

Logique inchangée : `transaction_lignes` Dépense où `operation_id = $this->operation->id`. La présence d'une transaction réalisée sur un tiers qui n'a **pas** de prévision crée néanmoins une ligne d'affichage (sinon on perdrait l'info actuelle). Concrètement :

- Pour chaque cellule `(tiers, sous_cat, séance)`, on cherche la prévision ; absente = `montant_prevu = 0`.
- Pour chaque cellule réalisée sans prévision correspondante, on **affiche quand même** la sous-catégorie côté Réalisé (création d'une "ligne fantôme" en affichage uniquement).

Cette logique de fusion est portée par un nouveau service `EncadrementMatrixBuilder` qui retourne la structure de la matrice (analogue à `buildMatrixData` actuel, étendue).

#### 4.1.4 Suppression du mécanisme `addedTiersIds`

Plus utile dès lors que les encadrants sont persistés en base. Retirer la propriété et la logique associée.

### 4.2 Sujet 2 — Compte de résultat opérations avec montants prévisionnels

#### 4.2.1 Toggles par défaut

`RapportCompteResultatOperations` :
- `parSeances` : default **`true`**.
- `parTiers` : default **`true`**.
- Nouveau `previsionnel` : default `false`, sync URL `prev`.

Les anciennes URL `?seances=0&tiers=0` continuent de fonctionner (les valeurs explicites priment sur le défaut).

#### 4.2.2 Affichage

Quand `previsionnel = true`, chaque cellule de montant (catégorie / sous-catégorie / tiers / total) devient un mini-bloc empilé sur 3 lignes :

```
1 234,56 €    ← Prévu (gris)
1 100,00 €    ← Réalisé (noir, gras)
   -134,56    ← Écart (rouge si négatif, vert si positif)
```

Variantes selon les toggles :

- `parSeances=true` : la triplette s'applique dans chaque colonne séance + total.
- `parSeances=false` + `previsionnel=true` : on ajoute **3 colonnes à droite** de la colonne Montant existante : `Prévu`, `Réalisé`, `Écart`. (Décision : 3 colonnes plutôt que 2, comme le bilan ; plus lisible que d'écraser la colonne Montant.)
  - **Note** : "Montant" devient redondant avec "Réalisé" → on remplace la colonne Montant par Réalisé/Prévu/Écart quand `previsionnel=true && parSeances=false`.

#### 4.2.3 Source des prévisions

- **Charges** : `encadrement_previsions` agrégées par sous-catégorie / catégorie / tiers / séance, sur les `operationIds` filtrés.
- **Produits** : `reglements.montant_prevu` joint à `participants` → `tiers` → opération.
  - Sous-catégorie : `operations.type_operation.sous_categorie_id` (une seule par opération).
  - Catégorie : remontée depuis la sous-catégorie.
  - Séance : `reglements.seance_id` → `seances.numero`.

Nouvelle méthode `CompteResultatBuilder::compteDeResultatOperations()` étendue avec un 5e paramètre `bool $previsionnel`. Quand `true`, le builder fait **2 jeux de requêtes** (réalisé inchangé + prévisions) et les fusionne dans la même hiérarchie.

#### 4.2.4 Export PDF / Excel

`exportUrl()` transmet `prev=1` quand activé. Les builders d'export PDF / Excel doivent rendre les 3 sous-lignes (Prévu / Réalisé / Écart) — adaptation des blade `pdf/rapport-operations.blade.php` et de l'export Excel du même rapport.

### 4.3 Sujet 3 — Communication : encadrants élargis aux prévisions

`OperationCommunication::getEncadrantsTiers()` doit retourner l'**union** des tiers :

- Tiers ayant au moins une transaction Dépense dont une ligne pointe sur l'opération (logique actuelle).
- Tiers ayant au moins une ligne `encadrement_previsions` sur l'opération.

Implémentation : ajout d'une 2e requête sur `encadrement_previsions`, fusion par `unique()` puis `Tiers::whereIn(...)`.

Pas d'autre changement (sélection par défaut, opt-out, templates, etc. conservés).

### 4.4 Sujet 4 — Bilan financier (onglet Infos)

Tableau actuel : "Total dépenses / Total recettes / Total dons / Solde", uniquement en réalisé.

**Évolution** : ajout de 2 colonnes supplémentaires "Planifié" et "Écart" (avec couleurs cohérentes : rouge si dépense réalisée > planifiée, vert sinon ; inverse pour les recettes).

Tableau cible :

| Poste | Planifié | Réalisé | Écart |
|---|---:|---:|---:|
| Total dépenses | x € | x € | x € |
| Total recettes | x € | x € | x € |
| Total dons | — | x € | — |
| **Solde** | x € | x € | x € |

- **Planifié dépenses** = Σ `encadrement_previsions.montant_prevu` pour l'opération.
- **Planifié recettes** = Σ `reglements.montant_prevu` joints aux participants de l'opération.
- **Planifié dons** : non applicable (les dons ne sont pas planifiés à ce stade), cellule "—".
- **Solde planifié** = Planifié recettes − Planifié dépenses.

Logique portée par `OperationDetail::render()`.

## 5. Architecture

### 5.1 Nouveaux artefacts

| Type | Path | Rôle |
|---|---|---|
| Migration | `database/migrations/2026_05_13_create_encadrement_previsions_table.php` | Schéma DB |
| Modèle | `app/Models/EncadrementPrevision.php` | Eloquent (extends `TenantModel`) |
| Service | `app/Services/EncadrementMatrixBuilder.php` | Fusion prévu / réalisé pour la matrice |
| Tests Pest | `tests/Feature/Livewire/AnimateurManager*`, `tests/Unit/Services/EncadrementMatrixBuilderTest.php`, `tests/Feature/Livewire/RapportCompteResultatOperationsPrevisionnel*`, `tests/Feature/Livewire/OperationCommunicationEncadrantsPrevisionnelTest.php` | Couverture |

### 5.2 Artefacts modifiés

| Path | Changement |
|---|---|
| `app/Livewire/AnimateurManager.php` | Réécriture : persistance prévisions, suppression `addedTiersIds`, intégration `EncadrementMatrixBuilder` |
| `resources/views/livewire/animateur-manager.blade.php` | Stack P/R par ligne, boutons ajouter ligne / recopier / supprimer ligne |
| `app/Livewire/RapportCompteResultatOperations.php` | Toggles par défaut `true`, nouveau toggle `previsionnel`, passe au service |
| `resources/views/livewire/rapport-compte-resultat-operations.blade.php` | Stack 3 lignes par cellule quand toggle ON |
| `app/Services/Rapports/CompteResultatBuilder.php` | Nouveau paramètre `$previsionnel`, jeux de requêtes prévision (charges + produits) |
| `app/Services/RapportService.php` | Pass-through du nouveau paramètre |
| `app/Http/Controllers/RapportExportController.php` | Pass-through du paramètre `prev` (méthodes `xlsxOperations` et `pdfOperationsData`) |
| `resources/views/pdf/rapport-operations.blade.php` | Stack 3 lignes |
| Service export Excel rapport opérations | Stack 3 lignes |
| `app/Livewire/OperationCommunication.php` | `getEncadrantsTiers()` étendue (union prévisions) |
| `app/Livewire/OperationDetail.php` | Calcul des montants planifiés, passage au blade |
| `resources/views/livewire/operation-detail.blade.php` | Tableau bilan à 4 colonnes |

### 5.3 Multi-tenant

- `encadrement_previsions.association_id` rempli automatiquement via `TenantModel`.
- Tous les services lisent via Eloquent (scope global appliqué) ou via `TenantContext::currentId()` pour les requêtes brutes du builder.

### 5.4 Permissions

Cohérence avec l'existant :
- Lecture : tous les rôles ayant accès à l'opération.
- Écriture (édition cellule prévisionnelle, ajout ligne, suppression ligne, ajout encadrant) : rôles avec `canWrite(Espace::Gestion)` (cf. `ReglementTable::getCanEditProperty`).

## 6. Stratégie de tests

TDD strict, un step = un cycle RED-GREEN-REFACTOR. Couverture cible :

### 6.1 Tests unitaires

- `EncadrementMatrixBuilder` : tiers avec prévisions seules, tiers avec réalisé seul, tiers mixte, sous-catégorie présente uniquement en réalisé (ligne fantôme).
- `CompteResultatBuilder::compteDeResultatOperations(previsionnel=true)` : 8 combinaisons (parSeances × parTiers × previsionnel).

### 6.2 Tests Livewire feature

- Ajout d'un encadrant → ligne créée en DB avec sous-catégorie choisie.
- Édition inline d'un montant prévu → persistance en DB.
- Suppression d'une ligne sous-catégorie sans réalisé → OK ; avec réalisé → bouton désactivé.
- Suppression d'un encadrant supprime ses prévisions (cascade).
- Filtre fail-closed multi-tenant : prévisions d'une autre asso jamais visibles.

### 6.3 Tests de non-régression

- `RapportCompteResultatOperationsTest` : URL sans paramètres → maintenant `parSeances=true, parTiers=true` par défaut (à mettre à jour, signal positif).
- `OperationCommunicationTest` : encadrants sans transaction mais avec prévision → présents.
- Bilan financier onglet Infos : tableau 4 colonnes correct.

## 7. Migration des données existantes

Aucune. Les opérations en cours n'ont pas de prévisions ; la table est vide, le réalisé continue de s'afficher. Les utilisateurs saisissent à la demande.

Pas de backfill non plus depuis les transactions existantes : ce serait factice (prévu = réalisé) et masquerait le vrai écart.

## 8. Risques et points de vigilance

1. **Perf compte de résultat** : doubler les requêtes (réalisé + prévisionnel) ne pose pas de problème à ce stade (les volumes sont raisonnables), mais à vérifier sur la prod après MEP.
2. **Lisibilité du stack 3-lignes** : à valider visuellement après implémentation. Si trop chargé, fallback possible vers une option mode "compact" (Prévu en hover-tooltip). Hors scope MVP.
3. **Cascade des suppressions** : suppression d'une opération doit cascade les prévisions (déjà géré par FK cascadeOnDelete) ; suppression d'un tiers idem.
4. **Encadrants apparaissant deux fois** dans la liste Communication : la `unique()` sur les `tiers_id` règle le doublon.
5. **Tests existants `AnimateurManager`** : à réécrire intégralement vu la refonte. Compter ce coût dans le plan.

## 9. Découpage proposé (à valider via /plan)

Un seul slice cohérent, en TDD :

1. Migration + modèle `EncadrementPrevision` (RED → GREEN, test smoke).
2. Service `EncadrementMatrixBuilder` (RED → GREEN avec fixtures).
3. Refonte `AnimateurManager` (Livewire feature tests) — cellules éditables, ajout/suppression de ligne, ajout encadrant.
4. Extension `CompteResultatBuilder::compteDeResultatOperations` (previsionnel).
5. Refonte view `rapport-compte-resultat-operations.blade.php` (stack 3-lignes).
6. Toggles par défaut `parSeances/parTiers = true` + nouveau toggle.
7. Exports PDF / Excel rapport opérations.
8. `OperationCommunication::getEncadrantsTiers()` étendue.
9. Bilan financier onglet Infos (4 colonnes).
10. Pre-PR quality gate (Pint + Pest 100% vert + revue).

## 10. Critères d'acceptation

- ✅ Je peux ajouter un encadrant sur une opération, lui ajouter 2 sous-catégories différentes (ex. "Encadrement" + "Frais de déplacement"), et saisir un montant prévu par séance pour chacune.
- ✅ Le montant prévu est persisté ; je peux fermer / rouvrir l'onglet et le retrouver.
- ✅ Si je supprime un encadrant ou une opération, les prévisions associées sont supprimées.
- ✅ Le rapport Compte de résultat opérations s'ouvre par défaut avec "Séances en colonnes" + "Tiers en lignes" cochés.
- ✅ En activant "Montants prévisionnels", chaque cellule affiche Prévu / Réalisé / Écart empilés.
- ✅ Un encadrant bénévole (uniquement prévisionnel, jamais de transaction) apparaît dans la liste de destinataires de l'onglet Communication.
- ✅ Le bilan de l'onglet Infos affiche 4 colonnes (poste / Planifié / Réalisé / Écart).
- ✅ Pest 100% vert, Pint clean.
