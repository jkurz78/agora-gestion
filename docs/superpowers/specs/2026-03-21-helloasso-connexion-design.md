# HelloAsso — Écran de paramétrage de connexion

## Contexte

L'association utilise HelloAsso pour collecter cotisations et dons en ligne. L'application SVS Accounting devra à terme importer ces données via l'API HelloAsso (polling). Ce premier chantier pose les fondations : stockage sécurisé des credentials OAuth2 et vérification que la connexion fonctionne.

---

## Décisions structurantes

- **Table dédiée** `helloasso_parametres` avec `association_id` FK — anticipe le multi-association, évite de polluer la table `associations`
- **`client_secret` chiffré** via cast `encrypted` Laravel — données sensibles
- **Environnement** stocké comme enum `production`/`sandbox` — l'URL de base est dérivée, pas saisie librement
- **Test synchrone** via `HelloAssoService` — cohérent avec les services métier existants, suffisant pour un bouton de test utilisé rarement
- **Test indépendant de la sauvegarde** — on peut tester des credentials avant de les enregistrer

---

## Schéma de données

### Table `helloasso_parametres`

| Colonne | Type | Contraintes |
|---|---|---|
| `id` | bigint | PK auto-increment |
| `association_id` | bigint | FK → `associations.id`, unique |
| `client_id` | string(255) | nullable |
| `client_secret` | text | nullable, encrypted |
| `organisation_slug` | string(255) | nullable |
| `environnement` | enum(`production`, `sandbox`) | not null, défaut `production` |
| `created_at` / `updated_at` | timestamps | |

Pas de `SoftDeletes` — une suppression est réversible par re-saisie.

### Modèle `HelloAssoParametres`

```php
// app/Models/HelloAssoParametres.php
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
            'client_secret' => 'encrypted',
            'association_id' => 'integer',
        ];
    }
}
```

---

## Architecture technique

### `HelloAssoService` (`app/Services/HelloAssoService.php`)

Service injecté, utilise `Http::` de Laravel.

**Méthode principale :**
```php
public function testerConnexion(HelloAssoParametres $parametres): HelloAssoTestResult
```

**Étapes internes :**
1. Détermine `$baseUrl` selon `$parametres->environnement` :
   - `production` → `https://api.helloasso.com`
   - `sandbox` → `https://api.helloasso-sandbox.com`
2. POST `{baseUrl}/oauth2/token` avec `client_id`, `client_secret`, `grant_type=client_credentials`
3. Si échec (HTTP 4xx/5xx ou timeout) → retourne `HelloAssoTestResult` en erreur avec message explicite
4. Si succès → GET `{baseUrl}/v5/organizations/{slug}` avec le token Bearer
5. Retourne `HelloAssoTestResult` avec le nom de l'organisation ou message d'erreur

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

**État :**
- `string $clientId`
- `string $clientSecret` — affiché masqué, non pré-rempli si déjà enregistré (remplacé par placeholder `••••••••`)
- `string $organisationSlug`
- `string $environnement` — `production` ou `sandbox`
- `?HelloAssoTestResult $testResult` — résultat du dernier test

**Méthodes :**
- `mount()` : charge `HelloAssoParametres::where('association_id', 1)->first()`
- `sauvegarder()` : valide + persiste via `updateOrCreate`
- `testerConnexion()` : construit un objet `HelloAssoParametres` temporaire depuis les champs du formulaire (sans sauvegarder), appelle `HelloAssoService::testerConnexion()`, stocke le résultat dans `$testResult`

**Règles de validation pour `sauvegarder()` :**

| Champ | Règle |
|---|---|
| `client_id` | `nullable, string, max:255` |
| `client_secret` | `nullable, string` |
| `organisation_slug` | `nullable, string, max:255, regex:/^[a-z0-9-]+$/` |
| `environnement` | `required, in:production,sandbox` |

**Règles de validation pour `testerConnexion()` :**

| Champ | Règle |
|---|---|
| `client_id` | `required, string` |
| `client_secret` | `required, string` |
| `organisation_slug` | `required, string` |
| `environnement` | `required, in:production,sandbox` |

### Route et vue

**Route** (dans le groupe `parametres`) :
```php
Route::view('/helloasso', 'parametres.helloasso')->name('helloasso');
```

**Vue** : `resources/views/parametres/helloasso.blade.php`
**Vue Livewire** : `resources/views/livewire/parametres/helloasso-form.blade.php`

**Menu** : entrée "Connexion HelloAsso" dans le dropdown Paramètres de `layouts/app.blade.php`

---

## Interface utilisateur

### Bloc d'aide (en haut de l'écran)

Alerte Bootstrap `alert-info` avec les étapes pour créer les credentials sur HelloAsso :

1. Se connecter sur helloasso.com
2. Aller dans Tableau de bord > API > Mes applications
3. Créer une nouvelle application, copier le Client ID et le Client Secret
4. Le slug organisation est visible dans l'URL : `helloasso.com/associations/{slug}`

### Formulaire

```
Environnement    ○ Production  ● Sandbox
Client ID        [________________________________]
Client Secret    [________________________________]  (chiffré en base de données)
Slug organisation [_______________________________]
                  ex : association-svs

[ Enregistrer ]   [ Tester la connexion ]
```

- `client_secret` : champ `type="password"`, jamais pré-rempli si déjà enregistré (sécurité)
- Si `client_secret` déjà enregistré et champ laissé vide à la sauvegarde → conserver la valeur existante en base (ne pas écraser)

### Résultat du test (affiché sous les boutons)

**Succès :**
```
✓ Connexion réussie — Organisation : "Société de Voile de Sartrouville"
```

**Erreur credentials :**
```
✗ Erreur d'authentification : client_id ou client_secret invalide (HTTP 401)
```

**Erreur slug :**
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
- Gestion multi-associations (la colonne `association_id` est posée, le support complet viendra plus tard)
- Rafraîchissement automatique du token OAuth2
