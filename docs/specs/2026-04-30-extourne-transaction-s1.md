# Extourne de transaction — Slice 1 (primitive d'écriture en sens contraire + lettrage)

**Date** : 2026-04-30
**Statut** : spec PASS (consistency gate ✅), prête pour `/plan`
**Programme** : Annulation de facture & extourne — refonte de la dette technique de l'avoir
**Périmètre** : Slice 1 d'un programme à 3 slices (S0 audit signe négatif, S1 extourne, S2 annulation de facture). Livre la **primitive autonome** d'extourne d'une transaction recette (sans facture sous-jacente nécessaire) ainsi que le mécanisme de **lettrage** pour les neutralisations sans flux bancaire. Le Slice 2 (annulation de facture par avoir) s'appuiera sur cette primitive et sera spécifié séparément après livraison du Slice 1.
**Préalables** : Slice 0 livré (`docs/specs/2026-04-30-audit-signe-negatif-s0.md`). Branche source : `main` post-S0.
**Hors scope (slice futur)** : annulation de facture (Slice 2), extourne de transaction de sens **dépense**, extourne partielle (montant inférieur à l'origine), extourne d'une extourne, ré-émission corrective de facture, dé-lettrage manuel, gestion d'exercice clos.
**Vocabulaire** : "Extourne" en code et domain (terme comptable français standard, le PCG admet l'écriture en sens contraire), "Annulation" en UI / libellés / PDF (langue accessible utilisateur). Les deux désignent la même chose.

**Glossaire — distinction avec les extournes de provisions existantes** :

Le code AgoraGestion utilise **déjà** le mot "extourne" dans `ProvisionService::extournesExercice()` et `totalExtournes()` (livré v2.10.0) pour désigner le retournement automatique virtuel des `Provision` N→N+1 (PCA / FNP). Ce mécanisme n'écrit pas en table `transactions` ; il calcule des montants signés à la lecture du compte de résultat et du flux trésorerie. Le présent slice introduit un mécanisme **distinct** : la contre-passation **manuelle** d'une `Transaction` réelle, matérialisée par une seconde `Transaction` à montant négatif et reliée par la table `extournes`. Pour désambiguïser :

| Concept | Code | Effet |
|---|---|---|
| Extourne **virtuelle** de provision | `ProvisionService::extournesExercice($annee)` | Calcul à la lecture, pas d'écriture en `transactions` |
| Extourne **matérielle** de transaction (S1) | `TransactionExtourneService::extourner($tx)` | Crée une seconde `Transaction` négative + entrée table `extournes` + lettrage éventuel |

Le service Slice 1 est donc nommé **`TransactionExtourneService`** (et non `ExtourneService` simple) pour refléter son périmètre et éviter toute confusion. Le modèle Eloquent garde le nom court `Extourne` (table `extournes`) — le contexte d'usage suffit à lever l'ambiguïté.

---

## 1. Intent Description

**Quoi.** Introduire une primitive métier "Annuler la transaction" applicable à toute `Transaction` de sens **recette**, déclenchée par un bouton sur la fiche transaction (et accessible aux rôles Comptable et Admin uniquement). L'extourne génère une **seconde transaction recette de signe opposé** (montants négatifs sur la transaction et chacune de ses lignes), datée du jour de l'extourne, dans la même sous-catégorie / opération / séance / compte bancaire / tiers que l'origine. Le mode de paiement est repris de l'origine **et modifiable par l'utilisateur** au moment de la création (cas typique : règlement original par chèque, remboursement par virement). Le libellé par défaut est `"Annulation - {libellé origine}"`. Les deux transactions sont reliées par une nouvelle table dédiée `extournes (transaction_origine_id, transaction_extourne_id, rapprochement_lettrage_id, ...)`. Pour des raisons de performance et de simplicité de lecture (listes paginées, guards), un flag dénormalisé `transactions.extournee_at` est entretenu par le service en transaction atomique avec la création de l'entrée `extournes`.

Selon l'état de règlement de l'origine, le mécanisme se prolonge :

- **Origine `EnAttente`** (jamais touché la banque — typiquement une créance à recevoir non encaissée) : l'extourne naît `EnAttente` ; un **lettrage** est créé immédiatement, qui apparie origine et extourne (∑=0, sans flux bancaire). Le lettrage est un `RapprochementBancaire` de **type `lettrage`** (nouvelle valeur de l'enum, distincte du `bancaire` historique), à `solde_ouverture = solde_fin`, daté du jour, créé verrouillé. Les deux transactions passent au statut `Pointe` via le mécanisme existant `rapprochement_id`. Elles disparaissent ainsi naturellement des Créances à recevoir. Le lettrage est consultable dans le même écran que les rapprochements bancaires ordinaires, avec un filtre `type` permettant de basculer entre `Tous` / `Bancaire` / `Lettrage` (par défaut `Bancaire`).
- **Origine `Recu`** (déjà encaissée, déjà pointée à un rapprochement bancaire ordinaire) : l'extourne naît `EnAttente` ; **pas** de lettrage automatique. L'extourne attend dans la liste des transactions à pointer du compte bancaire, et l'utilisateur la pointera dans un futur **rapprochement bancaire ordinaire** quand le débit réel (chèque émis, virement sortant) apparaîtra à l'extrait. C'est la matérialisation comptable du remboursement à venir.

**Pourquoi.** Aujourd'hui, le système ne sait pas annuler une recette enregistrée. Pour rembourser un règlement (cas typique : participant absent à une séance pour raison de santé, chèque encaissé, on rembourse), l'utilisateur doit saisir une **dépense de remboursement** dans une sous-catégorie de dépense ad hoc, ce qui fausse la lecture économique du compte de résultat (la cotisation apparaît comme reçue, le remboursement comme une dépense distincte) et casse la traçabilité avec le règlement d'origine. La primitive d'extourne corrige ces deux défauts en autorisant l'écriture en sens contraire (montant négatif) sur la même sous-catégorie, ce qui est la pratique comptable standard et préserve la logique "produit constaté + produit annulé = produit net".

Cette primitive débloque par ailleurs le Slice 2 (annulation de facture par avoir), qui réutilisera l'extourne de transaction comme brique de base pour les lignes `MontantManuel` à neutraliser, et sera spécifié séparément.

**Quoi ce n'est pas.** Pas une suppression (soft-delete) de la transaction d'origine — celle-ci est conservée intacte, l'extourne s'ajoute. Pas une fonctionnalité d'avoir sur facture (Slice 2). Pas une extourne partielle (montant < origine). Pas une extourne de dépense (sens dépense est bloqué au MVP, le besoin n'est pas remonté). Pas une extourne en chaîne (extourner une extourne est interdit). Pas une régularisation de transaction issue de HelloAsso (verrou existant inchangé). Pas de gestion spécifique de l'exercice clos (limitation MVP : l'extourne se range dans l'exercice courant `ExerciceService::current()`, même si l'origine est dans un exercice clos — limitation documentée, à revisiter si remontée).

