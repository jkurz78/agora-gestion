# Plan: Annulation de facture par avoir — Slice 2 du programme Annulation de facture & Extourne

**Created**: 2026-05-01
**Branch**: `claude/funny-shamir-8661f9` (worktree, post-S1 — HEAD `5f99847d`)
**Status**: approved (2026-05-01)
**Modèle de livraison** : pas de PR par slice — évolution continue S0 → S1 → S2 sur la même branche worktree (utilisateur unique reviewer). PR vers main après S2 livré et testé staging.
**Spec source** : [docs/specs/2026-05-01-annulation-facture-avoir-s2.md](docs/specs/2026-05-01-annulation-facture-avoir-s2.md) (PASS 2026-05-01)
**Préalables** : Slice 0 ✅ ([plans/audit-signe-negatif-s0.md](plans/audit-signe-negatif-s0.md)) + Slice 1 ✅ ([plans/extourne-transaction-s1.md](plans/extourne-transaction-s1.md))

## Goal

Refondre `FactureService::annuler(Facture)` pour qu'il neutralise comptablement les transactions impactées par la facture annulée, en composant la primitive `TransactionExtourneService::extourner()` livrée par S1. **Pour chaque transaction `MontantManuel` générée** par cette facture (cas invoice-first depuis v4.1.9) : extourne d'office et dans son entièreté via S1, qui gère selon le statut d'origine soit le lettrage automatique (cas `EnAttente`) soit l'extourne `EnAttente` à pointer ultérieurement (cas `Recu`/`Pointe`). **Pour chaque transaction `Montant` référencée** : détachement du pivot `facture_transaction` uniquement, pas d'extourne (la TX redevient disponible pour rattachement à une autre facture brouillon ; l'utilisateur peut rembourser séparément via le bouton S1 sur la fiche TX). Suppression du guard historique `isLockedByRapprochement()` qui bloquait l'annulation dès qu'une TX liée était rapprochée banque verrouillée — c'est précisément le cas que S1 sait traiter. Mécanisme avoir existant inchangé (`numero_avoir = AV-{exerciceCourant}-NNNN`, `lockForUpdate` sur exercice). UI : modale Bootstrap dédiée `AnnulerFactureModal` informative (sections "extournée d'office" / "détachée seulement" / bandeau banque éventuel). Nouveau scope Eloquent `Transaction::scopeRattachableAFacture()` pour exclure les TX extournées (origines + miroirs) des sélecteurs de règlement, corrigeant un bug observable sur la branche S1 ([FactureEdit.php:447-458](app/Livewire/FactureEdit.php:447)). Couvre la dette d'origine `project_avoir_transactions_dette.md` et clôture le programme.

## Acceptance Criteria

