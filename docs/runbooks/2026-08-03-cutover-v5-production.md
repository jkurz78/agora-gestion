# Runbook — Bascule comptabilité V5 en production

| Champ | Valeur |
|---|---|
| Cible | O2Switch — `compta.soigner-vivre-sourire.fr` |
| Origine | Répétition générale sur la préprod NAS, 2026-08-03 |
| Durée observée | ~7 min de migrations, ~10 min pour la séquence complète |
| Fenêtre de maintenance | **Aucune** — décision de l'exploitant, seul utilisateur pendant la bascule |
| Réversibilité | Restauration de sauvegarde uniquement (voir § 7) |

Toutes les durées et tous les chiffres de ce runbook viennent de la répétition du 2026-08-03 sur un clone frais des données de production. Ils ne sont pas estimés.

## 1. Ce que la bascule change

La comptabilité passe en partie double inconditionnelle. Trois séparations apparaissent, qui n'existaient pas en V4 :

- ce qui est **dû** (401 fournisseurs, 411 clients) cesse d'être confondu avec ce qui est payé ;
- ce qui est **en main** (5112 chèques reçus, 530 espèces) cesse d'être confondu avec ce qui est en banque ;
- ce qui est **en banque** (512X) ne bouge qu'au mouvement bancaire réel.

**Conséquence visible dès la première minute** : le solde du compte courant affiché au tableau de bord passe de **1 411,88 € à 1 431,88 €**. Ce n'est pas une anomalie. Décomposition vérifiée sur la préprod :

| Poste | Effet |
|---|---|
| Chèques reçus non encore remis (5112) | **−160,00 €** — v4 les portait en banque dès la réception |
| Facture Kaligrafik FA000696, enregistrée non payée (401) | **+180,00 €** — v4 la déduisait de la banque dès la saisie |
| | **+20,00 €** |

Le chiffre V5 est celui du relevé bancaire. Le chiffre V4 mélangeait engagement et trésorerie.

## 2. Pré-requis

- [ ] Aucun autre utilisateur connecté pendant la fenêtre (condition retenue à la place de la maintenance).
- [ ] Sauvegarde de la base **et** des fichiers effectuée à l'instant (§ 3).
- [ ] La suite de tests passe en local (`php -d memory_limit=1G ./vendor/bin/pest`).
- [ ] `git status` propre sur `feat/compta-v5`.
- [ ] Les soldes d'ouverture des comptes bancaires sont connus **et leur date vérifiée** : c'est elle qui décide de l'exercice ouvert par la reprise (§ 5).

## 3. Sauvegarde — obligatoire, non négociable

Le rollback documenté par un retour de code est **périmé** : depuis la dissolution des sous-catégories, revenir en arrière suppose de restaurer la base. Cette sauvegarde est la seule sortie de secours.

Elle a deux volets — la base **et** les fichiers — parce que le déploiement écrase le répertoire applicatif (dont `.env` et `vendor/`, qui ne sont pas dans git).

Les deux archives vont dans `~/backups`, **hors de `public_html`** : le répertoire n'est donc pas servi par le web, et un déploiement raté ne peut pas l'effacer.

### 3.1 Base de données

```bash
ssh o2switch 'cd ~/public_html/compta.soigner-vivre-sourire.fr \
  && eval $(grep -E "^DB_(HOST|DATABASE|USERNAME|PASSWORD)=" .env | sed "s/^/export /") \
  && mkdir -p ~/backups && chmod 700 ~/backups \
  && mysqldump --single-transaction --skip-lock-tables --routines --triggers \
       --default-character-set=utf8mb4 \
       -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
     | gzip -9 > ~/backups/pre-v5-$(date +%Y%m%d-%H%M).sql.gz \
  && ls -lh ~/backups/'
```

`--single-transaction` évite de verrouiller les tables : l'application reste disponible pendant le dump (~2 s sur les volumes actuels).

### 3.2 Fichiers de l'application

