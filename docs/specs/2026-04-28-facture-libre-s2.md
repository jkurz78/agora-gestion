# Facture manuelle — Slice 2 (transformation devis → facture, lignes manuelles, génération transaction à la validation)

**Date** : 2026-04-28
**Statut** : spec PASS (consistency gate ✅), prête pour `/plan`
**Programme** : Devis & Facturation libre — invoice-first
**Périmètre** : Slice 2 unique élargie. Couvre transformation Devis Accepté → Facture, **et** création directe de Facture manuelle. La S3 originale ("facture manuelle directe") est fusionnée ici car techniquement portée par le même mécanisme. Embarque le fix d'un bug d'affichage PDF préexistant sur les lignes Texte (devis S1).
**Préalables** : S1 livrée v4.1.8 (`docs/specs/2026-04-27-devis-libre-s1.md`).

---

## 1. Intent Description

**Quoi.** Élargir le module Facture pour permettre l'émission d'une facture qui peut **mixer trois types de lignes** (enum `TypeLigneFacture` étendu avec une 3e valeur) :

- `Montant` (existant, inchangé) : référence une `transaction_ligne` existante via `facture_lignes.transaction_ligne_id` (flux historique : règlements de séances)
- `MontantManuel` (nouveau) : libellé + prix unitaire + quantité + sous-catégorie (+ optionnellement opération / séance), qui **génère** une `transaction_ligne` (et la `Transaction` qui la porte) à la validation de la facture
- `Texte` (existant, inchangé) : ligne d'information sans impact comptable (ex. mention contractuelle, détail de prestation)

Deux chemins d'arrivée d'une facture manuelle :

- depuis un devis manuel (S1) à l'état "accepté" : un bouton **"Transformer en facture"** crée une facture brouillon dont les lignes du devis sont recopiées en `MontantManuel` / `Texte`, et `factures.devis_id` est renseigné
- directement, depuis la liste des factures : un bouton **"Nouvelle facture manuelle"** crée une facture brouillon vierge, sans devis source

À la validation, pour les lignes `MontantManuel` : **une seule `Transaction` (recette) est créée, avec N `TransactionLignes`** (une par ligne MontantManuel). Chaque `facture_ligne.transaction_ligne_id` est setté sur la `transaction_ligne` correspondante, fermant la liaison fine. Le pivot `facture_transaction` (pérenne, inchangé) référence aussi la nouvelle transaction en plus des transactions sélectionnées par les lignes `Montant`. Le mode de règlement de cette transaction est celui saisi sur la facture (`facture.mode_paiement_prevu`) ; son statut de règlement est *"à recevoir"*. Le bouton "Encaisser" existant (flow Créances v2.4.3) la traite comme n'importe quelle créance.

**Bug embarqué (devis S1)** : aujourd'hui, les lignes `Texte` du devis affichent `0,00 €` dans les colonnes PU / Qté / Montant à l'export PDF au lieu de cellules vides. Le fix est embarqué dans S2 et appliqué cohéremment aux PDF devis et facture (cf §3.5).

**Pourquoi.** Aujourd'hui, le seul chemin pour créer une transaction est *règlement de séance → bouton "Comptabiliser"*. Les prestations exceptionnelles (mission, vente hors catalogue, prestation à entreprise) ne peuvent pas être facturées proprement : il faudrait pré-créer une opération fictive et des transactions à la main. La S1 a livré le devis manuel (engagement commercial) ; la S2 livre la suite naturelle : transformation devis → facture, et création facture manuelle directe. La transaction émerge maintenant aussi de la facture (et plus seulement de la séance), ce qui réconcilie le modèle *transaction-first* historique et le modèle *invoice-first* nécessaire pour les opérations exceptionnelles. Les deux mondes coexistent dans le même objet `Facture`, par typage des lignes.

**Quoi ce n'est pas.** Pas de remplacement du flux séances → transactions (qui reste majoritaire). Pas de gestion TVA. Pas de réécriture de l'avoir : un avoir actuel n'annule pas la transaction issue d'une ligne manuelle (trou préexistant orthogonal à S2, voir §3.7). Pas d'acomptes (1 devis → 1 facture). Pas de portail client. Pas d'envoi automatique au tiers à la validation.

