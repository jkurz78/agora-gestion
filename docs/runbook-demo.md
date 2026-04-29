# Runbook — Environnement démo en ligne

**Audience** : opérateur (maintenance, reproduction, recapture snapshot)
**URL** : `https://demo.agoragestion.org`
**Version** : S3 — 2026-04-28

---

## Architecture

L'environnement démo tourne sur le même hébergeur O2Switch que la prod. Un sous-domaine dédié (`demo.agoragestion.org`) pointe vers `~/public_html/demo.agoragestion.org/`. La base de données MySQL est distincte de la prod. Le fichier `.env.demo` (posé manuellement côté serveur, non versionné) pilote la configuration. Un snapshot YAML versionné dans `database/demo/snapshot.yaml` contient les données fictives avec des dates relatives. La commande `demo:reset` rejoue ce snapshot à chaque nuit à 4h00 via cron. Le workflow `deploy-demo.yml` se déclenche sur `push main` en parallèle du workflow prod (qui reste inchangé).

---

## Pré-requis O2Switch (à faire une seule fois par l'opérateur)

- [x] Sous-domaine `demo.agoragestion.org` créé dans cPanel (DNS + SSL Let's Encrypt auto)
- [x] Base de données MySQL dédiée créée + utilisateur dédié avec tous les droits sur cette DB
- [x] Accès SSH identique à la prod (les secrets GHA `SSH_KEY`, `CPANEL_USERNAME`, `CPANEL_API_TOKEN`, `CPANEL_SERVER`, `HOME_SSH_HOST` sont réutilisés tels quels)

---

## Installation initiale (à faire une seule fois)

### (a) Poser `.env.demo` côté serveur

Le template versionné dans le repo est [`.env.demo.example`](../.env.demo.example) (à la racine du projet). Procédure :

1. Se connecter en SSH (mêmes credentials que la prod) :
   ```bash
   ssh user@cpanel.o2switch.fr
   cd ~/public_html/demo.agoragestion.org
   ```
2. Copier le template livré par le 1er déploiement (ou directement depuis le repo) :
   ```bash
   cp .env.demo.example .env
   ```
   ⚠️ Le fichier final côté serveur s'appelle `.env` (pas `.env.demo`) — Laravel ne lit que `.env`. Le suffixe `.demo` du template est juste un identifiant de format.
3. Éditer `.env` avec les vraies valeurs :
   ```bash
   nano .env
   ```
4. Remplacer les placeholders `CHANGER_MOI_*` :
   - `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` : valeurs créées dans cPanel pour la DB démo dédiée
5. Générer `APP_KEY` (DIFFÉRENT de la prod) :
   ```bash
   php artisan key:generate
   ```

> **Important** : ne jamais copier-coller la `APP_KEY` de la prod. Sinon les tokens chiffrés en prod seraient déchiffrables en démo. La commande `key:generate` écrit la nouvelle clé directement dans `.env`.

> **Sécurité** : `.env` est gitignored (jamais committé). Le template `.env.demo.example` est versionné mais ne contient que des placeholders, jamais de credentials réels.

### (b) Premier déploiement

Pousser sur `main` déclenche automatiquement `deploy-demo.yml`. Ce workflow effectue :
1. `git pull origin main`
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan config:cache`
4. `php artisan migrate:fresh --force`
5. `php artisan demo:reset` (rejoue le snapshot YAML si `database/demo/snapshot.yaml` existe)
6. `php artisan up`

Si `snapshot.yaml` n'existe pas encore au premier déploiement, `demo:reset` sort proprement (la DB reste vide) — construire le snapshot ensuite (voir section ci-dessous) puis relancer `demo:reset` manuellement.

### (c) Configurer le cron O2Switch

Dans cPanel > Tâches Cron, ajouter après le premier déploiement réussi :

```cron
0 4 * * * cd ~/public_html/demo.agoragestion.org && php artisan demo:reset >> storage/logs/demo-reset.log 2>&1
```

---

## Construction du snapshot initial

Le snapshot est construit sur une DB locale dédiée (ne jamais utiliser la prod comme source).

### Étapes

1. Créer une DB locale dédiée :
   ```bash
   ./vendor/bin/sail mysql -e "CREATE DATABASE IF NOT EXISTS agora_demo_seed CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

2. Basculer `.env` local vers `agora_demo_seed` (modifier `DB_DATABASE`) et passer `APP_ENV=demo`.

3. Migrer et seeder :
   ```bash
   ./vendor/bin/sail artisan migrate:fresh --seed
   ```

4. Ajuster l'association et les utilisateurs :
   - Renommer l'asso `Mon Association` → `Les amis de la démo`
   - Renommer les emails `*@monasso.fr` → `*@demo.fr`
   - Vérifier que `admin@demo.fr` a le rôle admin de l'asso (pas super-admin système)
   - Vérifier que `jean@demo.fr` a le rôle utilisateur standard

5. Peupler les données via l'UI selon la charte ci-dessous.

6. Capturer le snapshot :
   ```bash
   ./vendor/bin/sail artisan demo:capture
   ```
   Produit `database/demo/snapshot.yaml`.

   **La capture automatise désormais la copie des fichiers logo et signature :**
   si l'association a un `logo_path` ou `cachet_signature_path` défini et que les fichiers existent sur le disque, ils sont automatiquement copiés dans `database/demo/files/branding/{id}/` et référencés dans la section `files:` du YAML. Plus besoin d'éditer manuellement le YAML pour ces fichiers.

   Si un fichier référencé est absent (colonne renseignée mais fichier manquant), la capture émet un avertissement et continue sans bloquer.

7. Vérifier les fichiers copiés dans `database/demo/files/` (logo, cachet...). Si nécessaire, ajouter manuellement des PDF de démonstration supplémentaires (≤ 5 fichiers au total, total ≤ 1 Mo — factures, attestations, NDF exemples) et les référencer à la main dans `files:`.

   > **Note** : si une nouvelle colonne `*_path` est introduite dans le code et doit être capturée, l'ajouter à `SnapshotConfig::FILE_PATH_COLUMNS` dans `app/Support/Demo/SnapshotConfig.php`.

8. Committer le snapshot et les fichiers :
   ```bash
   git add database/demo/snapshot.yaml database/demo/files/
   git commit -m "chore(demo): initial snapshot"
   ```

9. Rétablir `.env` local vers la DB de développement habituelle.

---

## Charte snapshot

Un bon snapshot démo contient au minimum :

**Tiers**
- ≥ 25 particuliers (mix noms féminins/masculins, adresses dans plusieurs régions françaises)
- ≥ 5 entreprises ou associations locales (mix `pour_recettes` / `pour_depenses`)

**Facturation**
- Devis à différents statuts : brouillon, validé, accepté, refusé
- Factures à différents statuts : brouillon, validée, encaissée, avoir

**Comptabilité**
- Transactions étalées sur l'exercice courant (recettes + dépenses, plusieurs sous-catégories)
- Au moins 1 don, 1 cotisation
- Au moins 1 rapprochement bancaire partiellement complété
- Compte de résultat visiblement peuplé

**Autres modules**
- Au moins 1 attestation de présence, 1 NDF, 1 facture partenaire reçue
- Au moins 1 séance avec participants

**Règles impératives**
- 100 % données fictives — aucun nom/email/IBAN réel
- Aucun super-admin dans le snapshot (`role_systeme = 'user'` pour tous)
- Fichiers PDF de démo : watermark "EXEMPLE" recommandé

**Quand recapturer** : à chaque feature qui ajoute une nouvelle table ou un nouveau type de contenu démontrable (par exemple : nouvelle fonctionnalité, nouveau module).

---

## Commandes utiles (cheat-sheet)

| Commande | Où | Effet |
|---|---|---|
| `php artisan demo:capture` | Local (DB peuplée, `APP_ENV=demo`) | Produit `database/demo/snapshot.yaml` |
| `php artisan demo:reset` | Serveur démo (`APP_ENV=demo`) | Rejoue le snapshot, dates rehydratées |
| `php artisan demo:reset --snapshot=chemin/custom.yaml` | Serveur démo | Snapshot alternatif |
| `tail -f storage/logs/demo-reset.log` | Serveur démo | Suivi du cron |
| `php artisan up` | Serveur démo | Sortie du mode maintenance si bloqué |

> `demo:capture` refuse d'exécuter si `APP_ENV=production` ou si plusieurs associations existent dans la DB.
> `demo:reset` refuse d'exécuter si `APP_ENV != demo`.

---

## Recette manuelle post-déploiement

Effectuer cette checklist après chaque premier déploiement ou mise à jour majeure.

- [ ] `https://demo.agoragestion.org/login` accessible — certificat SSL valide
- [ ] Bandeau bleu "Démonstration en ligne" visible avec les deux comptes listés
- [ ] Connexion `admin@demo.fr / demo` OK — redirection vers le dashboard
- [ ] Connexion `jean@demo.fr / demo` OK — accès limité au rôle utilisateur
- [ ] Navigation dashboard, tiers, factures, devis, opérations : pas d'erreur 500
- [ ] Création d'un tiers OK (données sauvegardées)
- [ ] Validation d'une facture manuelle OK
- [ ] Envoi email (bouton "Envoyer") → flash affiche "Email enregistré (mode démo)", aucun email envoyé — vérifier `storage/logs/laravel.log` pour confirmer le log
- [ ] Écran Paramètres > SMTP : bandeau "lecture seule" visible, inputs `disabled`, bouton "Enregistrer" absent
- [ ] Écran Paramètres > HelloAsso : idem
- [ ] Suppression ou archivage d'asso (si super-admin présent) : action refusée avec message d'erreur
- [ ] Vérifier `storage/logs/demo-reset.log` au lendemain de 4h — ligne de succès présente, aucune exception

---

## Dépannage

**Le workflow `deploy-demo.yml` échoue au `demo:reset`**
Vérifier que `database/demo/snapshot.yaml` est présent dans le repo et que `.env.demo` est posé côté serveur.

**La DB est vide après déploiement**
Le snapshot n'existait pas encore lors du déploiement. Construire le snapshot (voir section ci-dessus) puis exécuter `php artisan demo:reset` manuellement côté serveur.

**Le cron ne s'exécute pas**
Vérifier dans cPanel > Tâches Cron que la tâche est bien enregistrée. Tester manuellement :
```bash
cd ~/public_html/demo.agoragestion.org && php artisan demo:reset
```

**L'application reste en mode maintenance**
`demo:reset` garantit `php artisan up` en `finally` — mais si le process a été tué, exécuter manuellement :
```bash
cd ~/public_html/demo.agoragestion.org && php artisan up
```
