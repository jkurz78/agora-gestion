#!/usr/bin/env bash
#
# clone-prod-to-preprod.sh — Clone la base de données de prod vers staging (NAS)
#
# Reference: docs/specs/2026-05-19-fondations-partie-double-slice1.md §16.4
#
# À exécuter depuis l'hôte NAS staging. Restaure un dump de la prod dans la base
# preprod, anonymise, neutralise les secrets, migre, et fait un smoke-check.
#
# Le NAS n'a aucun accès SSH à la production. Le dump est donc normalement
# fabriqué depuis le poste de l'opérateur — qui atteint les deux — puis désigné
# par --dump. La récupération intégrée (PREPROD_SSH_HOST) ne sert qu'à un hôte
# qui aurait cet accès.
#
# Credentials lus depuis des variables d'environnement — jamais hardcodés.
# Toujours requis :
#   PREPROD_DB_HOST  PREPROD_DB_USER  PREPROD_DB_PASS  PREPROD_DB_NAME
# Requis seulement sans --dump :
#   PROD_DB_HOST  PROD_DB_USER  PROD_DB_PASS  PROD_DB_NAME  PREPROD_SSH_HOST
#
# Usage :
#   ./scripts/clone-prod-to-preprod.sh --dump=/tmp/prod-dump.sql.gz
#   ./scripts/clone-prod-to-preprod.sh --dry-run   # affiche sans exécuter

set -eo pipefail

# ---------------------------------------------------------------------------
# Dry-run flag
# ---------------------------------------------------------------------------

DRY_RUN=0
DUMP_FOURNI=""

for arg in "$@"; do
    case "${arg}" in
        --dry-run)
            DRY_RUN=1
            echo "[dry-run] Mode dry-run activé — aucune commande réelle exécutée."
            ;;
        --dump=*)
            DUMP_FOURNI="${arg#--dump=}"
            ;;
        *)
            echo "ERREUR : option inconnue : ${arg}" >&2
            echo "Options : --dry-run, --dump=<fichier.sql.gz>" >&2
            exit 1
            ;;
    esac
done

# ---------------------------------------------------------------------------
# Garde : vérifier que toutes les variables d'environnement sont définies
# En mode dry-run, la garde est skippée (aucune commande réelle exécutée).
# ---------------------------------------------------------------------------

required_vars=(
    PREPROD_DB_HOST
    PREPROD_DB_USER
    PREPROD_DB_PASS
    PREPROD_DB_NAME
)

# Le dump n'est récupéré par ce script que s'il ne lui est pas fourni.
if [[ -z "${DUMP_FOURNI}" ]]; then
    required_vars+=(PROD_DB_HOST PROD_DB_USER PROD_DB_PASS PROD_DB_NAME PREPROD_SSH_HOST)
fi

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
# Le SQL passe par le conteneur, jamais par l'hôte.
#
# L'hôte NAS a `ssh` mais aucun client MySQL ; le conteneur `db` a le client
# mais pas `ssh`. Ce script appelait les deux nus dans le même shell, ce qu'aucune
# des deux machines ne peut honorer — constaté le 2026-08-02, aucun
# storage/logs/clone-prod-*.log n'ayant jamais été écrit sur la préprod.
#
# Même patron que clone-prod-to-localhost.sh, qui tourne depuis des mois : le
# dump arrive par `ssh` côté hôte et entre dans la base par un tuyau vers le
# client du conteneur. MariaDB 11 ne fournit plus l'alias `mysql` : on appelle
# `mariadb` et `mariadb-admin` par leur nom.
# ---------------------------------------------------------------------------

COMPOSE_FILE="${COMPOSE_FILE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/docker-compose.staging.yml}"
DOCKER_BIN="${DOCKER_BIN:-/usr/local/bin/docker}"
DC="${DOCKER_BIN} compose -f ${COMPOSE_FILE}"

