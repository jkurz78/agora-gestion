# Newsletter subscription shim — PHP

Petit proxy PHP à déposer sur le site vitrine d'une asso pour relayer un formulaire d'inscription newsletter vers son instance AgoraGestion, **avec authentification HMAC**.

## Pourquoi ce shim ?

L'API `POST /api/newsletter/subscribe` d'AgoraGestion est authentifiée par **signature HMAC-SHA256** (pattern Stripe / AWS / Twilio). Le secret HMAC ne doit JAMAIS apparaître côté navigateur (JS, HTML), donc on a besoin d'un petit relais **server-side** sur le site appelant.

```
┌─── Navigateur ──────────────────────────────────────────────────────────┐
│  Formulaire HTML (statique) sur https://votre-site.org                  │
│  POST /forms/newsletter-shim.php   ← same-origin, pas de CORS           │
└────────────────────────┬────────────────────────────────────────────────┘
                         │
                         ▼
┌─── Apache + PHP (sur le même domaine que le formulaire) ────────────────┐
│  newsletter-shim.php :                                                  │
│    1. Lit le payload entrant                                            │
│    2. Lit NEWSLETTER_KEY_ID + NEWSLETTER_HMAC_SECRET depuis .env        │
│    3. Calcule HMAC-SHA256(timestamp + payload, secret)                  │
│    4. POST vers NEWSLETTER_ENDPOINT avec X-Key-Id / X-Signature         │
│    5. Renvoie la réponse au navigateur                                  │
└────────────────────────┬────────────────────────────────────────────────┘
                         │ HTTPS server-to-server
                         ▼
┌─── AgoraGestion ────────────────────────────────────────────────────────┐
│  Vérifie la signature, identifie l'asso, traite l'inscription           │
│  (double opt-in RGPD, email de confirmation, etc.)                      │
└─────────────────────────────────────────────────────────────────────────┘
```

Le shim n'est **pas spécifique à une asso** : il fonctionne pour n'importe quel client AgoraGestion. C'est la config (`.env`) qui le rattache à une instance + une clé.

## Pré-requis

