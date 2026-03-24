# Gestion des exercices comptables

**Date:** 2026-03-24
**Statut:** Draft

## Contexte

L'application gère actuellement les exercices comptables (sept → août) de manière purement calculée dans `ExerciceService`, sans persistance en base. L'utilisateur ne peut ni clôturer formellement un exercice, ni empêcher les modifications sur un exercice passé. Ce document spécifie l'ajout d'une gestion formelle des exercices avec clôture, réouverture, verrouillage et traçabilité.

## Objectifs

1. Permettre la **clôture formelle** d'un exercice après vérification de contrôles pré-clôture
2. **Verrouiller les données** d'un exercice clôturé (lecture seule sur transactions et virements)
3. Permettre la **réouverture** exceptionnelle avec motif obligatoire
4. Permettre la **création manuelle** d'un exercice futur avant clôture du précédent
5. Offrir une **piste d'audit** complète des actions sur les exercices
6. **Changer d'exercice affiché** via un écran dédié (le sélecteur navbar disparaît)

## Décisions de design

| Sujet | Décision |
|-------|----------|
| Permissions | Tous les utilisateurs authentifiés (pas de restriction admin) |
| Contrôles bloquants | Rapprochements en cours, lignes sans catégorie, virements déséquilibrés |
| Contrôles avertissement | Transactions non pointées, budget absent |
| Contrôles informatifs | Soldes des comptes à date de fin |
| Mode lecture seule | Flag `readonly` sur formulaires Livewire existants (champs désactivés, bouton sauvegarder masqué) |
| UX clôture | Wizard accordéon 3 étapes (pattern HelloAsso) |
| Sélecteur navbar | Badge informatif non cliquable, icône cadenas si clôturé |
| Changement d'exercice | Page dédiée avec liste + modale de confirmation |
| Terminologie | "Exercice affiché" (session), statuts "Ouvert"/"Clôturé" |
| Création exercice suivant | Manuelle (pas de création automatique à la clôture) |
| Migration initiale | Scan des transactions existantes → crée tous les exercices qui ont des données |
| Protection des données | Double verrou : services (bloquant) + trait Livewire (affichage) |

## Modèle de données

### Table `exercices`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint, PK | Auto-increment |
| `annee` | smallint, unique | Année de début (ex: 2025 = sept 2025 → août 2026) |
| `statut` | enum: `ouvert`, `cloture` | Statut de l'exercice |
| `date_cloture` | datetime, nullable | Date/heure de dernière clôture |
| `cloture_par_id` | FK → users, nullable | Utilisateur ayant clôturé |
| `created_at` / `updated_at` | timestamps | |

### Table `exercice_actions`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | bigint, PK | Auto-increment |
| `exercice_id` | FK → exercices | Exercice concerné |
| `action` | enum: `creation`, `cloture`, `reouverture` | Type d'action |
| `user_id` | FK → users | Utilisateur ayant effectué l'action |
| `commentaire` | text, nullable | Motif (obligatoire pour réouverture) |
| `created_at` | timestamp | Date/heure de l'action |

### Enum `StatutExercice`

```php
enum StatutExercice: string
{
    case Ouvert = 'ouvert';
    case Cloture = 'cloture';
}
```

### Enum `TypeActionExercice`

```php
enum TypeActionExercice: string
{
    case Creation = 'creation';
    case Cloture = 'cloture';
    case Reouverture = 'reouverture';
}
```

### Modèle `Exercice`

- `annee`, `statut`, `date_cloture`, `cloture_par_id` fillable
- Cast `statut` → `StatutExercice`, `date_cloture` → `datetime`
- `belongsTo(User::class, 'cloture_par_id')` — dernier utilisateur ayant clôturé
- `hasMany(ExerciceAction::class)` — historique des actions
- `scopeOuvert($query)` — filtre statut ouvert
- `scopeCloture($query)` — filtre statut clôturé
- `isCloture(): bool` — raccourci
- `label(): string` — retourne "2025-2026"
- `dateDebut(): Carbon` — 1er septembre de l'année
- `dateFin(): Carbon` — 31 août de l'année suivante

