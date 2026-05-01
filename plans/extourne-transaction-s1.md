# Plan: Extourne de transaction — Slice 1 du programme Annulation de facture & Extourne

**Created**: 2026-05-01
**Branch**: `claude/funny-shamir-8661f9` (worktree, post-S0 — HEAD `a4cda304`)
**Status**: implemented
**Modèle de livraison** : pas de PR par slice — une seule évolution continue S0 → S1 → S2 sur la même branche worktree (utilisateur unique reviewer).
**Livraison** : 2026-05-01, +14 commits sur worktree `claude/funny-shamir-8661f9`, +84 tests Pest (231 assertions). Suite Feature 2840 tests / 0 fail. Pint vert. Prêt pour S2.
**Spec source**: [docs/specs/2026-04-30-extourne-transaction-s1.md](docs/specs/2026-04-30-extourne-transaction-s1.md) (PASS)
**Préalable** : Slice 0 livré ✅ ([plans/audit-signe-negatif-s0.md](plans/audit-signe-negatif-s0.md), 15 commits, suite verte). Slice 2 (annulation de facture) sera spécifié séparément après livraison de S1.

## Goal

Livrer la **primitive autonome d'extourne** (annulation comptable) d'une transaction recette dans AgoraGestion, plus le mécanisme de **lettrage** automatique pour les recettes non encaissées. Une extourne est une seconde `Transaction` recette à montant négatif, datée du jour de l'annulation, dans la même sous-catégorie / opération / séance / compte / tiers que l'origine, reliée à elle par une nouvelle table `extournes` (source de vérité 1:0|1) et un flag dénormalisé `transactions.extournee_at` (cache lecture, entretenu atomiquement). Si l'origine était `EnAttente` (jamais touché la banque), un `RapprochementBancaire` de **type `lettrage`** verrouillé est créé automatiquement, appariant origine et extourne (∑=0), et les deux passent au statut `Pointe` — ce qui les retire des Créances à recevoir. Si l'origine était `Recu` ou `Pointe`, l'extourne naît `EnAttente` et attend un pointage banque ordinaire ultérieur (matérialisation comptable du remboursement réel chèque/virement). UI : bouton "Annuler la transaction" sur la fiche transaction (Comptable + Admin uniquement) avec modale Bootstrap (date, libellé éditable `"Annulation - {origine}"`, mode de paiement modifiable, motif libre), badge "annulée" sur les listes, filtre `type` (Tous / Bancaire / Lettrage, default Bancaire) sur l'écran Rapprochements bancaires. Multi-tenant fail-closed sur la nouvelle table `Extourne` (étend `TenantModel`). Le slice débloque le cas métier "remboursement de règlement sans facture" (désistement participant typiquement) et constitue la brique réutilisée par le Slice 2 (annulation de facture par avoir, hors scope ici).

## Acceptance Criteria

- [ ] Suite Pest verte : tests existants (~2740 post-S0) + ~25-30 nouveaux tests S1, 0 fail
- [ ] Tous les 14 scénarios BDD §2 de la spec implémentés en tests Feature (mappage 1:1). **Cas spéciaux** : Scénario 3 ("extourne encaissée pointable banque ordinaire") couvert au Step 12 ; Scénario 12 ("saisie manuelle négatif refusée") déjà couvert au Slice 0 — référence explicite ajoutée au Step 9 vers `tests/Feature/Audit/MontantValidationTest.php`.
- [ ] Compte de résultat : exercice avec recette +80 € + extourne -80 € pointées affiche ∑ produits sous-cat = 0 € **ET** détail liste 2 entrées séparées avec montants +80 € et -80 € (assertion sur la collection retournée par `CompteResultatBuilder`)
- [ ] Flux trésorerie : extourne pointée à un rapprochement bancaire ordinaire affecte le solde — **delta exact testé** (compte solde initial 500 €, extourne -80 € pointée → solde final 420 €)
- [ ] Flux trésorerie : lettrage neutre (cas non-encaissé) — solde inchangé (delta = 0 €)
- [ ] Multi-tenant : aucune fuite — **2 tests** : (a) tx tenant B fetchée via `find()` depuis tenant A → null (scope global) ; (b) objet Transaction du tenant B injecté directement dans le service (bypass scope) → service détecte `association_id` mismatch et lève exception (ceinture-bretelles)
- [ ] Migrations réversibles : `migrate` → `migrate:rollback` → `migrate` cycle propre, aucune perte de données
- [ ] Backfill `rapprochements_bancaires.type` : tous les enregistrements pré-S1 à `bancaire` après migration
- [ ] UI : bouton "Annuler la transaction" affiché ssi `Transaction::isExtournable()` ET utilisateur a rôle Comptable ou Admin (test Livewire présence/absence)
- [ ] Modale Bootstrap (pas de `confirm()` natif) — conforme convention `wire:confirm` du projet
- [ ] Cohérence atomique flag `transactions.extournee_at` ↔ entrée `extournes` : test inscrit un `Event::listen(TransactionExtournee::class, fn() => throw)` qui force le rollback **depuis l'intérieur de la `DB::transaction`** ; asserter aucune Extourne, aucun flag set, aucun lettrage. Stratégie sans mock (compatible `final class`).
- [ ] Lettrages exclus de Créances à recevoir (transactions `Pointe` exclues naturellement)
- [ ] Extourne `EnAttente` (cas encaissé) exclue de Créances à recevoir (vérifier extension du filtre S0 `montant > 0` au cas extourne)
- [ ] Filtre `type` sur écran rapprochements fonctionnel : `Bancaire` (default), `Lettrage`, `Tous`
- [ ] Logging `LogContext` porte `association_id` + `user_id` + `transaction_origine_id` + `transaction_extourne_id` + `extourne_id` à chaque extourne
- [ ] PSR-12 / Pint vert (`./vendor/bin/sail pint --test`)
- [ ] `declare(strict_types=1)` + `final class` sur tous les nouveaux fichiers
- [ ] Pas de régression sur l'annulation actuelle de facture (suite `FactureAvoirTest` reste verte sans modification)
- [ ] Indexes utiles ajoutés : `transactions.extournee_at`, `extournes.transaction_origine_id` (UNIQUE), `extournes.transaction_extourne_id` (UNIQUE), `rapprochements_bancaires.type`
- [ ] Eager loading sur listes pour éviter N+1 sur le badge "annulée". **Breakdown attendu** : 1 query count + 1 query paginate + 1 query eager `extournePour` + 1 query eager `extourneeVers` = **4 queries**, threshold absolu sur 25 transactions extournées. Test asserte `count(DB::getQueryLog()) <= 5` (marge +1 pour total tenant filter).
- [ ] Policy `ExtournePolicy` : Gestionnaire et Consultation refusés, Comptable et Admin acceptés
- [ ] `/code-review --changed` passe sans bloqueur

