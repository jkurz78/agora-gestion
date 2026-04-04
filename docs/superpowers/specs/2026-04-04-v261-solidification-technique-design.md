# v2.6.1 — Solidification technique

## Contexte

Audit complet du codebase identifiant des bugs, vulnérabilités XSS, problèmes de performance N+1, et l'absence de système de rôles/autorisations.

Deux lots, une seule release v2.6.1.

---

## Lot 1 — Corrections & Sécurité

### 1.1 Fix NPE `FormulaireToken::isExpire()`

**Fichier** : `app/Models/FormulaireToken.php:37-39`

`isExpire()` appelle `$this->expire_at->lt(today())` sans null check. Si `expire_at` est null, fatal error.

**Correction** :
```php
public function isExpire(): bool
{
    return $this->expire_at !== null && $this->expire_at->lt(today());
}
```

### 1.2 Fix XSS notes médicales

**Problème** : Les notes médicales (`ParticipantDonneesMedicales.notes`) sont saisies via `contenteditable` + `innerHTML` et rendues avec `{!! !!}` sans sanitisation.

**Fichiers affectés** :
- `app/Livewire/ParticipantTable.php:250` — save sans sanitisation
- `resources/views/livewire/participant-table.blade.php:315` — preview bulle `{!! Str::limit($hasNotes, 300) !!}`
- `resources/views/livewire/participant-table.blade.php:443` — éditeur `{!! $medNotes !!}`
- `resources/views/pdf/participant-fiche.blade.php:322` — `{!! $med->notes !!}`
- `resources/views/pdf/participants-annuaire.blade.php:236` — `{!! $med->notes !!}`

**Correction** :
1. Ajouter une méthode `sanitizeNotes()` sur `ParticipantDonneesMedicales` (même pattern que `EmailTemplate::sanitizeCorps()`) :
   ```php
   public static function sanitizeNotes(string $html): string
   {
       return strip_tags($html, '<p><br><strong><em><b><i><u><ul><ol><li>');
   }
   ```
2. Appliquer dans `ParticipantTable::saveNotes()` au moment du save
3. Les vues restent avec `{!! !!}` (nécessaire pour le rich text) mais le contenu est désormais nettoyé en entrée

### 1.3 Fix N+1 `RemiseBancaireService`

**Fichiers** : `app/Services/RemiseBancaireService.php`

Deux emplacements avec `.each()` imbriqués :

**a) `supprimer()` (ligne ~256)** — boucle transaction → lignes → affectations

Remplacer par :
```php
$txIds = Transaction::where('remise_id', $remise->id)->pluck('id');
$ligneIds = TransactionLigne::whereIn('transaction_id', $txIds)->pluck('id');
TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->delete();
TransactionLigne::whereIn('id', $ligneIds)->delete();
Transaction::whereIn('id', $txIds)->delete(); // soft delete
```

**b) `updateContenu()` (ligne ~158)** — même pattern pour la suppression des transactions retirées

### 1.4 Migration indexes

**Nouvelle migration** : `2026_04_04_100001_add_performance_indexes.php`

```php
Schema::table('transaction_lignes', function (Blueprint $table) {
    $table->index(['transaction_id', 'sous_categorie_id']);
    $table->index('operation_id');
});

Schema::table('transaction_ligne_affectations', function (Blueprint $table) {
    $table->index('transaction_ligne_id');
});
```

### 1.5 Middleware admin sur routes `parametres.*`

**Nouveau middleware** : `app/Http/Middleware/RequireAdmin.php`

Vérifie `auth()->user()->role === Role::Admin`. Retourne 403 sinon.

Appliqué au groupe de routes `parametres` dans `routes/web.php`.

> Note : ce middleware sera remplacé par les Policies du lot 2, mais il sécurise immédiatement.

---

## Lot 2 — Rôles & Fondations

### 2.1 Enum `Role`

**Nouveau fichier** : `app/Enums/Role.php`

```php
enum Role: string
{
    case Admin = 'admin';
    case Comptable = 'comptable';
    case Gestionnaire = 'gestionnaire';
    case Consultation = 'consultation';
}
```

Méthodes : `label()`, `color()`, `espaces(): array<Espace>` (espaces en écriture), `canWrite(Espace $espace): bool`, `canRead(Espace $espace): bool`.