**Périmètre Slice 2.** Enum `TypeLigneFacture` étendu (3 valeurs) ; colonnes libres ajoutées sur `facture_lignes` (PU / Qté / sous_cat / opération / séance, toutes nullables) ; champ `mode_paiement_prevu` sur `factures` ; FK `factures.devis_id` nullable ; génération de `Transaction` + `TransactionLignes` à la validation avec set de `facture_lignes.transaction_ligne_id` ; bouton "Transformer en facture" sur Devis Accepté ; bouton "Nouvelle facture manuelle" sur la liste ; UI Livewire d'édition des trois types de lignes ; PDF asymétrique honnête (option α) ; fix bug PDF lignes Texte (devis + facture) ; multi-tenant `TenantModel`.

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: Facture manuelle (avec ou sans devis source)
  Pour pouvoir facturer une prestation exceptionnelle ou matérialiser un devis accepté
  En tant que gestionnaire ou comptable
  Je crée une facture brouillon (depuis devis ou depuis rien), j'ajoute des lignes manuelles,
  je la valide pour générer la transaction comptable à recevoir.

  Contexte:
    Étant donné que je suis authentifié comme gestionnaire de l'association "Asso A"
    Et que le tiers "ACME SARL" existe dans "Asso A"
    Et que la sous-catégorie recette "Prestations de service" existe dans "Asso A"

  # ─── Transformation devis → facture ────────────────────────────────────

  Scénario: Transformer un devis accepté en facture brouillon
    Étant donné un devis "accepté" "D-2026-007" pour "ACME SARL"
    Et qu'il porte deux lignes "montant" et une ligne "texte"
    Quand je clique sur "Transformer en facture"
    Alors une facture "brouillon" est créée pour "ACME SARL"
    Et ses lignes recopient les deux lignes "MontantManuel" et la ligne "Texte" du devis
    Et la facture porte une référence vers le devis "D-2026-007"
    Et le devis reste à l'état "accepté"

  Scénario: Bouton "Transformer en facture" disponible uniquement sur devis accepté
    Étant donné un devis "<statut>" pour "ACME SARL"
    Quand j'affiche la fiche du devis
    Alors le bouton "Transformer en facture" est <visibilite>

    Exemples:
      | statut    | visibilite |
      | brouillon | absent     |
      | validé    | absent     |
      | accepté   | présent    |
      | refusé    | absent     |
      | annulé    | absent     |

  Scénario: Un devis accepté ne peut être transformé qu'une seule fois
    Étant donné un devis "accepté" "D-2026-008" déjà transformé en facture brouillon
    Quand j'affiche la fiche du devis
    Alors le bouton "Transformer en facture" est désactivé
    Et un message indique "Une facture issue de ce devis existe déjà"

  # ─── Création facture manuelle directe ────────────────────────────────────

  Scénario: Créer une facture manuelle vierge sans devis source
    Quand je clique sur "Nouvelle facture manuelle" et choisis le tiers "ACME SARL"
    Alors une facture "brouillon" est créée pour "ACME SARL"
    Et elle ne référence aucun devis
    Et je peux y ajouter des lignes des trois types

  # ─── Lignes manuelles : édition ───────────────────────────────────────────

  Scénario: Ajouter une ligne manuelle montant sur une facture brouillon
    Étant donné une facture "brouillon" pour "ACME SARL"
    Quand j'ajoute une ligne "MontantManuel" "Mission audit" PU 800 € qty 3 sous-catégorie "Prestations de service"
    Alors le montant de la ligne est 2 400 €
    Et le total facture est 2 400 €

  Scénario: Ajouter une ligne manuelle texte sans impact comptable
    Étant donné une facture "brouillon" pour "ACME SARL"
    Quand j'ajoute une ligne "Texte" "Détail de la mission selon annexe jointe"
    Alors la ligne est enregistrée sans montant
    Et le total facture est inchangé

  Scénario: Mixer ligne Montant (ref) et ligne MontantManuel sur la même facture
    Étant donné une facture "brouillon" pour "ACME SARL"
    Et une transaction recette "T-12" de 500 € pour "ACME SARL"
    Quand j'ajoute une ligne "Montant" pointant vers "T-12"
    Et j'ajoute une ligne "MontantManuel" "Frais annexes" PU 100 € qty 1 sous-catégorie "Prestations de service"
    Alors le total facture est 600 €
    Et la facture est valide à l'édition

  # ─── Mode de règlement prévisionnel ────────────────────────────────────

  Scénario: Saisir le mode de règlement prévisionnel sur la facture
    Étant donné une facture "brouillon" pour "ACME SARL" avec une ligne "MontantManuel"
    Quand je sélectionne le mode "virement" dans le bloc "Conditions de règlement"
    Alors le mode de règlement prévisionnel de la facture est "virement"

  Scénario: Mode de règlement prévisionnel requis à la validation si ≥ 1 ligne MontantManuel
    Étant donné une facture "brouillon" pour "ACME SARL" avec une ligne "MontantManuel"
    Et que le mode de règlement prévisionnel n'est pas renseigné
    Quand je tente de valider la facture
    Alors la validation est refusée
    Et un message indique que le mode de règlement prévisionnel est requis

  Scénario: Mode de règlement non requis si aucune ligne MontantManuel
    Étant donné une facture "brouillon" pour "ACME SARL" avec uniquement des lignes "Montant"
    Et que le mode de règlement prévisionnel n'est pas renseigné
    Quand je valide la facture
    Alors la validation réussit

  # ─── Validation et génération de transaction ───────────────────────────

  Scénario: Validation génère une transaction à recevoir pour les lignes manuelles montant
    Étant donné une facture "brouillon" pour "ACME SARL" avec mode "virement"
    Et deux lignes "MontantManuel" : "Mission" 1 200 € sous-cat "Prestations" et "Frais" 200 € sous-cat "Prestations"
    Quand je valide la facture
    Alors une nouvelle transaction recette est créée pour "ACME SARL"
    Et son montant total est 1 400 €
    Et son statut de règlement est "à recevoir"
    Et son mode de règlement est "virement"
    Et elle porte deux lignes (1 200 € et 200 €) avec sous-catégorie "Prestations"
    Et la transaction est rattachée à la facture via le pivot facture_transaction

  Scénario: Validation avec mix de lignes — transactions existantes + transaction générée
    Étant donné une facture "brouillon" pour "ACME SARL" avec mode "virement"
    Et une ligne "Montant" pointant vers la transaction "T-12" (500 €, déjà encaissée)
    Et une ligne "MontantManuel" "Frais" 200 € sous-cat "Prestations"
    Quand je valide la facture
    Alors une nouvelle transaction "T-99" recette de 200 € statut "à recevoir" est créée
    Et la facture est rattachée à "T-12" et "T-99" via le pivot facture_transaction
    Et le total facture est 700 €

  Scénario: Sous-catégorie requise sur ligne MontantManuel à la validation
    Étant donné une facture "brouillon" pour "ACME SARL" avec mode "virement"
    Et une ligne "MontantManuel" sans sous-catégorie
    Quand je tente de valider la facture
    Alors la validation est refusée
    Et un message indique que la sous-catégorie est requise sur chaque ligne montant

  # ─── Verrouillage post-validation ──────────────────────────────────────

  Scénario: La transaction générée est verrouillée par la facture validée
    Étant donné une facture "validée" "F-2026-042" qui a généré la transaction "T-99"
    Quand je tente de modifier ou supprimer la transaction "T-99"
    Alors l'action est refusée
    Et un message indique qu'elle est verrouillée par la facture "F-2026-042"

  Scénario: Encaissement de la facture passe la transaction générée à "payé"
    Étant donné une facture "validée" "F-2026-042" avec transaction "T-99" à recevoir
    Quand j'encaisse la facture (flow Créances existant)
    Alors le statut de règlement de "T-99" passe à "payé"

  # ─── Brouillon : édition ──────────────────────────────────────────────

  Scénario: Édition libre d'une facture brouillon
    Étant donné une facture "brouillon" pour "ACME SARL"
    Quand j'ajoute, modifie ou supprime une ligne manuelle
    Alors la facture reste "brouillon"
    Et le total est recalculé

  Scénario: Une facture validée n'est plus éditable
    Étant donné une facture "validée" "F-2026-042"
    Quand je tente de modifier une ligne ou le mode_paiement_prevu
    Alors la modification est refusée

  # ─── Rendu PDF lignes Texte (fix bug devis embarqué) ──────────────────

  Scénario: Ligne Texte sur PDF n'affiche pas zéro dans les colonnes montant
    Étant donné un devis ou une facture "<doc>" "<numero>" pour "ACME SARL"
    Et qu'il porte une ligne "Texte" "Détail de la prestation"
    Quand je génère le PDF
    Alors la ligne "Texte" affiche son libellé sur la pleine largeur
    Et les colonnes prix unitaire, quantité et montant sont vides (pas "0,00 €")

    Exemples:
      | doc     | numero     |
      | devis   | D-2026-007 |
      | facture | F-2026-042 |

  Scénario: PDF facture mixte — colonnes PU/Qté affichées seulement sur les lignes MontantManuel
    Étant donné une facture "validée" "F-2026-050" avec une ligne "Montant (ref)" et une ligne "MontantManuel"
    Quand je génère le PDF
    Alors la ligne "Montant (ref)" affiche libellé + montant total uniquement (PU et Qté vides)
    Et la ligne "MontantManuel" affiche libellé + PU + Qté + montant total

  # ─── Multi-tenant ─────────────────────────────────────────────────────

  Scénario: Une facture manuelle n'est pas visible depuis une autre association
    Étant donné une facture "validée" "F-2026-050" dans "Asso A"
    Et que la transaction "T-200" a été générée par sa validation
    Quand je me connecte sur "Asso B"
    Alors la facture n'apparaît dans aucune liste, vue 360°, recherche
    Et la transaction "T-200" n'est pas visible non plus
