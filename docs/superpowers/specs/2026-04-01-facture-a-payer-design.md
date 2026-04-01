# Facture "à payer" — Créances à recevoir

> Date : 2026-04-01
> Statut : Validé (brainstorm)
> Périmètre : Facturation avant règlement via compte "Créances à recevoir"
> Prérequis : Module facturation V1 (v2.4.0)

---

## 1. Contexte et objectif

L'association facture des prestations à des tiers (mutuelles, organismes sociaux, participants) qui règlent après la prestation. Aujourd'hui, une facture ne peut être créée qu'à partir de recettes déjà encaissées. Ce module permet de **facturer avant le règlement** en utilisant un compte système "Créances à recevoir".

### Cas d'usage principal

Les opérations se terminent souvent fin juin. Les mutuelles et organismes paient parfois plusieurs mois après. L'exercice comptable se clôture au 31 août. Sans ce module, le produit serait comptabilisé sur l'exercice suivant (décalage).

### Principe directeur

**Transaction-first** : l'utilisateur crée une recette sur le compte "Créances à recevoir", puis génère la facture depuis le flux existant. Aucun nouveau formulaire de saisie de lignes de facture — on réutilise intégralement le mécanisme V1.

---

## 2. Modèle de données

### 2.1 Nouveau compte système

Migration : insérer un `CompteBancaire` avec :

| Champ | Valeur |
|-------|--------|
| `nom` | Créances à recevoir |
| `est_systeme` | `true` |

Ce compte rejoint "Remises en banque" dans les comptes système.

### 2.2 Aucune modification de schéma

Aucune modification des tables `factures`, `facture_lignes`, `facture_transaction`, `transactions` ou `transaction_lignes`. Le modèle existant supporte déjà ce flux :

- `facture_lignes.transaction_ligne_id` pointe vers les `transaction_lignes` de la créance (comme V1)
- `facture_transaction` pivot lie la facture aux transactions (comme V1)
- `montantRegle()` exclut déjà les comptes système → une transaction sur "Créances à recevoir" n'est pas comptée comme réglée
- Le changement de `compte_id` est autorisé sur une transaction verrouillée par facture (`assertLockedByFactureInvariants()` ne bloque pas `compte_id`)

---

## 3. Flux utilisateur

### 3.1 Créer une recette "à recevoir"

1. L'utilisateur va dans **Nouvelle recette** (flux existant)
2. Il sélectionne le tiers, la date, les lignes (opération, sous-catégorie, séance, montant)
3. **Nouveauté** : le compte "Créances à recevoir" apparaît dans la liste des comptes disponibles. Ce compte n'est proposé que pour les **recettes**, jamais pour les dépenses ni les virements.
4. Il enregistre → la transaction est créée sur le compte système

### 3.2 Créer la facture

1. L'utilisateur va dans **Factures → Nouvelle facture**
2. Il sélectionne le tiers
3. La liste des transactions disponibles inclut la créance (recette sur compte système, non rattachée à une facture)
4. Il sélectionne la ou les transactions (créances ET/OU recettes déjà encaissées — le mélange est autorisé)
5. Les lignes de facture sont auto-générées depuis les `transaction_lignes` (flux V1 existant)
6. Il ajuste le brouillon si besoin (libellés, lignes de texte, mentions)
7. Il valide → numéro F-xxxx attribué, PDF Factur-X généré

### 3.3 Affichage de la facture validée

La fiche facture affiche :

| Élément | Source |
|---------|--------|
| Montant total | `montant_total` (figé à la validation) |
| Montant réglé | `montantRegle()` — somme des transactions sur comptes non-système |
| Reste à payer | `montant_total - montantRegle()` |
| Statut | Acquittée si `montantRegle() >= montant_total`, sinon Non réglée |

Le bouton **"Enregistrer le règlement"** est visible si `montantRegle() < montant_total` (il reste des créances à encaisser).

### 3.4 Encaisser une créance

1. L'utilisateur clique **"Enregistrer le règlement"** sur la fiche facture
2. Une modale affiche les transactions liées qui sont sur un compte système :
   - Libellé (tiers + opération + séance)
   - Montant
   - Checkbox de sélection
3. L'utilisateur coche les transactions qu'il encaisse
4. Il choisit le **compte bancaire destination** (dropdown : comptes non-système)
5. Confirmation → `compte_id` mis à jour sur chaque transaction sélectionnée
6. `montantRegle()` se recalcule → la facture devient acquittée si tout est couvert

### 3.5 Scénarios couverts

| Scénario | Flux |
|----------|------|
| Facture classique (V1) | Recette sur compte réel → facture → immédiatement acquittée |
| Facture avant règlement | Recette sur Créances → facture → en attente → encaissement quand le paiement arrive |
| Facture mixte | Recettes sur comptes réels + créances → facture → partiellement réglée → encaisser le reste |
| Encaissement partiel | L'utilisateur ne coche que les transactions correspondant au montant reçu |

---

## 4. Règles métier de l'encaissement

L'encaissement déplace les transactions sélectionnées du compte "Créances à recevoir" vers un compte bancaire réel.

