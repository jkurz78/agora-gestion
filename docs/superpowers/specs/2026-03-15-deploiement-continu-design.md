# Déploiement continu — GitHub Actions + deploy.php

## Contexte

L'application tourne sur un hébergement mutualisé O2Switch (cPanel) sous le sous-domaine `compta.soigner-vivre-sourire.fr`. Le déploiement est actuellement manuel (FTP + commandes SSH dans le terminal cPanel). L'objectif est d'automatiser le déploiement à chaque push sur `main`, avec exécution des tests avant toute mise en production.

O2Switch filtre les connexions SSH entrantes par IP — les runners GitHub Actions (IPs AWS dynamiques) ne peuvent pas se connecter en SSH directement. La solution retenue est un script PHP de déploiement exposé en HTTPS sur le serveur, déclenché par GitHub Actions après validation des tests.

---

## Architecture

```
push main
   │
   ▼
GitHub Actions (.github/workflows/deploy.yml)
   ├── checkout + composer install
   ├── php artisan test (PHP 8.4, SQLite :memory:)
   │     ├── échec → workflow s'arrête, prod intacte
   │     └── succès ↓
   └── curl --fail POST https://compta.soigner-vivre-sourire.fr/deploy.php
              Authorization: Bearer <DEPLOY_SECRET>
                    │
                    ▼
              O2Switch (public/deploy.php)
                    ├── supprime les erreurs PHP (display_errors Off)
                    ├── vérifie méthode POST + token via hash_equals()
                    ├── php artisan optimize:clear
                    ├── git pull origin main
                    ├── composer install --no-dev --optimize-autoloader
                    ├── php artisan migrate --force
                    ├── php artisan config:cache
                    ├── php artisan route:cache
                    ├── php artisan view:cache
                    └── log dans deploy.log (hors public/)
```

**Risque connu :** pas de mode maintenance (`php artisan down/up`) autour du déploiement. Il existe une fenêtre entre `git pull` et `php artisan migrate --force` pendant laquelle le nouveau code tourne sur l'ancien schéma. Pour cette application (données financières), ce risque est accepté en première version — le mode maintenance peut être ajouté ultérieurement.

---

## Fichiers à créer ou modifier

| Fichier | Action | Description |
|---------|--------|-------------|
| `phpunit.xml` | Modifier | Ajouter `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` |
| `.github/workflows/deploy.yml` | Créer | Workflow GitHub Actions |
| `public/deploy.php` | Créer | Script de déploiement sur O2Switch |

---

## Modification de phpunit.xml

Ajouter dans le bloc `<php>` :

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Cela garantit que tous les tests (local et CI) utilisent SQLite en mémoire, sans dépendance à MySQL.

---

## Sécurité de deploy.php

- Désactive immédiatement `display_errors` pour éviter toute fuite d'information en cas d'erreur PHP avant l'auth check
- N'accepte que les requêtes **POST**
- Lit `DEPLOY_SECRET` depuis le `.env` via un mini-parseur dotenv maison (Laravel n'est pas bootstrappé dans ce script — `env()` n'est pas disponible)
- Compare le token avec `hash_equals()` (protection contre les timing attacks) — jamais avec `===` ou `strcmp()`
- Toute requête invalide → réponse `403 Forbidden` immédiate, aucune commande exécutée
- Les sorties des commandes shell sont loguées dans `../deploy.log` (un niveau au-dessus de `public/`, inaccessible via HTTP)
- En cas d'échec d'une commande shell → arrêt immédiat + réponse `500`
- En cas de succès → réponse `200` JSON `{"status": "ok"}`

### Lecture du .env dans deploy.php

```php
<?php
ini_set('display_errors', '0');
error_reporting(0);

// Lecture manuelle du .env (Laravel non bootstrappé)
$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }
}

$secret = $env['DEPLOY_SECRET'] ?? '';
// ...
```

---

## Chemins sur O2Switch

- **Racine app :** `~/public_html/compta.soigner-vivre-sourire.fr/`
- **Document root :** `~/public_html/compta.soigner-vivre-sourire.fr/public/`
- **PHP :** `/usr/local/bin/php` (version 8.4, confirmé via `which php`)
- **Composer :** à confirmer via `which composer` — probablement `/usr/local/bin/composer`
- **deploy.log :** `~/public_html/compta.soigner-vivre-sourire.fr/deploy.log`

