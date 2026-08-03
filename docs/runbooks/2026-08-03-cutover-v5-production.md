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

```bash
ssh o2switch 'cd ~/public_html/compta.soigner-vivre-sourire.fr \
  && eval $(grep -E "^DB_(HOST|DATABASE|USERNAME|PASSWORD)=" .env | sed "s/^/export /") \
  && mkdir -p ~/backups \
  && mysqldump --single-transaction --skip-lock-tables -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
     | gzip > ~/backups/pre-v5-$(date +%Y%m%d-%H%M).sql.gz \
  && ls -lh ~/backups/ | tail -3'
```

Vérifier que le fichier fait une taille plausible (non nul, quelques Mo) **avant** de continuer.

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

```bash
ssh o2switch 'cd ~/public_html/compta.soigner-vivre-sourire.fr \
  && eval $(grep -E "^DB_(HOST|DATABASE|USERNAME|PASSWORD)=" .env | sed "s/^/export /") \
  && gunzip < ~/backups/pre-v5-AAAAMMJJ-HHMM.sql.gz \
     | mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"'
```

Puis remettre le code : `git revert` du commit de merge, ou `git reset --hard` sur le commit précédent suivi d'un push, ce qui redéclenche `deploy.sh`.

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