```bash
ssh o2switch 'cd ~/public_html \
  && tar --warning=no-file-changed \
       --exclude="storage/framework/cache/*" \
       --exclude="storage/framework/sessions/*" \
       --exclude="storage/framework/views/*" \
       -czf ~/backups/pre-v5-fichiers-$(date +%Y%m%d-%H%M).tar.gz \
       compta.soigner-vivre-sourire.fr \
  && ls -lh ~/backups/'
```

Les trois exclusions ne portent que sur des caches régénérés seuls ; les restaurer ferait revenir des sessions périmées. Tout le reste est dedans : `.env`, `vendor/`, `.git/`, les documents de `storage/app/private/associations/…` et le lien symbolique `public/storage`.

### 3.3 Vérifier avant d'aller plus loin

Une archive non vérifiée n'est pas une sauvegarde. Les quatre contrôles, tous passés le 2026-08-03 :

```bash
ssh o2switch 'cd ~/backups
  gzip -t pre-v5-*.gz && echo "gzip OK"
  gunzip -c pre-v5-AAAAMMJJ-HHMM.sql.gz | tail -1          # doit finir par "Dump completed"
  gunzip -c pre-v5-AAAAMMJJ-HHMM.sql.gz | grep -c "^CREATE TABLE"   # doit valoir le nb de tables
  tar -tzf pre-v5-fichiers-AAAAMMJJ-HHMM.tar.gz "compta.soigner-vivre-sourire.fr/.env"'
```

Le nombre de `CREATE TABLE` se compare à la base vivante :

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '<DB>';
```

**La preuve qui compte réellement est une restauration d'essai**, pas une taille de fichier. Le dump se recharge dans une base jetable locale, et les compteurs doivent coïncider avec la prod :

```bash
docker compose exec -T mysql mysql -uroot -ppassword -e "CREATE DATABASE restore_test_v4 CHARACTER SET utf8mb4;"
gunzip -c pre-v5-AAAAMMJJ-HHMM.sql.gz | docker compose exec -T mysql mysql -uroot -ppassword restore_test_v4
```

⚠️ Jamais dans `svs_accounting` — c'est un clone de prod servant à autre chose.

### 3.4 Copie hors-site

Rapatrier les deux archives et comparer les empreintes, pour ne pas dépendre du seul compte O2Switch :

```bash
mkdir -p ~/Desktop/backup-v5-AAAAMMJJ && cd ~/Desktop/backup-v5-AAAAMMJJ
scp o2switch:~/backups/pre-v5-*.gz .
ssh o2switch 'cd ~/backups && sha256sum pre-v5-*.gz'
shasum -a 256 *.gz          # les empreintes doivent être identiques
```

### 3.5 Sauvegarde du 2026-08-03 — état vérifié

| Élément | Valeur |
|---|---|
| `pre-v5-20260803-1654.sql.gz` | 1,8 Mo — 81 tables, `Dump completed` |
| `pre-v5-fichiers-20260803-1654.tar.gz` | 82 Mo — 15 190 entrées, 133 fichiers de `storage/app` |
| Restauration d'essai | ✅ 81 tables ; transactions 192, lignes 323, tiers 78, règlements 88 — identiques à la prod |
| Copie hors-site | `~/Desktop/backup-v5-20260803/`, SHA-256 identiques |
| HEAD git prod au moment du dump | `29bb0381` (v4.4.3) |

⚠️ Cette sauvegarde vaut pour l'état du **2026-08-03 à 16 h 54**. Si la bascule est reportée et que des saisies ont lieu entre-temps, **la refaire** : restaurer celle-ci perdrait tout ce qui a été saisi depuis.

## 4. Déploiement

⚠️ **Le push est le déploiement.** Pousser `main` sur `origin` déclenche GitHub Actions, qui lance `~/bin/deploy.sh` sur O2Switch. Il n'y a pas d'étape « pour voir ».

```bash
git checkout main
git merge --no-ff feat/compta-v5 -m "feat(compta): bascule en comptabilité partie double (V5)"
git push origin main
```

Le `--no-ff` est délibéré : `main` étant un ancêtre de `feat/compta-v5`, le merge serait sinon une avance rapide silencieuse. Un commit de merge laisse une trace explicite de la bascule dans l'historique.

`deploy.sh` enchaîne alors : `git pull`, `composer install --no-dev`, `optimize:clear`, **`migrate --force`** (les 28 migrations, ~7 min sur matériel comparable), `storage:link`, `app:version-stamp`, les caches, puis `compta:check-integrity` en alerte non bloquante.

Suivre le déroulé :

```bash
ssh o2switch 'tail -f ~/public_html/compta.soigner-vivre-sourire.fr/deploy.log'
```

Un courriel de succès ou d'échec part en fin de script. **Ne pas enchaîner sur le § 5 avant d'avoir vu la ligne « Déploiement terminé avec succès ».**

## 5. Commandes post-déploiement

`deploy.sh` ne les connaît pas. Elles sont obligatoires et doivent être passées dans cet ordre.

```bash
ssh o2switch
cd ~/public_html/compta.soigner-vivre-sourire.fr
PHP=/usr/local/bin/php

