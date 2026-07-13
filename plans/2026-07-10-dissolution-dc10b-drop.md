# DC-10b — Drop final `sous_categories`/`categories` (dissolution, slice 5)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.
> Exécution séquentielle : chaque étape laisse la suite complète verte avant la suivante.

**Goal :** supprimer physiquement `sous_categories`, `categories`, les 11 colonnes miroir
`sous_categorie_id`, `comptes.categorie_id`, et tout le code-pont — l'app ne connaît plus
que `comptes` + `familles`.

**Pré-état (2026-07-10) :** DC-1 → DC-10a terminés, suite 14 482 assertions / 0 failed
(commits `35b796cd`…`357b18be`). L'app est compte-first partout ; les colonnes miroir ne
sont plus lues, seulement entretenues par le trait `SyncCompteDepuisSousCategorie` et les
deux observers miroir.

**Contraintes :**
- ⛔ Jamais de `migrate:fresh` (DB dev = clone prod). Jamais de push origin.
- Les backfillers (`CompteIdBackfiller`, `VentilationCompteIdBackfiller`, `FamillesSeeder`)
  rejouent au cutover prod AVANT la migration de drop → ils doivent rester autonomes
  (DB::table uniquement, aucun modèle supprimé). Les vérifier, pas les supprimer.
- `AuditGuard` + son appel dans la migration `create_comptes` : l'appel dans une migration
  déjà jouée en dev mais PAS encore en prod → la migration doit rester rejouable au cutover.
  Ne supprimer AuditGuard que si la migration create_comptes est rendue autonome (inline)
  ou si AuditGuard survit en `Services/Compta/Migrations/` (choix : le garder, c'est du
  code de migration, pas du code-pont).

---

## DC-10b-1 — CompteFactory + conversion des fixtures de tests (le gros volume)

Les ponts restent ACTIFS pendant cette étape : créer un `Compte` directement fonctionne
déjà, la suite reste verte lot par lot.

- [ ] Créer `database/factories/CompteFactory.php` + `HasFactory` sur `App\Models\Compte`
  (defaults : numero_pcg séquencé classe 7 — ex. `706`, `707`… —, intitule Faker fr,
  classe dérivée du premier chiffre, actif=true, est_systeme=false, lettrable=false ;
  states `->depense()` (classe 6, 6xx), `->recette()`, `->numero('754')`).
- [ ] Convertir les ~145 fichiers de tests qui utilisent `SousCategorie::factory` :
  remplacer le motif « SousCategorie::factory(code_cerfa X) → observer matérialise le
  Compte → `Compte::where(numero_pcg, X)->firstOrFail()` » par `Compte::factory()->numero('X')`
  (ou création directe). Lots de ~20 fichiers par sous-agent, suite ciblée verte par lot.
  Les tests qui vérifient LE PONT lui-même (SyncCompteDepuisSousCategorieTest,
  VentilationCompteIdBackfillTest côté trait, tests miroir CompteObserver/
  SousCategorieCompteObserver, tests factories pourDons/pourCotisations…) : NE PAS
  convertir — ils meurent en DC-10b-2 (les lister au passage).
- [ ] Suite complète verte. Commit par lot.

## DC-10b-2 — Couper les ponts d'écriture

- [ ] Retirer le trait `SyncCompteDepuisSousCategorie` des 10 modèles + supprimer le trait.
- [ ] `CompteObserver` : supprimer `materialiserSousCategorie()` (miroir retour DC-7).
- [ ] Supprimer `SousCategorieCompteObserver` + son enregistrement.
- [ ] `FormulesList::saveNewSousCat` → création directe de Compte (motif
  `UsagesComptablesService::createAndFlag`).
- [ ] Retirer `sous_categorie_id` des `$fillable`/casts des 10 modèles + `TransactionLigne`,
  supprimer les relations `sousCategorie()` restantes.
- [ ] Supprimer les tests du pont listés en 10b-1 ; adapter les tests des factories
  SousCategorie (states pourDons/pourCotisations → à porter sur CompteFactory si un
  test métier en dépend encore).
- [ ] Suite complète verte. Commit.

## DC-10b-3 — Purge code mort app

- [ ] Supprimer : modèles `SousCategorie` + `Categorie` + leurs factories,
  `SousCategorieList` + blade `sous-categorie-list.blade.php`,
  `CategorieController` + `SousCategorieController` + routes (`Route::resource('categories', …)`
  dans web.php), `CompteVentilationResolver`, vue `parametres/categories/index.blade.php`.
- [ ] `TransactionConverter` (Services/Compta) : lit `sousCategorieId` — vérifier s'il est
  encore appelé (probable code de migration 1b) ; s'il rejoue au cutover, le rendre
  autonome, sinon le supprimer.
