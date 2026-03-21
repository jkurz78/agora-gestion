# HelloAsso — Écran de paramétrage de connexion

## Contexte

L'association utilise HelloAsso pour collecter cotisations et dons en ligne. L'application SVS Accounting devra à terme importer ces données via l'API HelloAsso (polling). Ce premier chantier pose les fondations : stockage sécurisé des credentials OAuth2 et vérification que la connexion fonctionne.

---

## Décisions structurantes

- **Table dédiée** `helloasso_parametres` avec `association_id` FK unique — anticipe le multi-association, évite de polluer la table `associations`
- **`client_secret` chiffré** via cast `encrypted` Laravel — données sensibles. Dépend de `APP_KEY` : si la clé change, les secrets stockés deviennent illisibles. `APP_KEY` doit être sauvegardée et stable en production.
- **Enum PHP** `App\Enums\HelloAssoEnvironnement` (backed string) — cohérent avec les conventions du projet
- **Environnement** stocké comme enum `production`/`sandbox` — l'URL de base est dérivée, pas saisie librement
- **Test synchrone** via `HelloAssoService` utilisant `Http::` de Laravel — cohérent avec les services métier existants, suffisant pour un bouton de test utilisé rarement
- **Test indépendant de la sauvegarde** — on peut tester des credentials avant de les enregistrer
- **`client_secret` non réécrit si laissé vide** à la sauvegarde — la valeur existante est conservée

---

## Schéma de données

### Table `helloasso_parametres`

| Colonne | Type | Contraintes |
|---|---|---|
| `id` | bigint | PK auto-increment |
| `association_id` | bigint | FK → `associations.id`, `unique()` |
| `client_id` | string(255) | nullable |
| `client_secret` | text | nullable, encrypted |
| `organisation_slug` | string(255) | nullable |
| `environnement` | string | not null, défaut `production` (stocké comme string, casté en enum) |
| `created_at` / `updated_at` | timestamps | |

Pas de `SoftDeletes` — une suppression est réversible par re-saisie.

**Migration :**
```php
$table->foreignId('association_id')->unique()->constrained('associations');
```

### Enum `HelloAssoEnvironnement` (`app/Enums/HelloAssoEnvironnement.php`)

```php
enum HelloAssoEnvironnement: string
{
    case Production = 'production';
    case Sandbox    = 'sandbox';

    public function baseUrl(): string
    {
        return match($this) {
            self::Production => 'https://api.helloasso.com',
            self::Sandbox    => 'https://api.helloasso-sandbox.com',
        };
    }
}
```

### Modèle `HelloAssoParametres` (`app/Models/HelloAssoParametres.php`)

```php
final class HelloAssoParametres extends Model
{
    protected $table = 'helloasso_parametres';

    protected $fillable = [
        'association_id',
        'client_id',
        'client_secret',
        'organisation_slug',
        'environnement',
    ];

    protected function casts(): array
    {
        return [
            'client_secret'  => 'encrypted',
            'association_id' => 'integer',
            'environnement'  => HelloAssoEnvironnement::class,
        ];
    }
}
```

---

## Architecture technique

### `HelloAssoService` (`app/Services/HelloAssoService.php`)

Utilise `Http::` de Laravel (façade Guzzle). Timeout configuré à **10 secondes** pour ne pas bloquer indéfiniment la requête Livewire.

**Méthode principale :**
```php
public function testerConnexion(HelloAssoParametres $parametres): HelloAssoTestResult
```

**Étapes internes :**

1. Récupère `$baseUrl` via `$parametres->environnement->baseUrl()`
2. POST `{baseUrl}/oauth2/token` :
   - Body : `client_id`, `client_secret`, `grant_type=client_credentials`
   - Timeout : 10s
3. **Gestion des erreurs :**
   - `\Illuminate\Http\Client\ConnectionException` (timeout, DNS, réseau) → erreur réseau distincte
   - HTTP 4xx/5xx → erreur d'authentification avec code HTTP
4. Si token obtenu → GET `{baseUrl}/v5/organizations/{slug}` avec header `Authorization: Bearer {token}`, timeout 10s
5. **Gestion des erreurs :**
   - HTTP 401 → credentials invalides
   - HTTP 404 → slug introuvable
   - Autre HTTP error → erreur générique avec code
   - `ConnectionException` → erreur réseau
6. Retourne `HelloAssoTestResult` avec le nom de l'organisation ou un message d'erreur

**Note implémentation — nom de l'organisation :** Le champ JSON exact de la réponse `GET /organizations/{slug}` (vraisemblablement `name` ou `organizationName`) doit être vérifié lors de l'implémentation contre la réponse réelle de l'API ou via la sandbox HelloAsso.

**Note implémentation — accès au secret dans le service :** Le service doit accéder à `$parametres->client_secret` via le getter Eloquent (pas un DTO plain PHP) pour que le cast `encrypted` déchiffre correctement la valeur — que le modèle soit persisité ou instancié temporairement via `fill()`.

### Value object `HelloAssoTestResult` (`app/Services/HelloAssoTestResult.php`)

