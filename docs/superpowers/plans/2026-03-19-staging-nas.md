# Staging NAS — Implémentation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mettre en place un environnement de préproduction sur le NAS Synology, déployable depuis le Mac via `git push`, avec un script de clonage des données de prod anonymisées.

**Architecture:** Le Mac pousse sur un bare repo Git hébergé sur le NAS ; un hook `post-receive` reconstruit et redémarre les containers Docker. Un script shell séparé clone la base MySQL de prod (O2Switch) vers le staging et anonymise la table `tiers`.

**Tech Stack:** Docker (PHP 8.3-FPM + Nginx + MySQL 8), Git bare repo + hook post-receive, SSH avec clé, Bash

---

## Prérequis manuels (à faire avant toute exécution)

Ces étapes se font à la main — elles ne font pas partie du code.

### A. Clé SSH Mac → NAS

```bash
# Sur le Mac
ssh-copy-id -p 2022 jurgen@NAS_IP
# Vérifier : connexion sans mot de passe
ssh -p 2022 jurgen@NAS_IP "echo OK"
```

### B. Ajouter jurgen au groupe docker sur le NAS

```bash
# Sur le NAS (via SSH ou interface Synology)
sudo synogroup --adduser docker jurgen
# Se déconnecter/reconnecter, puis vérifier :
docker ps
```

---

## Fichiers créés / modifiés

| Fichier | Rôle |
|---------|------|
| `Dockerfile` | Image PHP 8.3-FPM production (sans Sail) |
| `docker/nginx/default.conf` | Config Nginx pour PHP-FPM |
| `docker-compose.staging.yml` | Stack staging : app + nginx + mysql |
| `.env.staging.example` | Template des variables d'environnement staging |
| `scripts/deploy-hook.sh` | Hook git post-receive à installer sur le NAS |
| `scripts/clone-prod.sh` | Clone MySQL prod → staging + anonymisation |
| `scripts/anonymize-tiers.sql` | SQL d'anonymisation de la table tiers |

---

## Task 1 : Dockerfile production

**Files:**
- Create: `Dockerfile`
- Create: `docker/nginx/default.conf`

- [ ] **Step 1 : Créer le Dockerfile**

```dockerfile
FROM php:8.3-fpm-alpine

# Dépendances système
RUN apk add --no-cache \
    nginx \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    mysql-client

# Extensions PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl bcmath gd opcache

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dépendances Composer d'abord (cache layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Code source
COPY . .

# Scripts post-install
RUN composer run-script post-autoload-dump || true \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Permissions (mkdir -p au cas où les répertoires n'existent pas)
RUN mkdir -p storage/app/public storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
```

- [ ] **Step 2 : Créer la config Nginx**

```nginx
# docker/nginx/default.conf
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 3 : Commit**

```bash
git add Dockerfile docker/nginx/default.conf
git commit -m "feat(staging): Dockerfile production PHP 8.3-FPM + config nginx"
```

---

## Task 2 : docker-compose.staging.yml

**Files:**
- Create: `docker-compose.staging.yml`
- Create: `.env.staging.example`

- [ ] **Step 1 : Créer docker-compose.staging.yml**

```yaml
# docker-compose.staging.yml
services:
  app:
    build: .
    image: svs-accounting:staging
    restart: unless-stopped
    env_file: .env
    volumes:
      - storage_data:/var/www/html/storage/app/public
    depends_on:
      db:
        condition: service_healthy
    networks:
      - staging

  nginx:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "8080:80"
    volumes:
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
      - storage_data:/var/www/html/storage/app/public:ro
    depends_on:
      - app
    networks:
      - staging

  db:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10
    networks:
      - staging

volumes:
  storage_data:
  db_data:

networks:
  staging:
```

- [ ] **Step 2 : Créer .env.staging.example**

```dotenv
APP_NAME="SVS Accounting (Staging)"
APP_ENV=staging
APP_KEY=
APP_DEBUG=false
APP_URL=http://NAS_IP:8080

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=svs_staging
DB_USERNAME=svs
DB_PASSWORD=CHANGER_MOI
DB_ROOT_PASSWORD=CHANGER_MOI_ROOT

