# Plan: Back-office — Traitement des factures déposées par les partenaires

**Created**: 2026-04-24
**Branch**: `feat/portail-factures-partenaires` (Option A — un seul PR final portail + back-office)
**Spec**: [docs/specs/2026-04-24-back-office-factures-partenaires.md](../docs/specs/2026-04-24-back-office-factures-partenaires.md)
**Status**: approved

## Goal

Livrer l'UI back-office qui permet au comptable de **comptabiliser** (via le `TransactionForm` existant + IA `InvoiceOcrService` optionnelle) et **rejeter** les factures déposées par les partenaires sur le portail. Réutilisation maximale du stack IncomingDocument IMAP : pas d'écran dédié, pas de nouveau service OCR, delta ciblé sur `TransactionForm` + `InvoiceOcrService`. Inclut la modification côté portail pour afficher les dépôts rejetés (avec motif) dans la boîte de dépôt, afin que le partenaire puisse redéposer une version corrigée.

## Acceptance Criteria

- [ ] Route `/factures-partenaires/a-comptabiliser` accessible aux comptables (403 sinon, 404 cross-tenant).
- [ ] Liste back-office : 4 onglets (À traiter / Traitées / Rejetées / Toutes), tri date facture desc.
- [ ] Bouton "Comptabiliser" → `TransactionForm` s'ouvre pré-rempli (tiers, date, ref, PDF preview).
- [ ] IA configurée : `analyzeFromPath()` appelée avec `context` contenant tiers/ref/date attendus ; warnings générés en cas de discordance.
- [ ] IA non configurée : 3 champs + PDF preview pré-remplis, saisie manuelle du reste ; aucun message d'erreur.
- [ ] **PDF toujours attaché à la Transaction**, quelle que soit la branche IA.
- [ ] Upload manuel PJ masqué en mode dépôt (évite collision avec garde `comptabiliser()`).
- [ ] Validation → Transaction créée + `comptabiliser()` appelée + dépôt `Traitee` + event émis.
- [ ] Exercice clôturé bloque la comptabilisation, aucun changement d'état ni de disque.
- [ ] Bouton "Rejeter" : modale Bootstrap motif obligatoire, `statut=Rejetee`, PDF conservé, event `FactureDeposeeRejetee` émis.
- [ ] `rejeter()` refuse si statut ≠ Soumise.
- [ ] Portail `AtraiterIndex` : scope élargi à `Soumise+Rejetee`, tri Rejetee en premier, badge + motif visible.
- [ ] Portail `oublier()` guard élargi à `Soumise+Rejetee`.
- [ ] Tests d'intrusion cross-tenant sur liste + PDF + comptabiliser + rejeter (≥ 4 cas).
- [ ] Suite Pest verte, ≥ 20 tests dédiés back-office + portail modifs.
- [ ] Doc `docs/portail-tiers.md` mise à jour (affichage Rejetee portail).
- [ ] PSR-12 (`./vendor/bin/pint`).

## Steps

### Step 1: Event `FactureDeposeeRejetee` + service `rejeter()` + `oublier()` guard élargi

**Complexity**: standard
**RED**:
- `tests/Unit/Services/Portail/FacturePartenaireServiceRejeterTest.php` — assert :
  - `rejeter($depot, "motif")` sur Soumise → statut `Rejetee`, `motif_rejet` renseigné, event `FactureDeposeeRejetee` émis, PDF conservé sur disque, log `Log::info('facture_partenaire.rejetee', ...)` avec `association_id` + `user_id`.
  - `rejeter()` sur Traitee ou Rejetee → `DomainException`, aucun changement d'état.
  - `rejeter()` avec motif vide → `DomainException` (garde service, la validation UI est en plus).
- `tests/Unit/Services/Portail/FacturePartenaireServiceOublierTest.php` — ajout cas : `oublier()` sur dépôt `Rejetee` → hard delete OK (BDD + fichier) ; autre Tiers → refus.