# 1. Conversion de l'existant en partie double, tous exercices.
$PHP artisan compta:backfill-partie-double --all --dry-run   # audit
$PHP artisan compta:backfill-partie-double --all

# 2. Chèques de reprise encaissés avant AgoraGestion (idempotent, souvent 0).
$PHP artisan compta:corriger-cheques-reportes --dry-run
$PHP artisan compta:corriger-cheques-reportes

# 3. Statuts de règlement — indispensable APRÈS le backfill.
#    La migration de reclassement s'exécute au `migrate`, donc avant que les
#    lignes existent : elle est un no-op sur toute la population historique.
$PHP artisan compta:reconcilier-statuts --check
$PHP artisan compta:reconcilier-statuts

# 4. Contrôles.
$PHP artisan compta:smoke-test-v5
$PHP artisan compta:check-integrity
$PHP artisan compta:assert-pd-complete --check
$PHP artisan compta:reconcilier-statuts --check
```

Attendu, mesuré sur les données de production clonées : quelques transactions converties sur l'exercice antérieur, ~183 sur l'exercice courant, **1 transaction HelloAsso non enrichie** (information, pas erreur), les remises et virements internes repris, 2 divergences de statut corrigées, puis les trois gates vertes.

**Ne pas poursuivre si une gate sort en erreur.** Les causes sont nommées dans leur message.

## 6. Reprise des soldes et clôture — dans l'interface

La reprise initiale se fait seule ; aucune commande n'est nécessaire dans le cas nominal.

1. **Paramètres → Comptes bancaires.** Vérifier la colonne **N° comptable** : chaque compte doit porter un 512X. Un badge « absent » signale un compte sans écriture possible — s'arrêter et traiter avant d'aller plus loin.
2. **Corriger la date du solde d'ouverture du premier compte** (au 31/08/2024 si ce sont les positions de fin d'exercice 2023-2024). Enregistrer. **Rien ne doit se produire** : tant que les comptes ne désignent pas tous le même exercice, la reprise se diffère volontairement.
3. **Corriger la date du second compte.** Enregistrer. **La reprise part alors seule**, sur l'exercice que ces soldes ouvrent réellement.
4. **Comptabilité → Clôture.** La garde « Préalables comptables » doit être verte. Clôturer l'exercice.

Si la reprise ne part pas au second enregistrement, la cause est journalisée (`[Reprise]` dans `laravel.log`) : soit des mouvements existent à la date du solde de référence — seul cas qui exige encore `compta:bootstrap-an --meme-jour=inclus|exclus` —, soit l'exercice visé n'existe pas.

## 7. Vérification finale

Une sonde HTTP ne prouve rien : lors de la répétition, la page de connexion a répondu 200 pendant les six minutes où l'application était en réalité cassée, parce qu'elle n'écrit aucune ligne de journal.

Ce qui a valeur de preuve :

- [ ] les trois gates du § 5 sortent vertes ;
- [ ] **une action authentifiée qui écrit** aboutit — enregistrer une fiche, créer un règlement ;
- [ ] le solde du tableau de bord vaut ce qu'annonce le § 1 ;
- [ ] un rapport comptable de l'exercice courant s'affiche sans erreur ;
- [ ] `laravel.log` ne contient aucune trace de `DecryptException` ni d'erreur d'écriture.

## 8. Retour arrière

Il n'existe **pas** de retour arrière par le code. `COMPTA_USE_PARTIE_DOUBLE` a été supprimé, et les tables `sous_categories` / `categories` sont droppées par les migrations : revenir à V4 exige de restaurer la sauvegarde du § 3.

**Tout ce qui a été saisi depuis la sauvegarde est perdu.** C'est la raison d'être de la condition « aucun autre utilisateur connecté » du § 2 : elle borne cette perte à ce que l'exploitant a fait lui-même.

**1. La base d'abord.** Le dump commence par des `DROP TABLE IF EXISTS`, mais seulement pour les 81 tables de V4 : les tables créées par les migrations V5 (`comptes`, `ecritures`…) survivraient. Vider le schéma d'abord évite de mélanger les deux générations.

```bash
ssh o2switch 'cd ~/public_html/compta.soigner-vivre-sourire.fr \
  && eval $(grep -E "^DB_(HOST|DATABASE|USERNAME|PASSWORD)=" .env | sed "s/^/export /") \
  && mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" -N -B \
       -e "SET FOREIGN_KEY_CHECKS=0; SELECT CONCAT(\"DROP TABLE IF EXISTS \`\",table_name,\"\`;\") FROM information_schema.tables WHERE table_schema=\"$DB_DATABASE\";" \
     | mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  && gunzip < ~/backups/pre-v5-AAAAMMJJ-HHMM.sql.gz \
     | mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"'
