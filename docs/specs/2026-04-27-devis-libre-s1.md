# Devis libre — Slice 1 (création, édition, cycle de vie, PDF, email)

**Date** : 2026-04-27
**Statut** : spec PASS (consistency gate ✅), prête pour `/plan`
**Programme** : Devis & Facturation libre — pivot vers un chemin "invoice-first"
**Périmètre** : Slice 1 uniquement. Slice 2 (devis → transaction) et Slice 3 (facture libre / facture-first) explicitement hors scope.

---

## 1. Intent Description

**Quoi.** Un gestionnaire ou comptable peut créer un devis « depuis une page blanche » pour n'importe quel `Tiers`, sans le rattacher à une `Operation` ni à des `Participants`. Le devis se compose d'un en-tête (référence, date d'émission, tiers, libellé, date de validité) et de lignes libres (libellé, prix unitaire, quantité, montant calculé, sous-catégorie optionnelle). Il peut être édité, exporté en PDF, envoyé par email au tiers, et son cycle de vie est tracé via 5 statuts : `brouillon → envoyé → accepté | refusé | expiré`, avec la possibilité d'`annuler` à tout moment. L'acceptation et le refus enregistrent qui a marqué le statut et quand. L'expiration est purement informative (badge si date de validité dépassée), sans transition automatique en S1. Le devis apparaît dans l'historique et la vue 360° du tiers.

**Pourquoi.** Le module devis actuel (`DocumentPrevisionnel` v2.4.2) est cloué à `(Operation, Participant, Seances, Reglements)` ; il ne couvre que les devis liés au cœur de métier formation/séances. Pour répondre à des prestations ou ventes exceptionnelles à des entreprises (ex. mission ponctuelle, vente de produits hors catalogue), il faut pouvoir émettre un devis sans pré-créer une Operation fictive, sans certitude que l'affaire aboutisse. Aujourd'hui le contournement coûte cher (Operation + Participants synthétiques) et pollue les données métier. Un devis libre, autonome, résout ce blocage et prépare le terrain pour un futur chemin de comptabilisation (Slice 2, hors scope ici).

**Quoi ce n'est pas.** Pas de remplacement de `DocumentPrevisionnel` (qui reste pour les devis adossés à une Operation). Pas de gestion TVA (asso non assujettie). Pas d'acceptation par le client via portail (interne uniquement, par bouton). Pas de génération de transaction comptable depuis le devis (S2). Pas de création de facture libre depuis le devis (S3 abandonné en l'état). Pas de Factur-X (le devis n'est pas une pièce comptable).

**Périmètre Slice 1.** Modèle `Devis` autonome + UI Livewire (liste, création, édition, lignes, statuts, PDF, mail), apparition dans la vue 360° du tiers. Multi-tenant `TenantModel`.

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: Devis libre
  Pour pouvoir émettre un devis à un tiers sans pré-créer une opération
  En tant que gestionnaire ou comptable
  Je crée, édite, finalise et trace le cycle de vie d'un devis autonome

  Contexte:
    Étant donné que je suis authentifié comme gestionnaire de l'association "Asso A"
    Et que le tiers "ACME SARL" existe dans "Asso A"
    Et que la durée de validité par défaut des devis de "Asso A" est de 30 jours

  # ─── Création et composition ──────────────────────────────────────────

  Scénario: Créer un devis libre brouillon
    Quand je crée un nouveau devis pour le tiers "ACME SARL" daté du "2026-05-01"
    Alors un devis est créé au statut "brouillon"
    Et il est rattaché au tiers "ACME SARL"
    Et sa date de validité est "2026-05-31"
    Et son montant total est de 0,00 €
    Et il n'a pas encore de numéro de référence

  Scénario: Ajouter une ligne avec montant calculé
    Étant donné un devis brouillon pour "ACME SARL"
    Quand j'ajoute une ligne "Mission audit" avec prix unitaire 800,00 € et quantité 3
    Alors le montant de la ligne est 2 400,00 €
    Et le montant total du devis est 2 400,00 €

  Scénario: Sous-catégorie optionnelle sur une ligne
    Étant donné un devis brouillon pour "ACME SARL"
    Quand j'ajoute une ligne sans sous-catégorie
    Et j'ajoute une ligne avec la sous-catégorie "Prestations de service"
    Alors les deux lignes sont enregistrées
    Et le devis reste valide

  # ─── Cycle de vie ─────────────────────────────────────────────────────

  Scénario: Émettre le devis lui attribue un numéro
    Étant donné un devis brouillon non vide pour "ACME SARL"
    Quand je le marque "envoyé"
    Alors son statut est "envoyé"
    Et il reçoit un numéro de la forme "D-2026-NNN" séquentiel pour l'exercice courant
    Et ce numéro reste figé pour la suite de sa vie

  Scénario: Marquer un devis envoyé comme accepté
    Étant donné un devis "envoyé" "D-2026-007"
    Quand je le marque "accepté"
    Alors son statut est "accepté"
    Et l'utilisateur "moi" est tracé comme ayant marqué l'acceptation
    Et la date d'acceptation est "aujourd'hui"

  Scénario: Marquer un devis envoyé comme refusé
    Étant donné un devis "envoyé" "D-2026-008"
    Quand je le marque "refusé"
    Alors son statut est "refusé"
    Et l'utilisateur "moi" et la date sont tracés

  Scénario: Annuler un devis depuis n'importe quel statut
    Étant donné un devis "<statut_initial>" pour "ACME SARL"
    Quand je l'annule
    Alors son statut est "annulé"
    Et il devient verrouillé à l'édition

    Exemples:
      | statut_initial |
      | brouillon      |
      | envoyé         |
      | accepté        |
      | refusé         |

  # ─── Édition ──────────────────────────────────────────────────────────

  Scénario: Édition libre d'un devis brouillon
    Étant donné un devis "brouillon" pour "ACME SARL"
    Quand je modifie une ligne ou en ajoute une
    Alors le devis reste au statut "brouillon"
    Et le montant total est recalculé

  Scénario: Modifier un devis envoyé le repasse en brouillon
    Étant donné un devis "envoyé" "D-2026-009"
    Quand je modifie l'une de ses lignes
    Alors son statut redevient "brouillon"
    Et il conserve son numéro "D-2026-009"

  Scénario: Édition impossible sur devis verrouillé
    Étant donné un devis "<statut>" pour "ACME SARL"
    Quand je tente de modifier une ligne
    Alors la modification est refusée

    Exemples:
      | statut  |
      | accepté |
      | refusé  |
      | annulé  |

  # ─── Garde-fous d'émission ────────────────────────────────────────────

  Scénario: Impossible d'émettre un devis vide
    Étant donné un devis "brouillon" sans aucune ligne avec un montant > 0
    Quand je tente de le marquer "envoyé"
    Alors la transition est refusée
    Et un message indique qu'au moins une ligne avec montant est requise

  Scénario: Impossible d'exporter ou d'envoyer un devis vide
    Étant donné un devis "brouillon" sans aucune ligne avec un montant > 0
    Quand je tente l'export PDF ou l'envoi par email
    Alors l'action est refusée

  # ─── Validité (informative) ───────────────────────────────────────────

  Scénario: Badge expiré quand la date de validité est dépassée
    Étant donné un devis "envoyé" "D-2026-010" avec date de validité "2026-04-01"
    Et que la date du jour est "2026-04-15"
    Quand j'affiche la liste ou la fiche du devis
    Alors un badge "expiré" est visible
    Mais son statut reste "envoyé"

  # ─── Sortie : PDF et email ────────────────────────────────────────────

  Scénario: Exporter un devis en PDF
    Étant donné un devis "envoyé" "D-2026-011"
    Quand je demande l'export PDF
    Alors un fichier PDF est généré contenant numéro, date d'émission, date de validité,
         tiers, lignes, montant total, mentions de l'association

  Scénario: Export PDF d'un brouillon non vide affiche un filigrane
    Étant donné un devis "brouillon" pour "ACME SARL" avec au moins une ligne montant
    Quand je demande l'export PDF
    Alors un PDF est généré
    Et il affiche clairement la mention "BROUILLON"
    Et il n'affiche aucun numéro de référence

  Scénario: Envoyer un devis par email au tiers
    Étant donné un devis "envoyé" "D-2026-012"
    Et que "ACME SARL" a une adresse email
    Quand je l'envoie par email avec un message
    Alors un email est envoyé à l'adresse du tiers avec le PDF en pièce jointe
    Et l'événement est tracé dans les logs email du tiers

  # ─── Duplication ──────────────────────────────────────────────────────

  Scénario: Dupliquer un devis pour repartir d'une base
    Étant donné un devis "<statut>" "D-2026-020" pour "ACME SARL"
    Quand je le duplique
    Alors un nouveau devis "brouillon" est créé pour le même tiers
    Et les lignes sont recopiées
    Et le nouveau devis n'a pas encore de numéro
    Et la date d'émission est "aujourd'hui"
    Et la date de validité est recalculée à partir de la durée par défaut

    Exemples:
      | statut  |
      | accepté |
      | refusé  |
      | annulé  |
      | expiré  |
      | envoyé  |

  # ─── Vue 360° tiers ───────────────────────────────────────────────────

  Scénario: Le devis apparaît dans la vue 360° du tiers
    Étant donné un devis "envoyé" "D-2026-013" pour "ACME SARL"
    Quand j'ouvre la vue 360° de "ACME SARL"
    Alors le devis apparaît dans son historique avec son numéro, sa date, son montant et son statut

  # ─── Multi-tenant ─────────────────────────────────────────────────────

  Scénario: Un devis n'est pas visible depuis une autre association
    Étant donné un devis "envoyé" "D-2026-014" dans "Asso A"
    Quand je me connecte sur "Asso B"
    Alors le devis n'apparaît dans aucune liste, aucune recherche, aucune vue 360°
```

---

## 3. Architecture Specification

### 3.1 Domain model

**Table `devis`** (TenantModel)

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `association_id` | FK | scope `TenantModel` (fail-closed) |
| `numero` | string nullable | `D-{exercice}-NNN`, NULL tant que `brouillon`, attribué une fois à la 1re transition `→ envoyé`, immuable ensuite |
| `tiers_id` | FK `tiers` | requis |
| `date_emission` | date | requis ; détermine l'`exercice` du numéro |
| `date_validite` | date | requis ; défaut = `date_emission + association.devis_validite_jours` |
| `libelle` | string nullable | en-tête optionnel |
| `statut` | enum `StatutDevis` | `brouillon \| envoyé \| accepté \| refusé \| annulé` |
| `montant_total` | decimal(12,2) | dénormalisé |
| `exercice` | int | figé à `date_emission` |
| `accepte_par_user_id` | FK users nullable | trace `accepté` |
| `accepte_le` | datetime nullable | |
| `refuse_par_user_id` | FK users nullable | |
| `refuse_le` | datetime nullable | |
| `annule_par_user_id` | FK users nullable | |
| `annule_le` | datetime nullable | |
| `saisi_par_user_id` | FK users | créateur |
| `created_at`, `updated_at`, `deleted_at` | | softDeletes (cohérence financiers) |

Index : `(association_id, statut)`, `(association_id, tiers_id)`, unique partielle `(association_id, exercice, numero)` non-null.

**Table `devis_lignes`**

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `devis_id` | FK | cascade |
| `ordre` | int | tri |
| `libelle` | string | requis |
| `prix_unitaire` | decimal(12,2) | requis |
| `quantite` | decimal(10,3) | défaut 1 |
| `montant` | decimal(12,2) | dénormalisé = `prix_unitaire × quantite` |
| `sous_categorie_id` | FK `sous_categories` nullable | optionnel S1 |

**Enum `StatutDevis`** : `Brouillon, Envoye, Accepte, Refuse, Annule` + helpers `peutEtreModifie()`, `peutEtreDuplique()`, `peutPasserEnvoye()`.

**Champ Association** : ajout `devis_validite_jours INT DEFAULT 30`. Mentions et conditions PDF : réutilisent celles de la facture, pas de nouveau champ S1.

### 3.2 Service layer

`App\Services\DevisService` — toutes mutations en `DB::transaction()` :

| Méthode | Rôle |
|---|---|
| `creer(int $tiersId, ?Carbon $date = null): Devis` | brouillon + `date_validite` calculée |
| `ajouterLigne / modifierLigne / supprimerLigne` | + recalcul `montant_total` ; si statut `envoyé` → repasse en `brouillon` (numéro conservé) |
| `marquerEnvoye(Devis): void` | guard ≥1 ligne montant > 0 ; attribution numéro |
| `marquerAccepte / marquerRefuse(Devis): void` | guard `statut == envoyé` ; trace user + date |
| `annuler(Devis): void` | guard `statut != annulé` ; trace |
| `dupliquer(Devis): Devis` | nouveau brouillon, lignes recopiées, dates recalculées |
| `genererPdf(Devis, bool $brouillonWatermark): string` | retourne path stockage |
| `envoyerEmail(Devis, string $sujet, string $corps): void` | guard `statut != brouillon` ; PJ PDF ; log dans `email_logs` |

### 3.3 Numéro de référence

Décision : **séquence dédiée**, pas `NumeroPieceService` (le devis n'est pas une pièce comptable). Calcul `MAX(numero)+1` filtré par `(association_id, exercice)` sous lock pessimiste. Format `D-{exercice}-{NNN}` (3 digits min, extensible).

### 3.4 UI Livewire

| Composant | Route | Rôle |
|---|---|---|
| `App\Livewire\DevisLibre\DevisList` | `/devis-libres` | liste filtrable (statut, tiers, exercice), tri JS client, badge "expiré" |
| `App\Livewire\DevisLibre\DevisEdit` | `/devis-libres/{devis}` | édition en-tête + lignes inline ; transitions de statut ; boutons PDF / email / dupliquer ; mode lecture si verrouillé |

UX cohérente avec `FactureEdit` (lignes inline, footer total, modale Bootstrap pour `wire:confirm`).

### 3.5 PDF & Email

- **PDF** : nouveau gabarit `resources/views/pdf/devis-libre.blade.php`, dérivé de `DocumentPrevisionnel` mais sans bloc séances/participants. Filigrane "BROUILLON" piloté par paramètre. Footer unifié via `App\Support\PdfFooterRenderer`.
- **Email** : envoi via `SmtpService` ; ligne dans `email_logs` (`tiers_id`, `subject`, `attachment_path`). Pas de modèle email seedé en S1.

### 3.6 Intégrations

| Point | Comportement |
|---|---|
| `TiersQuickViewService::getSummary()` | nouveau bloc "Devis libres" : count par statut, total des `accepté` |
| Historique tiers | section "Devis libres" listant `numero, date, montant, statut` |
| Sidebar | entrée "Devis libres" sous le groupe `Facturation` |
| Logger | `Log::info('devis.envoye', …)` etc. — `LogContext` ajoute auto `association_id` + `user_id` |
| Multi-tenant | `Devis extends TenantModel` ; `DevisLigne` parent-scoped |
| Stockage PDF | `storage/app/associations/{id}/devis-libres/{devis_id}/devis-{numero}.pdf` |
| Cache, jobs | aucun en S1 |

### 3.7 Frontière avec l'existant

| Module | Impact |
|---|---|
| `DocumentPrevisionnel` v2.4.2 | aucun |
| `Facture` v2.4.0 | aucun |
| `TransactionUniverselleService` | aucun en S1 (point d'entrée S2) |
| `NumeroPieceService` | non utilisé |

### 3.8 Contraintes techniques

- `declare(strict_types=1)`, `final class`, type hints partout
- PSR-12 / Pint
- Tests Pest étendant `Tests\Support\TenantTestCase`
- Aucun `confirm()` natif — modales Bootstrap
- Casts `(int)` des deux côtés sur `===` PK/FK

### 3.9 Risques

| Risque | Mitigation |
|---|---|
| Course concurrente sur attribution numéro | lock pessimiste sur `(association_id, exercice)` |
| Scope creep vers S2 | slice strictement délimitée |
| Filigrane brouillon contourné | rendu PDF lit toujours statut courant |
| Liste polluée par devis annulés | filtre par défaut "non annulés" |

---

## 4. Acceptance Criteria

### 4.1 Tests

| Critère | Seuil |
|---|---|
| Couverture Pest `DevisService` | 100 % méthodes publiques, branches statut/guard testées |
| Tests feature ↔ scénarios BDD | mapping 1:1 |
| Suite Pest globale post-merge | 0 failed, 0 errored |
| Test isolation tenant `Devis` | ≥ 1 test "intrusion" |
| Test course attribution numéro | 1 test parallèle, zéro doublon `(association_id, exercice, numero)` |

### 4.2 Sécurité & multi-tenant

- `Devis extends TenantModel`, scope global fail-closed
- Toute query brute via `TenantContext::currentId()`
- Stockage PDF sous `storage/app/associations/{id}/devis-libres/…`
- Endpoints gardés par `auth` + tenant boot
- Logs `devis.*` portent `association_id` + `user_id`

### 4.3 Performance

| Critère | Seuil |
|---|---|
| Liste devis (1 000 lignes/tenant) | < 300 ms server side, pagination 50 |
| Vue 360° tiers (bloc Devis libres) | ≤ 2 queries, pas de N+1 |
| Génération PDF unitaire | < 2 s en local |
| Recalcul `montant_total` | update direct, pas de recharge complète |

### 4.4 UX & accessibilité

- `wire:confirm` → modale Bootstrap (jamais natif)
- Boutons désactivés (pas masqués) selon statut + tooltip explicatif
- En-têtes tableau `table-dark` + style projet
- Tri JS client `data-sort` (date ISO, montants)
- Locale `fr` partout

### 4.5 Données & migration

- Migrations `devis`, `devis_lignes`, ajout `associations.devis_validite_jours` (défaut 30) — up + down réversibles
- Aucune migration ou backfill sur tables existantes
- Seeders dev : ≥ 3 devis libres d'exemples (statuts variés) sur asso de démo

### 4.6 Documentation

- `CHANGELOG.md` : entrée Devis libres S1 + version
- Mémoire projet `project_devis_libre.md` créée — décisions clés + scope S1/S2/S3

### 4.7 Gates de livraison

| Étape | Critère |
|---|---|
| Pre-commit | `vendor/bin/pint` clean, suite Pest verte |
| PR review | code-review verte (security, perf, struct, naming) |
| Merge | tag + release GitHub `vX.Y.0` |

---

## 5. Consistency Gate

| Check | Statut |
|---|---|
| Intent unambigu | ✅ |
| Chaque comportement Intent → ≥ 1 scénario BDD | ✅ |
| Architecture contrainte au strict S1 | ✅ |
| Concepts cohérents (`Devis`, `StatutDevis`, `D-{exercice}-NNN`) | ✅ |
| Aucune contradiction inter-artefacts | ✅ |
| BDD ↔ AC mapping 1:1 | ✅ |
| Architecture ↔ AC perf cohérent | ✅ |
| Frontière existant explicite | ✅ |
| Multi-tenant traité | ✅ |
| 1 slice verticale livrable indépendamment | ✅ |

**Verdict : GATE PASSED — prête pour `/plan`.**