## Steps

### Step 1: Foundation — 3 migrations + enum `TypeRapprochement` + cast

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/MigrationsExtourneTest.php` :
- Test `extournes_table_a_les_colonnes_attendues` : asserter présence colonnes (`id`, `transaction_origine_id`, `transaction_extourne_id`, `rapprochement_lettrage_id`, `association_id`, `created_by`, timestamps, `deleted_at`)
- Test `extournes_table_a_unique_sur_origine_et_extourne` : asserter contraintes UNIQUE
- Test `transactions_a_la_colonne_extournee_at_indexed` : asserter colonne + index
- Test `rapprochements_bancaires_a_la_colonne_type_avec_default_bancaire` : asserter colonne, default, index
- Test `backfill_rapprochements_existants_a_bancaire` : créer 3 RapprochementBancaire avant migration de la colonne, lancer migration, asserter `type === 'bancaire'` pour les 3
- Test `cycle_migrate_rollback_migrate_propre` : `Artisan::call('migrate:rollback', ['--step' => 3])` puis `migrate` → asserter pas d'erreur, structure restaurée
- Test `enum_TypeRapprochement_a_les_valeurs_attendues` : asserter `Bancaire`, `Lettrage`, méthode `label()`, helpers `isLettrage()` / `isBancaire()` sur le modèle

**GREEN**:
- Migrations :
  - `database/migrations/2026_05_01_120000_create_extournes_table.php`
  - `database/migrations/2026_05_01_120001_add_extournee_at_to_transactions.php`
  - `database/migrations/2026_05_01_120002_add_type_to_rapprochements_bancaires.php` avec backfill DB::statement dans `up()`
- Enum `app/Enums/TypeRapprochement.php` : 2 cases, `label()`
- Cast sur `RapprochementBancaire::$type` + helpers `isLettrage()` / `isBancaire()`
- Toutes migrations réversibles (méthode `down()`)

**REFACTOR**: None (foundation pure)
**Files**: 3 migrations, `app/Enums/TypeRapprochement.php`, `app/Models/RapprochementBancaire.php`, `tests/Feature/Extourne/MigrationsExtourneTest.php`
**Commit**: `feat(extourne): foundation — extournes table, transactions.extournee_at, rapprochements_bancaires.type enum`

### Step 2: Modèle `Extourne` + factory + scope multi-tenant

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/ExtourneModelTest.php` :
- Test `extourne_etend_TenantModel_avec_scope_global_fail_closed` : sans TenantContext booté → `Extourne::query()->get()` retourne vide ; avec TenantContext booté tenant A → seules les extournes de A retournées
- Test `relations_origine_extourne_lettrage` : crée Extourne, asserter `extourne->origine` (Transaction), `extourne->extourne` (Transaction miroir), `extourne->lettrage` (RapprochementBancaire ou null)
- Test `relation_creator` (User) : asserter relation
- Test `soft_delete_active` : `extourne->delete()` → trashed, `withTrashed()` retrouve
- Test `factory_par_defaut_cree_extourne_coherente` : asserter origine et extourne ont même tenant, montants opposés

**GREEN**:
- `app/Models/Extourne.php` : `final class extends TenantModel`, fillable, casts, relations `origine()`, `extourne()`, `lettrage()`, `creator()`, soft deletes
- `database/factories/ExtourneFactory.php` : crée transaction origine + extourne miroir cohérents
- Pas de logique métier dans le modèle — juste relations + structure

**REFACTOR**: None
**Files**: `app/Models/Extourne.php`, `database/factories/ExtourneFactory.php`, test
**Commit**: `feat(extourne): Extourne model with tenant-scoped relations and factory`

