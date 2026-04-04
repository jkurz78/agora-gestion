# v2.6.2 — 2FA optionnel (OTP email + TOTP)

## Contexte

L'application gère des données comptables et médicales sensibles. Le système d'auth actuel (email/mdp + vérification email) est fonctionnel mais insuffisant. Cette spec ajoute une couche 2FA optionnelle que chaque utilisateur peut activer depuis son profil.

## Périmètre

### Deux méthodes 2FA au choix

**OTP par email** : code 6 chiffres envoyé par email à chaque connexion. Simple, aucune appli requise.

**TOTP** (appli authenticator) : code 6 chiffres généré par une appli (Google Authenticator, Authy, 1Password, etc.). Plus sécurisé car indépendant de l'email. Nécessite un QR code à l'activation.

### Recovery codes (TOTP uniquement)

- 8 codes alphanumériques au format `xxxx-xxxx` générés à l'activation du TOTP
- Affichés une seule fois — l'utilisateur doit les sauvegarder
- Usage unique, stockés hashés en base
- Régénérables depuis le profil (invalide les précédents)
- Non applicables à l'OTP email (le code arrive par email, pas de risque de perte d'accès)

### Cookie "Se fier à ce navigateur"

- Cookie signé et chiffré `two_factor_trusted`, durée 30 jours
- Lié au `user_id`
- Si présent et valide → 2FA sautée
- Révocable depuis le profil ("Révoquer tous les appareils de confiance")

---

## Architecture

### Migrations

**Modifier la table `users` :**
- `two_factor_method` : string nullable (null = désactivé, 'email', 'totp')
- `two_factor_secret` : text nullable, chiffré (secret TOTP, null si méthode email)
- `two_factor_confirmed_at` : datetime nullable (date de confirmation du TOTP)
- `two_factor_recovery_codes` : text nullable, chiffré (JSON array de codes hashés)

**Nouvelle table `two_factor_codes` :**
- `id` bigint PK
- `user_id` FK → users
- `code` string (6 chiffres, hashé)
- `expires_at` datetime
- `created_at` timestamp

### Enum `TwoFactorMethod`

```php
enum TwoFactorMethod: string
{
    case Email = 'email';
    case Totp = 'totp';
}
```

### Package

`pragmarx/google2fa-laravel` — implémentation PHP pure de TOTP (RFC 6238). Aucune dépendance Google, aucun appel réseau. QR code via `bacon/bacon-qr-code` (déjà dans Laravel).

### Service `TwoFactorService`

