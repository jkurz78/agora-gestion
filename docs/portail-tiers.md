# Portail Tiers — v1 (Slice 1 : Auth OTP)

Statut : livré 2026-04-19, branche feat/portail-tiers-slice1-auth-otp

## Objectif

Portail self-care par association (`/portail/{slug}`) permettant à un Tiers
existant de s'authentifier par OTP email sans compte utilisateur applicatif.
Fondation extensible pour futures features (notes de frais, attestations,
reçus fiscaux…).

## Flux d'authentification

```
GET /portail/{slug}/login
  └─ formulaire email
       │
       ▼ POST /portail/{slug}/login
       ├─ email inconnu ou archivé → même réponse qu'email connu (anti-énumération)
       └─ email connu → OTP généré + envoyé → redirect /portail/{slug}/otp

GET /portail/{slug}/otp
  └─ formulaire code OTP
       │
       ▼ POST /portail/{slug}/otp/verify
       ├─ code invalide / expiré / cooldown → message générique, retour /otp
       ├─ code valide + 1 Tiers → login direct → redirect /portail/{slug}/
       └─ code valide + N Tiers → stockage pending + redirect /portail/{slug}/choisir

GET /portail/{slug}/choisir
  └─ liste des Tiers pour cet email
       │
       ▼ POST /portail/{slug}/choisir
       └─ Tiers choisi → login → redirect /portail/{slug}/

GET /portail/{slug}/
  └─ accueil (home placeholder)
       └─ [futures features : NDF, attestations, reçus fiscaux]

POST /portail/{slug}/logout
  └─ session détruite → redirect /portail/{slug}/login
```

## Règles OTP

| Règle | Valeur | Config |
|---|---|---|
| Longueur du code | 8 chiffres | `portail.otp_length` |
| TTL | 10 minutes | `portail.otp_ttl_minutes` |
| Max tentatives | 3 | `portail.otp_max_attempts` |
| Cooldown | 15 min | `portail.otp_cooldown_minutes` |
| Renvoi minimum | 60 sec | `portail.otp_resend_seconds` |
| Session lifetime | 60 min glissante | `portail.session_lifetime_minutes` |

## Garde

`tiers-portail` — provider Eloquent sur le modèle `Tiers`, isolée de `web`.

Un utilisateur admin connecté sur `web` n'est pas considéré comme authentifié
sur `tiers-portail`, et vice-versa.

## Architecture

### Services

- `app/Services/Portail/OtpService.php` — génération, envoi, vérification, cooldown.
  - `request(Association, email)` → `RequestResult` (Sent | Silent | TooSoon | Cooldown)
  - `verify(Association, email, code)` → `VerifyResult` (Success[$tiersIds] | Invalid | Cooldown)
  - Temps constant anti-énumération : `Hash::make` exécuté même pour les emails inconnus.
- `app/Services/Portail/AuthSessionService.php` — login single-Tiers, multi-Tiers pending, chooser.
  - `loginSingleTiers(Tiers)` — connexion directe sur la garde.
  - `markPendingTiers(ids[])` — stocke les IDs en session pour le sélecteur.
  - `chooseTiers(tiersId)` — valide l'ID depuis pending et connecte.

### Middleware

- `app/Http/Middleware/Portail/BootTenantFromSlug.php` — résout l'`Association` depuis `{slug}`, retourne 404 si absent.
- `app/Http/Middleware/Portail/Authenticate.php` — vérifie que la garde `tiers-portail` est authentifiée.
- `app/Http/Middleware/Portail/EnsureTiersChosen.php` — redirige vers `/choisir` si des Tiers sont en attente de sélection.
- `app/Http/Middleware/Portail/EnforceSessionLifetime.php` — expire la session après 60 min d'inactivité.

### Composants Livewire

- `app/Livewire/Portail/Login.php` — formulaire email + gestion TooSoon.
- `app/Livewire/Portail/OtpVerify.php` — formulaire code + dispatch vers AuthSessionService.
- `app/Livewire/Portail/ChooseTiers.php` — sélecteur multi-Tiers.
- `app/Livewire/Portail/Home.php` — accueil post-login.