Matrice d'accès :

| Rôle | Comptabilité | Gestion | Paramètres |
|------|:---:|:---:|:---:|
| Admin | R+W | R+W | R+W |
| Comptable | R+W | R | non |
| Gestionnaire | R | R+W | non |
| Consultation | R | R | non |

`peut_voir_donnees_sensibles` reste un flag indépendant sur User, activable sur tout rôle.

### 2.2 Migration User

**Nouvelle migration** : `2026_04_04_100002_add_role_to_users.php`

- Ajouter colonne `role` (string, default `'admin'`) sur `users`
- Cast dans User model : `'role' => Role::class`
- Ajouter `role` au `$fillable`

Default `admin` pour ne pas casser les users existants.

### 2.3 Policies Laravel

Une Policy par modèle principal. Pattern uniforme :

```php
final class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canRead(Espace::Gestion);
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite(Espace::Gestion);
    }

    // update, delete → canWrite(Espace::Gestion)
}
```

**Policies à créer** :

| Policy | Espace principal | Modèle |
|--------|:---:|--------|
| OperationPolicy | Gestion | Operation |
| ParticipantPolicy | Gestion | Participant |
| TransactionPolicy | Compta | Transaction |
| FacturePolicy | Compta | Facture |
| RemiseBancairePolicy | Gestion | RemiseBancaire |
| CompteBancairePolicy | Paramètres (admin) | CompteBancaire |
| CategoriePolicy | Paramètres (admin) | Categorie |
| UserPolicy | Paramètres (admin) | User |
| TiersPolicy | les deux | Tiers |

### 2.4 Middleware `CheckEspaceAccess`

Remplace `RequireAdmin` du lot 1. Vérifie `$user->role->canRead($espace)` à l'entrée de chaque groupe de routes.

Pour les actions d'écriture, les Policies prennent le relais au niveau des composants Livewire et controllers.

### 2.5 Intégration dans les composants Livewire

Pattern : `$this->authorize('create', Operation::class)` dans les méthodes d'écriture.

Pour les vues, exposer un computed `$canEdit` :
```php
public function getCanEditProperty(): bool
{
    return Auth::user()->role->canWrite($this->espace);
}
```

Masquer les boutons d'action avec `@if($this->canEdit)` dans les blade.

### 2.6 UI Paramètres — Gestion des rôles

Sur l'écran utilisateurs existant (`parametres.utilisateurs`), ajouter un `<select>` pour le rôle dans la modale de création/modification. L'enum `Role` fournit les options via `Role::cases()`.

### 2.7 Refactoring RapportService

Extraire 3 builders depuis `RapportService` (1125 lignes → ~300 lignes chacun) :

| Builder | Méthodes extraites | Responsabilité |
|---------|-------------------|----------------|
| `CompteResultatBuilder` | `compteDeResultat()`, `compteDeResultatOperations()` + privées associées | P&L |
| `RapportSeancesBuilder` | `rapportSeances()` + privées associées | Rapport par séances |
| `FluxTresorerieBuilder` | `fluxTresorerie()` + privées associées | Flux de trésorerie |

`RapportService` devient un facade qui délègue + conserve `toCsv()`.

### 2.8 Tests

**Tests lot 1** :
- `FormulaireTokenTest` — isExpire/isValide avec expire_at null
- `ParticipantTableNotesTest` — sanitisation XSS au save
- `RemiseBancaireServiceTest` — suppression bulk (vérifier même résultat qu'avant)
- Migration test — vérifier que les indexes existent

**Tests lot 2** :
- `RoleEnumTest` — matrice canRead/canWrite exhaustive
- `PolicyTest` — chaque policy × chaque rôle (admin, comptable, gestionnaire, consultation)
- `MiddlewareCheckEspaceAccessTest` — accès autorisé/refusé par rôle
- `RapportServiceRefactoringTest` — résultats identiques avant/après refactoring (tests de non-régression sur les builders)
- `UserRoleManagementTest` — CRUD rôle dans l'UI paramètres

---

## Hors périmètre

- Refonte des FormRequest `authorize()` — sera fait naturellement via les Policies
- Cache `Association::first()` — optimisation future
- Fulltext search tiers — optimisation future
