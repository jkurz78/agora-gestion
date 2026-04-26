# Runbook — Wizard d'onboarding (S5)

**Contexte :** slice S5 déployé. À partir du merge, toute association dont `wizard_completed_at` est `NULL` redirige systématiquement ses admins sur `/onboarding` (via `ForceWizardIfNotCompleted`). Les assos existantes (SVS + éventuelles assos créées avant S5) doivent être marquées "déjà onboardées" pour ne PAS voir le wizard.

## Prérequis
- Déploiement de `feat/multi-tenancy-s1` en prod (ou merge post-S6) réalisé.
- Migration `2026_04_17_190001_add_wizard_state_to_associations_table.php` exécutée (ajoute `wizard_state` json + `wizard_current_step` tinyint default 1).
- Accès SSH O2Switch + mot de passe MySQL prod.

## Étape 1 — Migration

```bash
php artisan migrate
```

Vérifier :

```sql
SHOW COLUMNS FROM association LIKE 'wizard_%';
-- wizard_completed_at datetime null
-- wizard_state        json null
-- wizard_current_step tinyint unsigned default 1
```

## Étape 2 — Marquer les assos existantes comme "déjà onboardées"

**CRITIQUE** : sinon l'admin SVS tombe sur le wizard au prochain login.

```sql
UPDATE association
SET wizard_completed_at = NOW()
WHERE wizard_completed_at IS NULL;
```

Vérifier :

```sql
SELECT id, nom, slug, wizard_completed_at
FROM association;
-- Toutes les lignes doivent avoir un timestamp.
```

## Étape 3 — Créer une asso de test via le super-admin

1. Se loguer `/super-admin` (voir runbook S4).
2. Créer une asso fictive via `/super-admin/associations/create` avec un email réel.
3. L'admin invité reçoit un email "Invitation : {nom}" avec un lien reset-password.
4. L'admin définit son mot de passe → login.

## Étape 4 — Parcours du wizard (checklist post-deploy)

1. **Step 1 — Identité** : adresse, CP, ville, email, téléphone (opt), SIRET (opt), forme juridique (opt), logo + cachet (uploads 2Mo max). Submit → step 2.
2. **Step 2 — Exercice** : sélection mois 1-12. Submit → step 3.
3. **Step 3 — Compte bancaire principal** : nom, IBAN, BIC (opt), domiciliation (opt), solde initial, date solde initial. Submit → CompteBancaire créé, step 4. Retour + re-submit → MÊME compte mis à jour (pas de duplication).
4. **Step 4 — SMTP** : host, port, encryption (ssl/tls/starttls/none), user, password. Bouton "Tester la connexion" → banner SMTP (TCP only, pas d'AUTH). Bouton "Passer sans configurer" → confirm modal bootstrap → step 5. Save + retour + re-submit avec password vide → mot de passe en base préservé.
5. **Step 5 — HelloAsso (optionnel)** : client_id, client_secret, org_slug, environnement (production/sandbox). Submit → HelloAssoParametres créé avec `callback_token` random 40 chars. Skip permis.
6. **Step 6 — IMAP (optionnel)** : host, port, encryption, user, password, dossiers (default `INBOX.Processed` / `INBOX.Errors`). Skip permis.
7. **Step 7 — Plan comptable** : radio "Importer le plan par défaut" OU "Plan vide". Submit default → 7 catégories + 13 sous-catégories créées (CERFA 70, 74, 75, 60, 62, 64). Retour + re-submit → AUCUNE duplication (flag `plan_comptable_applied`).
8. **Step 8 — Premier type d'opération (optionnel)** : nom, description, sous-catégorie (dropdown depuis step 7). Save ou Skip.
9. **Step 9 — Récapitulatif** : dl read-only (nom, adresse, SIRET, exercice, compte, SMTP, HelloAsso, IMAP, plan, type_op). Bouton "Terminer l'onboarding" → confirm modal bootstrap → redirect `/dashboard`. `wizard_completed_at` stampé.

## Étape 5 — Vérifications post-wizard

```sql
SELECT
    a.nom,
    a.wizard_completed_at,
    a.wizard_current_step,
    (SELECT COUNT(*) FROM categories WHERE association_id = a.id) AS nb_cat,
    (SELECT COUNT(*) FROM sous_categories WHERE association_id = a.id) AS nb_sous_cat,
    (SELECT COUNT(*) FROM type_operations WHERE association_id = a.id) AS nb_type_op,
    (SELECT COUNT(*) FROM compte_bancaire WHERE association_id = a.id) AS nb_comptes
FROM association a
WHERE a.slug = '<slug-test>';
```

Attendu (parcours complet avec "default" + une type_op) :
- `wizard_completed_at` NOT NULL
- `wizard_current_step` = 9
- `nb_cat` = 7, `nb_sous_cat` = 13, `nb_type_op` = 1, `nb_comptes` = 1

## Étape 6 — Test du force-redirect

1. Créer un autre admin sur l'asso test, login.
2. Reset `wizard_completed_at = NULL` pour cette asso :
   ```sql
   UPDATE association SET wizard_completed_at = NULL WHERE slug = '<slug-test>';
   ```
3. Depuis un nav privé, login avec le compte admin.
4. Vérifier : tentative d'accès `/dashboard` → redirect vers `/onboarding`.
5. Super-admin connecté n'est PAS forcé (bypass).

## Rollback

Migration `2026_04_17_190001` supprime 3 colonnes. Rollback = `php artisan migrate:rollback --step=1` (prudence : perd toute la persistance wizard des assos en cours d'onboarding).

Alternative douce : si le wizard casse en prod, marquer toutes les assos comme onboardées (`UPDATE association SET wizard_completed_at = NOW()`), le middleware cesse de rediriger sans toucher à la structure.

## Notes

- Le wizard est accessible tant que `wizard_completed_at IS NULL`. Après finalisation, un accès à `/onboarding` re-monte la page mais les données sont déjà persistées (pas de reset).
- Les champs password sont chiffrés en base (`encrypted` cast sur `SmtpParametres.smtp_password`, `HelloAssoParametres.client_secret` + `callback_token`, `IncomingMailParametres.imap_password`).
- Le bouton "Tester la connexion SMTP" fait UNIQUEMENT un handshake TCP + lecture du banner (pas d'AUTH) — évite le verrouillage de compte provider sur tests répétés avec mauvais mot de passe.
- Logo et cachet uploads sont stockés dans `storage/app/associations/{id}/branding/logo.ext` et `.../cachet.ext` (chemin canonique tenant).

## Résidus connus (non fixés par S5)

- `EmailOptoutController::associationData()` lit toujours `Association::find(1)` — hotfix S7 via subdomain.
- `HelloassoForm.php:65` a encore `'association_id' => 1` — bug hérité S1, en attente.
- Factory `AssociationFactory` crée par défaut `wizard_completed_at = now()`. Tests qui veulent l'état non-onboardé doivent utiliser `Association::factory()->unonboarded()->create(...)`.
