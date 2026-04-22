# Plan: Slug-first login brandé + mode mono-association

**Created**: 2026-04-22
**Branch**: à créer — `feat/slug-login-mono`
**Status**: implemented
**Specs**: `memory/project_slug_login_mono.md` (gate PASS)

## Goal

Fermer la dette multi-tenant `/login` (bug `TODO(S7)`) et introduire un slug-en-tête (`/{slug}/login`, `/{slug}/portail`) lisible et validé contre une blacklist. Ajouter un mode mono-association auto-détecté qui fait disparaître le slug de l'UX quand `Association::count() === 1` (cas `svs.soignervivresourire.fr`). Deux slices en dev, livraison unique en prod.

## Acceptance Criteria

- [ ] `/login` : logo produit AgoraGestion uniquement (multi-asso), auto-brandé asso (mono)
- [ ] `/{slug}/login` : logo asso + rejet email hors asso à la soumission avec message fr
- [ ] User authentifié visitant `/login` ou `/{slug}/login` → redirect `/dashboard`, session préservée
- [ ] `/{slug}/portail/*` opérationnel ; `/portail/{slug}/*` retourne 404 (cassure nette assumée)
- [ ] Création asso avec slug ∈ blacklist refusée avec erreur `"Slug réservé"` fr
- [ ] Mode mono actif : `/login` et `/portail/login` fonctionnent sans slug, slug jamais visible dans la barre
- [ ] Bascule mono→multi (création 2ème asso) : `/portail/login` devient 404, `/login` redevient neutre sans action manuelle
- [ ] Suite Pest verte, zéro régression sur admin/super-admin/tests intrusion multi-tenant
- [ ] `pint` sans correction, `/code-review --changed` PASS

## Steps

---

### Slice A — Slug-first (login brandé + portail)

### Step 1: Config `reserved_slugs` + règle `ReservedSlug`

**Complexity**: standard
**RED**: Test unitaire `ReservedSlug` rule — accepte `svs`, `exemple`, rejette `dashboard`, `login`, `portail`, `admin`, et les ~40 slugs de la blacklist.
**GREEN**: Créer `config/tenancy.php` avec `reserved_slugs` (~40 entrées) + `app/Rules/ReservedSlug.php` qui lit la config et rejette toute valeur présente (comparaison lowercase).
**REFACTOR**: None needed
**Files**: `config/tenancy.php`, `app/Rules/ReservedSlug.php`, `tests/Unit/Rules/ReservedSlugTest.php`
**Commit**: `feat(tenancy): introduce reserved_slugs config + ReservedSlug validation rule`

### Step 2: Appliquer `ReservedSlug` à la création/édition d'asso

**Complexity**: standard
**RED**: Feature test — super-admin tente de créer asso avec slug `"dashboard"` → erreur 422 `"Slug réservé"` ; avec `"monassoc"` → 201.
**GREEN**: Ajouter la règle dans le FormRequest/Livewire de création d'asso (super-admin) + wizard onboarding.
**REFACTOR**: Regrouper règles slug (`unique`, `regex`, `reserved`) dans méthode partagée si duplication apparaît.
**Files**: `app/Http/Requests/AssociationRequest.php` (ou équivalent Livewire), `app/Livewire/Onboarding/*`, tests feature correspondants
**Commit**: `feat(onboarding): reject reserved slugs on association creation and wizard`

### Step 3: Neutraliser `/login` — supprimer le fallback `Association::first()`

**Complexity**: standard
**RED**: Feature test — GET `/login` en multi-asso → la vue reçoit `$association === null`, le rendu contient `agoragestion-logo.svg` et ne contient aucun `logo_url` d'asso.
**GREEN**: Supprimer dans `LayoutAssociationComposerProvider::boot()` le fallback `Association::first()` ; laisser `CurrentAssociation::tryGet()` (peut être null). Adapter `layouts/guest.blade.php` : `@if($association ?? null) logo asso @else logo produit @endif`.
**REFACTOR**: Supprimer le `TODO(S7)`.
**Files**: `app/Providers/LayoutAssociationComposerProvider.php`, `resources/views/layouts/guest.blade.php`, `tests/Feature/Auth/LoginNeutralTest.php`
**Commit**: `fix(auth): neutral /login shows product logo instead of Association::first()`

### Step 4: Nouvelle route brandée `GET /{slug}/login`

**Complexity**: standard
**RED**: Feature test — GET `/svs/login` → 200, vue contient `logo_url` de SVS, titre page contient `"SVS"`. GET `/inconnu/login` → 404.
**GREEN**: Dans `routes/auth.php`, ajouter groupe `prefix('{association:slug}')->middleware(['web', BootTenantFromSlug::class])->where('association', '[a-z0-9-]+')` exposant GET+POST `login`. Réutiliser le middleware portail `BootTenantFromSlug`. Placer le groupe **après** les routes fixes.
**REFACTOR**: Extraire la regex slug dans une constante partagée si réutilisée.
**Files**: `routes/auth.php`, `tests/Feature/Auth/LoginBrandedTest.php`
**Commit**: `feat(auth): add /{slug}/login branded route`

