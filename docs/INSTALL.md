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

# QUEUE_CONNECTION=database  # Recommandé en prod (voir §7)
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

## 5. Premier compte utilisateur

L'installation fresh n'a aucun compte. Deux options pour créer le premier user :

### Option A — Via la page d'inscription (si Breeze est activé)

Ouvrir `https://votre-domaine.fr/register` et remplir le formulaire. Cela crée
un user standard sans rôle système.

### Option B — Via Tinker (recommandé pour la prod, plus contrôlé)

```bash
php artisan tinker
```

```php
$user = \App\Models\User::create([
    'name' => 'Marie Dupont',
    'email' => 'marie@votre-domaine.fr',
    'password' => bcrypt('changez-moi'),
    'email_verified_at' => now(),
]);
```

Sortir avec `exit`.

---

## 6. Promotion du premier super-admin

Le rôle **super-admin** donne accès à `/super-admin/*` : création
d'associations, mode support read-only, suspension/archivage de tenants.

```bash
php artisan app:promote-super-admin marie@votre-domaine.fr
```

La commande affiche `User marie@votre-domaine.fr promoted to super-admin.`
puis vous pouvez vous connecter et accéder à `/super-admin`.

> 🔒 La promotion en super-admin est délibérément réservée à la CLI (pas
> d'interface) : c'est un acte d'admin système, pas un acte applicatif.

---

## 7. Cron (scheduler) et queue worker

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

## 8. Création de la première association

1. Connectez-vous avec votre compte super-admin → `/super-admin`.
2. Cliquez **« Créer une association »** → renseignez :
   - **Nom**
   - **Slug** (identifiant URL unique, ex. `mon-asso`)
   - **Email** de l'administrateur de cette association
3. L'admin de l'asso reçoit un mail d'invitation avec un lien de réinitialisation
   de mot de passe (valide 60 minutes).
4. À sa première connexion, l'admin est dirigé vers le **wizard d'onboarding**
   (9 étapes : infos générales, exercice comptable, comptes bancaires, plan
   comptable, modèles email, etc.).

Détails complets : voir [`onboarding-new-tenant.md`](onboarding-new-tenant.md).

---

## 9. Smoke test post-déploiement

```bash
# Vérification rapide de l'instance
php artisan about | head -20
php artisan migrate:status | tail
php artisan tinker --execute="echo \App\Models\User::count();"
```

Dans le navigateur :

- `/login` → se connecter avec le super-admin
- `/super-admin/associations` → voir l'asso créée à l'étape 8
- `/dashboard` (en mode support depuis l'asso) → vérifier l'isolation tenant
- Créer une dépense de test, attacher un PDF, vérifier qu'il s'enregistre dans
  `storage/app/associations/{id}/…`

---

## 10. Mise à jour

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
