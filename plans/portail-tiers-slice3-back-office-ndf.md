# Plan: Portail Tiers — Slice 3 (Back-office NDF comptable)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Created**: 2026-04-20
**Branch**: `feat/portail-tiers-slice1-auth-otp` (option A — même branche que Slice 1+2)
**Status**: approved
**Specs**: [docs/specs/2026-04-20-portail-tiers-slice3-back-office-ndf.md](../docs/specs/2026-04-20-portail-tiers-slice3-back-office-ndf.md)

## Goal

Livrer au back-office comptable l'écran de traitement des NDF soumises : validation + comptabilisation → Transaction, rejet motivé, dé-comptabilisation implicite via observer, badge top-bar "NDF N en attente", accès gated Admin/Comptable.

## Architecture

Approche TDD incrémentale, par briques structurelles, en suivant la granularité validée dans la spec. La stratégie privilégie des steps atomiques testables :

1. Fondations schéma (migrations + model tweaks)
2. Policy étendue
3. Observer (dé-comptabilisation implicite)
4. Service métier (valider + rejeter)
5. Livewire Index (liste + onglets)
6. Livewire Show (détail + mini-form + modal rejet)
7. Nav + badge (layout)
8. Intrusion multi-tenant
9. Observabilité + doc

## Acceptance Criteria (résumé)

