# Audit signe négatif — Slice 0 (préalable au Slice 1 Extourne)

**Date** : 2026-04-30
**Statut** : spec PASS (consistency gate ✅), prête pour `/plan`
**Programme** : Annulation de facture & extourne
**Périmètre** : Slice 0 du programme. Audite et fiabilise tout le code de l'application qui suppose `montant >= 0` ou `montant > 0` sur `Transaction` et `TransactionLigne`, en préparation de l'introduction des montants négatifs (extournes) au Slice 1. Aucune fonctionnalité métier nouvelle. Livre : un document d'audit, des tests de régression sur les sommations, et un patch isolé là où les hypothèses cassent.
**Préalables** : v4.1.9 (main), aucune autre fonctionnalité ne dépend de S0.

---

## 1. Intent Description

**Quoi.** Recenser exhaustivement les sites du code (validations de saisie, builders de rapports, sommations, exports, affichages) qui assument que `Transaction.montant` et `TransactionLigne.montant` sont positifs, puis :

- **Confirmer** par test que les sommations naturelles (ex. `sum('montant')`) intègrent correctement un mix positif/négatif sans bug
- **Patcher** localement les sites qui cassent (par exemple un `abs()` indu, un filtre `where('montant', '>', 0)` injustifié, ou une validation Livewire qui exclut les négatifs)
- **Durcir** les validations de **saisie manuelle** sur tous les formulaires utilisateur pour refuser explicitement les montants négatifs avec un message clair ("Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante.")

À l'issue du Slice 0, tous les rapports, KPI, écrans et exports doivent fonctionner correctement avec un mélange de transactions positives et négatives en base — même si aucune extourne n'est encore créée par l'application (le Slice 1 ouvrira la vanne).

**Pourquoi.** Le Slice 1 introduit la primitive d'extourne, qui crée des `Transaction` à `montant < 0`. Sans préparation, l'introduction de montants négatifs en production cassera silencieusement des sommations dans les rapports et dashboards. L'audit en slice séparé permet d'isoler le risque (livraison sans nouvelle fonctionnalité visible utilisateur), de revoir le code en détail sans la pression de la livraison fonctionnelle, et de fournir au Slice 1 une base solide où l'unique nouveau site à fiabiliser est le service d'extourne lui-même.

**Quoi ce n'est pas.** Pas de service d'extourne ni de bouton UI. Pas de table `extournes`. Pas de modification du modèle `Transaction` autre que les validations renforcées. Pas de migration sur les données existantes (aucune transaction négative en base à la fin du Slice 0).

