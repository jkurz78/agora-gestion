# Chantier 8 — Virements internes partie double (512→512)

**Branche** : `feat/compta-v5`
**Date** : 2026-06-17
**Statut** : Spec validée

## 1. Contexte

`VirementInterne` est aujourd'hui un enregistrement isolé (date, montant, compte_source, compte_destination) sans représentation dans le ledger partie double. Le grand livre, la balance et le journal Banque ignorent les virements. Ce chantier les branche sur le socle PD pour compléter le ledger.

## 2. Écriture comptable

Un virement de 1 000 € du compte BNP (512100) vers CIC (512200) :

| Journal | Pièce | Compte | Libellé | Débit | Crédit |
|---------|-------|--------|---------|-------|--------|
| Banque | 2025-2026:00042 | 512200 CIC | Virement interne | 1 000,00 | — |
| Banque | 2025-2026:00042 | 512100 BNP | Virement interne | — | 1 000,00 |

- **2 lignes** seulement (débit destination, crédit source).
- Pas de tiers (classe 5 des deux côtés).
- Pas de portage (transfert direct inter-comptes bancaires).
- Pas de lettrage (pas de chaîne 411/401).
- `type_ecriture = 'normale'`, `journal = Banque`, `equilibree = true`.

## 3. Transaction liée

### 3.1 Modèle

Une **seule** `Transaction` est créée par virement, reliée par une FK `virement_interne_id` (nullable) sur la table `transactions`.

| Champ | Valeur |
|-------|--------|
| `type` | `TypeTransaction::Virement` (nouveau case) |
| `date` | même date que le virement |
| `libelle` | `"Virement interne"` (ou la référence si renseignée) |
| `montant_total` | montant du virement |
| `mode_paiement` | `ModePaiement::Virement` |
| `type_ecriture` | `'normale'` |
| `journal` | `JournalComptable::Banque` |
| `numero_piece` | celui du `VirementInterne` (pas de nouveau numéro) |
| `equilibree` | `true` |
| `virement_interne_id` | FK vers `virements_internes.id` |

### 3.2 TypeTransaction — ajout du case `Virement`

L'enum `TypeTransaction` (string-backed, colonne `type VARCHAR(10)`) reçoit un nouveau case :

```php
case Virement = 'virement';
```

L'ajout est additif (pas de migration ALTER ENUM — c'est un VARCHAR). Il faut vérifier les `match` exhaustifs et les filtres `WHERE type IN (...)` dans les vues legacy pour ne pas inclure les virements à tort.

### 3.3 TransactionLigne

Deux lignes PD-only (`sous_categorie_id = NULL`) :

| # | `compte_id` | `debit` | `credit` | `tiers_id` | `libelle` |
|---|-------------|---------|----------|------------|-----------|
| 1 | 512X destination | montant | 0 | NULL | Virement interne |
| 2 | 512X source | 0 | montant | NULL | Virement interne |

Les deux lignes ont `operation_id = NULL`, `seance = NULL`, `lettrage_code = NULL`.

## 4. Rapprochement bancaire

Le rapprochement continue à fonctionner **via les champs existants** de `VirementInterne` :
- `rapprochement_source_id` — pointage côté source
- `rapprochement_destination_id` — pointage côté destination

L'écran de rapprochement gère déjà les virements comme un type distinct. Pas de changement.

La `Transaction` PD liée n'utilise **pas** `Transaction.rapprochement_id` (qui reste `NULL`). Le lien entre les lignes PD et le statut pointé sera résolu en P1 (grand livre) via la jointure `transactions.virement_interne_id → virements_internes.rapprochement_*_id`.

## 5. EtatReglementResolver

Pas impacté. Les virements n'ont pas de `statut_reglement` (pas de tiers 411/401). Le resolver ignore les transactions sans ligne tiers.

## 6. Cycle de vie

### 6.1 Création

`VirementInterneService::create()` :
1. Créer le `VirementInterne` (existant).
2. Si `config('compta.use_partie_double')` : appeler `EcritureGenerator::pourVirementInterne($virement)`.
3. Le generator crée la Transaction header + 2 lignes, asserte l'équilibre.

### 6.2 Mise à jour

`VirementInterneService::update()` :
1. Si une Transaction PD liée existe : la supprimer (avec ses lignes, cascade).
2. Mettre à jour le `VirementInterne` (existant).
3. Si PD actif : recréer la Transaction via le generator.

### 6.3 Suppression

`VirementInterneService::delete()` :
1. Vérifications existantes (rapprochement, remise).
2. Si une Transaction PD liée existe : la supprimer (cascade).
3. Supprimer le `VirementInterne`.

### 6.4 Guard PD

Toute la logique PD est conditionnée par `config('compta.use_partie_double')`. Si `false`, aucune Transaction n'est générée (comportement legacy inchangé).

## 7. Fichiers impactés

| Fichier | Changement |
|---------|-----------|
| `app/Enums/TypeTransaction.php` | + case `Virement = 'virement'` + label |
| `app/Services/Compta/EcritureGenerator.php` | + méthode `pourVirementInterne(VirementInterne): Transaction` |
| `app/Services/VirementInterneService.php` | Appels au generator dans create/update/delete |
| `app/Models/Transaction.php` | + relation `virementInterne()` belongsTo |
| `app/Models/VirementInterne.php` | + relation `transaction()` hasOne |
| Migration | + colonne `virement_interne_id` FK nullable sur `transactions` |
| Tests | Écriture, équilibre, cascade update/delete, guard PD off |

## 8. Résolution des comptes

`VirementInterne` stocke des `compte_source_id` / `compte_destination_id` qui réfèrent à `comptes_bancaires` (comptes physiques). Le generator doit résoudre le `Compte` PCG (classe 512) associé à chaque `CompteBancaire`.

Le lien est inversé : c'est le `Compte` 512X qui porte un `compte_bancaire_id` FK vers `comptes_bancaires`. La résolution utilise `CompteTresorerieResolver` (pattern existant) ou directement :

```php
Compte::where('compte_bancaire_id', $compteBancaireId)->bancaires()->first()
```

Si l'un des deux comptes 512X est introuvable (pas de schéma PCG configuré), le generator lève une exception (pas de skip silencieux — un virement sans les deux comptes est une erreur de configuration).

## 9. Invariants et assertions

Le generator asserte après création :
- **Équilibre** : somme débits = somme crédits.
- **Cohérence tenant** : toutes les lignes portent le même `association_id`.
- **Pas de tiers sur classe 5** : aucune ligne ne porte de `tiers_id`.
- **2 lignes exactement** : ni plus, ni moins.
- **Comptes distincts** : source ≠ destination.

## 10. Hors scope

- Sous-journaux par compte bancaire (P1).
- Intégration grand livre / rapprochement via jointure (P1).
- HelloAsso cash-out automatique (exploitera ce socle mais c'est un flux distinct).
