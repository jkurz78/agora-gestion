# Provisions de fin d'exercice — Intégration partie double

> **Branche** : `feat/compta-v5`
> **Priorité** : P0 (bloquant P1 — balance, grand livre, journaux)
> **Date** : 2026-06-18

## 1. Contexte

Les provisions de fin d'exercice (FNP — Factures Non Parvenues, CCA — Charges Constatées d'Avance, PCA — Produits Constatés d'Avance) vivent dans une table `provisions` séparée, sans aucun lien avec le socle partie double (`transactions` / `transaction_lignes` / `comptes`).

**Problème** : le `CompteResultatBuilder` en mode PD lit les classes 6 et 7 depuis `transaction_lignes`. Les provisions n'y figurent pas — elles disparaissent du résultat. La balance, le grand livre et les journaux (P1) les ignoreront aussi.

## 2. Décisions actées

| # | Décision | Justification |
|---|----------|---------------|
| D1 | La table `provisions` reste la source de vérité métier | L'écran CRUD, le wizard de clôture et la sémantique "provision de fin d'exercice" sont bien servis par un modèle dédié. |
| D2 | Chaque provision génère 2 Transactions PD liées (dotation + extourne) | Les écritures sont visibles dans balance, grand livre, journaux, compte de résultat — sans adaptation de ces vues. |
| D3 | Comptes 486/487 + 681/781 génériques | Suffisant pour 95 % des assos non fiscalisées. Pas de granularité 6815/6868. |
| D4 | Extourne générée en temps réel (au CRUD) | L'utilisateur voit ses extournes immédiatement. Pas besoin d'attendre la clôture. |
| D5 | L'extourne est générée même si l'exercice N+1 n'existe pas dans `exercices` | Les transactions dépendent de la date, pas d'une FK vers exercices. La balance N+1 la verra quand on consultera cet exercice. |
| D6 | Journal OD pour toutes les écritures de provision | Cohérent avec le PCG — les écritures d'inventaire passent dans le journal d'OD. |

## 3. Comptes PCG à seeder

4 nouveaux comptes système dans `SystemeSeeder::seed()` :

| N° PCG | Intitulé | Classe | Lettrable | Usage |
|--------|---------|--------|-----------|-------|
| 486 | Charges constatées d'avance | 4 | non | Contrepartie FNP/CCA (dépense provisionnée) |
| 487 | Produits constatés d'avance | 4 | non | Contrepartie PCA (recette provisionnée) |
| 681 | Dotations aux amort., dépréciations et provisions | 6 | non | Dotation charges (écriture N) |
| 781 | Reprises sur amort., dépréciations et provisions | 7 | non | Reprise produits (extourne N+1) |

Tous : `est_systeme = true`, `actif = true`, `pour_inscriptions = false`.

## 4. Schéma d'écritures

### 4.1 Provision de type dépense (FNP/CCA)

"On a consommé un service en N mais pas encore la facture" ou "On a payé d'avance une charge de N+1".