### Step 3: Guards `Transaction::isExtournable()` + attribute `estUneExtourne`

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/TransactionIsExtournableTest.php` couvrant tous les guards §3.4 :
- Test `recette_eligible_isExtournable_true` : transaction recette, statut `EnAttente`, sans facture, sans helloasso, non extournée → `true`
- Test `depense_isExtournable_false` : sens dépense → `false`
- Test `recette_deja_extournee_isExtournable_false` : `extournee_at` non null → `false`
- Test `recette_qui_est_elle_meme_extourne_isExtournable_false` : tx présente dans `extournes.transaction_extourne_id` → `false`. Vérifier que l'attribute `estUneExtourne` se calcule via `extournes` (eager loadable, pas de query N+1 si chargé)
- Test `recette_helloasso_isExtournable_false` : `helloasso_order_id` non null → `false`
- Test `recette_facture_validee_isExtournable_false` : pivot `facture_transaction` lié à facture statut `Validee` → `false`
- Test `recette_facture_brouillon_ou_annulee_isExtournable_true` : pivot lié à facture `Brouillon` ou `Annulee` → `true` (pas de blocage)
- Test `recette_soft_deleted_isExtournable_false` : `trashed()` → `false`

**GREEN**:
- `Transaction::isExtournable(): bool` enchaînant les 6 guards
- Attribute `estUneExtourne` : Eloquent attribute calculé via existence dans `extournes.transaction_extourne_id` (relation `extournePour()` → belongsTo via Extourne pivot) — eager loadable
- Cast `extournee_at` → datetime nullable (déjà fait par migration mais vérifier)
- Relation `extournePour()` (HasOne via Extourne) et `extourneeVers()` (HasOne via Extourne)

**REFACTOR**: Si redondance entre `extournePour` / `extourneeVers` et l'attribute, factoriser
**Files**: `app/Models/Transaction.php`, test
**Commit**: `feat(extourne): Transaction::isExtournable guards + estUneExtourne attribute`

### Step 4: DTO `ExtournePayload` + Policy `ExtournePolicy`

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/ExtournePayloadTest.php` + `tests/Feature/Extourne/ExtournePolicyTest.php` :
- Payload : test `defaults_from_origine` (date today, libellé `"Annulation - {origine.libelle}"`, mode_paiement = origine), test `override_libelle_et_mode_paiement_passent`, test validation date non future / cohérente
- Policy : tests pour les 4 rôles (`RoleAssociation::Admin` ✅, `Comptable` ✅, `Gestionnaire` ❌, `Consultation` ❌). Test super-admin (`RoleSysteme::SuperAdmin`) en mode support → refus en écriture (cohérent avec mode read-only S4)

**GREEN**:
- `app/DataTransferObjects/ExtournePayload.php` : `final class`, propriétés `date`, `libelle`, `mode_paiement`, `notes` (nullable), constructor + factory `fromOrigine(Transaction $tx, ?array $overrides = null): self`
- `app/Policies/ExtournePolicy.php` : `create(User, Transaction): bool` retourne `Comptable` ou `Admin` via `RoleAssociation` du user dans le tenant courant ; refuse super-admin support read-only
- Enregistrer la policy dans `AuthServiceProvider`

**REFACTOR**: None
**Files**: `app/DataTransferObjects/ExtournePayload.php`, `app/Policies/ExtournePolicy.php`, `app/Providers/AuthServiceProvider.php`, 2 tests
**Commit**: `feat(extourne): ExtournePayload DTO and ExtournePolicy (Comptable + Admin only)`

### Step 5: Service `TransactionExtourneService` — cas `Recu`/`Pointe` (sans lettrage) + LogContext

**Complexity**: complex
**RED**: Créer `tests/Feature/Extourne/TransactionExtourneServiceRecuTest.php` couvrant les scénarios BDD §2 "Annuler une recette déjà encaissée" et "Annuler une recette pointée banque verrouillée" :
- Test `extourner_recette_recu_cree_extourne_en_attente_sans_lettrage` : tx recette 80 € statut `Recu`, mode `cheque`, sans rapprochement → appel `extourner($tx, payload mode_paiement=virement)` retourne `Extourne` ; asserter :
  - Nouvelle transaction recette `montant_total = -80 €`, lignes inversées (1 ligne sous-cat `-80 €`), date today, libellé `"Annulation - …"`, mode `virement`, statut `EnAttente`
  - Origine reste `Recu` inchangée (statut, rapprochement_id)
  - Origine porte `extournee_at = now()`
  - Entrée `extournes` créée avec `rapprochement_lettrage_id = NULL`
  - Champs origine/extourne/creator/association_id corrects
- Test `extourner_recette_pointee_verrouillee_cree_extourne_en_attente_sans_lettrage` : origine `Pointe`, rattachée à R1 verrouillé → idem, origine reste rattachée à R1
- Test `extourner_propage_tiers_compte_operation_seance` : asserter copie 1:1 des FK
- Test `libelle_par_defaut_si_payload_vide` : payload sans libellé → `"Annulation - {origine.libelle}"`
- Test `mode_paiement_par_defaut_si_payload_vide` : payload sans mode → mode origine
- Test `notes_du_payload_copiees_dans_extourne` : payload notes "Remboursement chèque émis" → `extourne.notes` contient
- Test `transaction_atomique_en_DB_transaction` : tout dans `DB::transaction` (vérifier via spy ou mock query log)
- Test `LogContext_porte_les_ids` : capture log Laravel avec `Log::spy()`, asserter info log contient `association_id`, `user_id`, `transaction_origine_id`, `transaction_extourne_id`, `extourne_id`
- Test `event_TransactionExtournee_dispatched` : `Event::fake()`, asserter dispatch avec payload Extourne

**GREEN**:
- `app/Services/TransactionExtourneService.php` : `final class`, méthode `extourner(Transaction $origine, ExtournePayload $payload): Extourne`
  - `DB::transaction(function () { … })` :
    1. Vérifier policy via `Gate::authorize('create', [Extourne::class, $origine])` (lance exception si refus — gestion via Step 7)
    2. Vérifier `$origine->isExtournable()` (lance exception si non — Step 7)
    3. Créer `Transaction` miroir : copie des attributs métier de l'origine avec montant inversé, date payload, libellé payload, mode_paiement payload, notes payload, statut = `EnAttente`. Pas de `rapprochement_id`. **Pas de copie de pièce jointe** (Q2 — la PJ reste sur l'origine). Numéro de pièce = prochain numéro de la séquence courante du tenant (Q1 — pas de préfixe dédié).
    4. Copier les `TransactionLignes` 1:1 avec montants inversés (sous-cat, opération, séance, etc.) — **sans** copier les pièces jointes éventuelles des lignes
    5. Créer entrée `Extourne` avec `rapprochement_lettrage_id = NULL`
    6. Set `$origine->extournee_at = now()` puis `save()`
    7. `LogContext` info log avec tous IDs
    8. `event(new TransactionExtournee($extourne))` — **dispatché à l'intérieur de la `DB::transaction`** (pas via `DB::afterCommit`) pour que les listeners qui lèvent une exception forcent le rollback (cf. stratégie atomicité Step 8)
    9. Return `$extourne`