### Modèle `ExerciceAction`

- `exercice_id`, `action`, `user_id`, `commentaire` fillable
- Cast `action` → `TypeActionExercice`
- `belongsTo(Exercice::class)`
- `belongsTo(User::class)`
- `const UPDATED_AT = null` — table append-only, pas de colonne `updated_at`
- Migration utilise `$table->timestamp('created_at')` au lieu de `$table->timestamps()`

## Navigation

### Menu Exercices (entre Rapports et Paramètres)

```
Exercices ▾
├── Clôturer / Réouvrir
├── Changer d'exercice
├── ─────────────────── (séparateur)
└── Piste d'audit
```

Le premier item est **contextuel** :
- Si l'exercice affiché est **ouvert** → "Clôturer l'exercice"
- Si l'exercice affiché est **clôturé** → "Réouvrir l'exercice" (affiché en rouge/warning)

### Badge navbar

Le dropdown sélecteur d'exercice actuel est remplacé par un **badge informatif non cliquable** :
- Exercice ouvert : `[bi-calendar3] Exercice 2025-2026`
- Exercice clôturé : `[bi-lock] Exercice 2024-2025`

Utilise les Bootstrap Icons existants (`bi-calendar3`, `bi-lock`) — pas d'emojis.

La route `POST /exercice/changer` et les variables `$exercicesDispos` sont supprimées.

## Écrans

### Écran 1 : Clôturer l'exercice (wizard accordéon)

Pattern identique au wizard HelloAsso (`helloasso-sync-wizard`). Cards accordéon avec badge numéroté, `border-primary` sur l'étape active, `bg-success` sur les étapes complétées, navigation avant/arrière.

**Étape 1 — Contrôles pré-clôture**

