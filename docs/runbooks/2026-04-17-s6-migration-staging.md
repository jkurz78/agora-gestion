# Runbook — Migration prod → staging multi-tenant

**Date :** 2026-04-17
**Branche :** `feat/multi-tenancy-s1`
**Auteur :** Jurgen Kurz

---

## Objectif

Valider la bascule multi-tenant sur une copie fidèle de la production O2Switch avant tout merge `main` / déploiement prod. La procédure suit l'ordre suivant : (1) validation locale fresh install, (2) clone prod → staging NAS sans anonymisation (exception ponctuelle documentée), (3) vérification que le staging est identique à la prod, (4) installation de la branche multi-tenant sur staging et migration des données (l'association SVS existante devient tenant #1), (5) non-régression complète sur SVS en tenant #1, (6) décision go/no-go pour merge `main` et bascule prod — étape hors S6.

---

## Phase 1 — Validation locale (préalable)

### 1.1 Fresh install

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

> Le `DatabaseSeeder` crée l'association #1 "Mon Association", les comptes bancaires, les catégories, les types d'opération, les templates email et les opérations de test. Aucun `SuperAdminSeeder` distinct n'existe — le super-admin est configuré manuellement en Tinker (voir 1.3).

### 1.2 Configurer le super-admin en Tinker

```bash
./vendor/bin/sail artisan tinker
```

```php
User::where('email', 'admin@monasso.fr')
    ->update(['role_systeme' => \App\Enums\RoleSysteme::SuperAdmin->value]);
```

Vérification :

```php
User::where('email', 'admin@monasso.fr')->value('role_systeme');
// Attendu : "super_admin"

exit;
```

### 1.3 Créer une association via wizard

- Se connecter sur `http://localhost` avec `admin@monasso.fr` / `password`.
- Accéder à `/super-admin/` → liste des associations.
- Créer une nouvelle association "Test" et inviter un admin test.
- Se logger en admin test → le wizard 9 étapes se déclenche automatiquement (middleware `ForceWizardIfNotCompleted`).
- Compléter le wizard → vérifier que `/dashboard` est accessible à la fin.

### 1.4 Non-régression rapide

- Créer une opération, un tiers, une facture, un règlement.
- Exporter les tiers en CSV.
- Vérifier les logs :

```bash
./vendor/bin/sail artisan tinker
```

```php
// Vérifier que les entrées récentes ont bien un association_id
\DB::table('operations')->latest()->limit(3)->get(['id', 'association_id']);
\DB::table('tiers')->latest()->limit(3)->get(['id', 'association_id']);

exit;
```

Contrôler l'absence d'erreur dans les logs :

```bash
grep -i 'error\|exception' storage/logs/laravel.log | tail -20
```

✔ OK si aucune exception associée à un `association_id` null ou une violation de contrainte.

---

## Phase 2 — Clone prod → staging NAS (sans anonymisation)

> ⚠ **Exception de sécurité documentée :** les données de production sont copiées sur le NAS staging **sans anonymisation**, par nécessité fonctionnelle (vérification de la migration réelle des données SVS). Le dump prod doit être **purgé obligatoirement** après la recette (Phase 5).

### 2.1 Dump prod sur O2Switch

Se connecter en SSH sur O2Switch, puis :

```bash
# Adapter {user} et {db} aux variables réelles O2Switch
mysqldump -u {user} -p {db} \
  --single-transaction \
  --quick \
  --set-gtid-purged=OFF \
  > /tmp/prod-dump-$(date +%Y-%m-%d).sql

tar czf /tmp/prod-storage-$(date +%Y-%m-%d).tar.gz storage/app
```

### 2.2 Transfert vers NAS

Depuis la machine dev (ou directement O2Switch → NAS) :

```bash
scp user@o2switch:/tmp/prod-dump-*.sql     nas:/volume1/agora-staging/
scp user@o2switch:/tmp/prod-storage-*.tar.gz nas:/volume1/agora-staging/
```

Supprimer les fichiers temporaires sur O2Switch dès le transfert confirmé :

```bash
rm /tmp/prod-dump-*.sql /tmp/prod-storage-*.tar.gz
```

### 2.3 Import sur staging NAS

```bash
mysql -u {user} -p {db_staging} < /volume1/agora-staging/prod-dump-*.sql
tar xzf /volume1/agora-staging/prod-storage-*.tar.gz -C /volume1/agora-staging/app/
```

### 2.4 Vérification staging = prod

Se connecter sur le staging (mysql ou Tinker) et comparer les volumétries :

