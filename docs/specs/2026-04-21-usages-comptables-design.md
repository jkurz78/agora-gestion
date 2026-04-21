# Slice Usages comptables — configuration par cas d'usage

**Date** : 2026-04-21
**Branche** : `feat/portail-tiers-slice1-auth-otp` (même branche que programme NDF)
**Statut** : spec validée, prête pour `/plan` + `/build`

## Contexte

La table `sous_categories` porte aujourd'hui 4 colonnes flag (`pour_dons`, `pour_cotisations`, `pour_inscriptions`, `pour_frais_kilometriques`) configurables depuis l'écran `/sous-categories`. Ce modèle ne scale pas : l'abandon de créance arrive pour le CERFA, et demain repas / hébergement / per diem suivront. L'écran sous-cat devient illisible et ajouter un flag coûte une colonne par usage.

**Objectif** : retourner le modèle mental. Configurer **par cas d'usage** ("je paramètre les frais km") au lieu de **par sous-catégorie** ("je flagge une sous-cat"). Préparer la prochaine étape du programme NDF — l'abandon de créance → reçu fiscal CERFA — qui exige de désigner une sous-cat Dons unique comme "sous-cat abandon de créance".

**Décision architecturale majeure** : passer d'un modèle à flags booléens sur `sous_categories` à une **table pivot `usages_sous_categories`** pilotée par un enum `UsageComptable`. Ajouter un nouvel usage devient une valeur d'enum — plus jamais une migration de schéma.

## Décisions prises

1. **Modèle de données** : table pivot `usages_sous_categories` + enum `UsageComptable`. Les 4 colonnes bool existantes sont migrées puis supprimées.
2. **Layout de l'écran** : route unique `Paramètres → Comptabilité → Usages`, écran avec cards Bootstrap empilées (une card par usage).
3. **Modale `/sous-categories`** : retrait total des checkboxes flag — source unique de vérité dans l'écran Usages.
4. **Création inline** : depuis chaque card, mini-modale avec catégorie parent filtrée selon la polarité de l'usage (Dépense pour frais km, Recette pour Dons / Cotisations / Inscriptions).
5. **Abandon de créance** : valeur d'enum `AbandonCreance` comme les autres ; contraintes métier ("∈ Dons", "une seule par asso") portées par `UsagesComptablesService`.
6. **Presets seed** : `625A Frais de déplacements` → usage `FraisKilometriques`. `771 Abandon de créance` → usages `Don` + `AbandonCreance` (reste sous catégorie `77 - Produits exceptionnels`, fidèle au plan comptable).

## Modèle de données

### Nouvelle table `usages_sous_categories`

| Colonne | Type | Contrainte |
|---|---|---|
| `id` | bigint unsigned | PK |
| `association_id` | bigint unsigned | FK `associations.id`, index, `ON DELETE CASCADE` |
| `sous_categorie_id` | bigint unsigned | FK `sous_categories.id`, index, `ON DELETE CASCADE` |
| `usage` | string | valeurs de l'enum `UsageComptable` |
| `created_at` / `updated_at` | timestamps | |
| Index unique | `(association_id, sous_categorie_id, usage)` | |

Pas de `SoftDeletes` — configuration courante, pas de trace historique nécessaire.

### Enum `App\Enums\UsageComptable`

```php
enum UsageComptable: string
{
    case Don = 'don';
    case Cotisation = 'cotisation';
    case Inscription = 'inscription';
    case FraisKilometriques = 'frais_kilometriques';
    case AbandonCreance = 'abandon_creance';

    public function label(): string;          // libellé fr pluriel
    public function polarite(): TypeCategorie; // Depense ou Recette
    public function cardinalite(): string;     // 'mono' ou 'multi'
}
```

Polarité par usage :
- `FraisKilometriques` → `Depense`
- `Don`, `Cotisation`, `Inscription` → `Recette`
- `AbandonCreance` → `Recette` (hérite de Don)

Cardinalité par usage :
- `FraisKilometriques`, `AbandonCreance` → `mono`
- `Don`, `Cotisation`, `Inscription` → `multi`

### Migrations (3 étapes séparées)

**Migration 1** — `create_usages_sous_categories_table` : crée la table.

