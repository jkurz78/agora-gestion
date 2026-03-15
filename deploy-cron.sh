#!/bin/bash

# Script de déploiement automatique — appelé par le cron O2Switch toutes les 5 minutes.
# Vérifie si un nouveau commit est disponible sur origin/main et déploie si oui.

APPDIR="/home/nqgu6487/public_html/compta.soigner-vivre-sourire.fr"
LOGFILE="${APPDIR}/deploy.log"
PHP="/usr/local/bin/php"
COMPOSER="HOME=/home/nqgu6487 /opt/cpanel/composer/bin/composer"

cd "$APPDIR" || exit 1

# Récupérer les dernières infos depuis GitHub (sans modifier le working tree)
git fetch origin main >> "$LOGFILE" 2>&1

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)

if [ "$LOCAL" = "$REMOTE" ]; then
    exit 0
fi

# Nouveau commit détecté — déploiement
echo "" >> "$LOGFILE"
echo "============================================================" >> "$LOGFILE"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Déploiement démarré (${LOCAL:0:7} → ${REMOTE:0:7})" >> "$LOGFILE"

run_cmd() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] \$ $1" >> "$LOGFILE"
    eval "$1" >> "$LOGFILE" 2>&1
    local code=$?
    echo "Exit code: $code" >> "$LOGFILE"
    if [ $code -ne 0 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ÉCHEC — déploiement interrompu" >> "$LOGFILE"
        exit 1
    fi
}

run_cmd "$PHP artisan optimize:clear"
run_cmd "git pull origin main"
run_cmd "$COMPOSER install --no-dev --optimize-autoloader --no-interaction"
run_cmd "$PHP artisan migrate --force"
run_cmd "$PHP artisan config:cache"
run_cmd "$PHP artisan route:cache"
run_cmd "$PHP artisan view:cache"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Déploiement terminé avec succès" >> "$LOGFILE"