**Périmètre Slice 1.**
- Nouvelle table `extournes` (cf §3.1) — source de vérité du lien origine/extourne et du lettrage éventuel.
- Colonne dénormalisée `transactions.extournee_at` (datetime nullable), entretenue atomiquement par le service.
- Nouvelle valeur d'enum `TypeRapprochement::Lettrage` ; colonne `rapprochements_bancaires.type` (default `bancaire`, backfill complet).
- Service `ExtourneService::extourner(Transaction, payload): Extourne` qui orchestre check guards, création de la transaction miroir, création éventuelle du lettrage, set des flags, dans une `DB::transaction` unique.
- Bouton "Annuler la transaction" sur la fiche transaction (et indicateur dans les listes), affiché ssi `Transaction::isExtournable()` ET utilisateur a rôle Comptable ou Admin. Modale Bootstrap (pas de `confirm()` natif) avec champs : date (default today), libellé (default "Annulation - {origine}", éditable), mode de paiement (default origine, modifiable), notes (motif optionnel, libre, dans le champ existant).
- Filtre `type` sur l'écran "Rapprochements bancaires" (`Tous` / `Bancaire` / `Lettrage`, default `Bancaire`).
- Multi-tenant fail-closed : extourne strictement dans le tenant courant, scope global s'applique. La nouvelle table `extournes` étend `TenantModel`.
- Tests Pest Feature couvrant tous les scénarios BDD ci-dessous + tests d'intrusion multi-tenant.

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: Extourne (annulation) d'une transaction recette
  Pour pouvoir annuler comptablement un encaissement (remboursement, erreur, prestation non rendue) en respectant la traçabilité
  En tant que comptable ou admin
  Je sélectionne une transaction recette éligible et je l'annule via le bouton dédié
  Le système génère une transaction miroir de signe opposé et, si l'origine n'a jamais touché la banque, crée un lettrage qui les neutralise

  Contexte:
    Étant donné que je suis authentifié comme comptable de l'association "Asso A"
    Et que le tiers "Mr Dupont" existe dans "Asso A"
    Et que la sous-catégorie recette "Cotisations séance" existe dans "Asso A"
    Et que le compte bancaire "Caisse Épargne courant" existe dans "Asso A"

  Scénario: Annuler une recette non encaissée crée une extourne et un lettrage automatique
    Étant donné une transaction recette "Cotisation Mr Dupont mars" sur "Caisse Épargne courant"
      avec une ligne sous-catégorie "Cotisations séance" 80 €
      au statut "en attente"
      sans rapprochement banque
      sans facture liée
    Quand j'annule cette transaction (motif "Désistement séance 14/03 (santé)", date du jour, mode paiement inchangé)
    Alors une nouvelle transaction recette "Annulation - Cotisation Mr Dupont mars" est créée à la date du jour
      avec un montant de -80 €
      et une ligne sous-catégorie "Cotisations séance" -80 €
      et tiers "Mr Dupont"
      et compte "Caisse Épargne courant"
      et statut "pointé"
    Et la transaction d'origine passe également au statut "pointé"
    Et la transaction d'origine porte `extournee_at` = NOW
    Et une entrée `extournes` est créée avec `transaction_origine_id`, `transaction_extourne_id`, `rapprochement_lettrage_id`
    Et un rapprochement de type "lettrage" est créé sur "Caisse Épargne courant"
      à la date du jour
      avec solde_ouverture = solde_fin
      contenant exactement les deux transactions origine et extourne
    Et la transaction d'origine n'apparaît plus dans Créances à recevoir
    Et l'extourne n'apparaît pas dans Créances à recevoir non plus

  Scénario: Annuler une recette déjà encaissée crée une extourne en attente, sans lettrage
    Étant donné une transaction recette "Cotisation Mr Dupont mars" sur "Caisse Épargne courant"
      avec une ligne sous-catégorie "Cotisations séance" 80 €
      au statut "reçu"
      sans rapprochement banque verrouillé
    Quand j'annule cette transaction (motif "Remboursement chèque émis 30/04", mode paiement modifié en "virement")
    Alors une nouvelle transaction recette est créée à la date du jour
      avec un montant de -80 €
      avec mode de paiement "virement"
      et statut "en attente"
    Et la transaction d'origine reste au statut "reçu"
    Et la transaction d'origine porte `extournee_at` = NOW
    Et une entrée `extournes` est créée avec `rapprochement_lettrage_id` NULL
    Et l'extourne apparaît dans la liste des transactions à pointer du compte "Caisse Épargne courant"

  Scénario: L'extourne d'une recette encaissée se solde par pointage banque ordinaire
    Étant donné une transaction recette de 80 € au statut "reçu", déjà pointée au rapprochement bancaire R1 (verrouillé)
    Et son extourne -80 € au statut "en attente"
    Quand le rapprochement bancaire suivant R2 (type "bancaire") est créé
    Et que je pointe l'extourne -80 € contre la ligne "Chèque émis 30/04 -80 €" de l'extrait
    Et que je verrouille R2
    Alors l'extourne passe au statut "pointé"
    Et la transaction d'origine reste rattachée à R1 inchangée

  Scénario: Annuler une recette pointée banque verrouillée crée une extourne en attente, sans lettrage
    Étant donné une transaction recette au statut "pointé" dans le rapprochement bancaire R1 verrouillé
    Quand j'annule cette transaction
    Alors l'extourne -80 € est créée au statut "en attente"
    Et la transaction d'origine reste "pointé" dans R1 inchangée
    Et aucun lettrage n'est créé (cas symétrique du "déjà encaissée")

  Scénario: Modifier le libellé proposé à l'annulation
    Étant donné une transaction recette "Cotisation Mr Dupont mars"
    Quand j'ouvre la modale d'annulation
    Alors le champ libellé est pré-rempli avec "Annulation - Cotisation Mr Dupont mars"
    Et je peux le modifier avant de valider

  Scénario: Refus — utilisateur sans droit (rôle Gestionnaire)
    Étant donné un utilisateur Gestionnaire sur "Asso A"
    Quand il consulte une transaction recette éligible
    Alors le bouton "Annuler la transaction" n'est pas affiché
    Et un appel direct au service `ExtourneService::extourner` lève une exception d'autorisation

  Scénario: Refus — transaction de sens dépense non éligible (MVP)
    Étant donné une transaction dépense "Achat fournitures"
    Quand je consulte sa fiche
    Alors le bouton "Annuler la transaction" n'est pas affiché

  Scénario: Refus — transaction déjà extournée
    Étant donné une transaction recette qui a déjà été extournée (`extournee_at` non nul)
    Quand je consulte sa fiche
    Alors le bouton "Annuler la transaction" est désactivé avec le message "Cette transaction a déjà été annulée"
    Et un lien "Voir l'annulation" pointe vers la transaction d'extourne via la table extournes

  Scénario: Refus — transaction qui est elle-même une extourne
    Étant donné une transaction recette présente dans `extournes.transaction_extourne_id`
    Quand je consulte sa fiche
    Alors le bouton "Annuler la transaction" n'est pas affiché
    Et une mention "Cette transaction est une annulation de #X" est affichée avec lien vers l'origine

  Scénario: Refus — transaction rattachée à une facture validée
    Étant donné une transaction recette rattachée via le pivot `facture_transaction` à une facture au statut "validée"
    Quand je tente d'annuler cette transaction
    Alors une erreur indique "Cette transaction est portée par la facture F-2026-NNN. Annulez la facture pour la libérer."
    Et aucune extourne n'est créée

  Scénario: Refus — transaction issue de HelloAsso
    Étant donné une transaction recette dont `helloasso_order_id` est non nul
    Quand je consulte sa fiche
    Alors le bouton "Annuler la transaction" n'est pas affiché

  Scénario: Saisie manuelle d'un montant négatif refusée (rappel S0)
    Étant donné un formulaire de saisie de transaction recette
    Quand je saisis un montant -50 €
    Alors la validation refuse avec le message "Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante."

  Scénario: Compte de résultat reflète l'extourne (recette nette = 0)
    Étant donné une transaction recette de 80 € au statut "pointé" dans l'exercice 2026
    Et son extourne -80 € au statut "pointé" dans le même exercice
    Quand je consulte le compte de résultat de l'exercice 2026
    Alors la sous-catégorie "Cotisations séance" affiche un total de 0 €
    Et le détail montre les deux écritures (+80 € et -80 €)

  Scénario: Multi-tenant — extourne d'une transaction d'un autre tenant interdite
    Étant donné un comptable de "Asso A" qui tente d'invoquer le service d'extourne sur l'ID d'une transaction de "Asso B"
    Alors le scope global tenant `WHERE 1 = 0` retourne null et le service refuse avec "Transaction introuvable"

  Scénario: Lecture d'un lettrage dans l'écran rapprochements
    Étant donné des lettrages existants dans "Asso A"
    Quand je navigue vers "Rapprochements bancaires"
    Et que je sélectionne le filtre "Lettrage"
    Alors la liste affiche les lettrages avec date, compte, montant net (toujours 0), origine + extourne, nombre = 2 transactions
    Et le filtre par défaut "Bancaire" cache les lettrages
    Et le filtre "Tous" mélange les deux types

  Scénario: Indicateur visuel d'une transaction extournée dans une liste
    Étant donné une transaction extournée dans la liste universelle
    Alors un badge "annulée" est affiché sur la ligne d'origine
    Et la ligne d'extourne est rendue en italique avec préfixe "Annulation - " et montant négatif coloré

