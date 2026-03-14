# Version Footer Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Afficher la version de l'application (tag git + date du build) dans le pied de page de l'interface authentifiée.

**Architecture:** Une commande artisan `app:version-stamp` génère `config/version.php` à partir des métadonnées git. Un fallback dans `AppServiceProvider::boot()` génère automatiquement ce fichier si absent. Le layout `app.blade.php` affiche la version via `config('version.tag')` et `config('version.date')`.

**Tech Stack:** Laravel 11, Pest PHP, Bootstrap 5

---

## File Structure

| Action | Fichier |
|--------|---------|
| Créer | `app/Console/Commands/VersionStampCommand.php` |
| Modifier | `app/Providers/AppServiceProvider.php` |
| Modifier | `resources/views/layouts/app.blade.php` |
| Modifier | `.gitignore` |
| Créer | `tests/Unit/VersionStampCommandTest.php` |
| Créer | `tests/Feature/VersionFooterTest.php` |
| Généré (non versionné) | `config/version.php` |

---

## Chunk 1: Commande artisan + AppServiceProvider

### Task 1: Commande `app:version-stamp`

**Files:**
- Create: `app/Console/Commands/VersionStampCommand.php`
- Create: `tests/Unit/VersionStampCommandTest.php`

- [ ] **Step 1: Écrire le test qui échoue**

Créer `tests/Unit/VersionStampCommandTest.php` :

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

afterEach(function (): void {
    @unlink(config_path('version.php'));
});

it('crée config/version.php avec les clés tag et date', function (): void {
    @unlink(config_path('version.php'));

    Artisan::call('app:version-stamp');

    expect(file_exists(config_path('version.php')))->toBeTrue();

    $version = require config_path('version.php');

    expect($version)->toBeArray()
        ->toHaveKey('tag')
        ->toHaveKey('date');

    expect($version['tag'])->toBeString()->not->toBeEmpty();
    expect($version['date'])->toBeString()->not->toBeEmpty();
});

it('affiche un message de confirmation après stamping', function (): void {
    @unlink(config_path('version.php'));

    $exitCode = Artisan::call('app:version-stamp');

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Version stamped:');
});
```

- [ ] **Step 2: Lancer le test pour vérifier qu'il échoue**

```bash
APP_SERVICE=laravel.test COMPOSE_PROJECT_NAME=svs-accounting \
  ./vendor/bin/sail artisan test tests/Unit/VersionStampCommandTest.php --no-coverage
```

Résultat attendu : FAIL — "There are no commands defined in the 'app' namespace."

- [ ] **Step 3: Créer la commande**

Créer `app/Console/Commands/VersionStampCommand.php` :

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class VersionStampCommand extends Command
{
    protected $signature = 'app:version-stamp';

    protected $description = 'Génère config/version.php à partir des métadonnées git';

    public function handle(): int
    {
        $data = self::readGitVersion();

        self::writeVersionFile($data);

        $this->info("Version stamped: {$data['tag']} ({$data['date']})");

        return self::SUCCESS;
    }

    /**
     * @return array{tag: string, date: string}
     */
    public static function readGitVersion(): array
    {
        exec('git describe --tags --always 2>/dev/null', $tagOutput, $tagCode);
        exec("git log -1 --format=%cd --date=format:'%Y-%m-%d' 2>/dev/null", $dateOutput, $dateCode);

        return [
            'tag'  => ($tagCode === 0 && isset($tagOutput[0])) ? trim($tagOutput[0]) : 'unknown',
            'date' => ($dateCode === 0 && isset($dateOutput[0])) ? trim($dateOutput[0]) : 'unknown',
        ];
    }

    /**
     * @param array{tag: string, date: string} $data
     */
    public static function writeVersionFile(array $data): void
    {
        $content = "<?php\nreturn " . var_export($data, true) . ";\n";
        file_put_contents(config_path('version.php'), $content);
    }
}
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

```bash
APP_SERVICE=laravel.test COMPOSE_PROJECT_NAME=svs-accounting \
  ./vendor/bin/sail artisan test tests/Unit/VersionStampCommandTest.php --no-coverage
```

Résultat attendu : 2 tests PASSED.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/VersionStampCommand.php \
        tests/Unit/VersionStampCommandTest.php
git commit -m "feat: commande app:version-stamp génère config/version.php"
```

---

### Task 2: Fallback AppServiceProvider

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `tests/Feature/VersionFooterTest.php` (premier test seulement)

**Contexte :** Le fallback se déclenche au boot de l'application si `config/version.php` est absent. Il réutilise les méthodes statiques `VersionStampCommand::readGitVersion()` et `writeVersionFile()` pour ne pas dupliquer la logique.

- [ ] **Step 1: Écrire le test du fallback qui échoue**

Créer `tests/Feature/VersionFooterTest.php` :