- [ ] Purger les références résiduelles (imports, commentaires de transition, docblocks)
  dans les ~50 fichiers restants de l'inventaire
  (`scratchpad/dc10b-app-refs.txt` du 2026-07-10) : commandes (Audit, Backfill, Demo,
  Dump, FixProd, SmokeTest, Benchmark), services (EcritureGenerator, TransactionService,
  Cloture, RecuFiscal, Adhesion…), Livewire (HelloassoSyncWizard, FactureEdit,
  ProvisionIndex, TypeOperationShow…), blades (rapports PDF, cloture-wizard,
  select-compte-options…). Règle : si c'est un commentaire → reformuler compte-first ;
  si c'est du code → il doit déjà être mort (vérifier avant de supprimer).
- [ ] `comptes.categorie_id` : purger les usages code (PlanComptable, UsagesComptables
  createAndFlag, CompteAutocomplete confirmCreate, CompteObserver, onboarding
  DefaultChartOfAccountsService, PlanComptableSeeder) — la famille dérivée remplace.
- [ ] Suite complète verte. Commit.

## DC-10b-4 — Migration de drop + validation finale

- [x] Migration finale `2026_07_12_200001_drop_sous_categories_and_categories` :
  1. backfill fail-closed des rattachements encore résolubles, y compris les lignes
     soft-deleted et le compte de dons HelloAsso ;
  2. suppression des FK, index puis colonnes `sous_categorie_id` sur les 10 tables de
     ventilation et de `sous_categorie_don_id` ;
  3. remplacement de l'index transaction composite historique par l'index final
     `transaction_lignes_transaction_id_index` ;
  4. suppression de `comptes.categorie_id` ;
  5. transformation de `usages_sous_categories` en `usages_comptes`, avec
     `compte_id NOT NULL`, FK cascade, unique `(association_id, compte_id, usage)` et
     index finaux `usages_comptes_compte_id_index` et `(association_id, usage)`, sans
     nom d'index historique ;
  6. suppression des tables `sous_categories` puis `categories`.

  `down()` est volontairement irréversible : restaurer la sauvegarde.

- [x] Contrat de nullabilité confirmé : les 10 tables de ventilation conservent
  `compte_id` nullable et leur FK `ON DELETE SET NULL` (lignes texte, brouillons et
  mappings ignorés sont légitimes). Seul `usages_comptes.compte_id` est obligatoire et
  supprimé en cascade avec le compte.
- [x] Répétition MySQL sur une copie jetable du clone, jamais sur `svs_accounting` :
  dump `--single-transaction --skip-lock-tables`, compteurs source/copie identiques,
  zéro rattachement irrésoluble, zéro doublon d'usage et zéro doublon d'encadrement.
- [x] Les deux migrations du 12 juillet passent sur MySQL. `information_schema`
  confirme l'absence des tables et colonnes historiques, les FK/nullabilités attendues,
  les index finaux et l'unicité d'encadrement sur `compte_id`.
- [x] `compta:smoke-test-v5 --detail` et `compta:assert-pd-complete --check` passent.
  `compta:check-integrity` signale séparément 13 écarts de rapprochements déjà présents
  dans les données du clone ; la répétition reste saine (delta CR et rapprochements du
  smoke à 0, aucune transaction déséquilibrée ou sans partie double).
- [x] `database/schema/mysql-schema.sql` régénéré depuis le schéma final, sans `--prune`.
  Une seconde base MySQL vide charge ce dump puis répond `Nothing to migrate` et ne
  contient aucune table ou colonne historique.
- [x] Gates de clôture de la Task 6 :
  - AC1 : aucune référence au modèle ou au schéma comptable historique dans `app/`
    et `resources/views/` ;
  - dump final : aucune table, colonne ou index historique hors noms des migrations
    enregistrées ;
  - Pint : succès sur tous les fichiers PHP suivis hors `config/version.php`,
    modification utilisateur laissée intacte et hors commit ;
  - suite complète : 5 583 tests, 14 606 assertions, aucun échec avec
    `php -d memory_limit=1G ./vendor/bin/pest --compact` (la limite standard de 512 Mo
    est insuffisante à cause de l'accumulation des dépréciations PHP 8.5) ;
  - revue indépendante des deux correctifs MySQL et du dump : approuvée sans finding.

### Procédure de cutover reproductible

1. Sauvegarder la source avec `mysqldump --single-transaction --skip-lock-tables`.
2. Restaurer le dump dans une base `agora_dc10b_verify_*` et comparer les compteurs ainsi
   que les préflights avant toute migration.
3. Afficher et vérifier explicitement `DB_DATABASE` : refuser `svs_accounting`, n'exécuter
   `artisan migrate --force` que sur la copie jetable.
4. Contrôler `information_schema`, le smoke V5 et la complétude partie double.
5. Tester le dump de schéma sur une deuxième base vide et vérifier zéro migration pending.
6. Comparer à nouveau le statut des migrations de la source, puis supprimer bases et dump
   temporaires.

---

**Après DC-10b :** plan de recette localhost (re-clone prod + rejeu backfill complet +
smoke test), cf. mémoire projet.
