# Guide de test — Cycle de vie d'une transaction en mode Partie Double

> **Objectif** : permettre au Product Owner de valider visuellement le cycle de vie d'une transaction dans le moteur partie double (PD) d'AgoraGestion, depuis la saisie jusqu'au lettrage, la remise bancaire et l'extourne.

---

## 1. Préambule

Ce guide accompagne la branche `feat/compta-v5`. Il décrit 4 scénarios de validation à exécuter sur localhost ou preprod NAS.

### Prérequis

- **Sail démarré** : `./vendor/bin/sail up -d`
- **Mode PD activé** : `COMPTA_USE_PARTIE_DOUBLE=true` dans `.env`
- **Migrations fraîches** : `./vendor/bin/sail artisan migrate:fresh --seed`
- **Backfill exécuté** si données legacy présentes (voir section 2)

### Vocabulaire

| Terme | Définition |
|-------|-----------|
| T1 | Transaction principale (recette comptant, recette créance, dépense) |
| T2 | Transaction d'encaissement d'une créance ouverte |
| T4 | Transaction de consolidation d'une remise bancaire |
| Lettrage | Appariement débit/crédit sur un même compte (411, 401, 5112) |
| Équilibrée | Somme débits = somme crédits sur toutes les lignes PD |

---

## 2. Outils disponibles

### `compta:smoke-test-v5`

Validation globale sur tous les tenants. Compare compte de résultat legacy vs PD et vérifie l'invariant d'équilibre.

```bash
./vendor/bin/sail artisan compta:smoke-test-v5
# Ou pour une seule association :
./vendor/bin/sail artisan compta:smoke-test-v5 --asso=1
```

Sortie attendue : `Smoke-test OK : aucune divergence détectée.` (exit 0).

### `compta:backfill-partie-double --dry-run`

Audit pré-conversion des données legacy. N'écrit rien en base.

```bash
# Voir ce qui sera converti (exercice courant)
./vendor/bin/sail artisan compta:backfill-partie-double --dry-run --asso=1

# Lancer la conversion réelle
./vendor/bin/sail artisan compta:backfill-partie-double --asso=1
```

### `compta:dump-transaction {id} [--asso=ID]`

Dump détaillé d'une transaction : en-tête, lignes PCG, lettrages actifs, transactions liées, sources consolidées (T4).

```bash
# Avec association explicite
./vendor/bin/sail artisan compta:dump-transaction 42 --asso=1

# Avec autodétection de l'association (si non ambiguë)
./vendor/bin/sail artisan compta:dump-transaction 42
```

Exit code : 0 si OK, 1 si transaction introuvable.

### `v5:sync-from-main`

Sync hebdomadaire de `feat/compta-v5` depuis `main` + validation des tests Backfill.

```bash
./vendor/bin/sail artisan v5:sync-from-main
```

---

## 3. Scénario A — Recette comptant Chèque

**Objectif** : vérifier les 4-5 lignes PD générées pour une recette chèque, dont la paire 411 lettrée.

### Étapes

1. Aller sur **Banques > Opérations > Nouvelle recette**.
2. Remplir :
   - Mode de paiement : **Chèque**
   - Tiers : créer ou sélectionner (ex: Marie Dupont)
   - Montant : **100,00 €**
   - Sous-catégorie : **Cotisations** (liée au compte 706)
3. Valider la transaction. Noter l'ID affiché (ex: `#42`).
4. Lancer le dump :

```bash
./vendor/bin/sail artisan compta:dump-transaction 42 --asso=1
```

### Output attendu

```
═══════════════════════════════════════════════════════════════
  Transaction #42 — Recette « Cotisation Marie Dupont »
  Mode: Chèque  |  Montant: 100,00€  |  Date: 15/04/2026
  Type: recette  |  Statut: En attente
  Tiers: Dupont Marie (#5)  |  Compte bancaire: Compte courant (#1)
  Équilibrée: ✓
  Association: #1 Mon Association
═══════════════════════════════════════════════════════════════

Lignes (5):
+---------+------+---------------------+---------+---------+--------------+--------------+
| Ligne   | PCG  | Intitulé            | Débit   | Crédit  | Sous-cat     | Lettrage     |
+---------+------+---------------------+---------+---------+--------------+--------------+
| #101    | 706  | Cotisations         |         | 100.00  | Cotisations  | -            |
| #102    | 411  | Clients             | 100.00  |         | -            | AB12cd...    |
| #103    | 5112 | Chèques à encaisser | 100.00  |         | -            | -            |
| #104    | 411  | Clients             |         | 100.00  | -            | AB12cd...    |
+---------+------+---------------------+---------+---------+--------------+--------------+
| TOTAL   |      |                     | 200.00  | 200.00  |              |              |
+---------+------+---------------------+---------+---------+--------------+--------------+
```

