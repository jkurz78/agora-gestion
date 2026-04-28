# Plan : Démo en ligne — environnement public auto-réinitialisé

**Created** : 2026-04-28
**Branch** : `claude/funny-shamir-8661f9` (basée sur `main` post-v4.1.9)
**Spec** : [docs/specs/2026-04-28-demo-en-ligne.md](../docs/specs/2026-04-28-demo-en-ligne.md)
**Status** : implemented (2026-04-29) — 14 commits sur `claude/funny-shamir-8661f9`, suite verte 0 régression

## Goal

Livrer un environnement public `https://demo.agoragestion.org` permettant à un prospect de tester AgoraGestion sans inscription. L'asso `Les amis de la démo` est seedée par snapshot YAML versionné (dates relatives à `now()`), réinitialisée chaque nuit à 4h00 par cron, et déployée sur `push main` en parallèle de la prod (clone strict). Toutes les sorties externes (mails, HelloAsso, IMAP, OCR) sont bridées et les paramètres sensibles sont en lecture seule.

## Acceptance Criteria

- [ ] `App\Support\Demo::isActive()` est l'unique point de bascule du comportement démo
- [ ] Mails non délivrés en démo (`MAIL_MAILER=log` + UI affiche "Email enregistré (mode démo)")
- [ ] Webhook HelloAsso, sync HelloAsso, polling IMAP, OCR factures partenaires : no-op en démo (logs explicites)
- [ ] Écrans paramètres SMTP + HelloAsso en lecture seule en démo (inputs disabled, bouton enregistrer absent, écriture refusée 403)
- [ ] Suppression / archivage de l'asso refusés en démo (`DemoOperationBlockedException`)
- [ ] `/login` affiche le bandeau `alert-info` listant `admin@demo.fr / demo` et `jean@demo.fr / demo` ssi `Demo::isActive()`
- [ ] `php artisan demo:capture` produit un YAML versionné avec dates relatives, hash mots de passe écrasé en `demo`, tables d'infrastructure exclues, refus si > 1 asso, refus si `app()->environment('production')`
- [ ] `php artisan demo:reset` rejoue le snapshot YAML (dates rehydratées vs `now()`), copie les fichiers, garantit `php artisan up` en `finally`, refus si pas en démo
- [ ] Workflow `.github/workflows/deploy-demo.yml` se déclenche sur `push main`, en parallèle du workflow prod existant (qui reste inchangé)
- [ ] Suite Pest globale verte 0 failed
- [ ] Documentation : `docs/runbook-demo.md` créé, `CHANGELOG.md` mis à jour, mémoire projet à jour

## Steps

> Convention du plan : chaque step livre un commit committable et garde la suite verte. Les steps qui touchent du code Laravel sont **TDD strict** (RED-GREEN-REFACTOR avec Pest). Les steps ops (workflow GHA, scripts shell, docs) n'ont pas de TDD au sens strict — ils sont validés par lint/syntaxe + recette manuelle.

### Step 1 : Helper `App\Support\Demo`

**Complexity** : trivial
**RED** : `tests/Unit/Support/DemoTest.php` — `isActive()` retourne `false` en env `local|testing|production`, `true` ssi `app()->environment('demo')`.
**GREEN** : `app/Support/Demo.php` final class avec une seule méthode statique `isActive(): bool` qui délègue à `app()->environment('demo')`.
**REFACTOR** : aucun.
**Files** : `app/Support/Demo.php`, `tests/Unit/Support/DemoTest.php`
**Commit** : `feat(demo): introduce App\\Support\\Demo helper for env detection`

---

### Step 2 : Bridage mails — UI flash conditionnel

**Complexity** : standard
**Contexte** : `MAIL_MAILER=log` (Laravel-natif, géré par `.env.demo`) traite déjà la non-délivrance. Côté code : substituer le message flash sur les composants Livewire qui envoient un email (factures, devis, communication tiers, attestations) — typiquement un `session()->flash('success', 'Email envoyé')` à transformer en `'Email enregistré (mode démo)'` ssi `Demo::isActive()`.
**RED** : test feature qui simule l'envoi d'un email facture en démo et vérifie le flash message rendu côté Livewire.
**GREEN** : helper de message centralisé `App\Support\FlashMessages::emailSent(): string` (avec branchement `Demo::isActive()`) consommé par tous les call-sites identifiés.
**REFACTOR** : extraire la chaîne dans `lang/fr/demo.php` si plus de 2 occurrences.
**Files** : `app/Support/FlashMessages.php`, `lang/fr/demo.php`, ~3-5 composants Livewire d'envoi email, `tests/Feature/Demo/EmailFlashMessageTest.php`
**Commit** : `feat(demo): replace email-sent flash with demo-specific wording when demo is active`

