# Déploiement continu Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatiser le déploiement sur O2Switch à chaque push sur `main` via GitHub Actions + un script PHP de déploiement sécurisé.

**Architecture:** GitHub Actions lance les tests (PHP 8.4, SQLite :memory:) et, si succès, envoie une requête HTTPS vers `deploy.php` sur O2Switch qui exécute `git pull` + les commandes artisan. Aucune connexion SSH entrante depuis GitHub n'est nécessaire — le serveur initie toutes les opérations lui-même.

**Tech Stack:** PHP 8.4, GitHub Actions (`shivammathur/setup-php@v2`), curl, shell_exec, Pest PHP (tests existants)

**Note importante :** `deploy.php` est un script PHP standalone — Laravel n'est pas bootstrappé. Il lit le `.env` manuellement et n'utilise pas les helpers Laravel. Il n'y a pas de tests automatisés pour ce script (shell_exec vers un vrai serveur) — la validation est manuelle via curl.

---

## Chunk 1: phpunit.xml + deploy.php

### Task 1: SQLite :memory: dans phpunit.xml

**Files:**
- Modify: `phpunit.xml`

Ce changement permet aux tests de tourner sans MySQL, indispensable pour GitHub Actions CI.

- [ ] **Step 1 : Modifier phpunit.xml**

Dans le bloc `<php>` :
- **Remplacer** la ligne `<env name="DB_DATABASE" value="testing"/>` par `<env name="DB_DATABASE" value=":memory:"/>`
- **Ajouter** `<env name="DB_CONNECTION" value="sqlite"/>` juste avant

⚠️ Ne pas laisser deux entrées `DB_DATABASE` — remplacer, pas ajouter.

Le bloc `<php>` complet doit ressembler à :

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Le bloc `<php>` complet doit ressembler à :

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="APP_MAINTENANCE_DRIVER" value="file"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="MAIL_MAILER" value="array"/>
    <env name="PULSE_ENABLED" value="false"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="TELESCOPE_ENABLED" value="false"/>
</php>
```

- [ ] **Step 2 : Vérifier que tous les tests passent encore**

```bash
./vendor/bin/sail artisan test --no-ansi 2>&1 | tail -5
```

Résultat attendu : 0 failures. Les warnings `PDO::MYSQL_ATTR_SSL_CA` sont pré-existants et non bloquants (tests marqués `!` = passing avec deprecation).

- [ ] **Step 3 : Commit**

```bash
git add phpunit.xml
git commit -m "test: SQLite :memory: dans phpunit.xml pour CI sans MySQL"
```

---

### Task 2: deploy.php — Script de déploiement

**Files:**
- Create: `public/deploy.php`

Script PHP standalone sécurisé par token. Pas de tests automatisés — validé manuellement à l'étape de mise en production.

- [ ] **Step 1 : Créer `public/deploy.php`**

```php
<?php

declare(strict_types=1);

// Supprimer toute sortie d'erreur PHP avant l'auth check
ini_set('display_errors', '0');
error_reporting(0);

// ─── Lecture manuelle du .env ────────────────────────────────────────────────
// Laravel n'est pas bootstrappé ici — env() et getenv() ne lisent pas le .env.
$envFile = __DIR__ . '/../.env';
$env     = [];

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }
}

// ─── Authentification ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit;
}

$expectedSecret = $env['DEPLOY_SECRET'] ?? '';
$authHeader     = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedSecret = '';

if (str_starts_with($authHeader, 'Bearer ')) {
    $providedSecret = substr($authHeader, 7);
}

// hash_equals() est obligatoire pour éviter les timing attacks
if ($expectedSecret === '' || !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(403);
    exit;
}

// ─── Configuration ───────────────────────────────────────────────────────────
$appDir  = __DIR__ . '/..';
$logFile = $appDir . '/deploy.log';
$php     = '/usr/local/bin/php';
$composer = '/usr/local/bin/composer';

// ─── Helpers ─────────────────────────────────────────────────────────────────
function runCommand(string $cmd, string $logFile): bool
{
    $output  = [];
    $retCode = 0;

    exec($cmd . ' 2>&1', $output, $retCode);

    $log = '[' . date('Y-m-d H:i:s') . '] $ ' . $cmd . "\n";
    $log .= implode("\n", $output) . "\n";
    $log .= 'Exit code: ' . $retCode . "\n\n";

    file_put_contents($logFile, $log, FILE_APPEND);

    return $retCode === 0;
}

// ─── Déploiement ─────────────────────────────────────────────────────────────
file_put_contents($logFile, "\n" . str_repeat('=', 60) . "\n" . '[' . date('Y-m-d H:i:s') . "] Déploiement démarré\n", FILE_APPEND);

$commands = [
    "cd {$appDir} && {$php} artisan optimize:clear",
    "cd {$appDir} && git pull origin main",
    "cd {$appDir} && {$composer} install --no-dev --optimize-autoloader --no-interaction",
    "cd {$appDir} && {$php} artisan migrate --force",
    "cd {$appDir} && {$php} artisan config:cache",
    "cd {$appDir} && {$php} artisan route:cache",
    "cd {$appDir} && {$php} artisan view:cache",
];

foreach ($commands as $cmd) {
    if (!runCommand($cmd, $logFile)) {
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] ÉCHEC — déploiement interrompu\n", FILE_APPEND);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error']);
        exit;
    }
}

