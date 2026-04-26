# Runbook — Promotion du premier super-admin (S4)

**Contexte :** slice S4 déployé en prod. Aucun utilisateur n'a encore le rôle `super_admin`, donc `/super-admin/*` renvoie 403 pour tout le monde. Ce runbook décrit la promotion du premier super-admin SVS et la checklist de validation post-deploy.

## Prérequis
- Déploiement de `feat/multi-tenancy-s1` en prod (ou merge post-S6) réalisé.
- Accès SSH O2Switch + mot de passe MySQL prod.
- Email de connexion du compte à promouvoir (par défaut : `jurgen@svs-accounting.fr`).

## Étape 1 — Promotion SQL

Via phpMyAdmin ou mysql CLI :

```sql
UPDATE users
SET role_systeme = 'super_admin'
WHERE email = 'jurgen@svs-accounting.fr';
```

Vérifier :

```sql
SELECT id, email, role_systeme FROM users WHERE role_systeme = 'super_admin';
```

## Étape 2 — Clear cache session / auth

Si l'utilisateur était connecté avant la promotion, logout/login obligatoire pour que le middleware `EnsureSuperAdmin` voie la nouvelle valeur d'enum. Sinon rien à faire.

## Étape 3 — Accès `/super-admin` et checklist

1. Ouvrir `https://<prod-host>/super-admin` — vérifier le dashboard (message d'accueil + lien "Liste des associations").
2. Cliquer "Liste des associations" → page `/super-admin/associations` — la ou les assos existantes apparaissent avec leur statut (`actif`), compteur d'users, bouton "Détail" et "Support".
3. Cliquer "Détail" sur une asso → 3 onglets (Infos / Utilisateurs / Logs support). Logs vides à ce stade.
4. Cliquer "Support" depuis la liste → redirection vers `/dashboard` avec la bannière rouge "⚠ Mode support actif". Navbar et contenu chargés dans le contexte de l'asso.
5. Tester l'isolation : tenter une action qui fait un POST (ex. créer une dépense) → 403 "Mode support actif : toute écriture est interdite.".
6. Cliquer "Quitter le mode support" dans la bannière → retour sur `/super-admin/associations`. Session nettoyée.
7. Retourner sur le détail → onglet "Logs support" → 2 entrées (`enter_support_mode`, `exit_support_mode`) avec l'IP et le timestamp.

## Étape 4 — Création d'un nouveau tenant (test)

1. `/super-admin/associations/create` → formulaire Nom, Slug, Email admin, Nom admin.
2. Soumettre avec une asso fictive et un email réel (pour valider l'invitation).
3. Vérifier :
   - Redirection `/super-admin/associations` avec flash success.
   - La nouvelle asso apparaît dans la liste, statut `actif`.
   - L'admin reçoit un email "Invitation : {nom} sur AgoraGestion" avec un lien `/reset-password/{token}?email=...`.
   - Le lien mène au formulaire reset password Breeze fr et permet de définir un mot de passe.
   - Après login, l'admin tombe dans son nouveau tenant (vide, pas de wizard S5 encore déployé).

## Étape 5 — Transition lifecycle (test)

1. Depuis le détail d'un tenant en `actif` → bouton "Suspendre" → modal Bootstrap → confirm.
2. Statut devient `suspendu`. Le user admin du tenant (autre navigateur) est bloqué 403 "Cette association est suspendue."
3. "Réactiver" → retour `actif`.
4. "Suspendre" puis "Archiver" → modal "ARCHIVAGE IRRÉVERSIBLE" → statut `archive`, aucune action disponible.

## Rollback

Pas de migration DB pour S4. Rollback = redéploiement du tag pré-S4. Les `super_admin_access_log` existent mais ne seront plus écrits après rollback — aucun impact fonctionnel.

## Notes

- Le super-admin n'a PAS besoin d'être dans `association_user` pour entrer en mode support — c'est voulu.
- Les 3 transitions sont écrites en une seule requête SQL (`DB::transaction` enveloppe update + insert audit).
- Le bouton "Support" ouvre le tenant en mode read-only mais la bannière est visible sur TOUTES les pages (incluse dans `layouts/app.blade.php` et `layouts/app-sidebar.blade.php`).

## Résidus connus (non fixés par S4)

- `EmailOptoutController::associationData()` lit toujours `Association::find(1)` — route publique `/email/optout/{token}`, à résoudre en S7 via URL/subdomain.
- `HelloassoForm.php:65` a encore `'association_id' => 1` — bug hérité S1, hotfix standalone en attente.
