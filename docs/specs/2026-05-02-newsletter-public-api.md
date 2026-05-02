# API publique newsletter — buffer d'inscriptions + double opt-in

**Date** : 2026-05-02
**Statut** : spec en revue
**Programme** : Site web public SVS — formulaires d'inscription centralisés dans AgoraGestion
**Périmètre** : slice 1 — endpoint REST public `POST /api/newsletter/subscribe`, table buffer `newsletter_subscription_requests`, double opt-in RGPD, désinscription. **L'import des demandes confirmées vers la table `tiers` est traité dans une PR ultérieure** comme nouvel élément de la Boîte de réception.
**Préalables** : multi-tenant v4.0.0 en prod (S6 hardening livré). Site Astro `soigner-vivre-sourire.fr` (repo séparé) en cours de refonte.

---

## 1. Intent Description

**Quoi.** Exposer un endpoint REST public sur AgoraGestion qui collecte les inscriptions newsletter du site vitrine `soigner-vivre-sourire.fr` (Astro statique, repo externe). À chaque soumission valide, une ligne **buffer** est créée dans `newsletter_subscription_requests` avec un statut `pending`, et un email de confirmation est envoyé à l'abonné. Le clic sur le lien de confirmation marque la ligne `confirmed` et génère un token de désinscription. Le clic sur le lien de désinscription (présent dès le 1er email, RGPD) marque `unsubscribed`. **Aucune écriture directe dans `tiers`** : le buffer est une zone d'évènements bruts, fusionnée a posteriori par un gestionnaire (PR séparée, cf. §4).

**Pourquoi.** Le site vitrine SVS doit collecter ses abonnés newsletter sans prestataire tiers (pas de Brevo / Mailchimp). L'asso a fait le choix explicite de centraliser ses contacts dans son propre back-office. AgoraGestion devient l'outil unique pour la gestion des contacts associatifs — cohérent avec la stratégie "tout-en-un" déjà en place (membres, donateurs, factures, communications, NDF, etc.).

**Pourquoi maintenant.** Le site vitrine SVS est en refonte technique (Astro). Les pages d'inscription doivent être branchées sur AgoraGestion avant la mise en ligne du nouveau site. Le multi-tenant strict (S6 hardening) garantit qu'une asso tierce ne peut pas accéder ni polluer les inscriptions de SVS — l'endpoint résout le tenant à partir de l'origine HTTP appelante via une whitelist en config.