### Fonctionnel
- [ ] AC-1 Entrée nav "Notes de frais" dans groupe Comptabilité entre Transactions et Budget, visible Admin/Comptable.
- [ ] AC-2 Liste avec onglets À traiter / Validées / Rejetées / Toutes, tri date décroissante.
- [ ] AC-3 Détail NDF affiche lignes avec lien PJ (nouvel onglet) — 1 PJ par ligne.
- [ ] AC-4 Bouton "Valider & comptabiliser" ouvre mini-form inline (compte, mode, date + bouton Aujourd'hui).
- [ ] AC-5 Validation crée Transaction type Depense, statut_reglement=EnAttente, 1 TransactionLigne par ligne NDF, PJ copiées `ligne-{N}-{slug(libelle)}.{ext}`.
- [ ] AC-6 Validation update NDF statut=Validee, transaction_id, validee_at.
- [ ] AC-7 Rejet ouvre modal, motif obligatoire, update statut=Rejetee + motif_rejet.
- [ ] AC-8 Suppression (soft ou force) Transaction liée NDF → observer remet NDF Soumise.

### Sécurité
- [ ] AC-9 Policy `treat` accessible Admin/Comptable uniquement (403 sinon).
- [ ] AC-10 TenantScope fail-closed sur NDF et Transaction (asso A ↔ asso B).
- [ ] AC-11 Path stockage PJ tenant-scopé (`associations/{id}/transactions/...`).
- [ ] AC-12 Mode support super-admin (`BlockWritesInSupport`) bloque valider/rejeter.

### UX
- [ ] AC-13 Badge top-bar "NDF N" visible Admin/Comptable, masqué autres rôles, lien vers liste À traiter.
- [ ] AC-14 Messages d'erreur en français.
- [ ] AC-15 Confirmations via modale Bootstrap (jamais `confirm()` natif).
- [ ] AC-16 Panneau "Transaction #XXX" + lien "Ouvrir la transaction comptable" sur NDF Validée.

### Conformité
- [ ] AC-17 `declare(strict_types=1)` + `final class` + type hints.
- [ ] AC-18 `./vendor/bin/pint` vert.
- [ ] AC-19 Cast `(int)` des deux côtés dans `===` PK/FK.

### Tests
- [ ] AC-20 ≥1 test Pest par scénario BDD (~21 tests).
- [ ] AC-21 Tests d'intrusion tenant (asso A ↔ asso B) sur liste/détail/valider/rejeter.
- [ ] AC-22 Suite complète verte.

## Steps

### Step 1 : Migrations + TransactionLigne `piece_jointe_path`

**Complexity**: trivial
**RED** : test présence colonne `transaction_lignes.piece_jointe_path` (nullable), test index composite `(association_id, statut)` sur `notes_de_frais`, test `TransactionLigne` accepte `piece_jointe_path` via `$fillable`.
**GREEN** :
- Migration `2026_04_21_000000_add_piece_jointe_path_to_transaction_lignes.php` : `$t->string('piece_jointe_path')->nullable()->after('libelle');`
- Migration `2026_04_21_000001_add_index_to_notes_de_frais_statut.php` : `$t->index(['association_id', 'statut'], 'ndf_asso_statut_idx');`
- Ajouter `piece_jointe_path` dans `$fillable` de `app/Models/TransactionLigne.php`.
**Files** : 2 migrations, `app/Models/TransactionLigne.php`, `tests/Feature/BackOffice/NoteDeFrais/SchemaTest.php`.
**Commit** : `feat(ndf): add piece_jointe_path on transaction_lignes + index ndf statut`

---

### Step 2 : Policy `NoteDeFraisPolicy::treat` — Admin/Comptable only

**Complexity**: standard
**RED** : tests policy :
- Admin (dans tenant) → `treat` true.
- Comptable (dans tenant) → `treat` true.
- Gestionnaire → false.
- Consultation → false.
- User hors tenant → false.

**GREEN** :
- Étendre `app/Policies/NoteDeFraisPolicy.php` : ajouter méthode `treat(User $user, ?NoteDeFrais $ndf = null): bool`.
- Logique : lire `AssociationUser::where('user_id', $user->id)->where('association_id', TenantContext::currentId())->first()` et vérifier `role ∈ {Admin, Comptable}`. Si `$ndf` fourni, check également `ndf.association_id === TenantContext::currentId()` (défensif).
- Pas besoin d'enregistrement supplémentaire (policy déjà mappée dans `AuthServiceProvider` pour Slice 2).
**Files** : `app/Policies/NoteDeFraisPolicy.php`, `tests/Feature/BackOffice/NoteDeFrais/PolicyTest.php`.
**Commit** : `feat(ndf): policy treat Admin + Comptable only`

---

### Step 3 : Observer `TransactionObserver` — dé-comptabilisation implicite

**Complexity**: standard
**RED** : tests :
- Suppression (softdelete) d'une Transaction liée à une NDF → NDF repasse Soumise, `transaction_id=null`, `validee_at=null`.
- `forceDelete` d'une Transaction liée → idem.
- Suppression d'une Transaction **non liée** → aucune NDF touchée.
- Log `comptabilite.ndf.reverted_to_submitted` émis (assert via `Log::spy`).

**GREEN** :
- Créer `app/Observers/TransactionObserver.php` (final class) avec méthodes `deleted(Transaction $t): void` et `forceDeleted(Transaction $t): void`.
- Les deux appellent `$this->revertLinkedNdf($t)` qui :
  - Cherche `NoteDeFrais::where('transaction_id', $t->id)->first()`.
  - Si trouvé → `update(['statut' => Soumise, 'transaction_id' => null, 'validee_at' => null])`.
  - Log `Log::info('comptabilite.ndf.reverted_to_submitted', ['ndf_id' => ..., 'transaction_id' => $t->id])`.
- Enregistrer dans `AppServiceProvider::boot()` : `Transaction::observe(TransactionObserver::class);`.

**Files** : `app/Observers/TransactionObserver.php`, `app/Providers/AppServiceProvider.php`, `tests/Feature/BackOffice/NoteDeFrais/ObserverTest.php`.
**Commit** : `feat(ndf): observer reverts ndf to submitted on transaction delete`

---

### Step 4 : Service `NoteDeFraisValidationService::rejeter`

**Complexity**: trivial
**RED** :
- Rejeter une NDF Soumise avec motif non vide → statut Rejetee + motif_rejet renseigné.
- Rejeter avec motif vide → ValidationException "Le motif est obligatoire".
- Rejeter une NDF non-Soumise → DomainException.
- Log `comptabilite.ndf.rejected` émis.

**GREEN** :
- Créer `app/Services/NoteDeFrais/NoteDeFraisValidationService.php` (final class, namespace côté app pas Portail).
- Méthode `rejeter(NoteDeFrais $ndf, string $motif): void` : valide motif (min:1), check statut=Soumise via DomainException sinon, update, log.

**Files** : service + `tests/Feature/BackOffice/NoteDeFrais/RejectionTest.php`.
**Commit** : `feat(ndf): validation service rejeter with motif mandatory`

---

### Step 5 : Service `NoteDeFraisValidationService::valider` + ValidationData DTO

**Complexity**: complex
**RED** :
- Valider une NDF Soumise avec data valide → création Transaction type Depense, statut_reglement=EnAttente, 1 ligne par ligne NDF (sous_categorie_id, operation_id, seance, libelle, montant).
- Transaction `tiers_id = ndf.tiers_id`, `libelle = ndf.libelle`, `date = data.date`, `compte_id = data.compte_id`, `mode_paiement = data.mode_paiement`.
- PJ de chaque ligne NDF copiée vers `associations/{id}/transactions/{tr_id}/ligne-{N}-{slug(libelle)}.{ext}` (N = ordre 1-based).
- NDF update statut=Validee, transaction_id, validee_at=now().
- Retour de la Transaction créée.
- Refus si NDF non-Soumise → DomainException.
- Refus si date dans exercice clôturé → Exception ExerciceService.
- Rollback complet si copie PJ échoue (fichier source manquant).
- Log `comptabilite.ndf.validated` (ndf_id, transaction_id, montant_total, valide_par).
- Concurrence : lock pessimiste (`lockForUpdate`) sur NDF au début de la DB::transaction.

**GREEN** :
- DTO `app/Services/NoteDeFrais/ValidationData.php` (readonly) : `public function __construct(public int $compte_id, public ModePaiement $mode_paiement, public string $date)`.
- Méthode `valider(NoteDeFrais $ndf, ValidationData $data): Transaction` :
  - `DB::transaction` avec lockForUpdate sur NDF.
  - `assertOuvert(anneeForDate(data.date))`.
  - Check statut=Soumise (DomainException sinon).
  - Appelle `TransactionService::create($txData, $lignesData)` (pré-remplissage depuis NDF, association_id auto via TenantModel).
  - Pour chaque ligne NDF ayant une PJ : génère le nouveau path, `Storage::disk('local')->copy($source, $dest)`, update `transaction_ligne.piece_jointe_path`.
  - Si `Storage::copy` échoue → throw pour trigger rollback.
  - NDF update statut/transaction_id/validee_at.
  - Log + return Transaction.

**Files** : `app/Services/NoteDeFrais/ValidationData.php`, `app/Services/NoteDeFrais/NoteDeFraisValidationService.php` (étendu), `tests/Feature/BackOffice/NoteDeFrais/ValidationTest.php`.
**Commit** : `feat(ndf): validation service valider creates transaction with copied PJ`

---

### Step 6 : Livewire `BackOffice\NoteDeFrais\Index` — liste + onglets

**Complexity**: standard
**RED** :
- Liste visible pour Admin/Comptable (redirect/403 sinon).
- Onglet "À traiter" actif par défaut, filtre statut=Soumise.
- Onglets Validées / Rejetées / Toutes fonctionnent (param `?onglet=validees`).
- Tri date décroissante par défaut.
- Colonnes : Date | Tiers | Libellé | Montant | Statut | Actions.
- Clic ligne → route `comptabilite.ndf.show`.
- Isolation tenant (NDF asso B invisibles).

**GREEN** :
- `app/Livewire/BackOffice/NoteDeFrais/Index.php` (final class).
- Propriétés : `public string $onglet = 'a_traiter'`, query string mapping `?onglet=...`.
- Méthode `render()` filtre collection selon `$onglet`, retourne vue.
- Autorisation dans `mount()` : `$this->authorize('treat', NoteDeFrais::class)` (Laravel Gate accepte class-level pour notre policy treat avec `?NoteDeFrais $ndf = null`).
- Vue `resources/views/livewire/back-office/note-de-frais/index.blade.php` : tableau Bootstrap avec table-dark header (style projet), `data-sort` sur date/montant, onglets Bootstrap.
- Route : `Route::get('/comptabilite/notes-de-frais', Index::class)->middleware(['auth', 'tenant'])->name('comptabilite.ndf.index')`.
- Vérifier middleware `EnsureTenantAccess` + `auth` appliqués.

**Files** : composant + vue + `routes/web.php` (ou dédié), `tests/Feature/BackOffice/NoteDeFrais/IndexTest.php`.
**Commit** : `feat(ndf): livewire index back-office with tabs`

---

### Step 7 : Livewire `BackOffice\NoteDeFrais\Show` — détail + valider + rejeter

**Complexity**: complex
**RED** :
- Affichage en-tête (Tiers, date, libellé, total, statut, soumise_le).
- Tableau des lignes avec lien "Ouvrir PJ" (nouvel onglet, target="_blank") — utilise une route ou controller dédié pour servir la PJ.
- Statut Soumise → boutons Valider (ouvre mini-form inline) + Rejeter (ouvre modal Bootstrap).
- Mini-form : champs compte_id (select), mode_paiement (select défaut Virement), date (default = ndf.date), bouton "Aujourd'hui" set date=today.
- Submit mini-form → appelle `NoteDeFraisValidationService::valider`, redirect vers même page, flash success, panneau "Transaction #XXX" + lien `/comptabilite/transactions?edit={tr_id}`.
- Statut Rejetée → affichage motif.
- Statut Validée → panneau Transaction + lien.
- Modal Rejet : textarea motif required, submit → `rejeter`, redirect liste avec flash.
- Messages d'erreur en français (exercice clôturé, validation déjà faite, PJ manquante).
- Isolation : accès NDF asso B → 404.

**GREEN** :
- `app/Livewire/BackOffice/NoteDeFrais/Show.php` (final class).
- Propriétés : `public NoteDeFrais $ndf`, `public bool $showMiniForm = false`, `public bool $showRejectModal = false`, `public ?int $compteId = null`, `public string $modePaiement = 'virement'`, `public string $dateComptabilisation`, `public string $motifRejet = ''`.
- `mount(NoteDeFrais $noteDeFrais)` : `$this->authorize('treat', $noteDeFrais)`, `$this->ndf = $noteDeFrais`, `$this->dateComptabilisation = $ndf->date->format('Y-m-d')`.
- Méthodes : `openMiniForm()`, `setDateToday()`, `confirmValidation()` (wire:click), `openRejectModal()`, `confirmRejection()`.
- Vue Bootstrap avec :
  - En-tête card.
  - Tableau lignes, chaque ligne avec lien `<a href="{{ route('comptabilite.ndf.piece-jointe', [$ndf, $ligne]) }}" target="_blank">Ouvrir PJ</a>` (contrôleur dédié à créer — vérifie policy `treat` + renvoie fichier via `Storage::download`).
  - Mini-form toggle via `@if($showMiniForm)`.
  - Modal rejet Bootstrap avec `wire:confirm` pattern.
- Contrôleur `app/Http/Controllers/BackOffice/NoteDeFraisPieceJointeController.php` (méthode __invoke) :
  - Signature : `__invoke(NoteDeFrais $noteDeFrais, NoteDeFraisLigne $ligne): StreamedResponse`.
  - Gate `treat`. Vérifie `$ligne->note_de_frais_id === $noteDeFrais->id`.
  - Renvoie `Storage::disk('local')->download($ligne->piece_jointe_path)` avec header inline pour ouverture directe dans le navigateur.
- Route : `Route::get('/comptabilite/notes-de-frais/{noteDeFrais}', Show::class)->name('comptabilite.ndf.show')` + route pour la PJ `comptabilite.ndf.piece-jointe`.

**Files** : composant + vue + contrôleur + routes + `tests/Feature/BackOffice/NoteDeFrais/ShowTest.php`.
**Commit** : `feat(ndf): livewire show back-office with mini-form and reject modal`

---

### Step 8 : Nav top-bar + badge "NDF N en attente"

**Complexity**: standard
**RED** :
- Admin/Comptable avec 3 NDF soumises dans tenant → badge "NDF 3" visible.
- Gestionnaire/Consultation → pas de badge ni de nav item.
- Admin sans NDF soumises → nav item visible mais sans badge numérique (juste "NDF").
- Clic badge → redirige vers `/comptabilite/notes-de-frais`.
- Count scopé au tenant courant (asso A ↔ asso B → comptes distincts).

**GREEN** :
- `app/Providers/AppServiceProvider::boot()` : étendre le View Composer existant pour `layouts.app` afin d'injecter `ndfPendingCount`.
  - Si user non connecté ou pas Admin/Comptable dans le tenant → `$view->with('ndfPendingCount', 0)` et `with('canSeeNdf', false)`.
  - Sinon → `NoteDeFrais::where('statut', 'soumise')->count()` (DB value, pas accessor dérivé) + `with('canSeeNdf', true)`.
- `resources/views/layouts/app.blade.php` : ajouter une `<li class="nav-item">` juste avant l'entrée Budget (ligne ~237), conditionnelle sur `@if($canSeeNdf ?? false)` :
  ```blade
  <a class="nav-link {{ request()->routeIs('comptabilite.ndf.*') ? 'active' : '' }}"
     href="{{ route('comptabilite.ndf.index') }}">
      <i class="bi bi-receipt-cutoff"></i> Notes de frais
      @if(($ndfPendingCount ?? 0) > 0)
          <span class="badge bg-warning text-dark">{{ $ndfPendingCount }}</span>
      @endif
  </a>
  ```
- `resources/views/layouts/app-sidebar.blade.php` : étendre le match breadcrumb `Comptabilité` pour inclure `comptabilite.ndf.*`.

**Files** : `app/Providers/AppServiceProvider.php`, `resources/views/layouts/app.blade.php`, `resources/views/layouts/app-sidebar.blade.php`, `tests/Feature/BackOffice/NoteDeFrais/BadgeTest.php`.
**Commit** : `feat(ndf): nav item + badge ndfPendingCount for Admin/Comptable`

---

### Step 9 : Tests intrusion multi-tenant

**Complexity**: standard
**RED** :
- Comptable asso A accède à `/comptabilite/notes-de-frais/{ndf_B}` → 404 (TenantScope).
- Comptable asso A tente POST valider/rejeter NDF asso B via URL → 404.
- Count badge asso A ignore NDF asso B.
- Observer ne touche pas aux NDF d'autres tenants lors de suppression Transaction.
- Mode support super-admin → POST valider/rejeter bloqué (403 ou 419 selon impl BlockWritesInSupport).

**GREEN** : doit passer dès Step 2-8 (policy + TenantScope + BlockWritesInSupport déjà en place). Si fail → corriger.
**Files** : `tests/Feature/BackOffice/NoteDeFrais/IsolationTest.php`.
**Commit** : `test(ndf): tenant intrusion back-office NDF`

---

### Step 10 : Observabilité + doc

**Complexity**: trivial
**RED** : non applicable (doc).
**GREEN** :
- Vérifier les 3 événements log émis : `comptabilite.ndf.validated`, `comptabilite.ndf.rejected`, `comptabilite.ndf.reverted_to_submitted` (déjà dans services/observer).
- Mise à jour `docs/portail-tiers.md` — ajouter section "Slice 3 : Back-office NDF" (routes, rôles, flux validation/rejet, observer, badge).
- Mise à jour memory `project_portail_tiers.md` : Slice 3 implémentée.

**Files** : `docs/portail-tiers.md`, `memory/project_portail_tiers.md`.
**Commit** : `feat(ndf): structured logs + doc back-office Slice 3`

---

## Risques & Questions ouvertes

- **R1 — `authorize('treat', NoteDeFrais::class)` class-level** : Laravel Gate permet l'appel class-level si la policy method accepte `?NoteDeFrais $ndf = null` en paramètre. À vérifier en test Step 6. Si ça pose souci, basculer vers `Gate::define('treat-ndf', ...)` ou helper `$this->canTreatNdf()`.
- **R2 — Storage::copy atomique** : si la copie échoue à mi-parcours (ligne 3/5), le rollback DB annule la Transaction, mais les fichiers déjà copiés (ligne 1 et 2) restent sur le disque. On accepte (orphelins rares, purgés au prochain restart ou script maintenance) — à tracer comme dette mineure.
- **R3 — Observer firing en contexte job** : si une Transaction est soft-delete dans un job qui n'a pas booté TenantContext, la query `NoteDeFrais::where('transaction_id', ...)` va retourner vide (fail-closed). Actuellement aucun job ne supprime des Transactions, mais à surveiller si un chantier futur introduit ça.
- **R4 — Index composite et data existante** : migration ajoute un index sur `(association_id, statut)`. Sur la table existante (prod/staging), l'index se crée rapidement tant que le volume est faible (NDF est une table neuve en Slice 2). Aucune conversion de données.
- **R5 — Accessor `statut` dérivé (Payee)** : la query badge fait `where('statut', 'soumise')` sur le **champ DB**, pas l'accessor. Correct — le badge compte bien les NDF en attente de traitement. Test Step 8 vérifie explicitement qu'une NDF validée+pointée (donc Payee en accessor) n'apparaît pas dans le count.
- **R6 — Route PJ côté back-office vs contrôleur existant** : `TransactionPieceJointeController` existe pour les PJ niveau transaction. Nouveau contrôleur nécessaire pour PJ niveau ligne NDF (stockage différent, policy différente). Pas de réutilisation directe. OK.

## Pre-PR Quality Gate

- [ ] Suite verte complète (2054 existants + ~21 nouveaux).
- [ ] `./vendor/bin/pint` vert.
- [ ] `/code-review --changed` pass.
- [ ] Smoke test manuel local (Compte dev `admin@monasso.fr`) :
  - [ ] Depuis le portail Tiers (un Tiers avec email), soumettre une NDF (reprendre flux Slice 2).
  - [ ] Se connecter en admin sur l'app, vérifier badge "NDF 1" top-bar.
  - [ ] Ouvrir la liste, onglet À traiter, voir la NDF.
  - [ ] Ouvrir détail, cliquer sur la PJ (s'ouvre dans nouvel onglet).
  - [ ] Cliquer "Valider & comptabiliser", choisir compte, mode, tester le bouton "Aujourd'hui", valider.
  - [ ] Vérifier la Transaction créée + le lien "Ouvrir la transaction comptable".
  - [ ] Ouvrir la Transaction → vérifier 1 ligne par ligne NDF + PJ accessible.
  - [ ] Supprimer la Transaction via l'écran Transactions → vérifier que la NDF repasse en Soumise.
  - [ ] Valider à nouveau, puis tester "Rejeter" sur une autre NDF, vérifier motif persisté + redirection liste.
- [ ] MEMORY.md mis à jour (`project_portail_tiers.md`).
- [ ] Pas de nouveau warning PHP.

## Self-Review

**Spec coverage** :
- AC-1 Nav item → Step 8 ✓
- AC-2 Onglets + tri → Step 6 ✓
- AC-3 Détail + PJ nouvel onglet → Step 7 ✓
- AC-4 Mini-form → Step 7 ✓
- AC-5 Création Transaction + PJ copiées → Step 5 ✓
- AC-6 Update NDF Validee → Step 5 ✓
- AC-7 Rejet modal → Steps 4+7 ✓
- AC-8 Observer → Step 3 ✓
- AC-9 Policy Admin/Comptable → Step 2 ✓
- AC-10 TenantScope → Step 9 ✓
- AC-11 Path tenant-scopé → Step 5 ✓
- AC-12 BlockWritesInSupport → Step 9 ✓
- AC-13 Badge → Step 8 ✓
- AC-14 Messages fr → Steps 4+5+7 ✓
- AC-15 Modale Bootstrap → Step 7 ✓
- AC-16 Panneau Transaction → Step 7 ✓
- AC-17 strict_types + final → tous les steps ✓
- AC-18 Pint → Pre-PR Gate ✓
- AC-19 Cast int → Steps 2+5 ✓
- AC-20 Tests ≥21 → chaque step ✓
- AC-21 Intrusion → Step 9 ✓
- AC-22 Suite verte → Pre-PR Gate ✓

**Placeholders** : aucun TBD / TODO / "à définir". ✓
**Type consistency** : `NoteDeFraisValidationService`, `ValidationData`, `TransactionObserver`, `ndfPendingCount`, `canSeeNdf`, `treat` — noms cohérents entre steps. ✓
**Granularité** : 10 steps, chacun commité, chacun testable de façon atomique. ✓