**Périmètre Slice 0.**
- Document d'audit `docs/audit/2026-04-30-signe-negatif.md` recensant **tous** les sites consultés, avec verdict (OK / Patché) et lien fichier:ligne
- Tests Pest Feature de régression sur les builders de rapports avec un dataset incluant transactions négatives factices (créées en seed de test uniquement, pas en migration)
- Patches isolés sur les sites qui cassent (1 commit par site, message explicite)
- Validation `montant > 0` durcie sur tous les formulaires de saisie manuelle utilisateur, avec message d'erreur uniforme

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: Robustesse aux montants négatifs (préalable extourne)
  Pour préparer l'introduction des extournes (Slice 1) sans casser les rapports existants
  Le système doit accepter les transactions à montant négatif en base et les sommer correctement,
  tout en refusant la saisie manuelle utilisateur de montants négatifs.

  Scénario: Saisie manuelle d'un montant négatif refusée — TransactionForm
    Étant donné le formulaire de transaction (TransactionForm)
    Quand je saisis un montant -50 €
    Alors la validation refuse avec le message "Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante."

  Scénario: Saisie manuelle d'un montant négatif refusée — toutes les voies
    Étant donné les formulaires : TransactionUniverselle (création), FactureEdit (lignes manuelles), ReglementTable, BackOfficeNDF, VirementInterneForm, RemiseBancaireList, ProfileNDF
    Quand je saisis un montant négatif sur l'un d'eux
    Alors la validation refuse uniformément avec le même message

  Scénario: Compte de résultat correct avec transactions négatives
    Étant donné un exercice 2026 contenant 1 recette +100 € et 1 recette -100 € sur la même sous-catégorie
    Quand je consulte le compte de résultat de 2026
    Alors la sous-catégorie affiche un total de 0 €
    Et chaque écriture est listée individuellement dans le détail

  Scénario: Flux de trésorerie correct avec transactions négatives pointées banque
    Étant donné un exercice 2026 avec 1 dépense -50 € (négative, factice) pointée banque
    Quand je consulte le flux de trésorerie
    Alors le solde reflète l'écriture correctement

  Scénario: Dashboard recettes correct
    Étant donné des transactions positives et négatives dans le mois
    Quand je consulte le dashboard
    Alors les KPIs (total recettes, total dépenses) somment les négatifs sans les ignorer ni les passer en abs()

  Scénario: Export Excel correct
    Étant donné un export "Journal des transactions" sur un exercice avec négatifs
    Alors les négatifs apparaissent avec signe explicite et sont sommés correctement dans les totaux Excel

  Scénario: PDF compte de résultat correct
    Étant donné un PDF compte de résultat sur un exercice avec négatifs
    Alors les totaux affichés sont les sommes nettes (avec négatifs inclus), pas les sommes des valeurs absolues

  Scénario: Transaction négative en base ne casse pas les écrans existants
    Étant donné une transaction recette -100 € insérée directement en base (cas test)
    Quand je consulte tous les écrans listant des transactions (Transactions universelles, par compte, créances, par tiers, par catégorie)
    Alors aucune erreur ni warning ; le montant négatif s'affiche correctement (signe / couleur)

  Scénario: CsvImportService refuse les négatifs
    Étant donné un fichier CSV contenant une ligne à montant -50 €
    Quand j'importe le fichier
    Alors la ligne est rejetée avec un message d'erreur explicite, sans bloquer les autres lignes
