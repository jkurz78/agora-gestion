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

- [ ] Migration unique `drop_sous_categories_legacy` :
  1. drop FK + colonne `sous_categorie_id` sur les 11 tables (transaction_lignes,
     budget_lines, facture_lignes, devis_lignes, formules_adhesion, type_operations,
     helloasso_form_mappings, provisions, usages_sous_categories, notes_de_frais_lignes,
     encadrement_previsions),
  2. drop FK + colonne `comptes.categorie_id`,
  3. drop tables `sous_categories` puis `categories`.
  `down()` : recréation structurelle best-effort (données non restaurées — documenter).
- [ ] Renommer la table `usages_sous_categories` → `usages_comptes` ? NON — hors périmètre,
  noter en dette (rename de table = churn migrations/modèle sans gain fonctionnel).
- [ ] `./vendor/bin/sail artisan migrate` sur la base dev (clone prod) — vérifier le
  backfill DC-2 déjà joué n'est pas rejoué, la migration de drop passe.
- [ ] AC1 : `grep -rn 'sous_categorie\|SousCategorie\|Categorie\b' app/ resources/views/`
  → zéro (hors `usages_sous_categories` nom de table et migrations historiques).
- [ ] Pint + suite complète + `compta:smoke-test-v5`. Commit final.

---

**Après DC-10b :** plan de recette localhost (re-clone prod + rejeu backfill complet +
smoke test), cf. mémoire projet.