- [ ] Suite Pest verte : tests existants (~2844 post-S1) + ~10-12 nouveaux tests S2, 0 fail
- [ ] Tous les 14 scénarios BDD §2 de la spec implémentés en tests Feature (mappage 1:1)
- [ ] Annulation facture MontantManuel `EnAttente` → 1 extourne + 1 lettrage type `Lettrage` verrouillé, statuts `Pointe` des deux côtés (assertions sur DB après annulation)
- [ ] Annulation facture MontantManuel `Pointe` (origine pointée banque verrouillée) → extourne miroir `EnAttente`, pas de lettrage, origine inchangée — **changement de comportement vs v2.5.4** (ex-blocage levé)
- [ ] Annulation facture portant ligne `Montant` ref → TX ref inchangée (`extournee_at` reste null, `statut_reglement` préservé) + pivot détaché
- [ ] TX ref détachée redevient rattachable : créer F2 brouillon pour même tiers → sélecteur règlements propose la TX
- [ ] Annulation facture mixte (1 ligne `MontantManuel` + 1 ligne `Montant` ref) : 1 extourne pour la générée, détachement pivot pour la ref, atomicité garantie
- [ ] Refus double annulation : facture `Annulee` → exception "Cette facture est déjà annulée."
- [ ] Refus facture brouillon → message existant inchangé "Seule une facture validée peut être annulée."
- [ ] Policy `AnnulerFacturePolicy` : Gestionnaire refusé (UI bouton absent + service exception), Comptable et Admin acceptés
- [ ] Modale Bootstrap `AnnulerFactureModal` informative — pas de `confirm()` natif, sections distinctes MontantManuel / ref / bandeau banque conditionnel
- [ ] Multi-tenant : 2 tests d'intrusion (tenant A → facture B via `find()` retourne null ; injection directe service → exception ceinture-bretelles)
- [ ] Atomicité : forcer une exception après création de la 1ʳᵉ extourne (via `Event::listen` qui throw) → rollback complet, facture reste `Validee`, aucune extourne en base, pivot intact
- [ ] Numero avoir séquentiel sous concurrence : 2 annulations simultanées → 2 numéros distincts (`lockForUpdate` respecté)
- [ ] Compte de résultat : exercice avec facture annulée affiche ∑ sous-catégorie de la ligne MontantManuel = 0 € (recette +X − extourne X)
- [ ] **Invariant filtre règlements disponibles — TX origine extournée exclue** : créer F1 brouillon pour tiers d'une TX MontantManuel précédemment extournée → la TX origine n'apparaît pas dans le sélecteur
- [ ] **Invariant filtre règlements disponibles — TX miroir d'extourne exclue** : sélecteur ne propose jamais une TX listée dans `extournes.transaction_extourne_id`
- [ ] Pivot `facture_transaction` conservé pour MontantManuel (assertion : `$facture->transactions->contains($tx_montant_manuel)` vrai après annulation)
- [ ] Pivot `facture_transaction` détaché pour ref (assertion : `$facture->transactions->contains($tx_ref)` faux après annulation)
- [ ] Helpers `Facture::transactionsGenereesParLignesManuelles()` et `Facture::transactionsReferencees()` disjoints — leur intersection est vide, leur union = pivot complet (test sur facture mixte 1 MM + 1 ref)
- [ ] Logging `LogContext` enrichi : log `facture.annulee` capturé porte `association_id`, `user_id`, `facture_id`, `numero_avoir`, `transactions_extournees: [...]`, `transactions_detachees: [...]`
- [ ] PSR-12 / Pint vert (`./vendor/bin/sail pint --test`)
- [ ] `declare(strict_types=1)` + `final class` sur tous les nouveaux fichiers (DTO, policy, modale Livewire)
- [ ] Pas de régression sur les avoirs antérieurs : fixture facture transaction-first (avant v4.1.9, sans `MontantManuel`) → annulation OK, avoir créé, pivot ref détaché, aucune extourne
- [ ] `/code-review --changed` passe sans bloqueur

## Steps

### Step 1: Foundation — helpers de classification + scope Eloquent + policy

**Complexity**: standard

**RED** : Créer `tests/Feature/Annulation/FactureClassificationTest.php` :
- `transactionsGenereesParLignesManuelles_retourne_tx_des_lignes_MontantManuel` : facture avec 1 ligne MM (TX Tg générée par valider) → helper retourne `[Tg]`
- `transactionsGenereesParLignesManuelles_ignore_tx_referencees` : facture avec 1 ligne ref (Tref préexistante) → helper retourne `[]`
- `transactionsReferencees_retourne_tx_des_lignes_Montant_ref` : facture avec 1 ligne ref → helper retourne `[Tref]`
- `transactionsReferencees_ignore_tx_generees` : facture avec 1 ligne MM → helper retourne `[]`
- `helpers_disjoints_sur_facture_mixte` : facture 1 MM + 1 ref → intersection vide, union = `[Tg, Tref]`

Créer `tests/Feature/Annulation/TransactionRattachableAFactureScopeTest.php` :
- `scope_exclut_tx_extournee_origine` : Tg avec `extournee_at` non nul → absente du résultat
- `scope_exclut_tx_miroir_extourne` : Tm dans `extournes.transaction_extourne_id` → absente
- `scope_inclut_tx_libre` : T sans extourne → présente
- `scope_inclut_tx_recu_normale` : T `Recu` non extournée → présente

Créer `tests/Feature/Annulation/AnnulerFacturePolicyTest.php` :
- `gestionnaire_refuse` : Gate::denies('annuler', $facture) → true
- `comptable_accepte` / `admin_accepte` / `consultation_refuse`