### Step 5: Validation `email ↔ association` à la soumission de `/{slug}/login`

**Complexity**: complex
**RED**: Feature tests —
  - POST `/svs/login` avec `marie@exemple.fr` (appartient à Exemple) → 422, message `"Cet email n'est pas rattaché à l'association SVS."`, aucune session.
  - POST `/svs/login` avec `jean@svs.fr` + bon mot de passe → 302 vers `/dashboard`, TenantContext = SVS.
  - POST `/svs/login` avec `jean@svs.fr` + mauvais mot de passe → message générique `"Ces identifiants sont incorrects."`.
**GREEN**: Dans `AuthenticatedSessionController::store()`, si la requête est sur une route slug-first, charger `$association` depuis le binding et après résolution du user, vérifier `user.association_id === (int) $association->id`. Sinon, échouer avec message localisé avant de valider le password (timing : on peut vérifier en même temps que le password pour ne pas révéler l'existence de l'user — à ajuster).
**REFACTOR**: Isoler la logique de validation email↔asso dans une méthode privée testable.
**Files**: `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `lang/fr/auth.php`, `tests/Feature/Auth/LoginBrandedValidationTest.php`
**Commit**: `feat(auth): enforce email↔association match on /{slug}/login submit`

### Step 6: Redirect user déjà authentifié depuis `/login` et `/{slug}/login`

**Complexity**: standard
**RED**: Feature test — user connecté sur SVS visite `/svs/login`, `/exemple/login`, `/login` → chaque fois redirect vers `/dashboard`, session SVS inchangée, TenantContext reste SVS.
**GREEN**: Vérifier que le middleware `guest` est bien appliqué sur les 3 routes. Adapter si nécessaire pour que le redirect standard Laravel joue (`RouteServiceProvider::HOME`).
**REFACTOR**: None needed
**Files**: `routes/auth.php`, `tests/Feature/Auth/LoginAuthenticatedRedirectTest.php`
**Commit**: `feat(auth): redirect authenticated users away from login pages`

### Step 7: Migration prefix portail `/portail/{slug}/*` → `/{slug}/portail/*`

**Complexity**: standard
**RED**: Feature tests —
  - GET `/svs/portail/login` → 200, vue Login portail.
  - GET `/portail/svs/login` → 404.
  - Les 12 routes portail existantes (`login`, `otp`, `choisir`, `home`, `logout`, `ndf.*`, `logo`) répondent sous le nouveau prefix.
**GREEN**: Dans `routes/portail.php`, changer `prefix('portail/{association:slug}')` en `prefix('{association:slug}/portail')`. Vérifier que tous les `route('portail.*')` callers continuent de fonctionner (les noms sont stables). Ajuster templates Blade, emails (liens OTP, confirmations), tests existants qui URL-hardcodent.
**REFACTOR**: `rg '/portail/' --type=php --type=blade` pour traquer les URLs codées en dur.
**Files**: `routes/portail.php`, emails portail (`resources/views/emails/portail/*`), tests portail existants
**Commit**: `refactor(portail): move slug to first URL segment — /{slug}/portail/*`

---

### Slice B — Mode mono-association

### Step 8: Helper `MonoAssociation::isActive()` memoized

**Complexity**: standard
**RED**: Test unitaire —
  - 1 asso seedée → `isActive()` true.
  - 2 asso seedées → false.
  - Appel répété → ne refait pas de requête SQL (compter les queries avec `DB::getQueryLog()`).
**GREEN**: Créer `app/Support/MonoAssociation.php` avec méthode statique `isActive(): bool` qui memoize via propriété statique sur la durée d'une requête (reset via méthode `flush()` pour les tests).
**REFACTOR**: None needed
**Files**: `app/Support/MonoAssociation.php`, `tests/Unit/Support/MonoAssociationTest.php`
**Commit**: `feat(tenancy): add MonoAssociation::isActive() helper`

### Step 9: Middleware `MonoAssociationResolver`

**Complexity**: complex
**RED**: Feature tests —
  - Mode mono, request sur route sans slug → middleware boote `TenantContext` sur `Association::first()`.
  - Mode multi, même route → middleware passe sans booter, `TenantContext::currentId()` reste null.
  - Mode mono, route avec slug déjà présent → middleware no-op (laisse `BootTenantFromSlug` faire le travail).
**GREEN**: Créer `app/Http/Middleware/MonoAssociationResolver.php`. Si `MonoAssociation::isActive()` et `TenantContext::currentId()` est null : boote sur `Association::first()`.
**REFACTOR**: None needed
**Files**: `app/Http/Middleware/MonoAssociationResolver.php`, `tests/Feature/Middleware/MonoAssociationResolverTest.php`
**Commit**: `feat(tenancy): add MonoAssociationResolver middleware for single-association instances`

### Step 10: `/login` auto-brandé en mode mono

**Complexity**: standard
**RED**: Feature test — mode mono (1 seule asso "SVS"), GET `/login` → vue contient `logo_url` de SVS, TenantContext = SVS dans la vue.
**GREEN**: Appliquer `MonoAssociationResolver` sur la route `/login` (après `web`, avant `guest`).
**REFACTOR**: None needed
**Files**: `routes/auth.php`, `tests/Feature/Auth/LoginMonoBrandingTest.php`
**Commit**: `feat(auth): auto-brand /login with the sole association in mono mode`

### Step 11: `/portail/login` (sans slug) en mode mono

**Complexity**: standard
**RED**: Feature tests —
  - Mode mono, GET `/portail/login` → 200, vue Login portail avec logo SVS.
  - Mode mono, GET `/portail/otp?token=…` → 200.
  - Mode mono, GET `/svs/portail/login` reste accessible (parallèle).
  - Mode multi, GET `/portail/login` → 404.
**GREEN**: Dans `routes/portail.php`, ajouter un deuxième groupe `Route::prefix('portail')->middleware(['web', MonoAssociationResolver::class, RequireMono::class])->group(...)` qui expose les mêmes Livewire components que le groupe slug-first. Introduire middleware `RequireMono` qui abort(404) si `!MonoAssociation::isActive()`.
**REFACTOR**: Éviter duplication du groupe : extraire les `Route::*` dans une closure partagée entre les deux prefixes si le code devient verbeux.
**Files**: `routes/portail.php`, `app/Http/Middleware/RequireMono.php`, `tests/Feature/Portail/PortailMonoTest.php`
**Commit**: `feat(portail): expose /portail/* without slug in mono-association mode`

### Step 12: Bascule mono→multi immédiate

**Complexity**: standard
**RED**: Feature test —
  - Setup mode mono (1 asso SVS), `GET /portail/login` → 200.
  - Créer 2ème asso "Exemple" via super-admin.
  - Appeler `MonoAssociation::flush()` (ou la mémo expire naturellement entre requêtes).
  - `GET /portail/login` → 404. `GET /login` → logo AgoraGestion neutre.
**GREEN**: Vérifier que la mémoïsation de `MonoAssociation::isActive()` est scoped par request (pas de cache global). Au besoin, invalider dans l'observer `Association::created`.
**REFACTOR**: Documenter dans le code que la valeur est scoped request.
**Files**: `app/Support/MonoAssociation.php`, `app/Models/Association.php` (observer), `tests/Feature/Tenancy/MonoToMultiSwitchTest.php`
**Commit**: `feat(tenancy): mono→multi switch disables sluggess URLs on next request`

---

### Step 13: Vérif transverse — zéro régression + scénarios d'intrusion

**Complexity**: standard
**RED**: Relancer la suite Pest complète + les 12 tests d'intrusion multi-tenant existants (`tests/Feature/Tenancy/Intrusion/*`). Ajouter 2 tests :
  - User de SVS en mode multi tente `/exemple/login` + bonnes creds Exemple → bascule autorisée uniquement après `/logout` explicite.
  - Après bascule mono→multi, un user lié à SVS ne voit jamais l'asso "Exemple" via `/portail/login`.
**GREEN**: Corriger toute régression détectée.
**REFACTOR**: None
**Files**: `tests/Feature/Tenancy/Intrusion/*`, fichiers corrigés au besoin
**Commit**: `test(tenancy): cross-slice intrusion coverage for slug-first and mono modes`

---

## Pre-PR Quality Gate

- [ ] Suite Pest complète verte (y compris intrusion multi-tenant)
- [ ] `./vendor/bin/pint` sans correction
- [ ] `/code-review --changed` PASS (agents security-review, multi-tenant, test-review, spec-compliance)
- [ ] Les 17 scénarios BDD des specs sont implémentés en tests feature (mapping 1-pour-1)
- [ ] Recette manuelle locale en mode multi (2 asso) ET mode mono (1 asso seedée)
- [ ] Documentation : mettre à jour `docs/multi-tenancy.md` section URLs + créer `docs/mono-association.md` si nécessaire
- [ ] Release notes : préparer le changelog v4.2.0

## Risks & Open Questions

- **R1 — Emails déjà en file** avec liens `/portail/{slug}/…` : validé aucun en prod, mais vérifier la queue locale avant migration en staging.
- **R2 — Wizard onboarding** (S5 livré v4.0.0) peut contenir des `Validator::make` sur le slug qui dupliquent partiellement `ReservedSlug` — à harmoniser au Step 2.
- **R3 — Timing attack email↔asso validation** (Step 5) : vérifier avec security-review si le message explicite `"Cet email n'est pas rattaché à l'association X"` est acceptable vs message générique. Spec dit OK (slug public), mais double-check.
- **R4 — Recette locale "phase bizarre"** entre fin Slice A et début Slice B : mode mono ne fonctionnera pas entre Step 7 et Step 10. Ne pas déployer en staging avant Step 12.
- **R5 — `BootTenantFromSlug` middleware existant** (routes portail) est-il directement réutilisable sur `/{slug}/login` ou faut-il une variante ? À vérifier Step 4.
- **Q1 — Nomenclature config** : préférer `config/tenancy.php` (nouveau fichier) ou étendre une config existante comme `config/multitenancy.php` si elle existe ? À confirmer au Step 1.
