# Fiche tiers 360 — Slice 2 (adhésions : table dédiée + gratuité + onglet fiche)

**Date** : 2026-05-08
**Statut** : PASS — prêt pour /plan
**Branche cible** : `feat/fiche-tiers-slice2-adhesions` (créée depuis `feat/fiche-tiers-360-slice0-1`, qui n'est pas encore mergée)

## 1. Contexte

Le slice 0+1 a livré le squelette de la fiche tiers + les onglets Coordonnées et Dons. Le programme prévoit d'enchaîner avec **Adhésion** (statut + cotisation) avant Factures/NDF/Opérations.

Le constat actuel :
- L'écran `tiers.adherents` (`AdherentList`) **infère** le statut adhérent à partir de `transaction_lignes` avec une sous-catégorie `usage = Cotisation`. C'est suffisant pour les adhérents qui paient, mais **inapte à représenter une adhésion gratuite** (membre d'honneur, bénévole, famille bénéficiaire, partenaire) — il n'y a alors pas de transaction à inférer.
- Le besoin de gérer des adhésions sans paiement est réel et prioritaire pour les associations pilotes.
- Le programme prévoit aussi un slice futur « adhésion à durée limitée » (durée paramétrable en mois, dont date-à-date = 12 mois). Ce slice-ci doit préparer le terrain (UI menu déroulant extensible) sans implémenter la durée.

## 2. Objectif du slice

1. **Créer une table `adhesions`** dédiée comme source de vérité du statut adhérent.
2. **Lier automatiquement** chaque transaction recette de cotisation à une ligne `adhesions(gratuite=false)` via un observer.
3. **Permettre l'enregistrement d'une adhésion gratuite** (sans transaction) avec saisie d'un motif obligatoire.
4. **Refactorer `AdherentList`** pour consommer la nouvelle table.
5. **Ajouter l'onglet « Adhésion »** sur la fiche tiers full-screen (read-only, liste plate chrono inverse).
6. **Préparer le terrain** d'un futur slice 3-adhésions (durée limitée) en transformant le bouton « Nouvelle cotisation » en dropdown extensible.

## 3. Hors scope

- Adhésion à durée limitée paramétrable (slice 3-adhésions) : pas de `date_debut`/`date_fin` dans la table maintenant. La table reste extensible, on ajoutera ces colonnes plus tard sans casser.
- Reçus fiscaux pour cotisations (programme reçu fiscal phase 2).
- Édition d'une adhésion existante (motif modifié, transaction rattachée…). Read-only sur la fiche tiers, gestion via création/soft-delete uniquement.
- Mode `année civile` : décliné par le user (incohérent avec exercice 1 sept → 31 août).
- Membres « à vie » : pas un mode d'adhésion, à voir comme un futur flag `est_membre_permanent` indépendant.

## 4. Décisions de conception

### 4.1 Modèle de données

Table `adhesions` :

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint unsigned | PK |
| `association_id` | bigint unsigned, FK | tenant scope, fail-closed |
| `tiers_id` | bigint unsigned, FK | |
| `exercice` | smallint unsigned | année de début (2025 = exercice 2025-2026) |
| `transaction_id` | bigint unsigned, FK nullable | non-null = adhésion liée à une transaction cotisation, null = gratuite |
| `gratuite` | bool default false | |
| `motif_gratuite` | string(255) nullable | obligatoire si `gratuite=true` (CHECK ou règle applicative) |
| `created_at`, `updated_at`, `deleted_at` | timestamps | softdeletes |

Index :
- Unique partiel sur `(association_id, tiers_id, exercice)` filtré sur `deleted_at IS NULL` — empêche les doublons d'adhésion sur le même exercice. Sur MySQL classique pas d'unique partiel ; on fait soit (a) un unique simple `(association_id, tiers_id, exercice)` + `whereNull('deleted_at')` au niveau requête + nettoyage avant insert, (b) une contrainte applicative dans le service. Pragmatiquement : unique simple et le service gère le cas restore.
- Index `(transaction_id)` pour lookup observer.

### 4.2 Modèle Eloquent `App\Models\Adhesion`

- Étend `App\Models\TenantModel` (fail-closed)
- `final class`, `declare(strict_types=1)`, `SoftDeletes`
- `casts`: `exercice => 'integer'`, `gratuite => 'boolean'`
- Relations : `tiers()`, `transaction()` (nullable)
- Scope helper `forExercice(int $annee)`

### 4.3 Observer

`App\Observers\AdhesionObserver` enregistré sur `Transaction`. Pas sur `TransactionLigne` (plus simple : on regarde toute la transaction quand elle est saved/deleted/restored, on infère la liste des cotisations à partir de `lignes`).

Comportement :
- `created/updated` (sur Transaction de type `Recette`) :
  - Lister les `lignes` qui ont une sous-catégorie avec usage `Cotisation`.
  - Si la transaction a au moins une ligne cotisation : créer/restaurer une `Adhesion(gratuite=false, transaction_id=tx.id, exercice = exercice de tx.date)` si pas déjà existante. Idempotent.
  - Si la transaction a perdu toutes ses lignes cotisations (update qui retire la dernière) : soft-delete l'adhésion liée (uniquement si `gratuite=false`).
- `deleted` (soft-delete de la Transaction) : soft-delete l'adhésion liée si `gratuite=false`.
- `restored` : restore l'adhésion liée si `gratuite=false`.

⚠️ Pour ne pas exploser sur les imports HelloAsso massifs, l'observer ne doit toucher qu'aux adhésions où `transaction_id = $transaction->id`. Pas de scan large.

### 4.4 Service `App\Services\AdhesionService`

Méthodes :
- `creerDepuisTransaction(Transaction $tx): ?Adhesion` — appelée par l'observer. Idempotent.
- `creerGratuite(Tiers $tiers, int $exercice, string $motif, User $createur): Adhesion` — appelée par le modal d'offrir. Empêche le doublon (si une adhésion non-soft-deleted existe déjà sur le même tiers/exercice → DomainException).
- Toutes les opérations dans `DB::transaction()`.

### 4.5 Migration historique (commande Artisan)

`php artisan adhesions:backfill` :
- Pour chaque association, parcourir les transactions cotisations existantes et générer les `adhesions` manquantes (idempotent : ne crée pas si une existe déjà).
- Logging : `X adhesions créées sur Y transactions analysées`.
- À exécuter en post-deploy via instructions de release.

### 4.6 Refactor `AdherentList`

Remplacer la query d'inférence (lignes 39-90 actuelles) par une query directe sur `adhesions` :

```php
$query = Tiers::query()->whereHas('adhesions', function ($q) use ($exercice) {
    $q->where('exercice', $exercice);
});
```

Le filtre « en retard » : tiers ayant une adhésion sur l'exercice précédent mais pas sur l'exercice courant.
Eager-loading de la dernière adhésion de chaque tiers (pour afficher montant + mode + compte de la transaction si `gratuite=false`, ou badge « Offerte » + motif si `gratuite=true`).

### 4.7 UI : dropdown sur `tiers.adherents`

Remplacer l'`<a>` ligne 21-24 par un dropdown Bootstrap :

```html
<div class="dropdown ms-auto">
  <button class="btn btn-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
    <i class="bi bi-plus-lg"></i> Nouvelle adhésion
  </button>
  <ul class="dropdown-menu dropdown-menu-end">
    <li><a class="dropdown-item" href="{{ route('comptabilite.transactions') }}">Nouvelle cotisation (avec paiement)</a></li>
    <li><button class="dropdown-item" wire:click="$dispatch('offrir-adhesion')">Adhésion gratuite (offerte)</button></li>
  </ul>
</div>
```

Le slice 3-adhésions ajoutera une 3e entrée « Adhésion à durée limitée ».

### 4.8 Modal `OffrirAdhesionModal` (composant Livewire)

Composant Livewire global monté dans `app-sidebar.blade.php` (à la suite de `<livewire:tiers-quick-view />`). Écoute `offrir-adhesion`.

Champs :
- Sélecteur tiers (réutilise `TiersAutocomplete`)
- Sélecteur exercice (default = exercice courant via `ExerciceService::current()`, choix sur les exercices ouverts)
- Textarea motif (obligatoire, min 3 caractères, max 255)
- Bouton « Offrir l'adhésion »

Validation :
- Tiers requis
- Exercice requis et ouvert
- Motif obligatoire, longueur min/max

Soumission → appelle `AdhesionService::creerGratuite()` → flash success + dispatch `adhesion-creee` (que `AdherentList` peut écouter pour rafraîchir).

### 4.9 Onglet « Adhésion » sur fiche tiers

Composant `App\Livewire\Tiers\Onglets\Adhesion` enfant lazy de `FicheTiers`. Conditionnel (apparaît si tiers a au moins 1 adhésion). Compteur sur l'onglet = nombre d'adhésions.

Vue : liste plate chrono inverse (1 ligne par adhésion), colonnes :
- Exercice (`{{ $adhesion->exercice }} – {{ $adhesion->exercice + 1 }}`)
- Type (badge « Cotisation » bleu si `gratuite=false`, « Offerte » jaune si `gratuite=true`)
- Date (de la transaction si payée, de la création si gratuite)
- Montant (si payée) / Motif (si gratuite)
- Compte / Mode (si payée)
- Lien vers la transaction (icône) si payée

Pas d'action MVP (read-only). Les onglets s'enrichiront au fil du programme.

### 4.10 Mise à jour `FicheTiers::render()`

Ajouter le calcul du compteur adhésions et l'onglet conditionnel :

```php
$adhesionsCount = $this->tiers->adhesions()->count();

if ($adhesionsCount > 0) {
    $onglets[] = ['key' => 'adhesion', 'label' => 'Adhésion', 'count' => $adhesionsCount];
}
```

Routing onglet : `?onglet=adhesion`.

## 5. Symétrie portail tiers ↔ fiche back-office

Application de la règle [feedback_symetrie_portail_fiche_tiers.md].

### Onglet Adhésion
- **État portail actuel** : pas d'écran « Mes adhésions » dédié.
- **Action factorisation** : créer `App\Services\Tiers\TiersAdhesionTimelineService::forTiers(Tiers): AdhesionTimelineDTO` que le portail consommera quand un onglet équivalent sera priorisé. Pour ce slice, la fiche back-office consomme directement le service.
- **Dette d'alignement** : à inscrire dans la mémoire portail (« onglet Mes adhésions à créer » + variantes payée/offerte).

### Adhésion gratuite côté portail
- N'a pas vocation à exister côté portail (c'est un geste admin uniquement, le donateur ne s'auto-offre pas une adhésion).

## 6. Architecture cible

### 6.1 Fichiers nouveaux

```
app/
  Models/
    Adhesion.php                                  (TenantModel + SoftDeletes)
  Observers/
    AdhesionObserver.php                          (sur Transaction)
  Services/
    AdhesionService.php                           (creerDepuisTransaction, creerGratuite)
    Tiers/
      TiersAdhesionTimelineService.php            (forTiers, partagé portail futur)
      DTO/
        AdhesionTimelineDTO.php                   (array<int, AdhesionLigneDTO>, totalCount)
        AdhesionLigneDTO.php                      (Adhesion + libellés calculés)
  Console/Commands/
    BackfillAdhesions.php                         (commande Artisan)
  Livewire/
    OffrirAdhesionModal.php                       (modal global)
    Tiers/Onglets/
      Adhesion.php                                (onglet fiche tiers)

database/
  migrations/
    YYYY_MM_DD_HHMMSS_create_adhesions_table.php
  factories/
    AdhesionFactory.php

resources/views/
  livewire/
    offrir-adhesion-modal.blade.php
    tiers/onglets/
      adhesion.blade.php
```

### 6.2 Fichiers existants à modifier

| Path | Modification |
|---|---|
| `app/Models/Tiers.php` | Ajouter `public function adhesions(): HasMany { return $this->hasMany(Adhesion::class); }` |
| `app/Models/Transaction.php` | Ajouter `public function adhesions(): HasMany { return $this->hasMany(Adhesion::class); }` |
| `app/Providers/AppServiceProvider.php:60` (à proximité) | `Transaction::observe(AdhesionObserver::class);` |
| `app/Livewire/AdherentList.php:39-93` | Remplacer la query d'inférence par requête `adhesions` |
| `resources/views/livewire/adherent-list.blade.php:21-24` | Remplacer le bouton par le dropdown |
| `resources/views/livewire/adherent-list.blade.php:32-90` | Adapter les colonnes pour afficher badge type adhésion (cotisation/offerte) |
| `resources/views/layouts/app-sidebar.blade.php` | Ajouter `<livewire:offrir-adhesion-modal />` près du `<livewire:tiers-quick-view />` |
| `resources/views/layouts/app.blade.php:633` | Idem, l'autre layout |
| `app/Livewire/Tiers/FicheTiers.php` | Ajouter le calcul du compteur et l'inclusion de l'onglet adhesion |
| `resources/views/livewire/tiers/fiche-tiers.blade.php` | Ajouter le case `@if($currentOnglet === 'adhesion')` qui monte `<livewire:tiers.onglets.adhesion>` |

### 6.3 Tests

| Path | Niveau | Couvre |
|---|---|---|
| `tests/Unit/Models/AdhesionTest.php` | unit | Cast types, relation tiers/transaction, scope forExercice, tenant scope fail-closed |
| `tests/Unit/Services/AdhesionServiceTest.php` | unit | creerDepuisTransaction idempotent, creerGratuite (avec/sans doublon) |
| `tests/Unit/Services/Tiers/TiersAdhesionTimelineServiceTest.php` | unit | DTOs, ordre desc, comptage, tenant scope |
| `tests/Feature/Adhesions/AdhesionObserverTest.php` | feature | Création auto sur transaction cotisation, soft-delete miroir, restore miroir, idempotence sur update sans changement de cotisation |
| `tests/Feature/Adhesions/BackfillAdhesionsCommandTest.php` | feature | Commande génère les adhésions manquantes, idempotente, ne crée pas si déjà présent |
| `tests/Livewire/AdherentListAdhesionsTest.php` | livewire | Refactor consomme adhesions (incluant les gratuites), filtre a_jour/en_retard |
| `tests/Livewire/OffrirAdhesionModalTest.php` | livewire | Validation tiers/exercice/motif, soumission crée gratuite, doublon refusé, dispatch event |
| `tests/Feature/Tiers/FicheTiersOngletAdhesionTest.php` | feature | Onglet absent si pas d'adhésion, présent avec compteur, query string `?onglet=adhesion` |
| `tests/Livewire/Tiers/Onglets/AdhesionTest.php` | livewire | Rendu liste, badge cotisation/offerte, montant si payée, motif si gratuite, lien vers transaction si payée |

## 7. Conventions à respecter

- `declare(strict_types=1)` + `final class` + type hints partout
- PSR-12 via Pint
- Locale fr (labels modal, badges, messages)
- Multi-tenant : `Adhesion extends TenantModel`
- Cast `(int)` des deux côtés sur comparaisons PK/FK
- En-têtes de tableaux : `table-dark` style bleu foncé `#3d5473`
- `wire:confirm` via modale Bootstrap (pas natif) pour suppression d'adhésion
- Tri colonnes JS avec `data-sort` si tableaux triables
- Pas de `<h1>` dans la fiche tiers (déjà respecté)

## 8. Risques & points d'attention

1. **Idempotence de l'observer** : impératif. Si un seed/import recrée des transactions en bulk, on ne doit pas créer N fois la même adhésion. Le `firstOrCreate` sur `(tiers_id, exercice, transaction_id)` règle ça.
2. **Backfill** : doit être lancé une seule fois après le déploiement. Idempotence du backfill garantit qu'un re-run est sans effet.
3. **HelloAsso** : les transactions HelloAsso historiques vont déclencher l'observer au backfill. Vérifier que ça reste rapide (< 30s sur SVS qui a ~quelques milliers de transactions).
4. **Soft-delete cascadé** : si une transaction est soft-deleted, l'adhésion liée doit l'être aussi (sauf si gratuite — mais gratuite a `transaction_id=null` donc pas concernée). Le restore doit être miroir.
5. **Adhésion sur exercice clos** : règle métier ? Pour MVP on autorise (utile pour rattraper une adhésion oubliée). Pas de blocage.
6. **Rétrocompatibilité `AdherentList`** : le filtre `a_jour`/`en_retard` doit donner les mêmes résultats avant/après refacto sur les associations sans adhésions gratuites. Test de non-régression nécessaire.
7. **Performance : N+1 onglet Adhésion** : eager-load `transaction.compte` dans le DTO.
8. **Modèle de motif** : 255 chars max applicatif, mais on garde une string colonne (pas text) pour rester simple.

## 9. Définition de fait

- ✅ Migration appliquée, table `adhesions` en place
- ✅ Observer enregistré, tests verts
- ✅ Backfill exécuté (en local ; prod = à exécuter au déploiement)
- ✅ `AdherentList` refactoré, filtres `a_jour`/`en_retard` fonctionnels
- ✅ Dropdown sur `tiers.adherents` opérationnel + modal offrir adhésion
- ✅ Onglet « Adhésion » sur fiche tiers
- ✅ Suite Pest verte (tests existants + nouveaux)
- ✅ Pint clean
- ✅ Test manuel localhost : créer une transaction cotisation → adhésion auto, supprimer transaction → adhésion soft-delete, offrir adhésion gratuite → ligne créée, fiche tiers affiche l'onglet
- ✅ Mémoire projet à jour