### Autres

- `app/Http/Controllers/Portail/LogoutController.php` — POST logout.
- `app/Mail/Portail/OtpMail.php` — email OTP (code en clair dans l'email, jamais persisté).
- `app/Models/TiersPortailOtp.php` — modèle OTP (étend `TenantModel`).

## Sécurité

- OTP hashé (`Hash::make`), jamais en clair en base ni dans les logs.
- Anti-énumération : réponse HTTP identique email connu/inconnu (statut, body, redirect, flash).
- Temps de réponse constant : `Hash::make` systématique même pour les emails inconnus (décision Q3).
- Rate limiter scopé par `(association_id, email)` — pas de contamination cross-tenant.
- `TenantScope` fail-closed sur `TiersPortailOtp` et `Tiers` — isolation cross-tenant stricte (cf. `TenantIsolationTest`).
- OTP single-use : consommation atomique via `DB::transaction` avec `UPDATE ... WHERE consumed_at IS NULL`.

## Observabilité

Événements loggés via `Log::info(...)` (LogContext porte `association_id` automatiquement) :

| Événement | Déclencheur | Contexte |
|---|---|---|
| `portail.otp.requested` | `OtpService::request()` après envoi réussi | `email` |
| `portail.otp.verified` | `OtpService::verify()` après succès | `email`, `tiers_count` |
| `portail.otp.failed` | `OtpService::verify()` après code invalide | `email`, `attempts` |
| `portail.cooldown.triggered` | `OtpService` quand le cooldown devient actif | `email` |
| `portail.login.success` | `AuthSessionService::loginSingleTiers()` | `tiers_id`, `email` |
| `portail.tiers.chosen` | `AuthSessionService::chooseTiers()` | `tiers_id` |

Garantie : aucun log ne contient le code OTP (8 chiffres consécutifs) ni le champ `code_hash`
(vérifié par assertion dans `ObservabilityTest`).

## Notes de frais (Slice 2)

Statut : livré 2026-04-19.

### Flux Tiers
- Liste `/portail/{slug}/notes-de-frais` — NDF du Tiers connecté avec badge statut.
- Création `/portail/{slug}/notes-de-frais/nouvelle` — form avec N lignes dynamiques.
- Édition `/portail/{slug}/notes-de-frais/{id}/edit` — uniquement brouillon.
- Consultation `/portail/{slug}/notes-de-frais/{id}` — lecture seule.

### Statuts

| Statut | Par qui | Quand |
|---|---|---|
| brouillon | Tiers | À la création/sauvegarde |
| soumise | Tiers | Clic "Soumettre" (validations passent) |
| rejetee | Comptable (Slice 3) | Avec motif |
| validee | Comptable (Slice 3) | Avec création de Transaction |
| payée | Dérivé | Quand `transaction.statut_reglement === Pointe` |

### Règles de soumission
- date ≤ aujourd'hui
- libellé obligatoire
- ≥ 1 ligne
- chaque ligne : sous-catégorie + montant > 0 + pièce jointe obligatoire

### Justificatifs
- Formats : PDF, JPG, PNG, HEIC. Max 5 Mo.
- Stockage : `storage/app/associations/{id}/notes-de-frais/{note_id}/ligne-{ligne_id}.{ext}`.
- Suppression : cascade via event Eloquent `deleting` sur `NoteDeFraisLigne`.

### Observabilité

4 événements loggés avec `LogContext` portant `association_id` :
- `portail.ndf.created`
- `portail.ndf.updated`
- `portail.ndf.submitted`
- `portail.ndf.deleted`

### Dettes résolues (post-Slice 2)

- **Q6-A résolue (2026-04-20)** : les NDF Rejetées sont désormais éditables (avec re-soumission) et supprimables depuis le portail. La policy `update` et `delete` incluent maintenant le statut `Rejetee`. Le service `saveDraft` efface `motif_rejet` et `submitted_at` au retour en Brouillon. Les boutons Modifier/Supprimer sont affichés sur la page Show et dans la liste Index pour les NDF Rejetées.

### Dettes portées en Slice 3

- Filtre sous-catégorie `pour_depenses=true` : simplifié pour Slice 2 (toutes les sous-catégories affichées). Le champ `pour_depenses` est sur `Tiers`, pas sur `SousCategorie` — à clarifier avec produit.
- Validation opérations actives + exercice courant : à vérifier implementation.
- Archivage des NDF rejetées/payées : non prévu (Q6-A — dette résolue pour édit+suppression, archivage reste non prévu).

## Slice 3 — Back-office NDF comptable (2026-04-20)

Statut : livré 2026-04-20, branche feat/portail-tiers-slice1-auth-otp.

### Intent

Permettre à un utilisateur Admin ou Comptable de traiter les Notes de frais soumises
par les Tiers depuis le portail : validation (création d'une Transaction Dépense),
rejet avec motif obligatoire, et dé-comptabilisation implicite si la Transaction liée
est supprimée. Un badge dans la top-bar signale le nombre de NDF en attente.

C'est la v0 de ce périmètre — les dettes portées sont listées dans §6 de
`docs/specs/2026-04-20-portail-tiers-slice3-back-office-ndf.md`.

### Routes

```
GET  /comptabilite/notes-de-frais
     └─ liste (Livewire Index, 4 onglets : À traiter / Validées / Rejetées / Toutes)
        onglet par défaut : À traiter (statut=Soumise)
        tri : date décroissante

GET  /comptabilite/notes-de-frais/{noteDeFrais}
     └─ détail (Livewire Show)
        statut Soumise   → bouton "Valider & comptabiliser" (mini-form inline) + bouton "Rejeter" (modal Bootstrap)
        statut Validée   → panneau "Transaction #XXX" + lien /comptabilite/transactions?edit={id}
        statut Rejetée   → affichage motif
        statut Payée     → lecture seule (dérivé : Transaction en statut Pointe)

GET  /comptabilite/notes-de-frais/{noteDeFrais}/lignes/{ligne}/piece-jointe
     └─ contrôleur NoteDeFraisPieceJointeController (Storage::response)
        Gate treat + vérification appartenance ligne/NDF
```

### Rôles

- Admin et Comptable uniquement (Policy `treat` défensive tenant-aware → retourne `false` si TenantContext non booté).
- Gestionnaire / Consultation → 403.
- Super-admin en mode support → bloqué par `BlockWritesInSupport` (valider / rejeter impossibles).
- Règle "Comptable ne peut pas traiter sa propre NDF" abandonnée en v0 : aucun rattachement user↔tiers dans le modèle aujourd'hui. Cette règle est une dette tracée.

### Flux validation

```
Comptable ouvre NDF (statut Soumise)
  │
  ▼  Clique "Valider & comptabiliser"
  mini-form inline : compte_id (select) + mode_paiement (default Virement) + date (default ndf.date, bouton Aujourd'hui)
  │
  ▼  Submit
  NoteDeFraisValidationService::valider(ndf, ValidationData)
    ├─ DB::transaction {
    │    lockForUpdate sur NDF (anti-double-validation)
    │    refresh + vérif statut=Soumise (DomainException sinon)
    │    TransactionService::create(type=Depense, statut_reglement=EnAttente, tiers=ndf.tiers, …)
    │    pour chaque ligne NDF : Storage::disk('local')->copy(source, associations/{id}/transactions/{tr_id}/ligne-{N}-{slug}.{ext})
    │    ndf.update(statut=Validee, transaction_id, validee_at)
    │    log comptabilite.ndf.validated
    │  }
    └─ retourne Transaction
```

- Si la date tombe dans un exercice clôturé → `ExerciceService::assertOuvert()` lève une exception, flash erreur, NDF reste Soumise.
- Si `Storage::copy` échoue → rollback DB, NDF reste Soumise. Les fichiers déjà copiés avant l'échec deviennent des orphelins (dette mineure acceptée).

### Flux rejet

```
Comptable clique "Rejeter"
  │
  ▼  Modal Bootstrap : textarea motif (requis, min 1 caractère)
  │
  ▼  Confirmer le rejet
  NoteDeFraisValidationService::rejeter(ndf, motif)
    ├─ ValidationException si motif vide
    ├─ DomainException si statut ≠ Soumise
    ├─ ndf.update(statut=Rejetee, motif_rejet)
    └─ log comptabilite.ndf.rejected
```

### Dé-comptabilisation implicite

`TransactionObserver` (enregistré dans `AppServiceProvider::boot()`) :

```
Transaction::delete() ou Transaction::forceDelete()
  │
  ▼  Observer::deleting() — capture la NDF liée avant que la FK nullOnDelete n'efface transaction_id
  Observer::deleted()
    └─ si NDF liée → update(statut=Soumise, transaction_id=null, validee_at=null)
       log comptabilite.ndf.reverted_to_submitted
```

Pas de bouton "Dé-comptabiliser" dédié. La suppression de la Transaction (via l'écran Transactions existant) déclenche automatiquement le revert.

### Badge top-bar

- View Composer (`AppServiceProvider::boot()`) injecte `ndfPendingCount` (count DB direct, pas d'accessor) et `canSeeNdf` dans `layouts.app`.
- Affiché si `canSeeNdf` (Admin ou Comptable dans le tenant courant).
- Icône `bi-receipt-cutoff` + texte "Notes de frais" + badge warning si count > 0.
- Clic → `/comptabilite/notes-de-frais` (onglet À traiter par défaut).
- Count scopé au tenant courant via TenantScope (aucune contamination cross-tenant).

### Logs structurés

Tous portés par `LogContext` (porte automatiquement `association_id` + `user_id`) :

| Clé | Déclencheur | Contexte |
|---|---|---|
| `comptabilite.ndf.validated` | `NoteDeFraisValidationService::valider()` | `ndf_id`, `transaction_id`, `montant_total`, `valide_par` |
| `comptabilite.ndf.rejected` | `NoteDeFraisValidationService::rejeter()` | `ndf_id`, `tiers_id`, `motif` |
| `comptabilite.ndf.reverted_to_submitted` | `TransactionObserver::revertLinkedNdf()` | `ndf_id`, `transaction_id` |

### Invariants multi-tenant

- TenantScope fail-closed sur `NoteDeFrais` et `Transaction` : un comptable asso A ne peut pas accéder aux NDF asso B (→ 404).
- Policy `treat` vérifie défensivement que `ndf.association_id === TenantContext::currentId()`.
- Stockage PJ transaction : `associations/{asso_id}/transactions/{tx_id}/ligne-{N}-{slug}.{ext}` — scopé par ID numérique immuable.
- Badge count : query DB `WHERE statut='soumise'` filtrée par TenantScope courant.

### Dettes portées (v0)

- **Slice 4** : refonte écrans Transaction (affichage + édition PJ au niveau ligne dans tous les formulaires Transaction existants).
- Rattachement user↔tiers (règle "Comptable ne traite pas sa propre NDF").
- Notifications email (validation / rejet) → slice dédiée.
- Pagination back-office NDF (cohérent Slice 2, dette tracée).
- Export liste NDF (CSV/PDF).
- Lightbox preview PJ.
- Bouton "Dé-comptabiliser" dédié si le flux via suppression Transaction s'avère contre-intuitif.
- Orphelins stockage en cas d'échec partiel de copie PJ : nettoyage via script maintenance.

### Prochaines slices

- **Slice 4** : unification PJ au niveau ligne dans les écrans Transaction (refonte multi-écrans — hors programme NDF portail).

## Lignes de frais kilométriques (Slice 2+3, livré 2026-04-20)

### Lignes de frais kilométriques

En plus de la ligne de frais standard, le Tiers peut saisir un **déplacement** via un second bouton "Ajouter un déplacement". Le wizard km demande en deux étapes :

1. **Carte grise** du véhicule (PDF / JPG / PNG / HEIC, 5 Mo max, obligatoire).
2. **Libellé**, **puissance fiscale (CV)**, **distance (km)** et **barème (€/km)**. Un lien vers le barème officiel (`impots.gouv.fr`) est fourni à titre d'aide. Le montant s'affiche en temps réel : `montant = distance × barème`, arrondi à 2 décimales. L'opération et la séance restent facultatives. Aucune sous-catégorie n'est demandée au Tiers — voir résolution automatique ci-dessous.

**Stockage ligne** : la ligne utilise la même table `notes_de_frais_lignes` que les lignes standards, distinguées via le champ `type` (`standard` | `kilometrique`). Les paramètres km sont persistés dans le champ JSON `metadata` (`cv_fiscaux`, `distance_km`, `bareme_eur_km`). Le montant stocké est recalculé côté serveur à chaque save — aucune valeur client n'est prise en confiance.

**Résolution de la sous-catégorie** : l'écran Paramètres → Sous-catégories expose un flag `Frais kilométriques`. Au save d'une ligne km, le service applique automatiquement la sous-catégorie flaggée si elle est unique dans l'asso. Si 0 ou plusieurs sont flaggées, `sous_categorie_id` reste `null` et le comptable tranchera au back-office (mini-form déjà éditable).

**Back-office** : aucune modification UI. Lors de la validation d'une NDF, le champ `transaction_lignes.notes` est enrichi :

- Ligne standard → `notes = libelle NDF` (comportement inchangé).
- Ligne km → `notes = "{libelle Tiers} — Déplacement de {km} km avec un véhicule {CV} CV au barème de {bareme} €/km"`.

Le comptable conserve la description fiscale nécessaire à sa validation, la carte grise est copiée vers `transaction_lignes.piece_jointe_path` selon la convention existante.

**Architecture extensible** : `App\Services\NoteDeFrais\LigneTypes\LigneTypeInterface` + `LigneTypeRegistry` préparent l'ajout futur de types normés (repas, hébergement) — chaque nouveau type ajoute un case à l'enum `NoteDeFraisLigneType` + une classe strategy, sans migration.

## Dette technique

- Champ `archived` sur `Tiers` : scénarios "Tiers archivé" skippés (décision Q1). Le service traite un Tiers archivé comme email inconnu ; à activer quand le champ `archived` sera ajouté au modèle.
- Champ `civilite` : l'accueil affiche "Bienvenue {prénom} {nom}" ; sera ajusté quand le champ sera disponible.
- Cleanup OTP expirés : lazy-delete au `verify`, pas de job dédié — à prévoir si la table grossit.
- Sous-domaines par asso (v3.1) : préparé via le préfixe `/portail/{slug}`.

## Abandon de créance

Statut : livré 2026-04-21, branche feat/portail-tiers-slice1-auth-otp.

Specs complètes : [`docs/specs/2026-04-21-ndf-abandon-creance.md`](specs/2026-04-21-ndf-abandon-creance.md).

### Flux utilisateur

```
Tiers — portail (soumission NDF)
  └─ coche "Je renonce au remboursement et propose un don par abandon de créance"
       │  abandon_creance_propose = true persisté sur la NDF
       ▼
  Page Show (NDF Soumise)
  └─ bandeau "Don par abandon de créance proposé — en attente de traitement"

Comptable — back-office (NDF avec intention d'abandon)
  └─ encart conditionnel sur la page Show :
       ├─ "Valider et constater l'abandon"  (désactivé si aucune sous-cat AbandonCreance configurée)
       └─ "Valider sans constater l'abandon"  (flux normal — intention ignorée)

Modale "Constater l'abandon"
  └─ compte bancaire + mode paiement + date comptabilisation (défaut : date NDF, bouton Aujourd'hui)
     + date du don (défaut : date NDF, bouton Aujourd'hui séparé)
       │
       ▼  Submit → NoteDeFraisValidationService::validerAvecAbandonCreance()
       ├─ DB::transaction {
       │    lockForUpdate sur NDF (anti-double-validation)
       │    Transaction Dépense  (lignes NDF, tiers=émetteur, statut_reglement=Recu, date=dateComptabilisation)
       │    Transaction Don/Recette  (ligne unique sous-cat AbandonCreance, tiers=émetteur,
       │                             statut_reglement=Recu, date=dateDon,
       │                             libellé "Don par abandon de créance — NDF #{id}")
       │    ndf.update(statut=DonParAbandonCreances, transaction_id, don_transaction_id)
       │    log comptabilite.ndf.abandon_creance_constate
       │  }

Tiers — portail (NDF traitée)
  └─ page Show : "Don par abandon de créance — acté le {date}" + montant du don
```

### Prérequis de configuration

L'admin doit avoir désigné une sous-catégorie avec l'usage `AbandonCreance` dans
**Paramètres → Comptabilité → Usages**.

Le preset seed `771 Abandon de créance` est pré-configuré avec les usages `Don` + `AbandonCreance`.
Sans cette configuration, le bouton "Valider et constater l'abandon" reste désactivé et un message
indique qu'aucune sous-catégorie n'est désignée.

### Garanties

| Garantie | Mécanisme |
|---|---|
| Atomicité | `DB::transaction` imbriquée — si la création du don échoue, la dépense est rollback |
| Isolation tenant | Garde explicite `$ndf->association_id === TenantContext::currentId()` au début de `valider()` et `validerAvecAbandonCreance()` |
| Anti-double-validation | `lockForUpdate` sur la NDF avant toute modification |
| Tout ou rien | Abandon sur la NDF complète — pas de partiel ligne par ligne |
| Pas de pollution "à régler" | Les 2 transactions créées ont `statut_reglement = Recu` ; les listes "à régler" filtrent sur `EnAttente` |

Sentinelle de non-affichage dans les listes :
`tests/Feature/Comptabilite/ListesARegler/AbandonCreanceNonAffichageTest.php`.

### Statut NDF

`StatutNoteDeFrais::DonParAbandonCreances` — cas terminal distinct de `Validee`.
Le libellé affiché est "Don par abandon de créance".

### Logs structurés

| Clé | Déclencheur | Contexte |
|---|---|---|
| `comptabilite.ndf.abandon-creance-constate` | `validerAvecAbandonCreance()` | `ndf_id`, `transaction_id`, `don_transaction_id`, `montant_total` |

### Hors scope (slices futures)

- Génération PDF CERFA d'abandon de créance (hook prêt via `don_transaction_id`).
- Notifications email de validation.
- Abandon partiel ligne par ligne.
- Réversibilité d'un abandon constaté.

### Fichiers clés

| Rôle | Fichier |
|---|---|
| Service (méthodes `valider` + `validerAvecAbandonCreance`) | `app/Services/NoteDeFrais/NoteDeFraisValidationService.php` |
| Enum statut (case `DonParAbandonCreances`) | `app/Enums/StatutNoteDeFrais.php` |
| Migration (colonnes `abandon_creance_propose`, `don_transaction_id`) | `database/migrations/2026_04_21_201442_add_abandon_creance_columns_to_notes_de_frais.php` |
| Portail Form (propriété + checkbox) | `app/Livewire/Portail/NoteDeFrais/Form.php` + `resources/views/livewire/portail/note-de-frais/form.blade.php` |
| Portail Show (bandeau statut) | `resources/views/livewire/portail/note-de-frais/show.blade.php` |
| Back-office Show (encart + modale + action) | `app/Livewire/BackOffice/NoteDeFrais/Show.php` + `resources/views/livewire/back-office/note-de-frais/show.blade.php` |
