# Fondations partie double — Slice 1

- **Date** : 2026-05-19
- **Programme** : Comptabilité élargie (modèle uniforme partie double + cohabitation cash/double via UX)
- **Slice** : 1 / N — Fondations : modèle uniforme, lettrage, CR et rappro rebranchés
- **Périmètre** : exercice courant uniquement, un seul tenant (instance SVS)
- **Branche cible** : `feat/fondations-partie-double-slice1`

## Vocabulaire

| Terme | Définition |
|---|---|
| **Écriture** | Une `transaction_ligne` portant un débit OU un crédit sur un compte donné, optionnellement un tiers, optionnellement une opération |
| **Transaction** | Un ensemble d'écritures équilibrées (∑ débits = ∑ crédits) datées et libellées |
| **Compte** | Un compte au sens PCG (411, 706, 5112, 512…) — fusion de `sous_categories` + `comptes_bancaires` + nouveaux comptes système |
| **Compte de gestion** | Comptes classes 6 et 7 (charges, produits) — feeds le compte de résultat |
| **Compte de bilan** | Comptes classes 1 à 5 (capitaux, immo, stocks, tiers, trésorerie) — feed le bilan (slice 2+) |
| **Lettrage** | Mécanisme d'appariement de lignes débit/crédit sur un même compte (typiquement 411, 401, 5112) dont la somme algébrique est zéro |
| **Solde ouvert** | Somme algébrique des lignes non lettrées d'un compte (= positions réellement en attente) |
| **Solde courant** | Somme algébrique de toutes les lignes d'un compte (lettrées ou non) — informationnel |
| **À-nouveau** | Hors slice 1 — voir slice 2 |

## Décisions actées en cadrage (pré-spec)

1. **Stratégie cible** : **Modèle uniforme partie double partout**, avec une couche d'ergonomie qui assiste la saisie en mode cash basis (génération automatique des contreparties). Pas de cohabitation de deux moteurs.
2. **Mode UX cash vs double** : pas dans ce slice. Le slice 1 livre les fondations qui rendent la cohabitation **possible** plus tard. L'UX actuelle (recettes/dépenses) reste inchangée fonctionnellement.
3. **Périmètre fonctionnel utilisateur visible slice 1** : **inchangé** par rapport à aujourd'hui. Le compte de résultat continue d'être calculé, le rapprochement continue de fonctionner. Sous le capot tout est réécrit en partie double.
4. **École de comptabilisation des recettes/dépenses** : **École C — hybride par mode** (voir matrice §4.3). Comptant saute le 411 ; à crédit passe par 411.
5. **Comptes intermédiaires nouveaux** : `411 Clients`, `401 Fournisseurs`, `5112 Chèques à encaisser`, `530 Caisse` (si caisse espèces existe — à confirmer en step 1).
6. **Tiers porté par la ligne, pas la transaction** : `transaction_lignes.tiers_id` (nouveau). `transactions.tiers_id` devient dénormalisé en lecture (ou supprimé — à arbitrer en step 1).
7. **Comptes unifiés** : table `comptes` absorbe `sous_categories` ET `comptes_bancaires`. Les attributs bancaires (IBAN, BIC, solde initial) migrent dans une table satellite `comptes_bancaires_meta` ou en colonnes nullables sur `comptes` (à arbitrer en step 1).
8. **Renommage `sous_categorie` → `compte`** dans ce slice (refacto transverse).
9. **Lettrage automatique** sur paires et lots à la génération (`EcritureGenerator`).
10. **Délettrage** : mécanisme programmatique (`LettrageService::delettrer()`) + auto-délettrage sur extourne. Pas d'UI manuelle slice 1.
11. **512 ne se lettre jamais** : son cycle de pointage est le rapprochement bancaire, pas le lettrage.
12. **Remise bancaire = transaction multi-lignes splittée par tiers** (Variante 2a). La table `remises_bancaires` survit comme groupement logique avec lien vers la transaction de dépôt et les écritures sources.
13. **Backfill** : exercice courant uniquement (un seul tenant, premier exercice → 1A = 1C). Pas de pivot date, pas de support legacy en lecture.
14. **Hors slice 1 (slices ultérieurs)** : bilan, TVA, immobilisations, à-nouveau formel à la clôture, OD libres, UI manuelle de lettrage/délettrage, chèque impayé (flow dédié).

## Hypothèses techniques verrouillées

| Item | État |
|---|---|
| `transactions` (header) déjà unifié dépense/recette depuis v2.x | ✓ |
| `transaction_lignes` existe avec `sous_categorie_id`, `montant`, `operation_id`, `seance` | ✓ |
| `transaction_ligne_affectations` (M-N analytique opération/séance) existe | ✓ — préservé tel quel |
| `comptes_bancaires` table séparée avec `solde_initial`, IBAN, BIC, flags `actif_recettes_depenses` | ✓ — à fusionner dans `comptes` |
| `sous_categories.code_cerfa` aligné en pratique sur PCG (706A, 706B, 707, 741, 751, 754, 756, 761, 771, 606, 641, 645…) | ✓ — sert de base au mapping `numero_pcg` |
| `tiers_id` porté actuellement par `transactions` (entête) | ⚠ à migrer sur `transaction_lignes` |
| Lettrage actuel : aucune notion native (à introduire) | ⚠ |
| `Extourne` modèle + service livré v4.2.2 | ✓ — à enrichir d'auto-délettrage en step dédié |
| `Provision` modèle + service livré v2.10.0 | ✓ — code générant les lignes provisions à rebrancher sur nouveau modèle |
| Compte de résultat alimenté aujourd'hui par `transaction_lignes.sous_categorie_id` + `Categorie.classe` | ✓ — à rebrancher sur `transaction_lignes.compte_id` + `comptes.classe` |
| Rapprochement alimenté par `transactions.compte_id` + `transactions.remise_id` (GROUP BY) | ✓ — à rebrancher sur `transaction_lignes.compte_id` (filtre classe=5) + `transaction_lignes.lettrage_code` (groupement remise via lettrage) |
| `App\Support\TenantContext` actif, scope global sur `TenantModel` | ✓ — toute nouvelle table tenant-scopée hérite |

---

## 1. Intent

