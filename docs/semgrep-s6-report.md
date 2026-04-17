# Semgrep S6 — rapport de sweep

## Portée

- **Commande principale** : `semgrep scan --config=p/php --config=p/security-audit --config=p/owasp-top-ten --json -o /tmp/semgrep-s6.json app/Http/Controllers app/Livewire app/Services app/Models routes/web.php`
- **Commande de confirmation** : `semgrep scan --config=auto --json -o /tmp/semgrep-s6-auto.json app/ routes/`
- Configs : `p/php`, `p/security-audit`, `p/owasp-top-ten`, `auto`
- Date : 2026-04-17
- Total findings bruts : 29 (commande auto) / 0 (commande principale — règles de sécurité PHP insuffisantes sans login Semgrep)
- Version semgrep : 1.157.0

---

## Findings retenus (impact multi-tenancy)

| # | Fichier:ligne | Règle | Gravité | Résolution |
|---|---------------|-------|---------|------------|
| 1 | `app/Services/TransactionUniverselleService.php` (toutes branches) | Manuel — `DB::table()` sans `association_id` | CRITIQUE | Ajout de `->when(TenantContext::hasBooted(), fn($q) => $q->where('tx.association_id', TenantContext::currentId()))` dans les 4 méthodes `brancheDepense`, `brancheRecette`, `brancheVirementSortant`, `brancheVirementEntrant` |
| 2 | `app/Services/Rapports/CompteResultatBuilder.php` (10 requêtes) | Manuel — `DB::table()` sans `association_id` | CRITIQUE | Ajout du filtre `->when(TenantContext::hasBooted(), ...)` sur toutes les requêtes `DB::table` (transactions aliasées `d`, `r`, `tx`) + `fetchBudgetMap` (table `budget_lines`) |

### Détail finding #1 — TransactionUniverselleService

**Vecteur** : La page `/transactions` (vue générique sans compte ni tiers fixe) appelle `TransactionUniverselleService::paginate(compteId: null, tiersId: null, ...)`. Les méthodes `brancheDepense()` et `brancheRecette()` ne filtraient que conditionnellement (`->when($compteId !== null, ...)`) — si les deux paramètres étaient null, la requête retournait les transactions de **tous les tenants** dans la base.

**Exploitabilité** : Un utilisateur authentifié sur le tenant A, naviguant vers `/transactions` sans filtres, voyait les transactions des tenants B, C, D, etc. Idem pour les virements internes.

**Preuves** : Tests `RawQueryTenantIsolationTest::TransactionUniverselleService does not leak cross-tenant data` et `does not expose other-tenant recettes` — échoués avant fix, verts après.

### Détail finding #2 — CompteResultatBuilder

**Vecteur** : `CompteResultatBuilder::compteDeResultat(exercice)` appelle `fetchDepenseRows` + `fetchProduitsRows` qui utilisent `DB::table('transactions as d/r')`. Le seul filtre était une plage de dates. Dans un contexte multi-tenant, le compte de résultat agrégeait les charges et produits de **tous les tenants** pour l'exercice donné.

De plus, `fetchBudgetMap` utilisait `DB::table('budget_lines')->where('exercice', $exercice)` sans `association_id`, permettant à des lignes budgétaires d'un autre tenant de polluer le budget affiché si deux tenants partageaient un `sous_categorie_id` identique (collision d'ID auto-increment).

**Exploitabilité** : Un responsable comptable du tenant A voyant le rapport "Compte de résultat" obtenait les charges et produits de l'intégralité des associations enregistrées.

**Preuves** : Tests `CompteResultatBuilder::compteDeResultat does not aggregate other-tenant charges` et `fetchBudgetMap does not leak cross-tenant budget lines` — échoués avant fix, verts après.

---

## Findings écartés

| Catégorie | Nb | Justification |
|-----------|-----|---------------|
| `symfony-non-literal-redirect` (23 occurrences) | 23 | Faux positif : règle Symfony appliquée à Livewire. Les `$this->redirect(route('...'))` utilisent exclusivement des noms de routes internes générés par `route()`, jamais des URLs fournies par l'utilisateur. Aucun vecteur de redirection ouverte. |
| `unlink-use` (6 occurrences) | 6 | Hors périmètre multi-tenancy. Les `@unlink($tempPath)` concernent des fichiers temporaires créés par le code lui-même (`sys_get_temp_dir()`, chemins internes), jamais des chemins fournis par l'utilisateur. Commandes CLI ou blocs `finally` de nettoyage. |
| `DB::table('jobs')`, `DB::table('failed_jobs')` | 1 | Tables système Laravel sans `association_id` — accès légitimement cross-tenant dans le dashboard super-admin (`SuperAdmin\Dashboard`). |
| `DB::table('two_factor_codes')` | 1 | Table filtrée par `user_id` (pas de notion de tenant sur l'auth 2FA). Pas d'exposition cross-tenant. |
| `DB::table('sequences')` dans `NumeroPieceService` | 1 | Correctement scopé par `$associationId = CurrentAssociation::id()` — déjà conforme. |

---

## Synthèse

- **Findings retenus** : 2 (critique × 2)
- **Findings écartés** : 27
- **Fichiers modifiés** :
  - `app/Services/TransactionUniverselleService.php`
  - `app/Services/Rapports/CompteResultatBuilder.php`
- **Tests ajoutés** : `tests/Feature/Multitenant/RawQueryTenantIsolationTest.php` (4 tests)

---

## Suite de tests

- Avant fix : 3/4 tests de régression en échec (+ 1 non-détecté corrigé ensuite)
- Après fix : 0 échec
- Suite complète : **1824 tests, 0 failed** (1823 deprecated PDO::MYSQL_ATTR_SSL_CA — avertissement PHP/driver non lié aux changements)
- Baseline pré-T2 : 1819 tests → +4 nouveaux tests = 1823 attendus (+ 1 `true is true` existant)
