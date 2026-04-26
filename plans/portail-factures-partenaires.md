# Plan: Portail — Dépôt et suivi de factures par les partenaires

**Created**: 2026-04-23
**Branch**: `feat/portail-factures-partenaires` (à créer depuis `main`)
**Spec**: [docs/specs/2026-04-23-portail-factures-partenaires.md](../docs/specs/2026-04-23-portail-factures-partenaires.md)
**Status**: approved

## Goal

Livrer le MVP "factures partenaires" côté portail Tiers : dépôt PDF + 2 méta (date, numéro), liste "à traiter" avec suppression possible, et historique simplifié des Transaction de dépense liées au Tiers (hors NDF). Modèle léger autonome `FacturePartenaireDeposee` compatible avec un back-office à livrer ultérieurement (vision agrégateur multi-sources actée). Aucun back-office UI dans cette livraison.

## Acceptance Criteria

- [ ] 2 cartes ajoutées sous "Partenaires, bénévoles" sur la home portail (visibles ssi `pour_depenses=true`).
- [ ] Dépôt valide → `FacturePartenaireDeposee.statut=soumise` + PDF stocké sous `associations/{id}/factures-deposees/{Y}/{m}/...`.
- [ ] "Supprimer" hard delete BDD + fichier ; refuse si statut ≠ `soumise` ou autre Tiers.
- [ ] Écran "À traiter" liste uniquement `statut=soumise` du Tiers connecté, tri date facture desc.
- [ ] Écran "Historique" liste toutes `Transaction` de dépense du Tiers via Resource publique whitelistée.
- [ ] Transaction liée à `NoteDeFrais` (via `notes_de_frais.transaction_id`) **exclue** de l'historique.
- [ ] Texte muted en pied d'historique pointe vers l'écran NDF.
- [ ] Validation form : PDF obligatoire MIME `application/pdf` ≤ 10 Mo, date ≤ today, numéro 1-50c.
- [ ] Routes PDF signées scoped tenant + Tiers.
- [ ] Tests d'intrusion cross-tenant + cross-tiers verts (≥ 4 cas).
- [ ] Suite Pest verte, ≥ 25 tests dédiés.
- [ ] PSR-12 (`./vendor/bin/pint`).

## Steps

### Step 1: Migration + modèle `FacturePartenaireDeposee`

**Complexity**: standard
**RED**: `tests/Unit/Models/FacturePartenaireDeposeeTest.php` — assert : étend `TenantModel`, scope tenant fail-closed actif, casts (`date_facture` date, `traitee_at` datetime), enum `statut` accepte `soumise|traitee|rejetee` et défaut `soumise`, relations `tiers()` `transaction()`.
**GREEN**: migration `create_factures_partenaires_deposees_table` (+ enum PHP `App\Enums\StatutFactureDeposee` ou colonne string + cast), modèle `App\Models\FacturePartenaireDeposee` avec `$fillable`, `casts()`, relations.
**REFACTOR**: indexes `(association_id, tiers_id, statut)` et `(association_id, statut, created_at)` ajoutés à la migration.
**Files**: `database/migrations/2026_04_23_*_create_factures_partenaires_deposees_table.php`, `app/Models/FacturePartenaireDeposee.php`, `app/Enums/StatutFactureDeposee.php`, `database/factories/FacturePartenaireDeposeeFactory.php`, `tests/Unit/Models/FacturePartenaireDeposeeTest.php`
**Commit**: `feat(factures-partenaires): create FacturePartenaireDeposee model + migration`

### Step 2: Service `submit()`