- `app/Events/TransactionExtournee.php` : `final class`, propriété `Extourne`
- **Test dédié `extourne_n_herite_pas_pieces_jointes_origine`** : origine avec PJ → extourne créée sans PJ, origine conserve sa PJ. Asserter aucune copie de fichier sur disque, aucune entrée pivot piece_jointe pour l'extourne.

**REFACTOR**: Extraire helpers privés `creerTransactionMiroir(Transaction $origine, ExtournePayload $payload): Transaction` et `copierLignesInversees(Transaction $origine, Transaction $miroir): void` si lisibilité l'exige
**Files**: `app/Services/TransactionExtourneService.php`, `app/Events/TransactionExtournee.php`, test
**Commit**: `feat(extourne): TransactionExtourneService — Recu/Pointe path (no lettrage) + logging + event`

### Step 6: Service `TransactionExtourneService` — cas `EnAttente` (avec lettrage automatique)

**Complexity**: complex
**RED**: Créer `tests/Feature/Extourne/TransactionExtourneServiceEnAttenteTest.php` :
- Test `extourner_recette_en_attente_cree_extourne_et_lettrage_automatique` : tx recette 80 € statut `EnAttente`, sans rapprochement → appel `extourner($tx, payload defaut)` :
  - Nouvelle transaction `montant_total = -80 €`, statut `Pointe`
  - Origine passe à `Pointe`
  - Origine porte `extournee_at = now()`
  - Entrée `extournes.rapprochement_lettrage_id` non null
  - Nouveau `RapprochementBancaire` créé : `type = lettrage`, statut = `Verrouille`, `compte_id = origine.compte_id`, `solde_ouverture = solde_fin` (cohérent ∑=0), date today
  - Les 2 transactions ont `rapprochement_id = lettrage.id`
- Test `lettrage_contient_exactement_2_transactions` : asserter count
- Test `lettrage_solde_ouverture_egal_solde_fin` : asserter contrainte applicative
- Test `lettrage_pas_de_progression_solde` : `solde_fin - solde_ouverture = 0`
- Test `transaction_origine_disparait_des_creances_a_recevoir` : avant extourne → vue Créances inclut origine ; après → ne l'inclut plus (statut Pointe)
- Test `extourne_n_apparait_pas_dans_creances` : idem, statut Pointe
- Test `cas_EnAttente_compte_bancaire_inchange` : `RapprochementBancaire::createVerrouilleAuto()` réutilisé OU mécanisme dédié pour le lettrage (à décider — cf. risque R1)

**GREEN**:
- Étendre `TransactionExtourneService::extourner()` : si `origine.statut_reglement === StatutReglement::EnAttente` :
  - Créer `RapprochementBancaire` type `Lettrage`, statut `Verrouille`, `compte_id = origine.compte_id`, `date_fin = today`, `solde_ouverture = solde_fin = solde_actuel_compte` (lecture du dernier rapprochement bancaire ou 0)
  - Set `rapprochement_id` sur les 2 transactions (origine + miroir) → leur statut passe à `Pointe` via mécanisme existant
  - Set `extournes.rapprochement_lettrage_id`
- Ajouter méthode `creerLettrage(Transaction $origine, Transaction $miroir): RapprochementBancaire` dans le service (privée)
- Décider entre réutiliser `RapprochementBancaireService::createVerrouilleAuto()` (s'adapte à un type différent ?) ou logique dédiée — la spec §3.1 stipule "type=lettrage créé directement en Verrouille (pas d'EnCours pour ce type au MVP)". Préférer logique dédiée pour ne pas polluer le service existant qui est typé `bancaire` implicitement.

**REFACTOR**: Vérifier que `solde_ouverture = solde_fin` n'entre pas en conflit avec d'éventuelles validations existantes sur `RapprochementBancaire`
**Files**: `app/Services/TransactionExtourneService.php`, test
**Commit**: `feat(extourne): TransactionExtourneService — EnAttente path with automatic lettrage`

### Step 7: Service — guards d'erreur + multi-tenant intrusion

