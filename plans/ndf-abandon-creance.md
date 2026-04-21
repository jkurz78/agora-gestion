# Plan: NDF — Don par abandon de créance

**Created**: 2026-04-21
**Branch**: `feat/portail-tiers-slice1-auth-otp`
**Status**: draft
**Spec**: [docs/specs/2026-04-21-ndf-abandon-creance.md](../docs/specs/2026-04-21-ndf-abandon-creance.md)

## Goal

Permettre à un tiers de renoncer au remboursement d'une NDF depuis le portail et au comptable de constater cet abandon en back-office, matérialisé par deux Transactions au statut `reglee` (dépense + don) qui se neutralisent. Dernière pièce du programme NDF avant PR vers `main`.

## Acceptance Criteria

- [ ] `notes_de_frais.abandon_creance_propose` (bool) et `don_transaction_id` (FK nullable) persistent l'intention et l'audit
- [ ] Enum `StatutNoteDeFrais::DonParAbandonCreances` ajouté avec libellé "Don par abandon de créance"
- [ ] Checkbox "Je renonce au remboursement…" visible et fonctionnelle sur l'écran de soumission portail
- [ ] Service `NoteDeFraisValidationService::validerAvecAbandonCreance()` crée 2 Transactions au statut `reglee` atomiquement
- [ ] Aucune des 2 transactions n'apparaît dans les listes "à régler"
- [ ] Sous-cat `AbandonCreance` absente → bouton back-office désactivé + message explicite
- [ ] Encart back-office visible si intention d'abandon, avec 2 CTA (constater / valider sans constater)
- [ ] Modale date d'effet avec défaut `date_note` NDF et bouton "Aujourd'hui"
- [ ] Statut `DonParAbandonCreances` affiché côté portail avec date + montant du don
- [ ] Isolation tenant respectée (aucune fuite cross-asso)
- [ ] Suite complète verte, 0 failure ; Pint clean

## Steps

### Step 1: Enum `StatutNoteDeFrais::DonParAbandonCreances`

**Complexity**: trivial
**RED**: Test unitaire — le case existe, `label()` retourne "Don par abandon de créance"
**GREEN**: Ajouter le case dans l'enum + clause `match`
**REFACTOR**: None needed
**Files**: `app/Enums/StatutNoteDeFrais.php`, `tests/Unit/Enums/StatutNoteDeFraisTest.php`
**Commit**: `feat(ndf): enum StatutNoteDeFrais::DonParAbandonCreances`

---

### Step 2: Migration — colonnes NDF `abandon_creance_propose` + `don_transaction_id`

**Complexity**: standard
**RED**: Test migration — après migrate, `Schema::hasColumn('notes_de_frais', 'abandon_creance_propose')` vrai ; FK `don_transaction_id` présente avec `ON DELETE SET NULL`
**GREEN**: Migration `add_abandon_creance_columns_to_notes_de_frais` (bool default false + FK nullable `transactions.id` ON DELETE SET NULL)
**REFACTOR**: None needed
**Files**: `database/migrations/2026_04_21_*_add_abandon_creance_columns_to_notes_de_frais.php`, `tests/Feature/Migrations/NoteDeFraisAbandonCreanceColumnsTest.php`
**Commit**: `feat(ndf): migration colonnes abandon_creance_propose + don_transaction_id`

---

### Step 3: Modèle `NoteDeFrais` — casts et relation `donTransaction`

**Complexity**: trivial
**RED**: Test unit — cast `abandon_creance_propose` en bool ; relation `donTransaction()` retourne la Transaction liée
**GREEN**: Ajouter `abandon_creance_propose` aux casts/fillable, ajouter méthode `donTransaction(): BelongsTo`
**REFACTOR**: None needed
**Files**: `app/Models/NoteDeFrais.php`, `tests/Unit/Models/NoteDeFraisTest.php`
**Commit**: `feat(ndf): model NoteDeFrais — relation donTransaction + cast intention`