**Complexity**: standard
**RED**: `tests/Unit/Services/Portail/FacturePartenaireServiceSubmitTest.php` — submit crée enregistrement statut `soumise`, stocke PDF sous `associations/{id}/factures-deposees/{Y}/{m}/{Y-m-d}-{numero-slug}-{rand6}.pdf`, persiste `pdf_taille`, log avec `LogContext`. Vérifier nom fichier généré (pas le nom uploadé). Storage::fake.
**GREEN**: `App\Services\Portail\FacturePartenaireService::submit(Tiers, array, UploadedFile): FacturePartenaireDeposee` dans `DB::transaction`.
**REFACTOR**: extraire un helper `buildPdfPath()` privé si plus lisible.
**Files**: `app/Services/Portail/FacturePartenaireService.php`, test associé
**Commit**: `feat(factures-partenaires): service submit() stores PDF in tenant-scoped path`

### Step 3: Service `oublier()` (hard delete)

**Complexity**: standard
**RED**: `tests/Unit/Services/Portail/FacturePartenaireServiceOublierTest.php` — oublier supprime BDD + fichier physique, refuse si statut ≠ `soumise`, refuse si autre Tiers (exception), rollback Storage si échec BDD.
**GREEN**: méthode `oublier(FacturePartenaireDeposee, Tiers): void` dans `DB::transaction`, `Storage::delete($depot->pdf_path)`.
**REFACTOR**: aucun a priori.
**Files**: `app/Services/Portail/FacturePartenaireService.php` (+ test)
**Commit**: `feat(factures-partenaires): service oublier() hard-deletes record + file`

### Step 4: Service `comptabiliser()` (pour back-office futur)

**Complexity**: standard
**RED**: `tests/Unit/Services/Portail/FacturePartenaireServiceComptabiliserTest.php` — comptabiliser **déplace** le PDF du dépôt vers `transactions/{transaction_id}/...` et renseigne `piece_jointe_path/nom/mime` (3 colonnes inline sur Transaction — pas de table pivot), met `statut=traitee`, `transaction_id`, `traitee_at`, émet event `FactureDeposeeComptabilisee`. Refuse si Transaction a déjà une pièce jointe.
**GREEN**: méthode `comptabiliser(FacturePartenaireDeposee, Transaction): void` + event `App\Events\Portail\FactureDeposeeComptabilisee`.
**REFACTOR**: aucun.
**Files**: `app/Services/Portail/FacturePartenaireService.php`, `app/Events/Portail/FactureDeposeeComptabilisee.php` (+ test)
**Commit**: `feat(factures-partenaires): service comptabiliser() bridges depot → transaction`

### Step 5: Resource publique `TransactionDepensePubliqueResource`

**Complexity**: standard
**RED**: `tests/Unit/Http/Resources/TransactionDepensePubliqueResourceTest.php` — toArray expose **uniquement** `date_piece`, `reference` (numero_piece || libelle), `montant_ttc`, `statut_reglement` ("En attente"/"Réglée"), `pdf_url` (URL signée si pièce jointe PDF, sinon null). Asserter qu'aucun champ interne ne fuit (notes, sous-cat, analytique).
**GREEN**: `App\Http\Resources\Portail\TransactionDepensePubliqueResource extends JsonResource`.
**REFACTOR**: extraire mapping statut règlement dans helper si réutilisé ailleurs.
**Files**: `app/Http/Resources/Portail/TransactionDepensePubliqueResource.php` (+ test)
**Commit**: `feat(factures-partenaires): public resource for depense transactions`

### Step 6: Routes + signed URL pour PDF

**Complexity**: standard
**RED**: `tests/Feature/Portail/FacturePartenaireRoutesTest.php` — routes `factures.*` et `historique.*` répondent 200 pour Tiers `pour_depenses=true` authentifié, 403 sinon (`EnsurePourDepenses`), URL PDF signée délivre le fichier au bon Tiers, refuse si signature absente / autre Tiers / autre tenant.
**GREEN**: ajouter dans `routes/portail.php` (et `routes/portail-mono.php`) :
- `GET factures` → `AtraiterIndex` (Step 7)
- `GET factures/depot` → `Depot` (Step 7)
- `GET factures/{depot}/pdf` → controller signé
- `GET historique` → `HistoriqueDepenses\Index` (Step 8)
- `GET historique/{transaction}/pdf` → controller signé (réutilise `pieceJointeFullPath`)
- Toutes derrière `EnsurePourDepenses`.
**REFACTOR**: factoriser le contrôleur signé en `App\Http\Controllers\Portail\PdfStreamController` paramétrable si possible.
**Files**: `routes/portail.php`, `routes/portail-mono.php`, `app/Http/Controllers/Portail/FacturePartenairePdfController.php`, `app/Http/Controllers/Portail/TransactionPdfController.php` (+ test)
**Commit**: `feat(factures-partenaires): portail routes + signed PDF endpoints`