# Hors scope MVP — listés ici pour cadrage du Slice 1, à implémenter ultérieurement :
# - Annulation manuelle d'un lettrage (dé-lettrage : soft-delete du lettrage et repassage statuts à EnAttente)
# - Export PDF / Excel "Journal des annulations" dédié
# - Extension à l'extourne de dépense
# - Gestion explicite de l'exercice clos (à ce jour, l'extourne se range dans l'exercice courant sans contrôle)
```

---

## 3. Architecture Specification

### 3.1 Modèle de données

**Nouvelle table `extournes`** :

| Champ | Type | Notes |
|---|---|---|
| `id` | bigint unsigned PK | |
| `transaction_origine_id` | bigint unsigned, FK `transactions(id)` ON DELETE RESTRICT, **UNIQUE** | une transaction ne peut être extournée qu'une fois |
| `transaction_extourne_id` | bigint unsigned, FK `transactions(id)` ON DELETE RESTRICT, **UNIQUE** | une transaction d'extourne ne sert qu'une fois |
| `rapprochement_lettrage_id` | bigint unsigned NULLABLE, FK `rapprochements_bancaires(id)` ON DELETE RESTRICT | non-NULL ssi origine était `EnAttente` au moment de l'extourne |
| `association_id` | bigint unsigned, FK `associations(id)` | multi-tenant (TenantModel) |
| `created_by` | bigint unsigned, FK `users(id)` | traçabilité auteur |
| `created_at`, `updated_at`, `deleted_at` | timestamps | soft delete pour futur dé-lettrage |

Modèle Eloquent `App\Models\Extourne` étend `TenantModel`. Relations :
- `origine()` : `belongsTo(Transaction::class, 'transaction_origine_id')`
- `extourne()` : `belongsTo(Transaction::class, 'transaction_extourne_id')`
- `lettrage()` : `belongsTo(RapprochementBancaire::class, 'rapprochement_lettrage_id')`

**Table `transactions`** — colonne ajoutée :

| Champ | Type | Notes |
|---|---|---|
| `extournee_at` | timestamp NULLABLE | flag dénormalisé. NULL = non extournée. NON-NULL = extournée à cette date. Set / unset par `TransactionExtourneService` dans la même `DB::transaction` que l'écriture dans `extournes`. Index. |

**Table `rapprochements_bancaires`** — colonne ajoutée :

| Champ | Type | Notes |
|---|---|---|
| `type` | varchar(20) NOT NULL DEFAULT 'bancaire' | enum applicatif `TypeRapprochement` (`bancaire` / `lettrage`). Backfill `bancaire` à la migration. Index. |

**Contraintes spécifiques aux lettrages** (validation applicative, pas DB) :
- `type=lettrage` ⇒ exactement 2 transactions liées
- `type=lettrage` ⇒ `solde_ouverture = solde_fin`
- `type=lettrage` ⇒ ∑ montants des transactions liées = 0
- `type=lettrage` ⇒ les 2 transactions ont le même `compte_id` et le même `tiers_id` (cohérence)
- `type=lettrage` créé directement en `Verrouille` (pas d'EnCours pour ce type au MVP)

### 3.2 Enums

**`StatutReglement` (existant, inchangé)** : `EnAttente`, `Recu`, `Pointe`. Le mécanisme `rapprochement_id` propage `Pointe` aux transactions lettrées via le service.

**Nouvelle enum `TypeRapprochement`** :

```php
enum TypeRapprochement: string {
    case Bancaire = 'bancaire';
    case Lettrage = 'lettrage';

