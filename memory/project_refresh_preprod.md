---
name: Refresh préprod
description: Procédure de refresh staging NAS avec anonymisation réaliste (clone prod, SQL + artisan chiffré)
type: project
---

Refresh préprod effectué le 2026-04-02.

**Why:** Le modèle de données avait beaucoup évolué (participants, données médicales chiffrées, factures, email_logs, formulaire_tokens) et l'ancien script d'anonymisation était cassé (colonne `adresse` renommée en `adresse_ligne1`, tables manquantes).

**How to apply:** `bash scripts/clone-prod.sh` fait tout automatiquement :
1. Dump prod O2Switch → import staging NAS (pipe SSH direct)
2. SQL `anonymize-tiers.sql` : données réalistes (120 noms/prénoms, villes Yvelines, emails crédibles)
3. Artisan `staging:anonymize-medical` : déchiffre le sexe avec la clé prod (passée en env `PROD_APP_KEY`), préserve le genre, ré-chiffre avec la clé staging
4. Cache clear

Points techniques :
- `encrypt()` ≠ `Crypt::encryptString()` — le cast `encrypted` utilise `encryptString` (sans serialize)
- La clé prod est extraite du `.env` prod et passée en variable d'env au conteneur Docker
- Les noms d'entreprises/établissements sont adaptés au domaine médical/social