**GREEN**:
- `App\Events\Portail\FactureDeposeeRejetee` (class + constructor `public readonly FacturePartenaireDeposee $depot`).
- `FacturePartenaireService::rejeter(FacturePartenaireDeposee $depot, string $motif): void` + enum `StatutFactureDeposee::Rejetee`.
- `oublier()` : guard `in [Soumise, Rejetee]` au lieu de `=== Soumise`.

**REFACTOR**: docblock `rejeter()` aligne la prose sur `comptabiliser()` (guards + audit).
**Files**: `app/Events/Portail/FactureDeposeeRejetee.php`, `app/Services/Portail/FacturePartenaireService.php`, `tests/Unit/Services/Portail/FacturePartenaireServiceRejeterTest.php`, `tests/Unit/Services/Portail/FacturePartenaireServiceOublierTest.php`
**Commit**: `feat(factures-partenaires): add rejeter() service + event + widen oublier() guard`

### Step 2: `InvoiceOcrService` — support `reference_attendue` + `date_attendue` dans `$context`

**Complexity**: standard
**RED**: `tests/Unit/Services/InvoiceOcrServicePromptTest.php` — nouveau test dédié au prompt généré :
- `buildPrompt` avec `context['reference_attendue']` et `context['date_attendue']` renvoie un texte qui mentionne ces deux valeurs et inclut des exemples de warnings correspondants.
- Comportement rétrograde : un `$context` avec uniquement `tiers_attendu` reste inchangé.

