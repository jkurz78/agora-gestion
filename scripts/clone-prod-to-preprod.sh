#!/usr/bin/env bash
#
# clone-prod-to-preprod.sh — Clone la base de données de prod vers staging (NAS)
#
# Reference: docs/specs/2026-05-19-fondations-partie-double-slice1.md §16.4
#
# À exécuter depuis l'hôte NAS staging. Récupère un dump MySQL de la prod
# O2Switch via SSH, le restaure dans la base preprod, lance l'anonymisation,
# les migrations, et un smoke-check minimal.
#
# Credentials lus depuis des variables d'environnement — jamais hardcodés.
# Variables requises :
#   PROD_DB_HOST      PREPROD_DB_HOST
#   PROD_DB_USER      PREPROD_DB_USER
#   PROD_DB_PASS      PREPROD_DB_PASS
#   PROD_DB_NAME      PREPROD_DB_NAME
#   PREPROD_SSH_HOST
#
# Usage :
#   ./scripts/clone-prod-to-preprod.sh
#   ./scripts/clone-prod-to-preprod.sh --dry-run   # affiche sans exécuter

set -eo pipefail

# ---------------------------------------------------------------------------
# Dry-run flag
# ---------------------------------------------------------------------------

DRY_RUN=0
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=1
    echo "[dry-run] Mode dry-run activé — aucune commande réelle exécutée."
fi

# ---------------------------------------------------------------------------
# Garde : vérifier que toutes les variables d'environnement sont définies
# En mode dry-run, la garde est skippée (aucune commande réelle exécutée).
# ---------------------------------------------------------------------------

required_vars=(
    PROD_DB_HOST
    PROD_DB_USER
    PROD_DB_PASS
    PROD_DB_NAME
    PREPROD_DB_HOST
    PREPROD_DB_USER
    PREPROD_DB_PASS
    PREPROD_DB_NAME
    PREPROD_SSH_HOST
)

if [[ "${DRY_RUN}" -eq 0 ]]; then
    for var in "${required_vars[@]}"; do
        if [[ -z "${!var:-}" ]]; then
            echo "ERREUR : variable d'environnement manquante : ${var}" >&2
            echo "Définissez toutes les variables requises avant d'exécuter ce script." >&2
            exit 1
        fi
    done
else
    echo "[dry-run] Garde env vars skippée (mode dry-run)."
    # Initialiser avec des placeholders pour que l'interpolation ne déclenche pas d'erreur
    PROD_DB_HOST="${PROD_DB_HOST:-<PROD_DB_HOST>}"
    PROD_DB_USER="${PROD_DB_USER:-<PROD_DB_USER>}"
    PROD_DB_PASS="${PROD_DB_PASS:-<PROD_DB_PASS>}"
    PROD_DB_NAME="${PROD_DB_NAME:-<PROD_DB_NAME>}"
    PREPROD_DB_HOST="${PREPROD_DB_HOST:-<PREPROD_DB_HOST>}"
    PREPROD_DB_USER="${PREPROD_DB_USER:-<PREPROD_DB_USER>}"
    PREPROD_DB_PASS="${PREPROD_DB_PASS:-<PREPROD_DB_PASS>}"
    PREPROD_DB_NAME="${PREPROD_DB_NAME:-<PREPROD_DB_NAME>}"
    PREPROD_SSH_HOST="${PREPROD_SSH_HOST:-<PREPROD_SSH_HOST>}"
fi

# ---------------------------------------------------------------------------
# Helper d'exécution
# ---------------------------------------------------------------------------

run() {
    if [[ "${DRY_RUN}" -eq 1 ]]; then
        echo "would run: $*"
    else
        eval "$*"
    fi
}

# ---------------------------------------------------------------------------
# Logging (mode réel uniquement)
# ---------------------------------------------------------------------------

LOG_DIR="storage/logs"
LOG_FILE="${LOG_DIR}/clone-prod-$(date +%Y-%m-%d-%H%M%S).log"

if [[ "${DRY_RUN}" -eq 0 ]]; then
    mkdir -p "${LOG_DIR}"
    exec > >(tee -a "${LOG_FILE}") 2>&1
fi

echo "[$(date)] Début clone-prod-to-preprod"

# ---------------------------------------------------------------------------
# Step 1 : Dump de la DB prod via SSH
# ---------------------------------------------------------------------------

echo "[$(date)] Step 1 : mysqldump prod ${PROD_DB_NAME} via SSH"
run "ssh ${PREPROD_SSH_HOST} \"mysqldump --single-transaction --skip-lock-tables -h ${PROD_DB_HOST} -u ${PROD_DB_USER} -p'${PROD_DB_PASS}' ${PROD_DB_NAME} | gzip\" > /tmp/prod-dump.sql.gz"

# ---------------------------------------------------------------------------
# Step 2 : Restauration dans la base preprod
# ---------------------------------------------------------------------------

echo "[$(date)] Step 2 : Restauration dans preprod ${PREPROD_DB_NAME}"
run "mysql -h '${PREPROD_DB_HOST}' -u '${PREPROD_DB_USER}' -p'${PREPROD_DB_PASS}' -e \"DROP DATABASE IF EXISTS ${PREPROD_DB_NAME}; CREATE DATABASE ${PREPROD_DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
run "gunzip < /tmp/prod-dump.sql.gz | mysql -h '${PREPROD_DB_HOST}' -u '${PREPROD_DB_USER}' -p'${PREPROD_DB_PASS}' '${PREPROD_DB_NAME}'"

# ---------------------------------------------------------------------------
# Step 3 : Anonymisation des données tiers
# ---------------------------------------------------------------------------

echo "[$(date)] Step 3 : Anonymisation tiers (scripts/anonymize-tiers.sql)"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
run "mysql -h '${PREPROD_DB_HOST}' -u '${PREPROD_DB_USER}' -p'${PREPROD_DB_PASS}' '${PREPROD_DB_NAME}' < '${SCRIPT_DIR}/anonymize-tiers.sql'"

# ---------------------------------------------------------------------------
# Step 4 : Migrations Laravel
# ---------------------------------------------------------------------------

echo "[$(date)] Step 4 : php artisan migrate --force"
run "php artisan migrate --force"

# ---------------------------------------------------------------------------
# Step 5 : Smoke-check DB
# ---------------------------------------------------------------------------

echo "[$(date)] Step 5 : vérification de connectivité preprod"
run "mysql -h '${PREPROD_DB_HOST}' -u '${PREPROD_DB_USER}' -p'${PREPROD_DB_PASS}' '${PREPROD_DB_NAME}' -e \"SELECT COUNT(*) FROM associations;\""

# ---------------------------------------------------------------------------
# Nettoyage
# ---------------------------------------------------------------------------

echo "[$(date)] Nettoyage : suppression du dump temporaire"
run "rm -f /tmp/prod-dump.sql.gz"

echo "[$(date)] clone-prod-to-preprod terminé avec succès."
if [[ "${DRY_RUN}" -eq 0 ]]; then
    echo "[$(date)] Log sauvegardé dans ${LOG_FILE}"
fi