### Step 7: Livewire `Depot` + `AtraiterIndex`

**Complexity**: standard
**RED**: `tests/Feature/Portail/FacturePartenaire/DepotTest.php` + `AtraiterIndexTest.php` — `Depot` valide PDF/MIME/10Mo/date/numéro, soumet via service ; `AtraiterIndex` liste dépôts `soumise` du Tiers (tri date desc), action supprimer via `wire:confirm` modale Bootstrap appelle `oublier()`, n'expose pas de dépôt traité.
**GREEN**: composants Livewire `App\Livewire\Portail\FacturePartenaire\Depot` (`WithFileUploads`) et `AtraiterIndex` ; vues Bootstrap, en-tête tableau `table-dark` (`--bs-table-bg:#3d5473;...`).
**REFACTOR**: extraire trait/Concern `WithPortailTenant` déjà existant pour le boot tenant.
**Files**: `app/Livewire/Portail/FacturePartenaire/Depot.php`, `AtraiterIndex.php`, `resources/views/livewire/portail/facture-partenaire/{depot,atraiter-index}.blade.php` (+ tests)
**Commit**: `feat(factures-partenaires): Livewire Depot + AtraiterIndex (portail)`

### Step 8: Livewire `HistoriqueDepenses\Index` (filtre NDF exclues)

**Complexity**: standard
**RED**: `tests/Feature/Portail/HistoriqueDepensesTest.php` — affiche les Transaction de dépense du Tiers (3 sources mixtes), exclut une Transaction liée à `NoteDeFrais` via `whereDoesntHave('noteDeFrais')`, projette via Resource publique, affiche statut "En attente"/"Réglée", lien PDF si pièce jointe, texte muted en pied d'écran rappelant l'écran NDF.
**GREEN**: composant `App\Livewire\Portail\HistoriqueDepenses\Index`, vue Blade (table-dark, colonnes Date/Référence/Montant/Statut/PDF), pagination ≥ 200.
**REFACTOR**: extraire la query dans un repository ou query object si le composant devient lourd.
**Files**: `app/Livewire/Portail/HistoriqueDepenses/Index.php`, `resources/views/livewire/portail/historique-depenses/index.blade.php` (+ test)
**Commit**: `feat(factures-partenaires): Livewire HistoriqueDepenses excluding NDF`

### Step 9: 2 cartes sur la home portail

**Complexity**: trivial
**RED**: étendre `tests/Feature/Portail/HomeBlocsTest.php` — vérifie présence des cartes "Vos factures à traiter" et "Historique de vos dépenses" sous "Partenaires, bénévoles" ssi `pour_depenses=true`, absentes sinon.
**GREEN**: éditer `resources/views/livewire/portail/home.blade.php` — 3 cartes empilées dans la section `pour_depenses` (NDF + Factures + Historique) avec `PortailRoute::to(...)`.
**REFACTOR**: si les cartes deviennent répétitives, extraire un partial `portail.partials.card-link`.
**Files**: `resources/views/livewire/portail/home.blade.php`, `tests/Feature/Portail/HomeBlocsTest.php`
**Commit**: `feat(portail): expose Factures + Historique cards under Partenaires section`

### Step 10: Tests d'intrusion (cross-tenant + cross-tiers)