---

### Step 3 : Bridage webhook HelloAsso — guard 200 no-op

**Complexity** : standard
**RED** : `tests/Feature/Demo/HelloAssoWebhookGuardTest.php` — POST `/webhooks/helloasso` en env démo retourne 200 sans persistance, log `helloasso.webhook.skipped_demo` émis. POST en env prod inchangé.
**GREEN** : early-return en début du `HelloAssoWebhookController::handle()` si `Demo::isActive()`.
**REFACTOR** : aucun.
**Files** : `app/Http/Controllers/HelloAssoWebhookController.php` (ou nom équivalent), `tests/Feature/Demo/HelloAssoWebhookGuardTest.php`
**Commit** : `feat(demo): skip HelloAsso webhook persistence in demo env`

---

### Step 4 : Bridage `helloasso:sync` — guard early-return

**Complexity** : standard
**RED** : `tests/Feature/Demo/HelloAssoSyncCommandGuardTest.php` — `$this->artisan('helloasso:sync')` en démo retourne 0, ne tape pas `HelloAssoSyncService`, log `helloasso.sync.skipped_demo` émis.
**GREEN** : guard en début de `HelloAssoSyncCommand::handle()`.
**Files** : `app/Console/Commands/HelloAssoSyncCommand.php` (chercher le nom exact), `tests/Feature/Demo/HelloAssoSyncCommandGuardTest.php`
**Commit** : `feat(demo): skip helloasso:sync command in demo env`

---

### Step 5 : Bridage `incoming-mail:fetch` — guard early-return

**Complexity** : standard
**RED** : `tests/Feature/Demo/IncomingMailFetchGuardTest.php` — commande retourne 0 sans appel IMAP, log `incoming-mail.skipped_demo` émis.
**GREEN** : guard en début de `IncomingMailFetchCommand::handle()` (déjà classe finale).
**Files** : `app/Console/Commands/IncomingMailFetchCommand.php`, `tests/Feature/Demo/IncomingMailFetchGuardTest.php`
**Commit** : `feat(demo): skip incoming-mail:fetch in demo env`

---

### Step 6 : Bridage OCR factures partenaires — stub statique

**Complexity** : standard
**RED** : `tests/Feature/Demo/InvoiceOcrStubTest.php` — `InvoiceOcrService::analyze($pdfPath)` en démo retourne un payload stub (`montant=100, date=now, libelle="Facture exemple"`) sans appel externe.
**GREEN** : guard `Demo::isActive()` au début de `analyze()` retourne le DTO stub.
**REFACTOR** : centraliser le stub dans une constante de classe.
**Files** : `app/Services/InvoiceOcrService.php`, `tests/Feature/Demo/InvoiceOcrStubTest.php`
**Commit** : `feat(demo): return static OCR stub in demo env`

---

### Step 7 : Guard suppression / archivage asso — `DemoOperationBlockedException`

**Complexity** : standard
**RED** : `tests/Feature/Demo/AssociationLifecycleGuardTest.php` — appel direct à `AssociationService::destroy($asso)` ou `archive($asso)` en démo lève `DemoOperationBlockedException` ; en prod le comportement existant est inchangé.
**GREEN** : nouvelle exception `App\Exceptions\DemoOperationBlockedException` (extends `RuntimeException`) + guards dans `destroy()` et `archive()`.
**REFACTOR** : si une troisième opération destructive est identifiée pendant l'audit, factoriser via un trait `BlocksInDemo`.
**Files** : `app/Exceptions/DemoOperationBlockedException.php`, `app/Services/AssociationService.php` (ou nom équivalent — chercher), `tests/Feature/Demo/AssociationLifecycleGuardTest.php`
**Commit** : `feat(demo): block destroy and archive of association in demo env`

---

### Step 8 : Middleware `EnforceDemoReadOnly` + bindings de routes