---

### Step 4: Portail — capture de l'intention dans `Form::saveDraft()` et `Form::submit()`

**Complexity**: standard
**RED**: Tests Livewire portail :
- set `abandonCreanceProposed = true`, submit → NDF persistée avec `abandon_creance_propose = true`
- set `false` → colonne à `false`
- saveDraft préserve aussi la valeur
**GREEN**: Propriété `public bool $abandonCreanceProposed`, mapping dans `buildData()`, propagation au service portail `NoteDeFraisService::saveDraft()` qui écrit la colonne
**REFACTOR**: Si `buildData()` devient trop touffu, extraire la construction du tableau
**Files**: `app/Livewire/Portail/NoteDeFrais/Form.php`, `app/Services/Portail/NoteDeFrais/NoteDeFraisService.php`, `tests/Feature/Portail/NoteDeFrais/FormAbandonCreanceTest.php`
**Commit**: `feat(portail-ndf): capture de l'intention d'abandon de créance`

---

### Step 5: Portail — checkbox UI sur l'écran de soumission

**Complexity**: standard
**RED**: Test feature :
- la vue contient le label "Je renonce au remboursement et propose un don par abandon de créance"
- checkbox bindée à `abandonCreanceProposed`
- visible uniquement sur l'écran de soumission (pas sur le brouillon)
**GREEN**: Ajout bloc form checkbox dans `form.blade.php` (portail), condition d'affichage
**REFACTOR**: None needed
**Files**: `resources/views/livewire/portail/note-de-frais/form.blade.php`, `tests/Feature/Portail/NoteDeFrais/FormAbandonCreanceViewTest.php`
**Commit**: `feat(portail-ndf): checkbox abandon de créance sur soumission`

---

### Step 6: Portail — affichage statut sur page NDF (Show)

**Complexity**: standard
**RED**: Tests Livewire Show :
- NDF `Soumise` avec `abandon_creance_propose = true` → bandeau "Don par abandon de créance proposé — en attente de traitement"
- NDF `DonParAbandonCreances` → affichage "Don par abandon de créance — acté le {date}" + montant
**GREEN**: Bloc conditionnel dans `show.blade.php` portail, lecture `abandon_creance_propose` + `donTransaction->date`
**REFACTOR**: Extraire partial si le bloc devient verbeux
**Files**: `app/Livewire/Portail/NoteDeFrais/Show.php`, `resources/views/livewire/portail/note-de-frais/show.blade.php`, `tests/Feature/Portail/NoteDeFrais/ShowAbandonCreanceTest.php`
**Commit**: `feat(portail-ndf): affichage statut abandon de créance`

---

### Step 7: Service `NoteDeFraisValidationService::validerAvecAbandonCreance()`

**Complexity**: complex
**RED**: Tests unit/feature service :
- cas nominal : NDF soumise avec intention + sous-cat `AbandonCreance` désignée → 2 Transactions créées au statut `reglee`, statut NDF `DonParAbandonCreances`, `don_transaction_id` renseigné
- `DomainException` si pas de sous-cat `AbandonCreance` désignée
- `DomainException` si statut NDF ≠ `Soumise`
- `DomainException` si plusieurs sous-cat `AbandonCreance` (cas pathologique)
- rollback si insertion Transaction Don échoue (pas de Transaction dépense orpheline)
- date du don paramètre respecté ; libellé Transaction Don = "Don par abandon de créance — NDF #{id}"
- copie PJ fonctionne (hérite du flux valider existant)
- isolation tenant
**GREEN**: Méthode `validerAvecAbandonCreance(NoteDeFrais $ndf, ValidationData $data, string $dateDon): Transaction` — factoriser la partie "créer Transaction dépense + copier PJ" de `valider()` en méthode privée `createTransactionDepenseFromNdf(NoteDeFrais, ValidationData, StatutReglement): Transaction`, puis la réutiliser avec `StatutReglement::Reglee`. Créer la Transaction Don via `TransactionService::create()` avec une ligne unique.
**REFACTOR**: Extraction de la factorisation ci-dessus — appliquer aussi à `valider()` pour rester DRY
**Files**: `app/Services/NoteDeFrais/NoteDeFraisValidationService.php`, `tests/Feature/Services/NoteDeFrais/ValiderAvecAbandonCreanceTest.php`
**Commit**: `feat(ndf): service validerAvecAbandonCreance — 2 transactions réglées`

