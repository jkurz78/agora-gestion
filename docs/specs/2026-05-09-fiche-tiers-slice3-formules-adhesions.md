# Fiche tiers 360 — Slice 3 (Formules d'adhésion + durée + mapping HelloAsso)

**Date** : 2026-05-09
**Statut** : SLICE 3 LIVRÉ (3a backend + 3b UI) — prêt pour test manuel + PR
**Branche cible** : `feat/fiche-tiers-slice3-formules-adhesions` (créée depuis `feat/fiche-tiers-slice2-adhesions`, qui n'est pas encore mergée)

## 1. Contexte

Le slice 2 a posé `Adhesion` comme entité primaire (la transaction devient une conséquence comptable), avec un mode unique « exercice ». Le brainstorm du 2026-05-09 a fait émerger trois besoins structurants :

1. **Adhésions à durée limitée paramétrable** (1, 3, 6, 12 mois ; le « date à date » HelloAsso = 12 mois).
2. **Catalogue de formules d'adhésion** : une asso a typiquement plusieurs tarifs (« Adhésion adulte », « Étudiant », « Bienfaiteur ») avec leurs caractéristiques propres (mode, durée, déductibilité).
3. **Mapping fin des Tiers HelloAsso** : aujourd'hui le sync absorbe les `Membership` HelloAsso dans une seule sous-cat, perdant l'information de tier (label, déductibilité fiscale, validity_type). En miroir, les events HelloAsso multi-tiers perdent l'info de tarif côté Participant.

L'API HelloAsso v5 expose au niveau Form un `validity_type` (`MovingYear` | `Custom` | `Illimited`) et au niveau Tier un `is_eligible_tax_receipt`. Ces champs doivent se refléter côté AgoraGestion pour ne pas perdre la sémantique.

## 2. Objectifs du slice

1. Introduire **`formule_adhesion`** comme catalogue paramétré par asso (mode, durée, montant par défaut, déductibilité, sous-cat dédiée, état actif/historique).
2. Introduire **`helloasso_tier_mappings`** polymorphe (target = formule_adhesion OU type_operation_tarif) pour tracer le lien Tier HelloAsso ↔ entité métier AgoraGestion. **Branchée pour les memberships dans ce slice ; events viendront s'y greffer dans un slice ultérieur sans refacto structurel.**
3. **Étendre `adhesions`** : `formule_adhesion_id` nullable, `date_debut` / `date_fin` nullables (mode durée), `notes` (renommage de `motif_gratuite`), suppression du flag `gratuite` (déduction depuis `transaction_id`/`montant`).
4. **Wizard « Nouvelle adhésion »** consolidé : remplace `OffrirAdhesionModal`, propose tiers + formule + durée si applicable + montant + notes. Création atomique Adhesion + Transaction si paiement.
5. **Sync HelloAsso enrichie** : exploite `tier.id`, résout via mapping, peuple `formule_adhesion_id` et `date_debut`/`date_fin` quand applicable.
6. **UI Paramètres** : nouvel écran « Paramètres → Adhésions » (CRUD formules) + extension « Paramètres → HelloAsso » (mapping tiers semi-automatique).
7. **AdherentList et onglet Adhésion fiche tiers** adaptés (badge formule, dates de validité, statut « à jour » devient fonction de la date du jour vs `date_fin` pour mode durée).

## 3. Hors scope

- **Mapping HelloAsso events ↔ TypeOperationTarif** : la table polymorphe est posée dans ce slice, mais l'exploitation côté events est traitée dans un slice futur (« Slice events tarification fine »).
- **Reçus fiscaux pour cotisations éligibles** : programme reçu fiscal phase 2, traité comme slice indépendant après la stabilisation du modèle adhésion.
- **Édition d'une adhésion existante** : pas dans le MVP. La création reste le canal principal ; pour modifier, on soft-delete et on recrée. Possible évolution future (geste « compléter une adhésion legacy » pour rattacher une formule a posteriori).
- **Mode `Illimited` HelloAsso** : explicitement non supporté côté AgoraGestion. Si un form HelloAsso a `validity_type = Illimited`, sync warn + adhésion créée en mode `exercice` par défaut + alerte dashboard.
- **Tarification multi-niveau intra-formule** (ex « adhésion adulte avec ou sans option journal ») : pas modélisé. Une formule = un tarif. Multiples tarifs = multiples formules.

## 4. Décisions de conception

### 4.1 Catalogue `formules_adhesion`

```sql
CREATE TABLE formules_adhesion (
    id BIGINT UNSIGNED PRIMARY KEY,
    association_id BIGINT UNSIGNED NOT NULL,        -- tenant scope (fail-closed)
    nom VARCHAR(120) NOT NULL,                       -- ex « Adhésion adulte 2025 »
    description TEXT NULL,
    mode ENUM('exercice','duree') NOT NULL,
    duree_mois SMALLINT UNSIGNED NULL,               -- requis si mode=duree, NULL sinon
    montant_par_defaut DECIMAL(10,2) NULL,           -- préalimentation wizard, pas de validation stricte
    deductible_fiscal BOOLEAN NOT NULL DEFAULT FALSE,
    sous_categorie_id BIGINT UNSIGNED NOT NULL,      -- FK sous_categories (validation usage=Cotisation)
    actif BOOLEAN NOT NULL DEFAULT TRUE,
    created_at, updated_at, deleted_at,

    INDEX idx_actif (association_id, actif),
    INDEX idx_souscat (sous_categorie_id)
);

-- Contrainte « au plus 1 formule active par sous-catégorie » :
-- MySQL ne supportant pas les index uniques partiels (`WHERE`), la contrainte
-- est appliquée en applicatif (validation modèle + service). Tests dédiés
-- garantissent qu'un essai d'activation d'une 2e formule sur la même sous-cat
-- est rejeté avec DomainException explicite.
```

**Règles métier** :
- `mode = duree` ⇒ `duree_mois` obligatoire (CHECK applicatif).
- `mode = exercice` ⇒ `duree_mois = NULL`.
- `sous_categorie_id` doit avoir `usage = Cotisation` (validation au modèle).
- À tout instant, **au plus 1 formule active** par `(association_id, sous_categorie_id)`. Si l'admin tente d'activer une 2e formule sur la même sous-cat, le service désactive l'ancienne (ou refuse selon UX choisie — ma reco : refuser, l'admin doit explicitement désactiver l'ancienne).
- Soft-delete autorisé : permet l'historique (« politique tarifaire 2024 »).

### 4.2 Mapping HelloAsso polymorphe `helloasso_tier_mappings`

```sql
CREATE TABLE helloasso_tier_mappings (
    id BIGINT UNSIGNED PRIMARY KEY,
    association_id BIGINT UNSIGNED NOT NULL,
    helloasso_form_slug VARCHAR(255) NOT NULL,
    helloasso_tier_id INT UNSIGNED NOT NULL,
    helloasso_tier_label VARCHAR(255) NOT NULL,    -- cache pour reconnaissance UI
    target_type VARCHAR(100) NOT NULL,               -- FQCN: App\Models\FormuleAdhesion | App\Models\TypeOperationTarif (pas d'alias morphMap)
    target_id BIGINT UNSIGNED NOT NULL,
    created_at, updated_at,

    UNIQUE KEY unique_tier (association_id, helloasso_form_slug, helloasso_tier_id),
    INDEX idx_target (target_type, target_id)
);
```

**Convention** : Laravel polymorphic relation via `(target_type, target_id)`. Modèles éligibles déclarent un `morphMany` :

```php
// FormuleAdhesion
public function helloAssoTierMappings(): MorphMany
{
    return $this->morphMany(HelloAssoTierMapping::class, 'target');
}

// TypeOperationTarif (slice ultérieur)
public function helloAssoTierMappings(): MorphMany { ... }
```

### 4.3 Refonte `adhesions`

Migration alter :

```sql
ALTER TABLE adhesions
    ADD COLUMN formule_adhesion_id BIGINT UNSIGNED NULL AFTER transaction_id,
    ADD COLUMN date_debut DATE NULL AFTER formule_adhesion_id,
    ADD COLUMN date_fin DATE NULL AFTER date_debut,
    ADD COLUMN notes VARCHAR(255) NULL AFTER date_fin,
    ADD CONSTRAINT FOREIGN KEY (formule_adhesion_id) REFERENCES formules_adhesion(id) ON DELETE SET NULL,
    ADD INDEX idx_dates (tiers_id, date_debut, date_fin);

-- Migration de données :
UPDATE adhesions SET notes = motif_gratuite WHERE motif_gratuite IS NOT NULL;
ALTER TABLE adhesions DROP COLUMN motif_gratuite;
ALTER TABLE adhesions DROP COLUMN gratuite;
```

**Règles métier post-migration** :
- `exercice` reste rempli si `formule.mode = exercice` OU si adhésion legacy (sans formule).
- `date_debut` / `date_fin` remplis uniquement si `formule.mode = duree` (calculés automatiquement à la création).
- L'unicité `(association_id, tiers_id, exercice)` est **conservée** uniquement pour les adhésions mode exercice (`exercice IS NOT NULL`). Pour mode durée, recouvrement métier détecté en applicatif (un adhérent ne peut pas avoir 2 adhésions dont les périodes se chevauchent).

### 4.4 Service `AdhesionService` étendu

```php
final class AdhesionService
{
    public function creerDepuisTransaction(Transaction $tx): ?Adhesion;       // Inchangé en signature, enrichi en logique
    public function creerDepuisWizard(NouvelleAdhesionDTO $data): Adhesion;   // Nouveau, remplace creerGratuite
}
```

**Logique enrichie de `creerDepuisTransaction`** :
1. Vérifier la transaction (recette + tiers présent + ligne avec sous-cat usage Cotisation).
2. **Résolution de la formule** — priorité explicite :
   - **Priorité 1** (HelloAsso) : si `tx.helloasso_payment_id` est renseigné, chercher le tier d'origine via `helloasso_item_id` puis `helloasso_tier_mappings(form_slug, tier_id) → target_type=formule_adhesion`. Si trouvé, c'est la formule à appliquer (override la sous-cat).
   - **Priorité 2** (saisie manuelle) : sinon, lire la sous-cat de la 1re ligne cotisation et appeler `$sousCat->formuleAdhesionActive()`. Si trouvée, c'est la formule.
   - **Sinon** : adhésion legacy sans formule (`formule_adhesion_id = null`).
3. Calcul des dates selon `formule.mode` :
   - `exercice` : `exercice = exerciceFromDate(tx.date)`, `date_debut = null`, `date_fin = null`
   - `duree`  : `date_debut = tx.date`, `date_fin = tx.date + formule.duree_mois`, `exercice = null`
4. Idempotent : lookup adhésion existante par `(tiers_id, exercice)` si mode exercice, sinon par `(tiers_id, date_debut, date_fin)` exact match. Restore si soft-deleted.
5. Si l'adhésion existe déjà avec une formule différente : on ne l'écrase pas (la 1re transaction prime, comme acté en slice 2).

**`creerDepuisWizard(NouvelleAdhesionDTO $data)`** :
- DTO contient : `tiers_id`, `formule_id`, `date_debut` (si mode=duree, défaut today), `montant` (decimal nullable, 0 = gratuite), `notes`, `date_paiement`, `mode_paiement`, `compte_id`.
- Crée transaction si `montant > 0`, sinon `transaction_id = null`.
- Crée Adhesion avec formule, dates, notes, etc.
- Atomique en `DB::transaction()`.

### 4.5 Observer

`AdhesionObserver` et `AdhesionTransactionLigneObserver` (slice 2) restent en place. Le service est enrichi, les observers transparents.

### 4.6 Sync HelloAsso enrichie

`HelloAssoSyncService::resolveItem()` retourne en plus :
- `helloasso_tier_id` (depuis `item.tierId`)
- `validity_type` (depuis `form.validity_type` quand `form_type=Membership`)

Le résolveur cherche un `helloasso_tier_mappings` pour `(form_slug, tier_id)`. Si trouvé et target=`formule_adhesion`, l'adhésion résultante (créée par observer) sera rattachée à cette formule via le helper `sousCat->formuleAdhesionActive()` qui retournera la formule cible.

**Cas non mappé** : warn dans le log + tableau de bord HelloAsso (slice 4 super-admin déjà), pas de fail. L'adhésion est créée legacy.

**Mode `Illimited`** : warn + crée en mode exercice par défaut. La signalisation peut prendre la forme d'un encart sur l'écran HelloAsso → mapping ou d'une notification admin.

### 4.7 UI : Paramètres → Adhésions → Formules

Nouveau composant Livewire pleine page : `App\Livewire\Parametres\Adhesions\FormulesList`.

Structure de l'écran :
- Toolbar : bouton « Nouvelle formule » + filtre actif/inactif/toutes.
- Tableau : nom, sous-cat, mode, durée_mois, montant_défaut, déductible, état (badge actif/inactif), actions (édit, désactiver, supprimer (soft-delete)).
- Modale d'édition (création/modification) : tous les champs, validation côté serveur.

Validations :
- `nom` requis, max 120
- `sous_categorie_id` requis, doit être `usage=Cotisation` ET pas déjà liée à une formule active (excepté en édition de la formule en cours)
- `mode` requis, enum
- Si `mode=duree` : `duree_mois` requis, entre 1 et 36 (3 ans max)
- `montant_par_defaut` ≥ 0 si renseigné
- Reste optionnel

### 4.8 UI : Paramètres → HelloAsso → Mapping Tiers

Extension de l'écran HelloAsso existant. Onglet « Mapping tiers » :
- Bouton « Importer les tiers HelloAsso » (appel `HelloAssoApiClient::fetchForms()` puis fetch des tiers de chaque form Membership).
- Tableau : form_slug | tier_id | tier_label | montant | déductibilité | sous-cat → formule cible (dropdown des formules de l'asso) | actions.
- Création/édition d'un mapping appel `helloasso_tier_mappings`.
- Filtres : non mappés en haut.

**Note UX** : le bouton import peut détecter automatiquement les correspondances probables (label tier ≈ nom formule, montant tier ≈ montant_par_defaut formule) et pré-suggérer.

### 4.9 Wizard « Nouvelle adhésion »

Remplace `OffrirAdhesionModal`. Composant Livewire `App\Livewire\NouvelleAdhesionModal`.

Étapes (mono-étape Bootstrap, pas de stepper) :
- Sélecteur tiers (`<livewire:tiers-autocomplete>`)
- Sélecteur formule (dropdown des formules actives de l'asso)
- Selon formule.mode :
  - Si `exercice` : sélecteur exercice (default = courant via `ExerciceService::openYears()`)
  - Si `duree` : champ `date_debut` (default today), champ readonly `date_fin` (calculé)
- Champ montant (préalimenté par `formule.montant_par_defaut`, éditable, accept 0)
- Si montant > 0 : champs date_paiement, mode_paiement, compte_id, référence
- Champ notes (texte libre, autocomplete sur valeurs existantes)
- Boutons « Annuler » / « Créer l'adhésion »

Le bouton dropdown sur AdherentList devient :
- « Nouvelle cotisation (avec paiement) » → wizard avec montant > 0 par défaut
- « Adhésion gratuite » → wizard pré-rempli avec montant = 0

Slice 4 ajoutera la 3e entrée « Adhésion sur durée libre ».

### 4.10 AdherentList adapté

- Colonne « Type » : montre la formule ou « Cotisation » (legacy).
- Colonne « Validité » : exercice si mode=exercice, intervalle date si mode=duree.
- Filtre `a_jour` : adhésion existante pour `now()` (intersection date_debut/date_fin OU exercice = courant).
- Filtre `en_retard` : avait une adhésion l'exercice précédent OU dans les 30 jours passés (mode durée), pas maintenant.

### 4.11 Onglet Adhésion fiche tiers

Adapté : ajoute colonnes formule, date_debut/fin si mode=duree. Le tri reste chronologique inverse (date_fin ou exercice).

## 5. Mapping conceptuel HelloAsso ↔ AgoraGestion (rappel)

| HelloAsso | AgoraGestion |
|---|---|
| Form `form_type=Membership` + `validity_type` | métadonnée portée sur la formule |
| `validity_type = MovingYear` | `formule.mode = duree`, `duree_mois = 12` |
| `validity_type = Custom` | `formule.mode = exercice` |
| `validity_type = Illimited` | non supporté, fallback `exercice` + warn |
| `Tier` | 1 `formule_adhesion` |
| `Tier.price` | `formule.montant_par_defaut` |
| `Tier.is_eligible_tax_receipt` | `formule.deductible_fiscal` |
| `Tier.label` | `formule.nom` |
| `helloasso_tier_mappings` | trace explicite du lien |
| Form `form_type=Event` + tiers | hors scope ce slice (mapping polymorphe préparé) |

## 6. Architecture cible (fichiers)

### Nouveaux

| Path | Responsabilité |
|---|---|
| `database/migrations/YYYY_MM_DD_create_formules_adhesion_table.php` | Catalogue formules |
| `database/migrations/YYYY_MM_DD_create_helloasso_tier_mappings_table.php` | Mapping polymorphe |
| `database/migrations/YYYY_MM_DD_alter_adhesions_add_formule_dates_notes.php` | Refonte adhésions |
| `database/factories/FormuleAdhesionFactory.php` | |
| `database/factories/HelloAssoTierMappingFactory.php` | |
| `app/Models/FormuleAdhesion.php` | TenantModel + softdeletes |
| `app/Models/HelloAssoTierMapping.php` | + relations morphTo |
| `app/Services/Adhesion/NouvelleAdhesionDTO.php` | DTO immuable |
| `app/Services/Adhesion/AdhesionService.php` | Étendu (existe déjà) |
| `app/Services/Adhesion/SousCategorieFormuleResolver.php` | Helper « sous-cat → formule active » |
| `app/Livewire/NouvelleAdhesionModal.php` | Wizard global |
| `app/Livewire/Parametres/Adhesions/FormulesList.php` | Page Paramètres → Adhésions |
| `app/Livewire/Parametres/HelloAsso/MappingTiers.php` | Onglet HelloAsso → Mapping tiers |
| `resources/views/livewire/nouvelle-adhesion-modal.blade.php` | Vue wizard |
| `resources/views/livewire/parametres/adhesions/formules-list.blade.php` | Vue formules |
| `resources/views/livewire/parametres/helloasso/mapping-tiers.blade.php` | Vue mapping |
| `resources/views/parametres/adhesions.blade.php` | Layout page paramètres adhésions |

### Existants à modifier

| Path | Modification |
|---|---|
| `app/Models/SousCategorie.php` | Ajouter `formulesAdhesion(): HasMany`, `formuleAdhesionActive(): ?FormuleAdhesion` |
| `app/Models/Adhesion.php` | Ajouter `formuleAdhesion(): BelongsTo`, supprimer cast `gratuite`, retirer `motif_gratuite` du fillable, ajouter `notes`/`date_debut`/`date_fin`/`formule_adhesion_id` |
| `app/Services/AdhesionService.php` | Logique formule appliquée + `creerDepuisWizard` (nouveau), suppression de `creerGratuite` (remplacé par wizard) |
| `app/Observers/AdhesionTransactionLigneObserver.php` | Inchangé (l'enrichissement formule passe par le service) |
| `app/Services/HelloAssoSyncService.php` | Étendre `resolveItem` pour exposer `tier_id`, `validity_type` ; lookup mapping si Membership |
| `app/Services/HelloAssoApiClient.php` | Méthode `fetchFormDetail($formType, $formSlug)` pour récupérer les tiers d'un form |
| `app/Livewire/AdherentList.php` | Filtres a_jour/en_retard adaptés au mode durée |
| `resources/views/livewire/adherent-list.blade.php` | Colonnes formule + validité |
| `resources/views/livewire/tiers/onglets/adhesion.blade.php` | Idem |
| `app/Livewire/OffrirAdhesionModal.php` | **Supprimé** (remplacé par `NouvelleAdhesionModal`) |
| `resources/views/components/sidebar.blade.php` | Ajouter entrée « Paramètres → Adhésions » |
| `resources/views/livewire/parametres/...` | Ajouter sous-section adhésions |

### Tests

| Path | Niveau | Couvre |
|---|---|---|
| `tests/Unit/Models/FormuleAdhesionTest.php` | unit | Tenant scope, validation usage Cotisation, contrainte unique active |
| `tests/Unit/Models/HelloAssoTierMappingTest.php` | unit | Polymorphisme target, contraintes |
| `tests/Unit/Services/Adhesion/AdhesionServiceWizardTest.php` | unit | `creerDepuisWizard` mode exercice / mode durée / gratuit |
| `tests/Unit/Services/Adhesion/SousCategorieFormuleResolverTest.php` | unit | Lookup formule active, fallback null |
| `tests/Feature/Adhesions/ObserverWithFormuleTest.php` | feature | Observer applique la formule active de la sous-cat ; legacy si pas de formule ; multi-cotisations même exercice |
| `tests/Feature/HelloAsso/SyncWithTierMappingTest.php` | feature | Tier mappé → formule appliquée ; non mappé → legacy + warn |
| `tests/Livewire/NouvelleAdhesionModalTest.php` | livewire | Validation champs, mode exercice/durée, montant zéro = gratuite, doublon refusé |
| `tests/Livewire/Parametres/Adhesions/FormulesListTest.php` | livewire | CRUD formules, validation 1 active par sous-cat |
| `tests/Livewire/Parametres/HelloAsso/MappingTiersTest.php` | livewire | Import depuis API, création mapping, édition |
| `tests/Feature/Tiers/AdherentListWithDureeTest.php` | feature | Filtre a_jour fonctionne sur mode durée |
| `tests/Feature/Tiers/OngletAdhesionWithFormuleTest.php` | feature | Affichage formule + dates dans onglet fiche tiers |
| `tests/Feature/Migration/AdhesionsRefactorMigrationTest.php` | feature | Migration `motif_gratuite → notes` + drop `gratuite`, données préservées |

## 7. Conventions à respecter

- `declare(strict_types=1)` + `final class` + type hints
- PSR-12 via Pint
- Locale fr (labels, validation messages)
- Multi-tenant : tous les modèles étendent `TenantModel`
- Cast `(int)` PK/FK des deux côtés
- En-têtes tableaux `table-dark` style bleu foncé `#3d5473`
- `wire:confirm` via modale Bootstrap pour soft-delete
- Sous-catégorie `usage=Cotisation` validée au modèle FormuleAdhesion
- Pas de `<h1>` sur les nouveaux écrans pleine page (pattern ATtelier de slice 0+1)

## 8. Risques & points d'attention

1. **Migration de données** : adhésions slice 2 ont `gratuite` + `motif_gratuite`. Migration doit être idempotente, testée sur dataset SVS avant prod.
2. **Backfill formules** : le slice ne crée PAS automatiquement de formule pour les sous-cats existantes. L'admin paramètre quand il en a besoin. Les adhésions existantes restent legacy (`formule_adhesion_id = null`). Le filtre AdherentList « à jour » continue de fonctionner via le test sur exercice.
3. **Recouvrement adhésions mode durée** : un adhérent ne doit pas avoir 2 adhésions dont les périodes se chevauchent. Validation applicative dans `creerDepuisWizard`.
4. **Mapping HelloAsso non mappé** : warn mais ne bloque pas. Sinon le sync tomberait au moindre tier non configuré.
5. **Validity_type Illimited** : explicitement non supporté. Fallback exercice + warn. Documenter dans le mapping HelloAsso.
6. **Sous-cat changée sur formule active** : impact sur les transactions futures (nouvelle sous-cat utilisée). Admin guidé via modale de confirmation.
7. **Performance** : lookup `sous-cat → formule active` fréquent (à chaque transaction cotisation). Cache request-scoped recommandé dans `SousCategorieFormuleResolver`.
8. **Tests existants slice 2** : doivent passer sans modification (l'API publique `creerDepuisTransaction` est inchangée). Les tests `OffrirAdhesionModalTest` à supprimer (remplacés par `NouvelleAdhesionModalTest`).
9. **Symétrie portail** : règle `feedback_symetrie_portail_fiche_tiers.md` — `TiersAdhesionTimelineService` (créé slice 2) reste la source partagée. Pas de changement structurel côté portail dans ce slice.

## 9. Stratégie de scinder le slice (option)

Le slice est volumineux (~12-15 phases TDD). Si l'utilisateur préfère, on peut le scinder en :
- **Slice 3a — Backend** : migrations, modèles, service, observer, sync HelloAsso enrichie, tests unit/feature.
- **Slice 3b — UI** : wizard, paramètres formules, paramètres HelloAsso mapping, AdherentList/onglet adaptés, tests livewire.

Avantage : recette intermédiaire en local après 3a (le système fonctionne en mode legacy + quelques formules paramétrées via tinker). Slice 3b ajoute le confort utilisateur.

À arbitrer par l'utilisateur avant l'écriture du plan.

## 10. Définition de fait

- ✅ Migrations appliquées, tables créées, schema cohérent
- ✅ Modèles + factories + tests unit verts
- ✅ Service `AdhesionService::creerDepuisWizard` couvert par tests
- ✅ Observer transaction → adhésion avec formule appliquée si configurée, legacy sinon
- ✅ Sync HelloAsso : mapping tier appliqué, fallback legacy si non configuré, log clair
- ✅ Wizard « Nouvelle adhésion » remplace `OffrirAdhesionModal` — gratuite et payante via le même geste
- ✅ Paramètres → Adhésions → Formules : CRUD complet, contrainte 1 active par sous-cat respectée
- ✅ Paramètres → HelloAsso → Mapping Tiers : import depuis API, création/édition mapping, suggestions auto
- ✅ AdherentList : badge formule + colonne validité, filtres a_jour/en_retard fonctionnels en mode exercice ET durée
- ✅ Onglet Adhésion fiche tiers : badge formule + dates, lien crayon vers édition transaction préservé
- ✅ Suite Pest verte (existants + nouveaux), 0 failed
- ✅ Pint clean
- ✅ Test manuel localhost : créer une formule, mapper un tier HelloAsso, déclencher un sync → adhésion avec formule appliquée correcte ; saisir une transaction cotisation directement → adhésion avec formule appliquée via lookup sous-cat ; offrir une adhésion gratuite via wizard → adhésion sans transaction
- ✅ Mémoire projet à jour (`project_fiche_tiers_360.md`, nouveau `project_formules_adhesion.md`)
