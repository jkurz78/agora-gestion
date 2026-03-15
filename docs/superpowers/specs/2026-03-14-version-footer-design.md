# Version Footer — Design Spec

**Date:** 2026-03-14
**Statut:** Approuvé

## Objectif

Afficher la version courante de l'application (tag git + date du build) dans le pied de page de l'interface, afin que l'utilisateur et l'administrateur puissent identifier facilement la version installée.

## Architecture

### 1. Commande artisan `app:version-stamp`

**Fichier :** `app/Console/Commands/VersionStampCommand.php`

- Signature : `app:version-stamp`
- Description : "Génère config/version.php à partir des métadonnées git"
- Lit via `exec()` :
  - `git describe --tags --always` → tag ou SHA court (ex. `v1.0.0`, `abc1234`)
  - `git log -1 --format=%cd --date=format:'%Y-%m-%d'` → date du commit (ex. `2026-03-14`)
- Écrit `config/version.php` :

```php
<?php
return [
    'tag'      => 'abc1234',
    'date'     => '2026-03-14',
];
```

- Si git n'est pas disponible (exec échoue), utilise `['tag' => 'unknown', 'date' => 'unknown']`.
- Affiche un message de confirmation : `Version stamped: abc1234 (2026-03-14)`

### 2. Fallback dans AppServiceProvider

**Fichier :** `app/Providers/AppServiceProvider.php` — méthode `boot()`

- Si `config/version.php` n'existe pas au démarrage, appelle la même logique de lecture git et génère le fichier.
- Évite toute erreur si la commande n'a jamais été lancée (première installation).

### 3. Pied de page dans le layout principal

**Fichier :** `resources/views/layouts/app.blade.php`

Ajouter juste avant `</body>` :

```blade
<footer class="text-center text-muted small py-3 mt-4 border-top">
    SVS Accounting &middot; {{ config('version.tag', 'dev') }} &middot; {{ config('version.date', '') }}
</footer>
```

- Style : Bootstrap 5, texte petit et gris, discret.
- Uniquement dans `app.blade.php` (pas dans `guest.blade.php`).

### 4. Exclusion du fichier généré

**Fichier :** `.gitignore`

Ajouter : `/config/version.php`

Le fichier est local et généré — il ne doit pas être versionné.

## Flux d'utilisation

```
git pull
php artisan app:version-stamp   # → écrit config/version.php
# Footer affiche : SVS Accounting · abc1234 · 2026-03-14
```

**Première install (sans commande) :**
```
AppServiceProvider::boot() détecte l'absence de config/version.php
→ génère automatiquement le fichier
→ Footer affiche la version correcte sans intervention
```

## Fichiers concernés

| Action | Fichier |
|--------|---------|
| Créer | `app/Console/Commands/VersionStampCommand.php` |
| Modifier | `app/Providers/AppServiceProvider.php` |
| Modifier | `resources/views/layouts/app.blade.php` |
| Modifier | `.gitignore` |
| Généré (non versionné) | `config/version.php` |

## Tests

- Test unitaire `VersionStampCommandTest` : vérifie que la commande crée `config/version.php` avec les clés `tag` et `date`.
- Test que le fallback `AppServiceProvider` génère le fichier si absent.
- Test de rendu blade : `assertSee` sur le tag dans le layout.