---

### Step 8: Back-office — encart intention d'abandon sur la page Show

**Complexity**: standard
**RED**: Tests Livewire back-office :
- NDF avec `abandon_creance_propose = true` → encart "Don par abandon de créance proposé" visible
- Bouton "Valider et constater l'abandon" présent, **désactivé** si pas de sous-cat `AbandonCreance` désignée, avec message "Configure l'usage dans Paramètres → Comptabilité → Usages"
- Bouton "Valider sans constater l'abandon" présent (fallback flux normal)
- NDF sans intention → encart absent, flux inchangé
**GREEN**: Bloc conditionnel dans `show.blade.php` back-office + lecture `Association::sousCategoriesFor(UsageComptable::AbandonCreance)` dans le composant Show
**REFACTOR**: Si la logique de "computed sous-cat abandon" devient complexe, extraire en computed property
**Files**: `app/Livewire/BackOffice/NoteDeFrais/Show.php`, `resources/views/livewire/back-office/note-de-frais/show.blade.php`, `tests/Feature/BackOffice/NoteDeFrais/ShowAbandonCreanceEncartTest.php`
**Commit**: `feat(back-office-ndf): encart intention abandon de créance`

---

### Step 9: Back-office — modale + action `constaterAbandon` sur `Show`

**Complexity**: complex
**RED**: Tests Livewire back-office :
- action `constaterAbandon` appelle le service avec les bons paramètres (compte, mode, date dépense, date don)
- date don par défaut = `ndf->date` (format ISO), champ modifiable
- date don du jour accessible via action `setDateDonToday`
- après succès : redirection + flash message
- échec service → erreur affichée, pas de transition d'état
- policy : utilisateur non-admin → 403
- isolation tenant (NDF asso B → 404)
**GREEN**: Propriétés `public string $dateDon`, `public ?int $compteIdAbandon`, `public ?ModePaiement $modePaiementAbandon` (ou réutilisation des propriétés validation existantes) + méthode publique `constaterAbandon()` qui appelle `validerAvecAbandonCreance`. Modale Bootstrap dans la vue avec champ date + bouton "Aujourd'hui".
**REFACTOR**: Si pollution de `Show.php`, envisager extraction en trait (mais probablement pas nécessaire)
**Files**: `app/Livewire/BackOffice/NoteDeFrais/Show.php`, `resources/views/livewire/back-office/note-de-frais/show.blade.php`, `tests/Feature/BackOffice/NoteDeFrais/ConstaterAbandonTest.php`
**Commit**: `feat(back-office-ndf): action constater abandon de créance`

---

### Step 10: Listes "à régler" — vérification non-régression

**Complexity**: standard
**RED**: Test feature — créer une NDF en abandon (via service step 7), vérifier :
- la Transaction dépense n'apparaît pas dans la liste "Dépenses à régler"
- la Transaction Don n'apparaît pas dans "Recettes à encaisser" (si existante)
- les deux sont bien présentes dans les listes générales de transactions
**GREEN**: Aucune modification attendue — les listes existantes filtrent déjà sur `statut_reglement` ; ce test est une sentinelle
**REFACTOR**: Si le test révèle une régression, ajuster le scope `forStatutReglement` ou équivalent
**Files**: `tests/Feature/Comptabilite/ListesARegler/AbandonCreanceNonAffichageTest.php`
**Commit**: `test(ndf): non-régression listes à régler pour abandon`

