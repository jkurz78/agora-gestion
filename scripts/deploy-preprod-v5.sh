#!/usr/bin/env bash
#
# deploy-preprod-v5.sh — Déploie feat/compta-v5 en preprod (NAS staging)
#
# Reference: docs/specs/2026-05-19-fondations-partie-double-slice1.md §16.5
#
# Séquence :
#   1. Clone la DB prod → preprod (via clone-prod-to-preprod.sh)
#   2. Migrations Laravel
#   3. Backfill partie double --dry-run (audit pré-backfill)
#   4. Backfill partie double réel (idempotent)
#   5. Active le feature flag COMPTA_USE_PARTIE_DOUBLE=true dans .env
#   6. Smoke-test final (compta:smoke-test-v5)
#
# Usage :
#   ./scripts/deploy-preprod-v5.sh
#   ./scripts/deploy-preprod-v5.sh --dry-run   # affiche sans exécuter

set -euo pipefail

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

DRY_RUN=0
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=1
    echo "[dry-run] Mode dry-run activé — aucune commande réelle exécutée."
fi

run() {
    local cmd="$*"
    if [[ "${DRY_RUN}" -eq 1 ]]; then
        echo "would run: ${cmd}"
    else
        eval "${cmd}"
    fi
}

# ---------------------------------------------------------------------------
# Step 1 : Clone DB prod → preprod
# ---------------------------------------------------------------------------

echo "[$(date)] Step 1 : clone-prod-to-preprod.sh ${1:-}"
if [[ "${DRY_RUN}" -eq 1 ]]; then
    echo "would run: ${SCRIPT_DIR}/clone-prod-to-preprod.sh --dry-run"
    # Simuler la sortie du sous-script en mode dry-run
    bash "${SCRIPT_DIR}/clone-prod-to-preprod.sh" --dry-run
else
    bash "${SCRIPT_DIR}/clone-prod-to-preprod.sh"
fi

# ---------------------------------------------------------------------------
# Step 2 : Migrations Laravel
# ---------------------------------------------------------------------------

echo "[$(date)] Step 2 : php artisan migrate --force"
run "php artisan migrate --force"

# ---------------------------------------------------------------------------
# Step 3 : Backfill partie double — dry-run (audit pré-backfill)
#          --all : tous les exercices ayant des transactions (y compris l'exercice
#          précédent dont les ENL expliquent le solde bancaire d'ouverture).
# ---------------------------------------------------------------------------

echo "[$(date)] Step 3 : compta:backfill-partie-double --all --dry-run (audit)"
run "php artisan compta:backfill-partie-double --all --dry-run"

# ---------------------------------------------------------------------------
# Step 4 : Backfill partie double réel (idempotent, tous exercices)
# ---------------------------------------------------------------------------

echo "[$(date)] Step 4 : compta:backfill-partie-double --all (backfill réel)"
run "php artisan compta:backfill-partie-double --all"

# ---------------------------------------------------------------------------
# Step 4b : Correctif OneShot — chèques de reprise déjà encaissés avant AgoraGestion.
#           Bascule 5112 → 512X les chèques pointés sur un rappro verrouillé mais jamais
#           passés par une remise bancaire (reprise d'historique). Idempotent.
# ---------------------------------------------------------------------------

echo "[$(date)] Step 4b : compta:corriger-cheques-reportes --dry-run (audit)"
run "php artisan compta:corriger-cheques-reportes --dry-run"

echo "[$(date)] Step 4b : compta:corriger-cheques-reportes (correctif réel)"
run "php artisan compta:corriger-cheques-reportes"

# ---------------------------------------------------------------------------
# Step 5 : Activer le feature flag COMPTA_USE_PARTIE_DOUBLE
# ---------------------------------------------------------------------------

echo "[$(date)] Step 5 : activation du feature flag COMPTA_USE_PARTIE_DOUBLE=true"
run "sed -i 's/COMPTA_USE_PARTIE_DOUBLE=false/COMPTA_USE_PARTIE_DOUBLE=true/' .env"

# ---------------------------------------------------------------------------
# Step 6 : Smoke-test final
# ---------------------------------------------------------------------------

echo "[$(date)] Step 6 : config:clear + compta:smoke-test-v5"
run "php artisan config:clear"
run "php artisan compta:smoke-test-v5"

echo "[$(date)] deploy-preprod-v5 terminé avec succès."