# Client SQL de la préprod, exécuté dans le conteneur db.
preprod_sql() {
    echo "${DC} exec -T db mariadb -h '${PREPROD_DB_HOST}' -u '${PREPROD_DB_USER}' -p'${PREPROD_DB_PASS}'"
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

# Le NAS n'a aucun accès SSH à la prod — ni clé, ni known_hosts : ce Step 1 n'y a
# jamais pu s'exécuter. Le poste de l'opérateur, lui, atteint les deux ; il peut
# donc pousser le dump ici et le désigner par --dump, plutôt que d'ouvrir un
# accès à la production depuis le NAS.
DUMP="/tmp/prod-dump.sql.gz"

if [[ -n "${DUMP_FOURNI}" ]]; then
    DUMP="${DUMP_FOURNI}"
    echo "[$(date)] Step 1 : dump fourni (${DUMP}) — aucune récupération"
    if [[ "${DRY_RUN}" -eq 0 && ! -s "${DUMP}" ]]; then
        echo "ERREUR : dump introuvable ou vide : ${DUMP}" >&2
        exit 1
    fi
else
    echo "[$(date)] Step 1 : mysqldump prod ${PROD_DB_NAME} via SSH"
    run "ssh ${PREPROD_SSH_HOST} \"mysqldump --single-transaction --skip-lock-tables -h ${PROD_DB_HOST} -u ${PROD_DB_USER} -p'${PROD_DB_PASS}' ${PROD_DB_NAME} | gzip\" > ${DUMP}"
fi

# ---------------------------------------------------------------------------
# Step 2 : Restauration dans la base preprod
# ---------------------------------------------------------------------------

echo "[$(date)] Step 2 : Restauration dans preprod ${PREPROD_DB_NAME}"
run "$(preprod_sql) -e \"DROP DATABASE IF EXISTS ${PREPROD_DB_NAME}; CREATE DATABASE ${PREPROD_DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""
run "gunzip < ${DUMP} | $(preprod_sql) '${PREPROD_DB_NAME}'"

# ---------------------------------------------------------------------------
# Step 3 : Anonymisation des données tiers
# ---------------------------------------------------------------------------

echo "[$(date)] Step 3 : Anonymisation tiers (scripts/anonymize-tiers.sql)"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
run "$(preprod_sql) '${PREPROD_DB_NAME}' < '${SCRIPT_DIR}/anonymize-tiers.sql'"

# ---------------------------------------------------------------------------
# Step 3b : Champs chiffrés — les valeurs viennent de la prod, chiffrées avec la
#           clé APP de PRODUCTION. La préprod a sa propre APP_KEY : toute lecture
#           lève `DecryptException: The MAC is invalid.` et l'écran part en 500.
#
#           Constaté le 2026-07-29 sur la préprod NAS : l'écran Recettes/dépenses
#           appelait InvoiceOcrService::isConfigured(), qui lit le champ chiffré
#           association.anthropic_api_key → 500, sans rapport avec la compta.
#
#           Politique : les SECRETS applicatifs n'ont rien à faire sur une
#           préprod — on les efface systématiquement. Les DONNÉES chiffrées
#           (présences, données médicales) ne sont récupérables qu'avec la clé de
#           prod ; on ne les re-chiffre que si PROD_APP_KEY est fournie.
#
#           `smtp_parametres` est en outre DÉSACTIVÉE, et pas seulement vidée.
#           BootTenantConfig écrase la configuration mail dès qu'une ligne existe
#           et qu'elle est active : une ligne active au mot de passe nul ne rend
#           pas la préprod muette, elle la fait échouer à l'authentification SMTP
#           — donc plus de code 2FA, donc plus de connexion possible. Désactivée,
#           la préprod retombe sur le mailer de son propre .env, qui lui
#           fonctionne. Aucune préprod ne doit dépendre du SMTP de la prod.
# ---------------------------------------------------------------------------

echo "[$(date)] Step 3b : neutralisation des secrets applicatifs chiffrés"
run "$(preprod_sql) '${PREPROD_DB_NAME}' -e \"
    UPDATE association SET anthropic_api_key = NULL;
    UPDATE helloasso_parametres SET client_secret = NULL, callback_token = NULL;
    UPDATE incoming_mail_parametres SET imap_password = NULL;
    UPDATE smtp_parametres SET smtp_password = NULL, enabled = 0;
    UPDATE users SET two_factor_secret = NULL, two_factor_recovery_codes = NULL;\""

if [[ -n "${PROD_APP_KEY:-}" ]]; then
    echo "[$(date)] Step 3b : PROD_APP_KEY fournie → re-chiffrement des données"
    run "${DC} exec -T app php artisan staging:rekey-encrypted"
else
    echo "[$(date)] Step 3b : PROD_APP_KEY absente — présences et données médicales"
    echo "           restent chiffrées avec la clé de prod. Les écrans qui les"
    echo "           lisent (présences, émargement, fiches médicales) tomberont en"
    echo "           500. Sans effet sur la recette comptable. Pour les récupérer :"
    echo "           PROD_APP_KEY=… php artisan staging:rekey-encrypted"
fi

# ---------------------------------------------------------------------------
# Step 4 : Migrations Laravel
# ---------------------------------------------------------------------------

echo "[$(date)] Step 4 : php artisan migrate --force"
run "${DC} exec -T app php artisan migrate --force"

# ---------------------------------------------------------------------------
# Step 5 : Smoke-check DB
# ---------------------------------------------------------------------------

echo "[$(date)] Step 5 : vérification de connectivité preprod"
run "$(preprod_sql) '${PREPROD_DB_NAME}' -e \"SELECT COUNT(*) FROM association;\""

# ---------------------------------------------------------------------------
# Nettoyage
# ---------------------------------------------------------------------------

if [[ -n "${DUMP_FOURNI}" ]]; then
    echo "[$(date)] Nettoyage : dump fourni par l'opérateur, conservé (${DUMP})"
else
    echo "[$(date)] Nettoyage : suppression du dump temporaire"
    run "rm -f ${DUMP}"
fi

echo "[$(date)] clone-prod-to-preprod terminé avec succès."
if [[ "${DRY_RUN}" -eq 0 ]]; then
    echo "[$(date)] Log sauvegardé dans ${LOG_FILE}"
fi