```sql
SELECT COUNT(*) FROM operations;
SELECT COUNT(*) FROM factures;
SELECT COUNT(*) FROM tiers;
SELECT COUNT(*) FROM transactions;
```

Comparer avec les mêmes chiffres relevés sur prod avant le dump. Ouvrir `/dashboard` sur staging → les KPIs doivent correspondre à la prod.

✔ staging validé comme copie fidèle de la prod.

---

## Phase 3 — Installer la branche multi-tenant sur staging

### 3.1 Pull de la branche

> **Prérequis :** le dépôt doit être cloné dans `/volume1/agora-staging`. Si ce n'est pas encore le cas :
> ```bash
> git clone git@github.com:jurgenkurz/agora-gestion.git /volume1/agora-staging
> cd /volume1/agora-staging
> cp .env.example .env  # puis configurer DB_* pour pointer sur la base staging importée en 2.3
> php artisan key:generate
> ```

```bash
cd /volume1/agora-staging
git fetch origin feat/multi-tenancy-s1
git checkout feat/multi-tenancy-s1
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan cache:clear
```

### 3.2 Lancer les migrations multi-tenant

> ⚠ Cette étape est irréversible sur le staging. Assurez-vous d'avoir le dump prod à portée pour un éventuel rollback (Phase 4).

```bash
php artisan migrate --force
```

Les migrations S1-S6 executées sur les données prod doivent :

- Enrichir la table `association` (ajout de `slug`, `statut`, `exercice_mois_debut`, `wizard_completed_at`) — **migration `2026_04_15_100001_enrich_associations_table`**.
- Créer la table pivot `association_user` et y migrer les rôles existants — **migrations `100003` et `100050`**.
- Ajouter `role_systeme` sur `users` — **migration `2026_04_15_100004_enrich_users_table_multi_tenant`**.
- Ajouter `association_id` sur toutes les tables scopées (groupes A à E) — **migrations `100010` à `100014`**.
- Backfiller `association_id = 1` sur toutes les lignes existantes — **migration `2026_04_15_100020_backfill_association_id_svs`** (si aucune asso n'existe, elle en crée une par défaut ; si l'asso SVS existe déjà, elle rattache toutes les lignes à son id).
- Rendre `association_id` non-null et ajouter les contraintes — **migrations `100030`, `100040`, `100041`**.
- Backfiller les chemins de fichiers multi-tenant (storage par asso) — **migrations `2026_04_17_100000` à `100070`**.

Vérification post-migration :

```sql
-- L'association SVS doit avoir un slug non-null
SELECT id, nom, slug, statut FROM association;

-- Toutes les opérations doivent avoir association_id renseigné
SELECT COUNT(*) FROM operations WHERE association_id IS NULL;
-- Attendu : 0

SELECT COUNT(*) FROM tiers WHERE association_id IS NULL;
-- Attendu : 0

SELECT COUNT(*) FROM factures WHERE association_id IS NULL;
-- Attendu : 0

SELECT COUNT(*) FROM transactions WHERE association_id IS NULL;
-- Attendu : 0

SELECT COUNT(*) FROM comptes_bancaires WHERE association_id IS NULL;
-- Attendu : 0
```

### 3.3 Configurer le super-admin sur staging

```bash
php artisan tinker
```

```php
// 1) Lister les users existants pour identifier l'email de l'admin SVS réel en prod
//    (admin@monasso.fr est l'email de dev/seed, il n'existera probablement pas en prod)
User::orderBy('id')->get(['id', 'email', 'role_systeme']);

// 2) Promouvoir — adapter l'email ci-dessous au résultat de l'étape 1
$updated = User::where('email', '{email-admin-svs-prod}')
    ->update(['role_systeme' => \App\Enums\RoleSysteme::SuperAdmin->value]);

// 3) Vérifier que la mise à jour a bien touché exactement 1 ligne
//    (0 = email inexistant, > 1 = doublon de compte — stopper et investiguer)
if ($updated !== 1) {
    throw new RuntimeException("Promotion super-admin échouée : {$updated} rows");
}

// 4) Confirmer la valeur
User::where('email', '{email-admin-svs-prod}')->value('role_systeme');
// Attendu : "super_admin"

exit;
```

### 3.4 Checklist non-régression SVS en tant que tenant #1

Effectuer chaque vérification sur le staging après migration. Cocher chaque item :