Responsabilités :
- `enableEmail(User)` : active la méthode email
- `enableTotp(User)` : génère un secret TOTP, retourne le secret (non encore confirmé)
- `confirmTotp(User, code)` : valide le premier code TOTP, marque comme confirmé, génère les recovery codes
- `disable(User)` : désactive le 2FA, supprime secret/codes
- `generateEmailCode(User)` : crée un code 6 chiffres en base, envoie l'email, valide 10 minutes
- `verifyEmailCode(User, code)` : vérifie le code OTP email
- `verifyTotpCode(User, code)` : vérifie le code TOTP via google2fa
- `verifyRecoveryCode(User, code)` : vérifie un recovery code (usage unique, supprimé après)
- `generateRecoveryCodes(User)` : génère 8 nouveaux codes, retourne en clair, stocke hashés
- `setTrustedBrowser(Response, User)` : ajoute le cookie signé 30 jours
- `isTrustedBrowser(Request, User)` : vérifie le cookie
- `revokeTrustedBrowsers(User)` : invalide tous les cookies (rotation d'un token interne)

### Middleware `EnsureTwoFactor`

- Appliqué aux mêmes routes que `verified` (compta et gestion)
- Après login, si `$user->two_factor_method !== null` et pas de cookie trusted valide :
  - Stocke l'intention en session (`two_factor_user_id`)
  - Redirige vers `/two-factor/challenge`
- Si 2FA non actif ou cookie valide → laisse passer

### Routes

```
GET  /two-factor/challenge     → affiche le formulaire (code ou recovery)
POST /two-factor/challenge     → vérifie le code
POST /two-factor/challenge/resend  → renvoie le code email (throttle 60s)
```

Middleware : `auth` (l'utilisateur est authentifié mais pas encore "2FA validé").

### Mailable `TwoFactorCodeMail`

Email simple avec le code 6 chiffres et une mention "Ce code expire dans 10 minutes."

### UI — Page Challenge (`/two-factor/challenge`)

Page Bootstrap simple, même layout que login :
- Titre : "Vérification en deux étapes"
- Si méthode email : "Un code a été envoyé à votre adresse email"
- Si méthode TOTP : "Entrez le code de votre application d'authentification"
- Input code 6 chiffres (autofocus, inputmode=numeric)
- Checkbox "Se fier à ce navigateur pendant 30 jours"
- Bouton "Vérifier"
- Si email : lien "Renvoyer le code" (throttle 60s)
- Si TOTP : lien "Utiliser un code de récupération" → bascule vers un input recovery code
- Lien "Se déconnecter" en bas

### UI — Section Sécurité sur page Profil

Nouveau composant Livewire `TwoFactorSetup` inclus en bas de `/profil`.

**État désactivé :**
- Texte : "La vérification en deux étapes n'est pas activée."
- Bouton "Activer via email" / "Activer via application"

**État OTP email activé :**
- Badge "OTP email activé"
- Bouton "Passer au TOTP" / "Désactiver"

**État TOTP — étape configuration (non confirmé) :**
- QR code SVG inline (pas d'image externe)
- Secret en texte pour saisie manuelle
- Input "Entrez le code affiché dans votre application pour confirmer"
- Bouton "Confirmer"

**État TOTP activé et confirmé :**
- Badge "TOTP activé"
- Section recovery codes (affichée une seule fois à l'activation, puis cachée)
- Bouton "Régénérer les codes de récupération" (affiche les nouveaux codes une fois)
- Bouton "Révoquer tous les appareils de confiance"
- Bouton "Désactiver le 2FA"

---

## Flow détaillé

### Activation OTP email

1. Profil → clic "Activer via email"
2. `TwoFactorService::enableEmail($user)` → `two_factor_method = 'email'`
3. Done — à la prochaine connexion, un code sera envoyé

### Activation TOTP

1. Profil → clic "Activer via application"
2. `TwoFactorService::enableTotp($user)` → génère secret, stocke chiffré, `confirmed_at` reste null
3. Affiche QR code + secret texte
4. L'utilisateur scanne et entre le code affiché dans l'appli
5. `TwoFactorService::confirmTotp($user, $code)` → vérifie le code, set `confirmed_at`, génère 8 recovery codes
6. Affiche les recovery codes une fois — l'utilisateur les copie

### Connexion avec 2FA

1. POST /login → email/mdp valides → session authentifiée
2. Middleware `EnsureTwoFactor` intercepte → 2FA actif + pas de cookie trusted
3. Redirige vers `/two-factor/challenge`
4. Si méthode email → `generateEmailCode()` envoie le code
5. L'utilisateur saisit le code
6. POST /two-factor/challenge → vérifie le code
7. Si checkbox "Se fier" cochée → cookie 30 jours posé
8. Redirige vers la destination initiale

### Utilisation d'un recovery code (TOTP)

1. Sur la page challenge TOTP → clic "Utiliser un code de récupération"
2. Saisie du code `xxxx-xxxx`
3. `verifyRecoveryCode()` → vérifie contre les hashés, supprime le code utilisé
4. Connexion validée
5. Avertissement : "Il vous reste N codes de récupération"

---

## Tests

- `TwoFactorServiceTest` : enable/disable email et TOTP, generation/verification codes, recovery codes, trusted browser
- `TwoFactorChallengeTest` : flow complet connexion avec 2FA email, TOTP, recovery code, cookie trusted, expiration code, throttle resend
- `TwoFactorSetupTest` : activation/désactivation depuis le profil, confirmation TOTP, régénération recovery codes
- `EnsureTwoFactorMiddlewareTest` : redirection si 2FA requis, passthrough si désactivé ou cookie valide

---

## Hors périmètre

- 2FA obligatoire par rôle (évolution future)
- Liste des appareils de confiance (évolution future)
- Backup via SMS (coût, complexité)