**Quoi ce n'est pas.** Pas un outil d'envoi de campagnes newsletter (rédaction, expédition de masse — hors scope, traité plus tard via le module Communication Tiers existant). Pas une UI back-office pour gérer les abonnés (sera traitée dans une PR ultérieure, comme nouvel élément de la Boîte de réception unifiée — cf. `project_inbox_unifiee.md`). Pas une écriture directe dans la table `tiers` (cf. `feedback_buffer_imports_externes.md` : tout flux d'ingestion externe passe par un buffer dédoublonné manuellement). Pas un endpoint pour les autres formulaires du site SVS (contact, pré-inscription équithérapie) — chacun fera l'objet d'une mission distincte avec son propre buffer dédié.

**Périmètre slice 1.** Migration `newsletter_subscription_requests` (tenant-scopée) ; modèle `App\Models\Newsletter\SubscriptionRequest extends TenantModel` ; FormRequest `SubscribeNewsletterRequest` avec honeypot ; controller `Api\NewsletterSubscriptionController` (subscribe + confirm + unsubscribe) ; service `Newsletter\SubscriptionService` (logique idempotente, transactions) ; middleware `Api\BootTenantFromNewsletterOrigin` (résolution tenant via `Origin` header) ; mailable `NewsletterConfirmation` (HTML+texte, FR, charte SVS minimale) ; vues web `newsletter/{confirmed,unsubscribed,expired}.blade.php` (layout public sobre) ; rate limiter `newsletter` (5/h/IP) ; CORS `config/cors.php` restreint à 3 origines SVS ; commande `newsletter:forget {email}` (hard delete RGPD) ; tests Pest end-to-end ; section README.

---

## 2. User-Facing Behavior (BDD Gherkin)

```gherkin
# language: fr
Fonctionnalité: API publique d'inscription newsletter
  Pour qu'un visiteur du site vitrine SVS puisse s'inscrire à la newsletter
  En tant que visiteur, formulaire web
  Je soumets mon email + prénom + consentement RGPD à AgoraGestion qui m'envoie un email de confirmation

  Contexte:
    Étant donné que l'asso "Soigner Vivre Sourire" (slug "soigner-vivre-sourire") existe
    Et que config/newsletter.php map "https://soigner-vivre-sourire.fr" → "soigner-vivre-sourire"
    Et que la table newsletter_subscription_requests est vide

  # ─── Inscription nominale ─────────────────────────────────────────────

  Scénario: Inscription valide depuis l'origine autorisée
    Étant donné l'origine HTTP "https://soigner-vivre-sourire.fr"
    Quand je POST /api/newsletter/subscribe avec
      | champ    | valeur                |
      | email    | alice@example.fr      |
      | prenom   | Alice                 |
      | consent  | true                  |
      | bot_trap |                       |
    Alors la réponse est 200 avec body {"status": "pending_double_optin"}
    Et une ligne newsletter_subscription_requests existe avec
      | association_id | (asso SVS)        |
      | email          | alice@example.fr  |
      | prenom         | Alice             |
      | status         | pending           |
      | ip_address     | (IP de la requête)|
      | tiers_id       | null              |
    Et confirmation_token_hash est non-null (sha256, 64 chars hex)
    Et confirmation_expires_at = now + 7 jours
    Et unsubscribe_token_hash est non-null
    Et un email NewsletterConfirmation est envoyé à alice@example.fr
    Et l'email contient le lien GET /newsletter/confirm/{token_clair}
    Et l'email contient le lien GET /newsletter/unsubscribe/{token_clair}
    Et aucun token clair n'apparaît dans les logs Laravel

  # ─── CORS ─────────────────────────────────────────────────────────────

  Scénario: Préflight OPTIONS depuis origine autorisée
    Étant donné l'origine "https://soigner-vivre-sourire.fr"
    Quand j'OPTIONS /api/newsletter/subscribe
    Alors la réponse est 204
    Et l'en-tête Access-Control-Allow-Origin contient "https://soigner-vivre-sourire.fr"
    Et l'en-tête Access-Control-Allow-Methods contient "POST, OPTIONS"
    Et l'en-tête Access-Control-Allow-Headers contient "Content-Type"

  Scénario: POST depuis origine non-autorisée
    Étant donné l'origine "https://attaquant.example"
    Quand je POST /api/newsletter/subscribe avec un payload valide
    Alors la réponse est 403
    Et aucune ligne n'est créée
    Et aucun email n'est envoyé

  # ─── Validation ───────────────────────────────────────────────────────

  Scénario: Email mal formé
    Quand je POST /api/newsletter/subscribe avec email="pas-un-email", consent=true
    Alors la réponse est 422 avec body.error = "validation_failed"
    Et body.fields.email est non-vide

  Scénario: Consentement absent
    Quand je POST /api/newsletter/subscribe avec email="alice@example.fr", consent=false
    Alors la réponse est 422 avec body.fields.consent non-vide

  # ─── Honeypot ─────────────────────────────────────────────────────────

  Scénario: bot_trap rempli (probable bot)
    Quand je POST /api/newsletter/subscribe avec
      | champ    | valeur            |
      | email    | bot@spam.com      |
      | consent  | true              |
      | bot_trap | http://link-spam  |
    Alors la réponse est 200 avec body {"status": "pending_double_optin"}
    Et aucune ligne newsletter_subscription_requests n'est créée
    Et aucun email n'est envoyé

  # ─── Idempotence et anti-énumération ──────────────────────────────────

  Scénario: Doublon email pending (re-soumission)
    Étant donné une ligne pending existante pour alice@example.fr (asso SVS, créée il y a 1h)
    Quand je POST /api/newsletter/subscribe avec email="alice@example.fr", consent=true
    Alors la réponse est 200 avec body {"status": "pending_double_optin"}
    Et il existe toujours UNE seule ligne pour alice@example.fr (mise à jour, pas dupliquée)
    Et confirmation_token_hash a été régénéré (différent de la valeur précédente)
    Et confirmation_expires_at = now + 7 jours (reset)
    Et un nouvel email NewsletterConfirmation est envoyé

  Scénario: Doublon email confirmed (silence anti-énumération)
    Étant donné une ligne confirmed existante pour bob@example.fr (asso SVS)
    Quand je POST /api/newsletter/subscribe avec email="bob@example.fr", consent=true
    Alors la réponse est 200 avec body {"status": "pending_double_optin"}
    Et la ligne existante reste inchangée (toujours confirmed, mêmes tokens)
    Et aucun nouvel email n'est envoyé
    Et aucune nouvelle ligne n'est créée

  Scénario: Re-inscription après désinscription
    Étant donné une ligne unsubscribed existante pour carol@example.fr (asso SVS)
    Quand je POST /api/newsletter/subscribe avec email="carol@example.fr", consent=true
    Alors la réponse est 200 avec body {"status": "pending_double_optin"}
    Et une NOUVELLE ligne pending est créée pour carol@example.fr
    Et la ligne unsubscribed précédente est conservée intacte (preuve RGPD)
    Et un email NewsletterConfirmation est envoyé sur la nouvelle ligne

  # ─── Rate limiting ────────────────────────────────────────────────────

  Scénario: 6e inscription depuis la même IP en 1h
    Étant donné 5 POST /api/newsletter/subscribe déjà envoyés depuis 1.2.3.4 dans la dernière heure
    Quand je POST une 6e fois depuis 1.2.3.4 avec un payload valide
    Alors la réponse est 429 avec body.error = "rate_limit"
    Et aucune nouvelle ligne n'est créée

  # ─── Confirmation (web) ───────────────────────────────────────────────

  Scénario: Token de confirmation valide
    Étant donné une ligne pending pour alice@example.fr avec confirmation_token = "tok-clair-123"
    Quand j'ouvre GET /newsletter/confirm/tok-clair-123
    Alors la réponse est 200 et affiche la vue "newsletter.confirmed"
    Et la ligne devient status=confirmed, confirmed_at=now
    Et confirmation_token_hash et confirmation_expires_at sont conservés tels quels (audit)
    Et la vue contient un lien de désinscription utilisant unsubscribe_token clair

  Scénario: Token de confirmation expiré
    Étant donné une ligne pending pour alice@example.fr avec confirmation_expires_at = il y a 1 heure
    Quand j'ouvre GET /newsletter/confirm/{token_correspondant}
    Alors la réponse est 410 et affiche la vue "newsletter.expired"
    Et la ligne reste status=pending (pas de mutation)

  Scénario: Token de confirmation inconnu / forgé
    Quand j'ouvre GET /newsletter/confirm/token-bidon
    Alors la réponse est 404

  # ─── Désinscription (web) ─────────────────────────────────────────────

  Scénario: Token de désinscription valide depuis ligne confirmed
    Étant donné une ligne confirmed pour alice@example.fr avec unsubscribe_token = "unsub-clair-456"
    Quand j'ouvre GET /newsletter/unsubscribe/unsub-clair-456
    Alors la réponse est 200 et affiche la vue "newsletter.unsubscribed"
    Et la ligne devient status=unsubscribed, unsubscribed_at=now
    Et la ligne n'est PAS hard-supprimée (preuve RGPD)

  Scénario: Désinscription possible depuis ligne pending (avant confirmation)
    Étant donné une ligne pending pour dave@example.fr avec unsubscribe_token = "unsub-clair-789"
    Quand j'ouvre GET /newsletter/unsubscribe/unsub-clair-789
    Alors la réponse est 200 et affiche la vue "newsletter.unsubscribed"
    Et la ligne devient status=unsubscribed, unsubscribed_at=now

  Scénario: Token de désinscription inconnu
    Quand j'ouvre GET /newsletter/unsubscribe/token-bidon
    Alors la réponse est 404

  # ─── Droit à l'effacement RGPD ────────────────────────────────────────

  Scénario: Suppression d'un email à la demande
    Étant donné 3 lignes (1 pending, 1 confirmed, 1 unsubscribed) pour erin@example.fr
    Quand j'exécute "php artisan newsletter:forget erin@example.fr"
    Alors les 3 lignes sont hard-deletées (DELETE physique)
    Et la commande affiche "3 lignes supprimées pour erin@example.fr"
```

---

## 3. Architecture Specification

### 3.1 Résolution du tenant — Origin header

**Décision.** L'asso cible est résolue à partir du header HTTP `Origin` de la requête entrante via une whitelist en `config/newsletter.php`. Pas de slug dans l'URL (le contrat API exposé au site Astro reste `POST /api/newsletter/subscribe`). Pas de middleware existant `BootTenantFromSlug` (réservé au portail) — on crée un middleware dédié `App\Http\Middleware\Api\BootTenantFromNewsletterOrigin`.

```php
// config/newsletter.php (NOUVEAU)
return [
    'origins' => [
        'https://soigner-vivre-sourire.fr'     => 'soigner-vivre-sourire',
        'https://dev.soigner-vivre-sourire.fr' => 'soigner-vivre-sourire',
        'http://localhost:4321'                => 'soigner-vivre-sourire',
    ],
    'rate_limit' => [
        'max_attempts' => 5,
        'decay_hours'  => 1,
    ],
    'confirmation_ttl_days' => 7,
];
```

**Logique du middleware** :
1. Lire `$request->headers->get('Origin')`.
2. Si pas de match dans `config('newsletter.origins')` → `abort(403)`.
3. Sinon, charger `Association::where('slug', $slug)->firstOrFail()` (sans scope tenant — `Association` n'est pas tenant-scopé, c'est la racine).
4. `TenantContext::boot($association)`.
5. Continuer.

**Ordre des middlewares** sur la route : `BootTenantFromNewsletterOrigin` AVANT le throttler (pour que le throttler puisse utiliser `TenantContext::currentId()` dans sa clé), AVANT `SubstituteBindings` (cohérent avec la priorité actuelle dans `bootstrap/app.php`).

### 3.2 CORS

`config/cors.php` n'existe pas dans le projet — on le crée. Limité au strict nécessaire :

```php
return [
    'paths' => ['api/newsletter/*'],
    'allowed_methods' => ['POST', 'OPTIONS'],
    'allowed_origins' => array_keys(config('newsletter.origins', [])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
```

Le middleware `HandleCors` Laravel est déjà actif par défaut. En publiant `cors.php`, on active la config. Aucune autre route n'est concernée.

### 3.3 Migration `newsletter_subscription_requests`

```
- id (bigInt, PK)
- association_id (bigInt unsigned, FK → associations, ON DELETE CASCADE, INDEX)
- email (string 255, INDEX, NON unique)
- prenom (string 100, nullable)
- status (enum: 'pending', 'confirmed', 'unsubscribed') default 'pending'
- confirmation_token_hash (string 64, nullable, INDEX)
- confirmation_expires_at (timestamp, nullable)
- unsubscribe_token_hash (string 64, nullable, UNIQUE INDEX)
- subscribed_at (timestamp, nullable)
- confirmed_at (timestamp, nullable)
- unsubscribed_at (timestamp, nullable)
- ip_address (string 45, nullable)        // IPv6 max
- user_agent (string 255, nullable)
- tiers_id (bigInt unsigned, FK → tiers nullOnDelete, nullable, INDEX) // alimenté par PR import
- created_at, updated_at
- INDEX composite (association_id, status)  // pour les listings back-office
- INDEX composite (association_id, email)   // pour la résolution doublon
```

Pas de `softDeletes` : les statuts (`unsubscribed`) sont la trace RGPD. Le hard delete passe par `newsletter:forget`.

### 3.4 Modèle `App\Models\Newsletter\SubscriptionRequest`

```php
final class SubscriptionRequest extends TenantModel
{
    protected $table = 'newsletter_subscription_requests';

    protected $fillable = [
        'email', 'prenom', 'status',
        'subscribed_at', 'ip_address', 'user_agent',
    ];
    // tokens et timestamps de mutation : assignés via méthodes dédiées, jamais en mass-assign

    protected $casts = [
        'status'                  => SubscriptionRequestStatus::class, // enum PHP 8.1
        'confirmation_expires_at' => 'datetime',
        'subscribed_at'           => 'datetime',
        'confirmed_at'            => 'datetime',
        'unsubscribed_at'         => 'datetime',
    ];

    // Scope active() = confirmed et non-désinscrit
    public function scopeActive(Builder $q): Builder { return $q->where('status', SubscriptionRequestStatus::Confirmed); }

    // Mutations explicites
    public function regenerateConfirmationToken(): string { /* renvoie le token clair, stocke hash + expiry */ }
    public function regenerateUnsubscribeToken(): string  { /* renvoie le token clair, stocke hash */ }
    public function markConfirmed(): void                  { /* status, confirmed_at */ }
    public function markUnsubscribed(): void               { /* status, unsubscribed_at */ }

    // Lookup par token clair
    public static function findByConfirmationToken(string $clear): ?self    { /* hash + where */ }
    public static function findByUnsubscribeToken(string $clear): ?self     { /* hash + where */ }
}
```

**Enum** : `App\Enums\Newsletter\SubscriptionRequestStatus { Pending; Confirmed; Unsubscribed }`.

**Tokens** : `Str::random(48)` produit ~64 caractères base64-url-safe ; on stocke `hash('sha256', $token)` (64 chars hex). Comparaison par `hash_equals` côté lookup pour cohérence avec le pattern HelloAsso existant — en pratique, `where('confirmation_token_hash', $hash)` suffit (le hash est déterministe, pas besoin de timing-safe à l'index).

**TenantModel** : auto-injection de `association_id` à la création via `TenantContext::currentId()`. Le scope global `TenantScope` filtre toutes les queries — `findByConfirmationToken` ne peut donc trouver qu'une ligne du tenant courant. ✅ Isolation cross-tenant garantie.

### 3.5 FormRequest `App\Http\Requests\Api\SubscribeNewsletterRequest`

Le FormRequest gère la validation **et** le court-circuit honeypot. Si `bot_trap` est rempli, on neutralise les règles dans `prepareForValidation` et on positionne un flag public que le controller lit pour court-circuiter avec un 200 silencieux. Cette approche garde la logique honeypot encapsulée au niveau du FormRequest, sans révéler la détection via un 422.

```php
final class SubscribeNewsletterRequest extends FormRequest
{
    public bool $isHoneypotTriggered = false;

    public function authorize(): bool { return true; } // autorisation au niveau du middleware Origin

    protected function prepareForValidation(): void
    {
        if (filled($this->input('bot_trap'))) {
            $this->isHoneypotTriggered = true;
            $this->replace([]); // neutralise tous les inputs : aucune règle ne peut échouer
        }
    }

    public function rules(): array
    {
        if ($this->isHoneypotTriggered) {
            return []; // court-circuit : la validation passe trivialement
        }

        return [
            'email'   => ['required', 'email', 'max:255'],
            'prenom'  => ['nullable', 'string', 'max:100'],
            'consent' => ['required', 'accepted'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        // Réponse JSON normalisée 422 : {"error": "validation_failed", "fields": {...}}
        throw new HttpResponseException(response()->json([
            'error'  => 'validation_failed',
            'fields' => $validator->errors()->toArray(),
        ], 422));
    }
}
```

**Pourquoi pas une règle `max:0` sur `bot_trap`** : produirait un 422 si rempli, ce qui révèle la détection au bot. La spec utilisateur impose un 200 silencieux côté visible. Le pattern `prepareForValidation + flag` est la solution qui reste invisible.

### 3.6 Service `App\Services\Newsletter\SubscriptionService`

Orchestre la logique idempotente + `DB::transaction()` (pattern projet : services métier dans `app/Services/`, `final class`, `declare(strict_types=1)`).

```php
final class SubscriptionService
{
    public function __construct(private readonly Mailer $mailer) {}

    public function subscribe(string $email, ?string $prenom, string $ip, string $userAgent): void
    {
        DB::transaction(function () use ($email, $prenom, $ip, $userAgent) {
            $existing = SubscriptionRequest::where('email', $email)
                ->whereIn('status', [SubscriptionRequestStatus::Pending, SubscriptionRequestStatus::Confirmed])
                ->latest('id')
                ->first();

            if ($existing?->status === SubscriptionRequestStatus::Confirmed) {
                return; // anti-énumération : silence total
            }

            if ($existing?->status === SubscriptionRequestStatus::Pending) {
                $confirmation = $existing->regenerateConfirmationToken();
                $unsubscribe  = $existing->regenerateUnsubscribeToken();
                $existing->save();
                $this->sendConfirmation($existing, $confirmation, $unsubscribe);
                return;
            }

            // nouveau OU re-inscription après unsubscribed → nouvelle ligne
            $request = new SubscriptionRequest([
                'email'        => $email,
                'prenom'       => $prenom,
                'status'       => SubscriptionRequestStatus::Pending,
                'subscribed_at' => now(),
                'ip_address'   => $ip,
                'user_agent'   => Str::limit($userAgent, 250, ''),
            ]);
            $confirmation = $request->regenerateConfirmationToken();
            $unsubscribe  = $request->regenerateUnsubscribeToken();
            $request->save();
            $this->sendConfirmation($request, $confirmation, $unsubscribe);
        });
    }

    public function confirm(SubscriptionRequest $request): void
    {
        if ($request->confirmation_expires_at->isPast()) {
            throw new ConfirmationExpiredException();
        }
        $request->markConfirmed();
        $request->save();
    }

    public function unsubscribe(SubscriptionRequest $request): void
    {
        $request->markUnsubscribed();
        $request->save();
    }

    private function sendConfirmation(SubscriptionRequest $r, string $confirmationClear, string $unsubscribeClear): void
    {
        $this->mailer->to($r->email)->send(new NewsletterConfirmation($r, $confirmationClear, $unsubscribeClear));
    }
}
```

### 3.7 Controller `App\Http\Controllers\Api\NewsletterSubscriptionController`

**Flux honeypot.** On n'utilise PAS le FormRequest pour le honeypot (sinon `bot_trap` non-vide produit un 422 qui révèle la détection). On utilise une signature avec `Request` brut, on regarde `bot_trap` AVANT toute validation, et on court-circuite avec un 200 silencieux. Si vide, on délègue la validation à `SubscribeNewsletterRequest` via `app(SubscribeNewsletterRequest::class)` ou en validant manuellement avec les mêmes règles. Le FormRequest reste utile pour la **réponse 422 normalisée** sur les autres erreurs (email mal formé, consent absent).

```php
final class NewsletterSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $service) {}

    public function subscribe(SubscribeNewsletterRequest $request): JsonResponse
    {
        // Honeypot : court-circuit silencieux (le flag est posé dans prepareForValidation)
        if ($request->isHoneypotTriggered) {
            return response()->json(['status' => 'pending_double_optin']);
        }

        // À ce stade, la validation a passé (rules() ne contient que les vrais champs si pas honeypot)
        $this->service->subscribe(
            email: $request->validated('email'),
            prenom: $request->validated('prenom'),
            ip: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return response()->json(['status' => 'pending_double_optin']);
    }

    public function confirm(string $token): View
    {
        $request = $this->service->findByConfirmationToken($token);
        if (! $request) abort(404);
        try {
            $this->service->confirm($request);
        } catch (ConfirmationExpiredException) {
            return view('newsletter.expired')->setStatusCode(410);
        }
        return view('newsletter.confirmed', ['association' => TenantContext::current()]);
        // La vue affiche un message de remerciement et rappelle que la désinscription
        // est possible via le lien présent dans tout email reçu — PAS de lien frais en vue.
        // Cela évite de rotater le unsubscribe_token à la confirmation (le lien email reste
        // valide à vie, c'est suffisant et conforme RGPD).
    }

    public function unsubscribe(string $token): View
    {
        $request = SubscriptionRequest::findByUnsubscribeToken($token);
        if (! $request) abort(404);
        $this->service->unsubscribe($request);
        return view('newsletter.unsubscribed');
    }
}
```

**Subtilité unsubscribe URL post-confirmation** : le token clair n'existe qu'au moment de la création / régénération. Pour afficher un lien de désinscription sur la vue de confirmation, on régénère un nouveau token de désinscription dans `confirm()` et on le passe en clair à la vue (jamais stocké en clair). Le lien envoyé dans l'email de confirmation reste valide aussi (token unique, juste rotaté). Décision : on garde une seule clé `unsubscribe_token_hash` à la fois ; le lien email peut donc devenir invalide après confirmation **uniquement si l'utilisateur ouvre la page de confirmation**. Acceptable car la vue de confirmation contient un lien de désinscription frais. **Alternative** : accepter cette limitation et ne PAS rotater le token à la confirmation — option plus simple, retenue pour le slice 1. Le token de désinscription est généré une fois à la création et reste valide pour toute la durée de vie de la ligne.

### 3.8 Routes

`routes/api.php` (en plus de l'existant HelloAsso) :
```php
Route::middleware([
    \App\Http\Middleware\Api\BootTenantFromNewsletterOrigin::class,
    'throttle:newsletter',
])->group(function () {
    Route::post('/newsletter/subscribe', [NewsletterSubscriptionController::class, 'subscribe'])
        ->name('api.newsletter.subscribe');
});
```

`routes/web.php` (vues HTML) :
```php
Route::get('/newsletter/confirm/{token}', [NewsletterSubscriptionController::class, 'confirm'])
    ->middleware('throttle:30,1') // anti-bruteforce sur les tokens
    ->name('newsletter.confirm');
Route::get('/newsletter/unsubscribe/{token}', [NewsletterSubscriptionController::class, 'unsubscribe'])
    ->middleware('throttle:30,1')
    ->name('newsletter.unsubscribe');
```

**Tenant resolution sur les routes web non-authentifiées.** Le visiteur qui clique sur le lien dans son email n'a pas de session → `ResolveTenant` (middleware web) ne boote rien. Et comme `SubscriptionRequest extends TenantModel`, une query directe retourne `WHERE 1 = 0` (fail-closed). Solution : la méthode `SubscriptionService::findByConfirmationToken($clear)` désactive **localement** le scope global, lookup par hash (qui est `UNIQUE INDEX` ou suffisamment discriminant), boote `TenantContext` à partir de `$row->association_id`, puis renvoie le modèle. À partir de ce point, tout le reste du traitement est tenant-scopé normalement.

```php
public function findByConfirmationToken(string $clearToken): ?SubscriptionRequest
{
    $hash = hash('sha256', $clearToken);

    /** @var SubscriptionRequest|null $row */
    $row = SubscriptionRequest::withoutGlobalScope(TenantScope::class)
        ->where('confirmation_token_hash', $hash)
        ->first();

    if (! $row) return null;

    if (! TenantContext::hasBooted() || TenantContext::currentId() !== $row->association_id) {
        TenantContext::boot($row->association);
    }

    return $row;
}
```

Idem pour `findByUnsubscribeToken`. **Justification** : c'est le seul endroit du code qui désactive le scope tenant ; il est encapsulé dans le service ; les tokens sont des secrets opaques (sha256, 64 chars) — savoir le hash, c'est avoir l'autorité de booter le tenant correspondant. Pas plus permissif qu'une URL signée Laravel standard.

Les 2 routes web ne portent pas le middleware `boot-tenant` (qui requiert une session) — c'est volontaire.

### 3.9 Mailable `App\Mail\NewsletterConfirmation`

- Classe `final`, `extends Mailable`, `Queueable + SerializesModels`.
- Constructor : `(SubscriptionRequest $request, string $confirmationToken, string $unsubscribeToken)`.
- `envelope()` : sujet `"Confirmez votre inscription à la newsletter — {asso.nom}"` (asso = `TenantContext::current()`).
- `content()` : view `emails.newsletter.confirmation` (HTML) + `emails.newsletter.confirmation-text` (plain text).
- URLs construites via `TenantUrl::route('newsletter.confirm', ['token' => $confirmationToken])` (préparation v3.1 sous-domaines, conventionalprojet).
- Charte SVS minimale : header avec nom de l'asso, body texte sobre, footer avec mention RGPD + lien désinscription explicite (RGPD : présent dès le 1er email).
- Pas de tracking pixel (hors scope, pas de campagne).

### 3.10 Vues web `resources/views/newsletter/`

- `confirmed.blade.php` : "Merci, votre inscription est confirmée." + lien désinscription en pied de page (token frais).
- `unsubscribed.blade.php` : "Vous êtes désinscrit·e de la newsletter de {asso}." (asso = `TenantContext::current()->nom`).
- `expired.blade.php` : "Ce lien de confirmation a expiré. Vous pouvez vous réinscrire depuis le site soigner-vivre-sourire.fr." (HTTP 410).
- Layout : `layouts.public-minimal` (NOUVEAU, sobre, mêmes styles que les vues `email-optout` existantes — Bootstrap 5 CDN, charte AgoraGestion).

### 3.11 Rate limiter

Dans `bootstrap/app.php`, ajout dans `withMiddleware()` (ou via un service provider dédié `AppServiceProvider::boot()`) :

```php
RateLimiter::for('newsletter', function (Request $request) {
    return Limit::perHour(config('newsletter.rate_limit.max_attempts', 5))
        ->by($request->ip())
        ->response(fn () => response()->json(['error' => 'rate_limit'], 429));
});
```

### 3.12 Confidentialité / logs

- Le payload entrant n'est **pas** loggué. On log juste un évènement métier neutre : `Log::info('newsletter.subscription.received', ['association_id' => TenantContext::currentId(), 'status' => 'queued'])`. **Pas d'email**, **pas d'IP**.
- En cas d'erreur 500 (rare), Laravel log la stack trace ; on s'assure que `request->all()` n'est pas inclus via la config `debug=false` en prod (déjà le cas).
- Tokens clairs : envoyés UNIQUEMENT dans l'email + dans les vues de confirmation. Jamais dans les logs ni dans les réponses JSON.

### 3.13 Commande `newsletter:forget {email}`

`App\Console\Commands\Newsletter\ForgetSubscriberCommand` :

```php
final class ForgetSubscriberCommand extends Command
{
    protected $signature = 'newsletter:forget {email} {--association= : slug de l\'asso (optionnel, sinon toutes)}';
    protected $description = 'Supprime physiquement toutes les lignes du buffer newsletter pour un email (RGPD).';

    public function handle(): int
    {
        // Cas multi-asso : si --association est fourni, boote ce tenant. Sinon, désactive le scope global.
        // Hard delete sur toutes les lignes correspondantes.
        // Affiche le compte.
    }
}
```

Le tiers déjà importé (lignes avec `tiers_id` non-null) reste **inchangé** dans la table `tiers` — la commande ne couvre que le buffer. La suppression du Tiers se fait via le RGPD existant côté Tiers (out of scope ici).

### 3.14 Plan de tests (TDD, ordre d'implémentation)

**Test feature unique** : `tests/Feature/Api/NewsletterSubscriptionTest.php` (Pest, RefreshDatabase, Mail::fake). Le bootstrap global (`tests/Pest.php`) crée déjà une asso + boote TenantContext — on ajoute dans le `beforeEach` du test la config `newsletter.origins` pointant cette asso, et on injecte le header `Origin` dans chaque requête.

Ordre TDD (chaque test est une étape RED → GREEN → REFACTOR) :

1. ✅ Migration crée la table avec toutes les colonnes
2. ✅ Modèle `SubscriptionRequest` existe, scope `active()` filtre `confirmed`
3. ✅ POST `/api/newsletter/subscribe` valide → 200 + ligne pending + Mail::assertSent
4. ✅ Email contient les liens `/newsletter/confirm/{token_clair}` et `/newsletter/unsubscribe/{token_clair}`
5. ✅ POST sans header Origin autorisé → 403
6. ✅ OPTIONS preflight depuis origine autorisée → 204 avec headers CORS
7. ✅ Email mal formé → 422 `{"error":"validation_failed","fields":{"email":[...]}}`
8. ✅ consent absent ou false → 422
9. ✅ bot_trap rempli → 200, 0 ligne, 0 mail
10. ✅ Doublon pending → 200, ligne mise à jour (token rotaté), 1 mail (le nouveau)
11. ✅ Doublon confirmed → 200, ligne inchangée, 0 mail
12. ✅ Re-inscription après unsubscribed → 200, NOUVELLE ligne pending créée
13. ✅ 6e POST IP en 1h → 429
14. ✅ GET `/newsletter/confirm/{token}` valide → vue 200, status=confirmed
15. ✅ GET `/newsletter/confirm/{token}` expiré → vue 410, status reste pending
16. ✅ GET `/newsletter/confirm/{token-bidon}` → 404
17. ✅ GET `/newsletter/unsubscribe/{token}` valide depuis pending → vue 200, status=unsubscribed
18. ✅ GET `/newsletter/unsubscribe/{token}` valide depuis confirmed → vue 200, status=unsubscribed
19. ✅ GET `/newsletter/unsubscribe/{token-bidon}` → 404
20. ✅ Isolation tenant : asso B ne peut pas confirmer un token de l'asso A
21. ✅ `php artisan newsletter:forget alice@example.fr` → toutes les lignes hard-deletées
22. ✅ `Log::info` ne contient ni email ni IP ni tokens clairs (assertion sur le contenu du log)

### 3.15 Documentation

Section `## API publique — newsletter` ajoutée au `README.md` :
- Description courte (endpoint, double opt-in, désinscription, RGPD)
- Exemple `curl` complet (POST avec Origin)
- Schéma de la table buffer (colonnes principales)
- Lien vers le repo site SVS : https://github.com/jkurz78/www.soigner-vivre-sourire.fr
- Note sur le hors-scope : "L'import des demandes confirmées vers la table `tiers` sera traité dans une PR ultérieure comme nouvel élément de la Boîte de réception."

---

## 4. Acceptance Criteria

### 4.1 Inventaire de fichiers

**Créés :**
- `database/migrations/YYYY_MM_DD_HHMMSS_create_newsletter_subscription_requests_table.php`
- `app/Enums/Newsletter/SubscriptionRequestStatus.php`
- `app/Models/Newsletter/SubscriptionRequest.php`
- `app/Services/Newsletter/SubscriptionService.php`
- `app/Services/Newsletter/Exceptions/ConfirmationExpiredException.php`
- `app/Http/Requests/Api/SubscribeNewsletterRequest.php`
- `app/Http/Controllers/Api/NewsletterSubscriptionController.php`
- `app/Http/Middleware/Api/BootTenantFromNewsletterOrigin.php`
- `app/Mail/NewsletterConfirmation.php`
- `app/Console/Commands/Newsletter/ForgetSubscriberCommand.php`
- `config/newsletter.php`
- `config/cors.php` (nouveau, avec uniquement le path newsletter)
- `resources/views/emails/newsletter/confirmation.blade.php`
- `resources/views/emails/newsletter/confirmation-text.blade.php`
- `resources/views/newsletter/confirmed.blade.php`
- `resources/views/newsletter/unsubscribed.blade.php`
- `resources/views/newsletter/expired.blade.php`
- `resources/views/layouts/public-minimal.blade.php` (si pas réutilisable)
- `tests/Feature/Api/NewsletterSubscriptionTest.php`
- `database/factories/Newsletter/SubscriptionRequestFactory.php`

**Modifiés :**
- `routes/api.php` (ajout group newsletter)
- `routes/web.php` (ajout 2 routes confirm/unsubscribe)
- `bootstrap/app.php` (RateLimiter::for('newsletter'))
- `README.md` (section API publique newsletter)

### 4.2 Critères de succès

- ✅ Suite Pest passe à 100 % (existante + nouveaux tests, 0 failed)
- ✅ `./vendor/bin/pint --test` : 0 erreur de style
- ✅ `php artisan test --filter=Newsletter` : 100 % vert
- ✅ Test manuel local : POST depuis `curl -X POST -H "Origin: http://localhost:4321" -H "Content-Type: application/json" http://localhost/api/newsletter/subscribe -d '{"email":"test@example.fr","prenom":"Test","consent":true,"bot_trap":""}'` → 200 + ligne en DB + email visible dans Pail
- ✅ Test manuel local : ouvrir le lien de confirmation → vue de remerciement, ligne `confirmed`
- ✅ Test manuel local : ouvrir le lien de désinscription → vue de désinscription, ligne `unsubscribed`
- ✅ Test isolation : avec une seconde asso seedée, vérifier qu'elle ne peut PAS résoudre/confirmer un token de SVS (test automatique)
- ✅ Test cross-origin : `curl -H "Origin: https://attaquant.example"` → 403 (test automatique)
- ✅ README à jour avec la section API + curl + lien repo SVS

### 4.3 Branche et PR

- Branche : `feat/newsletter-public-api`
- Commits : conventional, atomiques (1 commit par étape TDD majeure)
- PR unique vers `main`
- Le titre PR : `feat: API publique newsletter (buffer + double opt-in)`

---

## 5. Hors-scope (PRs ultérieures)

- **Back-office d'import buffer → Tiers** : nouvel élément Boîte de réception qui liste les `confirmed` non-importés, propose fusion via `TiersMergeModal`, crée/met à jour le Tiers, alimente `tiers_id` sur le buffer. Réutilisera le pattern d'import CSV/XLSX (v2.7.2) pour la déduplication.
- **Envoi de campagnes newsletter** : module à part, s'appuiera sur les Tiers importés (avec opt-in confirmé) et le module Communication Tiers existant (v2.12.0).
- **Autres formulaires SVS** (contact, pré-inscription équithérapie) : chaque formulaire = 1 buffer dédié + 1 endpoint API + 1 PR.

---

## 6. Consistency Gate

| Item | Vérifié |
|---|---|
| Multi-tenant : table extends `TenantModel`, scope global fail-closed | ✅ |
| Résolution tenant : Origin header → config (pas de slug, pas de session) | ✅ |
| Pas d'écriture directe dans `tiers` (cf. feedback_buffer_imports_externes) | ✅ |
| RGPD : trace conservée (statuts, pas de soft-delete) ; `newsletter:forget` pour le droit à l'effacement | ✅ |
| RGPD : désinscription disponible dès le 1er email (pre-confirmation) | ✅ |
| Anti-énumération : 200 silencieux pour email confirmed, pour honeypot, pour rate-limit (pas révélé en clair côté client autre que via 429 explicite — note : 429 est OK, c'est un rate global IP, pas un signal sur l'email) | ✅ |
| Tokens : sha256 en DB, clair en URL (cf. spec utilisateur) | ✅ |
| URLs emails : `TenantUrl::route(...)` (convention projet) | ✅ |
| Conventions code : `declare(strict_types=1)`, `final class`, type hints, FR locale | ✅ |
| Conventions tests : Pest, RefreshDatabase, Mail::fake, bootstrap global TenantContext | ✅ |
| Pas de backward-compat à inventer (table neutre, pas de hack) | ✅ |
| Hors-scope explicitement listé | ✅ |

**Statut : PASS** — prête pour `/plan`.

---

## 7. Ouverture / décisions différées

- **Layout public minimaliste** : si un layout `public-minimal` réutilisable n'existe pas (utilisé par `email-optout` etc.), on le crée. Sinon on réutilise. À vérifier au build.
- **Charte SVS dans l'email** : minimale (couleurs, logo si présent dans `public/` côté tenant SVS). Pas de design fancy. Le mail doit passer les filtres anti-spam (texte plain en parallèle du HTML, mentions légales explicites, lien désinscription au-dessus de la pliure).
- **Test isolation tenant cross-token** : à inclure dans la suite (asso B ne doit pas pouvoir consommer un token de SVS). Repose sur l'unicité globale du `unsubscribe_token_hash` + le boot tenant à partir de `$row->association_id`.