**Migration 2** — `migrate_sous_categorie_flags_to_usages` : pour chaque ligne de `sous_categories`, lit les 4 bools et insère les lignes pivot correspondantes dans `usages_sous_categories`. Idempotente (utilise `upsert` ou vérifie l'absence avant insert). Rollback : vide la table.

**Migration 3** — `drop_flag_columns_from_sous_categories` : supprime `pour_dons`, `pour_cotisations`, `pour_inscriptions`, `pour_frais_kilometriques`. Rollback : recrée les colonnes et rejoue les flags depuis la table pivot.

### Helpers d'accès

Sur `App\Models\SousCategorie` :
- `usages(): HasMany` — relation vers `UsageSousCategorie`
- `hasUsage(UsageComptable $usage): bool`
- scope `forUsage(UsageComptable $usage)` — remplace tous les `where('pour_xxx', true)`

Sur `App\Models\Association` :
- `sousCategoriesFor(UsageComptable $usage): Collection` — toutes les sous-cat de l'asso ayant cet usage

Modèle pivot `App\Models\UsageSousCategorie` étend `TenantModel` (scope fail-closed sur `association_id`).

## Service métier

`App\Services\UsagesComptablesService` (dans `DB::transaction()`, tenant-scoped) :

- `setFraisKilometriques(?int $sousCategorieId): void` — mono ; supprime les liens `FraisKilometriques` existants de l'asso, crée le nouveau si id fourni.
- `toggleDon(int $sousCategorieId, bool $active): void`
- `toggleCotisation(int $sousCategorieId, bool $active): void`
- `toggleInscription(int $sousCategorieId, bool $active): void`
- `setAbandonCreance(?int $sousCategorieId): void` — mono ; vérifie que la sous-cat cible a déjà l'usage `Don` sinon `DomainException("La sous-catégorie doit être un Don")` ; supprime les liens `AbandonCreance` existants puis insère si id fourni.
- `createAndFlag(array $attrs, UsageComptable $usage): SousCategorie` — crée la sous-cat + pose le lien pivot correspondant en une transaction. Si usage = `AbandonCreance`, pose aussi `Don` automatiquement.

**Cascade logique** : `toggleDon($id, false)` sur une sous-cat qui a aussi `AbandonCreance` → les deux liens sont retirés (le service le fait, pas la DB).

## Consumers à refactorer

Refactor mécanique avant le drop des colonnes (migration 3). Chaque occurrence `->pour_dons` devient `->hasUsage(UsageComptable::Don)`, chaque `where('pour_dons', true)` devient `->forUsage(UsageComptable::Don)`.

Fichiers concernés (liste exhaustive) :

**Services** :
- `app/Services/NoteDeFrais/LigneTypes/KilometriqueLigneType.php`
- `app/Services/TransactionUniverselleService.php`
- `app/Services/TransactionService.php`
- `app/Services/TiersQuickViewService.php`
- `app/Services/Onboarding/DefaultChartOfAccountsService.php` (refactor seed — voir section Presets)

**Livewire** :
- `app/Livewire/SousCategorieList.php` + vue
- `app/Livewire/SousCategorieAutocomplete.php`
- `app/Livewire/TransactionForm.php`
- `app/Livewire/TransactionUniverselle.php`
- `app/Livewire/Dashboard.php`
- `app/Livewire/GestionDashboard.php`
- `app/Livewire/TypeOperationShow.php`
- `app/Livewire/OperationDetail.php`
- `app/Livewire/AdherentList.php`
- `app/Livewire/ParticipantTable.php`
- `app/Livewire/CommunicationTiers.php`
- `app/Livewire/Parametres/HelloassoSyncConfig.php`

**Modèle** :
- `app/Models/SousCategorie.php` — ajout helpers, retrait des colonnes fillable/casts

Stratégie : refactorer d'un bloc par dossier, `./vendor/bin/sail test` vert à chaque étape. Ne pas toucher la migration 3 avant que tous les consumers soient migrés.

## Écran `Paramètres → Comptabilité → Usages`

**Route** : `parametres.comptabilite.usages` → `/parametres/comptabilite/usages`

**Middleware** : groupe Paramètres existant (admin uniquement, super-admin en mode support = read-only).

**Composant Livewire** : `App\Livewire\Parametres\Comptabilite\UsagesComptables`

**Vue** : `resources/views/livewire/parametres/comptabilite/usages-comptables.blade.php`

**Layout** : 4 cards Bootstrap empilées, dans l'ordre :

1. **Indemnités kilométriques** — widget mono-select, option vide "— Aucune —".
2. **Cotisations** — widget multi-checkbox.
3. **Inscriptions** — widget multi-checkbox.
4. **Dons** — widget multi-checkbox + sub-bloc "Abandon de créance" (radio parmi les sous-cat Dons cochées + option "— Aucune —").

Chaque card a :
- Header dark Bootstrap (`#3d5473`) avec titre + courte phrase explicative
- Widget principal
- Bouton secondaire "+ Créer une sous-catégorie" (désactivé avec tooltip si aucune catégorie compatible existe)
- Lien discret en pied de card : "Voir l'écran des sous-catégories →"

**Sub-bloc abandon de créance** dans la card Dons :
- Visible uniquement si ≥ 1 sous-cat Dons cochée
- Radio list : une entrée par sous-cat Dons cochée + option "— Aucune —"
- Tooltip explicatif : "La sous-catégorie désignée sera utilisée pour les reçus fiscaux CERFA d'abandon de créance."

**Comportement des cascades** :
- Décocher une sous-cat Dons qui est actuellement l'abandon désigné → le radio bascule automatiquement sur "— Aucune —".
- Changer la sélection mono-select km → l'ancienne sous-cat perd son lien, la nouvelle le gagne, en une seule transaction.

**Fallbacks** :
- 0 sous-cat pour un usage → message "Aucune sous-catégorie paramétrée pour cet usage" + CTA "+ Créer une sous-catégorie".
- Plusieurs sous-cat `FraisKilometriques` (résidu de migration non déduplicé) → warning "Plusieurs sous-catégories détectées, choisis-en une" + select vide.

## Création inline d'une sous-catégorie

Modale Bootstrap montée localement dans `UsagesComptables` (pas de sous-composant Livewire séparé).

**Champs** :
- **Catégorie** (`<select>`) : filtré par polarité de l'usage déclencheur
  - `FraisKilometriques` → catégories de type `Depense`
  - `Don` / `Cotisation` / `Inscription` → catégories de type `Recette`
  - Depuis le sub-mono `AbandonCreance` : hérite de `Don` (Recette)
- **Nom** (input texte, required, max 255, unique sur `(association_id, categorie_id)`)
- **Code CERFA** (input texte, nullable, max 20)

**Aucune checkbox flag** — le lien pivot est créé automatiquement selon l'usage déclencheur.

**À la soumission** : `UsagesComptablesService::createAndFlag($attrs, $usage)` dans `DB::transaction()`. La sous-cat apparaît immédiatement dans le widget parent (cochée / sélectionnée).

**Catégorie vide** : si l'asso n'a aucune catégorie de polarité compatible, bouton "+ Créer" désactivé avec tooltip "Créer d'abord une catégorie de type Dépense/Recette dans Paramètres → Catégories".

## Impact sur `/sous-categories`

**Table (liste)** : 8 colonnes → 4 colonnes : **Catégorie** / **Nom** / **Code CERFA** / **Actions**. Retrait des 4 colonnes flag.

**Modale** : retrait total des 4 checkboxes. Champs restants : **Catégorie** / **Nom** / **Code CERFA** / **Actif**.

**Note statique en bas de modale** (non conditionnelle) :
> Les usages comptables (Dons, Cotisations, Inscriptions, Frais km, Abandon de créance) se configurent dans [Paramètres → Comptabilité → Usages](...).

**Suppression** : inchangée. Les liens pivot ne sont pas soft-deleted (table sans SoftDeletes). Un hard delete d'une sous-cat cascade la suppression des liens via la FK `ON DELETE CASCADE` ; en cas de soft delete, les liens restent en base mais deviennent invisibles côté requêtes car le scope `forUsage()` joint sur `sous_categories` qui filtre les soft-deleted.

## Presets seed `DefaultChartOfAccountsService`

Après création des catégories et sous-catégories, insertions dans `usages_sous_categories` :

| Sous-cat | Usages posés |
|---|---|
| `706A Formations` | `Inscription` |
| `706B Parcours thérapeutiques` | `Inscription` |
| `751 Cotisations` | `Cotisation` |
| `754 Dons manuels` | `Don` |
| `625A Frais de déplacements` | `FraisKilometriques` |
| `771 Abandon de créance` | `Don` + `AbandonCreance` |

Les paramètres `pour_*` sont retirés des tableaux du seeder (plus de colonne en DB).

## Navigation & permissions

**Sidebar** : sous **Paramètres** → nouvelle rubrique **Comptabilité**, première entrée **Usages**. Icône `bi-sliders`.

**Breadcrumb** : `Paramètres > Comptabilité > Usages`.

**Permissions** : admin uniquement (middleware Paramètres existant). Super-admin en mode support = read-only (pattern en place).

## Testing plan

### Tests unitaires

**Enum `UsageComptable`** :
- Toutes les valeurs ont un `label()` fr
- `polarite()` retourne la bonne `TypeCategorie`
- `cardinalite()` correct

**Helpers modèle** :
- `SousCategorie::hasUsage()` vrai/faux selon la présence du lien
- `SousCategorie::forUsage()` scope filtre correctement
- `Association::sousCategoriesFor()` retourne les bonnes sous-cat

**Service `UsagesComptablesService`** :
- `setFraisKilometriques($id)` pose le lien, retire l'ancien
- `setFraisKilometriques(null)` efface
- `toggleDon/Cotisation/Inscription` idempotent (rejouer ne double pas)
- `setAbandonCreance($id)` sur sous-cat sans `Don` → exception
- `setAbandonCreance($id)` sur sous-cat avec `Don` → lien posé
- `toggleDon($id, false)` sur sous-cat qui a `AbandonCreance` → les deux liens tombent (cascade)
- `createAndFlag()` crée la sous-cat + pose le lien + tenant-scoped
- `createAndFlag(AbandonCreance)` pose aussi `Don` automatiquement
- Isolation tenant : aucune méthode ne touche les liens d'une autre asso

### Tests d'intégration migration

- Migration 1 : table créée avec bons index
- Migration 2 : pour une asso avec N flags, crée exactement N liens ; idempotence (rejeu = 0 doublon)
- Migration 3 : colonnes retirées, rollback les recrée et rejoue les liens

### Tests Livewire `UsagesComptables`

- Rendu : 4 cards visibles, états cochés reflètent la DB
- Toggle multi-checkbox persiste en DB via service
- Mono-select km : changement retire l'ancien lien et pose le nouveau
- Sub-mono abandon : radio list ne contient que les sous-cat Dons cochées
- Décocher une sous-cat Don qui était abandon → radio à "— Aucune —"
- Création inline Cotisations → sous-cat créée + lien `Cotisation` posé
- Création inline Frais km → select catégorie filtré sur `Depense`
- Création inline depuis sub-mono abandon → lien `Don` + `AbandonCreance`
- Policy : utilisateur non admin → 403
- Isolation tenant : asso A ne voit pas les sous-cat de l'asso B

### Tests seed

- Après `DefaultChartOfAccountsService::applyTo($asso)` :
  - `625A` a un lien `FraisKilometriques`
  - `771` a deux liens : `Don` + `AbandonCreance`
  - `706A`, `706B` ont `Inscription`
  - `751` a `Cotisation`
  - `754` a `Don`
  - Aucune autre sous-cat n'a de lien pivot

### Non-régression

- Suite complète `./vendor/bin/sail test` verte à **chaque étape** du refactor des consumers (avant migration 3)
- `KilometriqueLigneType::resolveSousCategorie` : continue de résoudre la bonne sous-cat via la table pivot
- `TransactionUniverselleService` : flux création dons/cotisations inchangé côté utilisateur
- `SousCategorieList` : modale sans checkboxes, table à 4 colonnes

## Ordre d'exécution recommandé (TDD)

1. Migration 1 (création table) + tests migration
2. Enum `UsageComptable` + tests unitaires
3. Modèle pivot `UsageSousCategorie` (extends `TenantModel`) + tests isolation
4. Helpers sur `SousCategorie` et `Association` + tests
5. Migration 2 (migration de données) + tests idempotence
6. Refactor consumers "lecteurs de flags" un par un, suite verte à chaque commit (inclut la logique `SousCategorieList` côté backend : lire `hasUsage` au lieu de `pour_dons`)
7. Migration 3 (drop colonnes) + tests rollback
8. `UsagesComptablesService` + tests unitaires
9. Composant Livewire `UsagesComptables` + vue + tests feature
10. Refactor UI `SousCategorieList` : retrait des 4 colonnes flag dans la table + retrait des 4 checkboxes dans la modale + ajout de la note de renvoi vers l'écran Usages
11. Refactor `DefaultChartOfAccountsService` (presets)
12. Route + sidebar + permissions
13. Tests de bout en bout + `./vendor/bin/sail pint`

## Hors scope

- **Usages futurs** (repas, hébergement, per diem) : ajoutables par simple valeur d'enum, pas de migration.
- **Génération CERFA abandon de créance** : consumer futur de `AbandonCreance`, hors slice.
- **Métadonnées par lien pivot** (plafond, taux, ordre) : la table est prête à accueillir des colonnes supplémentaires quand le besoin émergera.
- **Migration des données en prod multi-asso** : une seule asso en prod au moment du déploiement (pas de complexité cross-tenant à gérer).
- **Rattachement user↔tiers** (dette NDF héritée) : hors ce slice.