**GREEN** :
- `app/Models/Facture.php` : ajouter méthodes `transactionsGenereesParLignesManuelles(): Collection` et `transactionsReferencees(): Collection` (jointure via `facture_lignes.type === MontantManuel` + `transaction_ligne_id`)
- `app/Models/Transaction.php` : ajouter `scopeRattachableAFacture(Builder $q): Builder` qui chaîne `whereNull('extournee_at')` + `whereNotIn('id', Extourne::select('transaction_extourne_id'))`
- `app/Policies/AnnulerFacturePolicy.php` (nouveau) : `annuler(User, Facture): bool` retourne Comptable+Admin only
- Enregistrer policy dans `AuthServiceProvider::$policies` (mapping `Facture::class => FacturePolicy::class` peut déjà exister — soit étendre, soit créer une policy dédiée — décision step : **policy dédiée** `AnnulerFacturePolicy` enregistrée via `Gate::define('annuler', ...)` pour ne pas affecter d'autres droits sur `Facture`)

**REFACTOR** : Si `Facture` a déjà des helpers similaires sur les transactions, factoriser. Documenter dans le code la sémantique des deux ensembles disjoints.

**Files** : `app/Models/Facture.php`, `app/Models/Transaction.php`, `app/Policies/AnnulerFacturePolicy.php`, `app/Providers/AuthServiceProvider.php`, `tests/Feature/Annulation/{FactureClassification,TransactionRattachableAFactureScope,AnnulerFacturePolicy}Test.php`

**Commit** : `feat(annulation-s2): step 1 — helpers classification MM/ref + scope rattachable + policy`

---

### Step 2: Refonte `annuler()` — cas MontantManuel `EnAttente` (extourne + lettrage automatique)

**Complexity**: complex

**RED** : Créer `tests/Feature/Annulation/AnnulerFactureMontantManuelEnAttenteTest.php` :
- Scénario BDD §2 #1 : facture validée 80 € avec 1 ligne MM, TX générée Tg `EnAttente`, sans rapprochement
- Annuler la facture → asserter :
  - `facture.statut === Annulee`, `numero_avoir === 'AV-{exerciceCourant}-0001'`, `date_annulation === today`
  - `Tg.extournee_at !== null`
  - 1 nouvelle Transaction Tm créée avec `montant_total = -80`, `libelle === 'Annulation - Facture F-...'`, `statut_reglement === Pointe`
  - 1 nouveau RapprochementBancaire `type === Lettrage`, `Verrouille`, contenant Tg et Tm
  - `Tg.statut_reglement === Pointe` (changé via lettrage)
  - 1 entrée `extournes` avec `transaction_origine_id === Tg.id`, `transaction_extourne_id === Tm.id`, `rapprochement_lettrage_id !== null`
  - Pivot `facture_transaction` contient toujours Tg (conservé pour MM)

**GREEN** :
- Réécrire `FactureService::annuler(Facture $facture): void` :
  - Ajouter dépendance `TransactionExtourneService` au constructeur
  - Guards : `assertTenantOwnership`, `assertValidee` (existant), nouveau `assertNotAnnulee`, `Gate::authorize('annuler', $facture)`
  - **Supprimer** la boucle `isLockedByRapprochement` actuelle
  - Wrapper `DB::transaction` :
    1. Calcul `numero_avoir` (mécanisme existant `lockForUpdate` exercice + max séquence)
    2. Update facture : `statut=Annulee`, `numero_avoir`, `date_annulation=today`
    3. Boucle MM : `foreach ($facture->transactionsGenereesParLignesManuelles() as $tg) { $extourneService->extourner($tg, ExtournePayload::fromOrigine($tg)); }`
    4. (Boucle ref reportée à Step 3)
- Audit : vérifier que `ExtournePayload::fromOrigine` existe avec un libellé par défaut `"Annulation - {origine.libelle}"` ; sinon ajuster pour produire le format attendu par le test (la signature actuelle dans S1 prend `ExtournePayload::fromOrigine($tx, [...])` — vérifier au step les paramètres acceptés)

**REFACTOR** : Extraire `assertNotAnnulee` et `calculerNumeroAvoir` en méthodes privées propres. Documenter l'ordre "flip statut avant primitive" qui contourne le guard S1 "facture validée".

**Files** : `app/Services/FactureService.php`, `tests/Feature/Annulation/AnnulerFactureMontantManuelEnAttenteTest.php`

**Commit** : `feat(annulation-s2): step 2 — extourne d'office MontantManuel EnAttente + lettrage auto`

---

### Step 3: Cas MontantManuel `Pointe` (extourne `EnAttente`, sans lettrage) + cas ref (détachement seul)

**Complexity**: standard

**RED** : Créer `tests/Feature/Annulation/AnnulerFactureMontantManuelPointeTest.php` (BDD §2 #2) :
- Facture validée 150 € MM, Tg `Pointe` rattaché à R1 banque verrouillé
- Annuler → asserter :
  - Tm créée `EnAttente`, `montant_total = -150`, sans `rapprochement_id`
  - Aucun nouveau rapprochement type `Lettrage`
  - Tg reste `Pointe`, reste rattaché à R1
  - `extournes.rapprochement_lettrage_id === null`

Créer `tests/Feature/Annulation/AnnulerFactureMontantRefTest.php` (BDD §2 #3 + #4) :
- Facture validée portant 1 ligne ref Tref (préexistante 200 € `Recu`)
- Annuler → Tref inchangée (`extournee_at === null`, `statut_reglement === Recu`), pivot ne contient plus Tref
- Test follow-up : créer F2 brouillon pour même tiers → sélecteur via `Transaction::rattachableAFacture()->where('tiers_id', ...)` propose Tref

**GREEN** :
- Compléter `FactureService::annuler()` avec la boucle ref :
  ```
  foreach ($facture->transactionsReferencees() as $tref) {
      $facture->transactions()->detach($tref->id);
  }
  ```
- Câbler `FactureEdit.php:447` pour utiliser le scope `Transaction::rattachableAFacture()` au lieu de la requête actuelle (réécriture cohérente, mais détail au step 7 pour ne pas mélanger les concerns ici — **alternative** : juste valider que le scope se branche correctement sur la query existante via `->rattachableAFacture()` chaîné). Décision : ajouter `->rattachableAFacture()` dans `FactureEdit::render()` au step 7 (UI consolidée). Le step 3 valide juste que le scope existe et fonctionne en isolation via test unitaire.
  - Actually, le test "Tref redevient rattachable" implique que le scope doit être consommé quelque part. Je le câble dans `FactureEdit.php` ici (lignes 447-458) pour rendre le test passant côté Livewire, et je raffine la modale au step 7.

**REFACTOR** : Si la requête de `FactureEdit::render()` devient longue, extraire en méthode privée `requeteReglementsDisponibles()`.

**Files** : `app/Services/FactureService.php`, `app/Livewire/FactureEdit.php`, `tests/Feature/Annulation/{AnnulerFactureMontantManuelPointe,AnnulerFactureMontantRef}Test.php`

**Commit** : `feat(annulation-s2): step 3 — cas MontantManuel Pointé (sans lettrage) + détachement ref + scope rattachable câblé`

---

### Step 4: Cas mixte (MM + ref dans la même facture) + atomicité

**Complexity**: standard

**RED** : Créer `tests/Feature/Annulation/AnnulerFactureMixteTest.php` :
- BDD §2 #5 : facture validée 1 ligne MM 100 € + 1 ligne ref 50 €
- Annuler → asserter :
  - 1 extourne pour Tg (MM) + lettrage auto
  - Pas d'extourne pour Tref, pivot ne contient plus Tref
  - Pivot contient toujours Tg
  - 2 collections helpers disjointes après annulation

Créer `tests/Feature/Annulation/AnnulerFactureAtomiciteTest.php` :
- Facture mixte, `Event::listen(TransactionExtournee::class, fn() => throw new RuntimeException('forcer rollback'))`
- Annuler → asserter exception levée, et :
  - `facture.statut === Validee` (rollback)
  - `numero_avoir === null`
  - Aucune extourne en base (`Extourne::count() === 0`)
  - Aucun lettrage créé
  - Pivot intact (Tg + Tref toujours là)

**GREEN** : Aucun code production nouveau attendu si Steps 2-3 corrects ; les tests doivent passer "for free". Si l'atomicité échoue, ajuster l'ordre dans la `DB::transaction` pour s'assurer que toutes les opérations (incluant detach pivot) sont à l'intérieur du wrapper.

**REFACTOR** : Vérifier qu'aucune opération hors `DB::transaction` ne fuit (le `Log::info` doit être hors transaction pour ne pas être rollback'é, comme dans `valider()`).

**Files** : `tests/Feature/Annulation/{AnnulerFactureMixte,AnnulerFactureAtomicite}Test.php` (potentiellement ajustement marginal `app/Services/FactureService.php`)

**Commit** : `feat(annulation-s2): step 4 — cas mixte MM + ref + test atomicité (Event::listen throw)`

---

### Step 5: Suppression du guard `isLockedByRapprochement` + refus double annulation + adaptation `FactureAvoirTest`

**Complexity**: standard

**RED** :
- Créer `tests/Feature/Annulation/AnnulerFactureBanqueVerrouilleeTest.php` (BDD §2 #6) :
  - Facture validée + Tg MM pointée à R1 verrouillé → annulation OK (pas d'exception "annuler le rapprochement"), Tg reste pointée R1, Tm `EnAttente` créée

- Créer `tests/Feature/Annulation/AnnulerFactureRefusTest.php` (BDD §2 #8, #9) :
  - Facture `Annulee` → annuler → exception "Cette facture est déjà annulée."
  - Facture `Brouillon` → annuler → exception "Seule une facture validée peut être annulée." (existant, on s'assure de la non-régression)

- Auditer `tests/Feature/FactureAvoirTest.php` (suite existante) :
  - Identifier les tests qui s'attendent au guard `isLockedByRapprochement`
  - Les amender pour refléter le nouveau comportement (extourne `EnAttente` créée au lieu d'exception)
  - Tests qui asserient seulement le numero_avoir / statut Annulee : non touchés
  - Aucun test ne doit être supprimé sans remplacement équivalent

**GREEN** :
- Confirmer que le guard `isLockedByRapprochement` est bien supprimé du `FactureService::annuler()` (Step 2 l'a déjà supprimé)
- Confirmer que `assertNotAnnulee()` lève le bon message
- Adapter `FactureAvoirTest` selon l'audit

**REFACTOR** : Si plusieurs tests `FactureAvoirTest` ont une fixture commune devenue obsolète, refactorer en factory ou data provider.

**Files** : `tests/Feature/Annulation/{AnnulerFactureBanqueVerrouillee,AnnulerFactureRefus}Test.php`, `tests/Feature/FactureAvoirTest.php` (amendements)

**Commit** : `feat(annulation-s2): step 5 — suppression guard banque verrouillée + assertNotAnnulee + maj FactureAvoirTest`

---

### Step 6: Multi-tenant intrusion + concurrence numéro + log enrichi

**Complexity**: standard

**RED** : Créer `tests/Feature/Annulation/AnnulerFactureMultiTenantTest.php` :
- BDD §2 #12 : 2 tenants, comptable A → fetch facture B via `Facture::find($idB)` retourne null (scope global)
- Bypass scope : injection directe `$service->annuler($factureB)` depuis un context tenant A → `assertTenantOwnership` lève exception (ceinture-bretelles)

Créer `tests/Feature/Annulation/AnnulerFactureConcurrenceTest.php` :
- 2 factures validées dans le même exercice
- Simuler 2 calls parallèles via `DB::transaction` imbriqués (ou test sequential mais en vérifiant le `lockForUpdate` via `\Illuminate\Database\Connection::transactionLevel()` instrumenté) — cf pattern utilisé dans `FactureValidationConcurrenceTest` ou équivalent existant
- Asserter 2 `numero_avoir` distincts et séquentiels

Créer `tests/Feature/Annulation/AnnulerFactureLoggingTest.php` :
- `Log::spy()` ou `Log::assertLogged('facture.annulee', fn ($context) => ...)`
- Annuler facture mixte → asserter log porte les 5 clés (`facture_id`, `numero_avoir`, `association_id`, `user_id`, `transactions_extournees`, `transactions_detachees`)

**GREEN** :
- Ajouter `Log::info('facture.annulee', [...])` à la fin de `FactureService::annuler()` (hors `DB::transaction`)
- Vérifier que `LogContext` middleware ajoute déjà `association_id` + `user_id` automatiquement (sinon les ajouter explicitement dans le payload)
- S'assurer que le log est émis APRÈS commit pour cohérence (cf pattern `Log::info('facture.valide', ...)` existant à `FactureService.php:291`)

**REFACTOR** : Si le payload du log devient long, extraire en méthode privée `buildLogContext($facture, $extournees, $detachees)`.

**Files** : `app/Services/FactureService.php`, `tests/Feature/Annulation/{AnnulerFactureMultiTenant,AnnulerFactureConcurrence,AnnulerFactureLogging}Test.php`

**Commit** : `feat(annulation-s2): step 6 — multi-tenant + concurrence numero_avoir + log enrichi`

---

### Step 7: Modale Livewire `AnnulerFactureModal` (UI informative)

**Complexity**: standard

**RED** : Créer `tests/Feature/Annulation/AnnulerFactureModalTest.php` :
- Test d'ouverture : composant rendu pour facture validée → vue contient sections "Transactions générées" / "Règlements référencés"
- Test affichage MM : facture 1 MM → liste contient le libellé + montant + statut, mention "annulation comptable forcée"
- Test affichage ref : facture 1 ref → liste contient libellé + montant + texte explicatif "Pour rembourser un règlement référencé, utilisez le bouton « Annuler la transaction » sur sa fiche."
- Test bandeau banque (BDD §2 #7) : facture avec ≥ 1 TX MM `Pointe` → bandeau orange visible
- Test absence de bandeau si toutes MM `EnAttente`
- Test policy : Gestionnaire connecté → bouton "Annuler la facture" absent sur la fiche (test sur `FactureShow` existant)
- Test confirm : `confirmer()` appelle `FactureService::annuler` puis dispatch event `FactureAnnulee` pour rafraîchir la fiche

**GREEN** :
- Créer `app/Livewire/Factures/AnnulerFactureModal.php` (ou `app/Livewire/AnnulerFactureModal.php` selon convention projet, à vérifier en lisant la structure courante de `app/Livewire/`)
- Créer `resources/views/livewire/factures/annuler-facture-modal.blade.php` :
  - Structure Bootstrap 5 modal avec sections : résumé, MM forcées, ref détachées, bandeau banque conditionnel
  - Bouton "Confirmer l'annulation" qui déclenche `wire:click="confirmer"`
- Adapter `app/Livewire/FactureShow.php` (ou équivalent — composant qui porte le bouton "Annuler la facture") :
  - Remplacer `wire:confirm` simple par dispatch d'un event d'ouverture de la modale
  - Cacher le bouton si policy refuse
- Vérifier convention projet : modale = composant Livewire séparé monté en parent, ou intégré directement dans `FactureShow` ? Lire un précédent (ex. `AnnulerTransactionModal` livré au S1) et reproduire le pattern.

**REFACTOR** : Si la blade contient plus de logique que d'affichage, extraire en composant ou en variables calculées.

**Files** : `app/Livewire/Factures/AnnulerFactureModal.php` (nouveau), `resources/views/livewire/factures/annuler-facture-modal.blade.php` (nouveau), `app/Livewire/FactureShow.php` (modification), `resources/views/livewire/facture-show.blade.php` (modification), `tests/Feature/Annulation/AnnulerFactureModalTest.php`

**Commit** : `feat(annulation-s2): step 7 — modale Livewire AnnulerFactureModal informative`

---

### Step 8: Invariant filtre règlements disponibles consolidé + non-régression historique + état incohérent

**Complexity**: standard

**RED** :
- Créer `tests/Feature/Annulation/SelecteurReglementsExclutExtourneesTest.php` :
  - BDD §2 #11 (et AC-20/21 spec) :
    - Setup : annuler une F1 (MM Tg extournée) → Tg.extournee_at non nul + Tm miroir négative existe
    - Créer F2 brouillon pour même tiers
    - Render `FactureEdit` sur F2 → vue['transactions'] ne contient ni Tg ni Tm
    - Vue contient une autre TX libre du même tiers (sanity check)

- Créer `tests/Feature/Annulation/AnnulerFactureHistoriqueTransactionFirstTest.php` :
  - Fixture facture transaction-first (mode v2.5.4 : 0 MM, lignes ref pointant vers TX préexistantes)
  - Annuler → avoir créé, pivot ref détaché, aucune extourne, aucune erreur
  - Asserter pas de régression sur le mécanisme legacy

- Créer `tests/Feature/Annulation/AnnulerFactureMontantManuelDejaExtourneeTest.php` (BDD §2 #10, ceinture-bretelles) :
  - Manipuler la base directement pour simuler `Tg.extournee_at` non nul SANS passer par S1 (état pathologique)
  - Annuler → exception "La transaction « ... » a déjà été annulée. État incohérent — contactez l'admin."
  - Facture reste `Validee`

**GREEN** :
- Étape déjà câblée Step 3, ce step **valide** que le filtre s'applique partout (pas seulement dans le test isolé du Step 1)
- Ajouter dans `FactureService::annuler()` une pré-validation explicite :
  ```
  foreach ($facture->transactionsGenereesParLignesManuelles() as $tg) {
      if ($tg->extournee_at !== null) {
          throw new RuntimeException("La transaction « {$tg->libelle} » a déjà été annulée. État incohérent — contactez l'admin.");
      }
  }
  ```
  → exécutée AVANT le flip statut Annulee, donc si exception, facture reste Validee.

**REFACTOR** : Si plusieurs guards de pré-validation s'accumulent en début de `annuler()`, extraire en méthode privée `assertExtournableMM($facture)`.

**Files** : `app/Services/FactureService.php`, `tests/Feature/Annulation/{SelecteurReglementsExclutExtournees,AnnulerFactureHistoriqueTransactionFirst,AnnulerFactureMontantManuelDejaExtournee}Test.php`

**Commit** : `feat(annulation-s2): step 8 — invariant filtre règlements + non-régression historique + guard état incohérent`

---

### Step 9: Compte de résultat post-annulation + scénarios BDD restants

**Complexity**: standard

**RED** :
- Créer `tests/Feature/Annulation/CompteResultatAnnulationTest.php` (BDD §2 #13) :
  - Facture validée 80 € MM dans exercice E
  - Annuler dans le même exercice E (date today)
  - `CompteResultatBuilder::pour($exercice)` → asserter ∑ sous-catégorie de la ligne = 0 € ET détail montre 2 lignes (+80 et -80)
  - Cohérent avec AC-3 du Slice 1

- Auditer §2 BDD pour repérer scénarios non encore couverts par les Steps 2-8 :
  - Scénario 4 ("ref redevient rattachable") → couvert Step 3
  - Scénario 7 ("modale informative listing") → couvert Step 7
  - Vérifier l'inventaire complet et combler si manquant

**GREEN** : Aucun code production attendu — comportement déjà livré par S0 (Compte de résultat traite les négatifs via `!= 0`) + Steps 2-8.

**REFACTOR** : Si certaines fixtures se répètent à travers les tests, extraire en `tests/Support/AnnulationFactureFactory.php` (helper de fixture).

**Files** : `tests/Feature/Annulation/CompteResultatAnnulationTest.php`, éventuellement `tests/Support/AnnulationFactureFactory.php`

**Commit** : `test(annulation-s2): step 9 — compte de résultat post-annulation + finalisation BDD coverage`

---

### Step 10: Pre-PR quality gate — Pint + code-review --changed + non-régression suite complète

**Complexity**: standard

**RED** : N/A (pas de nouveau test)

**GREEN** :
- `./vendor/bin/sail pint --test` → si rouge, `./vendor/bin/sail pint` puis recommit
- `./vendor/bin/sail test` → suite complète verte (~2854-2856 tests)
- `/code-review --changed` → traiter les findings critiques/majeurs
- Vérifier `declare(strict_types=1)` + `final class` sur tous nouveaux fichiers via grep
- Vérifier que `MEMORY.md` du projet ne contient pas de claim obsolète à mettre à jour
- Mettre à jour le pointeur `project_extourne_annulation_facture_programme.md` avec le statut S2 livré

**REFACTOR** : Si `/code-review` remonte des refactorings standards (DRY, naming, complexité), les appliquer en commits séparés numérotés.

**Files** : éventuellement multiples selon findings ; pas de nouveau code attendu en routine

**Commit** : `chore(annulation-s2): step 10 — pre-PR quality gate green` (+ commits de refacto si findings)

## Complexity Classification

| Step | Rating | Justification |
|---|---|---|
| 1 | standard | Helpers Eloquent + scope + policy isolés ; pattern projet établi |
| 2 | **complex** | Refonte du cœur de `annuler()`, composition cross-service S1, ordre critique du flip statut, premier point d'intégration |
| 3 | standard | Compléments incrémentaux, scope réutilisé |
| 4 | standard | Cas mixte = combinaison ; atomicité = pattern testé en S1 |
| 5 | standard | Suppression de guard + adaptations test existants |
| 6 | standard | Tests d'intrusion / concurrence / log = patterns projet établis (cf S1) |
| 7 | standard | Composant Livewire suit pattern `AnnulerTransactionModal` S1 |
| 8 | standard | Câblage des invariants + non-régression historique |
| 9 | standard | Tests d'intégration finaux, pas de logique nouvelle |
| 10 | standard | Quality gate, pas de nouveau code attendu en routine |

## Pre-PR Quality Gate

- [ ] All tests pass (`./vendor/bin/sail test` → 0 fail)
- [ ] Pint vert (`./vendor/bin/sail pint --test`)
- [ ] `/code-review --changed` passe sans bloqueur critique
- [ ] Documentation : aucune doc applicative à mettre à jour côté code (la spec et le memory file servent de doc)
- [ ] `MEMORY.md` du projet : pointeur `project_extourne_annulation_facture_programme.md` mis à jour avec statut S2 LIVRÉ
- [ ] Aucun TODO résiduel dans le code livré
- [ ] `declare(strict_types=1)` + `final class` sur tous les nouveaux fichiers (DTO, policy, modale Livewire)

## Risks & Open Questions

- **Risque — couplage `FactureService` ↔ `TransactionExtourneService`** : on injecte le second dans le premier. Risque que les deux services se "regardent dans les yeux" si on n'est pas vigilant (ex: extourner appelle annuler facture qui appelle extourner). Mitigation : la primitive S1 ne dépend PAS de `FactureService` (vérifié dans la spec S1 §3 et confirmé par lecture de [TransactionExtourneService.php](app/Services/TransactionExtourneService.php)). Sens unique : S2 → S1.

- **Risque — adaptation `FactureAvoirTest` existante** : suite legacy potentiellement large, certains tests pourraient devenir caduques (guard banque verrouillée). Mitigation : audit complet avant Step 5, amender plutôt que supprimer pour préserver la couverture, garder un journal des modifications dans le commit.

- **Risque — modèle de policy** : `AnnulerFacturePolicy` dédié vs extension d'une `FacturePolicy` existante. Décision step 1 : policy dédiée enregistrée via `Gate::define('annuler', ...)` pour ne pas affecter d'autres droits sur `Facture`. Si une `FacturePolicy` existe déjà, vérifier au step 1 s'il faut centraliser.

- **Question — où monter la modale `AnnulerFactureModal`** : composant Livewire séparé monté en parent de `FactureShow`, ou intégré ? À résoudre step 7 en regardant le pattern `AnnulerTransactionModal` livré au S1 (cf `app/Livewire/Extournes/AnnulerTransactionModal.php`).

- **Question — concurrence `numero_avoir` testable** : le pattern de test `lockForUpdate` n'est pas trivial à reproduire en Pest. Mitigation : si un pattern existe déjà (test `FactureValidationConcurrenceTest` ou équivalent pour la séquence `numero` des factures validées), reproduire ; sinon test sequential plus modeste qui assure la non-collision sans simuler le vrai parallel.

- **Question — fixture facture transaction-first historique** : pour le test AC-23 / Step 8, il faut une factory qui crée une facture v2.5.4-style (lignes ref uniquement, pas de MM). Vérifier au step 8 si une factory existante couvre déjà ce cas, sinon construire à la main.

- **Périmètre confirmé hors scope** : aucun changement DB schema, aucune migration, aucun changement HelloAsso, aucun changement PDF/Factur-X, exercice clos non géré (héritage S1).