**Complexity**: complex
**RED**: Créer `tests/Feature/Extourne/TransactionExtourneServiceGuardsTest.php` couvrant les scénarios BDD §2 de refus :
- Test `refus_si_user_sans_droit_Gestionnaire` : Gestionnaire → exception authorization (`AuthorizationException`)
- Test `refus_si_user_sans_droit_Consultation` : Consultation → exception
- Test `refus_si_transaction_depense` : appel sur dépense → `RuntimeException` "Cette transaction n'est pas extournable" (avec raison)
- Test `refus_si_transaction_deja_extournee` : `extournee_at` non null → exception
- Test `refus_si_transaction_est_elle_meme_extourne` : tx existant dans `extournes.transaction_extourne_id` → exception
- Test `refus_si_helloasso` : `helloasso_order_id` non null → exception
- Test `refus_si_facture_validee` : pivot lié facture `Validee` → exception "Cette transaction est portée par la facture F-… Annulez la facture pour la libérer."
- Test `refus_si_soft_deleted` : tx trashed → exception
- **Multi-tenant intrusion — 2 tests distincts** :
  - `intrusion_via_find_scope_retourne_null` : booter tenant A, appeler `Transaction::find($idDeTenantB)` → asserter résultat null (scope fail-closed agit en amont, le service n'est jamais appelé)
  - `intrusion_via_objet_inject_directement_service_refuse` : récupérer un objet Transaction du tenant B en bypassant le scope (via `Transaction::withoutGlobalScope(TenantScope::class)->find($idB)`), booter tenant A, injecter directement dans `extourner($txDeB, $payload)` → asserter `RuntimeException` ou `AuthorizationException` (ceinture-bretelles `association_id` check explicite). Asserter qu'aucune Extourne créée, aucun flag set sur la tx du tenant B (vérifier via le scope sans tenant).

**GREEN**:
- Dans `TransactionExtourneService::extourner()`, en début de transaction :
  1. Vérifier `$origine` n'est pas null (si appel par ID retournant null à cause du scope, lever exception explicite — ou s'appuyer sur le fait que la signature prend déjà un objet Transaction donc rien à faire ici, le scope a filtré en amont)
  2. Vérifier explicitement `$origine->association_id === TenantContext::currentId()` (ceinture + bretelles)
  3. Lever `AuthorizationException` via `Gate::authorize`
  4. Lever `RuntimeException` francisé si `! isExtournable()`, avec raison spécifique (boucler sur les guards pour rapporter laquelle)
- Optionnel : refactor `isExtournable()` pour retourner aussi un `?string` raison (ex. `assertExtournable(): void` qui lance avec raison)

**REFACTOR**: Centraliser les messages francisés dans une constante / lang file
**Files**: `app/Services/TransactionExtourneService.php`, `app/Models/Transaction.php` (si refacto isExtournable), test
**Commit**: `feat(extourne): TransactionExtourneService — refusal guards and multi-tenant intrusion blocking`

### Step 8: Atomicité — crash mid-transaction → rollback propre

**Complexity**: standard
**Stratégie de test (résolution AC-10)** : pas de mock. L'événement `TransactionExtournee` est dispatché **à l'intérieur** de la `DB::transaction()` (et non via `DB::afterCommit`). Un `Event::listen` enregistré par le test peut throw, ce qui force le rollback de toute la transaction. Le service garde son `final class` ; aucune injection de collaborator artificiel n'est requise.

**RED**: Créer `tests/Feature/Extourne/TransactionExtourneAtomiciteTest.php` :
- Test `event_listener_throw_rollback_complet_cas_recu` : tx recette `Recu` (cas sans lettrage). Enregistrer `Event::listen(TransactionExtournee::class, fn() => throw new RuntimeException('boom'))`. Appeler `extourner($tx, $payload)` → asserter exception levée. Vérifier état :
  - 0 entrée dans table `extournes` (count == 0 avec `Extourne::withTrashed()`)
  - `$tx->fresh()->extournee_at === null`
  - 0 nouvelle Transaction (count avant == count après)
  - 0 nouveau TransactionLigne créé pour la miroir
- Test `event_listener_throw_rollback_complet_cas_en_attente` : idem cas `EnAttente`, asserter en plus :
  - 0 nouveau RapprochementBancaire de type `Lettrage`
  - `$tx->fresh()->statut_reglement === EnAttente` (pas passé à `Pointe`)
  - `$tx->fresh()->rapprochement_id === null`

**GREEN**: Si Step 5 a bien dispatché l'event **dans** `DB::transaction` (et non via `DB::afterCommit`), les tests passent. Sinon ajuster Step 5/6 pour que le dispatch soit englobé dans la transaction.

**REFACTOR**: None
**Files**: test, éventuellement service
**Commit**: `test(extourne): atomic rollback — flag and extournes table stay consistent on crash`