**Complexity** : standard
**RED** : `tests/Feature/Demo/EnforceDemoReadOnlyMiddlewareTest.php` —
- POST/PUT/PATCH/DELETE sur `parametres/smtp/*`, `parametres/helloasso/*`, `associations/{id}/destroy`, `associations/{id}/archive` en démo → 403 + log `demo.write_blocked`
- GET sur ces mêmes routes en démo → 200 (l'écran s'affiche)
- En prod → comportement inchangé
**GREEN** : `App\Http\Middleware\EnforceDemoReadOnly` + alias `demo.read-only` enregistré dans `app/Http/Kernel.php` + application sur les routes ciblées (group middleware dans `routes/web.php`).
**REFACTOR** : extraire la liste des routes protégées dans une constante du middleware (lisible et auditable).
**Files** : `app/Http/Middleware/EnforceDemoReadOnly.php`, `app/Http/Kernel.php`, `routes/web.php`, `tests/Feature/Demo/EnforceDemoReadOnlyMiddlewareTest.php`
**Commit** : `feat(demo): enforce read-only on sensitive parameter routes`

---

### Step 9 : Vue Livewire SMTP — bandeau + inputs disabled

**Complexity** : standard
**RED** : `tests/Feature/Demo/SmtpScreenReadOnlyTest.php` — en démo, le rendu Livewire de l'écran SMTP affiche un bandeau d'info, les inputs portent l'attribut `disabled`, le bouton "Enregistrer" est absent du DOM.
**GREEN** : bloc `@if(\App\Support\Demo::isActive())` autour des inputs (ajout `disabled`) + bouton (suppression conditionnelle) + bandeau Bootstrap en haut.
**REFACTOR** : extraire le bandeau en composant Blade `<x-demo-readonly-banner />` réutilisable pour l'écran HelloAsso.
**Files** : `resources/views/livewire/parametres/smtp.blade.php` (ou équivalent), `resources/views/components/demo-readonly-banner.blade.php`, `tests/Feature/Demo/SmtpScreenReadOnlyTest.php`
**Commit** : `feat(demo): SMTP settings screen read-only in demo env`

---

### Step 10 : Vue Livewire HelloAsso — bandeau + inputs disabled

**Complexity** : standard
**RED** : `tests/Feature/Demo/HelloAssoScreenReadOnlyTest.php` — même attendu que step 9 pour l'écran HelloAsso.
**GREEN** : application du même pattern (bloc conditionnel + composant `<x-demo-readonly-banner />` réutilisé).
**Files** : `resources/views/livewire/parametres/helloasso.blade.php` (ou équivalent), `tests/Feature/Demo/HelloAssoScreenReadOnlyTest.php`
**Commit** : `feat(demo): HelloAsso settings screen read-only in demo env`

---

### Step 11 : Bandeau `/login` démo

**Complexity** : trivial
**RED** : `tests/Feature/Demo/LoginBannerTest.php` — GET `/login` en démo contient le titre "Démonstration en ligne", `admin@demo.fr`, `jean@demo.fr`, classes CSS `alert alert-info` ; en env prod, la chaîne "Démonstration en ligne" est absente.
**GREEN** : composant Blade `<x-demo-login-banner />` (alert-info Bootstrap, tableau 2 lignes : email/mdp/rôle) + include conditionnel dans `resources/views/auth/login.blade.php`.
**REFACTOR** : aucun.
**Files** : `resources/views/components/demo-login-banner.blade.php`, `resources/views/auth/login.blade.php`, `tests/Feature/Demo/LoginBannerTest.php`
**Commit** : `feat(demo): conditional alert-info banner on /login listing demo accounts`

---

### Step 12 : Commande `demo:capture`

**Complexity** : complex
**Contexte** : extrait toutes les tables tenant-scopées + `association` + `users`, exclut `sessions`, `cache`, `cache_locks`, `failed_jobs`, `jobs`, `password_reset_tokens`, `personal_access_tokens`, `migrations`, `email_logs`, `incoming_mail_logs`. Convertit toute date/datetime en delta relatif (ex. `-13d`). Écrase les hash mots de passe avec un hash fixe de `demo` (constante de classe). Refuse si > 1 asso. Refuse si `app()->environment('production')`. Sortie YAML stable (clés triées).
**RED** : `tests/Feature/Demo/DemoCaptureCommandTest.php` — fixture DB avec asso + users + tiers + transactions + dates absolues. La commande produit un YAML qui :
- liste toutes les tables non exclues
- transforme `2026-04-15` en `-13d` quand capture le 2026-04-28
- écrase `password` par le hash de `demo`
- refuse avec exit ≠ 0 si 2 assos
- refuse avec exit ≠ 0 si env=production
**GREEN** : `App\Console\Commands\DemoCaptureCommand` + helper `App\Support\Demo\DateDelta` (ou inline) pour conversion date ↔ delta. Utilise `symfony/yaml` (déjà transitif Laravel).
**REFACTOR** : extraire la liste des tables exclues en `App\Support\Demo\SnapshotConfig::EXCLUDED_TABLES`.
**Files** : `app/Console/Commands/DemoCaptureCommand.php`, `app/Support/Demo/SnapshotConfig.php`, `app/Support/Demo/DateDelta.php`, `tests/Feature/Demo/DemoCaptureCommandTest.php`
**Commit** : `feat(demo): demo:capture command with relative dates and password override`