MAIL_MAILER=log
```

> **Note :** Sur le NAS, copier ce fichier en `.env` dans le répertoire de déploiement et remplir les valeurs réelles. Ne jamais commiter `.env`.

- [ ] **Step 3 : Commit**

```bash
git add docker-compose.staging.yml .env.staging.example
git commit -m "feat(staging): docker-compose staging NAS + template .env"
```

---

## Task 3 : Script de déploiement (hook git)

**Files:**
- Create: `scripts/deploy-hook.sh`

- [ ] **Step 1 : Créer le hook post-receive**

```bash
#!/bin/bash
# scripts/deploy-hook.sh
# À installer sur le NAS comme hook post-receive du bare repo
set -e

DEPLOY_DIR="/volume1/docker/svs-staging"
GIT_WORK_TREE="$DEPLOY_DIR"
COMPOSE_FILE="$DEPLOY_DIR/docker-compose.staging.yml"

while read oldrev newrev ref; do
    if [[ "$ref" == "refs/heads/staging" ]]; then
        echo "==> Push sur staging détecté, déploiement..."

        # Vérifier que .env est présent avant de continuer
        if [[ ! -f "$DEPLOY_DIR/.env" ]]; then
            echo "ERREUR : $DEPLOY_DIR/.env manquant. Créer le fichier avant de déployer."
            exit 1
        fi

        # Extraire les fichiers dans le répertoire de déploiement
        # Chemin absolu car $HOME peut être vide dans le contexte git hook
        git --work-tree="$GIT_WORK_TREE" --git-dir="/home/jurgen/repos/svs-accounting.git" checkout -f staging

        cd "$DEPLOY_DIR"

        # Rebuild et restart
        docker compose -f "$COMPOSE_FILE" build app
        docker compose -f "$COMPOSE_FILE" up -d

        # Migrations (--force requis en non-production interactive)
        docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force

        # Vider les caches (la config a peut-être changé)
        docker compose -f "$COMPOSE_FILE" exec -T app php artisan config:cache
        docker compose -f "$COMPOSE_FILE" exec -T app php artisan route:cache
        docker compose -f "$COMPOSE_FILE" exec -T app php artisan view:cache

        echo "==> Déploiement staging terminé."
    fi
done
```

- [ ] **Step 2 : Rendre le script exécutable et commiter**

```bash
chmod +x scripts/deploy-hook.sh
git add scripts/deploy-hook.sh
git commit -m "feat(staging): hook post-receive pour déploiement automatique"
```

---

## Task 4 : Mise en place du bare repo sur le NAS

Ces commandes s'exécutent **sur le NAS** (via SSH depuis le Mac).

- [ ] **Step 1 : Créer le bare repo et le répertoire de déploiement**

```bash
ssh -p 2022 jurgen@NAS_IP "
  mkdir -p ~/repos/svs-accounting.git
  git -C ~/repos/svs-accounting.git init --bare
  mkdir -p /volume1/docker/svs-staging
"
```

- [ ] **Step 2 : Installer le hook post-receive**

```bash
# Copier le hook depuis le Mac
scp -P 2022 scripts/deploy-hook.sh jurgen@NAS_IP:~/repos/svs-accounting.git/hooks/post-receive
# Le rendre exécutable
ssh -p 2022 jurgen@NAS_IP "chmod +x ~/repos/svs-accounting.git/hooks/post-receive"
```

- [ ] **Step 3 : Copier .env sur le NAS**

```bash
# Copier le template, puis l'éditer sur le NAS
scp -P 2022 .env.staging.example jurgen@NAS_IP:/volume1/docker/svs-staging/.env
ssh -p 2022 jurgen@NAS_IP "nano /volume1/docker/svs-staging/.env"
# Remplir : APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD, APP_URL
```

Pour générer APP_KEY sans que l'app tourne :
```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