**Dotation** (exercice N, datée du 31 août N+1 = dernier jour de l'exercice) :

| Compte | Débit | Crédit | Libellé |
|--------|-------|--------|---------|
| 681 | montant | | Dotation : {libellé provision} |
| 486 | | montant | Dotation : {libellé provision} |

**Extourne** (exercice N+1, datée du 1er sept N+1 = premier jour de l'exercice suivant) :

| Compte | Débit | Crédit | Libellé |
|--------|-------|--------|---------|
| 486 | montant | | Extourne : {libellé provision} |
| 781 | | montant | Extourne : {libellé provision} |

### 4.2 Provision de type recette (PCA)

"On a encaissé en N mais le produit n'est pas acquis — il appartient à N+1".

**Dotation** (exercice N, datée du 31 août N+1) :

| Compte | Débit | Crédit | Libellé |
|--------|-------|--------|---------|
| 487 | montant | | Dotation : {libellé provision} |
| 781 | | montant | Dotation : {libellé provision} |

**Extourne** (exercice N+1, datée du 1er sept N+1) :

| Compte | Débit | Crédit | Libellé |
|--------|-------|--------|---------|
| 681 | montant | | Extourne : {libellé provision} |
| 487 | | montant | Extourne : {libellé provision} |

### 4.3 Résumé impact résultat

| Type | Dotation (N) | Extourne (N+1) |
|------|-------------|----------------|
| Dépense | +charge 681, neutre tréso (486) | −charge (486 D), +produit 781 |
| Recette | −produit (487 D), +produit 781 | +charge 681, neutre tréso (487 C) |

Résultat net sur N : la provision de dépense augmente les charges (681), la provision de recette diminue les produits nets (487 D annule une partie des 7xx, compensé par 781 C). L'extourne inverse le tout sur N+1.

## 5. Architecture

### 5.1 Migration

- Ajouter `provision_id` (FK nullable vers `provisions.id`, `ON DELETE SET NULL`) sur `transactions`.
- `ON DELETE SET NULL` plutôt que `CASCADE` : si une provision est soft-deletée, les transactions restent (et seront nettoyées par le service).

### 5.2 EcritureGenerator

Deux nouvelles méthodes :

```
pourProvisionDotation(Provision $provision): Transaction
pourProvisionExtourne(Provision $provision): Transaction
```

Pattern identique à `pourVirementInterne()` :
- Crée une `Transaction` (type = dépense pour dotation FNP, recette pour dotation PCA ; `type_ecriture = 'normale'` pour dotation, `'extourne'` pour extourne ; journal = OD)
- 2 `TransactionLigne` (débit/crédit) avec `compte_id`
- `equilibree = true`
- Assertions : `assertEquilibre`, `assertTenantCoherence`
- `PartieDoubleGuard::assertComplete()` appelé par le service appelant

**Type Transaction** : On utilise `TypeTransaction::Depense` ou `TypeTransaction::Recette` selon le sens de l'écriture. Pas besoin d'un nouveau case `Provision` dans l'enum — les provisions sont des écritures d'inventaire qui passent par les mêmes comptes 6xx/7xx.

### 5.3 ProvisionPDService

Nouveau service `App\Services\Compta\ProvisionPDService` :

```php
final class ProvisionPDService
{
    public function generer(Provision $provision): void
    {
        // 1. Supprimer les TX PD existantes liées
        Transaction::where('provision_id', $provision->id)->each(fn ($tx) => ...forceDelete)
        // 2. Générer dotation via EcritureGenerator
        // 3. Générer extourne via EcritureGenerator
        // 4. PartieDoubleGuard sur chaque
    }

    public function supprimer(Provision $provision): void
    {
        // Supprimer les TX PD liées (hard delete)
    }
}
```

### 5.4 Câblage ProvisionIndex

- `save()` : après `Provision::create()` ou `$provision->update()`, appeler `ProvisionPDService::generer($provision)`
- `delete()` : avant `$provision->delete()`, appeler `ProvisionPDService::supprimer($provision)`

### 5.5 Dates

- **Dotation** : `ExerciceService::dateRange($provision->exercice)['end']` = 31 août N+1
- **Extourne** : `ExerciceService::dateRange($provision->exercice + 1)['start']` = 1er sept N+1

Exercice = 1er sept → 31 août. Une provision pour `exercice = 2025` couvre sept 2025 → août 2026.
- Dotation datée du 2026-08-31
- Extourne datée du 2026-09-01

## 6. Impacts sur le code existant

### 6.1 CompteResultatBuilder — Aucun changement

Le path PD (`fetchClasseRowsPD`) lit les classes 6 et 7 depuis `transaction_lignes JOIN comptes`. Les comptes 681 (classe 6) et 781 (classe 7) seront automatiquement inclus.

### 6.2 FluxTresorerieBuilder — Adapter

Aujourd'hui il appelle `ProvisionService::totalProvisions()` / `totalExtournes()` qui lisent la table `provisions`. En mode PD, ces montants sont déjà dans les transactions (comptes 486/487 se compensent dans la trésorerie). Il faut s'assurer de ne pas double-compter. Option : sous `use_partie_double`, ne plus appeler `ProvisionService` et laisser les TX PD alimenter les totaux.

### 6.3 ProvisionService — Conservé pour l'écran CRUD

`provisionsExercice()` et `extournesExercice()` restent pour l'écran CRUD (lister les provisions). Les méthodes `totalProvisions()` / `totalExtournes()` deviennent redondantes avec le PD mais restent disponibles pour le path legacy.

### 6.4 ClotureWizard — Pas de changement fonctionnel

Le wizard affiche les provisions de l'exercice (via `ProvisionService`). Les extournes existent déjà sur N+1 — la clôture n'a rien à générer.

### 6.5 PartieDoubleGuard

Les transactions de provision sont des transactions normales avec `equilibree = true` et des lignes PD. Le guard les valide comme toute autre transaction.

### 6.6 Commande compta:assert-pd-complete

Les transactions de provision ont un `provision_id` non null. Elles ne sont pas HelloAsso. Le guard les vérifie normalement.

## 7. Critères d'acceptation

1. **AC-1** : Créer une provision dépense → 2 transactions PD générées (dotation 681 D / 486 C + extourne 486 D / 781 C)
2. **AC-2** : Créer une provision recette → 2 transactions PD générées (dotation 487 D / 781 C + extourne 681 D / 487 C)
3. **AC-3** : Modifier une provision → les 2 TX PD sont recréées avec les nouveaux montants
4. **AC-4** : Supprimer une provision → les 2 TX PD sont hard-deletées
5. **AC-5** : Les comptes 486, 487, 681, 781 sont seedés pour toutes les associations
6. **AC-6** : `CompteResultatBuilder` en mode PD affiche les dotations dans les charges (681) et produits (781)
7. **AC-7** : `PartieDoubleGuard::assertComplete()` passe sur les 2 TX de provision
8. **AC-8** : `compta:assert-pd-complete` valide les TX de provision
9. **AC-9** : `FluxTresorerieBuilder` ne double-compte pas les provisions en mode PD
10. **AC-10** : La dotation est datée du dernier jour de l'exercice, l'extourne du premier jour de l'exercice suivant
11. **AC-11** : Suite de tests verte, aucune régression

## 8. Hors scope

- Pas de nouveau type dans `TypeTransaction` (les provisions utilisent Depense/Recette)
- Pas de modification de l'IHM de l'écran Provisions (sauf câblage service PD)
- Pas de bilan (actif/passif) — les comptes 486/487 apparaîtront dans la balance mais pas de vue bilan dédiée
- Pas de numérotation par journal (déjà classé P2)
