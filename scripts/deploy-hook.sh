#!/bin/bash
# Hook git post-receive — à installer sur le NAS
# Chemin : ~/repos/agora-gestion.git/hooks/post-receive
set -e

DEPLOY_DIR="/volume1/docker/agora-staging"
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
        git --work-tree="$DEPLOY_DIR" --git-dir="***NAS_HOME***/repos/agora-gestion.git" checkout -f staging

        # Auto-mise à jour du hook lui-même (en premier, avant tout le reste)
        cp "$DEPLOY_DIR/scripts/deploy-hook.sh" "***NAS_HOME***/repos/agora-gestion.git/hooks/post-receive"
        chmod +x "***NAS_HOME***/repos/agora-gestion.git/hooks/post-receive"

        cd "$DEPLOY_DIR"

        # Stamper la version avant le build (git n'est pas dispo dans le container)
        _RAW_TAG=$(/usr/local/bin/git --git-dir="***NAS_HOME***/repos/agora-gestion.git" describe --tags 2>/dev/null || echo '')
        GIT_TAG=$([[ "$_RAW_TAG" =~ ^v[0-9] ]] && echo "$_RAW_TAG" || echo 'staging')
        GIT_DATE=$(/usr/local/bin/git --git-dir="***NAS_HOME***/repos/agora-gestion.git" log -1 --format=%as 2>/dev/null || date +%Y-%m-%d)
        GIT_YEAR=$(echo "$GIT_DATE" | cut -c1-4)
        printf "<?php\nreturn array (\n  'tag' => '%s',\n  'date' => '%s',\n  'year' => '%s',\n);\n" \
            "$GIT_TAG" "$GIT_DATE" "$GIT_YEAR" > "$DEPLOY_DIR/config/version.php"

        # Rebuild et restart
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" build app
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" up -d

        # Attendre que MariaDB soit prêt avant les migrations
        echo "==> Attente de MariaDB..."
        until /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T db mariadb-admin ping -h localhost --silent 2>/dev/null; do
            sleep 3
        done
        echo "==> MariaDB prêt."

        # Migrations
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force

        # Vider les caches
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan config:cache
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan route:cache
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan view:cache

        echo "==> Déploiement staging terminé."
    fi
done