- [ ] **Step 4 : Ajouter la remote NAS sur le Mac**

```bash
git remote add nas ssh://jurgen@NAS_IP:2022/home/jurgen/repos/svs-accounting.git
# Vérifier
git remote -v
```

- [ ] **Step 5 : Créer la branche staging et pousser**

```bash
git checkout -b staging
git push nas staging
# Observer la sortie du hook — doit se terminer par "Déploiement staging terminé."
```

- [ ] **Step 6 : Vérifier l'application**

Ouvrir http://NAS_IP:8080 dans le navigateur.
Vérifier les logs si problème :
```bash
ssh -p 2022 jurgen@NAS_IP "docker compose -f /volume1/docker/svs-staging/docker-compose.staging.yml logs --tail=50"
```

---

## Task 5 : Script clone prod → staging

**Files:**
- Create: `scripts/clone-prod.sh`
- Create: `scripts/anonymize-tiers.sql`

- [ ] **Step 1 : Créer le SQL d'anonymisation**

```sql
-- scripts/anonymize-tiers.sql
-- Anonymise les données personnelles de la table tiers
-- Conserve le type (entreprise/particulier) et les flags fonctionnels
UPDATE tiers SET
    nom = CASE type
        WHEN 'entreprise' THEN CONCAT('Société ', id)
        ELSE CONCAT('Tiers ', id)
    END,
    prenom = NULL,
    email = NULL,
    telephone = NULL,
    adresse = NULL;
```

- [ ] **Step 2 : Créer le script de clone**

```bash
#!/bin/bash
# scripts/clone-prod.sh
# Clone la base MySQL de prod (O2Switch) vers le staging (NAS)
# Usage : ./scripts/clone-prod.sh
set -e

# ── Configuration ─────────────────────────────────────────────────────────────
O2_HOST="votre-compte.o2switch.net"      # à adapter
O2_USER="votre_user_ssh"                  # à adapter
O2_DB="votre_base_mysql"                  # à adapter
O2_DB_USER="votre_user_mysql"             # à adapter
# O2_DB_PASS : à définir dans ~/.clone-prod.conf (voir ci-dessous)

NAS_IP="NAS_IP"                           # à adapter
NAS_PORT=2022
NAS_USER="jurgen"
NAS_DEPLOY="/volume1/docker/svs-staging"
# NAS_DB_PASS : à définir dans ~/.clone-prod.conf

DUMP_FILE="/tmp/svs_prod_$(date +%Y%m%d_%H%M%S).sql"

# ── Charger les mots de passe depuis un fichier local (jamais dans le repo) ──
CONF_FILE="$HOME/.clone-prod.conf"
if [[ ! -f "$CONF_FILE" ]]; then
    echo "Erreur : $CONF_FILE manquant."
    echo "Créer ce fichier avec :"
    echo "  O2_DB_PASS=votre_mot_de_passe_mysql"
    echo "  NAS_DB_PASS=votre_mot_de_passe_staging"
    exit 1
fi
source "$CONF_FILE"

echo "==> [1/5] Dump MySQL sur O2Switch..."
# Utilise un fichier .my.cnf temporaire pour éviter que le mot de passe
# apparaisse dans `ps aux` sur le serveur distant
ssh "$O2_USER@$O2_HOST" "
    printf '[client]\nuser=%s\npassword=%s\n' '$O2_DB_USER' '$O2_DB_PASS' > /tmp/.my_dump.cnf
    chmod 600 /tmp/.my_dump.cnf
    mysqldump --defaults-extra-file=/tmp/.my_dump.cnf '$O2_DB' > /tmp/svs_prod_dump.sql
    rm -f /tmp/.my_dump.cnf
"

echo "==> [2/5] Rapatriement du dump sur le Mac..."
scp "$O2_USER@$O2_HOST:/tmp/svs_prod_dump.sql" "$DUMP_FILE"

echo "==> [3/5] Transfert vers le NAS..."
scp -P "$NAS_PORT" "$DUMP_FILE" "$NAS_USER@$NAS_IP:/tmp/svs_prod_dump.sql"

echo "==> [4/5] Import sur la base staging..."
ssh -p "$NAS_PORT" "$NAS_USER@$NAS_IP" "
    docker compose -f $NAS_DEPLOY/docker-compose.staging.yml exec -T db \
        mysql -u svs -p'$NAS_DB_PASS' svs_staging < /tmp/svs_prod_dump.sql
"

echo "==> [5/5] Anonymisation des tiers..."
# Copier le SQL sur le NAS, puis l'exécuter là-bas (le redirect < doit être côté NAS)
scp -P "$NAS_PORT" scripts/anonymize-tiers.sql "$NAS_USER@$NAS_IP:/tmp/anonymize-tiers.sql"
ssh -p "$NAS_PORT" "$NAS_USER@$NAS_IP" \
    "docker compose -f $NAS_DEPLOY/docker-compose.staging.yml exec -T db \
        mysql -u svs -p'$NAS_DB_PASS' svs_staging < /tmp/anonymize-tiers.sql"

echo "==> Nettoyage..."
ssh "$O2_USER@$O2_HOST" "rm -f /tmp/svs_prod_dump.sql"
ssh -p "$NAS_PORT" "$NAS_USER@$NAS_IP" "rm -f /tmp/svs_prod_dump.sql /tmp/anonymize-tiers.sql"
rm -f "$DUMP_FILE"

echo ""
echo "✓ Clone prod → staging terminé et anonymisé."
echo "  Staging : http://$NAS_IP:8080"
```

