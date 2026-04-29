# Installation production

Ce guide couvre l'installation **from-scratch** d'une instance AgoraGestion en production.
Pour un environnement de développement local, voir le [README](../README.md).

> ℹ️ AgoraGestion est multi-tenant : une seule instance peut héberger plusieurs
> associations, isolées par un scope global `association_id`. Le rôle
> **super-admin** gère le cycle de vie des tenants.

---

## 1. Prérequis serveur

| Composant | Version minimale |
|---|---|
| PHP | 8.4 |
| Extensions PHP | `pdo_mysql`, `mbstring`, `xml`, `dom`, `curl`, `zip`, `intl`, `bcmath`, `imagick` (PDF), `gd` |
| Composer | 2.x |
| MySQL | 8.0 (ou MariaDB 10.6+) |
| Node.js | non requis (Bootstrap chargé via CDN, pas de build frontend) |
| Web server | Nginx ou Apache (PHP-FPM 8.4) |
| Cron | requis pour le scheduler (réception mail, queue, rappels…) |
| Ghostscript | requis pour la génération PDF/A-3 (Factur-X) |

---

## 2. Récupération du code

```bash
git clone https://github.com/<votre-org>/agora-gestion.git /var/www/agora-gestion
cd /var/www/agora-gestion
composer install --no-dev --optimize-autoloader
```

---

## 3. Configuration `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Éditez `.env` et fournissez au minimum :

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-domaine.fr

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=agora_gestion
DB_USERNAME=agora
DB_PASSWORD=...

MAIL_MAILER=smtp
MAIL_HOST=smtp.exemple.fr
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@votre-domaine.fr"
MAIL_FROM_NAME="Votre asso"

# QUEUE_CONNECTION=database  # Recommandé en prod (voir §6)
# SESSION_DRIVER=database
```

> ⚠️ La rotation de `APP_KEY` invalide les sessions actives **et les valeurs
> chiffrées en base** (mots de passe IMAP, données médicales). Sauvegardez
> précieusement cette clé.

---

## 4. Migrations

```bash
php artisan migrate --force
```

> ❗ Ne pas lancer `--seed` en production : les seeders créent des comptes
> dev fictifs (`admin@monasso.fr`, `jean@monasso.fr`) qu'il faudrait
> ensuite supprimer.

---

## 5. Bootstrap via le navigateur (`/setup`)

Ouvrez votre navigateur sur `https://votre-domaine.fr` (ou l'URL configurée dans `APP_URL`). L'application détecte qu'aucun super-admin n'existe et vous redirige automatiquement vers `/setup`.

Remplissez le formulaire :

| Champ | Description |
|---|---|
| Prénom + Nom | Votre identité (vous êtes le premier utilisateur, donc le futur super-administrateur) |
| Email + Mot de passe | Vos identifiants de connexion |
| Nom de votre association | Le nom de votre asso. Le slug technique est généré automatiquement. |

Au submit, l'application crée en une seule transaction :

- **Votre compte utilisateur**, avec le rôle `super-admin` (vous pourrez créer d'autres associations plus tard via `/super-admin/`)
- **Votre première association**, avec des valeurs par défaut sensées (forme juridique « Association loi 1901 », exercice comptable septembre→août — tout cela est éditable au prochain écran)
- **Votre rattachement** comme admin de cette association
- **Votre session** est ouverte automatiquement

Vous êtes alors redirigé vers `/dashboard` qui détecte que le wizard d'asso n'a pas encore été complété et vous emmène sur les **8 étapes du wizard** (identité, exercice, banque, SMTP, HelloAsso, IMAP, catégories, récap). Cette étape est obligatoire pour finaliser la configuration de l'asso.

---

## 6. Cron (scheduler) et queue worker

### Cron

Ajouter au crontab de l'utilisateur web (ex. `www-data`) :

```cron
* * * * * cd /var/www/agora-gestion && php artisan schedule:run >> /dev/null 2>&1
```

Cela permet : réception mail (IMAP), envoi des rappels d'opération,
nettoyage des tokens expirés, etc.

### Queue worker (recommandé)

Configurer un service systemd ou supervisor :

```ini
# /etc/supervisor/conf.d/agora-gestion-queue.conf
[program:agora-gestion-queue]
command=php /var/www/agora-gestion/artisan queue:work --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
stdout_logfile=/var/log/agora-gestion/queue.log
```

Puis `supervisorctl reread && supervisorctl update`.

---

## 7. Smoke test post-déploiement

```bash
# Vérification rapide de l'instance
php artisan about | head -20
php artisan migrate:status | tail
php artisan tinker --execute="echo \App\Models\User::count();"
```

Dans le navigateur :

- `/login` → se connecter avec le super-admin
- `/super-admin/associations` → voir l'asso créée à l'étape 5
- `/dashboard` (en mode support depuis l'asso) → vérifier l'isolation tenant
- Créer une dépense de test, attacher un PDF, vérifier qu'il s'enregistre dans
  `storage/app/associations/{id}/…`

---

## 8. Mise à jour

```bash
cd /var/www/agora-gestion
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan optimize  # cache config + routes + views
supervisorctl restart agora-gestion-queue  # si queue worker
```

> 💡 Un workflow GitHub Actions existe dans `.github/workflows/deploy.yml`
> pour automatiser ce processus avec déploiement par cPanel API. Adaptez-le
> à votre infra ou désactivez-le.

---

## Outils avancés

### Promotion d'un super-admin (récupération d'urgence ou multi-asso)

La page `/setup` est désactivée dès qu'un super-admin existe. Pour promouvoir un user existant en super-admin a posteriori (par exemple pour créer un second hébergeur d'asso, ou pour récupérer l'accès si vous perdez le compte initial) :

```bash
php artisan app:promote-super-admin marie@votre-domaine.fr
```

Cette commande est idempotente et accepte aussi `--demote` pour rétrograder.

---

## Configuration optionnelle

| Sujet | Documentation |
|---|---|
| Architecture multi-tenant | [`multi-tenancy.md`](multi-tenancy.md) |
| Onboarding nouveau tenant | [`onboarding-new-tenant.md`](onboarding-new-tenant.md) |
| Réception de documents par email (IMAP) | [`../README.md`](../README.md#reception-de-documents-par-mail-v28) |
| Portail tiers (NDF, factures partenaires) | [`portail-tiers.md`](portail-tiers.md) |
| Gabarits email (TinyMCE) | UI : Paramètres → Communication → Modèles email |
| Connecteur HelloAsso | UI : Paramètres → Banques → HelloAsso (clés API à demander dans le back-office HelloAsso de votre asso) |

---

## Sauvegardes

À planifier impérativement :

1. **Base de données MySQL** : dump quotidien (`mysqldump`).
2. **Stockage fichiers** : `storage/app/associations/` contient les
   pièces jointes (PDFs de dépenses, signatures, logos).
3. **`.env`** : la perte de `APP_KEY` rend les colonnes chiffrées illisibles.

Un script d'exemple pour la base démo locale est fourni dans
`scripts/backup-demo-seed.sh` (gitignored).
