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
   ├── php artisan test (PHP 8.4)
   │     ├── échec → workflow s'arrête, prod intacte
   │     └── succès ↓
   └── POST https://compta.soigner-vivre-sourire.fr/deploy.php
              Authorization: Bearer <DEPLOY_SECRET>
                    │
                    ▼
              O2Switch (public/deploy.php)
                    ├── vérifie méthode POST + token
                    ├── git pull origin main
                    ├── composer install --no-dev --optimize-autoloader
                    ├── php artisan migrate --force
                    ├── php artisan config:cache
                    ├── php artisan route:cache
                    ├── php artisan view:cache
                    └── log dans deploy.log (hors public/)
```

---

## Fichiers à créer

| Fichier | Emplacement | Description |
|---------|-------------|-------------|
| `deploy.yml` | `.github/workflows/deploy.yml` | Workflow GitHub Actions |
| `deploy.php` | `public/deploy.php` | Script de déploiement sur O2Switch |

### `deploy.php` ne doit pas être commité tel quel en production

`deploy.php` est commité dans le repo (il est dans `public/`) mais il lit `DEPLOY_SECRET` depuis les variables d'environnement du serveur (`.env`) — le secret n'est jamais dans le code.

---

## Sécurité de deploy.php

- N'accepte que les requêtes **POST**
- Vérifie le header `Authorization: Bearer <token>` contre `$_ENV['DEPLOY_SECRET']` (lu depuis le `.env` via la fonction d'environnement Laravel ou `getenv()`)
- Toute requête invalide → réponse `403 Forbidden` immédiate, aucune commande exécutée
- Les sorties des commandes shell sont loguées dans `../deploy.log` (un niveau au-dessus de `public/`, inaccessible via HTTP)
- En cas d'échec d'une commande shell → arrêt immédiat + réponse `500`
- En cas de succès → réponse `200` JSON `{"status": "ok"}`

---

## Chemins sur O2Switch

- **Racine app :** `~/public_html/compta.soigner-vivre-sourire.fr/`
- **Document root :** `~/public_html/compta.soigner-vivre-sourire.fr/public/`
- **PHP :** `/usr/local/bin/php` (version 8.4)
- **deploy.log :** `~/public_html/compta.soigner-vivre-sourire.fr/deploy.log`

---

## GitHub Secrets requis

| Secret | Valeur |
|--------|--------|
| `DEPLOY_URL` | `https://compta.soigner-vivre-sourire.fr/deploy.php` |
| `DEPLOY_SECRET` | Token aléatoire (`openssl rand -hex 32`) |

---

## Workflow GitHub Actions

- **Déclencheur :** `push` sur `main` uniquement
- **PHP :** 8.4 (correspond à la production)
- **Extensions PHP :** celles requises par Laravel (pdo, mbstring, xml, curl, zip…)
- **Base de données de test :** SQLite en mémoire (`:memory:`) pour isoler les tests du pipeline
- **Étapes :**
  1. `actions/checkout@v4`
  2. `shivammathur/setup-php@v2` avec PHP 8.4
  3. `composer install --no-interaction --prefer-dist`
  4. Copier `.env.example` → `.env`, générer `APP_KEY`
  5. `php artisan test`
  6. Si succès : `curl -X POST -H "Authorization: Bearer $DEPLOY_SECRET" $DEPLOY_URL`

---

## Étapes manuelles préalables (une seule fois)

Ces étapes sont à effectuer manuellement avant que le pipeline soit opérationnel :

1. **Générer un token** : `openssl rand -hex 32` → stocker dans GitHub Secrets (`DEPLOY_SECRET`) et dans le `.env` O2Switch (`DEPLOY_SECRET=...`)
2. **Configurer GitHub Secrets** : `DEPLOY_URL` + `DEPLOY_SECRET` dans Settings → Secrets and variables → Actions
3. **Remplacer le FTP par git sur O2Switch** :
   - Générer une paire de clés SSH sur O2Switch : `ssh-keygen -t ed25519 -C "deploy@o2switch"`
   - Ajouter la clé publique dans GitHub → Settings → Deploy keys (lecture seule)
   - Cloner le repo : `cd ~/public_html/compta.soigner-vivre-sourire.fr && git clone git@github.com:Feucherolles/svs-accounting.git .`
4. **Ajouter `DEPLOY_SECRET`** dans le `.env` de production sur O2Switch

---

## Ce qui est hors périmètre

- Notifications Slack/email en cas d'échec du déploiement
- Rollback automatique
- Déploiement blue/green
- Gestion du mode maintenance (`php artisan down/up`) — peut être ajouté ultérieurement