```

---

## 3. Architecture Specification

### 3.1 Cibles d'audit (liste exhaustive à confirmer pendant l'implémentation)

**Validations de saisie (durcissement obligatoire)** :

| Site | Fichier | Action |
|---|---|---|
| TransactionForm | `app/Livewire/Transactions/TransactionForm.php` | Règle `gt:0` sur `montant`, message uniforme |
| TransactionUniverselle (création) | `app/Livewire/TransactionUniverselle.php` | Idem |
| FactureEdit (lignes manuelles) | `app/Livewire/Factures/FactureEdit.php` | Règle `gt:0` sur `prix_unitaire` et `quantite` |
| ReglementTable | `app/Livewire/Reglements/ReglementTable.php` | Idem |
| BackOfficeNDF | `app/Livewire/BackOffice/NDF/*` | Idem |
| VirementInterneForm | `app/Livewire/Virements/VirementInterneForm.php` | Idem |
| RemiseBancaireList | `app/Livewire/Remises/RemiseBancaireList.php` | Idem |
| ProfileNDF (portail tiers) | `app/Livewire/Portail/Ndf/*` | Idem |
| CsvImportService | `app/Services/CsvImportService.php` | Validation ligne rejetée avec log |

**Builders de rapports (audit + tests)** :

| Site | Fichier | Audit |
|---|---|---|
| CompteResultatBuilder | `app/Services/Rapports/CompteResultatBuilder.php` | Sommations naturelles, vérifier aucun `abs()` indu |
| FluxTresorerieBuilder | `app/Services/Rapports/FluxTresorerieBuilder.php` | Idem |
| JournalTransactionsBuilder | `app/Services/Rapports/JournalTransactionsBuilder.php` | Affichage signe explicite, total signé |
| TransactionUniverselleService | `app/Services/TransactionUniverselleService.php` | Filtres et sommations |
| Dashboard KPIs | `app/Livewire/Dashboard/*` + services | Totaux du mois / exercice |
| Super-admin KPIs | `app/Livewire/SuperAdmin/Dashboard/*` | Totaux multi-tenant |
| Exports Excel | `app/Exports/*` | Sommations finales |
| PDFs (compte de résultat, flux trésorerie, CERFA, journal) | `app/Services/Pdf/*` ou équivalent + vues `resources/views/pdf/*` | Affichage et sommations |

**Affichage / formattage** :

| Site | Audit |
|---|---|
| Helpers `formatMontant()` ou équivalent | Gérer le signe (négatif en rouge ?) |
| Composants Blade table-dark | Vérifier que les colonnes "data-sort" trient correctement les négatifs (lexicographique vs numérique) |

### 3.2 Tests de régression

Suite Pest Feature dédiée `tests/Feature/Audit/SigneNegatifTest.php` :

- Pour chaque builder de rapport : un test qui injecte un dataset avec mix positif/négatif et asserte les sommations attendues
- Pour chaque formulaire de saisie : un test qui soumet un montant négatif et asserte le rejet
- Test global : insertion directe de transactions négatives en base → tous les écrans répondent sans erreur

### 3.3 Modifications de schéma

**Aucune** au Slice 0. Aucune migration. Aucun ajout de colonne. Le Slice 0 est une opération de fiabilisation pure.

### 3.4 Frontière avec l'existant

| Fonctionnalité | Impact |
|---|---|
| Saisie manuelle | Validation durcie (uniforme) |
| Import CSV | Rejet des négatifs (existant probablement, à confirmer) |
| Rapports | Aucun changement de comportement, mais tests de régression ajoutés |
| Slice 1 (Extourne) | Slice 0 est son préalable obligatoire |

### 3.5 Multi-tenant

Aucun impact spécifique. Le scope global `TenantScope` n'est pas concerné par le signe.

---

## 4. Acceptance Criteria

| # | Critère |
|---|---|
| AC-1 | Document `docs/audit/2026-04-30-signe-negatif.md` livré, exhaustif, chaque site coché OK ou patché |
| AC-2 | Suite Pest verte, +tests d'audit (~15-20 nouveaux tests) |
| AC-3 | 9 formulaires utilisateur identifiés rejettent uniformément les montants négatifs avec le message standardisé |
| AC-4 | Insertion directe en base d'une transaction négative : tous les écrans / rapports / exports répondent correctement |
| AC-5 | Compte de résultat, flux trésorerie, dashboard, super-admin KPIs : sommations vérifiées par test sur dataset mixte |
| AC-6 | PSR-12 / Pint vert |
| AC-7 | `declare(strict_types=1)` + `final class` sur tout nouveau fichier |
| AC-8 | Aucune régression sur les tests existants (3170+ verts) |
| AC-9 | Aucune fonctionnalité utilisateur visible nouvelle (Slice 0 = fiabilisation pure) |

---

## 5. Consistency Gate

- [x] Intent unambiguous — la portée est strictement audit + durcissement, pas de fonctionnalité nouvelle
- [x] Chaque comportement de l'Intent a un scénario BDD
- [x] L'architecture §3 contraint l'implémentation à un audit + patches localisés, sans schéma neuf
- [x] Vocabulaire cohérent : "audit", "durcissement", "validation" partout
- [x] Aucun artefact ne contredit un autre
- [x] Frontière nette avec Slice 1 (Slice 0 ne crée aucune extourne)

**Verdict : ✅ PASS — prête pour `/plan` (Slice 0).**

---

## 6. Recommandation post-livraison

`/clear` recommandé entre la livraison du Slice 0 et le démarrage du Slice 1 : le contexte Slice 0 (lecture exhaustive du code, recensement de sites) ne sert plus directement au Slice 1 (qui ajoute une primitive isolée). Le document d'audit livré tient lieu de mémoire si une question remonte sur un site déjà audité.
