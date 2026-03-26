#!/bin/bash

# Script de déploiement — appelé par GitHub Actions après chaque push sur main.

APPDIR="/home/nqgu6487/public_html/compta.soigner-vivre-sourire.fr"
LOGFILE="${APPDIR}/deploy.log"
PHP="/usr/local/bin/php"
COMPOSER="HOME=/home/nqgu6487 /usr/local/bin/composer"
MAILTO="jurgen.kurz@soigner-vivre-sourire.fr"

cd "$APPDIR" || exit 1
DEPLOY_START=$(date '+%Y-%m-%d %H:%M:%S')
SECTION_START=$(wc -l < "$LOGFILE")

echo "" >> "$LOGFILE"
echo "============================================================" >> "$LOGFILE"
echo "[${DEPLOY_START}] Déploiement démarré (${LOCAL:0:7} → ${REMOTE:0:7})" >> "$LOGFILE"

run_cmd() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] \$ $1" >> "$LOGFILE"
    eval "$1" >> "$LOGFILE" 2>&1
    local code=$?
    echo "Exit code: $code" >> "$LOGFILE"
    if [ $code -ne 0 ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] ÉCHEC — déploiement interrompu" >> "$LOGFILE"
        # Envoyer mail d'échec
        tail -n +"$SECTION_START" "$LOGFILE" | mail \
            -s "[SVS Accounting] ❌ Déploiement ÉCHOUÉ — $(date '+%d/%m/%Y %H:%M')" \
            "$MAILTO"
        exit 1
    fi
}

run_cmd "$PHP artisan optimize:clear"
run_cmd "git pull origin main"
run_cmd "git fetch --tags"
run_cmd "$COMPOSER install --no-dev --optimize-autoloader --no-interaction"
run_cmd "$PHP artisan migrate --force"
run_cmd "$PHP artisan storage:link --force"
run_cmd "$PHP artisan app:version-stamp"
run_cmd "$PHP artisan config:cache"
run_cmd "$PHP artisan route:cache"
run_cmd "$PHP artisan view:cache"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Déploiement terminé avec succès" >> "$LOGFILE"

# Envoyer mail de succès
tail -n +"$SECTION_START" "$LOGFILE" | mail \
    -s "[SVS Accounting] ✅ Déploiement réussi — $(date '+%d/%m/%Y %H:%M')" \
    "$MAILTO"
