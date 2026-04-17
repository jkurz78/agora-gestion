# Runbook — onboarding d'une nouvelle association

Ce document est destiné au super-admin. Il décrit la procédure complète pour créer une nouvelle association, accompagner son administrateur dans le wizard de configuration, et gérer le cycle de vie du tenant.

---

## Prérequis

- Compte super-admin actif (`users.role_systeme = 'super_admin'`, helper `$user->isSuperAdmin()`).
- Accès à l'interface `/super-admin/`.
- Accès au serveur pour vérifications post-onboarding si nécessaire.

---

## Procédure

### 1. Créer l'association

1. Se connecter avec le compte super-admin.
2. Naviguer vers `/super-admin/associations/create`.
3. Remplir les champs minimaux :
   - **Nom** de l'association.
   - **Slug** (identifiant URL unique, ex. `mon-asso`) — utilisé dans les routes super-admin et comme clé de model binding.
   - **Email** de l'administrateur principal.
4. Valider le formulaire.

L'association est créée avec le statut `actif`. Un utilisateur est créé (ou associé) avec le rôle `admin` dans le pivot `association_user`.

### 2. L'administrateur reçoit un mail d'invitation

- Le mail contient un lien de reset de mot de passe Laravel (token valide 60 minutes).
- L'administrateur clique sur le lien, définit son mot de passe et se connecte.

Si le mail n'arrive pas :

```
# Régénérer un lien de reset depuis Tinker (Sail)
./vendor/bin/sail artisan tinker
>>> $user = \App\Models\User::where('email', 'admin@exemple.fr')->first();
>>> \Password::sendResetLink(['email' => $user->email]);
```

### 3. Wizard d'onboarding (9 étapes)

Au premier login, le middleware `ForceWizardIfNotCompleted` redirige automatiquement l'admin vers `/onboarding` tant que `associations.wizard_completed_at` est NULL.

Le wizard `App\Livewire\Onboarding\Wizard` comporte exactement 9 étapes. La progression est persistée dans `associations.wizard_current_step` et `associations.wizard_state` — l'admin peut interrompre et reprendre.

| Étape | Contenu | Obligatoire |
|---|---|---|
| **1 — Identité** | Adresse, code postal, ville, email, téléphone, SIRET, forme juridique, logo, cachet de signature | Oui |
| **2 — Exercice** | Mois de début de l'exercice comptable (1–12) | Oui |
| **3 — Compte bancaire principal** | Nom de la banque, IBAN, BIC, domiciliation, solde initial, date du solde initial | Oui |
| **4 — SMTP** | Hôte, port, chiffrement (ssl/tls/starttls/none), identifiants, bouton "Tester la connexion". Skippable (désactive l'envoi d'emails). | Skippable |
| **5 — HelloAsso** | Client ID, Client Secret, slug organisation, environnement (sandbox/production) | Skippable |
| **6 — IMAP** | Hôte, port, chiffrement, identifiants, dossiers "traités" et "erreurs" (défauts : `INBOX.Processed` / `INBOX.Errors`) | Skippable |
| **7 — Plan comptable** | Choix : "Plan comptable par défaut" (catégories et sous-catégories pré-remplies) ou "Vide" | Oui |
| **8 — Premier type d'opération** | Nom, description, sous-catégorie liée — crée le premier `TypeOperation` du tenant | Skippable |
| **9 — Récapitulatif** | Synthèse de tous les paramètres saisis. Bouton "Terminer" → `wizard_completed_at` horodaté → redirection `/dashboard`. | Finalisation |

Après l'étape 9, `ForceWizardIfNotCompleted` laisse passer l'admin normalement.

### 4. Vérifications post-onboarding

**Interface super-admin :**

1. Naviguer vers `/super-admin/associations/{slug}`.
2. Vérifier que le statut affiché est `actif`.
3. Vérifier que `wizard_completed_at` est renseigné.

**Stockage :**

```bash
# Vérifier que le répertoire tenant existe (remplacer {id} par l'ID numérique)
ls storage/app/associations/{id}/
# Doit contenir au moins : branding/ si un logo a été uploadé
```

**Logs :**

```bash
# Vérifier que les actions du tenant sont bien taggées
grep '"association_id":{id}' storage/logs/laravel.log | tail -20
```

**Base de données (si accès direct) :**

```sql
SELECT id, nom, slug, statut, wizard_completed_at FROM association WHERE id = {id};
SELECT COUNT(*) FROM compte_bancaire WHERE association_id = {id};
SELECT COUNT(*) FROM categories WHERE association_id = {id};  -- 0 si l'admin a choisi "Vide" à l'étape 7
```

---

## Suspendre un tenant

1. Naviguer vers `/super-admin/associations/{slug}`.
2. Cliquer sur **"Suspendre"**.
3. Le statut passe à `suspendu`.

Effet immédiat : le middleware `ResolveTenant` retourne un `403` pour tous les utilisateurs du tenant (sauf le super-admin). Les données sont conservées intégralement.

Pour réactiver : cliquer sur **"Réactiver"** sur la même page.

---

## Mode support (lecture seule)

Le mode support permet au super-admin d'accéder à l'interface d'un tenant sans pouvoir modifier de données.

1. Naviguer vers `/super-admin/associations/{slug}`.
2. Cliquer sur **"Entrer en mode support"** (`POST /super-admin/associations/{slug}/support/enter`).
3. Une bannière rouge apparaît en haut de l'écran sur toutes les pages.

En mode support :

- `ResolveTenant` boot le tenant depuis `session.support_association_id` sans vérification du pivot `association_user`.
- `BlockWritesInSupport` rejette toutes les requêtes mutantes (`POST`, `PUT`, `PATCH`, `DELETE`) avec un `403`.
- Toutes les actions sont tracées dans `super_admin_access_log`.

Pour quitter : cliquer sur **"Quitter le mode support"** dans la bannière (`POST /super-admin/support/exit`).

---

## Archiver un tenant

1. Naviguer vers `/super-admin/associations/{slug}`.
2. Cliquer sur **"Archiver"**.
3. Le statut passe à `archive`.

Effet : toutes les requêtes non-super-admin sur ce tenant reçoivent un `403 — Cette association est archivée.` Les données sont conservées. L'archivage est réversible depuis l'interface super-admin.

---

## Référence rapide — états d'une association

| Statut | Accès utilisateurs | Accès super-admin | Wizard obligatoire |
|---|---|---|---|
| `actif` | Oui | Oui | Si `wizard_completed_at` NULL |
| `suspendu` | Non (403) | Oui (mode support) | Non |
| `archive` | Non (403) | Oui (mode support) | Non |