**Complexity**: complex
**RED**: `tests/Feature/Portail/FacturePartenaireIsolationTest.php` — 4 cas minimum :
1. Tiers A/Asso X ne voit pas dépôts Tiers B/Asso X dans `AtraiterIndex`.
2. Tiers A/Asso X ne voit pas Transactions Tiers B/Asso X dans `HistoriqueDepenses`.
3. Tiers A/Asso X ne peut télécharger PDF d'un dépôt Tiers B/Asso X (route signée refuse).
4. Tiers homonyme sur Asso Y ne voit aucune donnée d'Asso X.
+ 1 cas service `oublier()` refuse cross-tiers même tenant.
**GREEN**: assertions strictes sur scope `TenantModel` + guard `auth:tiers-portail` + signature URL. Si fuites détectées, sceller dans le service / controller.
**REFACTOR**: aucune.
**Files**: `tests/Feature/Portail/FacturePartenaireIsolationTest.php`
**Commit**: `test(factures-partenaires): cross-tenant + cross-tiers intrusion suite`

### Step 11: Doc utilisateur portail

**Complexity**: trivial
**RED**: aucun test ; vérification visuelle.
**GREEN**: ajouter une section "Factures partenaires" dans `docs/portail-tiers.md` (saisie minimaliste, 2 écrans, NDF distinct).
**REFACTOR**: aucun.
**Files**: `docs/portail-tiers.md`
**Commit**: `docs(portail): document factures partenaires feature`

### Step 12: Pre-PR quality gate + nettoyage

**Complexity**: standard
**RED**: lancer suite complète `./vendor/bin/sail artisan test` + `./vendor/bin/pint` + `/code-review --changed`.
**GREEN**: corriger les findings éventuels.
**REFACTOR**: dernier passage sur DRY/naming/comments.
**Files**: variable selon findings.
**Commit**: `chore(factures-partenaires): pre-PR cleanup + style`

## Complexity Classification

| Rating | Criteria | Review depth |
|--------|----------|--------------|
| `trivial` | Single-file rename, config change, typo fix, documentation-only | Skip inline review; covered by final `/code-review --changed` |
| `standard` | New function, test, module, or behavioral change within existing patterns | Spec-compliance + relevant quality agents |
| `complex` | Architectural change, security-sensitive, cross-cutting concern, new abstraction | Full agent suite including opus-tier agents |

## Pre-PR Quality Gate

- [ ] All Pest tests pass (≥ 25 nouveaux)
- [ ] PHPStan / type checks (si configuré)
- [ ] `./vendor/bin/pint` propre
- [ ] `/code-review --changed` PASS (security-review en particulier sur upload + signed URL)
- [ ] Documentation portail mise à jour
- [ ] Manuel : tester le golden path en navigateur sur `http://localhost` (admin@monasso.fr → fixturer un Tiers `pour_depenses` + lien magic OTP)

## Risks & Open Questions

- **Identification "type Dépense" sur Transaction** : `Transaction` n'a pas de colonne `type` apparente — il faudra vérifier à l'étape 5/8 comment distinguer dépense vs recette (signe du montant ? compte associé ? scope existant ?). Mitigation : Step 5 produit une query exploratoire dans le RED, on adapte.
- **Stockage `transaction_pieces_jointes`** (étape 4) : vérifier l'API existante (move ou copy) avant TDD pour aligner le test sur le contrat réel. Mitigation : explorer 5 min avant Step 4, sinon basculer le ticket en `complex`.
- **`portail-mono.php` vs `portail.php`** : confirmer que le dual route file est toujours requis ou si un unique fichier suffit. Mitigation : Step 6 ajoute aux deux par sécurité, simplifie en REFACTOR si possible.
- **Branche** : créer `feat/portail-factures-partenaires` depuis `main` avant Step 1. Confirmer avec JK que ce n'est pas à empiler sur `feat/portail-tiers-slice1-auth-otp` qui contient les développements récents non encore mergés.
- **Naming `oublier`** : choix volontairement humain en français (cohérent avec spec). Confirmer ou bascule en `forget`/`destroy`.