```php
<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\AppServiceProvider;

afterEach(function (): void {
    @unlink(config_path('version.php'));
});

it('AppServiceProvider::boot() génère config/version.php si le fichier est absent', function (): void {
    @unlink(config_path('version.php'));

    // Invoquer boot() directement pour simuler le démarrage de l'app sans le fichier
    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect(file_exists(config_path('version.php')))->toBeTrue();

    $version = require config_path('version.php');

    expect($version)->toBeArray()
        ->toHaveKey('tag')
        ->toHaveKey('date');
});

it('AppServiceProvider::boot() ne régénère pas config/version.php si le fichier existe déjà', function (): void {
    // Écrire un fichier version factice
    file_put_contents(config_path('version.php'), "<?php\nreturn ['tag' => 'v1.0.0', 'date' => '2026-01-01'];\n");
    $mtime = filemtime(config_path('version.php'));

    // Appeler boot() — ne doit pas écraser le fichier existant
    $provider = new AppServiceProvider(app());
    $provider->boot();

    expect(filemtime(config_path('version.php')))->toBe($mtime);
});
```

- [ ] **Step 2: Modifier AppServiceProvider**

Éditer `app/Providers/AppServiceProvider.php` :

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\VersionStampCommand;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (! file_exists(config_path('version.php'))) {
            $data = VersionStampCommand::readGitVersion();
            VersionStampCommand::writeVersionFile($data);
        }
    }
}
```

> **Important :** `AppServiceProvider` était `class` (non-final). Passer à `final class` pour respecter les conventions du projet. Vérifier d'abord qu'aucun autre provider n'étend cette classe (`grep -r "extends AppServiceProvider" app/`).

- [ ] **Step 3: Vérifier qu'aucune classe n'étend AppServiceProvider**

```bash
grep -r "extends AppServiceProvider" app/ || echo "OK — aucune extension"
```

Résultat attendu : "OK — aucune extension"

- [ ] **Step 4: Lancer les tests Feature existants pour détecter une régression**

```bash
APP_SERVICE=laravel.test COMPOSE_PROJECT_NAME=svs-accounting \
  ./vendor/bin/sail artisan test tests/Feature/ --no-coverage
```

Résultat attendu : tous les tests existants passent.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php \
        tests/Feature/VersionFooterTest.php
git commit -m "feat: fallback AppServiceProvider génère config/version.php au boot"
```

---

## Chunk 2: Footer blade + gitignore + test de rendu

### Task 3: Footer dans `app.blade.php` et `.gitignore`

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `.gitignore`
- Modify: `tests/Feature/VersionFooterTest.php`

- [ ] **Step 1: Enrichir le test du footer pour vérifier l'élément HTML**

Ajouter à `tests/Feature/VersionFooterTest.php` deux nouveaux tests :

```php
it('le footer version est présent dans les pages authentifiées', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertStatus(200);
    // Vérifier le marqueur unique du footer : "SVS Accounting &middot;" (entité HTML)
    $response->assertSee('SVS Accounting &middot;', false);
});

it('le footer version est absent des pages guest (login)', function (): void {
    $response = $this->get('/login');

    // La page login utilise guest.blade.php, pas app.blade.php
    // Elle ne doit PAS contenir "SVS Accounting &middot;" (spécifique au footer)
    $response->assertStatus(200);
    $response->assertDontSee('SVS Accounting &middot;', false);
});
```

- [ ] **Step 2: Lancer pour vérifier que le test 'footer version présent' échoue**

```bash
APP_SERVICE=laravel.test COMPOSE_PROJECT_NAME=svs-accounting \
  ./vendor/bin/sail artisan test tests/Feature/VersionFooterTest.php --no-coverage
```

Résultat attendu : le test `SVS Accounting &middot;` FAIL (l'entité HTML n'est pas encore dans la page).

- [ ] **Step 3: Ajouter le footer dans `app.blade.php`**

Dans `resources/views/layouts/app.blade.php`, remplacer la ligne :

```html
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts
</body>
```

par :

```html
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @livewireScripts

    <footer class="text-center text-muted small py-3 mt-4 border-top">
        SVS Accounting &middot; {{ config('version.tag', 'dev') }} &middot; {{ config('version.date', '') }}
    </footer>
</body>
```

- [ ] **Step 4: Ajouter `/config/version.php` dans `.gitignore`**

Ajouter à la fin de `.gitignore` :

```
# Version générée localement
/config/version.php
```

- [ ] **Step 5: Lancer tous les tests du footer**

```bash
APP_SERVICE=laravel.test COMPOSE_PROJECT_NAME=svs-accounting \
  ./vendor/bin/sail artisan test tests/Feature/VersionFooterTest.php --no-coverage
```

Résultat attendu : 4 tests PASSED.

- [ ] **Step 6: Lancer la suite complète pour détecter une régression**

```bash
APP_SERVICE=laravel.test COMPOSE_PROJECT_NAME=svs-accounting \
  ./vendor/bin/sail artisan test --no-coverage
```

Résultat attendu : tous les tests passent.

- [ ] **Step 7: Commit**

```bash
git add resources/views/layouts/app.blade.php \
        .gitignore \
        tests/Feature/VersionFooterTest.php
git commit -m "feat: footer version dans app.blade.php, config/version.php exclus du git"
```

---

## Vérification manuelle (optionnelle)

Après implémentation, tester manuellement :

```bash
# Générer le fichier version
./vendor/bin/sail artisan app:version-stamp

# Vérifier le contenu
cat config/version.php

# Redémarrer l'app et vérifier le footer dans le navigateur
# → http://localhost affiche : SVS Accounting · <tag> · <date>
```

Sans exécuter la commande (premier boot) :
```bash
rm -f config/version.php
# Relancer l'app → AppServiceProvider génère automatiquement le fichier
```
