# Spec — Portail Tiers, Slice 1 : Authentification OTP

> **Date** : 2026-04-19
> **Auteur** : Jurgen Kurz (+ assistant agent)
> **Statut** : Spec validée — prête pour `/plan` puis `/build`
> **Parent** : Programme "Notes de frais" (3 slices)
> **Slice** : 1/3 (fondation portail + auth) — cf. slices 2 et 3 pour spécifications ultérieures

---

## Slices du programme "Notes de frais"

1. **Slice 1 — Fondation portail Tiers (auth OTP)** — *cette spec*.
2. **Slice 2 — Écran NDF côté portail** (saisie, consultation, suivi) — spec à venir.
3. **Slice 3 — Back-office NDF comptable** (validation, rejet, comptabilisation, Transaction) — spec à venir.

---

## 1. Intent Description

### Titre
**Fondation portail Tiers — authentification OTP par email**

### Résumé
Livrer une page d'accueil sécurisée par association (`/portail/{slug}`), accessible à tout Tiers existant via un code OTP envoyé par email, sans création de compte utilisateur applicatif. Cette fondation est l'ossature sur laquelle viendront se brancher des features métier self-care (NDF, attestations, reçus fiscaux, factures, formulaires d'opérations…) au fil des slices suivantes.

### Contexte & motivation
L'application n'expose aujourd'hui aucune surface aux Tiers : tout passe par les utilisateurs applicatifs (Admin/Comptable/Gestionnaire). Avec la croissance des besoins (NDF, attestations, formulaires participants…), il devient nécessaire d'ouvrir une zone publique authentifiée *par Tiers*. Plutôt que de bricoler un magic-link à usage unique pour chaque feature (pattern `FormulaireToken` actuel, peu scalable), on construit une **fondation d'authentification réutilisable** : un Tiers se connecte une fois, accède à toutes les features qui lui sont destinées.

### Périmètre livré

**Routes & flux d'authentification.**
- `GET /portail/{association:slug}/login` — formulaire email.
- `POST /portail/{association:slug}/login` — soumission email → envoi OTP 8 chiffres si Tiers reconnu ; message neutre identique dans les deux cas (anti-énumération).
- `GET /portail/{association:slug}/otp` — formulaire OTP (8 chiffres).
- `POST /portail/{association:slug}/otp` — vérification → sélecteur Tiers (si multi-Tiers) ou home.
- `GET /portail/{association:slug}/choisir` — sélection de Tiers si plusieurs candidats pour l'email.
- `GET /portail/{association:slug}` — home avec logo + "Bienvenue {prénom nom}" + placeholder menu.
- `POST /portail/{association:slug}/logout`.

**Règles OTP.**
- 8 chiffres, généré aléatoirement, stocké hashé.
- Validité 10 minutes.
- Max 3 tentatives ; au-delà, cooldown 15 minutes (par couple email+association).
- Renvoi possible après 60 secondes (invalide l'OTP précédent).
- Single-use : invalidé après une vérification réussie.

**Session portail.**
- Durée 1 h glissante.
- Garde séparée `tiers-portail` — pas d'interaction avec la garde `web`.
- Scopée à l'association du slug.

**Multi-Tiers partagés (famille).**
- Si un email correspond à plusieurs Tiers dans l'asso, l'utilisateur choisit lequel incarner après OTP.
- Pas de switch en session : logout + re-OTP pour changer.

**Multi-tenancy.**
- `TenantContext::boot($association)` en middleware portail.
- Tous les nouveaux modèles étendent `TenantModel`.

### Hors scope Slice 1
- Toute feature métier (NDF → Slice 2).
- Notifications email autres que l'OTP.
- Récupération de compte / changement d'email Tiers depuis le portail.
- Sous-domaines par association (v3.1).
- Captcha (à étudier si abus constatés).
- 2FA additionnelle.
- Ajout du champ `civilité` sur Tiers (dette notée).

### Succès mesurable
- Un Tiers existant reçoit son OTP en < 30 s, se connecte et voit son nom + le placeholder.
- Un email inconnu reçoit le même message neutre, sans email envoyé.
- Aucun accès cross-tenant possible (vérifié par tests d'intrusion).
- Tentatives de brute-force OTP bloquées après 3 essais.

---

## 2. User-Facing Behavior (BDD / Gherkin)

Fichier cible (référence contractuelle, les tests Pest s'en inspirent) : `tests/Feature/Portail/AuthenticationOtp.feature`.

```gherkin
# language: fr
Fonctionnalité: Authentification OTP du portail Tiers
  En tant que Tiers d'une association
  Je veux me connecter au portail self-care de mon association
  Afin d'accéder aux services qui me sont destinés

  Contexte:
    Étant donné l'association "Les Amis du Quartier" de slug "amis-quartier"
    Et un Tiers "Marie Dupont" d'email "marie@example.org" rattaché à cette association

  # ─── Connexion : saisie de l'email ────────────────────────────────────────

  Scénario: Demande d'OTP avec un email connu
    Étant donné que je visite "/portail/amis-quartier/login"
    Quand je saisis "marie@example.org" dans le champ "Email"
    Et je clique sur "Recevoir mon code"
    Alors un email contenant un OTP de 8 chiffres est envoyé à "marie@example.org"
    Et l'OTP est enregistré avec une expiration de 10 minutes
    Et je suis redirigé vers "/portail/amis-quartier/otp"
    Et je vois "Si votre adresse est reconnue, un code à 8 chiffres vous a été envoyé."

  Scénario: Demande d'OTP avec un email inconnu (anti-énumération)
    Étant donné que je visite "/portail/amis-quartier/login"
    Quand je saisis "inconnu@example.org" dans le champ "Email"
    Et je clique sur "Recevoir mon code"
    Alors aucun email n'est envoyé
    Et aucun OTP n'est enregistré
    Et je suis redirigé vers "/portail/amis-quartier/otp"
    Et je vois exactement le même message "Si votre adresse est reconnue, un code à 8 chiffres vous a été envoyé."

  Scénario: Demande d'OTP avec un slug inexistant
    Quand je visite "/portail/asso-fantome/login"
    Alors je reçois une réponse HTTP 404

  # ─── Vérification de l'OTP ────────────────────────────────────────────────

  Scénario: Vérification OTP réussie (cas par défaut)
    Étant donné qu'un OTP "12345678" a été émis pour "marie@example.org" sur "amis-quartier" il y a 2 minutes
    Et que "marie@example.org" correspond à exactement 1 Tiers dans "amis-quartier"
    Quand je soumets "12345678" sur "/portail/amis-quartier/otp"
    Alors l'OTP est marqué consommé
    Et je suis authentifié en tant que ce Tiers
    Et je suis redirigé vers "/portail/amis-quartier"

  Scénario: OTP invalide
    Étant donné qu'un OTP "12345678" a été émis pour "marie@example.org" sur "amis-quartier"
    Et que je visite "/portail/amis-quartier/otp"
    Quand je saisis "00000000" dans le champ "Code"
    Et je clique sur "Valider"
    Alors je ne suis pas authentifié
    Et je vois "Code invalide ou expiré"
    Et le compteur de tentatives pour "marie@example.org" sur "amis-quartier" passe à 1

  Scénario: OTP expiré
    Étant donné qu'un OTP "12345678" a été émis pour "marie@example.org" sur "amis-quartier" il y a 11 minutes
    Quand je soumets "12345678" sur "/portail/amis-quartier/otp"
    Alors je ne suis pas authentifié
    Et je vois "Code invalide ou expiré"

  Scénario: OTP déjà consommé
    Étant donné qu'un OTP "12345678" a été consommé pour "marie@example.org" sur "amis-quartier"
    Quand je soumets "12345678" sur "/portail/amis-quartier/otp"
    Alors je ne suis pas authentifié
    Et je vois "Code invalide ou expiré"

  Scénario: Cooldown après 3 tentatives échouées
    Étant donné qu'un OTP "12345678" a été émis pour "marie@example.org" sur "amis-quartier"
    Quand je soumets "00000000" trois fois de suite sur "/portail/amis-quartier/otp"
    Alors je vois "Trop de tentatives. Réessayez dans 15 minutes."
    Et toute nouvelle soumission d'OTP pour "marie@example.org" sur "amis-quartier" est bloquée pendant 15 minutes
    Et toute nouvelle demande d'OTP pour "marie@example.org" sur "amis-quartier" est également bloquée pendant 15 minutes

  # ─── Renvoi d'OTP ─────────────────────────────────────────────────────────

  Scénario: Renvoi d'OTP trop tôt
    Étant donné qu'un OTP a été émis pour "marie@example.org" sur "amis-quartier" il y a 30 secondes
    Quand je clique sur "Renvoyer le code" sur "/portail/amis-quartier/otp"
    Alors aucun nouvel email n'est envoyé
    Et je vois "Veuillez patienter encore 30 secondes avant de demander un nouveau code."

  Scénario: Renvoi d'OTP autorisé
    Étant donné qu'un OTP "12345678" a été émis pour "marie@example.org" sur "amis-quartier" il y a 70 secondes
    Quand je clique sur "Renvoyer le code" sur "/portail/amis-quartier/otp"
    Alors un nouvel OTP "87654321" est envoyé à "marie@example.org"
    Et l'OTP "12345678" est invalidé
    Et le compteur de tentatives est remis à zéro

  # ─── Sélection de Tiers (email partagé) ───────────────────────────────────

  Scénario: Email associé à un seul Tiers — pas de sélecteur
    Étant donné que "marie@example.org" correspond à 1 Tiers "Marie Dupont" dans "amis-quartier"
    Quand je soumets un OTP valide sur "/portail/amis-quartier/otp"
    Alors je suis redirigé directement vers "/portail/amis-quartier"
    Et je vois "Bienvenue Marie Dupont"

  Scénario: Email associé à plusieurs Tiers — sélecteur affiché
    Étant donné que "famille@example.org" correspond à 2 Tiers "Marie Dupont" et "Paul Dupont" dans "amis-quartier"
    Quand je soumets un OTP valide sur "/portail/amis-quartier/otp"
    Alors je suis redirigé vers "/portail/amis-quartier/choisir"
    Et je vois la liste "Marie Dupont" et "Paul Dupont"
    Et je ne suis pas encore lié à un Tiers spécifique en session

  Scénario: Choix d'un Tiers après sélecteur
    Étant donné que je viens de valider un OTP sur "/portail/amis-quartier/choisir" pour "famille@example.org" (2 Tiers)
    Quand je clique sur "Paul Dupont"
    Alors je suis authentifié en tant que Paul Dupont sur la garde "tiers-portail"
    Et je suis redirigé vers "/portail/amis-quartier"
    Et je vois "Bienvenue Paul Dupont"

  Scénario: Accès direct à la page d'accueil sans avoir choisi un Tiers
    Étant donné que je viens de valider un OTP pour "famille@example.org" mais n'ai pas encore choisi de Tiers
    Quand je visite "/portail/amis-quartier"
    Alors je suis redirigé vers "/portail/amis-quartier/choisir"

  Scénario: Changement de Tiers en cours de session
    Étant donné que je suis authentifié en tant que Marie Dupont sur "amis-quartier"
    Et que mon email "famille@example.org" correspond à 2 Tiers
    Quand je veux me connecter en tant que Paul Dupont
    Alors je dois me déconnecter et redemander un OTP
    Et il n'existe aucun bouton "Changer de Tiers" dans l'interface en Slice 1

  # ─── Page d'accueil portail ───────────────────────────────────────────────

  Scénario: Affichage de la page d'accueil après connexion
    Étant donné que je suis authentifié en tant que Marie Dupont sur le portail "amis-quartier"
    Quand je visite "/portail/amis-quartier"
    Alors je vois le logo de l'association "Les Amis du Quartier"
    Et je vois "Bienvenue Marie Dupont"
    Et je vois la mention "Vos services arriveront bientôt : notes de frais, attestations de présence, reçus fiscaux…"
    Et je vois un bouton "Déconnexion"

  Scénario: Accès non authentifié à la page d'accueil
    Étant donné que je ne suis pas authentifié
    Quand je visite "/portail/amis-quartier"
    Alors je suis redirigé vers "/portail/amis-quartier/login"

  # ─── Isolation multi-tenant ───────────────────────────────────────────────

  Scénario: Un Tiers connecté pour une asso ne peut pas accéder à une autre asso
    Étant donné l'association "Les Amis du Quartier" de slug "amis-quartier"
    Et l'association "Club Voile" de slug "club-voile"
    Et que Marie Dupont est Tiers de "amis-quartier" mais pas de "club-voile"
    Et que je suis authentifié en tant que Marie Dupont sur "amis-quartier"
    Quand je visite "/portail/club-voile"
    Alors je suis redirigé vers "/portail/club-voile/login"
    Et ma session "amis-quartier" reste active

  Scénario: Un même email Tiers existant dans deux associations reste cloisonné
    Étant donné que "marie@example.org" est Tiers dans "amis-quartier" ET dans "club-voile"
    Et que je demande un OTP sur "/portail/amis-quartier/login"
    Quand je tente d'utiliser cet OTP sur "/portail/club-voile/otp"
    Alors je ne suis pas authentifié
    Et je vois "Code invalide ou expiré"

  # ─── Session ──────────────────────────────────────────────────────────────

  Scénario: Expiration de session après 1 heure d'inactivité
    Étant donné que je suis authentifié en tant que Marie Dupont sur "amis-quartier" depuis 1h01
    Sans aucune action depuis ma connexion
    Quand je visite "/portail/amis-quartier"
    Alors je suis redirigé vers "/portail/amis-quartier/login"

  Scénario: Déconnexion explicite
    Étant donné que je suis authentifié en tant que Marie Dupont sur "amis-quartier"
    Quand je clique sur "Déconnexion"
    Alors ma session portail est détruite
    Et je suis redirigé vers "/portail/amis-quartier/login"

  # ─── Cas Tiers désactivé ──────────────────────────────────────────────────

  Scénario: Tiers archivé refuse l'OTP
    Étant donné que Marie Dupont est marquée comme archivée dans "amis-quartier"
    Quand je saisis "marie@example.org" sur "/portail/amis-quartier/login"
    Alors aucun email n'est envoyé
    Et je vois le message neutre "Si votre adresse est reconnue…"
```

---

## 3. Architecture Specification

### 3.1 Composants nouveaux

**Modèles & tables.**

| Table | Modèle | Champs clés | Notes |
|---|---|---|---|
| `tiers_portail_otps` | `App\Models\TiersPortailOtp` (extends `TenantModel`) | `id`, `association_id`, `email`, `code_hash`, `expires_at`, `consumed_at`, `attempts`, `last_sent_at`, timestamps | `code_hash` = `Hash::make($code)`. Index `(association_id, email)`. |

**Garde d'authentification** (`config/auth.php`)
- Guard `tiers-portail` (driver `session`, provider `tiers`).
- Provider `tiers` (driver `eloquent`, model `App\Models\Tiers`).

**Middleware** (`app/Http/Middleware/Portail/`)
- `BootTenantFromSlug` — résout `{association:slug}`, boot `TenantContext`, partage `$association` aux vues.
- `EnsureTiersChosen` — redirige vers `/choisir` si multi-Tiers pending.
- `Authenticate` — redirect vers `/portail/{slug}/login`.

**Services** (`app/Services/Portail/`)
- `OtpService` : `request()`, `verify()`, `canResend()`, `cooldownActive()`.
- `AuthSessionService` : `markPendingTiers()`, `chooseTiers()`.

**Livewire** (`app/Livewire/Portail/`) — pages pleine-page
- `Login`, `OtpVerify`, `ChooseTiers`, `Home`.

**Mail** (`app/Mail/Portail/`)
- `OtpMail` — Markdown mailable, transactionnel système (non éditable).

**Routes** (`routes/portail.php`)
```php
Route::prefix('portail/{association:slug}')
    ->middleware(['web', \App\Http\Middleware\Portail\BootTenantFromSlug::class])
    ->name('portail.')
    ->group(function () {
        Route::get('/login', Login::class)->name('login');
        Route::get('/otp', OtpVerify::class)->name('otp');
        Route::get('/choisir', ChooseTiers::class)->name('choisir');
        Route::middleware([
            \App\Http\Middleware\Portail\EnsureTiersChosen::class,
            \App\Http\Middleware\Portail\Authenticate::class,
        ])->group(function () {
            Route::get('/', Home::class)->name('home');
            Route::post('/logout', LogoutController::class)->name('logout');
        });
    });
```

**Config** (`config/portail.php`)
```php
return [
    'otp_length' => 8,
    'otp_ttl_minutes' => 10,
    'otp_max_attempts' => 3,
    'otp_cooldown_minutes' => 15,
    'otp_resend_seconds' => 60,
    'session_lifetime_minutes' => 60,
];
```

### 3.2 Contraintes & invariants

- **Aucune fuite cross-tenant** — `TenantScope` fail-closed (v4 S6). Lookups Tiers faits *après* boot.
- **Anti-énumération** — réponse HTTP identique, temps d'exécution constant.
- **OTP single-use atomique** — `DB::transaction()` au `consume()`.
- **Pas de stockage en clair** — jamais loggué, jamais en base.
- **Garde isolée** — `tiers-portail` disjointe de `web`.
- **Rate limiting** — clé `portail-otp:{association_id}:{email_lower}`.
- **Tiers archivés** — exclus du lookup, comportement = email inconnu.
- **Logs** — 6 événements, `LogContext` porte `association_id` automatiquement.

### 3.3 Structure de fichiers

```
app/
  Http/Middleware/Portail/{BootTenantFromSlug,EnsureTiersChosen,Authenticate}.php
  Livewire/Portail/{Login,OtpVerify,ChooseTiers,Home}.php
  Http/Controllers/Portail/LogoutController.php
  Mail/Portail/OtpMail.php
  Models/TiersPortailOtp.php
  Services/Portail/{OtpService,AuthSessionService}.php
config/portail.php
database/migrations/YYYY_MM_DD_create_tiers_portail_otps_table.php
resources/views/
  portail/layouts/app.blade.php
  livewire/portail/{login,otp-verify,choose-tiers,home}.blade.php
  mail/portail/otp.blade.php
routes/portail.php
tests/Feature/Portail/{AuthenticationOtpTest,TenantIsolationTest,RateLimitingTest,MultiTiersChooserTest}.php
```

### 3.4 Préparé pour les slices à venir

- **v3.1 sous-domaines** : ajouter un alias de route — code Livewire/services inchangé.
- **Slice 2 (NDF portail)** : nouvelles routes sous même préfixe + menu dans `home.blade.php`.
- **Slice 3 (back-office NDF)** : zéro impact sur code Slice 1.

---

## 4. Acceptance Criteria

### Sécurité
- **S1** OTP stocké hashé uniquement (bcrypt/argon).
- **S2** OTP jamais dans les logs (CI grep).
- **S3** Réponse HTTP identique email connu/inconnu/archivé (égalité stricte statut/body/redirect/flash).
- **S4** 4ᵉ tentative bloquée après 3 échecs en < 15 min.
- **S5** Renvoi refusé si < 60 s.
- **S6** Isolation cross-tenant : OTP asso A refusé sur asso B.
- **S7** Garde `tiers-portail` indépendante de `web`.
- **S8** Tiers archivé → message neutre, 0 email.
- **S9** OTP single-use atomique (test race condition).
- **S10** Validation entrée OTP stricte (8 chiffres).

### Performance
- **P1** `POST /login` < 800 ms p95.
- **P2** `POST /otp` < 300 ms p95.
- **P3** Email inbox < 30 s p95.
- **P4** Temps constant handler login : écart moyenne < 50 ms entre email connu/inconnu.

### Fiabilité
- **F1** Session expire à 60 min glissante.
- **F2** `TenantContext` booté avant toute requête Eloquent.
- **F3** OTP expiré après 10 min.
- **F4** Cleanup OTP : dette acceptée, suppression paresseuse au `verify()` acceptable.

### UX
- **U1** Logo + nom asso visible sans scroll sur mobile 375×667.
- **U2** 100% messages d'erreur en français, non-techniques.
- **U3** Champ OTP accepte "1234 5678" et "12345678".
- **U4** Flow complet ≤ 3 pages et ≤ 60 s.
- **U5** Slug inexistant → HTTP 404 avec vue générique.

### Observabilité
- **O1** 6 événements loggés : `portail.otp.{requested,verified,failed,cooldown.triggered}`, `portail.login.success`, `portail.tiers.chosen`.
- **O2** Compteur `portail.otp.failed` exploitable.
- **O3** 0 log avec code OTP ou `code_hash`.

### Conformité projet
- **C1** `declare(strict_types=1)` + `final class` + type hints — 0 warning pint.
- **C2** PSR-12 — 0 violation pint.
- **C3** Tous nouveaux modèles → `TenantModel`.
- **C4** 100% labels/validation fr.
- **C5** 0 `confirm()` natif JS.
- **C6** 100% cast `(int)` sur comparaisons PK/FK.

### Tests
- **T1** 1 test Pest ≥ 1 scénario BDD (22 tests min).
- **T2** Suite verte pré-merge (0 régression sur 1839 tests).
- **T3** ≥ 2 tests d'intrusion tenant sur routes portail.

### Go/no-go
- **G1** Feu vert JK après démo staging.
- **G2** Page `docs/portail-tiers.md` avec flux + règles OTP.
- **G3** 0 régression app interne.

---

## 5. Consistency Gate — ✅ PASS

| Check | Verdict |
|---|---|
| Intent non-ambigu | ✅ |
| Chaque comportement Intent → ≥ 1 scénario BDD | ✅ (22 scénarios) |
| Architecture contraint sans over-engineering | ✅ (0 nouveau package, 1 table, 1 garde) |
| Concepts nommés cohéremment | ✅ |
| Aucune contradiction entre artefacts | ✅ |
| Critères acceptance traçables | ✅ |
| Scope = 1 slice verticale | ✅ |

---

## 6. Dettes et points reportés

1. **Champ `civilité` sur `Tiers`** — impacte libellé accueil ("Madame Dupont" vs "Marie Dupont").
2. **Cleanup OTP expirés (F4)** — lazy-delete au `verify()` acceptable en v0 ; job dédié si volumétrie.
3. **Unicité `(association_id, email)` sur `tiers`** — décidée non-bloquante (emails familiaux partagés).
4. **Abandon de frais / CERFA** — feature distincte post-slices 2/3.
5. **Captcha sur login portail** — à étudier si abus constatés en prod.
6. **Sous-domaines par association** — v3.1 multi-tenancy, déjà préparé dans l'architecture.