### Step 9: Filtre Créances à recevoir — exclure les extournes `EnAttente` (cas encaissé)

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/CreancesARecevoirExclutExtournesTest.php` :
- Test `extourne_en_attente_cas_encaissé_n_apparait_pas_dans_creances` : tx recette `Recu`, extourne via service → extourne `EnAttente` à -80 € → vue Créances ne l'inclut pas. **Asserter au niveau SQL** que la query du service contient bien une condition excluant les montants ≤ 0 (capture via `DB::getQueryLog()` ou via le scope appliqué au query builder), pas seulement absence de la ligne dans le résultat.
- Test `lettrage_origine_et_extourne_disparaissent_des_creances` : couvert au Step 6, croiser ici aussi
- Test `recette_normale_en_attente_apparait_toujours` : sanity check, pas de régression S0
- Test **BDD Scénario 12 (rappel S0)** : `saisie_manuelle_montant_negatif_refusee_via_TransactionForm` — référence explicite vers le test S0 existant `tests/Feature/Audit/MontantValidationTest.php` ou équivalent. Si le test S0 ne couvre pas exactement le wording de la spec S1 ("L'extourne se fait via le bouton dédié sur une transaction existante."), ajouter une assertion ici qui le confirme.

**GREEN**: Si filtre S0 `montant > 0` couvre déjà → tests passent, juste documenter dans audit. Sinon étendre le filtre dans `TransactionUniverselleService::paginate()` ou la vue Créances.

**REFACTOR**: None
**Files**: éventuellement `app/Services/TransactionUniverselleService.php`, test
**Commit**: `feat(extourne): exclude EnAttente extournes from Créances à recevoir view`

### Step 10: UI — Bouton "Annuler la transaction" + modale Bootstrap (Livewire)

**Complexity**: complex
**RED**: Créer `tests/Feature/Extourne/AnnulerTransactionUITest.php` :
- Test `bouton_visible_si_extournable_et_role_Comptable` : monter composant Livewire (TransactionUniverselle + row détaillée OU nouveau composant `AnnulerTransactionButton`), asserter `assertSee("Annuler la transaction")`
- Test `bouton_invisible_si_non_extournable` : tx déjà extournée → bouton désactivé "Cette transaction a déjà été annulée" + lien "Voir l'annulation" pointant vers extourne via table extournes
- Test `bouton_invisible_si_role_Gestionnaire` : Gestionnaire authentifié → `assertDontSee`
- Test `bouton_invisible_si_role_Consultation` : idem
- Test `bouton_invisible_si_helloasso_ou_facture_validee` : idem (tests des messages spécifiques quand applicable)
- Test `mention_si_transaction_est_elle_meme_extourne` : `assertSee("Cette transaction est une annulation de #X")` + lien vers origine
- Test `modale_pre_remplit_libelle_date_mode_paiement` : ouvrir modale → asserter défauts
- Test `modale_permet_modification_libelle` : modifier libellé → soumission OK
- Test `modale_permet_modification_mode_paiement` : passer de chèque à virement → extourne créée avec virement
- Test `submit_modale_appelle_service_et_affiche_toast` : asserter dispatch event ou navigation, asserter session flash succès
- Test `modale_est_bootstrap_pas_confirm_natif` : asserter présence de `data-bs-toggle="modal"` ou équivalent dans le markup, asserter absence de `wire:confirm` natif

**GREEN**:
- Créer `app/Livewire/Extournes/AnnulerTransactionModal.php` (modale Bootstrap dédiée, réutilisable depuis fiche tx ou row détaillée)
- Vue Blade `resources/views/livewire/extournes/annuler-transaction-modal.blade.php` : modale Bootstrap 5 avec champs date, libellé, mode_paiement (select), notes (textarea), bouton submit
- Composant racine émet event `transaction:extournee` à succès → toast (mécanisme existant projet)
- Intégration dans `TransactionUniverselle` (ou `RapprochementDetail` selon UX) : bouton conditionnel sur `isExtournable()` + policy, ouvre modale avec ID transaction
- Bouton désactivé avec tooltip francisé si non éligible, mention "Voir l'annulation" pour tx déjà extournée

**REFACTOR**: Vérifier que la modale est bien réutilisable depuis plusieurs écrans
**Files**: `app/Livewire/Extournes/AnnulerTransactionModal.php`, vue Blade, intégration dans `TransactionUniverselle.php` + sa vue, test
**Commit**: `feat(extourne): UI — Annuler la transaction button + Bootstrap modal`

### Step 11: UI — Indicateurs visuels listes (badge "annulée" + ligne extourne italique) + eager loading N+1

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/IndicateursListeTest.php` :
- Test `badge_annulee_sur_ligne_origine_extournee` : monter `TransactionUniverselle` avec tx extournée → asserter présence badge "annulée"
- Test `ligne_extourne_en_italique_avec_prefixe_et_montant_negatif_rouge` : asserter classes CSS `fst-italic` + libellé `"Annulation - …"` + style ou classe sur montant négatif
- **Test eager loading N+1** : créer 25 transactions extournées + lister via `TransactionUniverselle`. Breakdown attendu : 1 query count + 1 query paginate + 1 query eager `extournePour` + 1 query eager `extourneeVers` = **4 queries** sur la liste. Marge +1 (total = 5) pour absorber un éventuel filtre tenant ou un join interne. Mesurer via `DB::enableQueryLog()` → asserter `count(getQueryLog()) <= 5`. Si > 5 → analyse query par query pour identifier le N+1 et patcher.

**GREEN**:
- Modifier vue Blade `transaction-universelle.blade.php` : conditionner badge sur `$transaction->extournee_at !== null` ou présence dans `extournes` ; conditionner italique sur `$transaction->estUneExtourne`
- Ajouter `with(['extournePour', 'extourneeVers'])` (ou nom équivalent) sur la query du composant
- Cohérence couleurs/classes avec le helper `formatMontant()` durci au S0

**REFACTOR**: Si helper d'affichage `formatMontant()` peut absorber la logique signe → préférer
**Files**: vue Blade, `app/Livewire/TransactionUniverselle.php` (ou service), test
**Commit**: `feat(extourne): list indicators — annulée badge, italic extourne row, eager loading`