file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] Déploiement terminé avec succès\n", FILE_APPEND);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
```

- [ ] **Step 2 : Vérifier la syntaxe PHP**

```bash
php -l public/deploy.php
```

Résultat attendu : `No syntax errors detected in public/deploy.php`

- [ ] **Step 3 : Commit**

```bash
git add public/deploy.php
git commit -m "feat: deploy.php — script de déploiement sécurisé par token"
```

---

## Chunk 2: Workflow GitHub Actions

### Task 3: .github/workflows/deploy.yml

**Files:**
- Create: `.github/workflows/deploy.yml`

- [ ] **Step 1 : Créer le dossier et le fichier**

```bash
mkdir -p .github/workflows
```

Créer `.github/workflows/deploy.yml` :

```yaml
name: CI & Deploy

on:
  push:
    branches: [main]

jobs:
  test-and-deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP 8.4
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo, pdo_sqlite, mbstring, xml, dom, curl, zip, intl, bcmath
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Run tests
        run: php artisan test --no-ansi

      - name: Deploy to production
        run: |
          curl --fail -s \
            -X POST \
            -H "Authorization: Bearer ${{ secrets.DEPLOY_SECRET }}" \
            "${{ secrets.DEPLOY_URL }}"
```

- [ ] **Step 2 : Vérifier la syntaxe YAML**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))" && echo "YAML valide"
```

Résultat attendu : `YAML valide`

- [ ] **Step 3 : Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "ci: workflow GitHub Actions — tests PHP 8.4 + déploiement O2Switch"
```

- [ ] **Step 4 : Push sur main**

⚠️ **Ne pusher sur `main` que lorsque les étapes manuelles O2Switch sont complètes** (voir checklist ci-dessous). Si le serveur n'est pas encore configuré, `deploy.php` retournera `403` et le workflow échouera au step "Deploy to production".

```bash
git push origin main
```

---

## Checklist manuelle O2Switch (avant le premier push sur main)

Ces étapes sont à réaliser dans le terminal SSH du cPanel, dans l'ordre. Elles ne font pas partie du code commité — c'est la configuration unique du serveur.

- [ ] **O1 : Générer le token secret**

Sur ta machine locale :
```bash
openssl rand -hex 32
```
Copier la valeur — elle sera utilisée en O2 et O5.

- [ ] **O2 : Configurer les GitHub Secrets**

Sur GitHub → repo → Settings → Secrets and variables → Actions → New repository secret :
- `DEPLOY_SECRET` = le token généré en O1
- `DEPLOY_URL` = `https://compta.soigner-vivre-sourire.fr/deploy.php`

- [ ] **O3 : Générer la clé SSH deploy key sur O2Switch**

Dans le terminal SSH cPanel :
```bash
ssh-keygen -t ed25519 -C "deploy@o2switch" -f ~/.ssh/id_ed25519_github
cat ~/.ssh/id_ed25519_github.pub
```
Copier la clé publique affichée.

- [ ] **O4 : Ajouter la deploy key sur GitHub**

GitHub → repo → Settings → Deploy keys → Add deploy key :
- Title : `O2Switch`
- Key : la clé publique copiée en O3
- Allow write access : **non** (lecture seule suffit)

- [ ] **O5 : Configurer ~/.ssh/config sur O2Switch**

```bash
cat >> ~/.ssh/config << 'EOF'

Host github.com
    IdentityFile ~/.ssh/id_ed25519_github
    StrictHostKeyChecking no
EOF
```

Tester la connexion :
```bash
ssh -T git@github.com
```
Résultat attendu : `Hi Feucherolles! You've successfully authenticated...`

- [ ] **O6 : Cloner le repo sur O2Switch**

```bash
cd ~/public_html/compta.soigner-vivre-sourire.fr
# Le répertoire doit être vide ou contenir uniquement le .env
# Si des fichiers FTP sont présents, les supprimer d'abord (garder le .env)
git clone git@github.com:Feucherolles/svs-accounting.git .
```

- [ ] **O7 : Vérifier les permissions**

```bash
chmod -R 775 storage bootstrap/cache
```

- [ ] **O8 : Confirmer le chemin de composer**

```bash
which composer
```

Si différent de `/usr/local/bin/composer`, mettre à jour la variable `$composer` dans `public/deploy.php` avant de commiter.

- [ ] **O9 : Ajouter DEPLOY_SECRET dans le .env de production**

```bash
echo "DEPLOY_SECRET=<le token de O1>" >> ~/public_html/compta.soigner-vivre-sourire.fr/.env
```

- [ ] **O10 : Tester deploy.php manuellement**

```bash
curl --fail -v \
  -X POST \
  -H "Authorization: Bearer <le token de O1>" \
  https://compta.soigner-vivre-sourire.fr/deploy.php
```

Résultat attendu : code `200` + `{"status":"ok"}` dans la réponse.
Vérifier aussi le log :
```bash
tail -50 ~/public_html/compta.soigner-vivre-sourire.fr/deploy.log
```

---

## Vérification finale

Après le premier push sur `main` avec le workflow actif :

1. Aller sur GitHub → repo → Actions → vérifier que le workflow passe au vert
2. Vérifier sur O2Switch :
   ```bash
   tail -30 ~/public_html/compta.soigner-vivre-sourire.fr/deploy.log
   ```
3. Charger `https://compta.soigner-vivre-sourire.fr` et vérifier que l'app répond normalement