- **Hébergeur avec PHP** ≥ 8.0 (la plupart des hébergements mutualisés type O2Switch / OVH le proposent par défaut).
- **Extension cURL** activée (standard, déjà présente partout).
- **Apache** ou **Nginx** avec exécution PHP (ce repo fournit la config Apache via `.htaccess` ; pour Nginx, adapter selon la doc de l'hébergeur).
- **HTTPS** sur l'instance AgoraGestion cible (le shim refuse les certificats invalides via `CURLOPT_SSL_VERIFYPEER`).

## Installation

### 1. Récupérer une paire `(KEY_ID, SECRET)` côté AgoraGestion

L'admin de l'instance AgoraGestion crée une clé dédiée à ce site :

```bash
php artisan newsletter:keys:create --association=<id> --label="Site vitrine prod"
```

La commande affiche **une seule fois** le secret en clair. Le copier immédiatement, il n'est plus récupérable ensuite (stocké chiffré en DB).

Sortie type :
```
✓ Clé API créée pour l'asso #1 (Soigner Vivre Sourire).

  KEY_ID  : ak_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
  SECRET  : 7f8c2e9d4a1b6f3e8c5d9a2b7f4e1d6c3a9b5e2d7f4c1a8b6e3d5c9f2a7b4e1d

⚠️  Le secret n'est affiché qu'une fois. Le stocker en lieu sûr.
   Pour le perdre = révoquer la clé et en créer une nouvelle.
```

### 2. Déposer le shim sur le site vitrine

Copier les 4 fichiers de ce dossier dans un sous-dossier du webroot du site, par exemple `/forms/` :

```
public_html/
└── forms/
    ├── newsletter-shim.php       ← le code
    ├── .env                      ← à créer depuis .env.example, jamais commit
    ├── .env.example
    ├── .htaccess                 ← config Apache
    └── README.md
```

### 3. Configurer le `.env`

Copier `.env.example` en `.env` dans le même dossier, puis renseigner les 3 valeurs :

```env
NEWSLETTER_ENDPOINT=https://agora.<votre-asso>.org/api/newsletter/subscribe
NEWSLETTER_KEY_ID=ak_a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6
NEWSLETTER_HMAC_SECRET=7f8c2e9d4a1b6f3e8c5d9a2b7f4e1d6c3a9b5e2d7f4c1a8b6e3d5c9f2a7b4e1d
```

**S'assurer que `.env` est bien :**
- Ajouté au `.gitignore` du site appelant
- Bloqué en lecture HTTP via `.htaccess` (déjà fait)
- En lecture-seule pour l'utilisateur Apache (`chmod 600 .env` ou équivalent)

### 4. Tester l'installation

```bash
curl -X POST https://votre-site.org/forms/newsletter-shim.php \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.fr","prenom":"Test","nom":"Dupont","consent":true,"bot_trap":""}'
```

Réponse attendue : `200` avec `{"status":"pending_double_optin"}`.

Si tu reçois `500 {"error":"shim_misconfigured"}` : vérifier que les 3 variables sont bien lues (mauvais chemin du `.env`, droits, syntaxe).

Si tu reçois `403` : la signature est rejetée par AgoraGestion. Causes possibles : mauvais secret, horloge serveur décalée de plus de 5 minutes par rapport à AgoraGestion, clé révoquée côté AgoraGestion.

## Intégration dans un formulaire HTML

Le shim accepte deux formats : `application/x-www-form-urlencoded` (formulaire HTML classique) et `application/json` (fetch JS).

### Option A — Formulaire HTML pur (sans JS)

```html
<form method="POST" action="/forms/newsletter-shim.php">
    <label>Email
        <input type="email" name="email" required>
    </label>

    <label>Prénom (optionnel)
        <input type="text" name="prenom">
    </label>

    <label>Nom (optionnel)
        <input type="text" name="nom">
    </label>

    <label>
        <input type="checkbox" name="consent" value="1" required>
        J'accepte de recevoir la newsletter (RGPD)
    </label>

    <!-- Honeypot anti-bot : caché en CSS, doit rester vide -->
    <div style="position:absolute;left:-9999px" aria-hidden="true">
        <label>Ne pas remplir <input type="text" name="bot_trap" tabindex="-1" autocomplete="off"></label>
    </div>

    <button type="submit">S'inscrire</button>
</form>
```

⚠️ Avec cette approche, après soumission le navigateur affiche la réponse JSON brute. Pour une UX correcte, préférer l'option B avec JS, ou ajouter une page de remerciement (`<form action="/forms/thanks.html?...">` en redirigeant côté shim — non implémenté dans ce shim v1).

### Option B — Formulaire avec `fetch()` (UX recommandée)

```html
<form id="newsletter-form">
    <label>Email <input type="email" name="email" required></label>
    <label>Prénom <input type="text" name="prenom"></label>
    <label>Nom <input type="text" name="nom"></label>
    <label>
        <input type="checkbox" name="consent" required>
        J'accepte de recevoir la newsletter
    </label>

    <div style="position:absolute;left:-9999px" aria-hidden="true">
        <input type="text" name="bot_trap" tabindex="-1" autocomplete="off">
    </div>

    <button type="submit">S'inscrire</button>
    <p id="newsletter-status" role="status" aria-live="polite"></p>
</form>

<script>
document.getElementById('newsletter-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const status = document.getElementById('newsletter-status');
    const submit = form.querySelector('button[type="submit"]');

    submit.disabled = true;
    status.textContent = 'Envoi en cours…';

    const data = Object.fromEntries(new FormData(form));
    data.consent = data.consent === 'on';

    try {
        const response = await fetch('/forms/newsletter-shim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(data),
        });
        const body = await response.json();

        if (response.ok && body.status === 'pending_double_optin') {
            status.textContent = 'Merci ! Un email de confirmation vous a été envoyé.';
            form.reset();
        } else if (response.status === 422) {
            status.textContent = 'Vérifiez les champs : ' + Object.keys(body.fields || {}).join(', ');
        } else if (response.status === 429) {
            status.textContent = 'Trop d\'essais, réessayez dans une heure.';
        } else {
            status.textContent = 'Une erreur est survenue. Réessayez plus tard.';
        }
    } catch (err) {
        status.textContent = 'Erreur réseau. Vérifiez votre connexion.';
    } finally {
        submit.disabled = false;
    }
});
</script>
```

## Contrat HTTP côté AgoraGestion

Le shim envoie :

```
POST <NEWSLETTER_ENDPOINT>
Content-Type: application/json
Accept: application/json
User-Agent: AgoraGestion-Newsletter-Shim/1.0 (PHP)
X-Key-Id: ak_<32 hex chars>
X-Timestamp: <Unix timestamp seconds>
X-Signature: v1=<hex HMAC-SHA256 of "{X-Timestamp}.{request body}" using SECRET>

{"email":"alice@example.fr","prenom":"Alice","nom":"Dupont","consent":true,"bot_trap":""}
```

AgoraGestion vérifie :
1. La clé `X-Key-Id` existe en DB et n'est pas révoquée
2. `X-Timestamp` est dans une fenêtre de ±300 secondes par rapport à `now()` (anti-replay)
3. `X-Signature` recalculé côté serveur correspond bit-à-bit à celui reçu (`hash_equals`, timing-safe)

Si l'une des 3 vérifications échoue → `403 Forbidden` (silencieux, pas de détail révélé).

Si tout passe → AgoraGestion identifie l'asso via la FK de la clé, boote le tenant, applique les contrôles métier (validation, honeypot, rate limit, idempotence), et renvoie la réponse.

## Réponses possibles

| HTTP | Body | Sens |
|---|---|---|
| `200` | `{"status":"pending_double_optin"}` | Inscription enregistrée OU email déjà confirmé (anti-énumération) OU honeypot rempli (silencieux) |
| `422` | `{"error":"validation_failed","fields":{"email":["..."],...}}` | Payload invalide (email mal formé, consent absent, etc.) |
| `429` | `{"error":"rate_limit"}` | Trop de soumissions depuis cette IP (5/heure par défaut) |
| `403` | (vide ou erreur générique) | Signature invalide / clé révoquée / timestamp hors fenêtre |
| `502` | `{"error":"upstream_unavailable"}` | AgoraGestion injoignable (le shim a échoué à appeler l'API) |
| `500` | `{"error":"shim_misconfigured"}` | Variables d'env manquantes côté shim |
| `405` | `{"error":"method_not_allowed"}` | Méthode HTTP autre que POST |

## Sécurité — checklist

- [ ] `.env` est dans le `.gitignore` du site appelant
- [ ] `.env` n'est pas servi par Apache (cf. `.htaccess` fourni)
- [ ] `.env` est en `chmod 600` (lecture-seule pour l'utilisateur Apache)
- [ ] Le secret HMAC n'apparaît pas dans le code source du site (pas de copy-paste accidentel)
- [ ] HTTPS partout : sur le site appelant ET sur l'endpoint AgoraGestion
- [ ] Une clé par site (prod, dev, intranet) — pas de réutilisation
- [ ] Rotation prévue : si le serveur est compromis, révoquer la clé côté AgoraGestion immédiatement

## Limites de cette implémentation

- **PHP 8.0+ uniquement** (utilise `str_contains`, `declare(strict_types=1)`).
- **Single-file** : pas de framework, pas d'autoload Composer, pas de logger configurable. Pour un usage plus complet, intégrer dans une app PHP existante.
- **Pas de retry** : en cas d'échec de l'appel AgoraGestion, on remonte simplement un 502. À l'utilisateur de retenter manuellement.
- **Logs minimaux** : seules les erreurs serveur sont log via `error_log()`. Pas de log d'audit (volontaire — moins de PII traînant côté site appelant).
- **Pas de redirect post-success** : le shim renvoie toujours du JSON. Pour une redirection HTML, utiliser le pattern `fetch()` JS (option B ci-dessus).

Si l'un de ces points est bloquant, ouvrir une issue ou un PR sur le repo AgoraGestion.

## Mise à jour

Le shim suit le versioning de l'API AgoraGestion. Le préfixe `v1=` dans `X-Signature` permet une évolution rétrocompatible (futur `v2=` avec un schéma différent).

Tant que la signature reste en `v1=`, **mettre à jour le shim** ne change pas le comportement côté serveur. Pour une mise à jour majeure (`v2=`), AgoraGestion publiera une note de migration.

## Licence

MIT (cohérent avec AgoraGestion).