Contrôles bloquants (empêchent de passer à l'étape 2) :
1. **Rapprochements en cours** — des rapprochements en statut `EnCours` existent pour des comptes bancaires avec `date_fin` comprise dans la période de l'exercice (entre le 1er sept et le 31 août). Affichage : vert si aucun, rouge avec compte sinon.
2. **Lignes sans sous-catégorie** — des `TransactionLigne` de l'exercice n'ont pas de `sous_categorie_id`. Affichage : vert si aucune, rouge avec compte + lien "Voir le détail".
3. **Virements déséquilibrés** — vérification de la cohérence des virements internes. Affichage : vert si OK, rouge sinon.

Contrôles d'avertissement (signalés mais non bloquants) :
4. **Transactions non pointées** — transactions de l'exercice sans `rapprochement_id`. Affichage : orange avec compte + lien "Voir la liste", vert si toutes pointées.
5. **Budget absent** — aucune `BudgetLine` pour l'exercice. Affichage : orange si absent, vert si présent avec nombre de lignes.

Contrôle informatif :
6. **Soldes des comptes** — soldes calculés à la date de fin d'exercice, affichés pour référence.

Bouton "Suite" grisé tant que des contrôles bloquants sont en échec. Message "Corrigez les contrôles bloquants pour continuer".

**Étape 2 — Récapitulatif de l'exercice**

- Tableau des soldes des comptes bancaires au 31/08/YYYY
- Chiffres clés : total recettes, total dépenses, résultat (recettes - dépenses)
- Boutons Retour / Suite

**Étape 3 — Confirmation de clôture**

- Encadré d'avertissement jaune récapitulant les conséquences :
  - L'exercice sera marqué comme clôturé
  - Aucune modification possible sur les transactions et virements
  - Possibilité de réouvrir si nécessaire
- Bouton rouge "Clôturer l'exercice 2025-2026"
- Après clôture : message de succès, redirection vers la page "Changer d'exercice"

### Écran 2 : Changer d'exercice

- Bandeau informatif en haut : exercice actuellement affiché
- Tableau des exercices : exercice (label), période, statut (badge vert/gris), date de clôture, clôturé par, action
- L'exercice affiché montre "Affiché" au lieu du bouton
- Les autres exercices ont un bouton "Afficher"
- Bouton "Créer un exercice" en bas à droite (ouvre une modale simple avec choix de l'année)
- **Modale de confirmation** au clic sur "Afficher" :
  - Si exercice clôturé : avertissement "données en lecture seule"
  - Boutons Annuler / Confirmer
  - La confirmation met à jour la session et recharge la page

### Écran 3 : Réouvrir l'exercice

Accessible uniquement quand l'exercice affiché est clôturé (via menu Exercices).

- Encadré rouge d'avertissement avec :
  - Date et auteur de la dernière clôture
  - Conséquences : modifications à nouveau possibles, documents de clôture potentiellement invalides
  - Action enregistrée dans la piste d'audit
- Champ motif de réouverture **obligatoire** (textarea)
- Historique de cet exercice (liste chronologique des actions : création, clôtures, réouvertures)
- Bouton rouge "Réouvrir l'exercice"
- Après réouverture : message de succès, la page se recharge avec le nouveau statut

### Écran 4 : Piste d'audit

Tableau chronologique de toutes les actions sur tous les exercices :
- Colonnes : Date, Exercice, Action (badge coloré), Utilisateur, Commentaire
- Actions : Création (badge vert), Clôture (badge rouge), Réouverture (badge jaune)
- Trié par date décroissante

## Architecture technique

### Nouveaux fichiers

| Type | Fichier | Rôle |
|------|---------|------|
| Migration | `create_exercices_table` | Table `exercices` + seed données existantes |
| Migration | `create_exercice_actions_table` | Table `exercice_actions` |
| Modèle | `app/Models/Exercice.php` | Eloquent + scopes |
| Modèle | `app/Models/ExerciceAction.php` | Audit trail |
| Enum | `app/Enums/StatutExercice.php` | Ouvert / Cloture |
| Enum | `app/Enums/TypeActionExercice.php` | Creation / Cloture / Reouverture |
| Service | `app/Services/ClotureCheckService.php` | Les 6 contrôles pré-clôture |
| Trait | `app/Livewire/Concerns/RespectsExerciceCloture.php` | Injecte `$exerciceCloture` |
| Exception | `app/Exceptions/ExerciceCloturedException.php` | Levée par `assertOuvert()` |
| Livewire | `app/Livewire/Exercices/ClotureWizard.php` | Wizard 3 étapes |
| Livewire | `app/Livewire/Exercices/ChangerExercice.php` | Liste + création |
| Livewire | `app/Livewire/Exercices/ReouvrirExercice.php` | Réouverture + motif |
| Livewire | `app/Livewire/Exercices/PisteAudit.php` | Journal des actions |
| Vue | `resources/views/livewire/exercices/cloture-wizard.blade.php` | |
| Vue | `resources/views/livewire/exercices/changer-exercice.blade.php` | |
| Vue | `resources/views/livewire/exercices/reouvrir-exercice.blade.php` | |
| Vue | `resources/views/livewire/exercices/piste-audit.blade.php` | |

### Service `ExerciceService` (enrichi)

Le service existant est enrichi, pas remplacé. La méthode `current(): int` est **conservée** pour compatibilité avec les 15+ appels existants dans le codebase.

```php
// Existant — conservé tel quel (compatibilité)
current(): int                  // inchangé, retourne l'année de l'exercice en session
dateRange(int $annee): array    // inchangé
label(int $annee): string       // inchangé
defaultDate(): string           // inchangé
available(): array              // SUPPRIMÉ (remplacé par Exercice::orderByDesc('annee')->get()) — tests existants à supprimer

// Nouveau
exerciceAffiche(): ?Exercice    // appelle current() pour l'année, puis Exercice::where('annee', $year)->first()
anneeForDate(Carbon $date): int // calcule l'exercice d'une date (mois >= 9 → année, sinon année - 1)
assertOuvert(int $annee): void  // lève ExerciceCloturedException si clôturé
cloturer(Exercice $exercice, User $user): void
reouvrir(Exercice $exercice, User $user, string $commentaire): void
creerExercice(int $annee, User $user): Exercice
changerExerciceAffiche(Exercice $exercice): void  // stocke $exercice->annee dans session('exercice_actif')
```

La méthode `anneeForDate()` est essentielle pour le verrou service : elle détermine à quel exercice appartient une transaction d'après sa date (et non d'après l'exercice en session). Exemple : une transaction du 15 janvier 2026 → exercice 2025.

### Service `ClotureCheckService`

```php
final class ClotureCheckService
{
    public function executer(int $annee): ClotureCheckResult;
}

// DTO résultat
final class ClotureCheckResult
{
    /** @param CheckItem[] $bloquants */
    /** @param CheckItem[] $avertissements */
    /** @param array<string, float> $soldesComptes */
    public function __construct(
        public readonly array $bloquants,
        public readonly array $avertissements,
        public readonly array $soldesComptes,
    ) {}

    public function peutCloturer(): bool
    {
        return collect($this->bloquants)->every(fn (CheckItem $c) => $c->ok);
    }
}

// DTO par contrôle
final class CheckItem
{
    public function __construct(
        public readonly string $nom,
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?array $details = null, // ex: liste de transactions
    ) {}
}
```

### Double verrou — Protection des données

**Verrou 1 — Couche services (garde-fou ultime)**

Les services suivants appellent `$this->exerciceService->assertOuvert($annee)` au début de chaque méthode de mutation. L'année est déterminée via `anneeForDate()` à partir de la date de la transaction/virement, **pas** de l'exercice en session :

- `TransactionService::create()`, `update()`, `delete()`
- `TransactionService::affecterLigne()`, `supprimerAffectations()`
- `VirementInterneService::create()`, `update()`, `delete()`
- `RapprochementBancaireService::create()`, `createVerrouilleAuto()`, `verrouiller()`, `deverrouiller()`, `supprimer()`
- `RapprochementBancaireService::toggleTransaction()` (pointage/dépointage)

**Note :** `TransactionUniverselleService` est en lecture seule (méthode `paginate()` uniquement) — pas besoin de verrou.

**Cas particulier : `BudgetTable`** — Ce composant Livewire appelle directement `BudgetLine::create()`, `update()`, `delete()` sans passer par un service. Le trait `RespectsExerciceCloture` bloquera l'UI, et on ajoutera un appel inline `$this->exerciceService->assertOuvert($this->exercice)` dans les méthodes de mutation du composant.

**Cas particulier : transactions cross-exercice** — Si un utilisateur modifie la date d'une transaction pour qu'elle tombe dans un exercice clôturé, `assertOuvert()` bloquera car il vérifie l'exercice de la date cible.

Si l'exercice est clôturé, `ExerciceCloturedException` est levée et aucune donnée n'est modifiée.

**Verrou 2 — Trait Livewire (confort UX)**

```php
trait RespectsExerciceCloture
{
    public bool $exerciceCloture = false;

    public function bootRespectsExerciceCloture(): void
    {
        $exercice = app(ExerciceService::class)->exerciceAffiche();
        $this->exerciceCloture = $exercice?->isCloture() ?? false;
    }
}
```

Composants Livewire utilisant le trait :
- `TransactionList`, `TransactionCompteList`, `TransactionForm`, `TransactionUniverselle`
- `VirementInterneList`, `VirementInterneForm`
- `RapprochementList`, `RapprochementDetail`
- `BudgetTable`
- `ImportCsv`
- `HelloassoSyncWizard` (la sync crée des transactions, doit être bloquée sur exercice clôturé)
- `Dashboard` (afficher un bandeau informatif si l'exercice affiché est clôturé)

Dans les vues Blade, le flag `$exerciceCloture` :
- Masque les boutons "Créer", "Supprimer"
- Remplace "Modifier" par "Visualiser"
- Ajoute `disabled` sur tous les champs de formulaire
- Masque le bouton "Sauvegarder"

Si un composant oublie le trait : l'UI ne sera pas en lecture seule, mais le service bloquera la sauvegarde → bug d'affichage, pas bug de données.

### Migration initiale des données

La migration `create_exercices_table` inclut la seed des données existantes :

```php
// 1. Récupérer les années distinctes depuis Transaction::selectRaw('YEAR(date) as y')->distinct()
// 2. Calculer les exercices correspondants (une date en janvier 2025 → exercice 2024)
// 3. Créer un Exercice par année unique, statut "Ouvert"
// 4. Créer une ExerciceAction "Creation" pour chacun (user_id = premier admin ou user id 1)
// 5. Mettre en session l'exercice courant calculé
```

**Fresh install (aucune transaction)** : si la migration ne trouve aucune transaction, elle crée uniquement l'exercice courant (calculé par `ExerciceService`). La méthode `exerciceAffiche()` retourne `null` si aucun exercice n'existe en base — le trait gère ce cas avec `?->isCloture() ?? false`.

### Routes

```php
// Nouvelles routes (middleware auth)
Route::get('/exercices/cloture', ClotureWizard::class)->name('exercices.cloture');
Route::get('/exercices/changer', ChangerExercice::class)->name('exercices.changer');
Route::get('/exercices/reouvrir', ReouvrirExercice::class)->name('exercices.reouvrir');
Route::get('/exercices/audit', PisteAudit::class)->name('exercices.audit');

// Supprimé
// Route POST /exercice/changer (ancien sélecteur)
```

## Composants Livewire impactés (mode lecture seule)

Liste des composants nécessitant le trait `RespectsExerciceCloture` et les adaptations dans leurs vues Blade :

| Composant | Adaptations vue |
|-----------|----------------|
| `TransactionList` | Masquer bouton "Nouvelle transaction", transformer "Modifier" en "Visualiser" |
| `TransactionCompteList` | Idem |
| `TransactionForm` | Champs `disabled`, masquer "Sauvegarder" et "Supprimer" |
| `TransactionUniverselle` | Champs `disabled`, masquer "Sauvegarder" et "Supprimer" |
| `VirementInterneList` | Masquer "Nouveau virement", transformer "Modifier" en "Visualiser" |
| `VirementInterneForm` | Champs `disabled`, masquer "Sauvegarder" et "Supprimer" |
| `RapprochementList` | Masquer "Nouveau rapprochement" |
| `RapprochementDetail` | Masquer actions de pointage/dépointage, masquer verrouillage |
| `BudgetTable` | Champs `disabled`, masquer "Sauvegarder" |
| `ImportCsv` | Masquer le formulaire d'import, message "Exercice clôturé" |
| `HelloassoSyncWizard` | Afficher un message "Exercice clôturé" au lieu du wizard |
| `Dashboard` | Bandeau informatif "Vous consultez un exercice clôturé (lecture seule)" |

## Tests

### Tests unitaires
- `ExerciceService` : `assertOuvert()` passe/échoue selon statut
- `ClotureCheckService` : chaque contrôle isolément (bloquant, avertissement, OK)
- Modèle `Exercice` : scopes, `isCloture()`, `label()`, dates

### Tests d'intégration
- Clôture complète : contrôles OK → clôture → statut changé + action créée
- Clôture refusée : contrôle bloquant → impossible de clôturer
- Réouverture : exercice clôturé → réouverture avec motif → statut changé + action créée
- Verrouillage service : tentative de `TransactionService::create()` sur exercice clôturé → exception
- Cross-exercice : modification de date vers un exercice clôturé → exception
- Création d'exercice : création manuelle → exercice ouvert + action créée
- Changement d'exercice : session mise à jour correctement

### Tests Livewire
- `ClotureWizard` : navigation étapes, bouton grisé si bloquants, clôture réussie
- `ChangerExercice` : liste, création, changement avec confirmation
- `ReouvrirExercice` : motif obligatoire, réouverture réussie
- `PisteAudit` : affichage correct des actions
- Composants existants : vérifier que `$exerciceCloture` désactive correctement les boutons
