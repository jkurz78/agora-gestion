#!/bin/bash
# Hook git post-receive — à installer sur le NAS
# Chemin : ~/repos/svs-accounting.git/hooks/post-receive
set -e

DEPLOY_DIR="/volume1/docker/svs-staging"
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
        git --work-tree="$DEPLOY_DIR" --git-dir="/home/jurgen/repos/svs-accounting.git" checkout -f staging

        cd "$DEPLOY_DIR"

        # Rebuild et restart
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" build app
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" up -d

        # Migrations
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force

        # Vider les caches
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan config:cache
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan route:cache
        /usr/local/bin/docker compose -f "$COMPOSE_FILE" exec -T app php artisan view:cache

        echo "==> Déploiement staging terminé."
    fi
done