(Pas d'appel API réel — on mocke ou on expose `buildPrompt` via réflexion / méthode package-private testable.)

**GREEN**:
- Étendre le PHPDoc `@param` de `analyze*()` pour accepter les deux nouvelles clés.
- `buildPrompt()` : ajouter les deux clés au bloc "CONTEXTE ENCADRANT" + exemples de warnings ("Le numéro extrait … ne correspond pas au numéro déposé …").

**REFACTOR**: centraliser la construction du bloc contexte dans une petite méthode privée `buildContextBlock(array $context): string` si lisibilité souffre.
**Files**: `app/Services/InvoiceOcrService.php`, `tests/Unit/Services/InvoiceOcrServicePromptTest.php`
**Commit**: `feat(ocr): support reference_attendue + date_attendue in InvoiceOcrService context`

### Step 3: Policy `FacturePartenaireDeposeePolicy` + ability `treat`

**Complexity**: standard
**RED**: `tests/Unit/Policies/FacturePartenaireDeposeePolicyTest.php` —
- Comptable (role `Comptable` sur l'association) : `treat` → true.
- Admin : `treat` → true.
- Utilisateur standard : `treat` → false.
- Super-admin : `treat` → true.
- Autre tenant : `treat` → false.

**GREEN**:
- `App\Policies\FacturePartenaireDeposeePolicy` avec méthode `treat(User $user, ?FacturePartenaireDeposee $depot = null): bool` (scope tenant + role checks).
- Enregistrement dans `AuthServiceProvider::$policies`.

**REFACTOR**: facteur commun avec `NoteDeFraisPolicy` si identique (sinon laisser).
**Files**: `app/Policies/FacturePartenaireDeposeePolicy.php`, `app/Providers/AuthServiceProvider.php`, `tests/Unit/Policies/FacturePartenaireDeposeePolicyTest.php`
**Commit**: `feat(factures-partenaires): add policy FacturePartenaireDeposeePolicy with treat ability`

### Step 4: Route signée PDF back-office

**Complexity**: standard
**RED**: `tests/Feature/BackOffice/FacturePartenaireDepotPdfTest.php` —
- Comptable authentifié + URL signée valide → 200 + header `Content-Type: application/pdf`.
- URL signée expirée → 403.
- Comptable authentifié mais depot d'un autre tenant → 404 (scope global).
- Utilisateur non-comptable → 403.

**GREEN**:
- Route `GET /factures-partenaires/a-comptabiliser/{depot}/pdf` nommée `back-office.factures-partenaires.pdf`, middleware `auth` + `signed` + `can:treat,depot`.
- Controller/closure : stream du PDF via `Storage::disk('local')->response($depot->pdf_path)`.

**REFACTOR**: aucune.
**Files**: `routes/web.php`, éventuellement `app/Http/Controllers/BackOffice/FacturePartenaireDepotController.php`, `tests/Feature/BackOffice/FacturePartenaireDepotPdfTest.php`
**Commit**: `feat(factures-partenaires): add signed back-office PDF route`

### Step 5: `TransactionForm::openFormFromDepotFacture()` + masquage upload PJ manuel

**Complexity**: complex
**RED**: `tests/Livewire/TransactionFormFromDepotFactureTest.php` —
- Dispatch `open-transaction-form-from-depot-facture` avec `depotId` valide → `type=depense`, `tiers_id`, `date`, `reference` pré-remplis depuis le dépôt, `factureDeposeeId` renseigné, `ocrMode=true`, preview PDF URL signée back-office.
- Scope tenant : dépôt d'un autre tenant → pas de pré-remplissage, sessions flash error.
- Statut ≠ Soumise : pas d'ouverture, flash error.
- IA configurée : `InvoiceOcrService::analyzeFromPath()` appelée avec `context = ['tiers_attendu' => ..., 'reference_attendue' => $numero, 'date_attendue' => $date]` (mock service).
- IA non configurée : aucun appel OCR, form reste utilisable.
- IA en erreur : `ocrError` renseigné, `ocrWarnings` vide.
- Rendu : champ upload PJ manuel (`pieceJointe`) **non exposé** en mode dépôt (assertion sur la vue rendue).

**GREEN**:
- `TransactionForm` : ajouter `public ?int $factureDeposeeId = null`.
- Handler `#[On('open-transaction-form-from-depot-facture')] openFormFromDepotFacture(int $depotId)` : charge `FacturePartenaireDeposee` avec guards, init form, passe `$context` à `runOcrAnalysis()`.
- Include resets existants ([TransactionForm.php:129, 397](app/Livewire/TransactionForm.php#L129)) : ajouter `factureDeposeeId`.
- Vue `transaction-form.blade.php` : conditionner l'affichage du bloc upload PJ manuel sur `factureDeposeeId === null`.

**REFACTOR**: factoriser un éventuel helper `runOcrWithContext(array $context, string $diskPath)` si duplication.
**Files**: `app/Livewire/TransactionForm.php`, `resources/views/livewire/transaction-form.blade.php`, `tests/Livewire/TransactionFormFromDepotFactureTest.php`
**Commit**: `feat(factures-partenaires): open TransactionForm from depot-facture with pre-fill + OCR context`

### Step 6: `TransactionForm::finalizeFactureDeposeeCleanup()` + `retryOcr()` branche dépôt

**Complexity**: complex
**RED**: `tests/Livewire/TransactionFormSaveFromDepotFactureTest.php` —
- Save en mode dépôt (IA off, champs saisis manuellement) : Transaction Dépense créée, `comptabiliser($depot, $tx)` appelée, PDF déplacé vers `transactions/{txid}/...`, dépôt `statut=Traitee` + `transaction_id` + `traitee_at`, event `FactureDeposeeComptabilisee` émis, `factureDeposeeId` reset.
- Save en mode dépôt + IA on : idem + lignes pré-remplies appliquées.
- **PDF attaché dans les deux branches** (IA off / IA on) — assertion explicite.
- `ExerciceCloturedException` levée : rollback complet, dépôt reste `Soumise`, PDF reste sur le chemin source, Transaction non créée.
- Garde `piece_jointe préexistante` : `comptabiliser()` lève `DomainException` → rollback, flash error.
- `retryOcr()` avec `factureDeposeeId !== null` : `analyzeFromPath()` re-appelée, `ocrError` réinitialisé.

**GREEN**:
- `finalizeFactureDeposeeCleanup(Transaction $tx)` méthode privée :
  - Charge le dépôt, appelle `FacturePartenaireService::comptabiliser($depot, $tx)`.
  - Reset `factureDeposeeId` en fin.
- Branchement dans `save()` après création Transaction, symétrique à `finalizeIncomingDocumentCleanup()` ([TransactionForm.php:562-568](app/Livewire/TransactionForm.php#L562-L568)).
- `DB::transaction` englobant save + comptabiliser (rollback si l'un échoue).
- `retryOcr()` : ajouter la branche `factureDeposeeId !== null` symétrique au cas `incomingDocumentId` existant ([TransactionForm.php:655-680](app/Livewire/TransactionForm.php#L655-L680)).

**REFACTOR**: possibilité d'extraire `runOcrFromFile($diskPath, $context)` commun aux deux flux si duplication clé.
**Files**: `app/Livewire/TransactionForm.php`, `tests/Livewire/TransactionFormSaveFromDepotFactureTest.php`
**Commit**: `feat(factures-partenaires): finalize Transaction via comptabiliser() on save + retryOcr branch`

### Step 7: Composant Livewire `BackOffice\FacturePartenaire\Index` (liste + onglets)

**Complexity**: standard
**RED**: `tests/Feature/Livewire/BackOffice/FacturePartenaire/IndexTest.php` —
- Mount par comptable : 200, vue rendue.
- Mount par utilisateur non-comptable : 403 (policy).
- Mount par comptable sur tenant Y avec dépôts de X : liste vide (scope global).
- Onglet "a_traiter" par défaut : liste uniquement `statut=Soumise`.
- Onglet "traitees" : uniquement `Traitee`.
- Onglet "rejetees" : uniquement `Rejetee`.
- Onglet "toutes" : tous les dépôts du tenant.
- Tri : `date_facture desc`.
- Render contient colonnes spec (Date facture, Tiers, N°, Déposée le, Taille PDF, Actions).

**GREEN**:
- `App\Livewire\BackOffice\FacturePartenaire\Index` avec `#[Url(as: 'onglet')]` (pattern NDF [app/Livewire/BackOffice/NoteDeFrais/Index.php](app/Livewire/BackOffice/NoteDeFrais/Index.php)).
- Vue `resources/views/livewire/back-office/facture-partenaire/index.blade.php` avec onglets + table.
- `mount()` : `$this->authorize('treat', FacturePartenaireDeposee::class)`.
- Route `GET /factures-partenaires/a-comptabiliser` → composant, layout `app-sidebar`.

**REFACTOR**: aucune (pattern NDF déjà mature).
**Files**: `app/Livewire/BackOffice/FacturePartenaire/Index.php`, `resources/views/livewire/back-office/facture-partenaire/index.blade.php`, `routes/web.php`, `tests/Feature/Livewire/BackOffice/FacturePartenaire/IndexTest.php`
**Commit**: `feat(factures-partenaires): back-office Livewire Index with 4 tabs`

### Step 8: Actions back-office — "Comptabiliser" (dispatcher) + "Rejeter" (modale)

**Complexity**: standard
**RED**: test ajouté à `IndexTest.php` :
- `->call('comptabiliser', $depot->id)` avec un dépôt Soumise : dispatche `open-transaction-form-from-depot-facture` avec `depotId`.
- `->call('comptabiliser', $id)` avec dépôt d'un autre tenant : 404.
- `->call('comptabiliser', $id)` avec dépôt Traitee : flash error "Déjà traité", pas de dispatch.
- `->call('ouvrirRejet', $id)` puis `set('motifRejet', 'PDF illisible')->call('confirmerRejet')` : `rejeter()` appelée, dépôt `Rejetee`, flash succès, modale fermée.
- `->call('confirmerRejet')` avec motif vide : erreur de validation, dépôt reste Soumise.

**GREEN**:
- Méthodes `comptabiliser(int $depotId): void` et `ouvrirRejet(int $depotId)` / `confirmerRejet(): void` + state modale (`public bool $showRejectModal`, `public ?int $depotIdToReject`, `public string $motifRejet`).
- Blade : boutons d'action + modale Bootstrap `wire:confirm` avec textarea motif.

**REFACTOR**: aucune.
**Files**: `app/Livewire/BackOffice/FacturePartenaire/Index.php`, `resources/views/livewire/back-office/facture-partenaire/index.blade.php`, `tests/Feature/Livewire/BackOffice/FacturePartenaire/IndexTest.php`
**Commit**: `feat(factures-partenaires): add Comptabiliser + Rejeter actions on back-office list`

### Step 9: Sidebar — entrée "Factures à comptabiliser" dans le groupe Comptabilité

**Complexity**: trivial
**RED**: test render sidebar vu par comptable → contient lien `route('back-office.factures-partenaires.index')` avec label "Factures à comptabiliser" sous le groupe Comptabilité ; invisible si utilisateur non-comptable.
**GREEN**: mise à jour du partial sidebar correspondant (même niveau que "Notes de frais").
**REFACTOR**: aucune.
**Files**: `resources/views/layouts/partials/sidebar.blade.php` (ou nommage équivalent), `tests/Feature/NavigationSidebarTest.php` (si existant, sinon créer petit test ciblé).
**Commit**: `feat(factures-partenaires): add sidebar entry under Comptabilité`

### Step 10: Portail — `AtraiterIndex` scope élargi à `Rejetee` + tri + badge + motif

**Complexity**: standard
**RED**: modifications des tests existants `tests/Feature/Livewire/Portail/FacturePartenaire/AtraiterIndexTest.php` (ou ajout tests dédiés) :
- Un dépôt `Rejetee` avec `motif_rejet` est listé (scope élargi).
- Tri : un `Rejetee` + un `Soumise` → Rejetee en premier (priorité visuelle), secondaire `created_at desc`.
- Le rendu contient le badge "Rejetée" + le texte du motif.
- Un dépôt `Traitee` n'apparaît pas (comportement conservé).
- Bouton "Supprimer" disponible sur un dépôt Rejetee (test clic → hard delete OK via `oublier()` guard étendu à l'étape 1).

**GREEN**:
- `AtraiterIndex` : `whereIn('statut', [Soumise, Rejetee])` + `orderByRaw("FIELD(statut, 'rejetee', 'soumise')")` puis `->orderByDesc('created_at')`.
- Vue portail : colonne statut + badge + motif (affichage conditionnel `Rejetee`).

**REFACTOR**: aucune.
**Files**: `app/Livewire/Portail/FacturePartenaire/AtraiterIndex.php`, `resources/views/livewire/portail/facture-partenaire/atraiter-index.blade.php`, `tests/Feature/Livewire/Portail/FacturePartenaire/AtraiterIndexTest.php`
**Commit**: `feat(portail-factures-partenaires): show Rejetee deposits with motif in portal inbox`

### Step 11: Tests d'intrusion cross-tenant back-office + doc utilisateur

**Complexity**: standard
**RED**: `tests/Feature/BackOffice/FacturePartenaireIntrusionTest.php` —
1. Liste : comptable de X accède à `/factures-partenaires/a-comptabiliser` : les dépôts de Y n'apparaissent pas.
2. PDF : URL signée construite côté X pour un depot de Y → 404.
3. Comptabiliser : `->call('comptabiliser', $depotIdDeY)` → 404.
4. Rejeter : `->call('confirmerRejet', motif='x')` sur dépôt Y → 404, dépôt Y inchangé.
5. TransactionForm : `dispatch('open-transaction-form-from-depot-facture', $depotIdDeY)` depuis X → pas de pré-remplissage, flash error.

Mise à jour documentation : `docs/portail-tiers.md` — section "Factures partenaires" reçoit un paragraphe sur l'affichage Rejetee côté portail et le cycle Rejeté → Redépôt.

**GREEN**: les tests doivent tous passer sans code supplémentaire (couverts par Policy + TenantScope + scope explicite des étapes 3-8). Ajouter le paragraphe doc.

**REFACTOR**: aucune.
**Files**: `tests/Feature/BackOffice/FacturePartenaireIntrusionTest.php`, `docs/portail-tiers.md`
**Commit**: `test(factures-partenaires): cross-tenant intrusion tests + doc update`

## Complexity Classification

| Step | Complexité | Justification |
|---|---|---|
| 1 | standard | nouveau service + event, pattern existant |
| 2 | standard | extension ciblée d'un service existant |
| 3 | standard | policy classique |
| 4 | standard | route signée simple |
| 5 | **complex** | modifications cross-cutting sur `TransactionForm` (composant central) |
| 6 | **complex** | hook save + DB::transaction englobant + rollback exercice clôturé |
| 7 | standard | composant liste, pattern NDF mature |
| 8 | standard | actions Livewire + modale Bootstrap |
| 9 | trivial | config sidebar |
| 10 | standard | modif composant portail existant + tests associés |
| 11 | standard | tests d'intrusion + doc |

## Pre-PR Quality Gate

- [ ] `./vendor/bin/sail artisan test` — suite Pest entièrement verte (cible : 2682 + ≥ 20 nouveaux tests).
- [ ] `./vendor/bin/pint --test` — aucune violation PSR-12.
- [ ] `./vendor/bin/sail artisan test --filter=BackOffice\\\\FacturePartenaire` — tests back-office dédiés verts.
- [ ] `./vendor/bin/sail artisan test --filter=TransactionForm` — suite `TransactionForm` intacte (non-régression flux IncomingDocument + saisie manuelle + OCR classique).
- [ ] `/code-review --changed` passe (spec-compliance + agents qualité).
- [ ] Doc `docs/portail-tiers.md` à jour (section Rejetee).
- [ ] Mémoire projet `project_portail_factures_partenaires.md` mise à jour (statut "back-office livré").
- [ ] Rebase propre sur `feat/portail-factures-partenaires` ; aucun merge commit parasite.

## Risks & Open Questions

- **Risque de régression sur `TransactionForm`** : composant central utilisé par saisie manuelle + OCR + IncomingDocument + NDF comptabilisation. Mitigation : étapes 5 & 6 gardent les branches `factureDeposeeId !== null` explicitement isolées (aucune modification de la branche `incomingDocumentId` existante) + suite `TransactionForm*Test` doit rester verte à chaque step.
- **Collision `piece_jointe préexistante`** : la garde de `comptabiliser()` refuse une Transaction avec PJ déjà attachée. Upload PJ manuel masqué à l'étape 5 + hypothèse qu'aucun autre hook de `save()` n'attache de PJ avant `comptabiliser()`. À vérifier pendant l'étape 6 — le test exercice clôturé + le test PJ préexistante couvrent les deux bords.
- **Tri SQL `FIELD()` (étape 10)** : dépend de MySQL, incompatible avec SQLite de test si la suite y tourne. Si les tests Pest tournent sur MySQL Sail, OK ; sinon fallback via `->get()->sortBy(...)` côté Collection (la volumétrie est faible côté partenaire unique). À trancher au moment d'écrire le test.
- **OCR test du prompt (étape 2)** : `buildPrompt()` est privée. Option A : la passer en `protected` + test via classe anonyme/mock héritée. Option B : exposer un hook de test `public function debugPrompt(array $context): string`. Préférence A si Pest/PHPUnit le permet sans friction. Décision à l'étape.
- **Layout sidebar (étape 9)** : repérer le fichier partial exact (nommage conventionnel du projet) ; si plusieurs layouts existent, uniformiser avec l'entrée NDF.