**Conditions :**
- La facture doit être validée et non intégralement réglée
- Le compte de destination doit être un compte bancaire réel (non-système)
- Seules les transactions encore sur un compte système peuvent être encaissées
- L'opération est atomique (tout ou rien)

---

## 5. Formulaire recette : exposition du compte "Créances à recevoir"

### 5.1 Modification

Le composant `TransactionUniverselle` (ou le formulaire de création de recette) filtre aujourd'hui les comptes avec `est_systeme = false`. Modifier pour inclure le compte "Créances à recevoir" spécifiquement.

### 5.2 Approche recommandée

Stocker l'id du compte "Créances à recevoir" en cache ou le résoudre par convention :

```php
$creancesId = CompteBancaire::where('est_systeme', true)
    ->where('nom', 'Créances à recevoir')
    ->value('id');

CompteBancaire::where('est_systeme', false)
    ->orWhere('id', $creancesId)
    ->orderBy('nom')
    ->get();
```

Le filtre se fait sur l'id (pas sur le nom en chaîne) pour éviter toute fragilité si le libellé était modifié. En pratique, le nom est fixé par la migration seed et n'est pas modifiable par l'utilisateur.

---

## 6. Listing factures : filtres

### 6.1 Filtre par tiers

Dropdown ou champ de recherche texte, filtre sur `factures.tiers_id`.

### 6.2 Filtre par statut de paiement

Statut calculé (pas stocké) :

| Statut affiché | Condition |
|----------------|-----------|
| Brouillon | `statut = brouillon` |
| Non réglée | `statut = validee` ET `montantRegle() < montant_total` |
| Acquittée | `statut = validee` ET `montantRegle() >= montant_total` |
| Annulée | `statut = annulee` (V2) |

### 6.3 Implémentation du filtre "Non réglée"

Le statut "Non réglée" vs "Acquittée" est calculé dynamiquement. Pour le filtre SQL, deux approches :

**A) Sous-requête** : calculer `montant_regle` en SQL et comparer à `montant_total`. Plus performant mais requête complexe.

**B) Filtrage PHP** : charger les factures validées, calculer `montantRegle()` en PHP, filtrer. Acceptable tant que le volume reste faible (< 500 factures).

Recommandation : **B** pour cette version. Le volume de factures d'une association est faible. On optimisera en SQL si le besoin se présente.

---

## 7. Chaîne de verrouillage

Le changement de `compte_id` via `encaisser()` respecte la hiérarchie de verrouillage existante :

| Verrou | Changer `compte_id` | Commentaire |
|--------|---------------------|-------------|
| Facture validée (`isLockedByFacture`) | **Autorisé** | `assertLockedByFactureInvariants()` ne contrôle pas `compte_id` |
| Remise bancaire (`isLockedByRemise`) | **Bloqué** | Freeze total — ne devrait pas arriver (pas de remise sur créances) |
| Rapprochement (`isLockedByRapprochement`) | **Bloqué** | Ne devrait pas arriver (on encaisse avant de rapprocher) |

Ordre attendu du cycle de vie d'une créance :
```
Créances à recevoir → encaisser() → Compte courant → rapprochement bancaire → pointé
```

---

## 8. Ce qui ne change pas

| Composant | Impact |
|-----------|--------|
| `FactureService::creer()` | Aucun — crée toujours un brouillon vide |
| `FactureService::ajouterTransactions()` | Aucun — rattache des transactions et génère les lignes |
| `FactureService::valider()` | Aucun — fige le montant, attribue le numéro |
| `FactureService::genererPdf()` | Aucun — lit les lignes et `montantRegle()` |
| `montantRegle()` | Aucun — exclut déjà les comptes système |
| `isAcquittee()` | Aucun — compare déjà `montantRegle()` au total |
| `isLockedByFacture()` | Aucun — verrouille déjà la structure, pas le compte |
| Modèle `FactureLigne` | Aucun |
| Table `facture_lignes` | Aucune migration |
| Table `factures` | Aucune migration |
| Workflow remise bancaire | Hors périmètre |

---

## 9. Périmètre hors version

| Sujet | Raison |
|-------|--------|
| Point d'entrée "Opération / Règlements" | Trop d'inconnues, le flux manuel couvre le besoin |
| Pré-remplissage des lignes depuis une opération | Lié au point d'entrée opération |
| Encaissement via remise bancaire | Volume faible, l'encaissement direct suffit |
| Annulation de facture / avoir | Chantier V2 séparé |
| Envoi email de la facture | Chantier V2 séparé |

---

## 10. Résumé des développements

| # | Composant | Effort estimé |
|---|-----------|---------------|
| 1 | Migration : compte système "Créances à recevoir" | ~5 min |
| 2 | Formulaire recette : exposer le compte "Créances à recevoir" | ~15 min |
| 3 | `FactureService::encaisser()` | ~30 min |
| 4 | UI : bouton "Enregistrer le règlement" + modale | ~1h |
| 5 | UI : affichage Reste à payer / Non réglée sur la fiche facture | ~30 min |
| 6 | Listing factures : filtre tiers + statut | ~1h |
| 7 | Tests Pest | ~1h |
| **Total** | | **~4-5h** |