```

**2. Puis les fichiers.** L'archive du § 3.2 rend `.env` et `vendor/` dans leur état V4, sans dépendre de composer ni du réseau. Déplacer le répertoire V5 plutôt que l'écraser, pour garder de quoi faire l'autopsie.

```bash
ssh o2switch 'cd ~/public_html \
  && mv compta.soigner-vivre-sourire.fr compta-v5-echec-$(date +%Y%m%d-%H%M) \
  && tar -xzf ~/backups/pre-v5-fichiers-AAAAMMJJ-HHMM.tar.gz \
  && cd compta.soigner-vivre-sourire.fr \
  && /usr/local/bin/php artisan optimize:clear'
```

**3. Enfin le dépôt**, pour que le prochain déploiement ne réapplique pas la V5 : `git revert -m 1` du commit de merge sur `main`, puis push — ce qui redéclenche `deploy.sh` sur du code V4. Un `git reset --hard` + `push --force` marche aussi mais réécrit l'historique de `main`.

**4. Vérifier** comme au § 7 : une action authentifiée qui écrit, et le solde du tableau de bord revenu à 1 411,88 €.

## 9. Ce que la répétition a corrigé avant la bascule

À conserver : ces défauts n'auraient été visibles qu'en production.

| Défaut | Effet évité |
|---|---|
| `artisan down --message` — option supprimée en Laravel 11 | Fenêtre de maintenance inopérante (sans objet ici, aucune n'est posée) |
| `docker compose exec` lance artisan en root | Journal non inscriptible, application cassée après déploiement — **propre à la préprod conteneurisée**, sans objet sur O2Switch où artisan tourne sous l'utilisateur de PHP |
| Scripts de clone appelant `ssh` + `mysql` + `php` nus | Aucune machine ne dispose des trois ; le clone préprod n'avait jamais fonctionné |
| `SELECT … FROM associations` (table au singulier) | Clone avorté juste après les migrations |
| Ligne `smtp_parametres` importée, active et vidée | Plus aucun courriel — donc plus de code 2FA, donc plus de connexion |

## 10. Références

- [Runbook reprise initiale](2026-07-22-reprise-initiale-a-nouveaux.md)
- [Gardes du parcours comptable](../specs/2026-07-30-gardes-parcours-comptable.md)
- [Cutover et rollback](../compta-partie-double.md) § 8