### Step 12: UI — Filtre `type` sur `RapprochementList` + colonne Type

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/RapprochementListFiltreTypeTest.php` :
- Test `filtre_par_defaut_bancaire` : 2 rappro `Bancaire` + 1 `Lettrage` → liste affiche 2 (les 2 Bancaire)
- Test `filtre_lettrage` : sélectionner "Lettrage" → 1 ligne
- Test `filtre_tous` : sélectionner "Tous" → 3 lignes
- Test `colonne_type_affichee` : asserter présence label "Bancaire" / "Lettrage"
- Test `lettrage_affiche_2_transactions_appariees` : asserter ligne lettrage montre date, compte, montant net = 0, 2 transactions origine + extourne
- **Test BDD Scénario 3 — `extourne_recette_encaissee_pointable_dans_rapprochement_bancaire_ordinaire`** : 
  - Setup : tx recette 80 € statut `Recu`, déjà pointée à R1 verrouillé (type Bancaire)
  - Appel service → extourne -80 € statut `EnAttente`, pas de lettrage
  - Créer R2 (type Bancaire, EnCours) sur même compte
  - Pointer l'extourne dans R2 via mécanisme existant (toggle)
  - Verrouiller R2
  - Asserter : extourne passe à `Pointe`, `rapprochement_id = R2.id`, origine reste rattachée à R1 inchangée (statut, rapprochement_id)

**GREEN**:
- Modifier `app/Livewire/RapprochementList.php` : property `filterType` (enum `TypeRapprochement` + 'tous'), default `Bancaire`, modifier query
- Modifier vue Blade `rapprochement-list.blade.php` : ajouter select filter en haut, colonne Type, rendu spécifique pour Lettrage (afficher origine/extourne au lieu d'extrait bancaire)

**REFACTOR**: Si rendu lettrage diverge trop de bancaire → extraire partial Blade
**Files**: `app/Livewire/RapprochementList.php`, vue Blade, test
**Commit**: `feat(extourne): RapprochementList — type filter (Bancaire/Lettrage/Tous) + Type column`

### Step 13: Tests bout en bout — compte de résultat + flux trésorerie reflètent l'extourne

**Complexity**: standard
**RED**: Créer `tests/Feature/Extourne/RapportsAvecExtourneTest.php` :
- Test `compte_de_resultat_recette_nette_zero` : exercice 2026, recette +80 € pointée + extourne -80 € pointée même sous-cat → :
  - Asserter `produit_total_souscat == 0.00`
  - Asserter détail = collection contenant exactement 2 entrées
  - Asserter entrée 1 : montant = +80.00 €, libellé contient "Cotisations séance"
  - Asserter entrée 2 : montant = -80.00 €, libellé contient "Annulation - "
- Test `flux_tresorerie_extourne_pointee_banque_ordinaire_affecte_solde` : 
  - Setup : compte solde initial 500 € (rapprochement préalable verrouillé fixant ce solde)
  - Tx recette +80 € `Recu` → extourne -80 € via service → extourne pointée dans R2 verrouillé
  - Asserter `solde_trésorerie_compte == 420.00` (500 - 80, car l'extourne est un mouvement de sortie)
- Test `flux_tresorerie_lettrage_neutre` : 
  - Setup : compte solde initial 500 €
  - Tx recette +80 € `EnAttente` → extourne via service (lettrage automatique)
  - Asserter `solde_trésorerie_compte == 500.00` (delta = 0, le lettrage n'affecte pas la trésorerie réelle)
- Test `journal_transactions_export_excel_montre_extourne_avec_signe` : export sur exercice avec extourne → vérifier signe explicite et somme nette correcte (vérifier au moins 1 cellule contient "-80")

**GREEN**: Ces tests doivent passer naturellement grâce au S0 (sommations naturelles). Si l'un casse → patcher le builder concerné.

**REFACTOR**: None
**Files**: test
**Commit**: `test(extourne): end-to-end — compte de résultat + flux trésorerie reflect extournes correctly`

### Step 14: Pre-PR quality gate

**Complexity**: trivial
**RED**: N/A
**GREEN**:
- Lancer `./vendor/bin/sail test` : suite verte (~2740 + ~30 = ~2770 tests, 0 fail)
- Lancer `./vendor/bin/sail pint --test` : vert
- Lancer `/code-review --changed` : pas de bloqueur
- Vérifier les 20 ACs cochés
- **Pas de bump de version, pas de tag, pas de PR** (R5 : livraison continue S0 → S2 sur la même branche, version bumpée à la fin du programme)
- Vérifier `declare(strict_types=1)` + `final class` sur tous les nouveaux fichiers (`grep -L "declare(strict_types=1)" app/Models/Extourne.php …`)
- Vérifier indexes ajoutés via `\\d extournes` ou `SHOW INDEX FROM extournes`
- Mettre à jour mémoire `project_extourne_annulation_facture_programme.md` avec statut **S1 LIVRÉ — en attente de S2 avant merge sur main**

**REFACTOR**: None
**Files**: mémoire `project_extourne_annulation_facture_programme.md`
**Commit**: `docs(extourne): finalize slice 1 — primitive d'extourne + lettrage prête pour slice 2`

## Complexity Classification

| Step | Complexity | Justification |
|---|---|---|
| 1 | standard | 3 migrations + enum, foundation propre |
| 2 | standard | Modèle TenantModel + factory + tests scope |
| 3 | standard | Guards multiples sur Transaction, attribute calculé |
| 4 | standard | DTO + Policy, peu de logique |
| 5 | **complex** | Cœur du service, transaction atomique, événement, logging — sensible |
| 6 | **complex** | Lettrage automatique, second mécanisme atomique, contraintes ∑=0 — sensible |
| 7 | **complex** | Multi-tenant intrusion + 8 guards refus — sécurité-sensible |
| 8 | standard | Tests d'atomicité (vérification, code déjà écrit) |
| 9 | standard | Extension filtre Créances |
| 10 | **complex** | UI Livewire + modale Bootstrap + 11 cas testés |
| 11 | standard | Indicateurs listes + eager loading |
| 12 | standard | Filtre + colonne sur écran existant |
| 13 | standard | Tests bout en bout reposant sur S0 |
| 14 | trivial | Pre-PR gate |

4 steps `complex` (5, 6, 7, 10) — les plus sensibles sécuritairement et architecturalement. Les autres standard ou trivial.

## Pre-PR Quality Gate

