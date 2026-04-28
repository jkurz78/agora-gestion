# Démo en ligne — environnement public auto-réinitialisé

**Date** : 2026-04-28
**Statut** : spec PASS (consistency gate ✅), prête pour `/plan`
**Programme** : Démo publique AgoraGestion
**Périmètre** : slice unique S3 — environnement démo isolé sur `demo.agoragestion.org`, peuplé par snapshot capture/replay, déployé sur `push main` en parallèle de la prod (clone strict), réinitialisé chaque nuit à 4h00.
**Préalables** : v4.1.9 en prod (mono-prod stable, multi-tenant v4 livré). DB MySQL dédiée et sous-domaine créés côté O2Switch par l'opérateur.

---

## 1. Intent Description

**Quoi.** Mettre en ligne un environnement public `https://demo.agoragestion.org` permettant à un prospect de tester AgoraGestion **sans inscription, sans onboarding, sans engagement**. Le prospect arrive depuis le site vitrine, voit un bandeau de connexion qui affiche en clair les comptes (`admin@demo.fr / demo` et `jean@demo.fr / demo`), se connecte, navigue dans une association de démonstration **pré-peuplée** de données réalistes et fictives, et expérimente l'application comme un vrai utilisateur. Les actions destructives au-delà de l'asso démo (suppression d'asso, archivage), les flux qui sortent du système (envoi de mails, appels HelloAsso, IMAP), et les paramètres sensibles (SMTP, HelloAsso) sont bridés.

À 4h00 chaque matin, l'environnement est **réinitialisé automatiquement** via une commande artisan (`demo:reset`) qui rejoue un snapshot YAML versionné. Les dates contenues dans le snapshot sont stockées en **delta relatif** au moment de capture (`-12d`, `-3M`) et **réhydratées par rapport à `now()`** au reset, de sorte que la démo paraît toujours "à jour" : exercice comptable courant, factures émises il y a quelques jours, devis acceptés cette semaine.

**Pourquoi.** Dispositif standard pour un SaaS B2B (cf. Mautic, Strapi, Vendure) qui supprime la friction d'un tunnel d'inscription pour un prospect en phase de découverte. Le site vitrine peut pointer en CTA principal vers `demo.agoragestion.org`. Bénéfices secondaires : démo permanente pour le commercial sans pollution de la prod, terrain de jeu pour les nouvelles fonctionnalités avant publication, support pédagogique reproductible.

**Pourquoi maintenant.** AgoraGestion a atteint la maturité fonctionnelle (v4.1.9, multi-tenant v4) qui rend la démo représentative. Le multi-tenant strict (S6 hardening) garantit qu'une démo sur un tenant fictif ne pollue jamais d'autres tenants — l'asso démo est juste *une* asso parmi d'autres potentielles, gérée par les mêmes scopes globaux que la prod.

**Quoi ce n'est pas.** Pas une instance multi-tenant accueillant plusieurs prospects dans des assos séparées (un seul tenant démo, partagé). Pas un bac à sable persistant — toute donnée saisie disparaît au reset suivant. Pas une intégration au site vitrine (CTA et tracking sont hors scope, c'est l'opérateur qui pose le lien). Pas une duplication automatisée de la prod — le snapshot est construit manuellement par l'opérateur (donnée 100 % fictive), versionné en git. Pas de monitoring/alerting dédié (sera traité par O2Switch + logs Laravel standards). Pas de rate limiting public spécifique au démo.

**Périmètre Slice 3.** Helpers `App\Support\Demo` + middleware `EnforceDemoReadOnly` ; bridage des sorties externes (mails, HelloAsso webhook, HelloAsso sync, IMAP, OCR) ; lecture seule sur écrans paramètres SMTP + HelloAsso ; refus suppression/archivage d'asso en démo ; bandeau démo conditionnel sur `/login` ; commandes artisan `demo:capture` + `demo:reset` ; format snapshot YAML versionné avec dates relatives ; workflow GitHub Actions `deploy-demo.yml` déclenché sur `push main` (clone strict de prod) ; script `deploy-demo.sh` ; cron O2Switch `0 4 * * *` ; documentation runbook démo.

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: Démo publique AgoraGestion
  Pour qu'un prospect puisse tester l'application sans inscription
  En tant que visiteur du site vitrine
  Je me connecte sur demo.agoragestion.org avec un compte affiché et explore une asso pré-peuplée

  Contexte:
    Étant donné que l'environnement est démo (APP_ENV=demo)
    Et que l'asso "Démo AgoraGestion" est seedée par le snapshot YAML
    Et qu'elle contient des tiers, opérations, factures, devis, transactions, séances réalistes

  # ─── Page de connexion démo ───────────────────────────────────────────

  Scénario: Bandeau démo visible sur /login en environnement démo
    Quand j'ouvre "https://demo.agoragestion.org/login"
    Alors un bandeau démo est affiché en haut de la page
    Et il indique "Démonstration en ligne — données réinitialisées chaque nuit à 4h"
    Et il liste deux comptes :
      | email             | mot de passe | rôle        |
      | admin@demo.fr     | demo         | Admin       |
      | jean@demo.fr      | demo         | Utilisateur |
    Et aucun bouton d'auto-login n'est présent (saisie manuelle requise)

  Scénario: Bandeau démo absent en production
    Quand j'ouvre "https://app.agoragestion.org/login" (env=production)
    Alors aucun bandeau démo n'est affiché

  Scénario: Connexion avec compte démo
    Étant donné que je suis sur "https://demo.agoragestion.org/login"
    Quand je saisis "admin@demo.fr" / "demo" et soumets
    Alors je suis authentifié sur l'asso "Démo AgoraGestion"
    Et je vois le dashboard standard

  # ─── Bridage des sorties externes ─────────────────────────────────────

  Scénario: Envoi d'email écrit en log au lieu d'être délivré
    Étant donné une facture validée pour un tiers de démo
    Quand je clique sur "Envoyer par email"
    Alors le service SMTP n'est pas appelé
    Et un message confirmation est affiché ("Email enregistré (mode démo)")
    Et le contenu est écrit dans les logs Laravel (channel "mail" ou stack)

  Scénario: Webhook HelloAsso ignoré en démo
    Étant donné que l'environnement est démo
    Quand un POST arrive sur "/webhooks/helloasso"
    Alors la requête est acceptée (HTTP 200) sans persistance
    Et un log "helloasso.webhook.skipped_demo" est émis

  Scénario: Synchronisation HelloAsso désactivée en démo
    Étant donné que l'environnement est démo
    Quand le scheduler tente d'exécuter "helloasso:sync"
    Alors la commande retourne immédiatement (no-op)
    Et un log "helloasso.sync.skipped_demo" est émis

  Scénario: Polling IMAP désactivé en démo
    Étant donné que l'environnement est démo
    Quand le scheduler tente d'exécuter "incoming-mail:fetch"
    Alors la commande retourne immédiatement (no-op)
    Et un log "incoming-mail.skipped_demo" est émis

  Scénario: OCR factures partenaires désactivé en démo
    Étant donné un PDF déposé via le portail factures partenaires
    Quand l'analyse OCR est déclenchée
    Alors aucun appel externe n'est effectué
    Et la facture est créée avec montant et date pré-remplis depuis un stub

  # ─── Lecture seule sur paramètres sensibles ───────────────────────────

  Scénario: Écran paramètres SMTP en lecture seule en démo
    Étant donné que je suis admin et que je suis sur "/parametres/smtp"
    Quand j'affiche l'écran
    Alors les champs sont désactivés (disabled)
    Et un message indique "Modification désactivée en démo"
    Et le bouton "Enregistrer" est absent

  Scénario: Écran paramètres HelloAsso en lecture seule en démo
    Étant donné que je suis admin et que je suis sur "/parametres/helloasso"
    Quand j'affiche l'écran
    Alors les champs sont désactivés
    Et un message indique "Modification désactivée en démo"

  Scénario: Tentative d'écriture sur paramètres bridés via POST refusée
    Étant donné que je suis admin en démo
    Quand je soumets manuellement un POST de modification SMTP (forçage)
    Alors la requête retourne 403
    Et un log "demo.write_blocked" est émis

  # ─── Refus d'opérations destructives ──────────────────────────────────

  Scénario: Suppression de l'asso démo refusée
    Étant donné que je suis admin en démo
    Quand je tente de supprimer l'association "Démo AgoraGestion"
    Alors l'action est refusée
    Et un message indique "Suppression désactivée en démo"

  Scénario: Archivage de l'asso démo refusé
    Étant donné que je suis admin en démo
    Quand je tente d'archiver l'association
    Alors l'action est refusée
    Et un message indique "Archivage désactivé en démo"

  # ─── Comportement libre dans l'asso démo ──────────────────────────────

  Scénario: Création d'un tiers en démo
    Étant donné que je suis connecté en démo
    Quand je crée un tiers "Jean Test" avec email "jean@test.fr"
    Alors le tiers est enregistré
    Et il sera supprimé au prochain reset (4h)

  Scénario: Validation d'une facture manuelle en démo
    Étant donné que je suis connecté en démo
    Quand je crée et valide une facture manuelle
    Alors la facture est numérotée et verrouillée comme en prod
    Et la transaction "à recevoir" est générée
    Et l'envoi email reste bridé (logs uniquement)

  # ─── Snapshot capture (opérateur, en local) ───────────────────────────

  Scénario: Capture du snapshot depuis une DB locale peuplée
    Étant donné que l'opérateur a peuplé la DB locale via l'UI
    Et que la DB contient une seule association "Démo AgoraGestion"
    Quand l'opérateur exécute "php artisan demo:capture"
    Alors le fichier "database/demo/snapshot.yaml" est écrit
    Et toutes les dates absolues sont converties en delta relatif à now()
      (ex. "2026-04-15" devient "-13d" si capture le 2026-04-28)
    Et les utilisateurs ont leurs hash de mot de passe écrasés par les hash fixes "demo"
    Et les tokens, sessions, cache sont exclus de la capture

  Scénario: Refus de capture si plus d'une asso présente
    Étant donné que la DB locale contient 2 associations
    Quand l'opérateur exécute "php artisan demo:capture"
    Alors la commande échoue avec code de sortie ≠ 0
    Et un message indique "demo:capture exige une seule association"

  # ─── Reset automatique ────────────────────────────────────────────────

  Scénario: Reset nocturne replonge la base à zéro et rejoue le snapshot
    Étant donné qu'il est 4h00 sur le serveur démo
    Quand le cron exécute "demo:reset"
    Alors l'application passe en maintenance ("php artisan down")
    Et la base est purgée ("migrate:fresh --force")
    Et le snapshot YAML est rejoué : dates relatives réhydratées par rapport à now()
    Et le dossier "storage/app/associations/{id}/" est restauré depuis "database/demo/files/"
    Et l'application repasse en service ("php artisan up")
    Et la durée totale est < 30 secondes

  Scénario: Reset rejoue les dates en cohérence avec now()
    Étant donné un snapshot capturé le 2026-04-28 contenant une facture émise "-12d"
    Quand le reset est exécuté le 2026-05-15
    Alors la facture rejouée a date_emission = 2026-05-03
    Et l'exercice comptable courant est calé sur 2026-05-15

  Scénario: Reset robuste à l'échec de chargement
    Étant donné un snapshot YAML corrompu
    Quand le cron exécute "demo:reset"
    Alors la commande échoue
    Et "php artisan up" est appelé en finally (le site n'est pas bloqué en maintenance)
    Et un log "demo.reset.failed" est émis avec stack

  Scénario: Utilisateur en cours de saisie pendant le reset
    Étant donné que je suis connecté à 3h59 et que j'ai un brouillon non sauvegardé
    Quand le reset s'exécute à 4h00
    Alors mes prochaines requêtes reçoivent une page de maintenance
    Et après reset, je suis redirigé vers /login (sessions purgées)
    Et le brouillon est perdu (comportement assumé)

  # ─── Déploiement ──────────────────────────────────────────────────────

  Scénario: Push main déclenche le déploiement parallèle prod + démo
    Étant donné un push sur main
    Quand GitHub Actions s'exécute
    Alors le workflow "deploy.yml" déploie la prod
    Et le workflow "deploy-demo.yml" déploie la démo (en parallèle)
    Et la démo termine son déploiement par "demo:reset" (snapshot rejoué sur la nouvelle version)

  Scénario: Push sur une autre branche que main ne déploie pas la démo
    Étant donné un push sur "feat/xxx"
    Quand GitHub Actions s'exécute
    Alors "deploy-demo.yml" n'est pas déclenché
    Et la démo reste sur sa version précédente

  # ─── Détection d'environnement ────────────────────────────────────────

  Scénario: Helpers Demo cohérents partout
    Étant donné que APP_ENV=demo dans .env
    Alors "App\Support\Demo::isActive()" retourne true
    Et "config('app.env')" retourne "demo"
    Et tous les guards conditionnels (mail, hello-asso, imap, ocr, params, suppression) sont actifs
```

---

## 3. Architecture Specification

### 3.1 Détection d'environnement

`App\Support\Demo` (helper statique, mince) :

```php
final class Demo {
    public static function isActive(): bool {
        return app()->environment('demo');
    }
}
```

Convention : tous les guards conditionnels lisent **uniquement** `Demo::isActive()`. Pas de lecture directe de `app()->environment()` éparpillée. Permet un seul point de bascule lors des tests (`Config::set('app.env', 'demo')` ou refactor futur en feature flag).

### 3.2 Bridage des sorties externes

| Service | Stratégie | Implémentation |
|---|---|---|
| **Mail (SmtpService + tous les `Mailable`)** | `MAIL_MAILER=log` dans `.env.demo` | inchangé côté code — Laravel log driver écrit dans `storage/logs/laravel.log`. UI affiche message "Email enregistré (mode démo)" lorsque `Demo::isActive()` (substitution du flash success existant) |
| **HelloAsso webhook** (`POST /webhooks/helloasso`) | Guard tout début du controller : si `Demo::isActive()`, log + return 200 sans traitement | Modification du `HelloAssoWebhookController` (ajout d'un early-return) |
| **HelloAsso sync command** (`helloasso:sync`) | Guard en début de `handle()` : skip + log si démo | Modification de la classe `HelloAssoSyncCommand` |
| **IMAP polling** (`incoming-mail:fetch`) | Guard idem en début de `handle()` | Modification de `IncomingMailFetchCommand` (déjà classe finale, ajout au début) |
| **OCR factures partenaires** (`InvoiceOcrService`) | Guard `Demo::isActive()` retourne un stub : montant 100€, date now, libellé "Facture exemple" | Modification minimale du service, tests adaptés |

**Rationale** : guards en code (pas dans le scheduler) → comportement cohérent peu importe le mode d'invocation (cron, tinker, queue, manuel).

### 3.3 Lecture seule sur paramètres sensibles

Middleware `App\Http\Middleware\EnforceDemoReadOnly` :

- S'applique sur les routes : `parametres/smtp/*`, `parametres/helloasso/*`, `associations/{id}/destroy`, `associations/{id}/archive`
- Si `Demo::isActive()` ET méthode HTTP destructive (POST/PUT/PATCH/DELETE) → abort(403) avec message "Modification désactivée en démo"
- GET passe toujours → l'écran s'affiche, les composants Livewire détectent `Demo::isActive()` pour désactiver les inputs et masquer les boutons d'enregistrement

Vues Livewire SMTP / HelloAsso : ajout d'une condition `@if(\App\Support\Demo::isActive())` autour des `<input>` pour ajouter `disabled`, et autour du bouton "Enregistrer" pour le retirer ; bandeau d'info en haut de l'écran "Cet écran est en lecture seule en démo".

Suppression d'asso & archivage : guard côté service (`AssociationService::destroy()`, `AssociationService::archive()`) — `throw DemoOperationBlockedException` si `Demo::isActive()`. Plus défensif que middleware seul (couvre les appels non routés).

### 3.4 Login screen — bandeau démo

Vue `resources/views/auth/login.blade.php` : bloc conditionnel inséré au-dessus du formulaire :

```blade
@if(\App\Support\Demo::isActive())
  <x-demo-login-banner />
@endif
```

Le composant `<x-demo-login-banner />` (`resources/views/components/demo-login-banner.blade.php`) :
- Bandeau Bootstrap `.alert.alert-info` (bleu clair — neutre, "informationnel")
- Titre : "Démonstration en ligne"
- Texte : "Connectez-vous avec un des comptes ci-dessous. Les données sont réinitialisées chaque nuit à 4h."
- Tableau 2 lignes : `admin@demo.fr` / `demo` / Admin — `jean@demo.fr` / `demo` / Utilisateur
- **Pas de bouton auto-login** (saisie manuelle, par choix produit : moins de surface d'attaque, comportement plus représentatif).

Aucune modification du controller de login, de Fortify, de Breeze. Comportement d'auth strictement identique à la prod.

### 3.5 Snapshot — capture & replay

#### 3.5.1 Format YAML

Fichier unique versionné : `database/demo/snapshot.yaml`

Structure :

```yaml
captured_at: 2026-04-28T18:30:00+02:00
schema_version: 1
tables:
  associations:
    - id: 1
      nom: "Démo AgoraGestion"
      slug: "demo"
      created_at: -120d
      updated_at: -2d
  users:
    - id: 1
      association_id: 1
      email: admin@demo.fr
      password: $2y$12$<hash fixe pour "demo">
      role: Admin
      created_at: -120d
  tiers:
    - id: 1
      association_id: 1
      type: physique
      nom: DUPONT
      prenom: Marie
      ...
  # … toutes les tables tenant-scopées + table associations + users
files:
  - source: database/demo/files/factures/F-2026-001.pdf
    target: storage/app/associations/1/factures/F-2026-001.pdf
```

**Dates** : tout champ DateTime/Date sérialisé sous forme `-Nd`, `-NM`, `-Ny`, `+Nd` (signe + autorisé pour les rares cas de date future). Granularité au jour. La capture parse les dates absolues et les convertit en delta vs `captured_at`. Le replay applique le delta vs `now()`.

**Hash mots de passe** : ré-écrits par la capture avec un hash fixe pour le mot de passe "demo" (évite d'exposer un hash jamais utilisé en prod et garantit que les comptes démo sont stables).

**Tables exclues de la capture** : `sessions`, `cache`, `cache_locks`, `failed_jobs`, `jobs`, `password_reset_tokens`, `personal_access_tokens`, `migrations`, `email_logs`, `incoming_mail_logs` (sinon snapshot pollué de bruit). Liste durcie dans la commande, motivable.

**Files** : la capture **ne** copie **pas** les fichiers ; elle attend que l'opérateur prépare manuellement `database/demo/files/` avec un sous-ensemble représentatif (≤ 5 PDFs factures, ≤ 5 attestations) et déclare ces fichiers dans la section `files:` du YAML. Les fichiers sont versionnés en git (légers, peu nombreux).

#### 3.5.2 Commande `demo:capture`

`App\Console\Commands\DemoCaptureCommand` (signature `demo:capture {--out=database/demo/snapshot.yaml}`).

Étapes :
1. Vérifier qu'il n'y a qu'une seule asso en DB (échec sinon)
2. Lister toutes les tables hors liste d'exclusion
3. Pour chaque table, sélectionner toutes les lignes (sans scope global, en bypass `withoutGlobalScopes` puisque la capture est administrative)
4. Convertir chaque champ datetime/date en delta vs `now()`
5. Pour la table `users`, écraser `password` par un hash fixe (calculé une fois, codé en dur dans la commande)
6. Sérialiser en YAML stable (clés triées) et écrire dans `--out`
7. Logger un récap : nb tables, nb lignes par table

#### 3.5.3 Commande `demo:reset`

`App\Console\Commands\DemoResetCommand` (signature `demo:reset {--snapshot=database/demo/snapshot.yaml}`).

Étapes (try / finally pour garantir `up`) :

```
try {
  Artisan::call('down', ['--message' => 'Réinitialisation démo en cours', '--retry' => 60]);
  Artisan::call('migrate:fresh', ['--force' => true]);
  loadSnapshot($file);          // parse YAML, réhydrate dates, INSERT en bulk
  syncStorage();                 // efface storage/app/associations/, recopie database/demo/files/
} finally {
  Artisan::call('up');
}
```

Garde-fou : la commande n'accepte de tourner **que** si `Demo::isActive()` (sinon refus, exit ≠ 0). Évite un `demo:reset` accidentel en prod.

Performance cible : < 30s (incl. down/up). Snapshot ≤ 500 lignes par table ; bulk inserts.

### 3.6 Déploiement

#### 3.6.1 Workflow GitHub Actions

Nouveau fichier : `.github/workflows/deploy-demo.yml`

Trigger :

```yaml
on:
  push:
    branches: [main]
```

→ Clone strict de la prod : démo et prod déploient en parallèle à chaque push main, depuis le même commit. Si prod casse, démo casse aussi (acceptable — démo n'est pas critique, la même CI/test passe sur les deux).

Étapes (calquées sur `deploy.yml` existant, simplifiées) :
1. Tests Pest (mêmes que prod — fail bloque le déploiement démo, même cible que la job test du workflow prod)
2. SSH vers O2Switch (compte O2Switch identique à prod) avec whitelist IP runner via cPanel API (même mécanisme que `deploy.yml`)
3. Pull `main`, `composer install --no-dev`, `php artisan config:cache`, `php artisan migrate:fresh --force`, `php artisan demo:reset`
4. `php artisan up`

**Secrets GitHub** : aucun nouveau secret à ajouter. Le workflow démo réutilise les secrets prod existants : `CPANEL_USERNAME`, `CPANEL_API_TOKEN`, `CPANEL_SERVER`, `SSH_KEY`, `HOME_SSH_HOST`. Seul le **chemin de déploiement** diffère (`~/public_html/demo.agoragestion.org/`) — codé en dur dans le workflow ou exposé via un nouveau secret optionnel `O2SWITCH_DEMO_PATH` (recommandé pour rester aligné sur le pattern prod).

DB credentials vivent dans `.env.demo` côté serveur, jamais dans les secrets GHA.

Le workflow prod existant (`deploy.yml`) **reste strictement inchangé**.

#### 3.6.2 `.env.demo` (côté serveur, jamais committé)

Variables clés :
```
APP_ENV=demo
APP_DEBUG=false
APP_URL=https://demo.agoragestion.org
DB_HOST=…           # DB démo dédiée
DB_DATABASE=…
DB_USERNAME=…
DB_PASSWORD=…
MAIL_MAILER=log
SESSION_DRIVER=database  # idem prod
QUEUE_CONNECTION=sync    # ou database, peu importe en démo, pas de jobs lourds
```

#### 3.6.3 Cron O2Switch

Entrée crontab serveur démo :

```
0 4 * * * cd /home/.../demo.agoragestion.org && php artisan demo:reset >> storage/logs/demo-reset.log 2>&1
```

### 3.7 Frontière avec l'existant

| Module | Impact |
|---|---|
| Auth (Fortify, Breeze) | aucun — bandeau ajouté en pure vue |
| Multi-tenant (S6) | aucun — l'asso démo est un tenant ordinaire ; les scopes globaux protègent par défaut |
| `SmtpService`, `Mailable`s | aucun appel interne ne change ; `MAIL_MAILER=log` au niveau Laravel ; UI flash adapté |
| `HelloAssoWebhookController` | early-return ajouté en début |
| `HelloAssoSyncCommand` | guard `Demo::isActive()` ajouté |
| `IncomingMailFetchCommand` | guard `Demo::isActive()` ajouté |
| `InvoiceOcrService` | guard `Demo::isActive()` retourne stub |
| `AssociationService::destroy/archive` | guard `Demo::isActive()` → `DemoOperationBlockedException` |
| Vues Livewire SMTP / HelloAsso | bandeau + inputs `disabled` + bouton enregistrer masqué si `Demo::isActive()` |
| Wizard onboarding (S5) | aucun — l'asso démo est seedée par snapshot, le wizard ne tourne jamais |
| Super-admin | hors scope démo — le snapshot ne porte pas de super-admin (le wizard non plus, donc pas de SA dans la démo) |
| Tests Pest | nouveaux tests `tests/Feature/Demo/*` ; suite globale reste verte |

### 3.8 Contraintes techniques

- `declare(strict_types=1)`, `final class`, type hints partout
- PSR-12 / Pint
- Tests Pest sur tous les guards (mail, helloasso, imap, ocr, params, suppression) en mockant `Demo::isActive()` via `Config::set('app.env', 'demo')`
- Locale `fr` partout
- `wire:confirm` → modale Bootstrap (jamais natif)
- Cast `(int)` des deux côtés sur `===` PK/FK
- Pas de modification du schéma DB (pas de migration)
- Pas de nouvelle dépendance Composer (YAML via `symfony/yaml` déjà transitif via Laravel)

### 3.9 Risques

| Risque | Mitigation |
|---|---|
| `demo:reset` en prod par erreur | guard `Demo::isActive()` en tout début de commande, exit ≠ 0 sinon |
| Snapshot corrompu casse la démo | try/finally garantit `php artisan up` ; log + alerting passif (logs O2Switch) ; rollback manuel par redéploiement du tag précédent |
| Capture exclut une table importante | revue à la main du YAML après `demo:capture` ; tests sur la commande `demo:reset` qui rejoue un mini-snapshot fixture |
| Hash mot de passe écrasé fragile | hash fixe stocké en constante de classe, validé par test `assertTrue(Hash::check('demo', $constant))` |
| Réinitialisation pendant heure de bureau US (décalage UTC) | 4h Paris = 22h–23h US East / 19h US West — démo destinée principalement marché FR, acceptable |
| Données démo perçues comme datées (ex. exercice 2025) | dates relatives à `now()` règlent le problème ; revue visuelle obligatoire à chaque livraison snapshot |
| Capture exécutée sur prod par mégarde | la commande ne pose pas de garde explicite sur env (parce qu'elle peut être utile pour générer un snapshot anonymisé un jour) ; **MITIGATION** : `demo:capture` doit refuser de tourner si `app()->environment('production')` (à ajouter explicitement) |
| Fichiers dans `database/demo/files/` deviennent volumineux | charte : ≤ 5 PDFs, ≤ 1 Mo total — vérifié par test qui mesure la taille du dossier |
| Clé `APP_KEY` différente entre prod et démo | normal et souhaité — sinon les tokens chiffrés en prod seraient déchiffrables en démo |
| Reset 4h coupe un démonstrateur en pleine présentation | charte : commercial doit savoir que 4h est la fenêtre de reset ; downtime ~30s peut être ressenti en pleine démo si timing malheureux ; coût acceptable, pas de pré-annonce in-app |

---

## 4. Acceptance Criteria

### 4.1 Tests

| Critère | Seuil |
|---|---|
| Tests Pest des guards (mail, hello-asso webhook, hello-asso sync, imap, ocr, params smtp, params helloasso, suppression asso, archivage asso) | 1 test par guard, mock `Demo::isActive()` via `Config::set` |
| Test `demo:capture` sur DB locale fixture | round-trip capture + reset + dump = identité (modulo dates) |
| Test `demo:reset` rejoue un snapshot fixture | dates réhydratées correctes, fichiers copiés, count rows par table égal au snapshot |
| Test `demo:reset` refuse de tourner hors `app()->environment('demo')` | exit ≠ 0, message clair |
| Test `demo:capture` refuse de tourner sur `app()->environment('production')` | exit ≠ 0, message clair |
| Test bandeau démo sur `/login` | présent ssi `Demo::isActive()`, absent sinon |
| Suite Pest globale post-merge | 0 failed, 0 errored |
| Test régression : prod (env=production) inchangée — aucun guard ne s'active | suite existante reste verte |

### 4.2 Sécurité

- Aucune route démo n'expose de données prod (DB séparée, sous-domaine séparé, `APP_KEY` séparée)
- Bandeau de connexion expose `demo / demo` en clair — **assumé** : ce sont des credentials publics, l'asso démo n'a aucune valeur
- `EnforceDemoReadOnly` couvre **toutes** les routes paramètres sensibles (audit explicite à la PR)
- `demo:reset` et `demo:capture` ne sont jamais accessibles depuis HTTP — uniquement CLI (cron + opérateur SSH)
- Logs `demo.write_blocked`, `demo.reset.failed` portent `association_id` + `user_id` via `LogContext`
- Pas de mécanisme de bypass des guards (pas de "super-admin override en démo")

### 4.3 Performance

| Critère | Seuil |
|---|---|
| `demo:reset` complet (down → fresh → load → up) | < 30s (snapshot ≤ 500 lignes/table) |
| Page de login démo | inchangée vs prod (le bandeau ajoute < 5 ms) |
| Helpers `Demo::isActive()` | O(1), lit `app()->environment()` (cached) |
| Bridage emails — overhead | nul (Laravel switch driver natif) |

### 4.4 UX

- Bandeau `/login` visible et lisible (mobile + desktop), couleur info/warning standard Bootstrap
- Pas de bouton auto-login (saisie manuelle requise)
- Messages flash adaptés en démo : "Email enregistré (mode démo)" au lieu de "Email envoyé"
- Écrans paramètres SMTP / HelloAsso : bandeau d'info clair "Cet écran est en lecture seule en démo", inputs grisés, bouton enregistrer absent
- Refus suppression / archivage asso : message d'erreur explicite, pas de stack
- Locale `fr` partout, y compris message de maintenance pendant `php artisan down --message=…`

### 4.5 Données & opérationnel

- `database/demo/snapshot.yaml` versionné en git
- `database/demo/files/` versionné, taille ≤ 1 Mo
- `.env.demo` **jamais** committé, créé manuellement par l'opérateur côté O2Switch
- Cron `0 4 * * *` configuré sur le serveur démo (étape de runbook)
- Logs reset écrits dans `storage/logs/demo-reset.log`
- Snapshot mis à jour à chaque évolution majeure (à la main, par capture depuis local) — nouveau snapshot accompagne le PR de feature qui ajoute du contenu démo

### 4.6 Documentation

- `CHANGELOG.md` : entrée S3 + version (probable v4.2.0 ou v4.1.10 selon ampleur)
- Mémoire projet `project_demo_en_ligne.md` mise à jour : S3 livrée
- **Runbook opérateur** : `docs/runbook-demo.md` — créer DB MySQL O2Switch, créer sous-domaine, copier `.env.demo`, ajouter secrets GHA, configurer cron, capture initiale du snapshot, recette manuelle du premier déploiement
- README projet : section "Démo en ligne" pointant vers `https://demo.agoragestion.org`
- Charte snapshot : "comment construire un snapshot représentatif" — guide en 1 page dans `docs/runbook-demo.md` (que créer comme tiers / opérations / factures pour donner une bonne première impression)

### 4.7 Gates de livraison

| Étape | Critère |
|---|---|
| Pre-commit | `vendor/bin/pint` clean, suite Pest verte |
| PR review | code-review verte (security, perf, struct, naming) |
| Merge + tag | `git tag v4.2.0 && git push --tags` déclenche les 2 workflows |
| Recette manuelle démo | parcours login → dashboard → création tiers → création facture manuelle → email log → vérif bandeaux paramètres → vérif refus suppression asso |
| Recette reset | `php artisan demo:reset` manuel sur serveur démo (avant cron), vérifier durée < 30s, vérifier dates rejouées correctes |
| Recette cron | attendre 4h00 (ou décaler temporairement le cron à H+5min), vérifier reset OK |

---

## 5. Consistency Gate

| Check | Statut |
|---|---|
| Intent unambigu | ✅ |
| Chaque comportement Intent → ≥ 1 scénario BDD | ✅ |
| Architecture contrainte au strict S3 (pas d'over-engineering : pas de feature flag complexe, pas de monitoring, pas de billing) | ✅ |
| Concepts cohérents (`APP_ENV=demo`, `Demo::isActive()`, snapshot YAML, dates relatives, reset 4h, deploy sur push main) | ✅ |
| Aucune contradiction inter-artefacts | ✅ |
| BDD ↔ AC mapping 1:1 | ✅ |
| Architecture ↔ AC perf cohérent (< 30s reset, < 5 ms bandeau) | ✅ |
| Frontière existant explicite (auth, multi-tenant, wizard, super-admin tous "aucun impact" hormis guards listés) | ✅ |
| Multi-tenant traité (asso démo = tenant ordinaire, scopes globaux protègent par défaut) | ✅ |
| 1 slice verticale livrable indépendamment | ✅ — la slice est large mais **commercialement indivisible** : bridage sans snapshot = démo cassée ; snapshot sans deploy = inutile en prod ; deploy sans bridage = fuite de mails. Couverture acceptable pour S3. |
| Risques destructifs identifiés (`demo:reset` accidentel en prod) avec garde explicite | ✅ |
| Trous connus / dettes documentés | ✅ — pré-requis user listés en §6 |

**Verdict : GATE PASSED — prête pour `/plan`.**

---

## 6. Pré-requis user (rappel — à faire en parallèle au build)

- [x] Sous-domaine `demo.agoragestion.org` créé côté O2Switch (DNS + SSL)
- [x] DB MySQL démo dédiée créée + credentials notés
- [x] Accès SSH = même compte O2Switch que prod (donc secrets GHA réutilisés)
- [ ] (Optionnel) Ajouter le secret GHA `O2SWITCH_DEMO_PATH=~/public_html/demo.agoragestion.org/` — sinon le chemin sera codé en dur dans le workflow
- [ ] Construire la première version de la DB démo manuellement (en local sur DB dédiée `agora_demo_seed`) : tiers réalistes, opérations, devis variés, factures à différents statuts, transactions, séances, règlements, encaissements (asso `Les amis de la démo`, comptes `admin@demo.fr` / `jean@demo.fr`)
- [ ] Préparer `database/demo/files/` avec ≤ 5 PDF factures de démo (légers, fictifs)
- [ ] Créer `.env.demo` côté serveur O2Switch (non committé) — après le premier déploiement
- [ ] Exécuter `php artisan demo:capture` en local après peuplement → commit du `snapshot.yaml`
- [ ] Configurer le cron O2Switch `0 4 * * *` après le premier déploiement réussi
