# Runbook — Migration stockage S2 : isolation par tenant (SVS)

| Champ             | Valeur                                              |
|-------------------|-----------------------------------------------------|
| Date prévue       | Dimanche 26 avril 2026, 8h00–9h00 (heure française) |
| Durée estimée     | 10–15 min                                           |
| Niveau de risque  | Moyen — fichiers déplacés, rollback possible        |
| Responsable       | Jurgen (seul admin O2Switch)                        |
| Rollback plan     | Section 6                                           |

---

## 1. Pré-requis (à valider avant la fenêtre)

- [ ] Branch `feat/multi-tenancy-s1` (ou S2) mergée sur `main` et poussée en prod.
  > Note : si S2 est livrée avant que S3 soit en PR, le merge sur `main` peut être fait à ce stade. Vérifier que le HEAD de prod correspond bien au tag de release S2.
- [ ] Tag GitHub créé pour la release S2 (convention projet : tag après chaque push prod).
- [ ] Backup DB horodaté :
  ```bash
  mysqldump -u <user> -p <dbname> > ~/backups/svs-pre-s2-$(date +%Y%m%d-%H%M).sql
  ```
- [ ] Backup `storage/app/` horodaté :
  ```bash
  tar -czf ~/backups/storage-pre-s2-$(date +%Y%m%d-%H%M).tar.gz storage/app/
  ```
- [ ] Dry-run validé en staging (NAS) :
  ```bash
  php artisan tenant:migrate-storage --dry-run --association=1
  ```
  Aucune erreur, tous les fichiers listés trouvés.

---

## 2. Fenêtre de maintenance

- **Horaire :** dimanche 8h00–9h00 (heure française)
- **Canal :** application interne — aucune notification utilisateur requise.
  Si un utilisateur est connecté au moment de `artisan down`, noter l'événement dans le changelog de déploiement.

---

## 3. Étapes de déploiement

### 3.1 Mise en maintenance
```bash
php artisan down --message "Maintenance : isolation du stockage (10 min)"
```

### 3.2 Mise à jour du code
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
```

### 3.3 Migrations Laravel (backfill DB)
```bash
php artisan migrate --force
```
Exécute les 9 migrations de backfill S2 (Tasks 4–11). Voir la liste exhaustive en [Annexe A](#annexe-a--migrations-s2).

### 3.4 Validation DB post-migration
```bash
php artisan tinker
```
Vérifications spot :
```php
Association::first()->logo_path         // attendu : "logo.png" (pas "association/logo.png")
TypeOperation::first()->logo_path       // attendu : nom court sans préfixe
ParticipantDocument::first()->storage_path  // attendu : nom court
```
Exécuter la requête de contrôle complète ([Annexe B](#annexe-b--requête-de-vérification-post-migration)).
Tous les compteurs doivent retourner `n = 0`.

### 3.5 Migration physique des fichiers
```bash
php artisan tenant:migrate-storage --association=1 --force
```
Déplace les fichiers vers `storage/app/private/associations/1/...`.

### 3.6 Validation fichiers
```bash
ls -la storage/app/private/associations/1/branding/
ls -la storage/app/private/associations/1/documents/
ls -la storage/app/private/associations/1/pdfs/
```
Les fichiers attendus doivent être présents. Vérifier 2–3 sous-dossiers supplémentaires selon ce que le dry-run avait listé.

### 3.7 Nettoyage du cache
```bash
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 3.8 Remise en ligne
```bash
php artisan up
```

### 3.9 Smoke test (à faire immédiatement après `up`)

1. Login admin (`admin@monasso.fr`) — vérifier que la page dashboard s'affiche.
2. Générer une attestation de présence PDF — valide le flux logo + génération PDF.
3. Ouvrir une facture existante — valide `DocumentPrevisionnel` (logo + fichier PDF).
4. Ouvrir la fiche d'un participant avec documents — valide `ParticipantDocument`.
5. Envoyer un email de test à soi-même — vérifier que le logo CID s'affiche dans le client mail.

### 3.10 Surveillance 1h post-déploiement
```bash
tail -f storage/logs/laravel.log
```
Surveiller les occurrences de : `FileNotFoundException`, `UnableToReadFile`, `Path traversal interdit`, `404`.
Vérifier aussi les error logs O2Switch.

---

## 4. Action J+2 (mardi 28 avril)

Supprimer les anciens dossiers legacy (les fichiers ont été copiés, pas déplacés, pendant les 48h de fallback) :

```bash
# Vérifier d'abord que rien ne pointe encore vers ces chemins dans les logs
grep -r "storage/app/public/association" storage/logs/laravel.log | tail -20

# Suppression ciblée — NE PAS faire rm -rf storage/app/public/ en entier
rm -rf storage/app/public/association
rm -rf storage/app/public/type-operations
# Ajouter ici tout autre sous-dossier legacy identifié lors du dry-run
```

