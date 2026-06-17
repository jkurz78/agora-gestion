# G.2 — Garde bloquante non-échappement partie double

**Branche** : `feat/compta-v5`
**Date** : 2026-06-17
**Statut** : Spec validée

## 1. Contexte

Capstone Phase 2 : une fois `config('compta.use_partie_double') = true`, aucune transaction ne doit pouvoir être créée sans écritures PD équilibrées. Aujourd'hui 3 services sur 6 ne posent pas `equilibree = true` et n'assertent rien en sortie de `DB::transaction()`. Le backfill convertit les transactions existantes ; cette garde empêche les nouvelles de passer à travers.

## 2. PartieDoubleGuard

### 2.1 Contrat

Méthode statique `PartieDoubleGuard::assertComplete(Transaction $tx)` dans `app/Services/Compta/PartieDoubleGuard.php`.

Appelée **en fin de `DB::transaction()`**, après la création/modification de la transaction et de ses lignes PD.

### 2.2 Logique

```
si config('compta.use_partie_double') === false → return (no-op)
si $tx->helloasso_order_id !== null → return (chemin différé accepté)
si $tx->equilibree !== true → throw
si aucune ligne avec compte_id non null → throw
si somme(debit) ≠ somme(credit) → throw
```

L'exception est une `PartieDoubleIncompleteException` (runtime, fait rollback le `DB::transaction()` englobant).

### 2.3 Exemptions

| Chemin | Raison | Traitement |
|--------|--------|-----------|
| HelloAsso (`helloasso_order_id`) | Sync externe, PD différé via `TransactionConverter` | Exempté |
| PD off (`use_partie_double = false`) | Mode legacy, pas de PD | Guard inactif |

Pas d'autre exemption. Tous les autres chemins DOIVENT produire des écritures équilibrées.

## 3. Câblage dans les services

### 3.1 Services à câbler

| Service | Méthode | Appel guard |
|---------|---------|-------------|
| `TransactionService` | `create()` | Après `enrichirPartieDouble()`, dans le `DB::transaction` |
| `TransactionService` | `update()` | Après `enrichirPartieDouble()`, dans le `DB::transaction` |
| `FactureService` | `genererTransactionDepuisLignesManuelles()` | Après l'appel `pourRecetteACredit()`, dans le `DB::transaction` |
| `ReglementOperationService` | `comptabiliserSeance()` | Après `enrichirCreancePartieDouble()`, dans le `DB::transaction` |
| `VirementInterneService` | `create()` | Après `pourVirementInterne()`, dans le `DB::transaction` |
| `TransactionExtourneService` | `extourner()` | Après `creerTransactionMiroir()`, dans le `DB::transaction` |

`EcritureGenerator` n'est pas câblé directement — il asserte déjà ses propres invariants (équilibre, tenant, tiers) et est appelé par les services ci-dessus.

### 3.2 Fix FactureService et ReglementOperationService

Ces deux services ne posent pas `equilibree = true` quand la PD réussit. Corriger :

- `FactureService::genererTransactionDepuisLignesManuelles()` : poser `$tx->update(['equilibree' => true])` après le `pourRecetteACredit()` réussi.
- `ReglementOperationService::comptabiliserSeance()` : poser `$tx->update(['equilibree' => true])` après `enrichirCreancePartieDouble()` réussi.

## 4. Commande artisan

`compta:assert-pd-complete [--check] [--fix]`

- `--check` : vérifie toutes les transactions non-HelloAsso du tenant courant. Exit 1 si divergence. Pour CI/recette.
- `--fix` : tente de ré-enrichir les transactions incomplètes via `TransactionConverter`.
- Sans flag : affiche un rapport (count OK / KO).

Pattern identique à `compta:reconcilier-statuts`.

## 5. Exception

```php
namespace App\Exceptions\Compta;

final class PartieDoubleIncompleteException extends \RuntimeException
{
    public static function sansLignes(int $transactionId): self
    {
        return new self("Transaction #{$transactionId} : mode PD actif mais aucune ligne comptable (compte_id). Vérifiez que enrichirPartieDouble() a été appelé.");
    }

    public static function desequilibree(int $transactionId, string $debit, string $credit): self
    {
        return new self("Transaction #{$transactionId} : PD déséquilibrée (debit={$debit}, credit={$credit}).");
    }

    public static function nonEquilibree(int $transactionId): self
    {
        return new self("Transaction #{$transactionId} : mode PD actif mais equilibree=false. La génération PD a échoué silencieusement.");
    }
}
```

## 6. Tests

- Guard actif + transaction complète → pass silencieux
- Guard actif + transaction sans lignes PD → throw `PartieDoubleIncompleteException`
- Guard actif + transaction déséquilibrée → throw
- Guard actif + HelloAsso → pass (exempté)
- Guard inactif (PD off) → pass quoi qu'il arrive
- Intégration : `TransactionService::create()` en mode PD avec tiers null (skip enrichir) → throw G.2
- Intégration : `FactureService` en mode PD → `equilibree = true` + guard pass

## 7. Hors scope

- Rétroaction sur les transactions existantes (rôle du backfill).
- Guard sur les modifications de lignes individuelles (les services gèrent le cycle complet).
- Sous-journaux ou validation par journal.