- [ ] **Step 3 : Créer ~/.clone-prod.conf sur le Mac (jamais committé)**

```bash
cat > ~/.clone-prod.conf << 'EOF'
O2_DB_PASS=VOTRE_MOT_DE_PASSE_MYSQL_PROD
NAS_DB_PASS=VOTRE_MOT_DE_PASSE_STAGING
EOF
chmod 600 ~/.clone-prod.conf
```

- [ ] **Step 4 : Rendre le script exécutable, ajouter .conf au .gitignore**

```bash
chmod +x scripts/clone-prod.sh

# Vérifier que .gitignore contient bien :
grep -q "\.clone-prod\.conf" .gitignore || echo ".clone-prod.conf" >> .gitignore
```

- [ ] **Step 5 : Commit**

```bash
git add scripts/clone-prod.sh scripts/anonymize-tiers.sql
git commit -m "feat(staging): script clone prod→staging avec anonymisation tiers"
```

- [ ] **Step 6 : Tester le clone**

```bash
./scripts/clone-prod.sh
# Observer les 5 étapes
# Vérifier sur staging que les données sont là et les tiers anonymisés
```

Sur le staging, vérifier via l'interface que :
- Les transactions, recettes, dépenses sont présentes
- Les noms de tiers sont bien "Tiers 1", "Tiers 2", "Société 3", etc.
- Les emails/téléphones/adresses sont à NULL

---

## Flux quotidien résumé

```
# Développer sur main comme d'habitude
git checkout main
# ... code + tests sur localhost ...

# Passer en staging pour valider sur données réelles
git checkout staging
git merge main
git push nas staging          # → déploiement automatique sur NAS

# Valider sur http://NAS_IP:8080

# Si tout est bon → push prod comme d'habitude
git checkout main
git push origin main          # → GitHub Actions → O2Switch
```

```
# Rafraîchir les données staging depuis la prod
./scripts/clone-prod.sh
```

---

## Notes importantes

- **`.env` sur le NAS** : ne jamais le commiter, le gérer manuellement sur le NAS
- **`~/.clone-prod.conf`** : fichier local sur le Mac uniquement, chmod 600
- **Port 8080** : configurer le reverse proxy Synology sur ce port si accès externe souhaité
- **Migrations** : le hook les lance automatiquement à chaque déploiement (`--force` requis hors interactif)
- **APP_KEY staging** : différent de la prod, générer une clé dédiée
