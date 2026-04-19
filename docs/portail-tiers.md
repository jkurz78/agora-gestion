# Portail Tiers — v1 (Slice 1 : Auth OTP)

Statut : livré 2026-04-19, branche feat/portail-tiers-slice1-auth-otp

## Objectif

Portail self-care par association (`/portail/{slug}`) permettant à un Tiers
existant de s'authentifier par OTP email sans compte utilisateur applicatif.
Fondation extensible pour futures features (notes de frais, attestations,
reçus fiscaux…).

## Flux d'authentification

```
GET /portail/{slug}/login
  └─ formulaire email
       │
       ▼ POST /portail/{slug}/login
       ├─ email inconnu ou archivé → même réponse qu'email connu (anti-énumération)
       └─ email connu → OTP généré + envoyé → redirect /portail/{slug}/otp

GET /portail/{slug}/otp
  └─ formulaire code OTP
       │
       ▼ POST /portail/{slug}/otp/verify
       ├─ code invalide / expiré / cooldown → message générique, retour /otp
       ├─ code valide + 1 Tiers → login direct → redirect /portail/{slug}/
       └─ code valide + N Tiers → stockage pending + redirect /portail/{slug}/choisir

GET /portail/{slug}/choisir
  └─ liste des Tiers pour cet email
       │
       ▼ POST /portail/{slug}/choisir
       └─ Tiers choisi → login → redirect /portail/{slug}/

GET /portail/{slug}/
  └─ accueil (home placeholder)
       └─ [futures features : NDF, attestations, reçus fiscaux]

POST /portail/{slug}/logout
  └─ session détruite → redirect /portail/{slug}/login
```

## Règles OTP

| Règle | Valeur | Config |
|---|---|---|
| Longueur du code | 8 chiffres | `portail.otp_length` |
| TTL | 10 minutes | `portail.otp_ttl_minutes` |
| Max tentatives | 3 | `portail.otp_max_attempts` |
| Cooldown | 15 min | `portail.otp_cooldown_minutes` |
| Renvoi minimum | 60 sec | `portail.otp_resend_seconds` |
| Session lifetime | 60 min glissante | `portail.session_lifetime_minutes` |

## Garde

`tiers-portail` — provider Eloquent sur le modèle `Tiers`, isolée de `web`.

Un utilisateur admin connecté sur `web` n'est pas considéré comme authentifié
sur `tiers-portail`, et vice-versa.

## Architecture

### Services

- `app/Services/Portail/OtpService.php` — génération, envoi, vérification, cooldown.
  - `request(Association, email)` → `RequestResult` (Sent | Silent | TooSoon | Cooldown)
  - `verify(Association, email, code)` → `VerifyResult` (Success[$tiersIds] | Invalid | Cooldown)
  - Temps constant anti-énumération : `Hash::make` exécuté même pour les emails inconnus.
- `app/Services/Portail/AuthSessionService.php` — login single-Tiers, multi-Tiers pending, chooser.
  - `loginSingleTiers(Tiers)` — connexion directe sur la garde.
  - `markPendingTiers(ids[])` — stocke les IDs en session pour le sélecteur.
  - `chooseTiers(tiersId)` — valide l'ID depuis pending et connecte.

### Middleware

- `app/Http/Middleware/Portail/BootTenantFromSlug.php` — résout l'`Association` depuis `{slug}`, retourne 404 si absent.
- `app/Http/Middleware/Portail/Authenticate.php` — vérifie que la garde `tiers-portail` est authentifiée.
- `app/Http/Middleware/Portail/EnsureTiersChosen.php` — redirige vers `/choisir` si des Tiers sont en attente de sélection.
- `app/Http/Middleware/Portail/EnforceSessionLifetime.php` — expire la session après 60 min d'inactivité.

### Composants Livewire

- `app/Livewire/Portail/Login.php` — formulaire email + gestion TooSoon.
- `app/Livewire/Portail/OtpVerify.php` — formulaire code + dispatch vers AuthSessionService.
- `app/Livewire/Portail/ChooseTiers.php` — sélecteur multi-Tiers.
- `app/Livewire/Portail/Home.php` — accueil post-login.

### Autres

- `app/Http/Controllers/Portail/LogoutController.php` — POST logout.
- `app/Mail/Portail/OtpMail.php` — email OTP (code en clair dans l'email, jamais persisté).
- `app/Models/TiersPortailOtp.php` — modèle OTP (étend `TenantModel`).

## Sécurité

- OTP hashé (`Hash::make`), jamais en clair en base ni dans les logs.
- Anti-énumération : réponse HTTP identique email connu/inconnu (statut, body, redirect, flash).
- Temps de réponse constant : `Hash::make` systématique même pour les emails inconnus (décision Q3).
- Rate limiter scopé par `(association_id, email)` — pas de contamination cross-tenant.
- `TenantScope` fail-closed sur `TiersPortailOtp` et `Tiers` — isolation cross-tenant stricte (cf. `TenantIsolationTest`).
- OTP single-use : consommation atomique via `DB::transaction` avec `UPDATE ... WHERE consumed_at IS NULL`.

## Observabilité

Événements loggés via `Log::info(...)` (LogContext porte `association_id` automatiquement) :

| Événement | Déclencheur | Contexte |
|---|---|---|
| `portail.otp.requested` | `OtpService::request()` après envoi réussi | `email` |
| `portail.otp.verified` | `OtpService::verify()` après succès | `email`, `tiers_count` |
| `portail.otp.failed` | `OtpService::verify()` après code invalide | `email`, `attempts` |
| `portail.cooldown.triggered` | `OtpService` quand le cooldown devient actif | `email` |
| `portail.login.success` | `AuthSessionService::loginSingleTiers()` | `tiers_id`, `email` |
| `portail.tiers.chosen` | `AuthSessionService::chooseTiers()` | `tiers_id` |

Garantie : aucun log ne contient le code OTP (8 chiffres consécutifs) ni le champ `code_hash`
(vérifié par assertion dans `ObservabilityTest`).

## À venir (Slices 2 & 3 du programme Notes de frais)

- Slice 2 — Écran NDF dans le portail (saisie, consultation, suivi statut).
- Slice 3 — Back-office NDF comptable (validation, rejet, comptabilisation).

## Dette technique

- Champ `archived` sur `Tiers` : scénarios "Tiers archivé" skippés (décision Q1). Le service traite un Tiers archivé comme email inconnu ; à activer quand le champ `archived` sera ajouté au modèle.
- Champ `civilite` : l'accueil affiche "Bienvenue {prénom} {nom}" ; sera ajusté quand le champ sera disponible.
- Cleanup OTP expirés : lazy-delete au `verify`, pas de job dédié — à prévoir si la table grossit.
- Sous-domaines par asso (v3.1) : préparé via le préfixe `/portail/{slug}`.