- [ ] Tous les tests Pest passent (`./vendor/bin/sail test`) — 0 fail
- [ ] Pint passe (`./vendor/bin/sail pint --test`)
- [ ] `/code-review --changed` passe sans bloqueur
- [ ] 20 Acceptance Criteria cochés
- [ ] 14 scénarios BDD (in-MVP) implémentés en tests Feature 1:1
- [ ] Migrations réversibles vérifiées (`migrate:rollback` + `migrate` cycle propre)
- [ ] Backfill `rapprochements_bancaires.type = bancaire` vérifié
- [ ] Multi-tenant intrusion test passe (tenant A ne peut pas extourner tx tenant B)
- [ ] Eager loading N+1 vérifié sur listes
- [ ] `declare(strict_types=1)` + `final class` partout (grep)
- [ ] `project_extourne_annulation_facture_programme.md` mis à jour avec statut S1 LIVRÉ
- [ ] `config/version.php` bumpé (v4.1.10 sous réserve)

## Risks & Open Questions

- **R1 — Réutilisation `RapprochementBancaireService::createVerrouilleAuto()` pour le lettrage** : ce service existant pointe des transactions et crée un rapprochement Verrouille. La spec exige type=`Lettrage`, `solde_ouverture=solde_fin`, et bypass de l'`assertOuvert(exercice)` (un lettrage doit pouvoir s'écrire même si le rapprochement bancaire ordinaire d'un exercice clos serait refusé — à valider). **Mitigation** : décider en Step 6 entre étendre le service existant (ajout paramètre `$type`) ou créer une méthode dédiée `creerLettrage()` dans `TransactionExtourneService`. Recommandation : méthode dédiée, plus simple, isole le risque.
- **R2 — `TenantTestCase` actingAs avec rôle Comptable** : `actingAsAdmin()` existe ; `actingAsComptable()` à créer ou utiliser pattern `actingAs($user)` direct avec user ayant `role=Comptable`. **Mitigation** : ajouter helpers `actingAsComptable()`, `actingAsGestionnaire()`, `actingAsConsultation()` dans `TenantTestCase` au début du Step 4.
- **R3 — Solde ouverture du lettrage** : la spec dit `solde_ouverture = solde_fin = solde_actuel_compte`. Le "solde actuel" = dernier solde du dernier rapprochement Verrouille du compte (ou 0 si aucun). À implémenter en lecture, ou simplement copier le `solde_fin` du dernier rapprochement Bancaire Verrouille ; si aucun, utiliser 0. **Mitigation** : tester explicitement le cas "compte vierge sans rapprochement préalable".
- **R4 — Pas de UI dédiée TransactionShow** : l'audit confirme l'absence d'un composant fiche transaction unique. Le bouton "Annuler" doit donc s'intégrer dans `TransactionUniverselle` (row détaillée ou colonne actions). **Mitigation** : positionner le bouton en colonne actions de la liste universelle, ouvrant la modale ; éviter de créer un nouvel écran fiche au prétexte de l'extourne (over-engineering).
- **R5 — Coordination worktree post-S0** : ✅ **TRANCHÉ** — pas de PR par slice. S1 s'empile directement sur S0 sur la même branche `claude/funny-shamir-8661f9`. Une seule évolution continue S0 → S1 → S2 sera mergée sur main à la fin du programme. Conséquence : pas de gate "livraison S0" entre les slices, l'historique git reste linéaire et les commits S1 cohabitent avec ceux de S0. Step 14 ne fait pas de PR.
- **R6 — Exercice clos ignoré (limitation MVP documentée)** : si origine est dans exercice clos, l'extourne se range dans l'exercice courant. Pas de check applicatif. **Mitigation** : documenter en doc utilisateur après livraison, ne rien faire au code.
- **R7 — Extourne d'extourne interdite mais comment ?** : le guard "transaction qui est elle-même une extourne" repose sur `extournes.transaction_extourne_id`. Si quelqu'un soft-delete l'Extourne (futur dé-lettrage), le guard pourrait redevenir false → permettrait extourne. **Mitigation** : le guard utilise `withTrashed()` sur la table extournes pour ce check, ou alternativement le flag `extournee_at` est aussi vérifié. Préférer la double vérification (flag ET extournes) — défense en profondeur.
- **R8 — Rendu colonne Type "Lettrage" dans `RapprochementList`** : un lettrage n'a pas de "rapprochement bancaire" au sens classique (pas de saisie d'extrait). Le rendu doit montrer "origine ↔ extourne" au lieu de "X transactions pointées sur extrait Y". **Mitigation** : extraire un partial Blade `rapprochement-row-lettrage.blade.php` ou conditionner le rendu via `if($rappro->isLettrage())`.
- **Q1 — Numéro de pièce de l'extourne** : ✅ **TRANCHÉ** — séquence courante de numéros de pièce, pas de préfixe dédié. L'extourne est une transaction à part entière et prend simplement le prochain numéro disponible. Le libellé `"Annulation - {origine}"` suffit à la distinguer humainement.
- **Q2 — Pièce jointe sur l'extourne** : ✅ **TRANCHÉ** — la pièce jointe de la transaction d'origine **reste attachée à l'origine, sans copie ni transfert**. L'extourne naît sans pièce jointe. Si l'utilisateur veut documenter le remboursement, il utilise le mécanisme pièce jointe existant après création. Conséquence Step 5 : **ne pas copier les pièces jointes** lors de la création de la transaction miroir (vérifier qu'aucune relation file/media n'est dupliquée par la copie d'attributs).
- **Q3 — `wire:confirm` Bootstrap pour le bouton "Annuler"** : ✅ **TRANCHÉ** — pas de `wire:confirm` supplémentaire. La modale fait office de confirmation (champs à remplir = saisie active vaut consentement).

---

**Notes pour l'exécution** :
- Préférer **subagent-driven** pour Steps 5-7 (cœur service, sensible, isolation contexte).
- Steps 1-4 et 8-13 peuvent s'enchaîner en exécution séquentielle classique.
- Step 14 obligatoirement séquentiel à la fin.
- Total estimé : 25-30 nouveaux tests Pest.
