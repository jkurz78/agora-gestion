#!/bin/bash
# clone-prod.sh — Cloner la base de production (O2Switch) vers le staging (NAS)
#
# Prérequis :
#   - Votre IP doit être whitelistée sur O2Switch (manuellement via cPanel SSH Access)
#   - La clé SSH de déploiement doit être disponible en local
#   - Le staging Docker doit être démarré sur le NAS
#
# Usage : ./scripts/clone-prod.sh
#
# Variables d'environnement (ou à éditer ci-dessous) :
#   PROD_SSH_USER  — identifiant cPanel O2Switch
#   PROD_SSH_HOST  — hostname du serveur O2Switch
#   PROD_SSH_KEY   — chemin vers la clé SSH de déploiement

set -euo pipefail

PROD_SSH_USER="${PROD_SSH_USER:-feucherolles}"
PROD_SSH_HOST="${PROD_SSH_HOST:-}"
PROD_SSH_KEY="${PROD_SSH_KEY:-$HOME/.ssh/id_ed25519_svs_deploy}"
PROD_APP_DIR="/home/$PROD_SSH_USER/public_html/***DEPLOY_SUBDOMAIN***"

NAS_SSH_HOST="dog.local"
NAS_SSH_PORT="2022"
NAS_SSH_USER="jurgen"
NAS_COMPOSE="/volume1/docker/svs-staging/docker-compose.staging.yml"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Vérifications préalables ────────────────────────────────────────────────
if [[ -z "$PROD_SSH_HOST" ]]; then
    echo "ERREUR : PROD_SSH_HOST non défini."
    echo "Exemple : PROD_SSH_HOST=ssh.o2switch.net ./scripts/clone-prod.sh"
    exit 1
fi

if [[ ! -f "$PROD_SSH_KEY" ]]; then
    echo "ERREUR : clé SSH introuvable : $PROD_SSH_KEY"
    exit 1
fi

SSH_PROD="ssh -i $PROD_SSH_KEY -o StrictHostKeyChecking=no $PROD_SSH_USER@$PROD_SSH_HOST"
SSH_NAS="ssh -p $NAS_SSH_PORT $NAS_SSH_USER@$NAS_SSH_HOST"

echo "==> Lecture des credentials MySQL de prod..."
PROD_ENV=$($SSH_PROD "cat $PROD_APP_DIR/.env")

DB_DATABASE=$(echo "$PROD_ENV" | grep '^DB_DATABASE=' | cut -d= -f2 | tr -d '"' | tr -d "'")
DB_USERNAME=$(echo "$PROD_ENV" | grep '^DB_USERNAME=' | cut -d= -f2 | tr -d '"' | tr -d "'")
DB_PASSWORD=$(echo "$PROD_ENV" | grep '^DB_PASSWORD=' | cut -d= -f2 | tr -d '"' | tr -d "'")
DB_HOST=$(echo "$PROD_ENV" | grep '^DB_HOST=' | cut -d= -f2 | tr -d '"' | tr -d "'" )
DB_HOST="${DB_HOST:-127.0.0.1}"

if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" ]]; then
    echo "ERREUR : impossible de lire les credentials MySQL depuis la prod."
    exit 1
fi

echo "    DB : $DB_DATABASE@$DB_HOST (utilisateur : $DB_USERNAME)"

# ── Lecture des credentials MySQL staging ───────────────────────────────────
echo "==> Lecture des credentials MySQL staging..."
STAGING_ENV=$($SSH_NAS "cat /volume1/docker/svs-staging/.env")

STAGING_DB=$(echo "$STAGING_ENV" | grep '^DB_DATABASE=' | cut -d= -f2 | tr -d '"' | tr -d "'")
STAGING_USER=$(echo "$STAGING_ENV" | grep '^DB_USERNAME=' | cut -d= -f2 | tr -d '"' | tr -d "'")
STAGING_PASS=$(echo "$STAGING_ENV" | grep '^DB_PASSWORD=' | cut -d= -f2 | tr -d '"' | tr -d "'")
STAGING_ROOT_PASS=$(echo "$STAGING_ENV" | grep '^DB_ROOT_PASSWORD=' | cut -d= -f2 | tr -d '"' | tr -d "'")

echo "    DB staging : $STAGING_DB (utilisateur : $STAGING_USER)"

# ── Dump prod → import staging ───────────────────────────────────────────────
echo "==> Dump de la base de production en cours..."
echo "    (transfert direct O2Switch → NAS, pas de fichier temporaire)"

$SSH_PROD "mysqldump \
    --host=$DB_HOST \
    --user=$DB_USERNAME \
    --password='$DB_PASSWORD' \
    --single-transaction \
    --skip-lock-tables \
    --no-tablespaces \
    $DB_DATABASE" \
| $SSH_NAS "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T db \
    mysql --user=root --password='$STAGING_ROOT_PASS' $STAGING_DB"

echo "==> Import terminé."

# ── Anonymisation ────────────────────────────────────────────────────────────
echo "==> Anonymisation des données personnelles..."
cat "$SCRIPT_DIR/anonymize-tiers.sql" \
| $SSH_NAS "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T db \
    mysql --user=root --password='$STAGING_ROOT_PASS' $STAGING_DB"

echo "==> Anonymisation terminée."

# ── Vider les caches Laravel ─────────────────────────────────────────────────
echo "==> Vidage des caches Laravel..."
$SSH_NAS "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T app php artisan cache:clear"
$SSH_NAS "/usr/local/bin/docker compose -f $NAS_COMPOSE exec -T app php artisan config:cache"

echo ""
echo "✓ Clone terminé. Base staging prête sur http://dog.local:8082"
echo "  Compte admin : admin@svs.fr / (mot de passe de prod, inchangé)"
