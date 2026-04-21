# Slice NDF — Don par abandon de créance

**Date** : 2026-04-21
**Branche** : `feat/portail-tiers-slice1-auth-otp` (même branche — continuité programme NDF)
**Statut** : spec validée (Consistency Gate PASS), prête pour `/plan` + `/build`

## 1. Intent Description

Lorsqu'un tiers soumet une note de frais sur le portail, il peut choisir de renoncer au remboursement et de transformer le montant en **don par abandon de créance**. Cette intention est portée par la NDF jusqu'à traitement comptable.

À la validation en back-office, le comptable crée **deux Transactions au statut `reglee`** : une Transaction dépense (Tiers = émetteur) et une Transaction Don du même montant sur la sous-catégorie désignée en usage `AbandonCreance`. Les deux écritures se neutralisent — aucun flux de trésorerie, aucune pièce à régler dans les listes en attente. La date du don est proposée par défaut à la **date de la NDF**, modifiable (bouton "aujourd'hui" déjà présent sur l'écran de comptabilisation).

Le tiers voit sa NDF dans un statut final distinct (`don_par_abandon_de_creances`), et un reçu fiscal CERFA matérialisant le don sera délivré (génération du PDF CERFA **hors scope de cette slice** — hook de données prêt pour une slice ultérieure).

**Règle** : abandon **tout ou rien** sur la NDF complète, jamais ligne par ligne.

## 2. User-Facing Behavior (BDD)

```gherkin
Feature: Don par abandon de créance sur note de frais

  Background:
    Given l'association a paramétré une sous-catégorie "771 Abandon de créance" en usage AbandonCreance
    And le tiers "Jean Martin" est authentifié sur le portail

  Scenario: Le tiers déclare un abandon au moment de la soumission
    Given Jean a saisi une NDF en brouillon avec 2 lignes pour un total de 120 €
    When il ouvre l'écran de soumission
    And il coche "Je renonce au remboursement et propose un don par abandon de créance"
    And il confirme la soumission
    Then la NDF passe au statut "soumise"
    And l'intention d'abandon est enregistrée sur la NDF
    And Jean voit un bandeau "Don par abandon de créance proposé — en attente de traitement"

  Scenario: Le tiers retire l'intention d'abandon avant soumission
    Given Jean a coché l'abandon dans l'écran de soumission
    When il décoche la case avant de confirmer
    Then la NDF se soumet normalement sans intention d'abandon

  Scenario: Le comptable valide une NDF avec intention d'abandon
    Given une NDF "soumise" de 120 € avec intention d'abandon de Jean Martin
    When le comptable ouvre la fiche NDF en back-office
    Then il voit un encart "Don par abandon de créance proposé"
    And le bouton principal est "Valider et constater l'abandon"
    When il clique "Valider et constater l'abandon"
    And il choisit une date d'effet du don (défaut = date de la NDF)
    And il confirme
    Then une Transaction dépense de 120 € est créée au statut "reglee" (Tiers = Jean)
    And une Transaction Don de 120 € est créée au statut "reglee" sur la sous-cat AbandonCreance (Tiers = Jean, date = date choisie)
    And la NDF passe au statut "don_par_abandon_de_creances"
    And aucune des deux transactions n'apparaît dans les listes "à régler"

  Scenario: Le comptable rejette l'intention d'abandon et valide normalement
    Given une NDF "soumise" de 120 € avec intention d'abandon de Jean Martin
    When le comptable clique "Valider sans constater l'abandon"
    Then une Transaction dépense de 120 € est créée (statut de règlement en attente — flux normal)
    And aucune Transaction Don n'est créée
    And la NDF passe au statut "validee"
    And Jean voit sur le portail que sa proposition d'abandon n'a pas été retenue

  Scenario: Le comptable rejette la NDF
    Given une NDF "soumise" avec intention d'abandon
    When le comptable rejette la NDF avec un motif
    Then la NDF passe au statut "rejetee"
    And aucune transaction n'est créée
    And l'intention d'abandon est sans effet

  Scenario: Aucune sous-catégorie AbandonCreance paramétrée
    Given l'association n'a pas désigné de sous-cat en usage AbandonCreance
    When le comptable ouvre une NDF avec intention d'abandon
    Then le bouton "Valider et constater l'abandon" est désactivé
    And un message invite à configurer l'usage dans Paramètres → Comptabilité → Usages

  Scenario: Le tiers consulte une NDF abandonnée
    Given une NDF passée au statut "don_par_abandon_de_creances"
    When Jean consulte sa NDF sur le portail
    Then il voit le statut "Don par abandon de créance — acté le {date}"
    And il voit le montant du don
```

## 3. Architecture Specification

### Modèle
- `notes_de_frais` : colonne `abandon_creance_propose` (bool, défaut `false`) — intention déclarée par le tiers.
- `notes_de_frais` : colonne nullable `don_transaction_id` (FK `transactions.id`) — lien vers la Transaction Don constatée (audit + affichage montant/date côté portail).
- Enum `App\Enums\StatutNoteDeFrais` : ajout case `DonParAbandonCreances = 'don_par_abandon_de_creances'` + libellé "Don par abandon de créance".

### Service métier — `NoteDeFraisValidationService`
Nouvelle méthode `validerAvecAbandonCreance(NoteDeFrais $ndf, ValidationData $data, string $dateDon): Transaction` dans `DB::transaction()` :
1. Résout la sous-cat `AbandonCreance` via `Association::sousCategoriesFor(UsageComptable::AbandonCreance)->sole()` — sinon `DomainException`.
2. Crée la Transaction dépense directement au statut `reglee` (variante du flux de validation existant — lignes NDF → lignes TX + copie PJ, mais `statut_reglement = Reglee`).
3. Crée la Transaction Don au statut `reglee` (ligne unique, sous-cat `AbandonCreance`, tiers = émetteur NDF, date = `$dateDon`, montant = total NDF, libellé = "Don par abandon de créance — NDF #{id}").
4. Passe la NDF au statut `DonParAbandonCreances`, renseigne `transaction_id` (dépense) et `don_transaction_id`.

La méthode `valider()` existante reste **inchangée**.

### Portail — `App\Livewire\Portail\NoteDeFrais\Form`
- Ajout propriété `public bool $abandonCreanceProposed = false`.
- Checkbox sur l'écran de soumission : "Je renonce au remboursement et propose un don par abandon de créance".
- Persisté dans `saveDraft()` et `submit()` via `NoteDeFraisService` (côté portail — distinct du validation service back-office).

### Portail — `App\Livewire\Portail\NoteDeFrais\Show`
- Affichage conditionnel : bandeau "Don par abandon de créance proposé — en attente" si `abandon_creance_propose && statut === Soumise`.
- Affichage final si `statut === DonParAbandonCreances` : "Don par abandon de créance — acté le {date}" + montant.

### Back-office — `App\Livewire\BackOffice\NoteDeFrais\Show`
- Encart conditionnel si `abandon_creance_propose` : "Don par abandon de créance proposé" + sous-cat désignée ou warning.
- Bouton primaire "Valider et constater l'abandon" (modale : date de don, défaut `$ndf->date`, bouton "Aujourd'hui"), désactivé si pas de sous-cat `AbandonCreance`.
- Bouton secondaire "Valider sans constater l'abandon" → flux `valider()` existant.
- Bouton "Rejeter" inchangé.

### Contraintes
- Multi-tenant : nouvelles requêtes via `TenantModel` / `TenantContext`.
- Policy NDF back-office (slice 3) : admin/comptable uniquement.
- Idempotence : la méthode lève `DomainException` si NDF pas en statut `Soumise`.
- Reversibilité : une NDF en statut `DonParAbandonCreances` ne peut pas être "dévalidée" (pas de régression v0).

## 4. Acceptance Criteria

| # | Critère | Condition de passage |
|---|---|---|
| AC1 | Intention capturée à la soumission | Colonne `abandon_creance_propose` persiste la valeur avant passage en `Soumise` |
| AC2 | Constat back-office crée 2 transactions atomiquement au statut `reglee` | Aucune des deux n'apparaît dans les listes "à régler" ; rollback complet en cas d'erreur |
| AC3 | Sous-cat AbandonCreance absente → pas de constat possible | Bouton désactivé + message explicite ; appel service lève `DomainException` |
| AC4 | Tout ou rien | Aucun chemin ne produit un abandon partiel ligne par ligne |
| AC5 | Statut visible côté tiers | Page NDF portail affiche `DonParAbandonCreances` avec date + montant |
| AC6 | Audit traçable | `don_transaction_id` non-nul ⇔ statut `DonParAbandonCreances` |
| AC7 | Isolation tenant | Comptable asso A ne peut pas constater abandon sur NDF asso B |
| AC8 | Suite de tests verte | `./vendor/bin/sail test` passe, 0 failure |
| AC9 | Pint vert | `./vendor/bin/sail pint --test` clean |
| AC10 | Libellé statut | UI affiche "Don par abandon de créance" (pas le snake_case) |
| AC11 | Date par défaut | Modale date d'effet = `date_note` NDF par défaut, modifiable, bouton "Aujourd'hui" |

## Hors scope

- **Génération PDF CERFA** d'abandon de créance — slice ultérieure, s'appuiera sur `don_transaction_id`.
- **Rattachement user↔tiers** (dette NDF héritée).
- **Notifications email** validation/rejet.
- **Reversibilité** d'un abandon constaté (transformation en règlement banque).
- **Abandon partiel** ligne par ligne.