---

### Step 11: E2E — scénario complet tiers → comptable

**Complexity**: standard
**RED**: Test feature end-to-end :
- Jean soumet une NDF 120 € avec checkbox cochée
- statut NDF = `Soumise`, `abandon_creance_propose = true`
- comptable valide avec abandon, date = date NDF
- 2 Transactions réglées créées, NDF en `DonParAbandonCreances`
- Jean revoit sa NDF : statut acté + montant
**GREEN**: Aucune nouvelle logique — ce test assemble les pièces
**REFACTOR**: None needed
**Files**: `tests/Feature/EndToEnd/NoteDeFraisAbandonCreanceE2ETest.php`
**Commit**: `test(ndf): E2E abandon de créance portail → back-office`

---

### Step 12: Documentation

**Complexity**: trivial
**RED**: N/A (doc only — pas de test)
**GREEN**: Ajouter section "Abandon de créance" dans `docs/portail-tiers.md` + note dans `README` si NDF y est mentionné
**REFACTOR**: None needed
**Files**: `docs/portail-tiers.md`
**Commit**: `docs(ndf): abandon de créance — flux portail + back-office`

## Complexity Classification

| Step | Complexity | Justification |
|------|-----------|---------------|
| 1 | trivial | Ajout simple d'un case enum |
| 2 | standard | Migration avec FK nullable, tests schéma |
| 3 | trivial | Cast + relation Eloquent |
| 4 | standard | Logique portail + service, capture état |
| 5 | standard | UI checkbox + conditionnelle |
| 6 | standard | Affichage conditionnel multi-états |
| 7 | **complex** | Nouvelle écriture comptable cross-service, sous-cat lookup, transaction atomique, PJ, isolation tenant |
| 8 | standard | Encart conditionnel, lecture config usage |
| 9 | **complex** | Action Livewire + modale + policy + paramétrage multi-champs |
| 10 | standard | Test de non-régression transverse |
| 11 | standard | Test E2E d'assemblage |
| 12 | trivial | Documentation |

## Pre-PR Quality Gate

- [ ] `./vendor/bin/sail test` — suite complète verte, 0 failure
- [ ] `./vendor/bin/sail pint --test` — clean
- [ ] `/code-review --changed` passe
- [ ] Spec artifacts à jour ([docs/specs/2026-04-21-ndf-abandon-creance.md](../docs/specs/2026-04-21-ndf-abandon-creance.md))
- [ ] Documentation `docs/portail-tiers.md` mise à jour
- [ ] Memory updated : `project_ndf_abandon_creance.md` status → livré

## Risks & Open Questions

- **R1** — Factorisation du flux `valider()` existant dans le service : risque de régression sur les NDF "normales". **Mitigation** : tests non-régression slice 3 en vert à chaque commit du step 7 ; la méthode privée extraite doit préserver exactement le comportement actuel.
- **R2** — `StatutReglement::Reglee` : vérifier que la valeur existe dans l'enum et que `TransactionService::create()` accepte un statut non-EnAttente sans effet de bord (ex: génération auto d'une écriture bancaire). **Mitigation** : inspection au début du step 7, ajouter un test ciblé si doute.
- **R3** — Sous-catégorie `AbandonCreance` polarité `Recette` mais Transaction Don : le service de création doit accepter `type = Recette` avec mode paiement cohérent (pas de compte bancaire puisque statut = `Reglee` sans flux). **Mitigation** : si `TransactionService::create` exige un compte, utiliser un compte "fictif" ou étendre le service — décision à prendre au step 7.
- **R4** — Politique back-office : la règle "un comptable ne peut pas traiter sa propre NDF" reste une dette Slice 4+. Documentée, pas bloquante pour cette slice.
- **Q1** — La date du don peut-elle être antérieure à la date de la NDF ? Par défaut on autorise tout (le comptable gère), validation `date|required` simple. À confirmer si contrainte métier.