---

## GitHub Secrets requis

| Secret | Valeur |
|--------|--------|
| `DEPLOY_URL` | `https://compta.soigner-vivre-sourire.fr/deploy.php` |
| `DEPLOY_SECRET` | Token aléatoire généré par `openssl rand -hex 32` |

---

## Workflow GitHub Actions

- **Déclencheur :** `push` sur `main` uniquement
- **PHP :** 8.4
- **Extensions PHP :** `pdo, pdo_sqlite, mbstring, xml, dom, curl, zip, intl, bcmath`
- **Base de données de test :** SQLite `:memory:` (via `phpunit.xml`)
- **Étapes :**
  1. `actions/checkout@v4`
  2. `shivammathur/setup-php@v2` avec PHP 8.4 et les extensions listées
  3. `composer install --no-interaction --prefer-dist`
  4. Copier `.env.example` → `.env`, générer `APP_KEY` via `php artisan key:generate`
  5. `php artisan test`
  6. Si succès : `curl --fail -s -X POST -H "Authorization: Bearer ${{ secrets.DEPLOY_SECRET }}" ${{ secrets.DEPLOY_URL }}`

Le flag `--fail` sur `curl` est obligatoire : sans lui, un `500` retourné par `deploy.php` serait ignoré et le workflow afficherait un succès alors que le déploiement a échoué.

---

## Étapes manuelles préalables (une seule fois, dans l'ordre)

1. **Générer le token** sur la machine locale :
   ```bash
   openssl rand -hex 32
   ```
   Conserver la valeur — elle sera utilisée aux étapes 2 et 4.

2. **Configurer les GitHub Secrets** dans Settings → Secrets and variables → Actions :
   - `DEPLOY_SECRET` = le token généré à l'étape 1
   - `DEPLOY_URL` = `https://compta.soigner-vivre-sourire.fr/deploy.php`

3. **Configurer la clé SSH deploy key sur O2Switch** (pour que O2Switch puisse faire `git pull` vers GitHub) :
   - Dans le terminal SSH cPanel :
     ```bash
     ssh-keygen -t ed25519 -C "deploy@o2switch" -f ~/.ssh/id_ed25519_github
     cat ~/.ssh/id_ed25519_github.pub
     ```
   - Copier la clé publique → GitHub repo → Settings → Deploy keys → Add deploy key (lecture seule)
   - Créer `~/.ssh/config` sur O2Switch :
     ```
     Host github.com
         IdentityFile ~/.ssh/id_ed25519_github
         StrictHostKeyChecking no
     ```

4. **Cloner le repo sur O2Switch** (remplace le FTP) :
   ```bash
   cd ~/public_html/compta.soigner-vivre-sourire.fr
   # Sauvegarder le .env existant si nécessaire
   git clone git@github.com:Feucherolles/svs-accounting.git .
   ```

5. **Ajouter `DEPLOY_SECRET` dans le `.env` de production** sur O2Switch :
   ```
   DEPLOY_SECRET=<le token de l'étape 1>
   ```
   ⚠️ Cette étape doit être faite **avant tout push sur `main`** — sans ce token, `deploy.php` retournera `403` pour toutes les requêtes.

6. **Vérifier les permissions** de `storage/` et `bootstrap/cache/` :
   ```bash
   chmod -R 775 storage bootstrap/cache
   ```

7. **Confirmer le chemin de composer** :
   ```bash
   which composer
   ```
   Mettre à jour `deploy.php` avec le chemin absolu si différent de `/usr/local/bin/composer`.

8. **Tester le script manuellement** avant le premier push :
   ```bash
   curl --fail -v -X POST -H "Authorization: Bearer <SECRET>" https://compta.soigner-vivre-sourire.fr/deploy.php
   ```
   Vérifier la réponse `200` et le contenu de `deploy.log`.

---

## Ce qui est hors périmètre

- Mode maintenance (`php artisan down/up`) — risque connu, ajout ultérieur
- Notifications en cas d'échec du déploiement
- Rollback automatique
- Déploiement blue/green
- Rate-limiting ou restriction IP sur `deploy.php`