- [ ] **Login admin SVS** → `/dashboard` s'affiche avec les KPIs SVS historiques (mêmes chiffres qu'avant migration).
- [ ] **`/operations`** → liste complète des opérations, volumétrie identique à l'avant-migration.
- [ ] **`/tiers`** → liste complète des tiers, volumétrie identique.
- [ ] **`/facturation/factures`** → liste des factures intacte ; ouvrir une facture → PDF encore accessible (chemin storage correct).
- [ ] **`/rapports/compte-resultat`** → CERFA se génère sans erreur.
- [ ] **`/rapports/analyse`** → tableau pivot chargé, données correctes.
- [ ] **Export CSV tiers** → fichier téléchargeable, contenu identique à l'avant-migration.
- [ ] **Attestation de présence PDF** → génération OK sur une séance existante.
- [ ] **Email transactionnel** → envoi OK (vérifier que les paramètres SMTP sont bien repris depuis `smtp_parametres` ou `.env`).
- [ ] **Logs** → `grep 'association_id' storage/logs/laravel.log` — les entrées récentes doivent montrer `association_id: 1` (ou équivalent JSON), aucune exception `association_id null`.
- [ ] **Super-admin `/super-admin/`** → liste affichée avec 1 tenant (SVS), statut `actif`.
- [ ] **Super-admin crée un 2e tenant** "Asso Test" → wizard s'ouvre pour l'admin invité, fonctionne en parallèle sans interférence avec SVS.
- [ ] **Isolation inter-tenant** → depuis le compte admin SVS, aucune opération/tiers de l'asso Test n'est visible (et vice-versa).

### 3.5 Tests d'isolation depuis la machine dev

Lancer avec les variables d'environnement pointant vers le staging (ou localement avec la base staging montée) :

```bash
php artisan test --testsuite=Feature --filter=MultiTenancy
```

Cible les classes :
- `tests/Feature/MultiTenancy/Isolation/CrossTenantAccessTest.php`
- `tests/Feature/MultiTenancy/Isolation/CrossTenantStorageTest.php`

Tous verts attendus (suite à 0 failed).

---

## Phase 4 — Décision

- **✅ GO** — Si la checklist 3.4 est entièrement cochée ET les tests 3.5 sont tous verts : feu vert pour merge `main` + déploiement prod. Cette étape est **hors S6** et fait l'objet d'un runbook séparé.
- **❌ ROLLBACK** — Si une régression est détectée :
  1. Sur staging : `git checkout main` (ou la version prod en cours).
  2. **Recréer la base staging vide** avant de ré-importer le dump (sinon l'import échoue sur les tables déjà altérées par `migrate --force`) :
     ```bash
     mysql -u {user} -p -e "DROP DATABASE {db_staging}; \
       CREATE DATABASE {db_staging} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
     ```
  3. Restaurer la base staging depuis le dump prod importé en Phase 2 :
     ```bash
     mysql -u {user} -p {db_staging} < /volume1/agora-staging/prod-dump-*.sql
     ```
  4. Restaurer aussi le storage depuis le tar d'origine si des fichiers ont été déplacés par les migrations S6 :
     ```bash
     rm -rf /volume1/agora-staging/storage/app
     tar xzf /volume1/agora-staging/prod-storage-*.tar.gz -C /volume1/agora-staging/
     ```
  5. Créer un ticket détaillant la régression, avec reproduction minimale.
  6. Retour en développement sur `feat/multi-tenancy-s1`.

---

## Phase 5 — Nettoyage (obligatoire)

> ⚠ **Obligatoire** — les données de prod non-anonymisées doivent être purgées dès la fin de la recette, qu'elle soit validée ou non.

```bash
# Sur le NAS — purger le dump et les fichiers storage copiés
rm /volume1/agora-staging/prod-dump-*.sql
rm /volume1/agora-staging/prod-storage-*.tar.gz

# Vider ou supprimer la base staging si elle n'est plus nécessaire
# mysql -u {user} -p -e "DROP DATABASE {db_staging};"
```

Vérifier également qu'aucune copie intermédiaire ne subsiste sur la machine dev ou O2Switch.

---

## Références

| Élément | Valeur |
|---|---|
| Branche | `feat/multi-tenancy-s1` |
| Migration backfill SVS | `2026_04_15_100020_backfill_association_id_svs.php` |
| Migration enrichissement asso | `2026_04_15_100001_enrich_associations_table.php` |
| Enum super-admin | `App\Enums\RoleSysteme::SuperAdmin` → valeur `'super_admin'` |
| Middleware suspension | `App\Http\Middleware\ResolveTenant` → `abort(403)` sur statut `suspendu`/`archive` |
| Tests isolation | `tests/Feature/MultiTenancy/Isolation/` |
| Route super-admin | `/super-admin/` |
| Route factures | `/facturation/factures` (pas `/factures`) |
| Route rapports | `/rapports/compte-resultat`, `/rapports/analyse` |
