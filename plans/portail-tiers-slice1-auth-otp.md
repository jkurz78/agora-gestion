# Plan: Portail Tiers — Slice 1 (Authentification OTP)

**Created**: 2026-04-19
**Branch**: `feat/portail-tiers-slice1-auth-otp` (à créer)
**Status**: implemented (2026-04-19)
**Specs**: [docs/specs/2026-04-19-portail-tiers-slice1-auth-otp.md](../docs/specs/2026-04-19-portail-tiers-slice1-auth-otp.md) — Consistency Gate PASS

## Goal

Livrer la fondation d'authentification du portail Tiers self-care : routes publiques par association (`/portail/{slug}/...`), OTP 8 chiffres par email, garde isolée `tiers-portail`, page d'accueil placeholder prête à recevoir les futures features (NDF, attestations, reçus fiscaux). Zéro logique métier dans cette slice — l'objectif est de livrer une infrastructure d'auth testée et sécurisée, utilisable par les slices 2 et 3 du programme Notes de frais.

## Décisions actées (specs)

- URL : `/portail/{slug}/...` (prépare sous-domaines v3.1).
- OTP : 8 chiffres, TTL 10 min, max 3 tentatives, cooldown 15 min, renvoi 60 s, hashé en base.
- Session : 1 h glissante, garde `tiers-portail` isolée de `web`.
- Multi-Tiers avec même email autorisé → sélecteur post-OTP.
- Anti-énumération : réponse identique email connu/inconnu/archivé, temps constant.
- Tiers archivé traité comme email inconnu. **Dette notée** : le champ `archived` n'existe pas encore sur `tiers` — scénarios BDD associés marqués `@skip` en Slice 1 (à activer quand le champ arrivera). Décision Q1 : option (a).
- Session lifetime 60 min portail-only : middleware custom qui ne touche pas `config/session.php`. Décision Q2.
- Pas de captcha v0 — à réévaluer si abus constatés en prod. Décision Q3.
- Index `(association_id, email)` ajouté sur `tiers` dans le Step 1 (absent aujourd'hui, nécessaire pour la perf du lookup). Décision Q4.

## Acceptance Criteria

### Sécurité
- [ ] AC-1: OTP stocké uniquement hashé (jamais en clair en base)
- [ ] AC-2: Aucun log ne contient le code OTP ni le hash (CI grep zéro match)
- [ ] AC-3: Réponse HTTP identique pour email connu / inconnu (statut, body, redirect, flash)
- [ ] AC-4: Temps de réponse `POST /login` : écart moyen < 50 ms entre email connu et inconnu
- [ ] AC-5: 4ᵉ tentative OTP bloquée après 3 échecs sur la même clé `(asso, email)`
- [ ] AC-6: Cooldown 15 min bloque toute nouvelle demande OU tentative pour la même clé
- [ ] AC-7: Renvoi refusé si `now - last_sent_at < 60 s`
- [ ] AC-8: OTP single-use (race-condition : 2 verify parallèles → 1 seul succès)
- [ ] AC-9: Isolation cross-tenant — OTP émis pour asso A rejeté sur asso B
- [ ] AC-10: Garde `tiers-portail` indépendante de `web` (admin connecté ≠ Tiers connecté)

### Flux
- [ ] AC-11: Slug inexistant → HTTP 404
- [ ] AC-12: Email connu → redirect `/otp` + flash anti-énumération + email envoyé
- [ ] AC-13: Email inconnu → même redirect + même flash + aucun email
- [ ] AC-14: OTP valide + 1 Tiers → connexion + redirect `/`
- [ ] AC-15: OTP valide + N Tiers (N ≥ 2) → redirect `/choisir` + liste
- [ ] AC-16: Choix d'un Tiers → connexion sur la garde + redirect `/`
- [ ] AC-17: Accès `/` sans choix Tiers en pending → redirect `/choisir`
- [ ] AC-18: OTP expiré / invalide / consommé → message générique "Code invalide ou expiré"
- [ ] AC-19: Session expire à 60 min glissante → redirect login
- [ ] AC-20: POST `/logout` détruit la session + redirect login

### Home
- [ ] AC-21: Home affiche logo asso + "Bienvenue {prénom} {nom}"
- [ ] AC-22: Home affiche placeholder "Vos services arriveront bientôt : notes de frais, attestations de présence, reçus fiscaux…"
- [ ] AC-23: Home affiche bouton Déconnexion

### Observabilité
- [ ] AC-24: 6 événements loggés avec `association_id` : `portail.otp.requested`, `portail.otp.verified`, `portail.otp.failed`, `portail.cooldown.triggered`, `portail.login.success`, `portail.tiers.chosen`

### Conformité
- [ ] AC-25: Tous nouveaux fichiers PHP : `declare(strict_types=1)` + `final class` + type hints (pint vert)
- [ ] AC-26: Tous nouveaux modèles étendent `TenantModel`
- [ ] AC-27: Labels & messages en français
- [ ] AC-28: Suite test verte : 0 régression sur les 1839+ tests existants

## Steps

### Step 1: Migration, config, model TiersPortailOtp

**Complexity**: standard
**RED**:
- Test migration : `tiers_portail_otps` existe avec colonnes attendues (`id`, `association_id`, `email`, `code_hash`, `expires_at`, `consumed_at`, `attempts`, `last_sent_at`, timestamps) + index `(association_id, email)`.
- Test model : `TiersPortailOtp` extends `TenantModel`, casts corrects, fillable présent, TenantScope actif (query sans `TenantContext` booté → `WHERE 1 = 0`).
- Test config : `config('portail.otp_length') === 8`, valeurs par défaut toutes présentes.

**GREEN**:
- `database/migrations/YYYY_MM_DD_create_tiers_portail_otps_table.php`
- `database/migrations/YYYY_MM_DD_add_email_index_to_tiers_table.php` (index composé `(association_id, email)`)
- `app/Models/TiersPortailOtp.php` (extends `TenantModel`)
- `config/portail.php`

**REFACTOR**: None needed
**Files**: `database/migrations/*create_tiers_portail_otps*.php`, `app/Models/TiersPortailOtp.php`, `config/portail.php`, `tests/Feature/Portail/TiersPortailOtpModelTest.php`
**Commit**: `feat(portail): create tiers_portail_otps table, model and config`

---

### Step 2: OtpService::request — happy path (email connu)

**Complexity**: complex
**RED**:
- Test : `OtpService::request($asso, 'marie@example.org')` où Marie est Tiers de `$asso` →
  - Un enregistrement `TiersPortailOtp` créé avec `code_hash` défini (jamais le code en clair).
  - `expires_at` = now + 10 min.
  - `attempts = 0`, `consumed_at = null`, `last_sent_at = now`.
  - `Mail::assertSent(OtpMail::class)` avec code 8 chiffres + nom asso.

**GREEN**:
- `app/Services/Portail/OtpService.php::request()`
- `app/Mail/Portail/OtpMail.php` (stub minimal, rendering complet en Step 7)

**REFACTOR**: Extraire la génération du code dans une méthode privée testable (`generateCode(): string`).
**Files**: `app/Services/Portail/OtpService.php`, `app/Mail/Portail/OtpMail.php`, `tests/Feature/Portail/OtpRequestTest.php`
**Commit**: `feat(portail): OtpService::request sends 8-digit OTP to known Tiers email`

---

### Step 3: OtpService::request — anti-énumération + temps constant

**Complexity**: complex
**RED**:
- Test email inconnu : aucun enregistrement créé, aucun mail envoyé, retour identique (aucune exception).
- Test email connu vs inconnu : les deux chemins exécutent `Hash::make` (vérifié via spy sur la façade `Hash`), garantissant un temps d'exécution comparable.
- Test `Tiers` soft-deleted (ou archived si le champ existe — sinon `@skip`) : traité comme inconnu.

**GREEN**: Branche défensive dans `request()` — toujours exécuter le hash, ne persister/mailer que si Tiers trouvé.

**REFACTOR**: Extraire `findEligibleTiers(email): Collection` pour lisibilité.
**Files**: `app/Services/Portail/OtpService.php`, `tests/Feature/Portail/OtpAntiEnumerationTest.php`
**Commit**: `feat(portail): OtpService enforces anti-enumeration and constant-time response`

---

### Step 4: OtpService — renvoi 60 s

**Complexity**: standard
**RED**:
- Test : 2ᵉ appel à `request()` moins de 60 s après le précédent → ne crée pas de nouvel OTP, ne dispatche pas de mail, réponse service = `ResendResult::TooSoon($secondsRemaining)`.
- Test : 2ᵉ appel 61 s après → nouveau code généré, ancien OTP invalidé (marqué consommé), compteur `attempts` remis à 0.

**GREEN**: `OtpService::canResend()`, logique de remplacement dans `request()`.
**REFACTOR**: None needed
**Files**: `app/Services/Portail/OtpService.php`, `tests/Feature/Portail/OtpResendTest.php`
**Commit**: `feat(portail): enforce 60s minimum between OTP resends`

---

### Step 5: OtpService::verify + cooldown 15 min

**Complexity**: complex
**RED**: 6 tests couvrant tous les cas :
- OTP valide + single-Tiers → `VerifyResult::success([$tiersId])`, `consumed_at` posé atomiquement.
- OTP valide + multi-Tiers → `VerifyResult::success([$id1, $id2])`.
- OTP incorrect → `VerifyResult::invalid()`, `attempts` incrémenté.
- OTP expiré → `VerifyResult::invalid()`.
- OTP déjà consommé → `VerifyResult::invalid()`.
- 3 échecs consécutifs → 4ᵉ tentative `VerifyResult::cooldown()`, cooldown actif 15 min (vérifié par `cooldownActive()`).
- Race-condition : 2 verify parallèles sur le même code → 1 succès, 1 échec (test avec `DB::transaction` + `lockForUpdate`).

**GREEN**:
- `OtpService::verify(Association $a, string $email, string $code): VerifyResult`.
- `OtpService::cooldownActive(Association $a, string $email): bool` (via `RateLimiter` facade, clé `portail-otp:{asso_id}:{email_lower}`).
- Enum `App\Services\Portail\VerifyStatus` : `success`, `invalid`, `cooldown`.

**REFACTOR**: Extraire `markConsumed(TiersPortailOtp $otp)` transactionnel.
**Files**: `app/Services/Portail/OtpService.php`, `app/Services/Portail/VerifyResult.php`, `tests/Feature/Portail/OtpVerifyTest.php`
**Commit**: `feat(portail): verify OTP with attempts tracking and 15min cooldown`

---

### Step 6: OtpMail rendering final

**Complexity**: trivial
**RED**: Test rendering :
- Sujet `Votre code de connexion — {nom asso}`.
- Body contient le code 8 chiffres, rappel 10 min, nom de l'asso.
- Aucune variable sensible non-échappée (XSS).

**GREEN**: Markdown mailable `resources/views/mail/portail/otp.blade.php`.
**REFACTOR**: None needed
**Files**: `app/Mail/Portail/OtpMail.php`, `resources/views/mail/portail/otp.blade.php`, `tests/Feature/Portail/OtpMailRenderTest.php`
**Commit**: `feat(portail): finalize OTP email template (subject + body)`

---

### Step 7: Guard `tiers-portail` + Tiers Authenticatable

**Complexity**: complex
**RED**:
- Test : `Auth::guard('tiers-portail')->loginUsingId($tiersId)` → `Auth::guard('tiers-portail')->check() === true` et `user()` retourne le Tiers.
- Test : `Auth::guard('web')->check() === false` en parallèle (isolation).
- Test : la guard fonctionne sans mot de passe (Tiers n'a pas de champ `password`).

**GREEN**:
- Étendre `App\Models\Tiers` avec `implements Authenticatable` + trait `Illuminate\Auth\Authenticatable` (ou méthodes stub pour `getAuthPassword()` retournant `''`).
- `config/auth.php` : nouveau provider `tiers` + guard `tiers-portail`.

**REFACTOR**: None needed
**Files**: `app/Models/Tiers.php`, `config/auth.php`, `tests/Feature/Portail/GuardIsolationTest.php`
**Commit**: `feat(portail): add tiers-portail guard isolated from web guard`

---

### Step 8: Routes + middleware BootTenantFromSlug + layout portail

**Complexity**: standard
**RED**:
- Test `GET /portail/amis-quartier/login` → 200, le layout portail est rendu (contient logo asso placeholder, ne contient pas la sidebar app interne).
- Test `GET /portail/slug-inconnu/login` → 404.
- Test : après passage du middleware, `TenantContext::currentId()` = association_id du slug.

**GREEN**:
- `app/Http/Middleware/Portail/BootTenantFromSlug.php`.
- `routes/portail.php` (chargé via `bootstrap/app.php`).
- `resources/views/portail/layouts/app.blade.php` (layout Bootstrap 5 minimal, logo asso via accessor existant, nom asso, sans sidebar).
- Livewire stubs pour `Login`, `OtpVerify`, `ChooseTiers`, `Home` (render vide pour faire passer les 200).

**REFACTOR**: None needed
**Files**: `app/Http/Middleware/Portail/BootTenantFromSlug.php`, `routes/portail.php`, `bootstrap/app.php`, `resources/views/portail/layouts/app.blade.php`, `app/Livewire/Portail/{Login,OtpVerify,ChooseTiers,Home}.php` + vues, `tests/Feature/Portail/RoutingTest.php`
**Commit**: `feat(portail): scaffold routes, slug middleware and base layout`

---

### Step 9: Livewire Login — formulaire email

**Complexity**: standard
**RED**:
- Test Livewire : soumission avec email non-valide → erreur validation fr.
- Test : soumission email valide → `OtpService::request()` appelé avec (asso courante, email) ; redirect vers `/portail/{slug}/otp` ; flash message neutre affiché.
- Test : CSRF protection active.

**GREEN**: Implémenter `app/Livewire/Portail/Login.php` + vue `resources/views/livewire/portail/login.blade.php`.
**REFACTOR**: None needed
**Files**: `app/Livewire/Portail/Login.php`, `resources/views/livewire/portail/login.blade.php`, `tests/Feature/Portail/LoginFlowTest.php`
**Commit**: `feat(portail): Livewire login component requests OTP via OtpService`

---

### Step 10: Livewire OtpVerify — saisie & vérification OTP

**Complexity**: standard
**RED**:
- Test : saisie code valide (single-Tiers) → `AuthSessionService` invoqué, login effectué, redirect `/portail/{slug}`.
- Test : code invalide → message d'erreur générique, reste sur la page.
- Test : cooldown actif → affichage "Trop de tentatives, réessayez dans X minutes".
- Test : bouton "Renvoyer le code" → appelle `OtpService::request()` si `canResend` OK, sinon affiche compte à rebours.
- Test : champ accepte format "1234 5678" et "12345678".

**GREEN**: Implémenter composant + vue, brancher sur `OtpService` + (en Step 11) `AuthSessionService`.
**REFACTOR**: None needed
**Files**: `app/Livewire/Portail/OtpVerify.php`, `resources/views/livewire/portail/otp-verify.blade.php`, `tests/Feature/Portail/OtpVerifyFlowTest.php`
**Commit**: `feat(portail): Livewire OTP verification with resend and cooldown UI`

---

### Step 11: AuthSessionService + multi-Tiers pending

**Complexity**: standard
**RED**:
- Test : `markPendingTiers([$id1, $id2])` stocke en session sous clé `portail.pending_tiers_ids`.
- Test : `chooseTiers($id)` si `$id` est dans la liste pending → `Auth::guard('tiers-portail')->login($tiers)`, clé pending supprimée.
- Test : `chooseTiers($id)` avec id hors liste pending → `AuthorizationException`.
- Test : verify multi-Tiers → redirect `/choisir` au lieu de `/`.

**GREEN**: `app/Services/Portail/AuthSessionService.php`, intégré dans `OtpVerify`.
**REFACTOR**: Si `OtpVerify` devient long, extraire la décision redirect dans le service.
**Files**: `app/Services/Portail/AuthSessionService.php`, `app/Livewire/Portail/OtpVerify.php`, `tests/Feature/Portail/AuthSessionServiceTest.php`
**Commit**: `feat(portail): AuthSessionService handles multi-Tiers pending state`

---

### Step 12: Livewire ChooseTiers + middleware EnsureTiersChosen

**Complexity**: standard
**RED**:
- Test `GET /portail/{slug}/choisir` sans session pending → redirect `/login`.
- Test avec 2 Tiers pending → liste les 2 avec nom complet.
- Test clic sur Tiers → `AuthSessionService::chooseTiers()`, redirect `/`.
- Test middleware `EnsureTiersChosen` sur `/` avec pending → redirect `/choisir`.
- Test middleware sans pending et sans auth → pass-through (le middleware suivant s'occupe du redirect login).

**GREEN**:
- `app/Http/Middleware/Portail/EnsureTiersChosen.php`.
- `app/Livewire/Portail/ChooseTiers.php` + vue.

**REFACTOR**: None needed
**Files**: `app/Http/Middleware/Portail/EnsureTiersChosen.php`, `app/Livewire/Portail/ChooseTiers.php`, `resources/views/livewire/portail/choose-tiers.blade.php`, `tests/Feature/Portail/MultiTiersChooserTest.php`
**Commit**: `feat(portail): ChooseTiers screen and middleware for multi-Tiers email`

---

### Step 13: Middleware Authenticate + Livewire Home + Logout

**Complexity**: standard
**RED**:
- Test `GET /portail/{slug}` non-authentifié → redirect `/portail/{slug}/login`.
- Test `GET /portail/{slug}` authentifié → contient logo asso, "Bienvenue {prénom nom}", placeholder menu, bouton Déconnexion.
- Test `POST /portail/{slug}/logout` authentifié → session portail détruite, redirect login.
- Test expiration session (60 min glissante) avec `Carbon::setTestNow` → redirect login. **Lifetime géré par middleware portail custom** (timestamp d'activité en session), sans toucher `config/session.php`.

**GREEN**:
- `app/Http/Middleware/Portail/Authenticate.php` (redirect vers login de la bonne asso).
- `app/Livewire/Portail/Home.php` + vue (logo, welcome, placeholder menu, bouton logout).
- `app/Http/Controllers/Portail/LogoutController.php`.
- Configuration session lifetime (via middleware custom ou `config/session.php` — préférer lifetime per-guard).

**REFACTOR**: None needed
**Files**: `app/Http/Middleware/Portail/Authenticate.php`, `app/Livewire/Portail/Home.php`, `app/Http/Controllers/Portail/LogoutController.php`, vues, `tests/Feature/Portail/HomeAndLogoutTest.php`, `tests/Feature/Portail/SessionExpiryTest.php`
**Commit**: `feat(portail): home page with placeholder menu and logout`

---

### Step 14: Tests d'intrusion multi-tenant

**Complexity**: complex
**RED** (tests déjà attendus en fail — ils valident l'isolation) :
- Test : OTP émis sur asso A ne peut être consommé sur asso B (même code, même email).
- Test : session authentifiée sur asso A, `GET /portail/{slug-B}` → redirect login de B (session A conservée).
- Test : Tiers existant dans asso A mais pas dans asso B → demande OTP sur B = réponse anti-énumération, aucun email.
- Test : `TenantScope` fail-closed — si un bug bootait pas le `TenantContext`, la requête `TiersPortailOtp::where('email', …)` retournerait `WHERE 1 = 0`.

**GREEN**: tous les tests passent avec l'implémentation actuelle. Sinon, fixer les fuites.

**REFACTOR**: None needed
**Files**: `tests/Feature/Portail/TenantIsolationTest.php`
**Commit**: `test(portail): tenant intrusion tests on OTP, session and lookup paths`

---

### Step 15: Observabilité — logs structurés + doc finale

**Complexity**: trivial
**RED**:
- Test : après un parcours complet (login → OTP → home → logout), les 6 événements sont loggués (spy `Log::shouldReceive('info')`).
- Test : aucun log ne contient 8 chiffres consécutifs (regex `/\b\d{8}\b/`) ni de `code_hash`.

**GREEN**:
- Ajouter les `Log::info('portail.otp.requested', ['email' => …])` dans les services/components. `LogContext` portera `association_id` et `user_id` automatiquement.
- Rédiger `docs/portail-tiers.md` (flux + règles OTP + schéma d'auth).

**REFACTOR**: None needed
**Files**: `app/Services/Portail/OtpService.php`, `app/Services/Portail/AuthSessionService.php`, `docs/portail-tiers.md`, `tests/Feature/Portail/ObservabilityTest.php`
**Commit**: `feat(portail): structured logs + doc portail-tiers`

---

## Complexity Classification

| Step | Complexity | Agents prévus (build phase) |
|------|-----------|-----------------------------|
| 1 | standard | spec-compliance + structure-review + naming-review |
| 2 | complex | + security-review (OTP generation) |
| 3 | complex | + security-review (timing attacks) |
| 4 | standard | + test-review |
| 5 | complex | + security-review + concurrency-review |
| 6 | trivial | naming-review seul |
| 7 | complex | + security-review (auth primitives) |
| 8 | standard | + structure-review |
| 9 | standard | + svelte-review (Livewire) + test-review |
| 10 | standard | + svelte-review + test-review |
| 11 | standard | + structure-review |
| 12 | standard | + svelte-review |
| 13 | standard | + a11y-review (layout, logout button) |
| 14 | complex | + security-review (multi-tenant) |
| 15 | trivial | doc-review |

## Pre-PR Quality Gate

- [ ] `./vendor/bin/sail artisan test` — suite verte, 0 régression (≥ 1839 tests + ~30 nouveaux).
- [ ] `./vendor/bin/pint --test` — 0 violation.
- [ ] `/code-review --changed` — pas de FAIL, warnings traités ou justifiés.
- [ ] Smoke test manuel local : `sail up -d`, `migrate:fresh --seed`, parcours login → OTP → home sur `/portail/monasso/login` avec un Tiers seedé.
- [ ] Vérif visuelle sur mobile (viewport 375×667) du layout portail.
- [ ] `docs/portail-tiers.md` à jour.
- [ ] `MEMORY.md` — ajouter l'entrée de livraison à la fin du build.

## Risques & Questions Ouvertes

### Risques

- **R1 — Tiers sans trait `Authenticatable`** : impact possible sur d'autres parties de l'app si on touche au model `Tiers` (facturation, communication, etc.). *Mitigation* : ajouter le trait et `getAuthPassword()` retournant `''` ne casse aucun code existant — vérifier en Step 7 avec `sail artisan test --parallel` sur l'ensemble de la suite avant de committer.
- **R2 — `TenantContext` non booté sur routes publiques** : la route `/portail/{slug}/login` est publique (pas de session admin). Si `BootTenantFromSlug` ne boote pas correctement, les lookups Tiers sont scopés `WHERE 1 = 0`. *Mitigation* : Step 8 inclut un test explicite vérifiant `TenantContext::currentId()` après le middleware.
- **R3 — Guard multi-cookie** : Laravel peut stocker plusieurs identités authentifiées dans la même session (namespacing par clé `login_{guard}_*`). Un admin connecté sur `web` qui ouvre `/portail/{slug}/login` reste admin côté back-office tout en étant authentifié côté portail. *Mitigation* : c'est le comportement attendu (sessions indépendantes), documenté dans la spec, validé par AC-10.
- **R4 — Rate limiter partagé avec d'autres features** : si une autre fonction utilise `RateLimiter` sans préfixe, risque de collision. *Mitigation* : préfixe obligatoire `portail-otp:` dans la clé.

### Questions résolues (2026-04-19)

- **Q1** ✅ Scénarios "Tiers archivé" → tests `@skip` avec TODO, à activer quand le champ arrivera sur `Tiers`.
- **Q2** ✅ Session lifetime portail-only via middleware custom, `config/session.php` intouché.
- **Q3** ✅ Pas de captcha v0.
- **Q4** ✅ Index `(association_id, email)` ajouté dans Step 1.

## Livrables attendus

- 1 branche `feat/portail-tiers-slice1-auth-otp`, ~15 commits.
- 1 migration, 1 config, 1 model, 3 middlewares, 4 Livewire components, 1 controller logout, 2 services, 1 mailable, 1 layout + 4 vues Livewire + 1 vue mail.
- ~30 tests Pest (1 par scénario BDD + edge cases unitaires).
- 1 doc `docs/portail-tiers.md`.
- 0 feature métier — prêt pour Slice 2 (NDF portail).