```php
final class HelloAssoTestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $organisationNom = null,
        public readonly ?string $erreur = null,
    ) {}
}
```

### Composant Livewire `Parametres\HelloAssoForm` (`app/Livewire/Parametres/HelloAssoForm.php`)

Tag Blade : `<livewire:parametres.helloasso-form />`

**État :**
- `string $clientId = ''`
- `string $clientSecret = ''` — jamais pré-rempli (champ `type="password"`), placeholder `••••••••` si déjà enregistré
- `string $organisationSlug = ''`
- `string $environnement = 'production'`
- `?HelloAssoTestResult $testResult = null`
- `bool $secretDejaEnregistre = false` — indique si un secret est en base (pour afficher le placeholder)

**Méthodes :**

`mount()` : charge `HelloAssoParametres::where('association_id', 1)->first()`, remplit les champs sauf `clientSecret`, met `$secretDejaEnregistre = true` si un secret est en base.

`sauvegarder()` :
- Valide les champs
- Si `$clientSecret` est vide **et** qu'un secret est déjà en base → exclure `client_secret` du payload de mise à jour (ne pas écraser)
- Si `$clientSecret` est renseigné → inclure dans le payload
- Persiste via `updateOrCreate(['association_id' => 1], $payload)`

`testerConnexion()` :
- Valide que `client_id`, `client_secret`, `organisation_slug` sont renseignés
- Si `$clientSecret` est vide et `$secretDejaEnregistre = true` → charger le secret depuis la base pour le test
- Construit un objet `HelloAssoParametres` temporaire (sans `save()`)
- Appelle `HelloAssoService::testerConnexion()`
- Stocke le résultat dans `$testResult`

**Règles de validation pour `sauvegarder()` :**

| Champ | Règle |
|---|---|
| `clientId` | `nullable, string, max:255` |
| `clientSecret` | `nullable, string` |
| `organisationSlug` | `nullable, string, max:255, regex:/^[a-z0-9-]+$/` |
| `environnement` | `required, in:production,sandbox` |

**Règles de validation pour `testerConnexion()` :**

| Champ | Règle |
|---|---|
| `clientId` | `required, string` |
| `clientSecret` | `required_unless:secretDejaEnregistre,true` |
| `organisationSlug` | `required, string` |
| `environnement` | `required, in:production,sandbox` |

### Route et vue

**Route** — à ajouter dans le groupe existant `Route::prefix('parametres')->name('parametres.')` dans `routes/web.php` :
```php
Route::view('/helloasso', 'parametres.helloasso')->name('helloasso');
// URL résolue : /parametres/helloasso
// Nom complet : parametres.helloasso
```

La route hérite du middleware `auth` du groupe parent existant. Pas de gate supplémentaire pour cette première version.

`association_id = 1` est une constante courante (application mono-association), cohérente avec le pattern `Association::find(1)` utilisé dans tout le projet.

**Vue page** : `resources/views/parametres/helloasso.blade.php`
**Vue Livewire** : `resources/views/livewire/parametres/helloasso-form.blade.php`

**Menu** : entrée "Connexion HelloAsso" dans le dropdown Paramètres de `layouts/app.blade.php`, conditionnée par `@if (Route::has('parametres.helloasso'))` comme les autres entrées.

---

## Interface utilisateur

### Bloc d'aide (alerte Bootstrap `alert-info`)

Instructions pour créer les credentials sur HelloAsso :

1. Se connecter sur helloasso.com avec un compte administrateur de l'association
2. Aller dans Tableau de bord > API > Mes applications
3. Créer une nouvelle application, copier le **Client ID** et le **Client Secret**
4. Le slug organisation est visible dans l'URL de votre espace HelloAsso : `helloasso.com/associations/{slug}`

### Formulaire

```
Environnement    ○ Production  ○ Sandbox

Client ID        [________________________________]

Client Secret    [________________________________]
                 (chiffré en base de données)
                 Si déjà enregistré : laisser vide pour conserver la valeur actuelle

Slug organisation [_______________________________]
                  ex : association-svs

[ Enregistrer ]   [ Tester la connexion ]
```

- `client_secret` : champ `type="password"`, jamais pré-rempli si déjà enregistré en base
- Si `client_secret` déjà enregistré et champ laissé vide à la sauvegarde → conserver la valeur existante

### Résultat du test (affiché sous les boutons)

**Succès :**
```
✓ Connexion réussie — Organisation : "Société de Voile de Sartrouville"
```

**Erreur credentials (HTTP 401) :**
```
✗ Erreur d'authentification : client_id ou client_secret invalide (HTTP 401)
```

**Erreur slug (HTTP 404) :**
```
✗ Organisation introuvable : vérifiez le slug (HTTP 404)
```

**Erreur réseau :**
```
✗ Impossible de joindre HelloAsso : timeout ou erreur réseau
```

---

## Ce qui est hors périmètre

- Import des cotisations et dons depuis HelloAsso (futur : polling API)
- Webhooks HelloAsso
- Support complet multi-associations (la colonne `association_id` et l'unicité sont posées, le routing viendra plus tard)
- Rafraîchissement automatique du token OAuth2