---

### Step 13 : Commande `demo:reset`

**Complexity** : complex
**Contexte** : `down --message="Réinitialisation démo en cours" --retry=60` → `migrate:fresh --force` → load YAML (réhydrate dates vs `now()`) → restaure fichiers depuis `database/demo/files/` vers `storage/app/associations/{id}/` → `up`. Tout en `try / finally` qui garantit `up`. Refuse si `! Demo::isActive()`.
**RED** : `tests/Feature/Demo/DemoResetCommandTest.php` —
- mini-snapshot fixture YAML chargé : count rows par table = attendu, dates rehydratées correctement (delta `-12d` de capture rejoué le 2026-05-15 = 2026-05-03)
- fichiers `database/demo/files/foo.pdf` copiés vers `storage/app/associations/1/factures/foo.pdf`
- snapshot YAML corrompu → la commande échoue mais `php artisan up` est rappelé en finally (vérifié via spy sur `Artisan::call`)
- env != demo → exit ≠ 0
- durée d'exécution (snapshot ≤ 500 lignes/table) < 30s (test pragmatique : assert sur un seuil large + benchmark indicatif)
**GREEN** : `App\Console\Commands\DemoResetCommand` réutilise `SnapshotConfig` + `DateDelta`. Bulk inserts via `DB::table($name)->insert($chunk)`.
**REFACTOR** : extraire `SnapshotLoader` si la commande dépasse 200 lignes.
**Files** : `app/Console/Commands/DemoResetCommand.php`, `app/Support/Demo/SnapshotLoader.php` (si extrait), `tests/Feature/Demo/DemoResetCommandTest.php`
**Commit** : `feat(demo): demo:reset command with try/finally up guarantee and date rehydration`

---

### Step 14 : Workflow GHA `deploy-demo.yml`

**Complexity** : standard
**Contexte** : calqué sur `deploy.yml` existant. Trigger `push: branches: [main]`. Réutilise les secrets prod (`CPANEL_USERNAME`, `CPANEL_API_TOKEN`, `CPANEL_SERVER`, `SSH_KEY`, `HOME_SSH_HOST`). Path serveur `~/public_html/demo.agoragestion.org/` (codé en dur ou via secret optionnel `O2SWITCH_DEMO_PATH`). Étapes : test Pest → whitelist IP cPanel → SSH déploiement (pull main, `composer install --no-dev`, `config:cache`, `migrate:fresh --force`, `demo:reset`, `up`) → unwhitelist IP.
**RED** : pas de TDD au sens strict pour un YAML GHA. Validation par : (1) `actionlint` sur le fichier (si dispo localement, sinon CI le rejettera), (2) push sur une branche temp pour observer le workflow se déclencher correctement (ou non, si erreur de syntaxe).
**GREEN** : `.github/workflows/deploy-demo.yml` rédigé.
**Files** : `.github/workflows/deploy-demo.yml`
**Commit** : `feat(demo): add deploy-demo.yml GitHub Actions workflow on push main`

---

### Step 15 : Documentation runbook + CHANGELOG + mémoire projet

**Complexity** : trivial
**Contexte** : aucun TDD. Audit final : la spec et le plan sont la source.
**Contenu de `docs/runbook-demo.md`** :
- Étapes O2Switch (sous-domaine, DB, SSL) — déjà fait par l'opérateur, documenter pour reproduction
- Création `.env.demo` côté serveur (template + variables)
- (Optionnel) ajout du secret GHA `O2SWITCH_DEMO_PATH`
- Construction du snapshot initial : recipe d'instantiation manuelle (asso `Les amis de la démo`, comptes `admin@demo.fr` / `jean@demo.fr`, ce qu'il faut peupler pour une bonne première impression)
- Cron `0 4 * * *` à poser après le premier déploiement
- Recette manuelle du premier déploiement
- Commandes utiles (`demo:capture`, `demo:reset`)
- Charte snapshot (taille, contenu attendu)
**Contenu CHANGELOG** : entrée S3 + version (v4.2.0).
**Contenu mémoire projet** : `project_demo_en_ligne.md` mis à jour "✅ S3 livré".
**Files** : `docs/runbook-demo.md`, `CHANGELOG.md`, `~/.claude/projects/.../memory/project_demo_en_ligne.md`, `~/.claude/projects/.../memory/MEMORY.md`
**Commit** : `docs(demo): add runbook + changelog entry for S3 demo en ligne`