    public function label(): string { /* "Bancaire" / "Lettrage" */ }
}
```

Cast sur `RapprochementBancaire::$type`. Helpers `isLettrage(): bool`, `isBancaire(): bool` sur le modèle.

### 3.3 Services métier

**Nouveau service `App\Services\TransactionExtourneService`** :

| Méthode | Signature | Comportement |
|---|---|---|
| `extourner(Transaction $origine, ExtournePayload $payload): Extourne` | Retourne l'`Extourne` créée | `DB::transaction` atomique : (a) check guards (cf §3.4) + check rôle (Comptable ou Admin), (b) crée la `Transaction` miroir + `TransactionLignes` miroir 1:1 (signes inversés), avec libellé/date/mode_paiement issus du payload, (c) si origine `EnAttente`, crée `RapprochementBancaire` type=`Lettrage` verrouillé sur le compte de l'origine, set `rapprochement_id` sur les deux transactions, passe `statut_reglement → Pointe`, (d) crée l'entrée `extournes` (dont `rapprochement_lettrage_id` set ou NULL), (e) set `origine.extournee_at = now()`. Émet event `TransactionExtournee` (utile pour Slice 2 et observabilité). |

**ExtournePayload** (DTO `App\DataTransferObjects\ExtournePayload`) :
- `date` : `Carbon` (default `now()`)
- `libelle` : `string` (default `"Annulation - {origine.libelle}"`)
- `mode_paiement` : `ModePaiement` (default = celui de l'origine)
- `notes` : `?string` (motif libre, copié dans `Transaction.notes` de l'extourne)

Le service n'orchestre **pas** d'extourne automatique en cascade : il extourne strictement la transaction passée. Les compositions (annulation de facture du Slice 2) seront orchestrées par le service appelant.

### 3.4 Guards d'éligibilité

Méthode `Transaction::isExtournable(): bool` :

| Guard | Comportement |
|---|---|
| `$this->type === 'recette'` | Faux pour dépense (MVP) |
| `$this->extournee_at === null` | Faux : a déjà été extournée (lecture O(1) sur le flag) |
| `$this->estUneExtourne === false` | Faux : la transaction est elle-même une extourne. Calculé via `extournes.transaction_extourne_id`. Cache via Eloquent attribute `estUneExtourne` (eager loadable). |
| `$this->helloasso_order_id === null` | Faux : verrou HelloAsso |
| `$this->factures()->whereStatut(StatutFacture::Validee)->exists()` (via pivot) | Faux : portée par facture validée |
| `! $this->trashed()` | Faux : soft-deleted |

Le bouton UI consulte `isExtournable()` ; le service revérifie tout côté serveur et lance des `RuntimeException` à messages francisés.

**Policy** : `ExtournePolicy::create(User $user, Transaction $tx)` retourne `$user->hasRole(['Comptable', 'Admin'])` (ou syntaxe Spatie / système de rôles équivalent du projet — à vérifier au plan).

### 3.5 UI

| Écran | Modification |
|---|---|
| **Fiche transaction** (`TransactionShow` ou row détaillée de TransactionUniverselle) | Bouton "Annuler la transaction" affiché ssi `isExtournable()` ET `auth()->user()` peut. Modale Bootstrap (cf convention) avec champs : date (default today), libellé (default "Annulation - {origine}", éditable), mode de paiement (default origine, modifiable), notes (textarea optionnel, max 500 chars). Soumission appelle `ExtourneService::extourner()`. Toast de succès. |
| **Liste transactions** (universelle, par compte, par tiers) | Indicateurs visuels : badge "annulée" sur ligne d'origine extournée (lue via `extournee_at`), ligne d'extourne en italique avec préfixe "Annulation - " et montant rouge négatif. Eager loading `with(['extournePour'])` pour éviter N+1 sur le badge. |
| **Filtre Créances à recevoir** | Inchangé : transactions `Pointe` exclues naturellement → lettrages disparaissent. Vérifier explicitement par test que les transactions négatives `EnAttente` (cas encaissé non encore pointé banque) **n'apparaissent pas** dans Créances à recevoir (filtrage `montant > 0` à ajouter sur cette vue). |
| **Liste rapprochements bancaires** | Filtre `type` ajouté en haut (`Tous` / `Bancaire` / `Lettrage`, default `Bancaire`). Colonne "Type" ajoutée à la table. Une ligne lettrage affiche : date, compte, montant net (=0), 2 transactions appariées. |
| **Compte de résultat / Flux trésorerie / Dashboard** | Aucune modification d'écran (cf. Slice 0 audit qui a vérifié les sommations). |

### 3.6 Multi-tenant

- `Transaction`, `RapprochementBancaire`, **et nouvelle `Extourne`** étendent `TenantModel` : scope global fail-closed actif, aucune extourne possible hors tenant courant.
- Le service vérifie que `transaction_origine.association_id === TenantContext::currentId()`.
- Logging via `LogContext` : chaque extourne porte `association_id` + `user_id` + `transaction_origine_id` + `transaction_extourne_id` + `extourne_id`.

### 3.7 Migrations

| Fichier | Effet |
|---|---|
| `2026_04_30_120000_create_extournes_table.php` | CREATE TABLE `extournes` (cf §3.1), avec FK ON DELETE RESTRICT, indexes |
| `2026_04_30_120001_add_extournee_at_to_transactions.php` | ALTER TABLE `transactions` ADD `extournee_at` TIMESTAMP NULL + index |
| `2026_04_30_120002_add_type_to_rapprochements_bancaires.php` | ALTER TABLE `rapprochements_bancaires` ADD `type` varchar(20) NOT NULL DEFAULT 'bancaire' + backfill + index |

Toutes réversibles (`down()`). Aucune perte de données. FK `RESTRICT` empêche la suppression d'une transaction extournée ou d'extourne (cohérent : pour défaire une extourne il faudra passer par un dé-lettrage explicite, hors MVP).

### 3.8 Frontière avec l'existant

| Fonctionnalité | Impact |
|---|---|
| Saisie de transaction (manuelle) | Inchangé (validation `montant >= 0` durcie au Slice 0) |
| Pointage / rapprochement bancaire | Aucun changement de logique. Filtre `type` ajouté côté listes. |
| Créances à recevoir | Filtrage durci : inclure `montant > 0` pour exclure les extournes en attente du cas encaissé |
| Compte de résultat, flux de trésorerie | Aucun changement de logique (Slice 0 a vérifié). |
| Annulation de facture (Slice 2) | **Construit dessus.** L'`annuler()` du Slice 2 appellera `ExtourneService::extourner()` pour chaque ligne `MontantManuel`. |
| HelloAsso | Verrou existant, inchangé : non extournable. |
| Slice 0 (audit signe négatif) | Préalable obligatoire — Slice 1 ne livre pas si Slice 0 n'est pas livré. |

---

## 4. Acceptance Criteria

| # | Critère | Mesure |
|---|---|---|
| AC-1 | Suite Pest verte | `./vendor/bin/sail test` passe avec 100 % des tests existants + ajouts (~25-30 nouveaux tests) |
| AC-2 | Tous les scénarios BDD §2 sont implémentés en tests Feature | 1 test = 1 scénario, mappage 1:1 (les 14 scénarios in-MVP) |
| AC-3 | Compte de résultat correct sur exercice avec extourne | Test : 1 recette +80 €, 1 extourne -80 € → ∑ produits sous-catégorie = 0 € |
| AC-4 | Flux trésorerie correct sur exercice avec extourne pointée banque | Test : extourne pointée à un rapprochement bancaire ordinaire affecte solde de trésorerie |
| AC-5 | Multi-tenant : aucune fuite | Test d'intrusion : tenant A tente d'extourner tx du tenant B → null/exception scope |
| AC-6 | Migrations réversibles | `migrate` + `migrate:rollback` + `migrate` cycle test, aucune perte |
| AC-7 | Backfill `rapprochements_bancaires.type` correct | Tous les enregistrements existants à `bancaire` après migrate |
| AC-8 | UI : bouton "Annuler la transaction" affiché ssi `isExtournable()` ET rôle Comptable/Admin | Test Livewire vérifiant la présence/absence selon les guards §3.4 et la policy |
| AC-9 | Modale Bootstrap (pas de `confirm()` natif) | Conforme à la convention `wire:confirm` du projet |
| AC-10 | Cohérence atomique flag `extournee_at` ↔ table `extournes` | Test : crash artificiel mid-transaction → rollback propre, ni flag ni entrée |
| AC-11 | Lettrages exclus de Créances à recevoir | Test : extourne d'une `EnAttente` → ni l'origine ni l'extourne n'apparaît dans la vue créances |
| AC-12 | Extourne `EnAttente` (cas encaissé) exclue de Créances à recevoir (filtre `montant > 0`) | Test dédié |
| AC-13 | Filtre `type` sur écran rapprochements fonctionnel | Test Livewire : `Bancaire` (default), `Lettrage`, `Tous` |
| AC-14 | Logging `LogContext` porte les IDs | Test sur log capturé |
| AC-15 | PSR-12 / Pint vert | `./vendor/bin/pint --test` passe |
| AC-16 | `declare(strict_types=1)` + `final class` sur tous les nouveaux fichiers | Vérification grep |
| AC-17 | Pas de régression sur l'annulation actuelle de facture (Slice 1 ne touche pas `FactureService::annuler()`) | Suite existante `FactureAvoirTest` reste verte sans modification |
| AC-18 | Indexes utiles ajoutés | `transactions.extournee_at`, `extournes.transaction_origine_id` (UNIQUE), `extournes.transaction_extourne_id` (UNIQUE), `rapprochements_bancaires.type` |
| AC-19 | Eager loading sur listes pour éviter N+1 sur le badge "annulée" | Test mesurant le nombre de queries sur une liste de 25 transactions extournées |
| AC-20 | Policy `ExtournePolicy` : Comptable + Admin only | Test : Gestionnaire refusé, Comptable et Admin acceptés |

---

## 5. Consistency Gate

- [x] Intent unambiguous — deux développeurs interprètent l'extourne (table `extournes` + flag `extournee_at` + lettrage) de la même façon
- [x] Chaque comportement de l'Intent a au moins un scénario BDD correspondant en §2
- [x] L'architecture §3 contraint l'implémentation à ce que l'Intent demande, sans over-engineering (table dédiée justifiée par la cardinalité 1:0|1 et le besoin de tracer le lettrage ; flag dénormalisé justifié par perf listes paginées et simplicité du Slice 2 à venir)
- [x] Concepts nommés de façon cohérente : "extourne" en code/domain, "annulation" en UI, "lettrage" pour le rapprochement à somme zéro, `transactions.extournee_at` pour le flag, table `extournes` pour la source de vérité
- [x] Aucun artefact ne contredit un autre
- [x] Frontière avec Slice 0 (audit) et Slice 2 (annulation de facture) claire
- [x] Permissions explicites : Comptable + Admin (Gestionnaire et autres rôles refusés)
- [x] Multi-tenant fail-closed appliqué à `Extourne` (TenantModel)
- [x] Limitation MVP "exercice clos" documentée explicitement

**Verdict : ✅ PASS — prête pour `/plan` (Slice 1, après livraison du Slice 0).**

---

## 6. Décisions actées (rappel synthétique)

| Décision | Choix |
|---|---|
| Mécanisme | Brèche du signe (option M). Extourne = transaction sens recette, montant négatif. PCG-conforme. |
| Sens couvert | Recette uniquement au MVP. Dépense écartée. |
| Lien origine ↔ extourne | Table dédiée `extournes` (source de vérité) + flag `transactions.extournee_at` (cache lecture) — hybride, atomique en `DB::transaction`. |
| Lettrage | Réutilisation table `rapprochements_bancaires` étendue (`type` enum), pas de nouvelle table. |
| Statut transactions lettrées | Réutilisation de `Pointe` (existant), pas de nouveau statut. |
| Saisie manuelle de négatif | Interdite, bypass uniquement par le service d'extourne. |
| Cas non encaissé (origine `EnAttente`) | Lettrage automatique à la création de l'extourne (`rapprochement_lettrage_id` set). |
| Cas encaissé (origine `Recu` ou `Pointe`) | Pas de lettrage, l'extourne attend dans la liste à pointer banque (`rapprochement_lettrage_id` NULL). |
| Libellé extourne | `"Annulation - {libellé origine}"` (default, modifiable). |
| Mode de paiement extourne | Default = origine, **modifiable**. |
| Motif | Champ `notes` existant sur la transaction d'extourne, libre. |
| Permissions | Comptable + Admin uniquement. Gestionnaire refusé. |
| UI rapprochement / lettrage | Même écran, filtre `type` (default `Bancaire`). |
| Vocabulaire | "Extourne" en code, "Annulation" en UI. |
| Hors scope explicite | Extourne dépense, extourne partielle, extourne d'extourne, annulation de facture (Slice 2), ré-émission corrective, dé-lettrage manuel, gestion exercice clos. |