**Objectif** : remplacer le moteur de comptabilisation actuel (cash basis avec champs `type=recette/dépense` + `montant_total` + `compte_id` à l'entête + lignes signées par le type) par un moteur partie double uniforme (écritures `débit`/`crédit` équilibrées, axe tiers porté par les lignes, comptes unifiés couvrant trésorerie et gestion). **Sans changement fonctionnel visible immédiat** pour l'utilisateur : le compte de résultat et le rapprochement bancaire produisent les mêmes résultats qu'avant.

**Pourquoi maintenant** : la mémoire `project_compta_partie_double.md` cadrait une approche cash basis enrichie. Cette spec **revise** cette orientation : on stocke en partie double dès aujourd'hui, ce qui ouvre la voie au bilan, à la TVA, aux immobilisations, et à la production de FEC sans réécriture ultérieure du modèle. Le coût marginal est concentré dans ce slice 1 (refonte du modèle + backfill + branchement génération auto). Les slices suivants n'ajouteront que des **vues** sur des données déjà partie double.

**Frontière (ce que ce slice NE livre PAS)** :
- Pas de bilan
- Pas de déclaration TVA
- Pas d'immobilisations
- Pas d'écritures d'à-nouveau à la clôture (mécanisme dans slice 2)
- Pas d'OD libres saisies par l'utilisateur (interface dans slice 2+)
- Pas d'UI manuelle de lettrage/délettrage
- Pas de flow chèque impayé (slice dédié)
- Aucune modification UI utilisateur visible des écrans existants (Recettes, Dépenses, Rappro, CR) — sauf adaptations strictement nécessaires (nom de colonne, libellés)

**Acceptance globale** : la suite Pest reste verte. Le compte de résultat affiché à l'utilisateur produit les **mêmes totaux** qu'aujourd'hui pour l'exercice courant. Le rapprochement affiche les mêmes lignes pointables et permet le même pointage. Les transactions historiques de l'exercice courant ont été converties en écritures partie double équilibrées sans perte d'information.

---

## 2. Modèle de données

### 2.1. Table `comptes` (nouvelle, absorbe sous_categories + comptes_bancaires)

```sql
CREATE TABLE comptes (
    id                   BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    association_id       BIGINT UNSIGNED NOT NULL,        -- tenant scope
    numero_pcg           VARCHAR(10) NOT NULL,            -- ex: '706', '5112', '411', '512'
    intitule             VARCHAR(255) NOT NULL,
    classe               TINYINT UNSIGNED NOT NULL,        -- 1..7, dérivé du 1er chiffre du numero_pcg
    categorie_id         BIGINT UNSIGNED NULL,             -- conservé pour regroupement UI (catégorie comptable historique)
    parent_compte_id     BIGINT UNSIGNED NULL,             -- sous-compte de (ex: 5112 -> 511 -> 51)
    actif                BOOLEAN NOT NULL DEFAULT TRUE,
    est_systeme          BOOLEAN NOT NULL DEFAULT FALSE,   -- comptes générés/protégés (411, 401, 5112, 530, 512 bancaires)
    pour_inscriptions    BOOLEAN NOT NULL DEFAULT FALSE,   -- héritage sous_categories
    lettrable            BOOLEAN NOT NULL DEFAULT FALSE,   -- TRUE pour 411, 401, 5112, 530 ; FALSE pour 512 et comptes de gestion
    -- attributs bancaires (nullable, populés quand classe=5 et numero_pcg='512%')
    iban                 VARCHAR(34) NULL,
    bic                  VARCHAR(11) NULL,
    domiciliation        VARCHAR(255) NULL,
    solde_initial        DECIMAL(12,2) NULL,
    date_solde_initial   DATE NULL,
    deleted_at           TIMESTAMP NULL,
    created_at           TIMESTAMP NULL,
    updated_at           TIMESTAMP NULL,
    UNIQUE KEY (association_id, numero_pcg),
    INDEX (association_id, classe),
    INDEX (association_id, lettrable)
);
```

**Décisions** :
- `numero_pcg` unique par tenant. Sert de clé naturelle (« le 706 de cette asso »).
- `classe` dérivée du 1er chiffre du `numero_pcg`, dénormalisée pour perf.
- `lettrable` flag explicite : TRUE pour les comptes de portage (411, 401, 5112, 530), FALSE pour 512 (rapprochement bancaire à la place) et tous les comptes de gestion (6, 7).
- Attributs bancaires en colonnes nullables sur `comptes` plutôt que table satellite — densité faible (1 asso a 1-5 comptes bancaires), pas la peine d'externaliser.
- `est_systeme` empêche la suppression et l'édition des comptes générés par seed (411, 401, 5112, 530).

### 2.2. Modification de `transaction_lignes`

Ajout de colonnes :

```sql
ALTER TABLE transaction_lignes
    ADD COLUMN compte_id          BIGINT UNSIGNED NULL AFTER sous_categorie_id,
    ADD COLUMN debit              DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER compte_id,
    ADD COLUMN credit             DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER debit,
    ADD COLUMN tiers_id           BIGINT UNSIGNED NULL AFTER credit,
    ADD COLUMN lettrage_code      VARCHAR(20) NULL AFTER tiers_id,
    ADD COLUMN libelle            VARCHAR(255) NULL AFTER lettrage_code,  -- libellé par ligne (optionnel)
    ADD INDEX (compte_id, tiers_id, lettrage_code),
    ADD INDEX (lettrage_code),
    ADD INDEX (compte_id, tiers_id);

-- Contrainte applicative : exactement une des deux colonnes debit/credit est > 0
-- Vérifiée par Eloquent mutator + tests, pas par CHECK constraint MySQL pour compat
```

`sous_categorie_id` est conservé temporairement (compat) pendant la migration puis **droppé en fin de slice 1** une fois le backfill complet et tous les usages migrés vers `compte_id`.

`montant` est **conservé** pour compat lecture pendant migration, puis **droppé en fin de slice 1**. Tous les nouveaux flux écrivent `debit` ou `credit`. Lecture : montant signé déductible (`+debit -credit` pour comptes de classe `actif`, etc. — mais utilisateurs lisent toujours via les builders, pas le champ brut).

### 2.3. Modification de `transactions` (header)

```sql
ALTER TABLE transactions
    ADD COLUMN equilibree           BOOLEAN NOT NULL DEFAULT FALSE AFTER montant_total,
    ADD COLUMN type_ecriture        ENUM('normale','an','od','extourne') NOT NULL DEFAULT 'normale' AFTER equilibree;

-- equilibree = invariant ∑debit = ∑credit sur les lignes. Calculé/vérifié à la sauvegarde.
-- type_ecriture : 'normale' (saisie utilisateur ou auto), 'an' (à-nouveau, slice 2+), 'od' (opération diverse, slice 2+), 'extourne' (déjà existant via Extourne).
```

`type` (`'recette' | 'dépense'`) est conservé temporairement pour compat puis **droppé en fin de slice 1**. Le type d'une transaction se dérive de ses lignes (transaction qui crédite un compte 7 = recette).

`tiers_id` à l'entête : **conservé** comme dénormalisation en lecture (perf filtres listes), mais **non source de vérité**. Maintenu en cohérence avec la première ligne 411/401 lors de la sauvegarde. À évaluer pour suppression slice 2 (si le filtre liste peut passer par `EXISTS (SELECT 1 FROM transaction_lignes WHERE transaction_id=transactions.id AND tiers_id=?)` sans perte de perf).

`compte_id` à l'entête : **conservé** pour compat lecture pendant migration, puis **réinterprété** comme « compte de trésorerie principal de la transaction » (= la ligne 512 ou 5112 ou 530 si présente). Utile pour le rappro qui filtre par compte. À droppage final slice 2 si le rappro peut filtrer sur `transaction_lignes.compte_id`.

`remise_id` à l'entête : conservé tel quel, lien avec `remises_bancaires`.

### 2.4. Modification de `remises_bancaires`

Aucun changement structurel slice 1. La table reste comme groupement logique avec :
- `compte_cible_id` → compte 512 destination
- Lien implicite vers la **transaction de dépôt** (la transaction T4 qui porte les lignes 512+/5112-) via `transactions.remise_id`
- Lien implicite vers les **transactions sources** (T1/T2/T3 vente comptant) via leurs lignes 5112 lettrées dans le même `lettrage_code` que les contreparties dans T4

Slice 2+ : envisager une table pivot `remise_transaction_source` si le requêtage par `lettrage_code` devient lourd. Slice 1 = pas besoin.

### 2.5. Table `lettrage_audit` (nouvelle)

```sql
CREATE TABLE lettrage_audit (
    id                 BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    association_id     BIGINT UNSIGNED NOT NULL,
    action             ENUM('lettre','delettre') NOT NULL,
    lettrage_code      VARCHAR(20) NOT NULL,
    compte_id          BIGINT UNSIGNED NOT NULL,        -- compte sur lequel le lettrage a été appliqué/levé
    transaction_ligne_ids JSON NOT NULL,                -- snapshot des lignes concernées au moment de l'action
    user_id            BIGINT UNSIGNED NULL,
    motif              VARCHAR(255) NULL,
    created_at         TIMESTAMP NULL,
    INDEX (association_id, lettrage_code),
    INDEX (association_id, created_at)
);
```

Append-only. Permet de tracer l'historique des lettrages et délettrages sans complexifier `transaction_lignes`. Source de vérité d'audit (utile aussi pour FEC ultérieur).

---

## 3. Plan de comptes initial (seed)

Le slice 1 introduit (ou conserve) les comptes suivants. Le mapping `sous_categories.code_cerfa → comptes.numero_pcg` est utilisé pour le backfill.

### 3.1. Comptes de gestion (classe 6 et 7)

**Source : table `sous_categories` actuelle.** Pour chaque sous-catégorie existante :
- `numero_pcg` = `sous_categories.code_cerfa` (déjà aligné PCG en pratique)
- `intitule` = `sous_categories.nom`
- `categorie_id` = `sous_categories.categorie_id`
- `classe` = premier chiffre de `code_cerfa`
- `lettrable` = `FALSE`

**Sous-catégories sans `code_cerfa`** : audit en step 1 du build. Soit affectation manuelle à un compte PCG existant, soit création d'un compte « divers » par défaut (606800 Autres charges, 758800 Autres produits — à acter en step 1).

### 3.2. Comptes de trésorerie (classe 5)

**Source : table `comptes_bancaires` actuelle.** Pour chaque compte bancaire :
- `numero_pcg` = `'5121'`, `'5122'`, `'5123'`… (incrément à partir de 5121 dans l'ordre des comptes bancaires existants)
- `intitule` = `comptes_bancaires.nom`
- `classe` = 5
- `lettrable` = FALSE (rappro bancaire)
- Attributs bancaires copiés (IBAN, BIC, domiciliation, solde_initial, date_solde_initial)

**Décision actée** : un sous-compte par banque physique (`5121`, `5122`…). Préserve la clarté du grand livre et le pattern PCG standard. Le step 1 du build seede simplement selon cette règle.

### 3.3. Comptes système nouveaux

Seedés par migration, `est_systeme = TRUE`, ne peuvent être ni supprimés ni édités :

| Numéro PCG | Intitulé | Classe | Lettrable |
|---|---|---|---|
| `411` | Clients | 4 | TRUE |
| `401` | Fournisseurs | 4 | TRUE |
| `5112` | Chèques à encaisser | 5 | TRUE |
| `530` | Caisse (espèces) | 5 | TRUE — créé seulement si l'asso utilise les espèces (voir 3.4) |

### 3.4. Caisse espèces — création conditionnelle

Slice 1 vérifie si des transactions historiques de l'exercice courant ont `mode_paiement = 'espèces'`. Si oui → compte 530 créé. Sinon → pas créé (l'asso ne manipule pas d'espèces, le compte serait du bruit).

### 3.5. Plan de comptes anticipé pour slice 2+ (NON seedé slice 1)

Pour mémoire, slice 2+ introduira : 102 Fonds associatifs, 119 Report à nouveau, 120 Résultat de l'exercice, 44566 TVA déductible, 44571 TVA collectée, 2x Immobilisations, 28x Amortissements, etc.

---

## 4. Service `EcritureGenerator`

Service central qui génère les écritures partie double pour un événement utilisateur de saisie (recette, dépense, encaissement, remise…). Il **encapsule la matrice École C**.

### 4.1. Interface publique

```php
final class EcritureGenerator
{
    public function pourRecetteComptant(
        Tiers $tiers,
        Compte $compteProduit,
        float $montant,
        ModePaiement $mode,         // chèque, espèces, virement, CB
        Compte $compteTresorerie,   // 512BNP (virement/CB) ou compte par défaut
        \DateTimeInterface $date,
        ?string $libelle = null,
        ?Operation $operation = null,
        ?int $seance = null,
    ): Transaction;

    public function pourRecetteACredit(
        Tiers $tiers,
        Compte $compteProduit,
        float $montant,
        \DateTimeInterface $dateConstatation,
        ?string $libelle = null,
        ?Operation $operation = null,
    ): Transaction;

    public function pourEncaissementCreance(
        Transaction $transactionCreance,  // T1 = 411/706, dont on lettre la ligne 411
        ModePaiement $mode,
        Compte $compteTresorerie,
        \DateTimeInterface $datePaiement,
        ?string $libelle = null,
    ): Transaction;

    public function pourDepenseComptant(/* symétrique */): Transaction;
    public function pourDepenseACredit(/* symétrique */): Transaction;
    public function pourReglementFournisseur(/* symétrique */): Transaction;

    public function pourRemiseBancaire(
        RemiseBancaire $remise,
        Collection $lignes5112Sources,   // les lignes 5112 à inclure dans le dépôt
    ): Transaction;                       // T4 = 512/5112 splittée par tiers
}
```

### 4.2. Invariants vérifiés par le service

1. **Équilibre** : ∑ débits = ∑ crédits sur la transaction générée. Validation en sortie de méthode, throw `EcritureNonEquilibreeException` sinon.
2. **Cohérence comptes/classe** : produit sur classe 7, charge sur classe 6, trésorerie sur classe 5, tiers sur classe 4. Throw `CompteIncorrectException` sinon.
3. **Tenant** : tous les comptes/tiers passés en argument appartiennent au tenant courant. Throw `TenantBoundaryException` sinon.
4. **Lettrage** : si l'opération produit un appariement (encaissement créance, remise), un `lettrage_code` est généré (UUID-short 20 chars) et appliqué aux lignes appariées via `LettrageService::lettrer()`.

### 4.3. Matrice de génération (référence)

| Saisie utilisateur | École C — écritures générées |
|---|---|
| **Recette comptant chèque** | T1 : `5112 D X (tiers) / 706 C X` |
| **Dépôt remise** | T4 : `512 D total / 5112 C par tiers splitté` + auto-lettrage par paire |
| **Recette comptant espèces** | T1 : `530 D X (tiers) / 706 C X` |
| **Dépôt espèces banque** | T4 : `512 D total / 530 C par tiers splitté` + auto-lettrage par paire |
| **Recette virement** | T1 : `512 D X (tiers) / 706 C X` (1 transaction, pas de portage) |
| **Recette CB HelloAsso** | T1 : `512 D X (tiers) / 706 C X` (1 transaction, sync API) |
| **Recette à crédit** | T1 : `411 D X (tiers) / 706 C X` |
| **Encaissement créance** | T2 : `5112 ou 530 ou 512 D X (tiers) / 411 C X (tiers)` + auto-lettrage paire 411 |
| **Dépense comptant chèque** | T1 : `607 D X / 512 C X (tiers)` — chèque émis débite 512 directement. Le décalage entre émission et débit bancaire est géré par le rappro (ligne 512 non pointée tant que la banque n'a pas débité). Pas de 5112 miroir. |
| **Dépense comptant CB** | T1 : `607 D X / 512 C X (tiers)` |
| **Dépense comptant espèces** | T1 : `607 D X / 530 C X (tiers)` |
| **Dépense à crédit (facture fournisseur)** | T1 : `607 D X / 401 C X (tiers)` |
| **Règlement fournisseur** | T2 : `401 D X (tiers) / 512 C X` + auto-lettrage paire 401 |

**Décision actée** : pas de compte miroir pour les chèques émis (asymétrie volontaire avec les chèques reçus qui passent par 5112). Raison : le 5112 sert à représenter les **valeurs physiques en main** (chèques reçus qui dorment dans le tiroir). Pour un chèque émis, l'asso n'a plus rien en main — elle a confié le chèque au fournisseur. L'attente du débit bancaire est gérée par le statut « non pointé » sur 512. Plus simple, sémantiquement plus juste.

### 4.4. Splittage par tiers de la remise (Variante 2a validée)

Pour `pourRemiseBancaire(remise, lignes5112Sources)` :

1. Grouper `lignes5112Sources` par `tiers_id` (si plusieurs lignes même tiers, somme par tiers)
2. Calculer le total = ∑ montants entrants
3. Créer la transaction T4 dans `DB::transaction` :
   - 1 ligne `512` débit `total`, sans tiers
   - N lignes `5112` crédit, **une par tiers groupé**, avec `tiers_id` rempli
4. Pour chaque ligne sortante 5112 : générer un `lettrage_code` unique et appliquer à la paire (ligne entrante du tiers + ligne sortante du tiers via `LettrageService::lettrer()`). Si plusieurs lignes entrantes pour le même tiers → toutes lettrées sous le même code.

---

## 5. Service `LettrageService`

### 5.1. Interface publique

```php
final class LettrageService
{
    /**
     * @param Collection<TransactionLigne> $lignes  Toutes sur le même compte, même tenant.
     * @param string|null $code  Si null, généré (UUID-short 20 chars).
     * @param string|null $motif  Optionnel, écrit en lettrage_audit.
     * @return string  Le code de lettrage utilisé.
     */
    public function lettrer(Collection $lignes, ?string $code = null, ?string $motif = null): string;

    /**
     * Délettre toutes les lignes portant ce code.
     */
    public function delettrer(string $code, ?string $motif = null): void;

    /**
     * Délettre une ligne et toutes celles qui partagent son lettrage_code.
     * Pratique pour auto-délettrage à l'extourne.
     */
    public function delettrerParLigne(TransactionLigne $ligne, ?string $motif = null): void;
}
```

### 5.2. Invariants

1. **Compte unique** : toutes les lignes d'un lettrage partagent le même `compte_id`. Throw sinon.
2. **Compte lettrable** : `compte.lettrable = TRUE`. Throw sinon.
3. **Équilibre** : ∑ (debit - credit) sur les lignes = 0. Throw `LettrageNonEquilibreException` sinon.
4. **Pas de relettrage** : si l'une des lignes a déjà un `lettrage_code`, throw `LettrageDejaPresentException`. Le caller doit délettrer d'abord.
5. **Tenant** : toutes les lignes appartiennent au tenant courant. Throw sinon.

### 5.3. Audit

Chaque appel `lettrer` ou `delettrer` génère une ligne dans `lettrage_audit` avec :
- `action`
- `lettrage_code`
- `compte_id`
- `transaction_ligne_ids` (snapshot JSON)
- `user_id` (résolu via `Auth::id()` si dispo, NULL si appel système — backfill, jobs)
- `motif`

### 5.4. Auto-délettrage sur extourne

`TransactionExtourneService::extourner(Transaction $tx)` (livré v4.2.2) est enrichi :

Pour chaque ligne de la transaction extournée qui porte un `lettrage_code` :
- Appel `LettrageService::delettrerParLigne($ligne, motif: 'Auto-délettrage suite à extourne de TX#' . $tx->id)`
- L'extourne génère sa transaction miroir comme avant, mais ses lignes ne sont **pas auto-lettrées** avec l'origine (on ne veut pas lettrer l'erreur avec sa correction — on veut que les deux apparaissent ouvertes pour que l'utilisateur les voit puis re-saisisse ce qu'il faut).

---

## 6. Compte de résultat rebranché

### 6.1. Source de vérité actuelle

`App\Services\Rapports\CompteResultatBuilder` lit :
- `transaction_lignes.sous_categorie_id` → `sous_categories.categorie_id` → `categories.classe` (6 ou 7)
- Somme `transaction_lignes.montant`, signé positivement (modèle actuel : montant >= 0, signe porté par `transactions.type`)

### 6.2. Branchement cible

Le builder lit :
- `transaction_lignes.compte_id` → `comptes.classe` (filtre classe IN (6, 7))
- Pour produits (classe 7) : ∑ `credit - debit`
- Pour charges (classe 6) : ∑ `debit - credit`

Résultat = ∑ produits − ∑ charges (signe naturel).

### 6.3. Test de non-régression

Le test capital du slice 1 est de **comparer ligne à ligne** le CR produit par l'ancien builder (avant backfill) avec celui produit par le nouveau (après backfill) sur l'exercice courant. **Tolérance : 0,00€**. Tout écart = bug de backfill ou de branchement.

Implementation : pendant le slice 1 et avant suppression de l'ancien code, on garde les deux builders accessibles via feature flag. Un test Pest `tests/Feature/CR/ParteIngressDoubleEquivalenceTest.php` lance les deux sur l'exercice courant et compare. Vert obligatoire avant merge.

### 6.4. Provisions

`ProvisionService` (v2.10.0) continue d'enrichir le CR avec provisions/extournes virtuelles. Sa logique est indépendante du backend partie double — elle agrège des `Provision` à part. À conserver tel quel slice 1.

---

## 7. Rapprochement bancaire rebranché

### 7.1. Source de vérité actuelle

`RapprochementBancaireService` lit `transactions WHERE compte_id = ? AND rapprochement_id IS NULL`, avec `GROUP BY remise_id` pour agréger les transactions d'une même remise.

### 7.2. Branchement cible

Le service lit les **lignes** classe 5 sur le compte 512 cible :
- `transaction_lignes WHERE compte_id = 512X AND rapprochement_id IS NULL` (note : le champ `rapprochement_id` migre du header vers la ligne, ou reste à l'entête — à acter en step 1 selon densité ; recommandation : conserver à l'entête car une transaction = un mouvement bancaire pointable, jamais splittée par rapprochement)

Plus de `GROUP BY remise_id` nécessaire : une remise est désormais **une seule transaction T4** avec **une seule ligne 512**. Elle apparaît naturellement comme une ligne pointable unique.

### 7.3. Affichage à l'écran

- Une transaction de recette virement → 1 ligne (la ligne 512 de la transaction)
- Une transaction de dépense par CB → 1 ligne (la ligne 512)
- Une remise bancaire (3 chèques déposés) → 1 ligne (la ligne 512 de T4, montant agrégé)
- Une transaction de dépense par chèque émis → apparaît au rappro comme une ligne 512 non pointée. Pointée quand la banque débite (cohérent avec le comportement actuel).

### 7.4. Test de non-régression

Symétrique au CR : un test Pest qui rappro le même mois sur les deux moteurs (ancien `compte_id` à l'entête + GROUP BY remise, nouveau `compte_id` sur les lignes) et compare les lignes affichées. **Tolérance : 0 ligne d'écart**, même libellés, même montants.

---

## 8. Backfill de l'exercice courant

### 8.1. Périmètre

L'instance unique en prod (SVS) est dans son **premier exercice ouvert** (01/09/2025 → 31/08/2026, contexte du 2026-05-19 → exercice en cours). Toutes les transactions de cet exercice doivent être converties au nouveau modèle.

Pas de transactions historiques d'exercices antérieurs à convertir.

### 8.2. Commande artisan

```bash
php artisan compta:backfill-partie-double {--exercice=current} {--dry-run}
```

`--dry-run` : produit un rapport sans modifier les données (audit des sous-catégories sans `code_cerfa`, des modes de paiement non couverts par la matrice, des cas limites).

Implémentation : `App\Console\Commands\BackfillPartieDoubleCommand` parcourt les transactions de l'exercice cible et applique pour chacune :

1. Lecture du `type` (recette/dépense) + `mode_paiement` + `compte_id` + lignes actuelles (`sous_categorie_id`, `montant`)
2. Mapping sous_catégorie → compte (via `code_cerfa` puis lookup `comptes.numero_pcg`)
3. Application de la matrice §4.3 pour reconstruire les lignes en débit/crédit partie double
4. Pour les recettes/dépenses comptant : génération des écritures de portage (5112 ou 530) si mode chèque/espèces. **Si la transaction est déjà rapprochée → on génère également la T4 de dépôt** pour ne pas laisser le 5112 ouvert. Sinon → on laisse 5112 ouvert (chèque non encore déposé).
5. Application du `lettrage_code` automatique pour les paires complètes
6. Update `transaction_lignes` (`debit`, `credit`, `compte_id`, `tiers_id`, `lettrage_code`)
7. Update `transactions` (`equilibree = TRUE` après vérification, conservation `type`/`compte_id` temporaire pour compat)

### 8.3. Cas particuliers gérés

| Cas | Comportement backfill |
|---|---|
| Transaction sans tiers (rare : ajustement manuel asso) | Lignes générées sans `tiers_id`. Pas de lettrage. Apparaît comme OD-like en lecture. |
| Sous-catégorie sans `code_cerfa` | Audit pré-backfill. Soit affectation manuelle, soit rejet du backfill avec liste à corriger. |
| Remise bancaire existante (groupant N transactions chèque) | Génération de la transaction T4 partie double splittée par tiers + lettrage. La table `remises_bancaires` est conservée telle quelle. |
| HelloAsso CB (transactions issues du webhook) | Mode = CB, traité comme virement reçu (T1 directe 512/706). |
| Transaction extournée (livré v4.2.2) | Lignes de l'origine ET de l'extourne convertis selon la matrice. Pas d'auto-lettrage entre origine et extourne (volontairement non lettré pour rester visible). |
| Provision (livré v2.10.0) | Hors backfill — `Provision` reste sa propre entité, n'a pas de `Transaction` associée. Inchangée. |

### 8.4. Validation post-backfill

Le test de non-régression §6.3 et §7.4 (CR identique, rappro identique) est exécuté **automatiquement à la fin du backfill**. Échec → rollback de la transaction DB englobante → backfill avorté.

### 8.5. Reversibilité

Le backfill s'exécute dans une `DB::transaction()`. Si le test post-validation échoue, rollback complet. Sinon, commit.

Les colonnes droppées en fin de slice 1 (`sous_categorie_id`, `montant`, `type`) ne sont droppées **qu'après** une période de stabilité (1 semaine en prod minimum), via une migration séparée dans une PR ultérieure. Slice 1 livre le code qui ne les utilise plus, mais la suppression physique est différée pour rollback de sécurité.

---

## 9. Renommage `sous_categorie` → `compte`

### 9.1. Renommages dans le code

| Fichier(s) | Avant | Après |
|---|---|---|
| Modèle | `App\Models\SousCategorie` | `App\Models\Compte` |
| Table (alias Eloquent uniquement) | `sous_categories` | `comptes` (vraie nouvelle table avec migration) |
| Relations | `transaction_lignes->sousCategorie()` | `transaction_lignes->compte()` |
| Property name | `$ligne->sous_categorie_id` | `$ligne->compte_id` |
| Code/UI | « Sous-catégorie » | « Compte » |
| Routes / écrans | `/parametres/sous-categories` | `/parametres/comptes` (+ redirect 301) |
| Tests | `SousCategorieFactory` | `CompteFactory` |

### 9.2. Stratégie

1. Migration crée la table `comptes` avec données seedées depuis `sous_categories`
2. `comptes_bancaires` rows sont insérées en parallèle dans `comptes` (classe 5, attributs bancaires en colonnes)
3. Modèle `SousCategorie` reste comme **alias deprecated** pendant le slice 1, étend `Compte`, lance des warnings en log
4. Tous les usages dans le code sont migrés progressivement (search/replace assisté)
5. Fin du slice 1 : `SousCategorie` modèle supprimé, redirect 301 sur les anciennes routes

### 9.3. Préserver l'historique catégorie

La table `categories` (catégorie comptable de regroupement, ex: « Recettes courantes », « Charges de fonctionnement ») est **conservée telle quelle**. `comptes.categorie_id` y pointe pour le regroupement UI dans les écrans Paramètres et le compte de résultat.

---

## 10. Acceptance Criteria

### Fonctionnel (non-régression utilisateur)

- [ ] **CR identique** : compte de résultat affiché pour l'exercice courant produit les mêmes lignes et les mêmes totaux que pré-slice 1 (tolérance 0,00€)
- [ ] **Rappro identique** : rapprochement bancaire affiche les mêmes lignes pointables, mêmes libellés, mêmes montants, mêmes statuts pointés
- [ ] **Saisie recette/dépense** : les écrans actuels continuent de fonctionner sans changement perceptible. Sous le capot, les écritures générées sont partie double équilibrées.
- [ ] **Saisie remise bancaire** : écran inchangé. La remise génère une transaction T4 partie double splittée par tiers, auto-lettrée.
- [ ] **Saisie facture (Factur-X)** : `FactureService::valider()` continue de fonctionner. Il appelle désormais `EcritureGenerator::pourRecetteACredit()` pour générer la transaction `411/706`. Encaissement via `pourEncaissementCreance()` lette automatiquement la paire 411. Pas de régression visible utilisateur. Test de non-régression dédié sur le module facturation.
- [ ] **Encaissement créance** : depuis l'écran existant, génère T2 `5112 ou 512 / 411` avec auto-lettrage de la paire 411
- [ ] **Fiche tiers** : affiche désormais **solde ouvert** (= ce que le tiers doit / ce qu'on lui doit) au lieu d'un solde courant qui mélange tout
- [ ] **Extourne d'une transaction lettrée** : déclenche auto-délettrage des lignes lettrées de la transaction origine
- [ ] **Provisions** : continue de fonctionner inchangé (alimente le CR comme avant)

### Technique

- [ ] **Suite Pest verte** : 0 failures, 0 errors, 0 skipped (hors skipped pré-existants)
- [ ] **Pint vert** : sans correction
- [ ] **Tenant isolation préservée** : 12 tests d'intrusion multi-tenant de S6 restent verts. Aucune nouvelle fuite. Toutes les nouvelles tables (`comptes`, `lettrage_audit`) ont une `association_id` et le scope global.
- [ ] **Performance** : sur l'exercice complet (~quelques milliers de transactions), CR < 500ms, rappro mensuel < 200ms (mêmes ordres qu'avant).
- [ ] **Backfill complet sans avortement** : la commande artisan termine avec succès, tous les invariants validés.
- [ ] **Tests de non-régression CR et rappro** : verts (comparaison ligne à ligne).

### Sémantique partie double

- [ ] **Invariant équilibre** : aucune transaction n'a `∑ debit ≠ ∑ credit`. Test global Pest qui scanne toutes les transactions de l'exercice après backfill.
- [ ] **Invariant lettrage** : pour chaque `lettrage_code` distinct, ∑ (debit - credit) = 0. Test global.
- [ ] **Invariant tenant** : aucune `transaction_ligne.compte_id` ne pointe vers un compte d'un autre tenant. Test cross-check `comptes.association_id = transactions.association_id`.

### Audit

- [ ] **Lettrage audit complet** : chaque opération de lettrage ou délettrage génère une ligne dans `lettrage_audit` avec `user_id` (ou NULL si système), `motif`, `transaction_ligne_ids`.

---

## 11. Scénarios BDD

```gherkin
Fonctionnalité: Fondations partie double — Slice 1

Contexte:
  Étant donné l'association "SVS" est dans son exercice 2025-2026
  Et le plan de comptes est seedé (411, 401, 5112, 530, 512BNP, comptes 6/7 issus des sous-catégories existantes)

# ============================================================
# Génération automatique des écritures
# ============================================================

Scénario: Recette comptant chèque — génération T1
  Étant donné un tiers "Pierre Dupont"
  Et le compte "706 Cotisations"
  Quand je saisis une recette de 50€ par chèque de Pierre Dupont sur le compte 706 le 15/09/2025
  Alors une transaction T1 est créée le 15/09/2025
  Et T1 contient 2 lignes :
    | compte | debit | credit | tiers          | lettrage |
    | 5112   | 50.00 | 0.00   | Pierre Dupont  | NULL     |
    | 706    | 0.00  | 50.00  | NULL           | NULL     |
  Et T1 est équilibrée (∑ debit = ∑ credit = 50.00)

Scénario: Dépôt remise — 3 chèques splittés par tiers + auto-lettrage
  Étant donné 3 transactions de recettes comptant chèque :
    | tiers          | montant | date       |
    | Pierre Dupont  | 50      | 15/09/2025 |
    | Paul Martin    | 30      | 16/09/2025 |
    | Jeanne Bernard | 20      | 17/09/2025 |
  Et chaque transaction a une ligne 5112 +X non lettrée
  Quand je crée une remise bancaire le 20/09/2025 incluant ces 3 chèques sur le compte 512BNP
  Alors une transaction T4 est créée le 20/09/2025 avec 4 lignes :
    | compte | debit  | credit | tiers          | lettrage_code |
    | 512BNP | 100.00 | 0.00   | NULL           | NULL          |
    | 5112   | 0.00   | 50.00  | Pierre Dupont  | AAA           |
    | 5112   | 0.00   | 30.00  | Paul Martin    | AAB           |
    | 5112   | 0.00   | 20.00  | Jeanne Bernard | AAC           |
  Et les 3 lignes 5112 entrantes des recettes initiales reçoivent les codes AAA, AAB, AAC respectivement
  Et le solde ouvert 5112 pour chacun des 3 tiers est de 0.00
  Et la ligne 512 BNP n'est pas lettrée
  Et la remise n°N existe dans la table `remises_bancaires` avec compte_cible = 512BNP

Scénario: Recette à crédit puis encaissement — passage par 411
  Étant donné un tiers "Pierre Dupont"
  Quand je saisis une recette à crédit de 50€ le 01/09/2025 sur le compte 706
  Alors une transaction T1 est créée :
    | compte | debit | credit | tiers          | lettrage |
    | 411    | 50.00 | 0.00   | Pierre Dupont  | NULL     |
    | 706    | 0.00  | 50.00  | NULL           | NULL     |
  Et le solde ouvert 411 pour Pierre Dupont est 50.00 (créance ouverte)

  Quand je saisis l'encaissement de cette créance par virement le 10/09/2025 sur 512BNP
  Alors une transaction T2 est créée :
    | compte | debit | credit | tiers          | lettrage |
    | 512BNP | 50.00 | 0.00   | NULL           | NULL     |
    | 411    | 0.00  | 50.00  | Pierre Dupont  | XXX      |
  Et la ligne 411 de T1 reçoit le lettrage_code XXX
  Et le solde ouvert 411 pour Pierre Dupont est 0.00

# ============================================================
# Délettrage automatique
# ============================================================

Scénario: Extourne d'une transaction lettrée déclenche le délettrage
  Étant donné une recette à crédit T1 411 D50 / 706 C50 le 01/09 (tiers Pierre)
  Et un encaissement T2 5112 D50 / 411 C50 le 10/09 (lettrage_code = XXX appliqué aux lignes 411 de T1 et T2)
  Quand j'extourne T2 via TransactionExtourneService
  Alors la transaction miroir T2' est créée avec lettrage_code NULL
  Et la ligne 411 de T1 a son lettrage_code repassé à NULL
  Et la ligne 411 de T2 a son lettrage_code repassé à NULL
  Et l'audit `lettrage_audit` contient une ligne action='delettre' code='XXX' motif='Auto-délettrage suite à extourne de TX#T2'
  Et le solde ouvert 411 pour Pierre Dupont remonte à 50.00 (créance rouverte)

Scénario: Délettrage programmatique
  Étant donné un lettrage_code 'XXX' appliqué à 2 lignes 411
  Quand j'appelle LettrageService::delettrer('XXX', motif: 'erreur de saisie')
  Alors les 2 lignes ont leur lettrage_code repassé à NULL
  Et l'audit `lettrage_audit` contient une ligne action='delettre' code='XXX' user_id=<Auth::id()> motif='erreur de saisie'

# ============================================================
# Invariants
# ============================================================

Scénario: Refus d'une transaction non équilibrée
  Quand je tente de persister une transaction avec :
    | compte | debit | credit |
    | 706    | 0     | 50     |
    | 5112   | 30    | 0      |
  Alors une EcritureNonEquilibreeException est levée
  Et aucune transaction n'est persistée

Scénario: Refus d'un lettrage non équilibré
  Étant donné 2 lignes sur le compte 411 du tiers Pierre :
    | debit | credit |
    | 50    | 0      |
    | 0     | 30     |
  Quand j'appelle LettrageService::lettrer([ligne1, ligne2])
  Alors une LettrageNonEquilibreException est levée
  Et aucune ligne ne reçoit de lettrage_code

Scénario: Refus de lettrer un compte non lettrable (512)
  Quand j'appelle LettrageService::lettrer sur des lignes du compte 512BNP
  Alors une CompteNonLettrableException est levée

Scénario: Tenant isolation sur lettrage
  Étant donné une ligne du tenant A et une ligne du tenant B (mêmes montants opposés)
  Quand j'appelle LettrageService::lettrer en étant connecté au tenant A
  Alors une TenantBoundaryException est levée

# ============================================================
# Backfill et non-régression
# ============================================================

Scénario: Backfill complet sur exercice courant
  Étant donné l'exercice 2025-2026 contient ~N transactions historiques en modèle ancien
  Quand j'exécute `php artisan compta:backfill-partie-double --exercice=current`
  Alors toutes les transactions ont leurs lignes converties en débit/crédit
  Et toutes les transactions ont equilibree=TRUE
  Et le compte de résultat post-backfill est identique au CR pré-backfill (tolérance 0.00€)
  Et le rapprochement bancaire post-backfill affiche les mêmes lignes pointables que pré-backfill

Scénario: Dry-run backfill détecte les sous-catégories non mappées
  Étant donné une sous-catégorie sans code_cerfa
  Quand j'exécute `php artisan compta:backfill-partie-double --dry-run`
  Alors la commande produit un rapport listant les sous-catégories non mappées
  Et aucune modification n'est appliquée à la base
```

---

## 12. Non-objectifs (explicites)

Pour éviter toute dérive de scope, le slice 1 **ne livre pas** :

- ❌ Bilan (ni vue ni PDF ni export)
- ❌ Déclaration TVA (CA3, comptes 4456X)
- ❌ Immobilisations (compte 2X, amortissements 28X)
- ❌ Écritures d'à-nouveau formelles à la clôture (la table `lettrage_audit` peut servir, mais le générateur AN est slice 2)
- ❌ OD libres saisies à la main par l'utilisateur (slice 2 : écran de saisie expert)
- ❌ UI manuelle de lettrage / délettrage
- ❌ Flow chèque impayé (réouverture créance + écriture corrective) — slice dédié
- ❌ FEC export (slice 3+)
- ❌ Balance générale, grand livre détaillé (slice 2+)
- ❌ Changement de l'UX recettes/dépenses pour l'utilisateur final
- ❌ Suppression du flag `actif_recettes_depenses` (orthogonal — peut bouger plus tard)

---

## 13. Risques et mitigations

| Risque | Impact | Mitigation |
|---|---|---|
| Backfill produit un CR différent post-conversion | Régression utilisateur visible, perte de confiance | Test automatique de comparaison ligne à ligne, rollback DB sur écart |
| Sous-catégories sans `code_cerfa` empêchent backfill | Backfill avorté | Dry-run préalable obligatoire, rapport d'audit, étape de correction manuelle |
| Performance dégradée sur listes longues (transactions, rappro) | UX lente, plaintes | Index `(compte_id, tiers_id, lettrage_code)` et `(compte_id, date)` posés dès slice 1. Benchmark sur exercice complet en step final. |
| Renommage `sous_categorie` → `compte` casse du code non testé | Régression silencieuse | Tests Pest couvrent tous les écrans listant des comptes. Grep exhaustif pré-merge. |
| Auto-délettrage à l'extourne perd l'historique | Audit compromis | `lettrage_audit` append-only conserve la trace de tous les codes et opérations |
| Confusion équipe entre 5112 et 512 dans les manipulations futures | Erreurs de saisie | Pas d'impact slice 1 (auto-génération uniquement) ; documentation interne `docs/compta-partie-double.md` produite en step final |
| Plan de comptes 411/401 trop simpliste (pas de sous-comptes par tiers) | Limitation pour assos qui voudront un grand livre 411 par tiers à l'avenir | Acceptable : la fiche tiers + lettrage suffisent. Si besoin émerge, sous-comptes par tiers possibles slice 3+ sans casser le modèle (juste enrichissement des numéros de compte). |
| Volume écritures gonfle (1 transaction utilisateur = 2 transactions backend en cas de portage) | DB plus dense | Acceptable : quelques milliers d'écritures par an, négligeable. Permet la précision sémantique requise. |

---

## 14. Ouvertures slice 2+

Pour information, le slice 1 prépare les éléments suivants qui seront capitalisés ultérieurement :

- **Slice 2 — Clôture et à-nouveau formels** : génère les écritures AN au début de chaque exercice à partir des soldes de clôture des comptes de bilan. Nécessite que `transaction_lignes.debit/credit` et `comptes.classe` existent → ✓ slice 1.
- **Slice 2 — UI lettrage / délettrage manuel** : écran « Comptes à lettrer » qui présente les lignes non lettrées d'un compte et permet le lettrage à la souris.
- **Slice 3 — Bilan** : agrégation des soldes classes 1-5 incluant AN. Vue Actif/Passif. Nécessite slice 1 + slice 2.
- **Slice 3 — Chèque impayé** : flow utilisateur dédié qui re-débite 411, délettre la paire concernée, et crée une écriture de réajustement.
- **Slice 4+ — TVA** : ajout colonnes `montant_ht`, `taux_tva`, `montant_tva` sur `transaction_lignes`. Écritures `44566` / `44571`. État CA3 dérivé. Nécessite slice 1.
- **Slice 4+ — Immobilisations** : entité `Immobilisation` + amortissements calculés à la lecture (pattern Provision). Nécessite slice 1.
- **Slice 5+ — FEC** : export réglementaire dérivé des lignes débit/crédit avec format standard. Nécessite slice 1 + slice 2 (AN).
- **Slice ? — Mode partie double exposé à l'utilisateur** : préférence `Association::mode_compta`, déverrouille l'UI experte (saisie OD libres, vue balance générale). Aucune migration de données — c'est un flag UI.

---

## 15. Découpage indicatif step build (à affiner en `/plan`)

Cet appoint sert juste à valider que le slice est de taille raisonnable.

| Step | Périmètre | Tests |
|---|---|---|
| 1 | Audit sous-catégories sans `code_cerfa`, validation mode_paiement, décisions de seed | Dry-run script |
| 2 | Migration `comptes` (table + seed depuis sous_categories + comptes_bancaires + nouveaux 411/401/5112/530) | Migration test |
| 3 | Modification `transaction_lignes` (colonnes `compte_id`, `debit`, `credit`, `tiers_id`, `lettrage_code`, `libelle`) | Migration test |
| 4 | Modèle `Compte` + alias deprecated `SousCategorie` | Unit tests |
| 5 | `LettrageService` + `lettrage_audit` table | Unit + Feature |
| 6 | `EcritureGenerator` (matrice §4.3) | Unit + Feature |
| 7 | Branchement saisie recette/dépense sur EcritureGenerator (Livewire components) | Feature |
| 8 | Branchement remise bancaire (Variante 2a) | Feature |
| 9 | Branchement encaissement créance + auto-lettrage 411 | Feature |
| 10 | `CompteResultatBuilder` rebranché sur `compte_id` + test de non-régression | Feature |
| 11 | `RapprochementBancaireService` rebranché sur lignes classe 5 + test de non-régression | Feature |
| 12 | Auto-délettrage sur extourne dans `TransactionExtourneService` | Feature |
| 13 | Backfill artisan (dry-run + run) | Feature |
| 14 | Renommage `sous_categorie` → `compte` (refacto transverse) + redirects 301 | Feature |
| 15 | Drop colonnes legacy `sous_categorie_id`, `montant`, `type` (migration différée, PR séparée) | Migration test |
| 16 | Doc interne `docs/compta-partie-double.md` + ADR pour acter la révision de `project_compta_partie_double.md` | — |

Estimation grossière : ~3-4 semaines de travail subagent-driven. Step 13 (backfill) et 14 (renommage) sont les plus risqués.

---

## 16. Référence

- Mémoire d'origine : `project_compta_partie_double.md` — cadrage initial cash basis enrichie, **révisé par cette spec**
- Mémoire connexe : `project_adr_remise_bancaire.md` — ADR-001 sur la remise. **Le nouveau modèle absorbe l'ADR-001** : la remise devient une écriture comptable splittée par tiers, le `statut_reglement` (recu/depose/pointe) disparaît au profit du lettrage.
- Mémoire connexe : `project_provisions.md` — provisions livrées v2.10.0, conservées telles quelles.
- Mémoire connexe : `project_extourne_annulation_facture_programme.md` — extournes livrées v4.2.2, enrichies d'auto-délettrage en step 12.