```

---

## 3. Architecture Specification

### 3.1 Domain model — évolutions

**Schéma actuel (rappel)**

- `factures` : entête (numero, tiers_id, statut, date_emission, montant_total, mentions, …) — pas de PU/qté.
- `facture_lignes` : `id`, `facture_id`, `transaction_ligne_id` (nullable, FK `transaction_lignes`), `type` enum (`Montant | Texte`), `libelle`, `montant` (nullable), `ordre`. Pas de timestamps.
- `facture_transaction` : pivot `(facture_id, transaction_id)` — pérenne, sert au verrou et à la requête "factures liées à cette transaction".
- `transactions` : entête (type, date, libelle, montant_total, mode_paiement, statut_reglement, …).
- `transaction_lignes` : `transaction_id`, `sous_categorie_id` (nullable), `operation_id` (nullable), `seance` (nullable), `montant`, `notes`.

**Table `factures`** — colonnes ajoutées :

| Colonne | Type | Notes |
|---|---|---|
| `devis_id` | FK `devis` nullable | NULL pour facture manuelle directe ; renseigné si transformation depuis devis. `ON DELETE RESTRICT` |
| `mode_paiement_prevu` | enum `ModePaiement` nullable | réutilise l'énumération `ModePaiement` existante. NULL autorisé si la facture ne porte aucune ligne `MontantManuel` ; requis à la validation sinon |

Index ajouté : `(association_id, devis_id)` pour la lookup "facture issue d'un devis".

**Table `facture_lignes`** — colonnes ajoutées (toutes nullables, ne servent qu'aux lignes `MontantManuel`) :

| Colonne | Type | Notes |
|---|---|---|
| `prix_unitaire` | decimal(12,2) nullable | requis ssi `type = MontantManuel` |
| `quantite` | decimal(10,3) nullable | requis ssi `type = MontantManuel`, défaut 1 |
| `sous_categorie_id` | FK `sous_categories` nullable | requis (à la validation facture) ssi `type = MontantManuel` |
| `operation_id` | FK `operations` nullable | optionnel, ssi `type = MontantManuel` |
| `seance` | int nullable | optionnel, ssi `type = MontantManuel` |

**Comportement par type (option α "asymétrie honnête")** :

| Champ | `Montant` (ref) | `MontantManuel` (libre) | `Texte` |
|---|---|---|---|
| `transaction_ligne_id` | renseigné (FK transaction_ligne existante) | NULL avant validation, renseigné à la validation (FK transaction_ligne nouvelle) | NULL |
| `libelle` | requis | requis | requis |
| `montant` | copie de `transaction_ligne.montant` | dénormalisé `prix_unitaire × quantite` | NULL |
| `prix_unitaire`, `quantite` | NULL | requis | NULL |
| `sous_categorie_id`, `operation_id`, `seance` | NULL (l'info vit sur la `transaction_ligne` référencée) | renseignables | NULL |

**Enum `App\Enums\TypeLigneFacture`** — 3e valeur ajoutée :

```php
enum TypeLigneFacture: string {
    case Montant       = 'montant';        // existant — ligne référençant une transaction_ligne
    case MontantManuel  = 'montant_manuel';  // nouveau  — ligne manuelle, génère une transaction_ligne à la validation
    case Texte         = 'texte';          // existant — ligne d'information
}
```

Helpers ajoutés : `genereTransactionLigne(): bool`, `aImpactComptable(): bool`.

Migration up + down réversible. **Aucun backfill de données** sur `facture_lignes` : les lignes existantes ont déjà `type = Montant` ou `Texte`, le sens reste exact ; les nouvelles colonnes ajoutées sont nullables et restent NULL pour ces lignes.

### 3.2 Service layer

`App\Services\FactureService` — méthodes ajoutées ou étendues :

| Méthode | Rôle |
|---|---|
| `creerLibreVierge(int $tiersId): Facture` | facture brouillon directe, `devis_id = null` |
| `ajouterLigneLibreMontant(Facture, array $attrs): FactureLigne` | crée ligne `MontantManuel` ; recalcule `montant_total` |
| `ajouterLigneLibreTexte(Facture, string $libelle): FactureLigne` | crée ligne `Texte` |
| `valider(Facture)` | **étendue** : guards mode_paiement_prevu si ≥ 1 MontantManuel ; sous_categorie_id sur chaque MontantManuel ; **génère 1 `Transaction` recette + N `TransactionLignes`** (1 par MontantManuel) ; rattache via pivot `facture_transaction` ; verrouille édition |

`App\Services\DevisService` — méthode ajoutée :

| Méthode | Rôle |
|---|---|
| `transformerEnFacture(Devis): Facture` | guard `statut = accepté` + pas de facture déjà liée ; crée Facture brouillon + recopie lignes (`montant` → `MontantManuel`, `texte` → `Texte`) + `devis_id` + `tiers_id` |

Toutes les mutations en `DB::transaction()` avec `lockForUpdate` + `guardAssociation` (pattern S1).

### 3.3 Génération de la transaction à la validation

À la validation d'une facture portant ≥ 1 ligne `MontantManuel` :

1. **1 `Transaction` créée** :
   - `type = recette`
   - `tiers_id = facture.tiers_id`
   - `date = date du jour` (= date de validation facture)
   - `libelle = "Facture {numero}"` (ex. `Facture F-2026-042`)
   - `montant_total = somme des MontantManuel`
   - `mode_paiement = facture.mode_paiement_prevu`
   - `statut_reglement = "à recevoir"`
2. **N `TransactionLignes`** : 1 par ligne `MontantManuel` — copie `sous_categorie_id`, `operation_id`, `seance`, `montant` ; `notes = facture_ligne.libelle`.
3. **Mise à jour `facture_lignes`** : pour chaque `MontantManuel`, on set `facture_lignes.transaction_ligne_id` sur la `transaction_ligne` créée à l'étape 2, fermant la liaison fine 1:1.
4. **Pivot `facture_transaction`** : ajoute la nouvelle `Transaction` à la facture (en plus des transactions déjà rattachées via les lignes `Montant` ref).
5. La transaction porte `isLockedByFacture()` (déjà implémenté côté pivot — inchangé). Les lignes `Montant` ref ne touchent pas à leur `transaction_ligne_id` existant. Les lignes `Texte` n'interviennent pas dans ce flux.

Pas d'appel à `TransactionUniverselleService` (qui modélise la saisie manuelle utilisateur). La génération est interne à `FactureService::valider()`. Refactor éventuel hors S2 si réutilisation s'impose.

> **Note terminologique** : "à recevoir" dans cette spec correspond techniquement à `StatutReglement::EnAttente` (label "En attente") dans le code. Une évolution future pourrait introduire un case `ARecevoir` distinct si le besoin métier émerge (ex. label "À recevoir" exposé à l'utilisateur). Voir `project_statut_reglement_termino.md`.

### 3.4 UI Livewire — évolutions

| Composant | Évolution |
|---|---|
| `App\Livewire\DevisLibre\DevisEdit` | bouton **"Transformer en facture"** visible si `statut = accepté` ; désactivé avec tooltip si déjà transformé ; ouvre la facture brouillon créée |
| `App\Livewire\FactureList` | bouton **"Nouvelle facture manuelle"** à côté de "Nouvelle facture" existante, ouvre modale `TiersAutocomplete` |
| Éditeur de facture brouillon (composant existant à étendre) | éditeur de lignes accepte 3 types via dropdown ou 3 boutons "Ajouter ligne ref / montant / texte" ; bloc **"Conditions de règlement"** affiche `mode_paiement_prevu` (visible ssi ≥ 1 MontantManuel) ; total recalculé live |

UX cohérente avec patterns existants : modale Bootstrap, `wire:confirm`, `table-dark`, locale `fr`, ligne entière cliquable, breadcrumb harmonisé. Mention discrète "Issue du devis {numero_devis}" affichée sur la facture si `devis_id` renseigné.

### 3.5 PDF facture & devis (option α — asymétrie honnête)

**Règles de rendu par type de ligne** (appliquées de façon cohérente entre PDF facture et PDF devis) :

| Type | Libellé | Prix unitaire | Quantité | Montant total |
|---|---|---|---|---|
| `Montant` (ref) | rendu | **vide** | **vide** | rendu |
| `MontantManuel` | rendu | rendu (`prix_unitaire`) | rendu (`quantite`) | rendu (`montant`) |
| `Texte` | rendu (full width si bénéfique au visuel, sinon sur la colonne libellé seule) | **vide** | **vide** | **vide** |

Cellules "vides" = chaîne vide (pas `0,00 €`, pas `0`). C'est le **fix bug devis embarqué** : aujourd'hui les lignes `Texte` du PDF devis affichent à tort `0,00 €` sur PU / Qté / Montant.

Une facture pure-libre (cas devis transformé) aura les 4 colonnes pleines partout. Une facture pure-ref (cas historique) verra PU et Qté toujours vides — équivalent visuel à un PDF à 2 colonnes utiles. Une facture mixte aura un rendu hétérogène mais raconte fidèlement la composition de la facture.

**Évolutivité** : si `transactions` se voit ajouter un jour `prix_unitaire` / `quantite` (nullable), les lignes `Montant` ref pourront restituer ces valeurs sans changement du modèle facture.

**Autres règles** :

- Mention `mode_paiement_prevu` ajoutée dans le bloc Conditions de règlement (PDF facture)
- Footer unifié inchangé (`PdfFooterRenderer`)
- Pas de mention "Issue du devis ..." sur le PDF facture (la facture est autonome juridiquement)

### 3.6 Intégrations

| Point | Comportement |
|---|---|
| `Devis` modèle | nouvelle relation `hasOne(Facture, 'devis_id')` ; helper `aDejaUneFacture(): bool` |
| `Facture` modèle | nouvelle relation `belongsTo(Devis, 'devis_id')` |
| `TiersQuickViewService::getSummary()` | bloc Devis manuels existant inchangé ; bloc Factures inchangé (les factures libres apparaissent comme les autres) |
| Encaissement (Créances v2.4.3) | flow inchangé — la transaction "à recevoir" générée s'y intègre nativement |
| Logger | `Log::info('facture.valide', […])` étendu, `Log::info('devis.transforme_en_facture', […])` ajouté |
| Multi-tenant | `Devis`, `Facture`, `FactureLigne`, `Transaction`, `TransactionLigne` étendent déjà `TenantModel` ou sont parent-scoped |
| Stockage PDF | inchangé : `storage/app/associations/{id}/factures/…` |
| Cache, jobs, webhooks | aucun en S2 |

### 3.7 Frontière avec l'existant

| Module | Impact |
|---|---|
| Flux séances → règlements → transactions → factures (historique) | aucun changement de comportement |
| `DocumentPrevisionnel` v2.4.2 | aucun |
| `Devis` libre S1 | ajout d'un bouton + d'une relation `hasOne(Facture)` ; cycle de vie inchangé |
| `NumeroPieceService` (numérotation factures) | facture continue à l'utiliser ; pas de changement de format |
| Annulation par avoir | comportement existant inchangé. **Trou connu** : l'avoir actuel n'annule pas les transactions générées par les lignes manuelles. Préexistant à S2 (le modèle d'avoir part du principe que les transactions pré-existent à la facture, ce qui n'est plus vrai pour les lignes manuelles). Hors scope S2. À traiter dans un ticket dédié post-S2. |
| `TransactionUniverselleService` | non appelé en S2 ; refactor potentiel hors scope |

### 3.8 Contraintes techniques

- `declare(strict_types=1)`, `final class`, type hints partout
- PSR-12 / Pint
- Tests Pest étendant `Tests\Support\TenantTestCase`
- Modales Bootstrap pour `wire:confirm` (jamais natif)
- Casts `(int)` des deux côtés sur `===` PK/FK
- Locale `fr`

### 3.9 Risques

| Risque | Mitigation |
|---|---|
| Race sur transformation devis → facture (double-clic) | guard `aDejaUneFacture()` + `lockForUpdate` sur le devis dans `transformerEnFacture` |
| Race sur validation facture (double-validation = 2 transactions générées) | `lockForUpdate` sur la facture + guard `statut = brouillon` dans `valider` |
| Migration `facture_lignes` casse les lignes existantes | aucune modification destructive ; nouvelles colonnes nullables ; tests sur fixtures représentatives ; migration down réversible |
| Avoir n'annule pas la transaction générée | dette préexistante documentée ; ticket dédié à créer ; pas de blocage S2 |
| Mode_paiement_prevu choisi diffère du mode réel à l'encaissement | l'encaissement (flow Créances) peut overrider — pas de blocage |
| Sous-catégorie manquante sur ligne MontantManuel à la validation | guard explicite `valider`, message d'erreur ciblé |

---

## 4. Acceptance Criteria

### 4.1 Tests

| Critère | Seuil |
|---|---|
| Couverture Pest `FactureService` (méthodes ajoutées + `valider` étendue) | 100 % méthodes publiques, branches type ligne / guards testées |
| Couverture Pest `DevisService::transformerEnFacture` | 100 % branches |
| Tests feature ↔ scénarios BDD | mapping 1:1 |
| Suite Pest globale post-merge | 0 failed, 0 errored |
| Test isolation tenant facture manuelle + transaction générée | ≥ 2 tests "intrusion" (facture, transaction générée) |
| Test race transformation devis (parallèle) | 1 test, zéro double facture |
| Test race validation facture (parallèle) | 1 test, zéro double transaction générée |
| Test régression flux historique séances → transactions → facture | suite existante reste verte |

### 4.2 Sécurité & multi-tenant

- `Facture`, `Devis`, `Transaction` tenant-scopés via `TenantModel`
- Toute query brute passe par `TenantContext::currentId()`
- Endpoints gardés par `auth` + middleware tenant + `CheckEspaceAccess:comptabilite`
- `withoutGlobalScopes()` toujours escorté par `guardAssociation()`
- Logs `facture.valide` + `devis.transforme_en_facture` portent `association_id` + `user_id` (via `LogContext`)

### 4.3 Performance

| Critère | Seuil |
|---|---|
| Validation facture (≤ 20 lignes manuelles) | < 500 ms server side |
| Liste factures (1 000 lignes/tenant) | < 300 ms, pagination 50 |
| Transformation devis → facture (≤ 20 lignes) | < 300 ms |
| Édition facture brouillon (ajout/modif ligne) | update direct, pas N+1 |
| Vue 360° tiers | inchangé, ≤ 2 queries pour bloc Factures |

### 4.4 UX & accessibilité

- `wire:confirm` → modale Bootstrap (jamais natif)
- Bouton "Transformer en facture" désactivé (pas masqué) avec tooltip si déjà transformé
- Bouton "Nouvelle facture manuelle" à côté de "Nouvelle facture" existante, libellé clair
- Bloc "Conditions de règlement" sur facture : champ `mode_paiement_prevu` visible ssi ≥ 1 MontantManuel
- En-têtes tableau `table-dark` + style projet
- Tri JS client `data-sort` (date ISO, montants)
- Locale `fr` partout
- Messages d'erreur ciblés (sous-cat manquante, mode_paiement_prevu manquant, devis déjà transformé)

### 4.5 Données & migration

- Migration `factures` : ajout `devis_id` (FK nullable, ON DELETE RESTRICT), `mode_paiement_prevu` (string nullable cast en enum `ModePaiement`), index `(association_id, devis_id)` — up + down réversibles
- Migration `facture_lignes` : ajout `prix_unitaire` (decimal 12,2 nullable), `quantite` (decimal 10,3 nullable), `sous_categorie_id` (FK nullable), `operation_id` (FK nullable), `seance` (int nullable) — up + down réversibles
- Aucune modification destructive sur les colonnes existantes (`type`, `transaction_ligne_id`, `libelle`, `montant`, `ordre` inchangés)
- Aucun backfill de données : la 3e valeur d'enum `MontantManuel` n'apparaît qu'en S2 ; les lignes existantes restent en `Montant` ou `Texte`
- Seeders dev : ≥ 1 devis transformé en facture (avec transaction générée), ≥ 1 facture manuelle directe validée (transaction générée), ≥ 1 facture manuelle brouillon

### 4.6 Documentation

- `CHANGELOG.md` : entrée S2 + version
- Mémoire projet `project_devis_libre.md` mise à jour : S2 livrée, S3 fusionnée dans S2
- **ADR à rédiger** : `docs/adr/ADR-002-facture-libre-invoice-first.md` — trace le pivot architectural (modèle 3-types de lignes, génération transaction à la validation, asymétrie PDF α). Format court : Contexte / Options envisagées / Décision / Conséquences.
- Ticket "Avoir n'annule pas transactions issues de lignes manuelles" créé (dette préexistante hors S2)

### 4.7 Gates de livraison

| Étape | Critère |
|---|---|
| Pre-commit | `vendor/bin/pint` clean, suite Pest verte |
| PR review | code-review verte (security, perf, struct, naming) |
| Merge | tag + release GitHub `vX.Y.0` |
| Recette manuelle | parcours devis→facture + parcours facture manuelle directe + encaissement |

---

## 5. Consistency Gate

| Check | Statut |
|---|---|
| Intent unambigu | ✅ |
| Chaque comportement Intent → ≥ 1 scénario BDD | ✅ |
| Architecture contrainte au strict S2 (pas d'over-engineering) | ✅ |
| Concepts cohérents (`TypeLigneFacture` 3 valeurs, `mode_paiement_prevu`, `factures.devis_id`, `Transaction` recette à recevoir) | ✅ |
| Aucune contradiction inter-artefacts | ✅ |
| BDD ↔ AC mapping 1:1 | ✅ |
| Architecture ↔ AC perf cohérent | ✅ |
| Frontière existant explicite (séances → transactions inchangé, avoir préexistant, S1 inchangé) | ✅ |
| Multi-tenant traité | ✅ |
| 1 slice verticale livrable indépendamment | ✅ |
| Trou connu (avoir/transactions générées) explicitement scopé hors S2 | ✅ |
| Schéma DB cible aligné sur l'existant (pas d'ajout `transaction_id` sur `facture_lignes` redondant — `transaction_ligne_id` suffit) | ✅ |
| Bug PDF lignes Texte explicitement embarqué + scénario BDD | ✅ |

**Verdict : GATE PASSED — prête pour `/plan`.**