### Points à vérifier

- **5 lignes** (ou 4 si recette sans tiers) avec `Équilibrée: ✓`
- **Ligne 706 C** : crédit sur le compte produit (classe 7)
- **Paire 411 D/C** : lettrage identique (code identique dans colonne Lettrage)
- **Ligne 5112 D** : débit chèques à encaisser (pas encore en banque)
- Section **Lettrages actifs** : 1 code, 2 lignes appariées (L#102 411 D ↔ L#104 411 C)

---

## 4. Scénario B — Recette à Crédit (Facture validée) puis Encaissement

**Objectif** : suivre le cycle facture → encaissement avec lettrage cross-transaction.

### Étapes

1. **Créer la facture** :
   - Aller sur **Facturation > Nouvelles facture**
   - Tiers : Sophie Martin, montant : **200,00 €**
   - Mode de règlement prévu : **Virement**
   - Valider la facture (statut → `validée`)
   - La transaction T1 est créée automatiquement. Notez son ID (ex: `#50`).

2. **Vérifier T1 (créance ouverte)** :

```bash
./vendor/bin/sail artisan compta:dump-transaction 50 --asso=1
```

Attendu : ligne `706 C 200`, ligne `411 D 200` (non lettrée → créance ouverte).
Section **Transactions liées** : `Liée à facture F-2025-0001 (statut: validee)`.

3. **Encaisser la facture** :
   - Sur la page de la facture, cliquer **Marquer reçu**.
   - La transaction T2 est créée. Notez son ID (ex: `#55`).

4. **Vérifier T1 après encaissement** :

```bash
./vendor/bin/sail artisan compta:dump-transaction 50 --asso=1
```

Attendu : ligne `411 D 200` maintenant lettrée (code identique à T2).
Section **Transactions liées** : `T1 créance encaissée par Tx #55`.

5. **Vérifier T2** :

```bash
./vendor/bin/sail artisan compta:dump-transaction 55 --asso=1
```

Attendu : `5121 D 200` (virement en banque) + `411 C 200` lettrée.
Section **Transactions liées** : `T2 d'encaissement de Tx #50 (créance)`.

---

## 5. Scénario C — Remise Bancaire (T4 consolidation)

**Objectif** : vérifier la consolidation de 3 chèques en une T4 de remise.

### Étapes

1. **Créer 3 recettes chèque** (cf. Scénario A) :
   - Tx #60 : Jean Martin — 100 €
   - Tx #61 : Paul Durand — 80 €
   - Tx #62 : Marie Dupont — 150 €

2. **Créer la remise bancaire** :
   - Aller sur **Banques > Remises bancaires > Nouvelle remise**
   - Sélectionner les 3 chèques ci-dessus
   - Valider la remise
   - La T4 est créée automatiquement. Trouver son ID via :

```bash
./vendor/bin/sail artisan tinker
>>> App\Models\Transaction::latest()->first()->id
```

3. **Vérifier la T4** (ex: ID #65) :

```bash
./vendor/bin/sail artisan compta:dump-transaction 65 --asso=1
```

### Output attendu pour la T4

```
Lignes (4):
  #201  5121  Compte courant  D 330.00   (total remise)
  #202  5112  Chèques         C 100.00   lettrée (paire avec L#103 de T#60)
  #203  5112  Chèques         C  80.00   lettrée (paire avec L#... de T#61)
  #204  5112  Chèques         C 150.00   lettrée (paire avec L#... de T#62)

Sources consolidées (3 Chèque):
  Tx#60 — Cotisation Jean Martin 100,00€
  Tx#61 — Cotisation Paul Durand 80,00€
  Tx#62 — Cotisation Marie Dupont 150,00€
```

### Points à vérifier

- Section **Sources consolidées** présente avec les 3 T1 sources
- Lettrages 5112 : chaque ligne C de la T4 est lettrée avec la ligne D de la T1 correspondante
- Total : 330 € = 100 + 80 + 150

---

## 6. Scénario D — Extourne

**Objectif** : vérifier que l'extourne miroir la transaction d'origine et délettre les lignes lettrées.

### Étapes

1. **Créer une recette chèque** (cf. Scénario A, ex: Tx #70).

2. **Extourner la transaction** via l'interface ou en artisan tinker :

```bash
./vendor/bin/sail artisan tinker
>>> $tx = App\Models\Transaction::find(70);
>>> app(App\Services\Compta\TransactionExtourneService::class)->extourner($tx);
>>> # Noter l'ID du miroir (ex: #71)
```

3. **Vérifier T1 originale (extournée)** :

```bash
./vendor/bin/sail artisan compta:dump-transaction 70 --asso=1
```

Attendu : `Équilibrée: ✓`, section **Transactions liées** : `Origine extournée par Tx #71`.
Les lignes lettrées de T1 doivent avoir leur lettrage_code à NULL (délettrage automatique).

4. **Vérifier le miroir T2'** :

```bash
./vendor/bin/sail artisan compta:dump-transaction 71 --asso=1
```

Attendu : lignes symétriques (débits ↔ crédits inversés par rapport à T1).
Section **Transactions liées** : `Miroir extourne de Tx #70`.

---

## 7. Lecture du smoke-test

Après chaque série de transactions, validez globalement :

```bash
./vendor/bin/sail artisan compta:smoke-test-v5 --asso=1
```

### Interprétation des colonnes

| Colonne | Signification | Seuil acceptable |
|---------|--------------|-----------------|
| CR Δ€ | Écart entre compte de résultat legacy et PD | < 0,01 € |
| Rappro Δ€ | Écart sur les soldes de rapprochement verrouillés | < 0,01 € |
| Tx déséquilibrées | Nombre de transactions où ∑débit ≠ ∑crédit | = 0 |

Si des écarts apparaissent, utilisez `compta:dump-transaction` sur les transactions suspectes.

---

## 8. Troubleshooting

### Δ ≠ 0 dans le smoke-test

1. Identifier la transaction suspecte avec une requête SQL directe ou via tinker :

```bash
./vendor/bin/sail artisan tinker
>>> DB::table('transaction_lignes as tl')
...     ->join('transactions as t', 't.id', '=', 'tl.transaction_id')
...     ->where('t.association_id', 1)
...     ->whereNull('tl.deleted_at')
...     ->groupBy('tl.transaction_id')
...     ->havingRaw('ABS(SUM(tl.debit) - SUM(tl.credit)) > 0.01')
...     ->pluck('tl.transaction_id');
```

2. Lancer le dump sur chaque ID suspect :

```bash
./vendor/bin/sail artisan compta:dump-transaction {id} --asso=1
```

3. Vérifier l'en-tête : `Équilibrée: ✗` signale une incohérence. Regarder les lignes : quelle ligne D/C manque ?

### Lignes orphelines (lettrage_code présent sur une seule ligne)

Vérifier `lettrage_audit` directement :

```bash
./vendor/bin/sail artisan tinker
>>> DB::table('lettrage_audit')->where('action', 'lettre')->latest()->limit(10)->get();
```

### Transaction non trouvée par la commande

Si `compta:dump-transaction` retourne exit 1 :
- Vérifier que l'ID est correct : `./vendor/bin/sail artisan tinker --execute "echo App\Models\Transaction::withoutGlobalScopes()->find({id})?->id;"`
- Vérifier que l'association est correcte avec `--asso=ID`
- Si la transaction est soft-deleted, elle est invisible même avec `withoutGlobalScopes`

---

## 9. Utilisation sur preprod NAS

Les commandes sont identiques, préfixées par `docker compose exec laravel.test php artisan` :

```bash
# Sur le NAS preprod (SSH)
cd /path/to/agora-gestion
docker compose exec laravel.test php artisan compta:dump-transaction 42 --asso=1
docker compose exec laravel.test php artisan compta:smoke-test-v5
docker compose exec laravel.test php artisan compta:backfill-partie-double --dry-run --asso=1
```

### Variables d'environnement preprod

Assurez-vous que `.env` sur le NAS contient :

```env
COMPTA_USE_PARTIE_DOUBLE=true
```

Vérifiable via :

```bash
docker compose exec laravel.test php artisan tinker --execute "echo config('compta.use_partie_double') ? 'PD: ON' : 'PD: OFF';"
```