> Ne pas supprimer `storage/app/public/` intégralement : peut contenir `htmlpurifier`, `temp`, ou d'autres fichiers non concernés par S2.

---

## 5. Checklist post-déploiement

- [ ] Smoke test passé (étape 3.9)
- [ ] Aucune erreur dans les logs 1h après (étape 3.10)
- [ ] Version bump dans `config/version.php` (si pas déjà fait dans le PR de release)
- [ ] Tag GitHub + release notes publiés
- [ ] `MEMORY.md` mis à jour : noter la mise en prod S2
- [ ] Action J+2 planifiée (suppression dossiers legacy)

---

## 6. Plan de rollback

### Cas 1 — Migration Laravel échouée

La DB est dans un état inconsistant. Revenir en arrière :

```bash
php artisan migrate:rollback --step=9
# 9 = nombre de migrations S2 backfill (voir Annexe A)
```

Puis restaurer le backup DB si nécessaire :

```bash
mysql -u <user> -p <dbname> < ~/backups/svs-pre-s2-<timestamp>.sql
```

### Cas 2 — `tenant:migrate-storage` a laissé des fichiers dans un mauvais état

```bash
php artisan tenant:migrate-storage --association=1 --reverse --force
```

Puis revert des commits S2 et redéploiement depuis le tag précédent :

```bash
git revert <hash-commit-S2>
git push origin main
# redéployer sur O2Switch
```

### Cas 3 — Régression fonctionnelle (logo cassé, PDF vide, 404 sur documents)

Durée estimée : ~15 min.

```bash
php artisan down
# Restaurer le backup DB
mysql -u <user> -p <dbname> < ~/backups/svs-pre-s2-<timestamp>.sql
# Restaurer storage/app/
cd /path/to/app
tar -xzf ~/backups/storage-pre-s2-<timestamp>.tar.gz
# Revert code
git revert <hash-commit-S2> && git push origin main
# redéployer
php artisan up
```

---

## 7. Surveillance 48h post-déploiement

| Quoi surveiller | Signal d'alerte |
|---|---|
| Logs Laravel | `FileNotFoundException`, `UnableToReadFile`, `Path traversal interdit` |
| Visuels utilisateur | Logos manquants, PDFs cassés, photos tiers absentes |
| Emails sortants | Logo CID absent dans les clients mail (tester avec un envoi à soi-même) |
| Volumétrie storage | `du -sh storage/app/private/associations/1/` doit correspondre à la somme de l'ancien `public/` + sous-dossiers de l'ancien `private/` |

---

## Annexe A — Migrations S2 (backfill)

| Fichier | Objet |
|---|---|
| `2026_04_17_100000_backfill_association_logo_paths.php` | Noms courts logo association |
| `2026_04_17_100010_backfill_type_operation_paths.php` | Noms courts logo type d'opération |
| `2026_04_17_100015_add_association_id_to_participant_documents.php` | Ajout colonne `association_id` + backfill |
| `2026_04_17_100020_backfill_participant_document_paths.php` | Noms courts documents participants |
| `2026_04_17_100030_backfill_incoming_document_paths.php` | Noms courts documents entrants |
| `2026_04_17_100040_backfill_seance_feuille_paths.php` | Noms courts feuilles signées séances |
| `2026_04_17_100050_backfill_transaction_piece_jointe_paths.php` | Noms courts pièces jointes transactions |
| `2026_04_17_100060_backfill_document_previsionnel_paths.php` | Noms courts PDFs documents prévisionnels |
| `2026_04_17_100070_backfill_provision_piece_jointe_paths.php` | Noms courts pièces jointes provisions |

---

## Annexe B — Requête de vérification post-migration

À exécuter via `php artisan tinker` ou directement en SQL. Tous les résultats doivent être `n = 0`.

```sql
SELECT 'association'             AS t, COUNT(*) AS n FROM association            WHERE logo_path          LIKE '%/%'
UNION ALL
SELECT 'type_operations',                            COUNT(*)    FROM type_operations         WHERE logo_path          LIKE '%/%'
UNION ALL
SELECT 'participant_documents',                      COUNT(*)    FROM participant_documents   WHERE storage_path       LIKE '%/%'
UNION ALL
SELECT 'incoming_documents',                         COUNT(*)    FROM incoming_documents      WHERE storage_path       LIKE '%/%'
UNION ALL
SELECT 'seances',                                    COUNT(*)    FROM seances                 WHERE feuille_signee_path LIKE '%/%'
UNION ALL
SELECT 'transactions',                               COUNT(*)    FROM transactions            WHERE piece_jointe_path  LIKE '%/%'
UNION ALL
SELECT 'documents_previsionnels',                    COUNT(*)    FROM documents_previsionnels WHERE pdf_path           LIKE '%/%'
UNION ALL
SELECT 'provisions',                                 COUNT(*)    FROM provisions              WHERE piece_jointe_path  LIKE '%/%';
```

Résultat attendu : 8 lignes, toutes avec `n = 0`.