---

## Complexity Classification (récap)

| Step | Titre | Classification |
|---|---|---|
| 1 | Helper `Demo` | trivial |
| 2 | Mail flash | standard |
| 3 | Webhook HelloAsso | standard |
| 4 | helloasso:sync | standard |
| 5 | incoming-mail:fetch | standard |
| 6 | OCR stub | standard |
| 7 | Asso destroy/archive | standard |
| 8 | Middleware read-only | standard |
| 9 | Vue SMTP | standard |
| 10 | Vue HelloAsso | standard |
| 11 | Bandeau /login | trivial |
| 12 | demo:capture | **complex** |
| 13 | demo:reset | **complex** |
| 14 | Workflow GHA | standard |
| 15 | Documentation | trivial |

## Pre-PR Quality Gate

- [ ] Tous les tests Pest passent (`./vendor/bin/sail test`)
- [ ] `vendor/bin/pint` clean
- [ ] `/code-review --changed` passe (security, perf, structure, naming)
- [ ] Documentation à jour (runbook, CHANGELOG, mémoire projet)
- [ ] Recette manuelle locale du flow `demo:capture` puis `demo:reset` validée
- [ ] Manuel : push sur une branche temporaire → vérifier que `deploy-demo.yml` ne se déclenche **pas** (trigger sur main uniquement)

## Risks & Open Questions

- **Liste des call-sites email pour Step 2** — à identifier au début du Step 2 (recherche `session()->flash('success', ...email...')` dans Livewire). Si > 8 occurrences, factoriser via une méthode helper ; si ≤ 3, modifier en place.
- **Composant Livewire SMTP / HelloAsso — chemins exacts** à confirmer au début des Steps 9/10 (`resources/views/livewire/parametres/smtp.blade.php` ou `helloasso.blade.php` ?).
- **Helper `DateDelta`** : granularité jour suffisante pour la démo. Pas de gestion d'heures/minutes (un timestamp `2026-04-15 14:30` capture en `-13d` puis se rejoue à `now()-13j` à `00:00:00` ou à l'heure du reset). Acceptable — la démo n'a pas besoin de précision sub-journalière.
- **Stratégie `down --retry=60`** : si le reset prend > 60s, les utilisateurs verront un 503 brut au lieu de la page maintenance. Le seuil 30s cible reste très en dessous, mais à monitorer en recette.
- **Premier déploiement** : nécessite que `.env.demo` soit posé côté serveur **avant** le premier push qui déclenche le workflow, sinon le déploiement échouera. Documenté dans le runbook (étape pré-déploiement obligatoire).
- **Secret `O2SWITCH_DEMO_PATH`** : décision à prendre au Step 14 — codé en dur dans le workflow (plus simple, moins flexible) vs secret GHA (cohérent avec le pattern prod, mais 1 secret de plus). **Proposition** : codé en dur — moins de surface, le path est public de toute façon.
- **Snapshot évolutif** : à chaque feature qui introduit une nouvelle table, le snapshot doit être recapturé pour la couvrir, sinon `migrate:fresh` créera la table mais `demo:reset` ne la peuplera pas → incohérence. Documenté dans le runbook (charte "à chaque feature majeure").

---

## Reading order conseillé pour /build

Steps 1 → 11 sont indépendants techniquement (juste tous dépendent du Step 1). Steps 12 et 13 dépendent du Step 1 et l'un de l'autre (Step 13 utilise les abstractions du Step 12). Step 14 peut être fait à tout moment après le Step 13. Step 15 en fin.

Ordre conseillé : 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13 → 14 → 15.

Possibilité de paralléliser via subagents : Steps 2-7 (bridage) et Step 11 (bandeau login) peuvent tourner en parallèle après Step 1. Steps 9-10 peuvent tourner en parallèle après Step 8.
