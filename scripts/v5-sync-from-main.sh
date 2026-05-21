#!/usr/bin/env bash
#
# v5-sync-from-main.sh — Sync feat/compta-v5 with main after a prod hotfix
#
# Reference: docs/specs/2026-05-19-fondations-partie-double-slice1.md §16.2
#
# To be run from a local dev clone after a hotfix has been merged on main
# and a release v4.x.y has been tagged.
#
# Workflow:
#   1. git fetch origin
#   2. checkout feat/compta-v5
#   3. merge main (manual conflict resolution if any)
#   4. run full test suite
#   5. run backfill --dry-run as a smoke check
#   6. push feat/compta-v5
#
# This script does NOT commit anything on its own — it stops at the merge
# step if conflicts arise, leaves the resolved state for the developer
# to push manually.
#
# Usage: ./scripts/v5-sync-from-main.sh

set -euo pipefail

BRANCH="feat/compta-v5"

echo "[v5-sync] Step 1: git fetch origin"
git fetch origin

echo "[v5-sync] Step 2: checkout ${BRANCH}"
git checkout "${BRANCH}"
git pull --ff-only origin "${BRANCH}"

echo "[v5-sync] Step 3: merge main"
if ! git merge origin/main --no-edit; then
  echo "[v5-sync] MERGE CONFLICT — resolve manually, then re-run from Step 4 onwards"
  echo "[v5-sync] After resolving:"
  echo "[v5-sync]   git add . && git commit"
  echo "[v5-sync]   ./vendor/bin/sail test"
  echo "[v5-sync]   ./vendor/bin/sail artisan compta:backfill-partie-double --dry-run"
  echo "[v5-sync]   git push origin ${BRANCH}"
  exit 1
fi

echo "[v5-sync] Step 4: full test suite"
./vendor/bin/sail test

echo "[v5-sync] Step 5: backfill dry-run sanity check"
./vendor/bin/sail artisan compta:backfill-partie-double --exercice=current --dry-run

echo "[v5-sync] Step 6: push ${BRANCH}"
git push origin "${BRANCH}"

echo "[v5-sync] Done. ${BRANCH} is up to date with main."
