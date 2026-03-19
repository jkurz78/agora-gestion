#!/bin/bash
# clone-prod.sh — Cloner la base de production (O2Switch) vers le staging (NAS)
#
# Prérequis :
#   - ~/.ssh/config configuré avec les alias 'o2switch' et 'nas'
#   - Votre IP whitelistée sur O2Switch (cPanel → SSH Access)
#   - Le staging Docker démarré sur le NAS
#
# Usage : ./scripts/clone-prod.sh

set -euo pipefail

PROD_APP_DIR="/home/nqgu6487/public_html/compta.soigner-vivre-sourire.fr"
NAS_COMPOSE="/volume1/docker/svs-staging/docker-compose.staging.yml"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Lecture des credentials ──────────────────────────────────────────────────
echo "==> Lecture des credentials MySQL de prod..."
PROD_ENV=$(ssh o2switch "cat $PROD_APP_DIR/.env")

DB_DATABASE=$(echo "$PROD_ENV" | grep '^DB_DATABASE=' | cut -d= -f2 | tr -d '"' | tr -d "'")
DB_USERNAME=$(echo "$PROD_ENV" | grep '^DB_USERNAME=' | cut -d= -f2 | tr -d '"' | tr -d "'")
DB_PASSWORD=$(echo "$PROD_ENV" | grep '^DB_PASSWORD=' | cut -d= -f2 | tr -d '"' | tr -d "'")
DB_HOST=$(echo "$PROD_ENV" | grep '^DB_HOST=' | cut -d= -f2 | tr -d '"' | tr -d "'")
DB_HOST="${DB_HOST:-127.0.0.1}"

if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" ]]; then
    echo "ERREUR : impossible de lire les credentials MySQL depuis la prod."
    exit 1
fi
echo "    DB : $DB_DATABASE@$DB_HOST (utilisateur : $DB_USERNAME)"

echo "==> Lecture des credentials MySQL staging..."
STAGING_ENV=$(ssh nas "cat /volume1/docker/svs-staging/.env")

STAGING_DB=$(echo "$STAGING_ENV" | grep '^DB_DATABASE=' | cut -d= -f2 | tr -d '"' | tr -d "'")
STAGING_ROOT_PASS=$(echo "$STAGING_ENV" | grep '^DB_ROOT_PASSWORD=' | cut -d= -f2 | tr -d '"' | tr -d "'")
echo "    DB staging : $STAGING_DB"

# ── Dump prod → import staging ───────────────────────────────────────────────
echo "==> Dump de la base de production en cours..."
echo "    (transfert direct O2Switch → NAS, pas de fichier temporaire)"

ssh o2switch "mysqldump \
    --host=$DB_HOST \
    --user=$DB_USERNAME \
    --password='$DB_PASSWORD' \
    --single-transaction \
    --skip-lock-tables \
    --no-tablespaces \
    $DB_DATABASE" \
| ssh nas "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T db \
    mariadb --user=root --password='$STAGING_ROOT_PASS' $STAGING_DB"

echo "==> Import terminé."

# ── Anonymisation ────────────────────────────────────────────────────────────
echo "==> Anonymisation des données personnelles..."
cat "$SCRIPT_DIR/anonymize-tiers.sql" \
| ssh nas "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T db \
    mariadb --user=root --password='$STAGING_ROOT_PASS' $STAGING_DB"

echo "==> Anonymisation terminée."

# ── Vider les caches Laravel ─────────────────────────────────────────────────
echo "==> Vidage des caches Laravel..."
ssh nas "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T app php artisan cache:clear"
ssh nas "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T app php artisan config:cache"

echo ""
echo "Clone termine. Base staging prete sur http://dog.local:8082"
echo "  Connexion avec vos credentials de prod (tiers anonymises, comptes inchanges)"
